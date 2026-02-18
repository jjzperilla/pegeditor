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
$email = trim((string)($input["email"] ?? ""));
$name = trim((string)($input["name"] ?? ""));

if (!in_array($type, ["OOS","PRICE"], true)) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"notif_type required"]);
  exit;
}
if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Valid email required"]);
  exit;
}

try {
  $db->begin_transaction();

  // Upsert contact
  $stmt = $db->prepare("
    INSERT INTO email_contacts (email, name, is_active, created_at, updated_at)
    VALUES (?, ?, 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      name = VALUES(name),
      is_active = 1,
      updated_at = NOW()
  ");
  $stmt->bind_param("ss", $email, $name);
  $stmt->execute();

  // Get contact id
  $cid = (int)$db->insert_id;
  if ($cid <= 0) {
    $q = $db->prepare("SELECT id FROM email_contacts WHERE email=? LIMIT 1");
    $q->bind_param("s", $email);
    $q->execute();
    $cid = (int)($q->get_result()->fetch_assoc()["id"] ?? 0);
  }

  if ($cid <= 0) throw new Exception("Failed to resolve contact");

  // Add subscription (reactivate if existed)
  $sub = $db->prepare("
    INSERT INTO email_notification_subscriptions (contact_id, notif_type, is_active, created_at, updated_at)
    VALUES (?, ?, 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      is_active = 1,
      updated_at = NOW()
  ");
  $sub->bind_param("is", $cid, $type);
  $sub->execute();

  $db->commit();
  echo json_encode(["status"=>"ok"]);
} catch (Throwable $e) {
  $db->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
