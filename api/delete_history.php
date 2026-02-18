<?php
require "auth.php";
requireAuth();
header("Content-Type: application/json");
require "db.php";
require __DIR__ . "/role.php";
requireEditor($db);

// ---- Workspace (default to Main = 1) ----
// If you already have a helper like currentWorkspaceId(), use that instead.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

$payload = json_decode(file_get_contents("php://input"), true);
$id = isset($payload["id"]) ? (int)$payload["id"] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Missing history id"
    ]);
    exit;
}

// Find config_id from history row (must match workspace)
$check = $db->prepare("
    SELECT h.config_id
    FROM peg_history h
    JOIN peg_configs c ON c.id = h.config_id
    WHERE h.id = ?
      AND h.workspace_id = ?
      AND c.workspace_id = ?
    LIMIT 1
");
$check->bind_param("iii", $id, $workspace_id, $workspace_id);
$check->execute();
$res = $check->get_result();

if ($res->num_rows === 0) {
    // Either doesn't exist, or belongs to a different workspace (treat as not found)
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

    // Delete modifiers & sales (scoped)
    $stmt = $db->prepare("DELETE FROM peg_modifiers WHERE config_id = ? AND workspace_id = ?");
    $stmt->bind_param("ii", $config_id, $workspace_id);
    $stmt->execute();

    $stmt = $db->prepare("DELETE FROM sales_data WHERE config_id = ? AND workspace_id = ?");
    $stmt->bind_param("ii", $config_id, $workspace_id);
    $stmt->execute();

    // Delete peg points (scoped)
    // NOTE: Will still fail if peg_point_history FK blocks deletion (as you mentioned).
    $stmt = $db->prepare("DELETE FROM peg_points WHERE config_id = ? AND workspace_id = ?");
    $stmt->bind_param("ii", $config_id, $workspace_id);
    $stmt->execute();

    // Delete config history snapshot row (scoped)
    $stmt = $db->prepare("DELETE FROM peg_history WHERE id = ? AND workspace_id = ?");
    $stmt->bind_param("ii", $id, $workspace_id);
    $stmt->execute();

    // Delete config (scoped)
    $stmt = $db->prepare("DELETE FROM peg_configs WHERE id = ? AND workspace_id = ?");
    $stmt->bind_param("ii", $config_id, $workspace_id);
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
