<?php
// api/set_workspace.php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
  echo json_encode(["status" => "unauthorized"]);
  exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$workspace_id = (int)($input["workspace_id"] ?? 0);

if ($workspace_id <= 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "Missing workspace_id"]);
  exit;
}

// verify membership + get role + workspace name
$chk = $db->prepare("
  SELECT wu.role, w.name
  FROM workspace_users wu
  JOIN workspaces w ON w.id = wu.workspace_id
  WHERE wu.user_id = ?
    AND wu.workspace_id = ?
  LIMIT 1
");
$chk->bind_param("ii", $user_id, $workspace_id);
$chk->execute();

$row = $chk->get_result()->fetch_assoc();
if (!$row) {
  http_response_code(403);
  echo json_encode(["status" => "error", "message" => "No access to this workspace"]);
  exit;
}

$_SESSION['workspace_id'] = $workspace_id;

echo json_encode([
  "status" => "ok",
  "workspace_id" => $workspace_id,
  "workspace_name" => (string)$row["name"],
  "role" => (string)$row["role"]
]);
