<?php
require "auth.php";
requireAuth();
header('Content-Type: application/json');
require 'db.php';

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

$raw = file_get_contents('php://input');
error_log("🟢 RAW:\n" . $raw);

$data = json_decode($raw, true);

if (
  !$data ||
  !isset($data['date']) ||
  !is_array($data['points'])
) {
  http_response_code(400);
  echo json_encode([
    'status' => 'error',
    'message' => 'Invalid payload'
  ]);
  exit;
}

$date   = (string)$data['date'];   // YYYY-MM-DD
$points = $data['points'];

/* ===============================
 TIME
================================ */
$estNow = new DateTime('now', new DateTimeZone('America/New_York'));
$pegDateTimeEST = $estNow->format('Y-m-d H:i:s');

$db->begin_transaction();

try {

  /* ===============================
     1) UPSERT HISTORY (workspace-scoped)
     IMPORTANT: Requires UNIQUE(workspace_id, peg_point_id, day_date)
  =============================== */
  $stmtHistory = $db->prepare("
    INSERT INTO peg_point_history
      (workspace_id, peg_point_id, day_date, price, qty, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      price = VALUES(price),
      qty   = VALUES(qty),
      created_at = VALUES(created_at)
  ");

  /* ===============================
     2) CHECK LATEST DATE (PER WORKSPACE)
  =============================== */
  $latestStmt = $db->prepare("
    SELECT MAX(day_date) AS max_date
    FROM peg_point_history
    WHERE workspace_id = ?
  ");
  $latestStmt->bind_param("i", $workspace_id);
  $latestStmt->execute();
  $latestDateRes = $latestStmt->get_result()->fetch_assoc();

  $latestDate = $latestDateRes['max_date'] ?? null;
  $isLatest   = ($latestDate === null || $date >= $latestDate);

  error_log("📅 WS={$workspace_id} SAVE DATE={$date} | LATEST={$latestDate} | isLatest=" . ($isLatest ? 'YES' : 'NO'));

  /* ===============================
     3) UPDATE LIVE peg_points (workspace-scoped)
        (price + qty ONLY)
  =============================== */
  $stmtUpdateLive = $db->prepare("
    UPDATE peg_points
    SET price = ?, qty = ?
    WHERE workspace_id = ?
      AND id = ?
  ");

  foreach ($points as $p) {

    $pegPointId = (int)($p['peg_point_id'] ?? 0);
    if ($pegPointId <= 0) continue;

    $price = (float)($p['price'] ?? 0);
    $qty   = (int)($p['qty'] ?? 0);

    // ---- A) SAVE HISTORY ----
    $stmtHistory->bind_param(
      "iisdis",
      $workspace_id,
      $pegPointId,
      $date,
      $price,
      $qty,
      $pegDateTimeEST
    );
    $stmtHistory->execute();

    error_log("➡ HISTORY SAVED ws={$workspace_id} id={$pegPointId} price={$price} qty={$qty}");

    // ---- B) UPDATE LIVE ONLY IF LATEST ----
    if ($isLatest) {
      // ✅ FIXED TYPES: price=double, qty=int, workspace=int, id=int
      $stmtUpdateLive->bind_param("diii", $price, $qty, $workspace_id, $pegPointId);
      $stmtUpdateLive->execute();

      error_log("✅ LIVE UPDATED ws={$workspace_id} id={$pegPointId}");
    }
  }

  $db->commit();

  echo json_encode([
    'status'       => 'success',
    'workspace_id' => $workspace_id,
    'isLatest'     => $isLatest,
    'latestDate'   => $latestDate
  ]);

} catch (Throwable $e) {

  $db->rollback();
  error_log("❌ ERROR: " . $e->getMessage());

  http_response_code(500);
  echo json_encode([
    'status' => 'error',
    'message' => $e->getMessage()
  ]);
}
