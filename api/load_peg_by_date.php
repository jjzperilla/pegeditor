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

$configId = (int)($_GET['config_id'] ?? 0);
$date     = $_GET['date'] ?? null;

if ($configId <= 0 || !$date) {
  http_response_code(400);
  echo json_encode([
    'status' => 'error',
    'message' => 'Invalid params'
  ]);
  exit;
}

/* ==========================================
   1) LOAD HISTORY FOR EXACT DATE (scoped)
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
  JOIN peg_points pp
    ON pp.id = h.peg_point_id
   AND pp.workspace_id = h.workspace_id
  JOIN peg_configs pc
    ON pc.id = pp.config_id
   AND pc.workspace_id = pp.workspace_id
  WHERE pc.id = ?
    AND pc.workspace_id = ?
    AND h.workspace_id = ?
    AND h.day_date = ?
  ORDER BY pp.id ASC
");
$hist->bind_param("iiis", $configId, $workspace_id, $workspace_id, $date);
$hist->execute();
$res = $hist->get_result();

if ($res->num_rows > 0) {
  echo json_encode([
    'status'       => 'success',
    'workspace_id' => $workspace_id,
    'points'       => $res->fetch_all(MYSQLI_ASSOC),
    'used_date'    => $date,
    'source'       => 'history'
  ]);
  exit;
}

/* ==========================================
   2) NO HISTORY → RETURN STRUCTURE ONLY (scoped)
========================================== */
$struct = $db->prepare("
  SELECT
    pp.id AS peg_point_id,
    pp.label,
    pp.channel,
    pp.url,
    pp.qty
  FROM peg_points pp
  JOIN peg_configs pc
    ON pc.id = pp.config_id
   AND pc.workspace_id = pp.workspace_id
  WHERE pc.id = ?
    AND pc.workspace_id = ?
    AND pp.workspace_id = ?
  ORDER BY pp.id ASC
");
$struct->bind_param("iii", $configId, $workspace_id, $workspace_id);
$struct->execute();
$res = $struct->get_result();

if ($res->num_rows > 0) {
  echo json_encode([
    'status'       => 'success',
    'workspace_id' => $workspace_id,
    'points'       => $res->fetch_all(MYSQLI_ASSOC),
    'used_date'    => null,
    'source'       => 'structure'
  ]);
  exit;
}

/* ==========================================
   3) NOTHING EXISTS
========================================== */
echo json_encode([
  'status'       => 'success',
  'workspace_id' => $workspace_id,
  'points'       => [],
  'source'       => 'empty'
]);
