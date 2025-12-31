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

$pegName = $payload['peg_name'] ?? null;
$margin  = isset($payload['marginPercent']) ? (float)$payload['marginPercent'] : 25;
$inventoryMode = $payload['inventoryMode'] ?? 'balanced';

/* 🔥 DATE AWARENESS */
$pegDate = $payload['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pegDate)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid date format']);
    exit;
}
if ($pegDate > date('Y-m-d')) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Future dates are not allowed']);
    exit;
}
$pegDateTime = $pegDate . ' 12:00:00';

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
       3) CONFIG (UPSERT)
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
        $config_id = (int)$row['id'];

        $upd = $db->prepare("
            UPDATE peg_configs
            SET margin_percent=?, inventory_mode=?, peg_name=?
            WHERE id=?
        ");
        $upd->bind_param("dssi", $margin, $inventoryMode, $pegName, $config_id);
        $upd->execute();
    } else {
        $ins = $db->prepare("
            INSERT INTO peg_configs
            (capacity, interface, condition_type, margin_percent, inventory_mode, peg_name)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param(
            "sssdss",
            $capacity,
            $interface,
            $condition,
            $margin,
            $inventoryMode,
            $pegName
        );
        $ins->execute();
        $config_id = $db->insert_id;
    }

    /* ===============================
       4) PEG POINTS
    ================================ */
    $updPoint = $db->prepare("
        UPDATE peg_points
        SET label=?, channel=?, url=?, price=?, qty=?, weight=?
        WHERE id=? AND config_id=?
    ");

    $insPoint = $db->prepare("
        INSERT INTO peg_points
        (config_id, label, channel, url, price, qty, weight)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    /* ===============================
       4.1) PEG POINT HISTORY (DATE AWARE)
    ================================ */
    $upsertHist = $db->prepare("
        INSERT INTO peg_point_history
          (peg_point_id, day_date, price, qty)
        VALUES
          (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          price = VALUES(price),
          qty   = VALUES(qty)
    ");

    foreach ($points as $p) {
        $pointId = isset($p['id']) ? (int)$p['id'] : null;

        $label   = $p['label'] ?? '';
        $channel = $p['channel'] ?? '';
        $url     = $p['url'] ?? '';
        $price   = (float)($p['price'] ?? 0);
        $qty     = (int)($p['qty'] ?? 0);
        $weight  = (float)($p['weight'] ?? 0);

        if ($pointId) {
            $updPoint->bind_param(
                "sssdidii",
                $label,
                $channel,
                $url,
                $price,
                $qty,
                $weight,
                $pointId,
                $config_id
            );
            $updPoint->execute();
        } else {
            $insPoint->bind_param(
                "isssdid",
                $config_id,
                $label,
                $channel,
                $url,
                $price,
                $qty,
                $weight
            );
            $insPoint->execute();
            $pointId = $db->insert_id;
        }

        // 🔥 DATE-AWARE HISTORY SAVE
        $upsertHist->bind_param("isdi", $pointId, $pegDate, $price, $qty);
        $upsertHist->execute();
    }

    /* ===============================
       5) MODIFIERS
    ================================ */
    $delMods = $db->prepare("DELETE FROM peg_modifiers WHERE config_id=?");
    $delMods->bind_param("i", $config_id);
    $delMods->execute();

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

    /* ===============================
       6) CALCULATE PEG
    ================================ */
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
       6.5) SALES DATA (UNCHANGED)
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
        if (!isset($s['day_label'])) continue;

        $dayLabel     = $s['day_label'];
        $salePrice   = (float)($s['sale_price'] ?? 0);
        $marketPrice = (float)($s['market_price'] ?? 0);
        $volume      = (int)($s['volume'] ?? 0);

        $insSales->bind_param(
            "issddi",
            $config_id,
            $capacity,
            $dayLabel,
            $salePrice,
            $marketPrice,
            $volume
        );
        $insSales->execute();
    }

    /* ===============================
       7) PEG HISTORY (DATE AWARE)
    ================================ */
    $hist = $db->prepare("
        INSERT INTO peg_history
        (config_id, capacity, interface, condition_type, peg_name,
         base_price, adjusted_price, margin_percent, inventory_mode, saved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            peg_name=VALUES(peg_name),
            base_price=VALUES(base_price),
            adjusted_price=VALUES(adjusted_price),
            margin_percent=VALUES(margin_percent),
            inventory_mode=VALUES(inventory_mode),
            saved_at=VALUES(saved_at)
    ");

    $hist->bind_param(
        "issssdddss",
        $config_id,
        $capacity,
        $interface,
        $condition,
        $pegName,
        $basePrice,
        $adjustedPrice,
        $margin,
        $inventoryMode,
        $pegDateTime
    );
    $hist->execute();

    $db->commit();

    echo json_encode([
        'status'    => 'success',
        'config_id' => $config_id,
        'date'      => $pegDate
    ]);
    exit;

} catch (Throwable $e) {

    $db->rollback();

    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
    exit;
}
