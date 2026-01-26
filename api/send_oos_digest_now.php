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

$est = new DateTime('now', new DateTimeZone('America/New_York'));
$queueDay = $est->format('Y-m-d');
$nowEST   = $est->format('Y-m-d H:i:s');

// recipients
$to = "jperilla@servertechsolutions.com";
$cc = ["jperilla@servertechsolutions.com"];

// Base URL for link
$baseUrl = "http://167.71.244.63/index.php?config_id=";

$dryRun = isset($_GET['dry_run']) ? (int)$_GET['dry_run'] : 0;

try {
  // 1) Get all unsent queued items for today
  $q = $db->prepare("
    SELECT q.config_id, q.peg_point_id
    FROM oos_email_queue q
    WHERE q.queue_day = ?
      AND q.sent_at IS NULL
    ORDER BY q.config_id ASC, q.peg_point_id ASC
  ");
  $q->bind_param("s", $queueDay);
  $q->execute();
  $r = $q->get_result();

  $byConfig = [];
  while ($row = $r->fetch_assoc()) {
    $cid = (int)$row['config_id'];
    $pid = (int)$row['peg_point_id'];
    if (!isset($byConfig[$cid])) $byConfig[$cid] = [];
    $byConfig[$cid][] = $pid;
  }

  if (!$byConfig) {
    echo json_encode([
      "status" => "ok",
      "message" => "No queued OOS items for today (EST) to send.",
      "queue_day" => $queueDay
    ]);
    exit;
  }

  // 2) Build ONE email body with sections
  $sections = [];
  $allConfigIds = array_keys($byConfig);

  foreach ($byConfig as $configId => $pointIds) {
    // Load config header info
    $cfg = $db->prepare("
      SELECT c.id, c.capacity, c.interface, c.condition_type, c.peg_name, c.drive_type_id,
             dt.label AS drive_type_label
      FROM peg_configs c
      LEFT JOIN drive_types dt ON dt.id = c.drive_type_id
      WHERE c.id = ?
      LIMIT 1
    ");
    $cfg->bind_param("i", $configId);
    $cfg->execute();
    $cfgRow = $cfg->get_result()->fetch_assoc();

    if (!$cfgRow) {
      $sections[] = implode("\n", [
        "OOS items were marked on save.",
        "Peg Name: (unknown)",
        "Capacity: (unknown)",
        "Drive Type: (unknown)",
        "Interface: (unknown)",
        "Condition: (unknown)",
        "Peg Points:",
        "- (config not found: {$configId})",
        "Saved at (EST): {$nowEST}",
        "Link: {$baseUrl}{$configId}",
        ""
      ]);
      continue;
    }

    $pegName   = (string)($cfgRow['peg_name'] ?? '');
    $capacity  = (string)($cfgRow['capacity'] ?? '');
    $iface     = (string)($cfgRow['interface'] ?? '');
    $cond      = (string)($cfgRow['condition_type'] ?? '');
    $driveType = (string)($cfgRow['drive_type_label'] ?? ($cfgRow['drive_type_id'] ?? ''));

    // Load point labels
    $lines = [];
    if (count($pointIds) > 0) {
      $placeholders = implode(",", array_fill(0, count($pointIds), "?"));
      $types = str_repeat("i", count($pointIds));

      $sqlPts = "
        SELECT id, label
        FROM peg_points
        WHERE config_id = ?
          AND id IN ($placeholders)
        ORDER BY id ASC
      ";
      $stmtPts = $db->prepare($sqlPts);

      $bindTypes = "i" . $types;
      $params = array_merge([$configId], $pointIds);
      $stmtPts->bind_param($bindTypes, ...$params);

      $stmtPts->execute();
      $ptsRes = $stmtPts->get_result();

      while ($p = $ptsRes->fetch_assoc()) {
        $label = trim((string)($p['label'] ?? ''));
        $lines[] = "- " . ($label !== "" ? $label : ("PEG Point #" . (int)$p['id']));
      }
    }

    if (!$lines) $lines[] = "- (no points found)";

    // Section body (plain text, exactly your format)
    $sections[] = implode("\n", [
      "OOS items were marked on save.",
      "Peg Name: " . ($pegName !== "" ? $pegName : "(no name)"),
      "Capacity: {$capacity}",
      "Drive Type: {$driveType}",
      "Interface: {$iface}",
      "Condition: {$cond}",
      "Peg Points:",
      implode("\n", $lines),
      "Saved at (EST): {$nowEST}",
      "Link: {$baseUrl}{$configId}",
      ""
    ]);
  }

  $subject = "OOS Notification Report: {$queueDay}";
  $bodyText = implode("\n", $sections);     // one combined body
  $bodyHtml = nl2br($bodyText);             // convert new lines to <br>

  if ($dryRun) {
    echo json_encode([
      "status" => "ok",
      "queue_day" => $queueDay,
      "dry_run" => true,
      "configs" => count($allConfigIds),
      "subject" => $subject,
      "body_preview" => $bodyText
    ]);
    exit;
  }

  // 3) Send ONE email
  $resMail = sendOosSummaryEmail($to, $subject, $bodyHtml, $cc);

  if (empty($resMail["success"])) {
    $err = $resMail["error"] ?? "Unknown mailer error";

    // store error on all pending rows
    $updErr = $db->prepare("
      UPDATE oos_email_queue
      SET error = ?
      WHERE queue_day = ?
        AND sent_at IS NULL
    ");
    $updErr->bind_param("ss", $err, $queueDay);
    $updErr->execute();

    echo json_encode([
      "status" => "ok",
      "queue_day" => $queueDay,
      "sent" => [],
      "failed" => [["error" => $err]]
    ]);
    exit;
  }

  // 4) Mark ALL queued rows (today) as sent
  $ccStr = implode(",", $cc);
  $upd = $db->prepare("
    UPDATE oos_email_queue
    SET sent_at = NOW(),
        sent_to = ?,
        sent_cc = ?,
        sent_subject = ?,
        error = NULL
    WHERE queue_day = ?
      AND sent_at IS NULL
  ");
  $upd->bind_param("ssss", $to, $ccStr, $subject, $queueDay);
  $upd->execute();

  echo json_encode([
    "status" => "ok",
    "queue_day" => $queueDay,
    "sent" => [["configs" => count($allConfigIds)]],
    "failed" => []
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
  exit;
}
