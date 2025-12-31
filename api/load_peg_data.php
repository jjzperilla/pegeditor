<?php
header("Content-Type: application/json");
require "db.php";

$capacity  = $_GET["capacity"] ?? null;
$interface = $_GET["interface"] ?? null;
$condition = $_GET["condition"] ?? null;

if (!$capacity || !$interface || !$condition) {
    echo json_encode(["status" => "error", "message" => "Missing parameters"]);
    exit;
}

/* ===============================
   LOAD CONFIG (WITH MARGIN %)
================================ */
$stmt = $db->prepare("
    SELECT id, peg_name, margin_percent
    FROM peg_configs
    WHERE capacity=? AND interface=? AND condition_type=?
    LIMIT 1
");
$stmt->bind_param("sss", $capacity, $interface, $condition);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode(["status" => "not_found"]);
    exit;
}

$config = $res->fetch_assoc();

$config_id = (int)$config["id"];
$peg_name  = $config["peg_name"] ?? null;
$margin = isset($config["margin_percent"])
    ? (float)$config["margin_percent"]
    : 80;

/* ===============================
   LOAD PEG POINTS
================================ */
$points = [];
$q = $db->query("
    SELECT
        id,
        label,
        channel,
        url,
        price,
        qty,
        weight,
        created_at
    FROM peg_points
    WHERE config_id = $config_id
    ORDER BY created_at ASC
");

while ($row = $q->fetch_assoc()) {
    $row["price"]      = (float)$row["price"];
    $row["qty"]        = (int)$row["qty"];
    $row["weight"]     = (float)$row["weight"];
    $row["created_at"] = $row["created_at"]; // keep full datetime
    $points[] = $row;
}

/* ===============================
   LOAD MODIFIERS
================================ */
$mods = [];
$q = $db->query("
    SELECT id, label, amount
    FROM peg_modifiers
    WHERE config_id = $config_id
    ORDER BY id ASC
");

while ($row = $q->fetch_assoc()) {
    $row["amount"] = (float)$row["amount"];
    $mods[] = $row;
}

/* ===============================
   LOAD SALES
================================ */
$sales = [];
$q = $db->query("
    SELECT id, capacity, day_label, sale_price, market_price, volume
    FROM sales_data
    WHERE config_id = $config_id
    ORDER BY id ASC
");

while ($row = $q->fetch_assoc()) {
    $row["sale_price"]   = (float)$row["sale_price"];
    $row["market_price"] = (float)$row["market_price"];
    $row["volume"]       = (int)$row["volume"];
    $sales[] = $row;
}

error_log(json_encode([
  "DEBUG_FILE" => "load_peg_data.php",
  "RAW_CONFIG_ARRAY" => $config
]));
/* ===============================
   RESPONSE
================================ */
echo json_encode([
    "status"         => "success",
    "config_id"      => $config_id,
    "peg_name"       => $peg_name,
    "margin_percent" => $margin,
    "marginPercent"  => $margin,

    "peg" => [
        "points"    => $points,
        "modifiers" => $mods,
        "sales"     => $sales
    ]
]);

