<?php
require __DIR__ . "/auth.php";
requireAuth();
require __DIR__ . "/db.php";
require __DIR__ . "/role.php";

header("Content-Type: application/json");

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$user_id = (int)($_SESSION["user_id"] ?? 0);

$stmt = $db->prepare("
  SELECT u.user_name, wu.role
  FROM users u
  LEFT JOIN workspace_users wu
    ON wu.user_id = u.id
   AND wu.workspace_id = ?
  WHERE u.id = ?
  LIMIT 1
");

$workspace_id = (int)($_SESSION["workspace_id"] ?? 1);

$stmt->bind_param("ii", $workspace_id, $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

echo json_encode([
  "status"   => "ok",
  "username" => $row["user_name"] ?? "User",
  "role"     => strtolower($row["role"] ?? "viewer")
]);
