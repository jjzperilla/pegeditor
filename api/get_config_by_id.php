<?php
require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

header('Content-Type: application/json');

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Invalid id']);
  exit;
}

$stmt = $db->prepare("
  SELECT id, capacity, interface, condition_type, drive_type_id
  FROM peg_configs
  WHERE id = ?
    AND workspace_id = ?
  LIMIT 1
");
$stmt->bind_param("ii", $id, $workspace_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
  // Not found OR belongs to a different workspace
  http_response_code(404);
  echo json_encode(['status' => 'error', 'message' => 'Config not found']);
  exit;
}

echo json_encode([
  'status' => 'ok',
  'workspace_id' => $workspace_id,
  'capacity' => $row['capacity'],
  'interface' => $row['interface'],
  'condition' => $row['condition_type'],
  'drive_type_id' => (int)$row['drive_type_id']
]);
