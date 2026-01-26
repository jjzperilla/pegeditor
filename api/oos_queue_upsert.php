<?php
require_once __DIR__ . "/db.php";
header("Content-Type: application/json");

function est_day(): string {
  $tz = new DateTimeZone("America/New_York");
  return (new DateTime("now", $tz))->format("Y-m-d");
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$config_id = (int)($input["config_id"] ?? 0);
$peg_point_id = (int)($input["peg_point_id"] ?? 0);

if (!$config_id || !$peg_point_id) {
  echo json_encode(["status"=>"error","message"=>"Missing config_id/peg_point_id"]);
  exit;
}

$day = est_day();

$stmt = $db->prepare("
  INSERT INTO oos_email_queue (queue_day, config_id, peg_point_id)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE noted_at = CURRENT_TIMESTAMP
");
$stmt->bind_param("sii", $day, $config_id, $peg_point_id);
$stmt->execute();

echo json_encode(["status"=>"ok"]);
