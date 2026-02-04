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

if ($config_id <= 0 || $session_id === "") {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "Missing config_id/session_id"]);
  exit;
}

$ttlSeconds = 45;

/*
  Optional: validate config belongs to this workspace
*/
$cfg = $db->prepare("SELECT 1 FROM peg_configs WHERE id = ? AND workspace_id = ? LIMIT 1");
$cfg->bind_param("ii", $config_id, $workspace_id);
$cfg->execute();
if ($cfg->get_result()->num_rows === 0) {
  http_response_code(404);
  echo json_encode(["status" => "error", "message" => "Config not found"]);
  exit;
}

// clean up expired locks (scoped)
$cleanup = $db->prepare("
  DELETE FROM peg_edit_locks
  WHERE workspace_id = ?
    AND last_seen < (NOW() - INTERVAL ? SECOND)
");
$cleanup->bind_param("ii", $workspace_id, $ttlSeconds);
$cleanup->execute();

// see if lock exists (scoped)
$stmt = $db->prepare("
  SELECT session_id, last_seen
  FROM peg_edit_locks
  WHERE workspace_id = ?
    AND config_id = ?
  LIMIT 1
");
$stmt->bind_param("ii", $workspace_id, $config_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
  // acquire new lock (scoped)
  $ins = $db->prepare("
    INSERT INTO peg_edit_locks (workspace_id, config_id, session_id, locked_at, last_seen)
    VALUES (?, ?, ?, NOW(), NOW())
  ");
  $ins->bind_param("iis", $workspace_id, $config_id, $session_id);
  $ins->execute();

  echo json_encode(["status" => "ok", "mode" => "edit", "workspace_id" => $workspace_id]);
  exit;
}

$owner = $row["session_id"];

// if you own it, heartbeat it (scoped)
if ($owner === $session_id) {
  $upd = $db->prepare("
    UPDATE peg_edit_locks
    SET last_seen = NOW()
    WHERE workspace_id = ?
      AND config_id = ?
      AND session_id = ?
  ");
  $upd->bind_param("iis", $workspace_id, $config_id, $session_id);
  $upd->execute();

  echo json_encode(["status" => "ok", "mode" => "edit", "workspace_id" => $workspace_id]);
  exit;
}

// someone else owns it
echo json_encode([
  "status" => "ok",
  "mode" => "view",
  "owner" => "another_session",
  "workspace_id" => $workspace_id
]);
