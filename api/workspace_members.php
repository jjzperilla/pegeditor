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
$workspace_id = (int)($input["workspace_id"] ?? 0);
if ($workspace_id <= 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"workspace_id required"]); exit; }

$stmt = $db->prepare("
  SELECT wu.user_id, wu.role, u.user_name
  FROM workspace_users wu
  LEFT JOIN users u ON u.id = wu.user_id
  WHERE wu.workspace_id = ?
  ORDER BY wu.user_id ASC
");
$stmt->bind_param("i", $workspace_id);
$stmt->execute();
$res = $stmt->get_result();

$members = [];
while ($r = $res->fetch_assoc()) {
  $members[] = [
    "user_id" => (int)$r["user_id"],
    "role" => (string)$r["role"],
    "display_name" => (string)($r["user_name"] ?: ("User #".$r["user_id"]))
  ];
}

echo json_encode(["status"=>"ok","members"=>$members]);
