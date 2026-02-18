<?php
// api/save_peg.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Log PHP errors to file (important for 500 debugging)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');

header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();

require __DIR__ . '/db.php';
require __DIR__ . "/role.php";
requireEditor($db);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

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

$basePegPrice      = isset($payload['basePegPrice']) ? (float)$payload['basePegPrice'] : 0;
$rawPrice          = isset($payload['rawPrice']) ? (float)$payload['rawPrice'] : 0;
$finalBasePegPrice = isset($payload['finalBasePegPrice']) ? (float)$payload['finalBasePegPrice'] : 0;
$adjustedPegBase   = (float)($payload['adjustedPegBase'] ?? 0);
$adjustedSalePrice = (float)($payload['adjustedSalePrice'] ?? 0);

$peg       = $payload['peg'] ?? [];
$points    = $peg['points'] ?? [];
$modifiers = $peg['modifiers'] ?? [];
$sales     = $peg['sales'] ?? [];

if (!$capacity || !$interface || !$condition) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Missing required fields: capacity/interface/condition']);
  exit;
}

$driveTypeId = (int)($payload['drive_type_id'] ?? 0);
if ($driveTypeId <= 0) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Missing drive_type_id']);
  exit;
}

/* ===============================
   3) TIME (EST)
================================ */
$estNow         = new DateTime('now', new DateTimeZone('America/New_York'));
$pegDateTimeEST = $estNow->format('Y-m-d H:i:s');
$pegDateEST     = $estNow->format('Y-m-d');

/* ===============================
   RESPONSE DEFAULTS
================================ */
$userSent     = false;
$userError    = null;
$newOosCount   = 0;
$debugOosItems = [];
$debugNotes    = [];

$db->begin_transaction();

