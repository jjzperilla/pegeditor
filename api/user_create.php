<?php
header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

if ($_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode([
    "status" => "error",
    "message" => "Admin only"
  ]);
  exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];

$user = trim((string)($input["user"] ?? ""));
$password = trim((string)($input["password"] ?? ""));

if ($user === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"user and password required"]);
  exit;
}

// prevent duplicate user
$chk = $db->prepare("SELECT id FROM users WHERE user_name=? LIMIT 1");
$chk->bind_param("s", $user);
$chk->execute();

if ($chk->get_result()->fetch_assoc()) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"user already exists"]);
  exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $db->prepare("
  INSERT INTO users (user_name, password_hash, is_active, created_at)
  VALUES (?, ?, 1, NOW())
");
$stmt->bind_param("ss", $user, $hash);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Failed to create user"]);
  exit;
}

echo json_encode(["status"=>"ok"]);
