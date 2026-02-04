<?php
require "auth.php";
requireAuth();

header("Content-Type: application/json");
require "db.php";

// workspace (from session)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$workspace_id = (int)($_SESSION["workspace_id"] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

$days = isset($_GET["days"]) ? (int)$_GET["days"] : 90;
if ($days < 1) $days = 90;
if ($days > 3650) $days = 3650;

$sql = "
  SELECT
    cfg.capacity AS capacity,
    h.day_date   AS date,
    AVG(h.price) AS avg
  FROM peg_point_history h
  JOIN peg_points p     ON p.id = h.peg_point_id
  JOIN peg_configs cfg  ON cfg.id = p.config_id
  WHERE h.workspace_id = ?
    AND h.day_date >= (CURDATE() - INTERVAL ? DAY)
    AND p.weight IS NOT NULL
    AND p.weight > 0
  GROUP BY cfg.capacity, h.day_date
  ORDER BY cfg.capacity ASC, h.day_date ASC
";

$stmt = $db->prepare($sql);
$stmt->bind_param("ii", $workspace_id, $days);
$stmt->execute();

$res = $stmt->get_result();
$rows = [];

while ($r = $res->fetch_assoc()) {
  $rows[] = [
    "capacity" => $r["capacity"],
    "date"     => $r["date"],
    "avg"      => (float)$r["avg"],
  ];
}

echo json_encode(["status" => "ok", "rows" => $rows]);
