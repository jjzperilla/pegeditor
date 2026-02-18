<?php
require "auth.php";
requireAuth();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Content-Type: application/json");
require "db.php";

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

/* ===============================
   INPUT
================================ */
$capacity = $_GET['capacity'] ?? null;

if (!$capacity) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Missing capacity"
    ]);
    exit;
}

$drive_type_id = (int)($_GET['drive_type_id'] ?? 1); // 1=HDD, 2=SSD
if (!in_array($drive_type_id, [1, 2], true)) $drive_type_id = 1;
/* ===============================
   LOAD PEG HISTORY (SNAPSHOT) - workspace scoped
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
    JOIN peg_configs pc
      ON pc.id = h.config_id
     AND pc.workspace_id = h.workspace_id
    JOIN drive_types dt ON dt.id = pc.drive_type_id
    WHERE h.workspace_id = ?
      AND pc.drive_type_id = ?
      AND h.capacity = ?
    ORDER BY h.saved_at DESC
");

$stmt->bind_param("iis", $workspace_id, $drive_type_id, $capacity);

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
    "status"        => "success",
    "workspace_id"  => $workspace_id,
    "drive_type_id" => $drive_type_id,
    "history"       => $history
]);

