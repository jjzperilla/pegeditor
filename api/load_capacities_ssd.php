<?php
require "auth.php";
requireAuth();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
require 'db.php';

// ---- Workspace (default Main = 1) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$workspace_id = (int)($_SESSION['workspace_id'] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

// Load capacities ONLY for this workspace
$stmt = $db->prepare("
    SELECT capacity
    FROM capacities
    WHERE workspace_id = ?
    AND drive_type_id = 2
    ORDER BY id ASC
");
$stmt->bind_param("i", $workspace_id);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}

echo json_encode([
    "status"       => "ok",
    "workspace_id" => $workspace_id,
    "capacities"   => $rows
]);
