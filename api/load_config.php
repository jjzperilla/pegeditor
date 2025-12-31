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

$config_id = (int)$payload['config_id'];

/* ===============================
   LOAD CONFIG
================================ */
$stmt = $db->prepare("
    SELECT
        id,
        capacity,
        interface,
        condition_type,
        peg_name,
        margin_percent
    FROM peg_configs
    WHERE id = ?
");
$stmt->bind_param("i", $config_id);
$stmt->execute();
$config = $stmt->get_result()->fetch_assoc();

if (!$config) {
    echo json_encode(['status' => 'not_found']);
    exit;
}

$margin = isset($config['margin_percent'])
    ? (float)$config['margin_percent']
    : 80;

/* ===============================
   LOAD PEG POINTS
================================ */
$points = [];
$res = $db->query("
    SELECT
        id,
        label,
        channel,
        url,
        price,
        qty,
        weight,
        created_at
    FROM peg_points
    WHERE config_id = $config_id
    ORDER BY created_at ASC
");

while ($row = $res->fetch_assoc()) {
    $row['price']      = (float)$row['price'];
    $row['qty']        = (int)$row['qty'];
    $row['weight']     = (float)$row['weight'];
    $row['created_at'] = $row['created_at'];
    $points[] = $row;
}

/* ===============================
   LOAD MODIFIERS
================================ */
$modifiers = [];
$res = $db->query("
    SELECT id, label, amount
    FROM peg_modifiers
    WHERE config_id = $config_id
    ORDER BY id ASC
");

while ($row = $res->fetch_assoc()) {
    $row['amount'] = (float)$row['amount'];
    $modifiers[] = $row;
}

/* ===============================
   LOAD SALES DATA
================================ */
$sales = [];
$res = $db->query("
    SELECT day_label, sale_price, market_price, volume
    FROM sales_data
    WHERE config_id = $config_id
    ORDER BY id ASC
");

while ($row = $res->fetch_assoc()) {
    $row['sale_price']   = (float)$row['sale_price'];
    $row['market_price'] = (float)$row['market_price'];
    $row['volume']       = (int)$row['volume'];
    $sales[] = $row;
}

/* ===============================
   RESPONSE
================================ */
echo json_encode([
    'status'         => 'success',
    'config_id'      => (int)$config['id'],
    'capacity'       => $config['capacity'],
    'interface'      => $config['interface'],
    'condition_type' => $config['condition_type'],
    'peg_name'       => $config['peg_name'],

    // SEND BOTH (safe + future-proof)
    'margin_percent' => $margin,
    'marginPercent'  => $margin,

    'peg' => [
        'points'    => $points,
        'modifiers' => $modifiers,
        'sales'     => $sales
    ]
]);

