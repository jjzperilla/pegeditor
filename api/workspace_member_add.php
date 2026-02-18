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
$role = trim((string)($input["role"] ?? "viewer"));

if ($workspace_id <= 0 || $target_user_id <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"workspace_id and user_id required"]);
  exit;
}
if (!in_array($role, $ALLOWED_ROLES, true)) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invalid role"]);
  exit;
}

// optional: verify user exists
$chkU = $db->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
$chkU->bind_param("i", $target_user_id);
$chkU->execute();
if (!$chkU->get_result()->fetch_assoc()) {
  http_response_code(404);
  echo json_encode(["status"=>"error","message"=>"User not found"]);
  exit;
}

$ins = $db->prepare("INSERT INTO workspace_users (user_id, workspace_id, role) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE role=VALUES(role)");
$ins->bind_param("iis", $target_user_id, $workspace_id, $role);

if (!$ins->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Failed to add member"]);
  exit;
}

echo json_encode(["status"=>"ok"]);