try {
  /* ===============================
     4) CONFIG UPSERT (workspace scoped)
  =============================== */
  $find = $db->prepare("
    SELECT id
    FROM peg_configs
    WHERE workspace_id = ?
      AND capacity = ?
      AND drive_type_id = ?
      AND interface = ?
      AND condition_type = ?
    LIMIT 1
  ");
  $find->bind_param("isiss", $workspace_id, $capacity, $driveTypeId, $interface, $condition);
  $find->execute();
  $res = $find->get_result();

  if ($row = $res->fetch_assoc()) {
    $config_id = (int)$row['id'];

    $upd = $db->prepare("
      UPDATE peg_configs
      SET margin_percent = ?, inventory_mode = ?, peg_name = ?
      WHERE id = ?
        AND workspace_id = ?
    ");
    $upd->bind_param("dssii", $margin, $inventoryMode, $pegName, $config_id, $workspace_id);
    $upd->execute();
  } else {
    //  FIXED TYPES: margin is double, inventory_mode string, peg_name string
    $ins = $db->prepare("
      INSERT INTO peg_configs
        (workspace_id, capacity, drive_type_id, interface, condition_type, margin_percent, inventory_mode, peg_name)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->bind_param("isissdss", $workspace_id, $capacity, $driveTypeId, $interface, $condition, $margin, $inventoryMode, $pegName);
    $ins->execute();
    $config_id = (int)$db->insert_id;
  }

  /* ===============================
     5) BUILD incomingIds BEFORE deletes
  =============================== */
  $incomingIds = [];
  foreach ($points as $p) {
    if (!empty($p['id'])) $incomingIds[] = (int)$p['id'];
  }
  $incomingIds = array_values(array_unique(array_filter($incomingIds)));

  /* ===============================
     6) LOAD OLD OOS MAP (BEFORE deletes) - scoped
     oldMap: id => ['oos'=>int, 'notified_at'=>string|null]
  =============================== */
  $oldMap = [];
  if (count($incomingIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
    $types = str_repeat('i', count($incomingIds));

    $q = $db->prepare("
      SELECT id, oos, oos_notified_at
      FROM peg_points
      WHERE workspace_id = ?
        AND config_id = ?
        AND id IN ($placeholders)
    ");
    $q->bind_param("ii".$types, $workspace_id, $config_id, ...$incomingIds);
    $q->execute();
    $r = $q->get_result();

    while ($row = $r->fetch_assoc()) {
      $id = (int)$row['id'];
      $oldMap[$id] = [
        'oos' => (int)$row['oos'],
        'notified_at' => $row['oos_notified_at']
      ];
    }
  }

  /* ===============================
     7) DELETE REMOVED PEG POINTS - scoped
  =============================== */
  if (count($incomingIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
    $types = str_repeat('i', count($incomingIds));

    $sql = "
      DELETE FROM peg_points
      WHERE workspace_id = ?
        AND config_id = ?
        AND id NOT IN ($placeholders)
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ii".$types, $workspace_id, $config_id, ...$incomingIds);
    $stmt->execute();
  } else {
    $stmt = $db->prepare("DELETE FROM peg_points WHERE workspace_id = ? AND config_id = ?");
    $stmt->bind_param("ii", $workspace_id, $config_id);
    $stmt->execute();
  }

  /* ===============================
     8) PREPARE POINT UPSERT - scoped
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
      adjusted_peg_price=?,
      oos=?,
      oos_notified_at = IF(?=0, NULL, oos_notified_at)
    WHERE id=? AND config_id=? AND workspace_id=?
  ");

  $insPoint = $db->prepare("
    INSERT INTO peg_points
      (workspace_id, config_id, label, channel, url, price, qty, weight, notes, peg_modifier, adjusted_peg_price, oos, oos_notified_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
  ");

  /* ===============================
     9) SALES DATA (REPLACE) - scoped
  =============================== */
  if (is_array($sales)) {
    $delSales = $db->prepare("DELETE FROM sales_data WHERE workspace_id=? AND config_id=?");
    $delSales->bind_param("ii", $workspace_id, $config_id);
    $delSales->execute();

    $insSales = $db->prepare("
      INSERT INTO sales_data
        (workspace_id, config_id, capacity, day_label, sale_price, market_price, volume)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($sales as $s) {
      if (!isset($s['day_label'])) continue;

      $dayLabel    = (string)$s['day_label'];
      $salePrice   = (float)($s['sale_price'] ?? 0);
      $marketPrice = (float)($s['market_price'] ?? 0);
      $volume      = (int)($s['volume'] ?? 0);

      $insSales->bind_param("iissddi", $workspace_id, $config_id, $capacity, $dayLabel, $salePrice, $marketPrice, $volume);
      $insSales->execute();
    }
  }

  /* ===============================
     10) HISTORY PREP - scoped
     IMPORTANT: your UNIQUE keys should include workspace_id
  =============================== */
  $upsertHist = $db->prepare("
    INSERT INTO peg_point_history
      (workspace_id, peg_point_id, day_date, price, qty, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      price = VALUES(price),
      qty   = VALUES(qty),
      created_at = VALUES(created_at)
  ");

  $upsertAdjHist = $db->prepare("
    INSERT INTO adjusted_peg_price_history
      (workspace_id, peg_point_id, day_date, adjusted_peg_price, created_at)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      adjusted_peg_price = VALUES(adjusted_peg_price),
      created_at = VALUES(created_at)
  ");

  /* ===============================
     11) POINT UPSERT + COLLECT OOS
  =============================== */
  $newlyOOS = [];

  foreach ($points as &$p) {
    $pointId = (!empty($p['id'])) ? (int)$p['id'] : null;

    $label       = (string)($p['label'] ?? '');
    $channel     = (string)($p['channel'] ?? '');
    $url         = (string)($p['url'] ?? '');
    $price       = (float)($p['price'] ?? 0);
    $qty         = (int)($p['qty'] ?? 0);
    $weight      = (float)($p['weight'] ?? 0);
    $notes       = $p['notes'] ?? null;
    $pegModifier = (float)($p['peg_modifier'] ?? 0);
    $oos         = !empty($p['oos']) ? 1 : 0;

    $adjustedPegPrice = $price * (1 + ($pegModifier / 100));

    if ($pointId) {
      $updPoint->bind_param(
  "sssdidsddiiiii",
  $label,
  $channel,
  $url,
  $price,
  $qty,
  $weight,
  $notes,
  $pegModifier,
  $adjustedPegPrice,
  $oos,
  $oos,
  $pointId,
  $config_id,
  $workspace_id
);
      $updPoint->execute();
    } else {
      $insPoint->bind_param(
        "iisssdidsddi",
        $workspace_id,
        $config_id,
        $label,
        $channel,
        $url,
        $price,
        $qty,
        $weight,
        $notes,
        $pegModifier,
        $adjustedPegPrice,
        $oos
      );
      $insPoint->execute();
      $pointId = (int)$db->insert_id;
      $p['id'] = $pointId;
    }

    $oldOos = 0;
    $oldNotified = null;

    if (isset($oldMap[$pointId])) {
      $oldOos = (int)($oldMap[$pointId]['oos'] ?? 0);
      $oldNotified = $oldMap[$pointId]['notified_at'] ?? null;
    }

    // ✅ NEW OOS only: transition 0 -> 1
    if ($oldOos === 0 && $oos === 1) {
      $newlyOOS[] = ['id' => $pointId, 'label' => $label];
    }

    // ✅ If user UNCHECKS, remove unsent queued item for today (scoped)
    if ($oos === 0) {
      try {
        $delQ = $db->prepare("
          DELETE FROM oos_email_queue
          WHERE workspace_id = ?
            AND config_id = ?
            AND peg_point_id = ?
            AND queue_day = ?
            AND sent_at IS NULL
        ");
        $delQ->bind_param("iiis", $workspace_id, $config_id, $pointId, $pegDateEST);
        $delQ->execute();
      } catch (Throwable $ignored) {}
    }

    // History rows (scoped)
    $upsertHist->bind_param("iisdis", $workspace_id, $pointId, $pegDateEST, $price, $qty, $pegDateTimeEST);
    $upsertHist->execute();

    $upsertAdjHist->bind_param("iisds", $workspace_id, $pointId, $pegDateEST, $adjustedPegPrice, $pegDateTimeEST);
    $upsertAdjHist->execute();

    // ✅ Send-once detection (use OLD notified_at, not new)
    $oldNotified = null;
    if (isset($oldMap[$pointId])) {
      $oldNotified = $oldMap[$pointId]['notified_at'] ?? null;
    }

    if ($oos === 1 && empty($oldNotified)) {
      $newlyOOS[] = ['id' => $pointId, 'label' => $label];
    }
  }
  unset($p);

  // uniq by id
  $seen = [];
  $newlyOOS = array_values(array_filter($newlyOOS, function($x) use (&$seen) {
    $id = (int)($x['id'] ?? 0);
    if ($id <= 0) return false;
    if (isset($seen[$id])) return false;
    $seen[$id] = true;
    return true;
  }));

  /* ===============================
     12) MODIFIERS (REPLACE) - scoped
  =============================== */
  $delMods = $db->prepare("DELETE FROM peg_modifiers WHERE workspace_id=? AND config_id=?");
  $delMods->bind_param("ii", $workspace_id, $config_id);
  $delMods->execute();

  $insMod = $db->prepare("
    INSERT INTO peg_modifiers
      (workspace_id, config_id, label, amount, modifier_type)
    VALUES (?, ?, ?, ?, ?)
  ");

  $modifierTotal = 0;
  $saleModifierTotal = 0;

  foreach ($modifiers as $m) {
    $mLabel = (string)($m['label'] ?? '');
    $amt    = (float)($m['amount'] ?? 0);
    $type   = (string)($m['modifier_type'] ?? 'buy');

    if ($type === 'sale') $saleModifierTotal += $amt;
    else $modifierTotal += $amt;

    $insMod->bind_param("iisds", $workspace_id, $config_id, $mLabel, $amt, $type);
    $insMod->execute();
  }

  /* ===============================
     13) PEG HISTORY SNAPSHOT - scoped
  =============================== */
  $adjustedPrice = $adjustedSalePrice;

  $marginTotal = $finalBasePegPrice * ($margin / 100);
  $pegCore = $finalBasePegPrice - $marginTotal;

  $lowBuy  = $pegCore;
  $highBuy = $pegCore * 1.05;

  $hist = $db->prepare("
    INSERT INTO peg_history
      (workspace_id, config_id, capacity, interface, condition_type, peg_name, raw_price,
       base_price, sale_modifier_total, adjusted_price,
       modifier_total, low_buy, high_buy,
       margin_percent, inventory_mode, saved_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      peg_name=VALUES(peg_name),
      raw_price=VALUES(raw_price),
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
    "iissssddddddddss",
    $workspace_id,
    $config_id,
    $capacity,
    $interface,
    $condition,
    $pegName,
    $rawPrice,
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

  /* ===============================
     13.1) PEG HISTORY LOG (APPEND-ONLY FOR DIGEST SUMMARY) - scoped
  =============================== */
  $lastLog = $db->prepare("
    SELECT raw_price, base_price, adjusted_price, low_buy, high_buy,
           sale_modifier_total, modifier_total, margin_percent, inventory_mode
    FROM peg_history_log
    WHERE workspace_id = ?
      AND config_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $lastLog->bind_param("ii", $workspace_id, $config_id);
  $lastLog->execute();
  $lastRow = $lastLog->get_result()->fetch_assoc();

  $norm = function($v) { return number_format((float)$v, 2, '.', ''); };

  $shouldInsertLog = true;
  if ($lastRow) {
    $shouldInsertLog = !(
      $norm($lastRow['raw_price'])          === $norm($rawPrice) &&
      $norm($lastRow['base_price'])         === $norm($finalBasePegPrice) &&
      $norm($lastRow['adjusted_price'])     === $norm($adjustedPrice) &&
      $norm($lastRow['low_buy'])            === $norm($lowBuy) &&
      $norm($lastRow['high_buy'])           === $norm($highBuy) &&
      $norm($lastRow['sale_modifier_total'])=== $norm($saleModifierTotal) &&
      $norm($lastRow['modifier_total'])     === $norm($modifierTotal) &&
      $norm($lastRow['margin_percent'])     === $norm($margin) &&
      (string)$lastRow['inventory_mode']    === (string)$inventoryMode
    );
  }

  if ($shouldInsertLog) {
    $logStmt = $db->prepare("
      INSERT INTO peg_history_log
        (workspace_id, config_id, raw_price, base_price, adjusted_price, low_buy, high_buy,
         sale_modifier_total, modifier_total, margin_percent, inventory_mode, saved_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // ✅ FIXED TYPES (needed 9 doubles)
    $logStmt->bind_param(
  "iiddddddddss",
  $workspace_id,
  $config_id,
  $rawPrice,
  $finalBasePegPrice,
  $adjustedPrice,
  $lowBuy,
  $highBuy,
  $saleModifierTotal,
  $modifierTotal,
  $margin,
  $inventoryMode,
  $pegDateTimeEST
);
    $logStmt->execute();
  }

  // commit first (DB safe)
  $db->commit();

  /* ===============================
     14) OOS QUEUE (AFTER COMMIT) - scoped
  =============================== */
  $newOosCount = count($newlyOOS);
  $debugOosItems = array_slice($newlyOOS, 0, 10);

  $queuedCount = 0;

  if ($newOosCount > 0) {
    $qIns = $db->prepare("
      INSERT INTO oos_email_queue (workspace_id, queue_day, config_id, peg_point_id, noted_at)
      VALUES (?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE noted_at = noted_at
    ");

    foreach ($newlyOOS as $x) {
      $pid = (int)($x['id'] ?? 0);
      if ($pid <= 0) continue;

      $qIns->bind_param("isii", $workspace_id, $pegDateEST, $config_id, $pid);
      $qIns->execute();
      if ($qIns->affected_rows === 1) $queuedCount++;
    }
  }

  echo json_encode([
    'status'    => 'success',
    'workspace_id' => $workspace_id,
    'config_id' => $config_id,
    'saved_at'  => $pegDateTimeEST,

    'new_oos_count'   => $newOosCount,
    'queued_oos_count'=> $queuedCount,

    'debug_oos_items' => $debugOosItems,
  ]);
  exit;

} catch (Throwable $e) {
  try { $db->rollback(); } catch (Throwable $ignored) {}
  http_response_code(500);
  echo json_encode([
    'status'  => 'error',
    'message' => $e->getMessage()
  ]);
  exit;
}
