<?php
header('Content-Type: application/json');
require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(["status"=>"error","message"=>"Admin only"]);
  exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$type = strtoupper(trim((string)($input["notif_type"] ?? "")));

if (!in_array($type, ["OOS","PRICE"], true)) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"notif_type required"]);
  exit;
}

$stmt = $db->prepare("
  SELECT s.id AS subscription_id, c.id AS contact_id, c.email, c.name
  FROM email_notification_subscriptions s
  JOIN email_contacts c ON c.id = s.contact_id
  WHERE s.notif_type = ?
    AND s.is_active = 1
    AND c.is_active = 1
  ORDER BY c.email ASC
");
$stmt->bind_param("s", $type);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
  $rows[] = [
    "subscription_id" => (int)$r["subscription_id"],
    "contact_id" => (int)$r["contact_id"],
    "email" => (string)$r["email"],
    "name" => (string)($r["name"] ?? "")
  ];
}

echo json_encode(["status"=>"ok","recipients"=>$rows]);
