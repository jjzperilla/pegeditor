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
$target_user_id = (int)($input["user_id"] ?? 0);

if ($workspace_id <= 0 || $target_user_id <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"workspace_id and user_id required"]);
  exit;
}

$del = $db->prepare("DELETE FROM workspace_users WHERE workspace_id=? AND user_id=? LIMIT 1");
$del->bind_param("ii", $workspace_id, $target_user_id);

if (!$del->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Remove failed"]);
  exit;
}

echo json_encode(["status"=>"ok"]);
