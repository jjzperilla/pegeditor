<?php
header("Content-Type: application/json");
session_start();

require "db.php";

/* =========================
   Read JSON input safely
========================= */
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

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
   Fetch stored hash
========================= */
$res = $db->query("SELECT password_hash FROM app_auth LIMIT 1");

if (!$res || $res->num_rows === 0) {
  http_response_code(500);
  echo json_encode([
    "status" => "error",
    "message" => "Auth not configured"
  ]);
  exit;
}

$row = $res->fetch_assoc();

/* =========================
   Verify password
========================= */
if (!password_verify($password, $row['password_hash'])) {
  http_response_code(401);
  echo json_encode([
    "status" => "error",
    "message" => "Invalid password"
  ]);
  exit;
}

/* =========================
   Success
========================= */
session_regenerate_id(true);
$_SESSION['auth'] = true;

echo json_encode([
  "status" => "success"
]);
