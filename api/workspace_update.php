<?php
header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode([
    "status" => "error",
    "message" => "Admin only"
  ]);
  exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$workspace_id = (int)($input["workspace_id"] ?? 0);
$name = trim((string)($input["name"] ?? ""));

if ($workspace_id <= 0 || $name === "") {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "workspace_id and name required"]);
  exit;
}

$upd = $db->prepare("UPDATE workspaces SET name=? WHERE id=? LIMIT 1");
$upd->bind_param("si", $name, $workspace_id);

if (!$upd->execute()) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "Update failed"]);
  exit;
}

echo json_encode(["status" => "ok"]);
