<?php
header('Content-Type: application/json');
require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

if ($_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode([
    "status" => "error",
    "message" => "Admin only"
  ]);
  exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$workspace_id = (int)($input["workspace_id"] ?? 0);

if ($workspace_id <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"workspace_id required"]);
  exit;
}

// Prevent deleting currently active workspace (optional safety)
$active_ws = (int)($_SESSION['workspace_id'] ?? 0);
if ($active_ws === $workspace_id) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Cannot delete active workspace. Switch first."]);
  exit;
}

$db->begin_transaction();

try {
  $delWU = $db->prepare("DELETE FROM workspace_users WHERE workspace_id=?");
  $delWU->bind_param("i", $workspace_id);
  $delWU->execute();

  $delW = $db->prepare("DELETE FROM workspaces WHERE id=? LIMIT 1");
  $delW->bind_param("i", $workspace_id);
  $delW->execute();

  $db->commit();
  echo json_encode(["status"=>"ok"]);
} catch (Throwable $e) {
  $db->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Delete failed"]);
}
