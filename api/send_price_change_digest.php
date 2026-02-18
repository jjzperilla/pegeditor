<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();

require __DIR__ . '/db.php';
require_once __DIR__ . '/oos_mailer.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if (isset($_GET['workspace_id'])) $workspace_id = (int)$_GET['workspace_id'];
if ($workspace_id <= 0) $workspace_id = 1;

// -------------------- time (EST day_date is DATE) --------------------
$tzEST = new DateTimeZone('America/New_York');

$todayEST = new DateTime('today', $tzEST); // today 00:00 EST

// TEST override (optional)
if (isset($_GET['dry_run']) && !empty($_GET['test_date'])) {
  $d = DateTime::createFromFormat('Y-m-d', $_GET['test_date'], $tzEST);
  if ($d instanceof DateTime) $todayEST = $d;
}

// -------------------- Only send on Monday + Friday (EST) --------------------
$dow = (int)$todayEST->format('N'); // 1=Mon ... 5=Fri ... 7=Sun

// optional testing override
if (isset($_GET['dry_run']) && isset($_GET['test_dow'])) {
  $testDow = (int)$_GET['test_dow'];
  if ($testDow >= 1 && $testDow <= 7) $dow = $testDow;
}

if (!in_array($dow, [1, 5], true)) {
  echo json_encode([
    "status" => "ok",
    "message" => "Skipped: price change digest only sends on Monday and Friday (EST).",
    "date_est" => $todayEST->format('Y-m-d'),
    "day_name_est" => $todayEST->format('l')
  ]);
  exit;
}

// -------------------- Window logic --------------------
// Monday: include weekend changes since Friday (Fri->Mon)
// Friday: include week-to-date (Mon->Fri)
if ($dow === 1) {
  // Monday: go back to Friday
  $startEST = (clone $todayEST)->modify('-3 day'); // Fri
} else {
  // Friday: start at Monday of this week
  $startEST = (clone $todayEST)->modify('monday this week');
}

$endEST = $todayEST;

$startUTC = $startEST->format('Y-m-d');
$endUTC   = $endEST->format('Y-m-d');

$todayLabel  = $todayEST->format('Y-m-d');
$windowLabel = "{$startUTC} → {$endUTC}";


// recipients
$to = "jperilla@servertechsolutions.com";
$cc = [];

// get PRICE notification recipients
$type = "PRICE";

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
   * 1) Pull all peg points + their configs (so we can label/group)
   *    (Same idea as OOS: build config bundle)
   */
