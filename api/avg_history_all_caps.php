<?php
require "auth.php";
requireAuth();

header("Content-Type: application/json");
require "db.php"; // gives $db

$days = isset($_GET["days"]) ? (int)$_GET["days"] : 90;
if ($days < 1) $days = 90;
if ($days > 3650) $days = 3650;

// If you prefer SUM instead of AVG, replace AVG(h.price) with SUM(h.price)
$sql = "
  SELECT
      cfg.capacity AS capacity,
  h.day_date   AS date,
  AVG(h.price) AS avg
FROM peg_point_history h
JOIN peg_points p     ON p.id = h.peg_point_id
JOIN peg_configs cfg  ON cfg.id = p.config_id
WHERE h.day_date >= (CURDATE() - INTERVAL ? DAY)
  AND p.weight IS NOT NULL
  AND p.weight > 0
  GROUP BY cfg.capacity, h.day_date
  ORDER BY cfg.capacity ASC, h.day_date ASC
";


$stmt = $db->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "Prepare failed", "detail" => $db->error]);
  exit;
}

$stmt->bind_param("i", $days);
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
