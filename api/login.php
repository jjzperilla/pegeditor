<?php
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/db.php";

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input) || !isset($input['password'])) {
  http_response_code(400);
  echo json_encode([
    "status" => "error",
    "message" => "Password required"
  ]);
  exit;
}

$password = trim($input['password']);

/* =========================
   Fetch user (single-user or admin user)
   Adjust query if multi-user login
========================= */
$stmt = $db->prepare("
  SELECT id, password_hash
  FROM users
  WHERE is_active = 1
  ORDER BY id ASC
  LIMIT 1
");
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user || !password_verify($password, $user['password_hash'])) {
  http_response_code(401);
  echo json_encode([
    "status" => "error",
    "message" => "Invalid password"
  ]);
  exit;
}

/* =========================
   Login success
========================= */
session_regenerate_id(true);

$_SESSION['auth'] = true;              // optional legacy
$_SESSION['user_id'] = (int)$user['id'];

/* =========================
   Set initial workspace
========================= */
$ws = $db->prepare("
  SELECT workspace_id
  FROM workspace_users
  WHERE user_id = ?
  ORDER BY workspace_id ASC
  LIMIT 1
");
$ws->bind_param("i", $_SESSION['user_id']);
$ws->execute();
$row = $ws->get_result()->fetch_assoc();

$_SESSION['workspace_id'] = (int)($row['workspace_id'] ?? 1);

$_SESSION['last_activity'] = time();

echo json_encode([
  "status" => "success",
  "user_id" => $_SESSION['user_id'],
  "workspace_id" => $_SESSION['workspace_id']
]);
