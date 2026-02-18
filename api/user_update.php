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
$user_name = trim((string)($input["user_name"] ?? ""));
$new_password = trim((string)($input["password"] ?? ""));

if ($target_user_id <= 0 || $user_name === "") {
  http_response_code(400);
  echo json_encode([
    "status" => "error",
    "message" => "Invalid data"
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

  // update username
  $stmt = $db->prepare("
    UPDATE users
    SET user_name = ?
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("si", $user_name, $target_user_id);

  if (!$stmt->execute()) {
    throw new Exception("Update failed");
  }

  // update password only if provided
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
