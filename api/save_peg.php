<?php
require "auth.php";
requireAuth();

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

$pegName       = $payload['peg_name'] ?? null;
$margin        = isset($payload['marginPercent']) ? (float)$payload['marginPercent'] : 50;
$inventoryMode = $payload['inventoryMode'] ?? 'balanced';

/* 🔒 FINAL VALUES FROM UI */
$basePegPrice = isset($payload['basePegPrice'])? (float)$payload['basePegPrice']: 0;
$finalBasePegPrice = isset($payload['finalBasePegPrice'])? (float)$payload['finalBasePegPrice']: 0;
$adjustedPegBase   = (float)($payload['adjustedPegBase'] ?? 0);
$adjustedSalePrice = (float)($payload['adjustedSalePrice'] ?? 0);

$peg       = $payload['peg'] ?? [];
$points    = $peg['points'] ?? [];
$modifiers = $peg['modifiers'] ?? [];
$sales     = $peg['sales'] ?? [];

if (!$capacity || !$interface || !$condition) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

$driveTypeId = (int)($payload['drive_type_id'] ?? 0);

if ($driveTypeId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing drive_type_id'
    ]);
    exit;
}

/* ===============================
   3) TIME
================================ */
$estNow = new DateTime('now', new DateTimeZone('America/New_York'));

$pegDateTimeEST = $estNow->format('Y-m-d H:i:s');
$pegDateEST     = $estNow->format('Y-m-d'); 

$db->begin_transaction();

