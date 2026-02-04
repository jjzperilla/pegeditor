<?php
require "auth.php";
requireAuth();
header("Content-Type: application/json");
require "db.php";

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

$capacity   = $_GET["capacity"] ?? null;
$interface  = $_GET["interface"] ?? null;
$drive_type = $_GET["drive_type"] ?? null;  // HDD / SSD
$condition  = $_GET["condition"] ?? null;
$days       = isset($_GET["days"]) ? max(1, (int)$_GET["days"]) : 30;

$drive_type = strtoupper(trim((string)$drive_type));
$drive_type_id = ($drive_type === "HDD") ? 1 : (($drive_type === "SSD") ? 2 : null);

if (!$capacity || !$interface || !$drive_type_id || !$condition) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "Missing filters"]);
  exit;
}

$stmt = $db->prepare("
  SELECT
    aph.peg_point_id,
    pp.label AS label,
    aph.day_date AS day,
    aph.adjusted_peg_price AS price
  FROM adjusted_peg_price_history aph
  JOIN peg_points pp
    ON pp.id = aph.peg_point_id
   AND pp.workspace_id = aph.workspace_id
  JOIN peg_configs pc
    ON pc.id = pp.config_id
   AND pc.workspace_id = pp.workspace_id
  WHERE pc.workspace_id = ?
    AND aph.workspace_id = ?
    AND pc.capacity = ?
    AND pc.interface = ?
    AND pc.drive_type_id = ?
    AND pc.condition_type = ?
    AND aph.day_date >= CURDATE() - INTERVAL ? DAY
  ORDER BY aph.day_date ASC, aph.peg_point_id ASC
");

$stmt->bind_param(
  "iissisi",
  $workspace_id,
  $workspace_id,
  $capacity,
  $interface,
  $drive_type_id,
  $condition,
  $days
);

$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($r = $res->fetch_assoc()) {
  $data[] = [
    "peg_point_id" => (int)$r["peg_point_id"],
    "label"        => $r["label"],
    "day"          => $r["day"],
    "price"        => (float)$r["price"]
  ];
}

echo json_encode(["status" => "ok", "workspace_id" => $workspace_id, "data" => $data]);
