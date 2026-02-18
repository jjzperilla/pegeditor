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
$subscription_id = (int)($input["subscription_id"] ?? 0);

if ($subscription_id <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"subscription_id required"]);
  exit;
}

$stmt = $db->prepare("
  UPDATE email_notification_subscriptions
  SET is_active=0, updated_at=NOW()
  WHERE id=?
  LIMIT 1
");
$stmt->bind_param("i", $subscription_id);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Remove failed"]);
  exit;
}

echo json_encode(["status"=>"ok"]);
