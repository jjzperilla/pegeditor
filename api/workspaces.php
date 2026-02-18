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

$isAdmin = ($user_id === 1);

// ensure session workspace is valid for this user
// NOTE: for admin, you may want getWorkspaceId to allow any workspace.
// For now we’ll keep it and fall back to session/default.
$active_ws = (int)($_SESSION['workspace_id'] ?? 0);
if ($active_ws <= 0) $active_ws = 1;

if (!$isAdmin) {
  $active_ws = getWorkspaceId($db, $user_id);
}

$rows = [];

if ($isAdmin) {
  // ✅ Admin: list ALL workspaces
  $stmt = $db->prepare("
    SELECT id, name
    FROM workspaces
    ORDER BY id ASC
  ");
  $stmt->execute();
  $res = $stmt->get_result();

  while ($r = $res->fetch_assoc()) {
    $wid = (int)$r['id'];
    $rows[] = [
      "id" => $wid,
      "name" => (string)$r["name"],
      "role" => "admin",
      "active" => ($wid === $active_ws)
    ];
  }
} else {
  // ✅ Normal user: list only member workspaces
  $stmt = $db->prepare("
    SELECT w.id, w.name, wu.role
    FROM workspace_users wu
    JOIN workspaces w ON w.id = wu.workspace_id
    WHERE wu.user_id = ?
    ORDER BY w.id ASC
  ");
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($r = $res->fetch_assoc()) {
    $wid = (int)$r['id'];
    $rows[] = [
      "id" => $wid,
      "name" => (string)$r["name"],
      "role" => (string)$r["role"],
      "active" => ($wid === $active_ws)
    ];
  }
}

// Optional: if admin’s active workspace doesn’t exist, fallback to first workspace
if ($isAdmin && $active_ws > 0) {
  $found = false;
  foreach ($rows as $w) {
    if ((int)$w["id"] === $active_ws) { $found = true; break; }
  }
  if (!$found && count($rows) > 0) {
    $active_ws = (int)$rows[0]["id"];
    $_SESSION['workspace_id'] = $active_ws;
    // also update "active" flags
    foreach ($rows as &$w) {
      $w["active"] = ((int)$w["id"] === $active_ws);
    }
    unset($w);
  }
}

echo json_encode([
  "status" => "ok",
  "active_workspace_id" => $active_ws,
  "workspaces" => $rows
]);
