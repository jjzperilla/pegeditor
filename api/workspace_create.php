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
$name = trim((string)($input["name"] ?? ""));

if ($name === "") {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Name required"]);
  exit;
}

$stmt = $db->prepare("INSERT INTO workspaces (name) VALUES (?)");
$stmt->bind_param("s", $name);
if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Failed to create workspace"]);
  exit;
}

$newId = (int)$db->insert_id;

// optional: ensure admin is a member
$role = "admin";
$ins = $db->prepare("INSERT IGNORE INTO workspace_users (user_id, workspace_id, role) VALUES (?, ?, ?)");
$ins->bind_param("iis", $user_id, $newId, $role);
$ins->execute();

echo json_encode(["status"=>"ok","workspace_id"=>$newId]);
