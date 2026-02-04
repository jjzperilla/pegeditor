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
if ($config_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid config_id'
    ]);
    exit;
}

/* ===============================
   LOAD CONFIG (scoped)
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
      AND workspace_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $config_id, $workspace_id);
$stmt->execute();
$config = $stmt->get_result()->fetch_assoc();

if (!$config) {
    http_response_code(404);
    echo json_encode(['status' => 'not_found']);
    exit;
}

$margin = isset($config['margin_percent']) && $config['margin_percent'] !== null
    ? (float)$config['margin_percent']
    : 80.0;

/* ===============================
   LOAD PEG POINTS (scoped)
================================ */
$points = [];
$stmt = $db->prepare("
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
    WHERE config_id = ?
      AND workspace_id = ?
    ORDER BY created_at ASC
");
$stmt->bind_param("ii", $config_id, $workspace_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $row['price']      = (float)$row['price'];
    $row['qty']        = (int)$row['qty'];
    $row['weight']     = (float)$row['weight'];
    $points[] = $row;
}

/* ===============================
   LOAD MODIFIERS (scoped)
================================ */
$modifiers = []; // ✅ FIX: initialize
$stmt = $db->prepare("
    SELECT
        id,
        label,
        amount,
        modifier_type
    FROM peg_modifiers
    WHERE config_id = ?
      AND workspace_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("ii", $config_id, $workspace_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $row['amount'] = (float)$row['amount'];
    $row['modifier_type'] = $row['modifier_type'] ?? 'buy';
    $modifiers[] = $row;
}

/* ===============================
   LOAD SALES DATA (scoped)
================================ */
$sales = [];
$stmt = $db->prepare("
    SELECT day_label, sale_price, market_price, volume
    FROM sales_data
    WHERE config_id = ?
      AND workspace_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("ii", $config_id, $workspace_id);
$stmt->execute();
$res = $stmt->get_result();

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
    'workspace_id'   => $workspace_id,

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
