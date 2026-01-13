<?php
require "auth.php";
requireAuth();

require "db.php";
header("Content-Type: application/json");

/*
  Optional filters (safe defaults)
*/
$capacity  = $_GET["capacity"]  ?? null;
$days      = isset($_GET["days"]) ? (int)$_GET["days"] : 90;

$params = [];
$types  = "";

/*
  Base query
*/
$sql = "
SELECT
  ph.id,
  ph.saved_at,
  pc.capacity,
  dt.label AS drive_type,
  pc.interface,
  pc.condition_type,
  ph.peg_price
FROM peg_history ph
JOIN peg_configs pc ON pc.id = ph.config_id
LEFT JOIN drive_types dt ON dt.id = pc.drive_type_id
WHERE 1 = 1
";

/*
  Capacity filter
*/
if ($capacity) {
  $sql    .= " AND pc.capacity = ?";
  $params[] = $capacity;
  $types   .= "s";
}

/*
  Date range filter
*/
if ($days > 0) {
  $sql    .= " AND ph.saved_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
  $params[] = $days;
  $types   .= "i";
}

$sql .= " ORDER BY ph.saved_at DESC LIMIT 500";

$stmt = $db->prepare($sql);

if ($params) {
  $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
  $rows[] = $row;
}

echo json_encode([
  "status" => "ok",
  "data"   => $rows
]);
