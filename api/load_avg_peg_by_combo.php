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

$capacity = $_GET['capacity'] ?? null;
$days     = isset($_GET['days']) ? (int)$_GET['days'] : 30;

if (!$capacity || $days <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid params'
    ]);
    exit;
}

/*
  Average PEG price per day
  - Source: peg_point_history (REAL history)
  - Grouped by interface + condition
  - One row PER DAY
  - Scoped by workspace_id
*/

$sql = "
SELECT
  h.day_date AS day,
  c.interface,
  c.condition_type,
  AVG(h.price) AS avg_price
FROM peg_point_history h
JOIN (
  SELECT p1.id, p1.config_id
  FROM peg_points p1
  JOIN (
    SELECT workspace_id, config_id, label, MAX(id) AS max_id
    FROM peg_points
    WHERE workspace_id = ?
      AND weight IS NOT NULL AND weight > 0
    GROUP BY workspace_id, config_id, label
  ) x ON x.max_id = p1.id
  WHERE p1.workspace_id = ?
) ap ON ap.id = h.peg_point_id
JOIN peg_configs c
  ON c.id = ap.config_id
 AND c.workspace_id = ?
WHERE h.workspace_id = ?
  AND c.capacity = ?
  AND h.day_date >= (CURDATE() - INTERVAL ? DAY)
GROUP BY
  h.day_date,
  c.interface,
  c.condition_type
ORDER BY
  h.day_date ASC
";

$stmt = $db->prepare($sql);
$stmt->bind_param('iiiisi', $workspace_id, $workspace_id, $workspace_id, $workspace_id, $capacity, $days);
$stmt->execute();
$res = $stmt->get_result();

$data = [];

while ($r = $res->fetch_assoc()) {
    $key = strtolower($r['interface']) . '|' . strtolower($r['condition_type']);

    if (!isset($data[$key])) {
        $data[$key] = [];
    }

    $data[$key][] = [
        'date'  => $r['day'],
        'price' => round((float)$r['avg_price'], 2)
    ];
}

echo json_encode([
    'status'       => 'success',
    'workspace_id' => $workspace_id,
    'data'         => $data
]);
