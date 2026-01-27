<?php
// api/send_oos_digest_now.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();

require __DIR__ . '/db.php';
require_once __DIR__ . '/oos_mailer.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// -------------------- helpers -------------------

// -------------------- time --------------------
$est     = new DateTime('now', new DateTimeZone('America/New_York'));
$today   = $est->format('Y-m-d');
$nowEST  = $est->format('Y-m-d H:i:s');

// recipients
$to = "jperilla@servertechsolutions.com";
$cc = ["jperilla@servertechsolutions.com", "jj.perilla12@gmail.com"];

// Link base
$baseUrl = "http://167.71.244.63/index.php?config_id=";

// dry run via web: ?dry_run=1
$dryRun = isset($_GET['dry_run']) ? (int)$_GET['dry_run'] : 0;

// NOTE: if you want CLI dry_run=1 support, call:
// CRON_TOKEN=... php send_oos_digest_now.php dry_run=1
// and parse $argv here. (optional)

try {
  /**
   * 1) Get ALL OOS peg points (current truth), including URL
   */
  $q = $db->prepare("
    SELECT
      p.config_id,
      p.id    AS peg_point_id,
      p.label AS peg_point_label,
      p.url   AS peg_point_url,

      c.peg_name,
      c.capacity,
      c.interface,
      c.condition_type,
      COALESCE(dt.label, c.drive_type_id) AS drive_type_label
    FROM peg_points p
    JOIN peg_configs c ON c.id = p.config_id
    LEFT JOIN drive_types dt ON dt.id = c.drive_type_id
    WHERE p.oos = 1
    ORDER BY p.config_id ASC, p.id ASC
  ");
  $q->execute();
  $r = $q->get_result();

  $byConfig = []; // config_id => ['cfg'=>..., 'points'=>[ ['label'=>..,'url'=>..] ]]
  while ($row = $r->fetch_assoc()) {
    $cid = (int)$row['config_id'];

    if (!isset($byConfig[$cid])) {
      $byConfig[$cid] = [
        'cfg' => [
          'peg_name'   => (string)($row['peg_name'] ?? ''),
          'capacity'   => (string)($row['capacity'] ?? ''),
          'drive_type' => (string)($row['drive_type_label'] ?? ''),
          'interface'  => (string)($row['interface'] ?? ''),
          'condition'  => (string)($row['condition_type'] ?? ''),
        ],
        'points' => []
      ];
    }

    $label = trim((string)($row['peg_point_label'] ?? ''));
    if ($label === '') $label = "PEG Point #" . (int)$row['peg_point_id'];

    $url = ($row['peg_point_url'] ?? '');
    $byConfig[$cid]['points'][] = [
      'label' => $label,
      'url'   => $url
    ];
  }

  if (!$byConfig) {
    echo json_encode([
      "status" => "ok",
      "message" => "No OOS peg points found (nothing to send).",
      "date_est" => $today
    ]);
    exit;
  }

  /**
   * 2) Latest saved_at per config for sorting (newest on top)
   */
  $latestStmt = $db->prepare("
    SELECT MAX(saved_at) AS latest_saved_at
    FROM peg_history
    WHERE config_id = ?
  ");

  $sections = []; // each: ['saved_at' => 'Y-m-d H:i:s', 'text' => '...']

  foreach ($byConfig as $configId => $bundle) {
    $cfg    = $bundle['cfg'];
    $points = $bundle['points'];

    // latest saved_at
    $latestStmt->bind_param("i", $configId);
    $latestStmt->execute();
    $latestRow = $latestStmt->get_result()->fetch_assoc();
    $latestSavedAt = (string)($latestRow['latest_saved_at'] ?? '');
    if ($latestSavedAt === '') $latestSavedAt = $nowEST;

    // Peg Config line
    $pegName = trim($cfg['peg_name'] ?? '');
    if ($pegName === '') $pegName = "(no name)";

    $capacity  = $cfg['capacity']   ?? '';
    $driveType = $cfg['drive_type'] ?? '';
    $iface     = $cfg['interface']  ?? '';
    $cond      = $cfg['condition']  ?? '';

    // Peg Points Marked OOS lines
    $pointLines = [];
    foreach ($points as $p) {
      $pLabel = $p['label'] ?? 'PEG Point';
      $pUrl   = trim((string)($p['url'] ?? ''));
      if ($pUrl === '') $pUrl = '(no url)';
      $pointLines[] = "- {$pLabel}:  {$pUrl}";
    }
    if (!$pointLines) $pointLines[] = "- (no points found)";

    $sectionText = implode("\n", [
      "<br>",
      "Peg Config: {$pegName} / {$capacity} / {$driveType} / {$iface} / {$cond}",
      "Peg Points Marked OOS: ",
      implode("\n", $pointLines),
      "Saved at (EST): {$latestSavedAt}",
      "Link: {$baseUrl}{$configId}",
      "" // blank line after each config
    ]);

    $sections[] = [
      'saved_at' => $latestSavedAt,
      'text'     => $sectionText
    ];
  }

  // Sort newest first
  usort($sections, function($a, $b) {
    return strcmp($b['saved_at'], $a['saved_at']);
  });

  /**
   * 3) Build final email
   */
  $subject  = "OOS Summary List: {$today} (EST)";
  $bodyText = "OOS Summary List:\n" . implode("", array_column($sections, 'text'));
  $bodyHtml = nl2br($bodyText);

  if ($dryRun) {
    echo json_encode([
      "status" => "ok",
      "dry_run" => true,
      "date_est" => $today,
      "configs" => count($sections),
      "subject" => $subject,
      "body_preview" => $bodyText
    ]);
    exit;
  }

  $resMail = sendOosSummaryEmail($to, $subject, $bodyHtml, $cc);

  if (empty($resMail["success"])) {
    $err = $resMail["error"] ?? "Unknown mailer error";
    echo json_encode([
      "status" => "error",
      "message" => "Email send failed",
      "error" => $err
    ]);
    exit;
  }

  echo json_encode([
    "status" => "ok",
    "date_est" => $today,
    "sent" => [
      "to" => $to,
      "cc" => implode(", ", $cc),
      "configs" => count($sections)
    ]
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
  exit;
}
