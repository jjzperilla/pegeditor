<?php
header("Content-Type: application/json");
require "db.php";

$capacity  = $_GET['capacity']  ?? null;
$interface = $_GET['interface'] ?? null;
$condition = $_GET['condition'] ?? null;

if (!$capacity || !$interface || !$condition) {
  echo json_encode([
    "status" => "error",
    "message" => "Missing parameters"
  ]);
  exit;
}

/*
  STEP 1:
  Resolve config_id from peg_configs
*/
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
    "status" => "not_found",
    "message" => "No peg config found"
  ]);
  exit;
}

$config = $res->fetch_assoc();
$configId = (int)$config['id'];

/*
  STEP 2:
  Get latest peg_history for this config
*/
$stmt = $db->prepare("
  SELECT
    adjusted_price,
    margin_percent
  FROM peg_history
  WHERE config_id = ?
  ORDER BY saved_at DESC
  LIMIT 1
");

$stmt->bind_param("i", $configId);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
  echo json_encode([
    "status" => "not_found",
    "message" => "No peg history found"
  ]);
  exit;
}

$row = $res->fetch_assoc();

echo json_encode([
  "status"         => "success",
  "config_id"      => $configId,
  "adjusted_price" => (float)$row["adjusted_price"],
  "margin_percent" => (float)$row["margin_percent"]
]);
