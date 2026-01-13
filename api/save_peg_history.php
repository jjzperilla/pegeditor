<?php
require "auth.php";
requireAuth();
header('Content-Type: application/json');
require 'db.php';

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

$date   = $data['date'];          // YYYY-MM-DD
$points = $data['points'];

/* ===============================
 TIME
================================ */
$estNow = new DateTime('now', new DateTimeZone('America/New_York'));

$pegDateTimeEST = $estNow->format('Y-m-d H:i:s');
$pegDateEST     = $estNow->format('Y-m-d'); 

$db->begin_transaction();

try {

  /* ===============================
     1) UPSERT HISTORY
  =============================== */
  $stmtHistory = $db->prepare("
    INSERT INTO peg_point_history
      (peg_point_id, day_date, price, qty, created_at)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      price = VALUES(price),
      qty   = VALUES(qty),
      created_at = VALUES(created_at)
  ");

  /* ===============================
     2) CHECK LATEST DATE (GLOBAL)
  =============================== */
  $latestDateRes = $db->query("
    SELECT MAX(day_date) AS max_date
    FROM peg_point_history
  ")->fetch_assoc();

  $latestDate = $latestDateRes['max_date'] ?? null;
  $isLatest   = ($latestDate === null || $date >= $latestDate);

  error_log("📅 SAVE DATE={$date} | LATEST={$latestDate} | isLatest=" . ($isLatest ? 'YES' : 'NO'));

  /* ===============================
     3) UPDATE LIVE peg_points
        (price + qty ONLY)
  =============================== */
  $stmtUpdateLive = $db->prepare("
    UPDATE peg_points
    SET price = ?, qty = ?
    WHERE id = ?
  ");

  foreach ($points as $p) {

    $pegPointId = (int)($p['peg_point_id'] ?? 0);
    if ($pegPointId <= 0) continue;

    $price = (float)($p['price'] ?? 0);
    $qty   = (int)($p['qty'] ?? 0);

    // ---- A) SAVE HISTORY ----
    $stmtHistory->bind_param(
      "isdis",
      $pegPointId,
      $date,
      $price,
      $qty,
      $pegDateTimeEST
    );
    $stmtHistory->execute();

    error_log("➡ HISTORY SAVED id={$pegPointId} price={$price} qty={$qty}");

    // ---- B) UPDATE LIVE ONLY IF LATEST ----
    if ($isLatest) {
      $stmtUpdateLive->bind_param(
        "ddi",
        $price,
        $qty,
        $pegPointId
      );
      $stmtUpdateLive->execute();

      error_log("✅ LIVE UPDATED id={$pegPointId}");
    }
  }

  $db->commit();

  echo json_encode([
    'status'   => 'success',
    'isLatest' => $isLatest
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
