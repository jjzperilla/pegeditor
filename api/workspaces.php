<?php
// api/workspaces.php
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

// ensure session workspace is valid for this user
$active_ws = getWorkspaceId($db, $user_id);

$stmt = $db->prepare("
  SELECT
    w.id,
    w.name,
    wu.role
  FROM workspace_users wu
  JOIN workspaces w ON w.id = wu.workspace_id
  WHERE wu.user_id = ?
  ORDER BY w.id ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();

$res = $stmt->get_result();
$rows = [];

while ($r = $res->fetch_assoc()) {
  $wid = (int)$r['id'];
  $rows[] = [
    "id" => $wid,
    "name" => (string)$r["name"],
    "role" => (string)$r["role"],
    "active" => ($wid === $active_ws)
  ];
}

echo json_encode([
  "status" => "ok",
  "active_workspace_id" => $active_ws,
  "workspaces" => $rows
]);
