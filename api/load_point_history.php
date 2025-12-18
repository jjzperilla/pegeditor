<?php
header('Content-Type: application/json');
require 'db.php';

$point_id = isset($_GET['point_id']) ? (int)$_GET['point_id'] : 0;
$days     = isset($_GET['days']) ? max(1, (int)$_GET['days']) : 30;

if (!$point_id) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing point_id'
    ]);
    exit;
}

$stmt = $db->prepare("
    SELECT
        day_date AS date,
        price
    FROM peg_point_history
    WHERE peg_point_id = ?
    ORDER BY STR_TO_DATE(day_date, '%Y-%m-%d') DESC
    LIMIT $days
");
$stmt->bind_param("i", $point_id);
$stmt->execute();

$res = $stmt->get_result();
$history = [];

while ($row = $res->fetch_assoc()) {
    $history[] = [
        'date'  => $row['date'],
        'price' => (float)$row['price']
    ];
}

// Oldest → newest (Chart.js wants this)
$history = array_reverse($history);

echo json_encode([
    'status'  => 'success',
    'history' => $history
]);
