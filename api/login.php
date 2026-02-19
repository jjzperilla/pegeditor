<?php
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/db.php";

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
  http_response_code(400);
  echo json_encode([
    "status" => "error",
    "message" => "Invalid request body"
  ]);
  exit;
}

$username = trim((string)($input["username"] ?? ""));
$password = trim((string)($input["password"] ?? ""));

if ($username === "") {
  http_response_code(400);
  echo json_encode([
    "status" => "error",
    "message" => "Username required"
  ]);
  exit;
}

if ($password === "") {
  http_response_code(400);
  echo json_encode([
    "status" => "error",
    "message" => "Password required"
  ]);
  exit;
}

/* =========================
   Fetch user by username
========================= */
$stmt = $db->prepare("
  SELECT id, user_name, password_hash, role
  FROM users
  WHERE is_active = 1
    AND user_name = ?
  LIMIT 1
");
$stmt->bind_param("s", $username);
$stmt->execute();

$res = $stmt->get_result();
$user = $res->fetch_assoc();

if (!$user) {
  http_response_code(401);
  echo json_encode([
    "status" => "error",
    "message" => "Invalid username or password"
  ]);
  exit;
}

if (empty($user["password_hash"]) || !password_verify($password, $user["password_hash"])) {
  http_response_code(401);
  echo json_encode([
    "status" => "error",
    "message" => "Invalid username or password"
  ]);
  exit;
}

/* =========================
   Login success
========================= */
session_regenerate_id(true);

$_SESSION["auth"] = true; // optional legacy
$_SESSION["user_id"] = (int)$user["id"];
$_SESSION["role"] = (string)($user["role"] ?? "viewer");
$_SESSION["user_name"] = (string)($user["user_name"] ?? $username);

/* =========================
   A) Ensure auto access to main workspace (ID=1)
========================= */
$mainWsId = 1;
$userId = (int)$_SESSION["user_id"];

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
========================= */
$_SESSION["workspace_id"] = 1;
$_SESSION["last_activity"] = time();

echo json_encode([
  "status" => "success",
  "user_id" => $_SESSION["user_id"],
  "workspace_id" => $_SESSION["workspace_id"],
  "role" => $_SESSION["role"],
  "username" => $_SESSION["user_name"]
]);
