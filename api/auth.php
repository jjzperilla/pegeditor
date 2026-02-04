<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$timeout = 1800;

if (isset($_SESSION['last_activity']) &&
    time() - $_SESSION['last_activity'] > $timeout) {

  session_unset();
  session_destroy();

  http_response_code(401);
  echo json_encode([
    "status" => "expired"
  ]);
  exit;
}

$_SESSION['last_activity'] = time();

if (!function_exists('isValidCronToken')) {
  function isValidCronToken(?string $token): bool {
    $env = getenv('CRON_TOKEN');
    return !empty($env) && !empty($token) && hash_equals($env, $token);
  }
}

if (!function_exists('requireAuth')) {
 function requireAuth(): void {
  // Allow CLI cron calls using token=... argument
  if (PHP_SAPI === 'cli') {
    $tokenArg = $_SERVER['argv'][1] ?? '';
    $token = $tokenArg;

    if (strpos($tokenArg, 'token=') === 0) {
      $token = substr($tokenArg, 6);
    }

    if (!isValidCronToken($token)) {
      echo json_encode(["status" => "unauthorized", "where" => "cli"]);
      exit;
    }
    return;
  }

  //  Browser/API auth
  if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["status" => "unauthorized"]);
    exit;
  }
}
}


function getWorkspaceId(mysqli $db, int $userId): int {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $ws = (int)($_SESSION['workspace_id'] ?? 0);

  // If ws exists and user has access, keep it
  if ($ws > 0) {
    $chk = $db->prepare("SELECT 1 FROM workspace_users WHERE workspace_id=? AND user_id=? LIMIT 1");
    $chk->bind_param("ii", $ws, $userId);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) return $ws;
  }

  // Otherwise pick first accessible workspace
  $q = $db->prepare("SELECT workspace_id FROM workspace_users WHERE user_id=? ORDER BY workspace_id ASC LIMIT 1");
  $q->bind_param("i", $userId);
  $q->execute();
  $row = $q->get_result()->fetch_assoc();
  $ws = (int)($row['workspace_id'] ?? 0);
  if ($ws <= 0) $ws = 1;

  $_SESSION['workspace_id'] = $ws;
  return $ws;
}

function requireWorkspaceRole(mysqli $db, int $workspaceId, int $userId, array $allowedRoles): void {
  $q = $db->prepare("SELECT role FROM workspace_users WHERE workspace_id=? AND user_id=? LIMIT 1");
  $q->bind_param("ii", $workspaceId, $userId);
  $q->execute();
  $row = $q->get_result()->fetch_assoc();

  if (!$row || !in_array($row['role'], $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(["status"=>"error","message"=>"No workspace access"]);
    exit;
  }
}
