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
$contact_id = (int)($input["contact_id"] ?? 0);
$email = trim((string)($input["email"] ?? ""));
$name = trim((string)($input["name"] ?? ""));

if ($contact_id <= 0 || $email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"contact_id and valid email required"]);
  exit;
}

$stmt = $db->prepare("
  UPDATE email_contacts
  SET email=?, name=?, updated_at=NOW()
  WHERE id=? AND is_active=1
  LIMIT 1
");
$stmt->bind_param("ssi", $email, $name, $contact_id);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Update failed"]);
  exit;
}

echo json_encode(["status"=>"ok"]);
