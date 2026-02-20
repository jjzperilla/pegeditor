<?php
header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(["status" => "error", "message" => "Admin only"]);
  exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];

$user     = trim((string)($input["user"] ?? ""));
$password = trim((string)($input["password"] ?? ""));
$email    = trim((string)($input["email"] ?? ""));

if ($user === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"user and password required"]);
  exit;
}

// Optional email validation
if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invalid email format"]);
  exit;
}

// Check if username already exists (active or inactive)
$chk = $db->prepare("SELECT id, is_active FROM users WHERE user_name=? LIMIT 1");
$chk->bind_param("s", $user);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();

$hash = password_hash($password, PASSWORD_DEFAULT);

// If exists and ACTIVE -> block
if ($existing && (int)$existing["is_active"] === 1) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"user already exists"]);
  exit;
}

// If exists and INACTIVE -> reactivate instead of insert
if ($existing && (int)$existing["is_active"] === 0) {

  // Optional: block duplicate email if provided (exclude this same user id)
  if ($email !== "") {
    $chkEmail = $db->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
    $chkEmail->bind_param("si", $email, $existing["id"]);
    $chkEmail->execute();
    if ($chkEmail->get_result()->fetch_assoc()) {
      http_response_code(400);
      echo json_encode(["status"=>"error","message"=>"email already exists"]);
      exit;
    }
  }

  $reactivate = $db->prepare("
    UPDATE users
    SET password_hash = ?, email = ?, is_active = 1
    WHERE id = ?
    LIMIT 1
  ");
  $reactivate->bind_param("ssi", $hash, $email, $existing["id"]);

  if (!$reactivate->execute()) {
    http_response_code(500);
    echo json_encode(["status"=>"error","message"=>"Failed to reactivate user"]);
    exit;
  }

  echo json_encode(["status"=>"ok", "reactivated"=>true]);
  exit;
}

// Otherwise: create new user
// Optional: prevent duplicate email (if email provided)
if ($email !== "") {
  $chkEmail2 = $db->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
  $chkEmail2->bind_param("s", $email);
  $chkEmail2->execute();
  if ($chkEmail2->get_result()->fetch_assoc()) {
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>"email already exists"]);
    exit;
  }
}

$stmt = $db->prepare("
  INSERT INTO users (user_name, email, password_hash, is_active, created_at)
  VALUES (?, ?, ?, 1, NOW())
");
$stmt->bind_param("sss", $user, $email, $hash);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Failed to create user"]);
  exit;
}

echo json_encode(["status"=>"ok", "reactivated"=>false]);
