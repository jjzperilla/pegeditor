<?php
require "auth.php";
requireAuth();

require "db.php";
header("Content-Type: application/json");

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

/*
  Optional filters (safe defaults)
*/
$capacity  = $_GET["capacity"]  ?? null;
$days      = isset($_GET["days"]) ? (int)$_GET["days"] : 90;

$params = [];
$types  = "";

/*
  Base query (workspace-scoped)
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
JOIN peg_configs pc
  ON pc.id = ph.config_id
 AND pc.workspace_id = ph.workspace_id
LEFT JOIN drive_types dt ON dt.id = pc.drive_type_id
WHERE ph.workspace_id = ?
";

$params[] = $workspace_id;
$types   .= "i";

/*
  Capacity filter
*/
if ($capacity) {
  $sql     .= " AND pc.capacity = ?";
  $params[] = $capacity;
  $types   .= "s";
}

/*
  Date range filter
*/
if ($days > 0) {
  $sql     .= " AND ph.saved_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
  $params[] = $days;
  $types   .= "i";
}

$sql .= " ORDER BY ph.saved_at DESC LIMIT 500";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
  $rows[] = $row;
}

echo json_encode([
  "status"       => "ok",
  "workspace_id" => $workspace_id,
  "data"         => $rows
]);
