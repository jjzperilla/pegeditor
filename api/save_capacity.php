<?php
require "auth.php";
requireAuth();
header("Content-Type: application/json");
require "db.php";

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$capacity = trim((string)($data["capacity"] ?? ""));

if ($capacity === "") {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "Capacity is required"]);
  exit;
}

// Check if exists (scoped)
$check = $db->prepare("
  SELECT id
  FROM capacities
  WHERE workspace_id = ?
    AND capacity = ?
  LIMIT 1
");
$check->bind_param("is", $workspace_id, $capacity);
$check->execute();
$checkResult = $check->get_result();

if ($checkResult->num_rows > 0) {
  echo json_encode(["status" => "exists", "message" => "Capacity already exists", "workspace_id" => $workspace_id]);
  exit;
}

// Insert new capacity (scoped)
$stmt = $db->prepare("
  INSERT INTO capacities (workspace_id, capacity)
  VALUES (?, ?)
");
$stmt->bind_param("is", $workspace_id, $capacity);

try {
  $stmt->execute();
  echo json_encode([
    "status" => "success",
    "workspace_id" => $workspace_id,
    "id" => (int)$stmt->insert_id,
    "capacity" => $capacity
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
