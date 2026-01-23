<?php
require "auth.php";
requireAuth();

header("Content-Type: application/json; charset=utf-8");
require "db.php";

// STRICT mysqli so errors throw exceptions
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {

    $capacity   = $_GET["capacity"]   ?? null;
    $interface  = $_GET["interface"]  ?? null;
    $condition  = $_GET["condition"]  ?? null;
    $drive_type = $_GET["drive_type"] ?? null;

    if (!$capacity || !$interface || !$condition || !$drive_type) {
        echo json_encode([
            "status" => "error",
            "message" => "Missing parameters"
        ]);
        exit;
    }

    /* ===============================
       LOAD CONFIG (WITH MARGIN %)
    ================================ */
    $stmt = $db->prepare("
        SELECT
            pc.id,
            pc.peg_name,
            pc.margin_percent,
            dt.label AS drive_type
        FROM peg_configs pc
        JOIN drive_types dt ON dt.id = pc.drive_type_id
        WHERE
            pc.capacity = ?
            AND pc.interface = ?
            AND pc.condition_type = ?
            AND dt.label = ?
        LIMIT 1
    ");
    $stmt->bind_param("ssss", $capacity, $interface, $condition, $drive_type);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        echo json_encode(["status" => "not_found"]);
        exit;
    }

    $config = $res->fetch_assoc();
    $config_id = (int)$config["id"];
    $peg_name  = $config["peg_name"] ?? null;
    $margin    = isset($config["margin_percent"]) ? (float)$config["margin_percent"] : 50;

    /* ===============================
       LOAD LATEST ADJUSTED PRICE
    ================================ */
    $adjusted_price = 0;

    $stmt = $db->prepare("
        SELECT adjusted_price
        FROM peg_history
        WHERE config_id = ?
        ORDER BY saved_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $adjusted_price = (float)$row["adjusted_price"];
    }

    /* ===============================
       LOAD PEG POINTS
    ================================ */
    $points = [];

    // NOTE: If your table does NOT have created_at, this will throw and you'll see it in JSON now.
    $stmt = $db->prepare("
        SELECT
            id,
            label,
            channel,
            url,
            price,
            qty,
            weight,
            notes,
            peg_modifier,
            adjusted_peg_price,
            oos,
            oos_notified_at,
            created_at
        FROM peg_points
        WHERE config_id = ?
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();
    $q = $stmt->get_result();

    while ($row = $q->fetch_assoc()) {
        $points[] = [
            "id" => (int)$row["id"],
            "label" => $row["label"],
            "channel" => $row["channel"],
            "url" => $row["url"],
            "price" => (float)$row["price"],
            "qty" => (int)$row["qty"],
            "weight" => (float)$row["weight"],
            "notes" => $row["notes"] ?? "",
            "peg_modifier" => (float)$row["peg_modifier"],
            "adjusted_peg_price" => (float)$row["adjusted_peg_price"],
            "oos" => (int)($row["oos"] ?? 0),
            "oos_notified_at" => $row["oos_notified_at"] ?? null,
            "created_at" => $row["created_at"] ?? null
        ];
    }

    /* ===============================
       LOAD MODIFIERS
    ================================ */
    $mods = [];

    $stmt = $db->prepare("
        SELECT id, label, amount, modifier_type
        FROM peg_modifiers
        WHERE config_id = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();
    $q = $stmt->get_result();

    while ($row = $q->fetch_assoc()) {
        $row["amount"] = (float)$row["amount"];
        $row["modifier_type"] = $row["modifier_type"] ?: "buy";
        $mods[] = $row;
    }

    /* ===============================
       LOAD SALES
    ================================ */
    $sales = [];

    $stmt = $db->prepare("
        SELECT id, capacity, day_label, sale_price, market_price, volume
        FROM sales_data
        WHERE config_id = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $config_id);
    $stmt->execute();
    $q = $stmt->get_result();

    while ($row = $q->fetch_assoc()) {
        $row["sale_price"]   = (float)$row["sale_price"];
        $row["market_price"] = (float)$row["market_price"];
        $row["volume"]       = (int)$row["volume"];
        $sales[] = $row;
    }

    /* ===============================
       RESPONSE
    ================================ */
    echo json_encode([
        "status"         => "success",
        "config_id"      => $config_id,
        "peg_name"       => $peg_name,
        "drive_type"     => $config["drive_type"],
        "margin_percent" => $margin,
        "adjusted_price" => $adjusted_price,
        "peg" => [
            "points"    => $points,
            "modifiers" => $mods,
            "sales"     => $sales
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
    exit;
}
