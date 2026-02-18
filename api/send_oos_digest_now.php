<?php
// api/send_oos_digest_now.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();

require __DIR__ . '/db.php';
require_once __DIR__ . '/oos_mailer.php';

/* --------------------
   workspace_id
-------------------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

// Optional manual test override: ?workspace_id=2
if (isset($_GET['workspace_id'])) {
  $workspace_id = (int)$_GET['workspace_id'];
  if ($workspace_id <= 0) $workspace_id = 1;
}

// -------------------- time --------------------
$est     = new DateTime('now', new DateTimeZone('America/New_York'));
$today   = $est->format('Y-m-d');
$nowEST  = $est->format('Y-m-d H:i:s');

// recipients
$to = "jperilla@servertechsolutions.com";
$cc = [];

// get PRICE notification recipients
$type = "OOS";

$stmt = $db->prepare("
  SELECT c.email
  FROM email_notification_subscriptions s
  JOIN email_contacts c ON c.id = s.contact_id
  WHERE s.notif_type = ?
    AND s.is_active = 1
    AND c.is_active = 1
  ORDER BY c.email ASC
");
$stmt->bind_param("s", $type);
$stmt->execute();

$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
  $cc[] = $row["email"];
}

// dry run via web: ?dry_run=1
$dryRun = isset($_GET['dry_run']) ? (int)$_GET['dry_run'] : 0;

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

      c.capacity,
      c.interface,
      c.condition_type,
      COALESCE(dt.label, c.drive_type_id) AS drive_type_label
    FROM peg_points p
    JOIN peg_configs c ON c.id = p.config_id
    LEFT JOIN drive_types dt ON dt.id = c.drive_type_id
    WHERE p.workspace_id = ?
      AND c.workspace_id = ?
      AND p.oos = 1
    ORDER BY p.config_id ASC, p.id ASC
  ");
  $q->bind_param("ii", $workspace_id, $workspace_id);
  $q->execute();
  $r = $q->get_result();

  $byConfig = []; // config_id => ['cfg'=>..., 'points'=>[ ['label'=>..,'url'=>..] ]]
  while ($row = $r->fetch_assoc()) {
    $cid = (int)$row['config_id'];

    if (!isset($byConfig[$cid])) {
      $byConfig[$cid] = [
        'cfg' => [
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

    $url = trim((string)($row['peg_point_url'] ?? ''));

    $byConfig[$cid]['points'][] = [
      'label' => $label,
      'url'   => $url
    ];
  }

  if (!$byConfig) {
    echo json_encode([
      "status" => "ok",
      "message" => "No OOS peg points found (nothing to send).",
      "date_est" => $today,
      "workspace_id" => $workspace_id
    ]);
    exit;
  }

  /**
   * 2) Latest saved_at per config for sorting only (NOT shown in user)
   */
  $latestStmt = $db->prepare("
    SELECT MAX(saved_at) AS latest_saved_at
    FROM peg_history
    WHERE workspace_id = ?
      AND config_id = ?
  ");

  $sections = []; // each: ['saved_at' => 'Y-m-d H:i:s', 'text' => '...']

  foreach ($byConfig as $configId => $bundle) {
    $cfg    = $bundle['cfg'];
    $points = $bundle['points'];

    // latest saved_at (only for sorting)
    $latestStmt->bind_param("ii", $workspace_id, $configId);
    $latestStmt->execute();
    $latestRow = $latestStmt->get_result()->fetch_assoc();
    $latestSavedAt = (string)($latestRow['latest_saved_at'] ?? '');
    if ($latestSavedAt === '') $latestSavedAt = $nowEST;

    $capacity  = strtoupper(trim((string)($cfg['capacity']   ?? '')));
    $driveType = strtoupper(trim((string)($cfg['drive_type'] ?? '')));
    $iface     = strtoupper(trim((string)($cfg['interface']  ?? '')));
    $cond      = strtoupper(trim((string)($cfg['condition']  ?? '')));

    // Build point lines in Google/Gmail-friendly style
    $pointLines = [];
    foreach ($points as $p) {
      $pLabel = trim((string)($p['label'] ?? 'PEG Point'));
      $pUrl   = trim((string)($p['url'] ?? ''));

      if ($pUrl !== '') {
        $safeUrl = htmlspecialchars($pUrl, ENT_QUOTES, 'UTF-8');
        $pointLines[] = "- {$pLabel}:  <a href=\"{$safeUrl}\" target=\"_blank\">Product Link</a>";
      } else {
        $pointLines[] = "- {$pLabel}";
      }
    }
    if (!$pointLines) $pointLines[] = "";

    $sectionText = implode("\n", [
      "PEG CONFIG:",
      "Specs: {$capacity} / {$driveType} / {$iface} / {$cond}",
      "",
      "Peg Points Marked OOS:",
      implode("\n", $pointLines),
      "",
      "<hr>",
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
   * 3) Build final user (plain text style)
   */
  $subject = "OOS Summary Notification - Peg Points Marked Out of Stock: {$today} (EST)";

  $bodyText = implode("\n", array_merge(
    [
      "Please see below the Out-of-Stock (OOS) Summary for peg points recently marked as unavailable.",
      "",
      "<hr>",
    ],
    array_column($sections, 'text'),
    [
      "Please review the affected peg configurations and take any necessary action.",
      ""
    ]
  ));

  if ($dryRun) {
    echo json_encode([
      "status" => "ok",
      "dry_run" => true,
      "date_est" => $today,
      "workspace_id" => $workspace_id,
      "configs" => count($sections),
      "subject" => $subject,
      "body_preview" => $bodyText
    ]);
    exit;
  }

  $bodyHtml = nl2br($bodyText);

  $resMail = sendOosSummaryuser($to, $subject, $bodyHtml, $cc);

  if (empty($resMail["success"])) {
    $err = $resMail["error"] ?? "Unknown mailer error";
    echo json_encode([
      "status" => "error",
      "message" => "user send failed",
      "error" => $err
    ]);
    exit;
  }

  echo json_encode([
    "status" => "ok",
    "date_est" => $today,
    "workspace_id" => $workspace_id,
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
