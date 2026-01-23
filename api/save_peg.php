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

// Mailer wrapper (your file)
require_once __DIR__ . '/oos_mailer.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
$emailSent     = false;
$emailError    = null;
$newOosCount   = 0;
$debugOosItems = [];
$debugNotes    = [];

$db->begin_transaction();

try {
  /* ===============================
     4) CONFIG UPSERT
  =============================== */
  $find = $db->prepare("
    SELECT id
    FROM peg_configs
    WHERE capacity = ?
      AND drive_type_id = ?
      AND interface = ?
      AND condition_type = ?
    LIMIT 1
  ");
  $find->bind_param("siss", $capacity, $driveTypeId, $interface, $condition);
  $find->execute();
  $res = $find->get_result();

  if ($row = $res->fetch_assoc()) {
    $config_id = (int)$row['id'];

    $upd = $db->prepare("
      UPDATE peg_configs
      SET margin_percent = ?, inventory_mode = ?, peg_name = ?
      WHERE id = ?
    ");
    $upd->bind_param("dssi", $margin, $inventoryMode, $pegName, $config_id);
    $upd->execute();
  } else {
    $ins = $db->prepare("
      INSERT INTO peg_configs
        (capacity, drive_type_id, interface, condition_type, margin_percent, inventory_mode, peg_name)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->bind_param("sissdds", $capacity, $driveTypeId, $interface, $condition, $margin, $inventoryMode, $pegName);
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
     6) LOAD OLD OOS MAP (BEFORE deletes)
     oldMap: id => ['oos'=>int, 'notified_at'=>string|null]
  =============================== */
  $oldMap = [];
  if (count($incomingIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
    $types = str_repeat('i', count($incomingIds));

    $q = $db->prepare("
      SELECT id, oos, oos_notified_at
      FROM peg_points
      WHERE id IN ($placeholders)
    ");
    $q->bind_param($types, ...$incomingIds);
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
     7) DELETE REMOVED PEG POINTS
  =============================== */
  if (count($incomingIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
    $types = str_repeat('i', count($incomingIds));

    $sql = "
      DELETE FROM peg_points
      WHERE config_id = ?
        AND id NOT IN ($placeholders)
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i".$types, $config_id, ...$incomingIds);
    $stmt->execute();
  } else {
    $stmt = $db->prepare("DELETE FROM peg_points WHERE config_id = ?");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();
  }

  /* ===============================
     8) PREPARE POINT UPSERT
     - includes oos
     - resets oos_notified_at when oos=0
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
    WHERE id=? AND config_id=?
  ");

  $insPoint = $db->prepare("
    INSERT INTO peg_points
      (config_id, label, channel, url, price, qty, weight, notes, peg_modifier, adjusted_peg_price, oos, oos_notified_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
  ");

  /* ===============================
     9) SALES DATA (REPLACE)
  =============================== */
  if (is_array($sales)) {
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

      $dayLabel    = (string)$s['day_label'];
      $salePrice   = (float)($s['sale_price'] ?? 0);
      $marketPrice = (float)($s['market_price'] ?? 0);
      $volume      = (int)($s['volume'] ?? 0);

      $insSales->bind_param("issddi", $config_id, $capacity, $dayLabel, $salePrice, $marketPrice, $volume);
      $insSales->execute();
    }
  }

  /* ===============================
     10) HISTORY PREP
  =============================== */
  $upsertHist = $db->prepare("
    INSERT INTO peg_point_history
      (peg_point_id, day_date, price, qty, created_at)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      price = VALUES(price),
      qty   = VALUES(qty),
      created_at = VALUES(created_at)
  ");

  $upsertAdjHist = $db->prepare("
    INSERT INTO adjusted_peg_price_history
      (peg_point_id, day_date, adjusted_peg_price, created_at)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      adjusted_peg_price = VALUES(adjusted_peg_price),
      created_at = VALUES(created_at)
  ");

  /* ===============================
     11) POINT UPSERT + COLLECT OOS (SEND ONCE RULE)
     ✅ Send if: oos=1 AND old notified_at is empty (NULL)
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
        "sssdidsddiiii",
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
        $config_id
      );
      $updPoint->execute();
    } else {
      $insPoint->bind_param(
        "isssdidsddi",
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

    // History rows
    $upsertHist->bind_param("isdis", $pointId, $pegDateEST, $price, $qty, $pegDateTimeEST);
    $upsertHist->execute();

    $upsertAdjHist->bind_param("isds", $pointId, $pegDateEST, $adjustedPegPrice, $pegDateTimeEST);
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
     12) MODIFIERS (REPLACE)
  =============================== */
  $delMods = $db->prepare("DELETE FROM peg_modifiers WHERE config_id=?");
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
    $mLabel = (string)($m['label'] ?? '');
    $amt    = (float)($m['amount'] ?? 0);
    $type   = (string)($m['modifier_type'] ?? 'buy');

    if ($type === 'sale') $saleModifierTotal += $amt;
    else $modifierTotal += $amt;

    $insMod->bind_param("isds", $config_id, $mLabel, $amt, $type);
    $insMod->execute();
  }

  /* ===============================
     13) PEG HISTORY SNAPSHOT
  =============================== */
  $adjustedPrice = $adjustedSalePrice;

  $marginTotal = $finalBasePegPrice * ($margin / 100);
  $pegCore = $finalBasePegPrice - $marginTotal;

  $lowBuy  = $pegCore;
  $highBuy = $pegCore * 1.05;

  $hist = $db->prepare("
    INSERT INTO peg_history
      (config_id, capacity, interface, condition_type, peg_name,
       base_price, sale_modifier_total, adjusted_price,
       modifier_total, low_buy, high_buy,
       margin_percent, inventory_mode, saved_at)
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

  // ✅ commit first (DB safe)
  $db->commit();

  /* ===============================
     14) OOS EMAIL (AFTER COMMIT)
  =============================== */
  $newOosCount = count($newlyOOS);
  $debugOosItems = array_slice($newlyOOS, 0, 10);

  if ($newOosCount > 0) {
    $oosToEmail = "jperilla@servertechsolutions.com";
    
      $oosCcEmails = [
    'jperilla@servertechsolutions.com',
    'paulb@servertechsolutions.com'
];  
      
    // Drive type label
    $driveTypeLabel = (string)$driveTypeId;
    try {
      $dtStmt = $db->prepare("SELECT label FROM drive_types WHERE id=? LIMIT 1");
      $dtStmt->bind_param("i", $driveTypeId);
      $dtStmt->execute();
      $dtRow = $dtStmt->get_result()->fetch_assoc();
      if (!empty($dtRow['label'])) $driveTypeLabel = $dtRow['label'];
    } catch (Throwable $ignored) {}

    $lines = [];
    foreach ($newlyOOS as $x) {
      $lines[] = "- " . (($x['label'] ?? '') !== '' ? $x['label'] : ("PEG Point #" . (int)$x['id']));
    }

    $subject = "OOS PEG Points: {$pegName} - {$capacity} ({$driveTypeLabel} / {$interface} / {$condition})";

  $eol = "\r\n";

$body = implode($eol, [
    "OOS items were marked on save.",
    "<br>",
    "Peg Name: {$pegName}",
    "<br>",
    "Capacity: {$capacity}",
    "<br>",
    "Drive Type: {$driveTypeLabel}",
    "<br>",
    "Interface: {$interface}",
    "<br>",
    "Condition: {$condition}",
    "<br>",
    "Peg Points:",
    implode($eol, $lines),
    "<br>",
    "Saved at (EST): {$pegDateTimeEST}"
]);

    try {
      $mailResult = sendOosSummaryEmail($oosToEmail,$subject,$body,$oosCcEmails);

      if (!empty($mailResult['success'])) {
        $emailSent = true;

        // mark notified_at only if email sent
        $ids = array_map(fn($x) => (int)$x['id'], $newlyOOS);
        $ids = array_values(array_unique(array_filter($ids)));

        if (count($ids) > 0) {
          $placeholders = implode(",", array_fill(0, count($ids), "?"));
          $types = str_repeat("i", count($ids));

          $sql = "
            UPDATE peg_points
            SET oos_notified_at = NOW()
            WHERE config_id=?
              AND id IN ($placeholders)
          ";

          $mark = $db->prepare($sql);
          $mark->bind_param("i".$types, $config_id, ...$ids);
          $mark->execute();
        }
      } else {
        $emailError = $mailResult['error'] ?? 'Unknown mailer error';
      }
    } catch (Throwable $e2) {
      $emailError = $e2->getMessage();
    }
  }

  echo json_encode([
    'status'    => 'success',
    'config_id' => $config_id,
    'saved_at'  => $pegDateTimeEST,

    'new_oos_count'       => $newOosCount,
    'oos_email_sent'      => $emailSent,
    'oos_email_error'     => $emailError,

    'debug_oos_items'     => $debugOosItems,
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
