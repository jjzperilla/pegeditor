<?php
header('Content-Type: application/json');

require __DIR__ . '/auth.php';
requireAuth();
require __DIR__ . '/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
  echo json_encode(["status"=>"unauthorized"]);
  exit;
}

// Prefer role-based admin check (not user_id === 1)
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
if (!$isAdmin) {
  http_response_code(403);
  echo json_encode(["status"=>"error","message"=>"Admin only"]);
  exit;
}

$stmt = $db->prepare("
  SELECT id, user_name, email
  FROM users
  WHERE is_active = 1
  ORDER BY id ASC
");
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
  $rows[] = [
    "id" => (int)$r["id"],
    "user_name" => (string)($r["user_name"] ?? ""),
    "email" => (string)($r["email"] ?? ""),
    "label" => ($r["user_name"] ?: ("User #" . $r["id"]))
  ];
}

echo json_encode([
  "status" => "ok",
  "users" => $rows
]);
