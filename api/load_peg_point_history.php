<?php
header('Content-Type: application/json');
require 'db.php';

$capacity   = $_GET['capacity']   ?? null;
$interface  = $_GET['interface']  ?? null;
$driveType  = $_GET['drive_type'] ?? null; // HDD / SSD
$condition  = $_GET['condition']  ?? null;
$days       = isset($_GET['days']) ? (int)$_GET['days'] : null;

// Drive type map (must match app)
$DRIVE_TYPE_MAP = [
  'HDD' => 1,
  'SSD' => 2
];

$driveTypeId =
  $DRIVE_TYPE_MAP[strtoupper($driveType ?? '')] ?? null;

if (!$capacity || !$interface || !$driveTypeId || !$condition) {
  echo json_encode([
    'status' => 'error',
    'message' => 'Missing or invalid filters'
  ]);
  exit;
}

$sql = "
SELECT
  pph.peg_point_id,
  pp.label AS peg_label,
  DATE(pph.day_date) AS day,
  pph.price
FROM peg_point_history pph
JOIN peg_points pp
  ON pp.id = pph.peg_point_id
JOIN peg_configs pc
  ON pc.id = pp.config_id
WHERE
  pc.capacity = ?
  AND pc.interface = ?
  AND pc.drive_type_id = ?
  AND pc.condition_type = ?
";

$types  = "ssis";
$params = [$capacity, $interface, $driveTypeId, $condition];

if ($days) {
  $sql .= " AND pph.day_date >= NOW() - INTERVAL ? DAY";
  $types .= "i";
  $params[] = $days;
}

$sql .= "
ORDER BY
  pph.peg_point_id,
  day ASC
";

$stmt = $db->prepare($sql);
if (!$stmt) {
  echo json_encode([
    'status' => 'error',
    'message' => $db->error
  ]);
  exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
  $data[] = $row;
}

echo json_encode([
  'status' => 'ok',
  'data' => $data
]);
