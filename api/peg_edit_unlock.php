<?php
require "auth.php";
requireAuth();
header("Content-Type: application/json");
require "db.php";

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

$input = json_decode(file_get_contents("php://input"), true);
$config_id  = isset($input["config_id"]) ? (int)$input["config_id"] : 0;
$session_id = isset($input["session_id"]) ? trim((string)$input["session_id"]) : "";

if ($config_id > 0 && $session_id !== "") {
  $stmt = $db->prepare("
    DELETE FROM peg_edit_locks
    WHERE workspace_id = ?
      AND config_id = ?
      AND session_id = ?
  ");
  $stmt->bind_param("iis", $workspace_id, $config_id, $session_id);
  $stmt->execute();
}

echo json_encode([
  "status" => "ok",
  "workspace_id" => $workspace_id
]);
