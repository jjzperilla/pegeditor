<?php
header("Content-Type: application/json");
require "db.php";

$capacity = $_GET['capacity'] ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50; // max rows to return
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

if ($capacity === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Capacity is required"]);
    exit;
}

// Select history rows for this capacity (most recent first)
$stmt = $db->prepare("
    SELECT id, config_id, capacity, interface, condition_type, base_price, adjusted_price, inventory_mode, saved_at, notes
    FROM peg_history
    WHERE capacity = ?
    ORDER BY saved_at DESC, id DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("sii", $capacity, $limit, $offset);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = [
        "id" => (int)$r["id"],
        "config_id" => isset($r["config_id"]) ? (int)$r["config_id"] : null,
        "capacity" => $r["capacity"],
        "interface" => $r["interface"],
        "condition_type" => $r["condition_type"],
        "base_price" => (float)$r["base_price"],
        "adjusted_price" => (float)$r["adjusted_price"],
        "inventory_mode" => $r["inventory_mode"],
        "saved_at" => $r["saved_at"],
        "notes" => $r["notes"]
    ];
}

echo json_encode([
    "status" => "success",
    "history" => $rows
]);
?>
