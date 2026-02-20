<?php
header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(["status" => "error", "message" => "Admin only"]);
  exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$target_user_id = (int)($input["user_id"] ?? 0);

if ($target_user_id <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invalid user"]);
  exit;
}

$me = (int)($_SESSION['user_id'] ?? 0);
if ($me > 0 && $target_user_id === $me) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"You cannot delete your own account"]);
  exit;
}

// Prevent deleting super admin (ID 1)
if ($target_user_id === 1) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Cannot delete super admin"]);
  exit;
}

$stmt = $db->prepare("
  UPDATE users
  SET is_active = 0
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("i", $target_user_id);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Delete failed"]);
  exit;
}

echo json_encode(["status"=>"ok"]);


