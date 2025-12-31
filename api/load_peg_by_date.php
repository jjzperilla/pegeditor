<?php
header('Content-Type: application/json');
require 'db.php';

$configId = (int)($_GET['config_id'] ?? 0);
$date     = $_GET['date'] ?? null;

if (!$configId || !$date) {
  echo json_encode([
    'status' => 'error',
    'message' => 'Invalid params'
  ]);
  exit;
}

/* ==========================================
   1) LOAD HISTORY FOR EXACT DATE
========================================== */
$hist = $db->prepare("
  SELECT
    pp.id AS peg_point_id,
    pp.label,
    pp.channel,
    pp.url,
    h.price,
    h.qty
  FROM peg_point_history h
  JOIN peg_points pp ON pp.id = h.peg_point_id
  WHERE pp.config_id = ?
    AND h.day_date = ?
  ORDER BY pp.id ASC
");
$hist->bind_param("is", $configId, $date);
$hist->execute();
$res = $hist->get_result();

if ($res->num_rows > 0) {
  echo json_encode([
    'status'    => 'success',
    'points'    => $res->fetch_all(MYSQLI_ASSOC),
    'used_date' => $date,
    'source'    => 'history'
  ]);
  exit;
}

/* ==========================================
   2) NO HISTORY → RETURN STRUCTURE ONLY
   (NO PRICES)
========================================== */
$struct = $db->prepare("
  SELECT
    id AS peg_point_id,
    label,
    channel,
    url,
    qty
  FROM peg_points
  WHERE config_id = ?
  ORDER BY id ASC
");
$struct->bind_param("i", $configId);
$struct->execute();
$res = $struct->get_result();

if ($res->num_rows > 0) {
  echo json_encode([
    'status'    => 'success',
    'points'    => $res->fetch_all(MYSQLI_ASSOC),
    'used_date' => null,
    'source'    => 'structure'
  ]);
  exit;
}

/* ==========================================
   3) NOTHING EXISTS
========================================== */
echo json_encode([
  'status' => 'success',
  'points' => [],
  'source' => 'empty'
]);
