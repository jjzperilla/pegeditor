<?php
require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  echo json_encode(['status' => 'error']);
  exit;
}

$stmt = $db->prepare("
  SELECT id, capacity, interface, condition_type, drive_type_id
  FROM peg_configs
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
  echo json_encode(['status' => 'error']);
  exit;
}

echo json_encode([
  'status' => 'ok',
  'capacity' => $row['capacity'],
  'interface' => $row['interface'],
  'condition' => $row['condition_type'],
  'drive_type_id' => (int)$row['drive_type_id']
]);
