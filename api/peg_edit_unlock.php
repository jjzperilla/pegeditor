<?php
require "auth.php";
requireAuth();
header("Content-Type: application/json");
require "db.php";

$input = json_decode(file_get_contents("php://input"), true);
$config_id  = isset($input["config_id"]) ? (int)$input["config_id"] : 0;
$session_id = isset($input["session_id"]) ? trim((string)$input["session_id"]) : "";

if ($config_id && $session_id !== "") {
  $stmt = $db->prepare("DELETE FROM peg_edit_locks WHERE config_id=? AND session_id=?");
  $stmt->bind_param("is", $config_id, $session_id);
  $stmt->execute();
}

echo json_encode(["status" => "ok"]);
