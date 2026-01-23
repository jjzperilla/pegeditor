<?php
require "auth.php";
requireAuth();
header("Content-Type: application/json");
require "db.php";

$input = json_decode(file_get_contents("php://input"), true);
$config_id  = isset($input["config_id"]) ? (int)$input["config_id"] : 0;
$session_id = isset($input["session_id"]) ? trim((string)$input["session_id"]) : "";

if (!$config_id || $session_id === "") {
  echo json_encode(["status" => "error", "message" => "Missing config_id/session_id"]);
  exit;
}

$ttlSeconds = 45;

// clean up expired locks (optional but helpful)
$db->query("DELETE FROM peg_edit_locks WHERE last_seen < (NOW() - INTERVAL {$ttlSeconds} SECOND)");

// see if lock exists
$stmt = $db->prepare("SELECT session_id, last_seen FROM peg_edit_locks WHERE config_id=? LIMIT 1");
$stmt->bind_param("i", $config_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
  // acquire new lock
  $ins = $db->prepare("
    INSERT INTO peg_edit_locks (config_id, session_id, locked_at, last_seen)
    VALUES (?, ?, NOW(), NOW())
  ");
  $ins->bind_param("is", $config_id, $session_id);
  $ins->execute();

  echo json_encode(["status" => "ok", "mode" => "edit"]);
  exit;
}

$owner = $row["session_id"];

// if you own it, heartbeat it
if ($owner === $session_id) {
  $upd = $db->prepare("UPDATE peg_edit_locks SET last_seen = NOW() WHERE config_id=? AND session_id=?");
  $upd->bind_param("is", $config_id, $session_id);
  $upd->execute();

  echo json_encode(["status" => "ok", "mode" => "edit"]);
  exit;
}

// someone else owns it (not expired, since we deleted expired above)
echo json_encode([
  "status" => "ok",
  "mode" => "view",
  "owner" => "another_session"
]);
