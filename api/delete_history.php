<?php
header("Content-Type: application/json");
require "db.php";

$payload = json_decode(file_get_contents("php://input"), true);
$id = $payload["id"] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Missing history id"
    ]);
    exit;
}

// Find config_id from history row
$check = $db->prepare("
    SELECT config_id
    FROM peg_history
    WHERE id = ?
    LIMIT 1
");
$check->bind_param("i", $id);
$check->execute();
$res = $check->get_result();

if ($res->num_rows === 0) {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "message" => "History not found"
    ]);
    exit;
}

$config_id = (int)$res->fetch_assoc()['config_id'];

$db->begin_transaction();

try {

    // Delete modifiers & sales (safe)
    $stmt = $db->prepare("DELETE FROM peg_modifiers WHERE config_id = ?");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();

    $stmt = $db->prepare("DELETE FROM sales_data WHERE config_id = ?");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();

    // Delete peg points
    // ⛔ Will FAIL if peg_point_history exists (by design)
    $stmt = $db->prepare("DELETE FROM peg_points WHERE config_id = ?");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();

    // Delete config history snapshot
    $stmt = $db->prepare("DELETE FROM peg_history WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    // Delete config
    $stmt = $db->prepare("DELETE FROM peg_configs WHERE id = ?");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();

    $db->commit();

    echo json_encode(["status" => "success"]);

} catch (Throwable $e) {
    $db->rollback();
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
