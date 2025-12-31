<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Content-Type: application/json");
require "db.php";

/* ===============================
   INPUT
================================ */
$capacity = $_GET['capacity'] ?? null;

if (!$capacity) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing capacity"
    ]);
    exit;
}

/* ===============================
   LOAD PEG HISTORY
================================ */
$stmt = $db->prepare("
    SELECT
        h.id,
        h.config_id,
        h.capacity,
        h.interface,
        h.condition_type,
        h.peg_name,
        h.base_price,
        h.adjusted_price,
        h.margin_percent,
        h.saved_at
    FROM peg_history h
    WHERE h.capacity = ?
    ORDER BY h.saved_at DESC
");

$stmt->bind_param("s", $capacity);
$stmt->execute();
$res = $stmt->get_result();

$history = [];

while ($row = $res->fetch_assoc()) {
    $margin = isset($row["margin_percent"])
    ? (float)$row["margin_percent"]
    : 80;

$history[] = [
    "id"             => (int)$row["id"],
    "config_id"      => (int)$row["config_id"],
    "capacity"       => $row["capacity"],
    "interface"      => $row["interface"],
    "condition_type" => $row["condition_type"],
    "peg_name"       => $row["peg_name"],
    "base_price"     => (float)$row["base_price"],
    "adjusted_price" => (float)$row["adjusted_price"],

    // ✅ SEND BOTH KEYS
    "margin_percent" => $margin,
    "marginPercent"  => $margin,

    "saved_at"       => $row["saved_at"]
];
}

/* ===============================
   RESPONSE
================================ */
echo json_encode([
    "status"  => "success",
    "history" => $history
]);
