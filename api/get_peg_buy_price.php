<?php
require "auth.php";
requireAuth();
header("Content-Type: application/json");
require "db.php";

$capacity  = $_GET['capacity']  ?? null;
$interface = $_GET['interface'] ?? null;
$condition = $_GET['condition'] ?? null;
$date      = $_GET['date']      ?? null; // optional YYYY-MM-DD

if (!$capacity || !$interface || !$condition) {
  echo json_encode([
    "status"  => "error",
    "message" => "Missing parameters"
  ]);
  exit;
}

/* =====================================================
   STEP 1: Resolve config_id
===================================================== */
$stmt = $db->prepare("
  SELECT id
  FROM peg_configs
  WHERE capacity = ?
    AND interface = ?
    AND condition_type = ?
  LIMIT 1
");

$stmt->bind_param("sss", $capacity, $interface, $condition);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
  echo json_encode([
    "status"  => "not_found",
    "message" => "No peg config found"
  ]);
  exit;
}

$configId = (int)$res->fetch_assoc()['id'];

/* =====================================================
   STEP 2: Fetch buy range from peg_history
===================================================== */
if ($date) {
  $stmt = $db->prepare("
    SELECT
      low_buy,
      high_buy,
      saved_at
    FROM peg_history
    WHERE config_id = ?
      AND DATE(saved_at) = ?
    ORDER BY saved_at DESC
    LIMIT 1
  ");
  $stmt->bind_param("is", $configId, $date);
} else {
  $stmt = $db->prepare("
    SELECT
      low_buy,
      high_buy,
      saved_at
    FROM peg_history
    WHERE config_id = ?
    ORDER BY saved_at DESC
    LIMIT 1
  ");
  $stmt->bind_param("i", $configId);
}

$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
  echo json_encode([
    "status"  => "not_found",
    "message" => "No peg history found"
  ]);
  exit;
}

$row = $res->fetch_assoc();

/* =====================================================
   RESPONSE
===================================================== */
echo json_encode([
  "status"    => "success",
  "config_id"=> $configId,
  "low_buy"  => (float)$row["low_buy"],
  "high_buy" => (float)$row["high_buy"],
  "saved_at" => $row["saved_at"]
]);