$qPoints = $db->prepare("
  SELECT
    p.id AS peg_point_id,
    p.config_id,
    p.label AS peg_point_label,
    c.capacity,
    c.interface,
    c.condition_type,
    c.peg_name,
    COALESCE(dt.label, c.drive_type_id) AS drive_type_label
  FROM peg_points p
  JOIN peg_configs c
    ON c.id = p.config_id
   AND c.workspace_id = p.workspace_id
  LEFT JOIN drive_types dt ON dt.id = c.drive_type_id
  WHERE p.workspace_id = ?
  ORDER BY p.config_id ASC, p.id ASC
");
$qPoints->bind_param("i", $workspace_id);
$qPoints->execute();
$rPoints = $qPoints->get_result();

  $pointsById = [];        // peg_point_id => meta
  $cfgById    = [];        // config_id => cfg meta
  while ($row = $rPoints->fetch_assoc()) {
    $ppid = (int)$row['peg_point_id'];
    $cid  = (int)$row['config_id'];

    $label = trim((string)($row['peg_point_label'] ?? ''));
    if ($label === '') $label = "PEG Point #{$ppid}";

    $pointsById[$ppid] = [
      'config_id' => $cid,
      'label' => $label,
    ];

    if (!isset($cfgById[$cid])) {
      $cfgById[$cid] = [
        'peg_name'   => (string)($row['peg_name'] ?? ''),
        'capacity'   => (string)($row['capacity'] ?? ''),
        'drive_type' => (string)($row['drive_type_label'] ?? ''),
        'interface'  => (string)($row['interface'] ?? ''),
        'condition'  => (string)($row['condition_type'] ?? ''),
      ];
    }
  }

  if (!$pointsById) {
    echo json_encode([
      "status" => "ok",
      "message" => "No peg_points found.",
      "date_est" => $todayLabel
    ]);
    exit;
  }

  /**
   * 2) Pull peg_point_history rows in the UTC window (ordered)
   */
$qHist = $db->prepare("
  SELECT
    h.peg_point_id,
    h.day_date,
    h.price,
    p.config_id,
    COALESCE(NULLIF(TRIM(p.label), ''), CONCAT('PEG Point #', h.peg_point_id)) AS peg_point_label
  FROM peg_point_history h
  JOIN peg_points p
    ON p.id = h.peg_point_id
   AND p.workspace_id = h.workspace_id
  WHERE h.workspace_id = ?
    AND h.day_date >= ?
    AND h.day_date <= ?
  ORDER BY p.config_id ASC, h.peg_point_id ASC, h.day_date ASC
");
$qHist->bind_param("iss", $workspace_id, $startUTC, $endUTC);
$qHist->execute();
$rHist = $qHist->get_result();

$windowRows = [];     // peg_point_id => rows
$pointMeta = [];      // peg_point_id => ['config_id'=>, 'label'=>]
while ($row = $rHist->fetch_assoc()) {
  $ppid = (int)$row['peg_point_id'];
  $cid  = (int)$row['config_id'];

  $pointMeta[$ppid] = [
    'config_id' => $cid,
    'label'     => (string)$row['peg_point_label'],
  ];

  $windowRows[$ppid][] = [
    'day_date' => (string)$row['day_date'],
    'price'    => (float)$row['price'],
  ];
}


  if (!$windowRows) {
    echo json_encode([
      "status" => "ok",
      "message" => "No peg_point_history rows in EST window (nothing to send).",
      "window_est" => $windowLabel,
      "window_utc" => [$startUTC, $endUTC],
    ]);
    exit;
  }

  /**
   * 3) For each peg_point, find the immediate previous price BEFORE startUTC
   *    then compute changes within window:
   *    - previous_price -> first_price_in_window (if different)
   *    - then each subsequent record inside window compared to previous record
   */
$qPrev = $db->prepare("
  SELECT price
  FROM peg_point_history
  WHERE workspace_id = ?
    AND peg_point_id = ?
    AND day_date < ?
  ORDER BY day_date DESC
  LIMIT 1
");

  $changesByConfig = []; // config_id => ['cfg'=>..., 'changes'=>[...]]
  $totalChanges = 0;

  foreach ($windowRows as $ppid => $rows) {
    $cid = (int)$pointMeta[$ppid]['config_id'];
$pLabel = (string)$pointMeta[$ppid]['label'];

    // init bundle
    if (!isset($changesByConfig[$cid])) {
      $cfg = $cfgById[$cid] ?? ['capacity'=>'','drive_type'=>'','interface'=>'','condition'=>''];
      $changesByConfig[$cid] = [
        'cfg' => $cfg,
        'changes' => []
      ];
    }

    // previous price before window
   $qPrev->bind_param("iis", $workspace_id, $ppid, $startUTC);
$qPrev->execute();
    $prevRow = $qPrev->get_result()->fetch_assoc();
    $prevPrice = $prevRow ? (float)$prevRow['price'] : null;

    // walk window rows
    $lastPrice = $prevPrice;
    foreach ($rows as $i => $rec) {
      $curPrice = (float)$rec['price'];

      // If lastPrice is null (no prior), skip comparison for first record
      if ($lastPrice === null) {
  // New point inside window: count as change
  $changesByConfig[$cid]['changes'][] = [
    'peg_point_id' => $ppid,
    'label' => $pLabel,
    'old' => null,
    'new' => $curPrice,
    'at'  => $rec['day_date'],
    'type'=> 'new_point'
  ];
  $totalChanges++;
} elseif ($curPrice != $lastPrice) {
  $changesByConfig[$cid]['changes'][] = [
    'peg_point_id' => $ppid,
    'label' => $pLabel,
    'old' => $lastPrice,
    'new' => $curPrice,
    'at'  => $rec['day_date'],
    'type'=> 'price_change'
  ];
  $totalChanges++;
}


      $lastPrice = $curPrice;
    }
  }

  // ✅ Only user if there are actual peg point changes
  // Also remove configs that ended up with zero changes
  foreach ($changesByConfig as $cid => $bundle) {
    if (empty($bundle['changes'])) unset($changesByConfig[$cid]);
  }

  if (!$changesByConfig) {
    echo json_encode([
      "status" => "ok",
      "message" => "No peg point PRICE changes in EST window; user not sent.",
      "window_est" => $windowLabel,
      "window_utc" => [$startUTC, $endUTC],
    ]);
    exit;
  }

  /**
   * 4) Prepare peg_history before/after for summary (per config with changes)
   */
function getBeforeAfterSnapshots(mysqli $db, int $workspaceId, int $configId): array {

  $stmtAfter = $db->prepare("
    SELECT saved_at, raw_price, base_price, adjusted_price, low_buy, high_buy
    FROM peg_history_log
    WHERE workspace_id = ?
      AND config_id = ?
    ORDER BY saved_at DESC
    LIMIT 1
  ");
  $stmtAfter->bind_param("ii", $workspaceId, $configId);
  $stmtAfter->execute();
  $after = $stmtAfter->get_result()->fetch_assoc();

  if (!$after) return [null, null];

  $stmtBefore = $db->prepare("
    SELECT saved_at, raw_price, base_price, adjusted_price, low_buy, high_buy
    FROM peg_history_log
    WHERE workspace_id = ?
      AND config_id = ?
      AND saved_at < ?
      AND (
        raw_price <> ?
        OR base_price <> ?
        OR adjusted_price <> ?
        OR low_buy <> ?
        OR high_buy <> ?
      )
    ORDER BY saved_at DESC
    LIMIT 1
  ");
  $stmtBefore->bind_param(
    "iisddddd",
    $workspaceId,
    $configId,
    $after['saved_at'],
    $after['raw_price'],
    $after['base_price'],
    $after['adjusted_price'],
    $after['low_buy'],
    $after['high_buy']
  );
  $stmtBefore->execute();
  $before = $stmtBefore->get_result()->fetch_assoc();

  if (!$before) $before = $after;

  return [$before, $after];
}



  /**
   * 5) Build sections (same style as OOS)
   */
  $sections = [];

  foreach ($changesByConfig as $configId => $bundle) {
    $cfg = $bundle['cfg'];
    $changes = $bundle['changes'];

    $pegName  = strtoupper(trim((string)($cfg['peg_name']   ?? '')));
    $capacity  = strtoupper(trim((string)($cfg['capacity']   ?? '')));
    $driveType = strtoupper(trim((string)($cfg['drive_type'] ?? '')));
    $iface     = strtoupper(trim((string)($cfg['interface']  ?? '')));
    $cond      = strtoupper(trim((string)($cfg['condition']  ?? '')));

    $changeLines[] = "DEBUG CONFIG_ID={$configId}";
      
    // Change lines
    $changeLines = [];
    foreach ($changes as $ch) {
      $lbl = trim((string)$ch['label']);
      $old = number_format((float)$ch['old'], 2);
      $new = number_format((float)$ch['new'], 2);
      $changeLines[] = "- {$lbl}: {$old} → {$new}";
    }
    if (!$changeLines) $changeLines[] = "";

    // Summary before/after from peg_history
   [$before, $after] = getBeforeAfterSnapshots($db, $workspace_id, $configId);
    
 if ($dryRun) {
  $summaryLines[] = "DEBUG config_id={$configId}";
  $summaryLines[] = "DEBUG startUTC={$startUTC} endUTC={$endUTC}";
  $summaryLines[] = "DEBUG before=" . ($before ? json_encode($before) : "NULL");
  $summaryLines[] = "DEBUG after=" . ($after ? json_encode($after) : "NULL");

  // Also show max saved_at for that config (quick query)
  $dbg = $db->prepare("SELECT MAX(saved_at) AS max_saved_at, MIN(saved_at) AS min_saved_at, COUNT(*) AS cnt FROM peg_history WHERE config_id = ?");
  $dbg->bind_param("i", $configId);
  $dbg->execute();
  $dbgRow = $dbg->get_result()->fetch_assoc();
  $summaryLines[] = "DEBUG peg_history cnt=" . ($dbgRow['cnt'] ?? '0') . " min=" . ($dbgRow['min_saved_at'] ?? 'NULL') . " max=" . ($dbgRow['max_saved_at'] ?? 'NULL');
}   
    
    
 $summaryLines = [];

if ($before && $after) {
  if ($before['saved_at'] >= $startUTC) {
  }
  $summaryLines[] = "Raw Price: " . number_format((float)$before['raw_price'], 2) . " → " . number_format((float)$after['raw_price'], 2);
  //$summaryLines[] = "Base PEG Price: " . number_format((float)$before['base_price'], 2) . " → " . number_format((float)$after['base_price'], 2);
  //$summaryLines[] = "Adjusted Sale Price: " . number_format((float)$before['adjusted_price'], 2) . " → " . number_format((float)$after['adjusted_price'], 2);
  //$summaryLines[] = "Low Buy Price: " . number_format((float)$before['low_buy'], 2) . " → " . number_format((float)$after['low_buy'], 2);
  //$summaryLines[] = "High Buy Price: " . number_format((float)$before['high_buy'], 2) . " → " . number_format((float)$after['high_buy'], 2);
} else {
  $summaryLines[] = "(No History data available for this config.)";
}



    $sectionText = implode("\n", [
      "PEG CONFIG: {$pegName}",
      "Specs: {$capacity} / {$driveType} / {$iface} / {$cond}",
      "",
      //"Peg Point Price Changes:",
      //implode("\n", $changeLines),
      //"",
      "Summary (Before → After):",
      implode("\n", $summaryLines),
      "",
      "<hr>",
    ]);

    // Use the newest change time for sorting (like OOS uses saved_at)
    $latestAt = end($changes)['at'] ?? $endUTC;

    $sections[] = [
      'latest_at' => (string)$latestAt,
      'text'      => $sectionText
    ];
  }

  // Sort newest first
  usort($sections, function($a, $b) {
    return strcmp($b['latest_at'], $a['latest_at']);
  });

  /**
   * 6) Build final user (plain text style)
   */
  $subject = "Price Change Summary Notification: {$todayLabel} (EST)";

  $bodyText = implode("\n", array_merge(
    [
      "Please see below the Weekly Price Change Summary (PEG Point price changes).",
      "Window (EST): {$windowLabel}",
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
      "date_est" => $todayLabel,
      "window_est" => $windowLabel,
      "window_utc" => [$startUTC, $endUTC],
      "configs_with_changes" => count($sections),
      "total_changes" => $totalChanges,
      "subject" => $subject,
      "body_preview" => $bodyText
    ]);
    exit;
  }

  // Send as HTML but preserve plain-text formatting
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
    "date_est" => $todayLabel,
    "window_est" => $windowLabel,
    "sent" => [
      "to" => $to,
      "cc" => implode(", ", $cc),
      "configs" => count($sections),
      "total_changes" => $totalChanges
    ]
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
  exit;
}