try {

    /* ===============================
       4) CONFIG UPSERT
    =============================== */
    $find = $db->prepare("
        SELECT id FROM peg_configs
        WHERE capacity = ?
        AND drive_type_id = ?
        AND interface = ?
        AND condition_type = ?
        LIMIT 1
    ");
    $find->bind_param(
    "siss",
    $capacity,
    $driveTypeId,
    $interface,
    $condition
);
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
            (capacity, drive_type_id, interface, condition_type, margin_percent, inventory_mode, peg_name)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param(
        "sissdds",
        $capacity,
        $driveTypeId,
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
   DELETE REMOVED PEG POINTS
================================ */
$incomingIds = [];

foreach ($points as $p) {
    if (!empty($p['id'])) {
        $incomingIds[] = (int)$p['id'];
    }
}

if (count($incomingIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
    $types = str_repeat('i', count($incomingIds));

    $sql = "
        DELETE FROM peg_points
        WHERE config_id = ?
          AND id NOT IN ($placeholders)
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        "i" . $types,
        $config_id,
        ...$incomingIds
    );
    $stmt->execute();
} else {
    // 🔥 If no points left, delete ALL
    $stmt = $db->prepare("
        DELETE FROM peg_points
        WHERE config_id = ?
    ");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();
}
    
 /* ===============================
       5) PREPARE PEG POINT STATEMENTS
    =============================== */
    $updPoint = $db->prepare("
        UPDATE peg_points
        SET
            label=?,
            channel=?,
            url=?,
            price=?,
            qty=?,
            weight=?,
            notes=?,
            peg_modifier=?,
            adjusted_peg_price=?
        WHERE id=? AND config_id=?
    ");

    $insPoint = $db->prepare("
        INSERT INTO peg_points
        (
            config_id,
            label,
            channel,
            url,
            price,
            qty,
            weight,
            notes,
            peg_modifier,
            adjusted_peg_price
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");   

    
    /* ===============================
   6.5) SALES DATA (SAFE)
================================ */
if (is_array($sales)) {

    $delSales = $db->prepare(
        "DELETE FROM sales_data WHERE config_id=?"
    );
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
        $salePrice    = (float)($s['sale_price'] ?? 0);
        $marketPrice  = (float)($s['market_price'] ?? 0);
        $volume       = (int)($s['volume'] ?? 0);

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
}

    /* ===============================
   6.8) PEG POINT UPSERT
================================ */
$upsertHist = $db->prepare("
    INSERT INTO peg_point_history
  (peg_point_id, day_date, price, qty, created_at)
  VALUES
  (?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
  price = VALUES(price),
  qty   = VALUES(qty),
  created_at = VALUES(created_at);
");

if (!$upsertHist) {
    throw new Exception("Prepare failed (peg_point_history): " . $db->error);
}

$upsertAdjHist = $db->prepare("
  INSERT INTO adjusted_peg_price_history
    (peg_point_id, day_date, adjusted_peg_price, created_at)
  VALUES
    (?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    adjusted_peg_price = VALUES(adjusted_peg_price),
    created_at = VALUES(created_at)
");
if (!$upsertAdjHist) {
  throw new Exception("Prepare failed (adjusted_peg_price_history): " . $db->error);
}    
    
    
foreach ($points as &$p) {

    $pointId = isset($p['id']) && $p['id']
        ? (int)$p['id']
        : null;

    $label   = (string)($p['label'] ?? '');
    $channel = (string)($p['channel'] ?? '');
    $url     = (string)($p['url'] ?? '');
    $price   = (float)($p['price'] ?? 0);
    $qty     = (int)($p['qty'] ?? 0);
    $weight  = (float)($p['weight'] ?? 0);
    $notes   = $p['notes'] ?? null;
    $pegModifier = (float)($p['peg_modifier'] ?? 0);

    $adjustedPegPrice = $price * (1 + ($pegModifier / 100));

    if ($pointId) {
        // UPDATE
        $updPoint->bind_param(
            "sssdidsddii",
            $label,
            $channel,
            $url,
            $price,
            $qty,
            $weight,
            $notes,
            $pegModifier,
            $adjustedPegPrice,
            $pointId,
            $config_id
        );
        $updPoint->execute();
    } else {
        // INSERT
        $insPoint->bind_param(
            "isssdidsdd",
            $config_id,
            $label,
            $channel,
            $url,
            $price,
            $qty,
            $weight,
            $notes,
            $pegModifier,
            $adjustedPegPrice
        );
        $insPoint->execute();

        // CRITICAL FIX
        $pointId = $db->insert_id;
        $p['id'] = $pointId; // update in-memory reference
    }

    //  ALWAYS WRITE HISTORY
    $upsertHist->bind_param(
        "isdis",
        $pointId,
        $pegDateEST,
        $price,
        $qty,
        $pegDateTimeEST
    );
    
    $upsertHist->execute();
    
$upsertAdjHist->bind_param(
  "isds",
  $pointId,
  $pegDateEST,
  $adjustedPegPrice,
  $pegDateTimeEST
);
$upsertAdjHist->execute();
}
unset($p); // break reference
    /* ===============================
       7) MODIFIERS
    =============================== */
    $delMods = $db->prepare("DELETE FROM peg_modifiers WHERE config_id=?");
if (!$delMods) {
    throw new Exception("Prepare failed (delete modifiers): " . $db->error);
}
$delMods->bind_param("i", $config_id);
$delMods->execute();

    $insMod = $db->prepare("
        INSERT INTO peg_modifiers
        (config_id, label, amount, modifier_type)
        VALUES (?, ?, ?, ?)
    ");

    $modifierTotal = 0;
    $saleModifierTotal = 0;

    foreach ($modifiers as $m) {
        $label = $m['label'] ?? '';
        $amt   = (float)($m['amount'] ?? 0);
        $type  = $m['modifier_type'] ?? 'buy';

        $type === 'sale'
            ? $saleModifierTotal += $amt
            : $modifierTotal += $amt;

        $insMod->bind_param("isds", $config_id, $label, $amt, $type);
        $insMod->execute();
    }

    /* ===============================
       8) PEG HISTORY SNAPSHOT (AUTHORITATIVE)
    =============================== */
    $adjustedPrice = $adjustedSalePrice;

    $marginTotal = $finalBasePegPrice * ($margin / 100);
    $pegCore = $finalBasePegPrice - $marginTotal;

    $lowBuy  = $pegCore;
    $highBuy = $pegCore * 1.05;

    error_log("base price". $basePrice);
    $hist = $db->prepare("
        INSERT INTO peg_history
        (
            config_id, capacity, interface, condition_type, peg_name,
            base_price, sale_modifier_total, adjusted_price,
            modifier_total, low_buy, high_buy,
            margin_percent, inventory_mode, saved_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            peg_name=VALUES(peg_name),
            base_price=VALUES(base_price),
            sale_modifier_total=VALUES(sale_modifier_total),
            adjusted_price=VALUES(adjusted_price),
            modifier_total=VALUES(modifier_total),
            low_buy=VALUES(low_buy),
            high_buy=VALUES(high_buy),
            margin_percent=VALUES(margin_percent),
            inventory_mode=VALUES(inventory_mode),
            saved_at=VALUES(saved_at)
    ");

    $hist->bind_param(
        "issssdddddddss",
        $config_id,
        $capacity,
        $interface,
        $condition,
        $pegName,
        $finalBasePegPrice,
        $saleModifierTotal,
        $adjustedPrice,
        $modifierTotal,
        $lowBuy,
        $highBuy,
        $margin,
        $inventoryMode,
        $pegDateTimeEST
    );

    $hist->execute();

    $db->commit();

    echo json_encode([
        'status'    => 'success',
        'config_id' => $config_id,
        'saved_at'  => $pegDateTimeEST
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
