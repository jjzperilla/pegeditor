<?php
// cron/send_oos_digest.php
// Run daily at 5pm America/New_York

require __DIR__ . '/../api/db.php';
require __DIR__ . '/../api/oos_mailer.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
  FROM oos_email_queue q
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

// Build ONE email body with sections per config_id
$sections = [];
foreach ($byConfig as $configId => $pointIds) {
  // config details
  $cfgStmt->bind_param("i", $configId);
  $cfgStmt->execute();
  $cfg = $cfgStmt->get_result()->fetch_assoc();
  if (!$cfg) continue;

  $pegName   = (string)($cfg['peg_name'] ?? '');
  $capacity  = (string)($cfg['capacity'] ?? '');
  $iface     = (string)($cfg['interface'] ?? '');
  $cond      = (string)($cfg['condition_type'] ?? '');
  $driveType = (string)($cfg['drive_type_label'] ?? $cfg['drive_type_id'] ?? '');

  // last saved at
  $savedStmt->bind_param("i", $configId);
  $savedStmt->execute();
  $savedRow = $savedStmt->get_result()->fetch_assoc();
  $savedAt = (string)($savedRow['last_saved_at'] ?? '');
  if ($savedAt === '') $savedAt = $queueDay . ' (unknown time)';

  // points
  $pointMap = fetchPoints($db, $pointIds);
  $lines = [];
  foreach ($pointIds as $pid) {
    $label = $pointMap[$pid]['label'] ?? ("PEG Point #{$pid}");
    $notes = trim($pointMap[$pid]['notes'] ?? '');
    // Format: "- Point 1 - Lazy boy" (label + optional notes)
    $line = "- " . $label;
    if ($notes !== '') $line .= " - " . $notes;
    $lines[] = $line;
  }

  $link = $BASE_URL . "/index.php?config_id=" . $configId;

  // EXACT format you want (plain text)
  $sections[] = implode("\n", [
    "OOS items were marked on save.",
    "Peg Name: " . $pegName,
    "Capacity: " . $capacity,
    "Drive Type: " . $driveType,
    "Interface: " . $iface,
    "Condition: " . $cond,
    "Peg Points:",
    implode("\n", $lines),
    "Saved at (EST): " . $savedAt,
    "Link: " . $link,
    "" // spacing line
  ]);
}

$body = implode("\n", $sections);

$subject = "OOS PEG Points Digest ({$queueDay} EST)";

$mailResult = sendOosSummaryEmail($TO, $subject, $body, $CC);

if (empty($mailResult['success'])) {
  $err = $mailResult['error'] ?? 'Unknown mailer error';
  echo "Mail failed: {$err}\n";
  exit(1);
}

echo "Mail sent.\n";

// Mark queue as sent (unless test)
if (!$isTest) {
  $mark = $db->prepare("
    UPDATE oos_email_queue
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
