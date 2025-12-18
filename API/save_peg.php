<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
require 'db.php';

/* ===============================
   1) READ JSON
================================ */
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty request body']);
    exit;
}

$payload = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

/* ===============================
   2) INPUTS
================================ */
$capacity  = trim($payload['capacity'] ?? '');
$interface = strtolower(trim($payload['interface'] ?? ''));
$condition = strtolower(trim($payload['condition'] ?? ''));

$inventory = $payload['inventoryMode'] ?? 'balanced';
$pegName   = $payload['peg_name'] ?? null;

$peg       = $payload['peg'] ?? [];
$points    = $peg['points'] ?? [];
$modifiers = $peg['modifiers'] ?? [];
$sales     = $peg['sales'] ?? [];

if (!$capacity || !$interface || !$condition) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

$db->begin_transaction();

try {

    /* ===============================
       3) RESOLVE CONFIG (UPDATE OR CREATE)
    ================================ */
    $config_id = null;

    $find = $db->prepare("
        SELECT id FROM peg_configs
        WHERE capacity=? AND interface=? AND condition_type=?
        LIMIT 1
    ");
    $find->bind_param("sss", $capacity, $interface, $condition);
    $find->execute();
    $res = $find->get_result();

    if ($row = $res->fetch_assoc()) {
        // UPDATE MODE
        $config_id = (int)$row['id'];

        $upd = $db->prepare("
            UPDATE peg_configs
            SET inventory_mode=?, peg_name=?
            WHERE id=?
        ");
        $upd->bind_param("ssi", $inventory, $pegName, $config_id);
        $upd->execute();

    } else {
        // CREATE MODE
        $ins = $db->prepare("
            INSERT INTO peg_configs
            (capacity, interface, condition_type, inventory_mode, peg_name)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->bind_param("sssss", $capacity, $interface, $condition, $inventory, $pegName);
        $ins->execute();
        $config_id = $db->insert_id;
    }

    /* ===============================
       4) PEG POINTS (UPSERT) + HISTORY
    ================================ */
    $updPoint = $db->prepare("
        UPDATE peg_points
        SET label=?, channel=?, url=?, price=?, weight=?
        WHERE id=? AND config_id=?
    ");

    $insPoint = $db->prepare("
        INSERT INTO peg_points
        (config_id, label, channel, url, price, weight)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $checkHist = $db->prepare("
        SELECT id FROM peg_point_history
        WHERE peg_point_id=? AND day_date=CURDATE() AND price=?
        LIMIT 1
    ");

    $insHist = $db->prepare("
        INSERT INTO peg_point_history
        (peg_point_id, day_date, price)
        VALUES (?, CURDATE(), ?)
    ");

    foreach ($points as $p) {
        $pointId = isset($p['id']) ? (int)$p['id'] : null;

        $label   = $p['label'] ?? '';
        $channel = $p['channel'] ?? '';
        $url     = $p['url'] ?? '';
        $price   = (float)($p['price'] ?? 0);
        $weight  = (float)($p['weight'] ?? 0);

        if ($pointId) {
            $updPoint->bind_param(
                "sssddii",
                $label, $channel, $url, $price, $weight,
                $pointId, $config_id
            );
            $updPoint->execute();
        } else {
            $insPoint->bind_param(
                "isssdd",
                $config_id, $label, $channel, $url, $price, $weight
            );
            $insPoint->execute();
            $pointId = $db->insert_id;
        }

        // 🔒 IMMUTABLE HISTORY (NO DUPLICATE SAME DAY + PRICE)
        $checkHist->bind_param("id", $pointId, $price);
        $checkHist->execute();
        $exists = $checkHist->get_result()->num_rows > 0;

        if (!$exists) {
            $insHist->bind_param("id", $pointId, $price);
            $insHist->execute();
        }
    }

    /* ===============================
       5) MODIFIERS (RESET PER SAVE)
    ================================ */

    $insMod = $db->prepare("
        INSERT INTO peg_modifiers (config_id, label, amount)
        VALUES (?, ?, ?)
    ");

    foreach ($modifiers as $m) {
        $label = $m['label'] ?? '';
        $amt   = (float)($m['amount'] ?? 0);
        $insMod->bind_param("isd", $config_id, $label, $amt);
        $insMod->execute();
    }
/* =====================================================
   CALCULATE BASE & ADJUSTED PRICE
===================================================== */
$basePrice = 0;
$totalWeight = 0;

foreach ($points as $p) {
    $price  = (float)($p['price'] ?? 0);
    $weight = (float)($p['weight'] ?? 1);

    if ($weight <= 0) $weight = 1;

    $basePrice += $price * $weight;
    $totalWeight += $weight;
}

$basePrice = $totalWeight > 0 ? $basePrice / $totalWeight : 0;

$modifierTotal = 0;
foreach ($modifiers as $m) {
    $modifierTotal += (float)($m['amount'] ?? 0);
}

$adjustedPrice = $basePrice + $modifierTotal;
    /* ===============================
       6) SALES DATA (RESET PER SAVE)
    ================================ */
    $delSales = $db->prepare("DELETE FROM sales_data WHERE config_id=?");
    $delSales->bind_param("i", $config_id);
    $delSales->execute();

    $insSales = $db->prepare("
        INSERT INTO sales_data
        (config_id, capacity, day_label, sale_price, market_price, volume)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($sales as $s) {
        $day    = $s['day_label'] ?? '';
        $sale   = (float)($s['sale_price'] ?? 0);
        $market = (float)($s['market_price'] ?? 0);
        $vol    = (int)($s['volume'] ?? 0);

        $insSales->bind_param(
            "issddi",
            $config_id, $capacity, $day, $sale, $market, $vol
        );
        $insSales->execute();
    }

    /* ===============================
       7) CONFIG HISTORY (AUDIT LOG)
    ================================ */
    $stmt = $db->prepare("
    INSERT INTO peg_history
    (config_id, capacity, interface, condition_type, peg_name,
     base_price, adjusted_price, inventory_mode, saved_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        peg_name        = VALUES(peg_name),
        base_price      = VALUES(base_price),
        adjusted_price  = VALUES(adjusted_price),
        inventory_mode  = VALUES(inventory_mode),
        saved_at        = NOW()
");

$stmt->bind_param(
    'issssdds',
    $config_id,
    $capacity,
    $interface,
    $condition,
    $pegName,
    $basePrice,
    $adjustedPrice,
    $inventory
);

$stmt->execute();



    $db->commit();

    echo json_encode([
        'status' => 'success',
        'config_id' => $config_id
    ]);

} catch (Throwable $e) {
    $db->rollback();
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
