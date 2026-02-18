<?php
// cron/send_oos_digest.php
// Run daily at 5pm America/New_York

require __DIR__ . '/../api/db.php';
require __DIR__ . '/../api/oos_mailer.php';


// === SETTINGS ===
$BASE_URL = 'http://167.71.244.63'; // your site base
$TO = 'jperilla@servertechsolutions.com';
$CC = ['jperilla@servertechsolutions.com']; // add more if needed

$tz = new DateTimeZone('America/New_York');
$now = new DateTime('now', $tz);
$queueDay = $now->format('Y-m-d'); // today's EST date

// Optional CLI args: php send_oos_digest.php --day=2026-01-26 --test
$isTest = in_array('--test', $argv, true);
foreach ($argv as $a) {
  if (strpos($a, '--day=') === 0) {
    $queueDay = substr($a, 6);
  }
}

// 1) get all unsent queued items for the day
$q = $db->prepare("
  SELECT q.config_id, q.peg_point_id
  FROM oos_user_queue q
  WHERE q.queue_day = ?
    AND q.sent_at IS NULL
  ORDER BY q.config_id ASC, q.peg_point_id ASC
");
$q->bind_param("s", $queueDay);
$q->execute();
$res = $q->get_result();

$byConfig = [];
while ($row = $res->fetch_assoc()) {
  $cid = (int)$row['config_id'];
  $pid = (int)$row['peg_point_id'];
  if (!isset($byConfig[$cid])) $byConfig[$cid] = [];
  $byConfig[$cid][] = $pid;
}

if (count($byConfig) === 0) {
  echo "No unsent OOS queue for {$queueDay}\n";
  exit;
}

// helper to fetch config details
$cfgStmt = $db->prepare("
  SELECT
    c.id,
    c.peg_name,
    c.capacity,
    c.interface,
    c.condition_type,
    c.drive_type_id,
    dt.label AS drive_type_label
  FROM peg_configs c
  LEFT JOIN drive_types dt ON dt.id = c.drive_type_id
  WHERE c.id = ?
  LIMIT 1
");

// helper to fetch latest saved_at from peg_history
$savedStmt = $db->prepare("
  SELECT MAX(saved_at) AS last_saved_at
  FROM peg_history
  WHERE config_id = ?
");

// helper to fetch point labels + notes
// (note: uses IN list; safe because we bind ints)
function fetchPoints(mysqli $db, array $pointIds): array {
  if (count($pointIds) === 0) return [];
  $placeholders = implode(',', array_fill(0, count($pointIds), '?'));
  $types = str_repeat('i', count($pointIds));

  $sql = "
    SELECT id, label, notes
    FROM peg_points
    WHERE id IN ($placeholders)
    ORDER BY id ASC
  ";
  $stmt = $db->prepare($sql);
  $stmt->bind_param($types, ...$pointIds);
  $stmt->execute();
  $r = $stmt->get_result();

  $out = [];
  while ($row = $r->fetch_assoc()) {
    $out[(int)$row['id']] = [
      'label' => (string)($row['label'] ?? ''),
      'notes' => (string)($row['notes'] ?? ''),
    ];
  }
  return $out;
}

// Build ONE user body with sections per config_id
// Build ONE user body with sections per config_id (plain text)
$sections = [];

foreach ($byConfig as $configId => $pointIds) {
  // config details
  $cfgStmt->bind_param("i", $configId);
  $cfgStmt->execute();
  $cfg = $cfgStmt->get_result()->fetch_assoc();
  if (!$cfg) continue;

  $capacity  = trim((string)($cfg['capacity'] ?? ''));
  $iface     = trim((string)($cfg['interface'] ?? ''));
  $cond      = trim((string)($cfg['condition_type'] ?? ''));
  $driveType = trim((string)($cfg['drive_type_label'] ?? $cfg['drive_type_id'] ?? ''));

  // points
  $pointMap = fetchPoints($db, $pointIds);
  $lines = [];

  foreach ($pointIds as $pid) {
    $label = trim((string)($pointMap[$pid]['label'] ?? ''));
    $notes = trim((string)($pointMap[$pid]['notes'] ?? ''));

    if ($label === '') $label = "Point {$pid}";

    $url = extractFirstUrl($notes);
    $remark = $notes;

    if ($url !== '') {
      $remark = trim(str_replace($url, '', $remark));
      $remark = trim($remark, "- \t");
    }

    $bullet = "- " . $label;
    if ($remark !== '') $bullet .= " – " . $remark;

    $lines[] = $bullet . ":\n  " . ($url !== '' ? $url : "(no URL)");
  }

  $sections[] = implode("\n", [
    "PEG CONFIG:",
    "Specs: {$capacity} / {$driveType} / {$iface} / {$cond}",
    "",
    "Peg Points Marked OOS:",
    implode("\n\n", $lines),
    "",
    "--------------------------------------------------",
    ""
  ]);
}

// Final user body
$body = implode("\n", array_merge(
  [
    "Hello,",
    "",
    "Please see below the Out-of-Stock (OOS) Summary for peg points recently marked as unavailable.",
    "",
    "--------------------------------------------------",
    ""
  ],
  $sections,
  [
    "Please review the affected peg configurations and take any necessary action.",
  ]
));

$body = implode("\n", $sections);

$subject = "OOS Summary Notification – Peg Points Marked Out of Stock ({$queueDay} EST)";

$mailResult = sendOosSummaryuser($TO, $subject, $body, $CC);

if (empty($mailResult['success'])) {
  $err = $mailResult['error'] ?? 'Unknown mailer error';
  echo "Mail failed: {$err}\n";
  exit(1);
}

echo "Mail sent.\n";

// Mark queue as sent (unless test)
if (!$isTest) {
  $mark = $db->prepare("
    UPDATE oos_user_queue
    SET sent_at = NOW(), sent_message = 'sent'
    WHERE queue_day = ?
      AND sent_at IS NULL
  ");
  $mark->bind_param("s", $queueDay);
  $mark->execute();
  echo "Queue marked sent.\n";
} else {
  echo "TEST MODE: queue NOT marked sent.\n";
}
