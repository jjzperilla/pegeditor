<?php
require "auth.php";
requireAuth();

require_once __DIR__ . "/db.php";
header("Content-Type: application/json");

function est_day(): string {
  $tz = new DateTimeZone("America/New_York");
  return (new DateTime("now", $tz))->format("Y-m-d");
}

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$config_id = (int)($input["config_id"] ?? 0);
$peg_point_id = (int)($input["peg_point_id"] ?? 0);

if ($config_id <= 0 || $peg_point_id <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Missing config_id/peg_point_id"]);
  exit;
}

$day = est_day();

/*
  Optional safety check:
  Ensure the peg_point belongs to the config AND same workspace.
  This prevents queue poisoning with mismatched IDs.
*/
$check = $db->prepare("
  SELECT 1
  FROM peg_points
  WHERE id = ?
    AND config_id = ?
    AND workspace_id = ?
  LIMIT 1
");
$check->bind_param("iii", $peg_point_id, $config_id, $workspace_id);
$check->execute();
$ok = $check->get_result()->num_rows > 0;

if (!$ok) {
  http_response_code(404);
  echo json_encode(["status"=>"error","message"=>"Peg point not found for this config/workspace"]);
  exit;
}

/*
  Insert queue row scoped by workspace
  IMPORTANT: Your oos_email_queue table should have workspace_id column
  and a UNIQUE KEY that includes (workspace_id, queue_day, config_id, peg_point_id)
*/
$stmt = $db->prepare("
  INSERT INTO oos_email_queue (workspace_id, queue_day, config_id, peg_point_id)
  VALUES (?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE noted_at = CURRENT_TIMESTAMP
");
$stmt->bind_param("isii", $workspace_id, $day, $config_id, $peg_point_id);
$stmt->execute();

echo json_encode(["status"=>"ok","workspace_id"=>$workspace_id]);
