<?php
header("Content-Type: application/json");
require "db.php";

$data = json_decode(file_get_contents("php://input"), true);
$capacity = trim($data["capacity"] ?? "");

if ($capacity === "") {
    echo json_encode(["status" => "error", "message" => "Capacity is required"]);
    exit;
}

// Check if exists
$check = $db->prepare("SELECT id FROM capacities WHERE capacity = ?");
$check->bind_param("s", $capacity);
$check->execute();
$checkResult = $check->get_result();

if ($checkResult->num_rows > 0) {
    echo json_encode(["status" => "exists", "message" => "Capacity already exists"]);
    exit;
}

// Insert new capacity
$stmt = $db->prepare("INSERT INTO capacities (capacity) VALUES (?)");
$stmt->bind_param("s", $capacity);

if ($stmt->execute()) {
    echo json_encode(["status" => "success"]);
} else {
    echo json_encode(["status" => "error", "message" => $stmt->error]);
}
?>