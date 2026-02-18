<?php
// api/role.php
require_once __DIR__ . "/db.php";

function getWorkspaceRole(mysqli $db): string {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  $workspace_id = (int)($_SESSION["workspace_id"] ?? 1);
  if ($workspace_id <= 0) $workspace_id = 1;

  $user_id = (int)($_SESSION["user_id"] ?? 0);
  if ($user_id <= 0) return "viewer"; // safest default

  $stmt = $db->prepare("
    SELECT role
    FROM workspace_users
    WHERE workspace_id = ?
      AND user_id = ?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $workspace_id, $user_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  $role = strtolower(trim($row["role"] ?? "viewer"));
  return in_array($role, ["editor", "viewer"], true) ? $role : "viewer";
}

function requireEditor(mysqli $db): void {
  $role = getWorkspaceRole($db);
  if ($role !== "editor") {
    http_response_code(403);
    echo json_encode(["status" => "forbidden", "message" => "Editor access required"]);
    exit;
  }
}
