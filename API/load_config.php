<?php
header('Content-Type: application/json');
require 'db.php';

$payload = json_decode(file_get_contents('php://input'), true);

if (!isset($payload['config_id'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing config_id'
    ]);
    exit;
}

$config_id = intval($payload['config_id']);

// -----------------------------
// LOAD CONFIG
// -----------------------------
$stmt = $db->prepare("
    SELECT id, capacity, interface, condition_type, inventory_mode, peg_name
    FROM peg_configs
    WHERE id = ?
");
$stmt->bind_param("i", $config_id);
$stmt->execute();
$config = $stmt->get_result()->fetch_assoc();

if (!$config) {
    echo json_encode([
        'status' => 'not_found'
    ]);
    exit;
}

// -----------------------------
// LOAD PEG POINTS
// -----------------------------
$points = [];
$res = $db->query("
    SELECT id, label, channel, url, price, weight
    FROM peg_points
    WHERE config_id = $config_id
");
while ($row = $res->fetch_assoc()) {
    $points[] = $row;
}

// -----------------------------
// LOAD MODIFIERS
// -----------------------------
$modifiers = [];
$res = $db->query("
    SELECT id, label, amount
    FROM peg_modifiers
    WHERE config_id = $config_id
");
while ($row = $res->fetch_assoc()) {
    $modifiers[] = $row;
}

// -----------------------------
// LOAD SALES DATA
// -----------------------------
$sales = [];
$res = $db->query("
    SELECT day_label, sale_price, market_price, volume
    FROM sales_data
    WHERE config_id = $config_id
");
while ($row = $res->fetch_assoc()) {
    $sales[] = $row;
}

// -----------------------------
// RETURN JSON
// -----------------------------
echo json_encode([
    'status' => 'success',
    'config_id' => $config['id'],
    'capacity' => $config['capacity'],
    'interface' => $config['interface'],
    'condition_type' => $config['condition_type'],
    'inventoryMode' => $config['inventory_mode'],
    'peg_name' => $config['peg_name'],
    'peg' => [
        'points' => $points,
        'modifiers' => $modifiers,
        'sales' => $sales
    ]
]);
