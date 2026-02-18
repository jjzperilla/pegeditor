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

$ALLOWED_ROLES = ["admin","manager","editor","viewer"];

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$workspace_id = (int)($input["workspace_id"] ?? 0);
$target_user_id = (int)($input["user_id"] ?? 0);
$role = trim((string)($input["role"] ?? ""));

if ($workspace_id <= 0 || $target_user_id <= 0 || $role === "") {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"workspace_id, user_id, role required"]);
  exit;
}
if (!in_array($role, $ALLOWED_ROLES, true)) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invalid role"]);
  exit;
}

$upd = $db->prepare("UPDATE workspace_users SET role=? WHERE workspace_id=? AND user_id=? LIMIT 1");
$upd->bind_param("sii", $role, $workspace_id, $target_user_id);

if (!$upd->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Update failed"]);
  exit;
}

echo json_encode(["status"=>"ok"]);
