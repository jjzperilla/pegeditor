<?php
require "auth.php";
requireAuth();

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
   LOAD PEG HISTORY (SNAPSHOT)
================================ */
$stmt = $db->prepare("
    SELECT
        h.id,
        h.config_id,
        h.capacity,

        dt.label AS drive_type,
        h.interface,
        h.condition_type,
        h.peg_name,

        h.base_price,
        h.adjusted_price,
        h.sale_modifier_total,
        h.modifier_total,
        h.low_buy,
        h.high_buy,

        h.margin_percent,
        h.saved_at
    FROM peg_history h
    JOIN peg_configs pc ON pc.id = h.config_id
    JOIN drive_types dt ON dt.id = pc.drive_type_id
    WHERE h.capacity = ?
    ORDER BY h.saved_at DESC
");

$stmt->bind_param("s", $capacity);
$stmt->execute();
$res = $stmt->get_result();

$history = [];

while ($row = $res->fetch_assoc()) {
    $history[] = [
        "id"                  => (int)$row["id"],
        "config_id"           => (int)$row["config_id"],
        "capacity"            => $row["capacity"],

        "drive_type"          => $row["drive_type"] ?? "HDD",

        "interface"           => $row["interface"],
        "condition_type"      => $row["condition_type"],
        "peg_name"            => $row["peg_name"],

        "base_price"          => (float)$row["base_price"],
        "adjusted_price"      => (float)$row["adjusted_price"],
        "sale_modifier_total" => (float)$row["sale_modifier_total"],
        "modifier_total"      => (float)$row["modifier_total"],
        "low_buy"             => (float)$row["low_buy"],
        "high_buy"            => (float)$row["high_buy"],

        "margin_percent"      => (float)$row["margin_percent"],
        "saved_at"            => $row["saved_at"]
    ];
}

/* ===============================
   RESPONSE
================================ */
echo json_encode([
    "status"  => "success",
    "history" => $history
]);
