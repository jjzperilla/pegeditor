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

$stmt = $db->prepare("
    SELECT id, peg_name, inventory_mode
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
$config_id = intval($config["id"]);
$peg_name = $config["peg_name"] ?? null;
$inventory = $config["inventory_mode"] ?? "balanced";


// ------------------ LOAD PEG POINTS ------------------
$points = [];
$q = $db->query("SELECT * FROM peg_points WHERE config_id = $config_id ORDER BY id ASC");

while ($row = $q->fetch_assoc()) {
    $row["price"]  = floatval($row["price"]);
    $row["weight"] = floatval($row["weight"]);
    $points[] = $row;
}


// ------------------ LOAD MODIFIERS ------------------
$mods = [];
$q = $db->query("SELECT * FROM peg_modifiers WHERE config_id = $config_id ORDER BY id ASC");

while ($row = $q->fetch_assoc()) {
    $row["amount"] = floatval($row["amount"]);
    $mods[] = $row;
}


// ------------------ LOAD SALES ------------------
$sales = [];
$q = $db->query("SELECT * FROM sales_data WHERE config_id = $config_id ORDER BY id ASC");

while ($row = $q->fetch_assoc()) {
    $row["sale_price"]   = floatval($row["sale_price"]);
    $row["market_price"] = floatval($row["market_price"]);
    $row["volume"]       = intval($row["volume"]);
    $sales[] = $row;
}

echo json_encode([
    "status"        => "success",
    "config_id"     => $config_id,
    "peg_name"      => $peg_name,
    "inventoryMode" => $inventory,
    "peg" => [
        "points"    => $points,
        "modifiers" => $mods,
        "sales"     => $sales
    ]
]);
?>
