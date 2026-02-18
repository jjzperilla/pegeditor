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
   Fetch user (password-based match)
========================= */
$stmt = $db->prepare("
  SELECT id, password_hash, role
  FROM users
  WHERE is_active = 1
  ORDER BY id ASC
  LIMIT 50
");
$stmt->execute();

$res = $stmt->get_result();

$matchedUser = null;
while ($row = $res->fetch_assoc()) {
  if (!empty($row['password_hash']) && password_verify($password, $row['password_hash'])) {
    $matchedUser = $row;
    break;
  }
}

if (!$matchedUser) {
  http_response_code(401);
  echo json_encode([
    "status" => "error",
    "message" => "Invalid password"
  ]);
  exit;
}

$user = $matchedUser;

/* =========================
   Login success
========================= */
session_regenerate_id(true);

$_SESSION['auth'] = true; // optional legacy
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['role'] = (string)$user['role'];

/* =========================
   A) Ensure auto access to main workspace (ID=1)
========================= */
$mainWsId = 1;
$userId = (int)$_SESSION['user_id'];

// Add membership if missing (default role: viewer)
$ins = $db->prepare("
  INSERT INTO workspace_users (workspace_id, user_id, role)
  SELECT ?, ?, 'viewer'
  FROM DUAL
  WHERE NOT EXISTS (
    SELECT 1 FROM workspace_users WHERE workspace_id = ? AND user_id = ?
  )
");
$ins->bind_param("iiii", $mainWsId, $userId, $mainWsId, $userId);
$ins->execute();

/* =========================
   B) Set initial workspace (default to 1)
   Since we auto-add above, workspace 1 is always valid.
========================= */
$_SESSION['workspace_id'] = 1;

$_SESSION['last_activity'] = time();

echo json_encode([
  "status" => "success",
  "user_id" => $_SESSION['user_id'],
  "workspace_id" => $_SESSION['workspace_id'],
  "role" => $_SESSION['role']
]);
