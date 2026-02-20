<?php
header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode([
    "status" => "error",
    "message" => "Admin only"
  ]);
  exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];

$target_user_id = (int)($input["user_id"] ?? 0);
$user_name      = trim((string)($input["user_name"] ?? ""));
$email          = trim((string)($input["email"] ?? ""));      // NEW
$new_password   = trim((string)($input["password"] ?? ""));

if ($target_user_id <= 0 || $user_name === "") {
  http_response_code(400);
  echo json_encode([
    "status" => "error",
    "message" => "Invalid data"
  ]);
  exit;
}

// Optional email validation (only if provided)
if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode([
    "status" => "error",
    "message" => "Invalid email format"
  ]);
  exit;
}

if ($new_password !== "" && strlen($new_password) < 8) {
  http_response_code(400);
  echo json_encode([
    "status" => "error",
    "message" => "Password must be at least 8 characters"
  ]);
  exit;
}

try {
  $db->begin_transaction();

  // Check duplicate username (exclude current user)
  $chkUser = $db->prepare("SELECT id FROM users WHERE user_name = ? AND id <> ? LIMIT 1");
  $chkUser->bind_param("si", $user_name, $target_user_id);
  $chkUser->execute();
  if ($chkUser->get_result()->fetch_assoc()) {
    $db->rollback();
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "user already exists"]);
    exit;
  }

  // Check duplicate email if email provided (exclude current user)
  if ($email !== "") {
    $chkEmail = $db->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
    $chkEmail->bind_param("si", $email, $target_user_id);
    $chkEmail->execute();
    if ($chkEmail->get_result()->fetch_assoc()) {
      $db->rollback();
      http_response_code(400);
      echo json_encode(["status" => "error", "message" => "email already exists"]);
      exit;
    }
  }

  // Update username + email
  // If you want "blank clears email", keep as-is.
  // If you want "blank means don't change email", tell me and I’ll adjust.
  $stmt = $db->prepare("
    UPDATE users
    SET user_name = ?, email = ?
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("ssi", $user_name, $email, $target_user_id);

  if (!$stmt->execute()) {
    throw new Exception("Update failed");
  }

  // Update password only if provided
  if ($new_password !== "") {
    $hash = password_hash($new_password, PASSWORD_DEFAULT);

    $stmt2 = $db->prepare("
      UPDATE users
      SET password_hash = ?
      WHERE id = ?
      LIMIT 1
    ");
    $stmt2->bind_param("si", $hash, $target_user_id);

    if (!$stmt2->execute()) {
      throw new Exception("Password update failed");
    }
  }

  $db->commit();

  echo json_encode([
    "status" => "ok",
    "password_updated" => ($new_password !== "")
  ]);

} catch (Throwable $e) {
  $db->rollback();
  http_response_code(500);
  echo json_encode([
    "status" => "error",
    "message" => $e->getMessage()
  ]);
}
