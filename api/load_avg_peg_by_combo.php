<?php
header('Content-Type: application/json');
require 'db.php';

$capacity = $_GET['capacity'] ?? null;
$days     = isset($_GET['days']) ? (int)$_GET['days'] : 30;

if (!$capacity || $days <= 0) {
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
*/

$sql = "
SELECT
  h.day_date AS day,
  c.interface,
  c.condition_type,
  AVG(h.price) AS avg_price
FROM peg_point_history h
JOIN peg_points p   ON p.id = h.peg_point_id
JOIN peg_configs c  ON c.id = p.config_id
WHERE
  c.capacity = ?
  AND h.day_date >= CURDATE() - INTERVAL ? DAY
GROUP BY
  h.day_date,
  c.interface,
  c.condition_type
ORDER BY
  h.day_date ASC
";

$stmt = $db->prepare($sql);
$stmt->bind_param('si', $capacity, $days);
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
    'status' => 'success',
    'data'   => $data
]);
