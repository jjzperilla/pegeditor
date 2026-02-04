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

$point_id = isset($_GET['point_id']) ? (int)$_GET['point_id'] : 0;
$days     = isset($_GET['days']) ? max(1, (int)$_GET['days']) : 30;

// optional: cap so someone can’t request huge payloads
$days = min($days, 365);

if ($point_id <= 0) {
    http_response_code(400);
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
    WHERE workspace_id = ?
      AND peg_point_id = ?
    ORDER BY STR_TO_DATE(day_date, '%Y-%m-%d') DESC
    LIMIT $days
");
$stmt->bind_param("ii", $workspace_id, $point_id);
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
    'status'       => 'success',
    'workspace_id' => $workspace_id,
    'history'      => $history
]);
