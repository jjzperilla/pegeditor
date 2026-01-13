<?php
session_start();

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

function requireAuth() {
  if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401);
    echo json_encode(["status" => "unauthorized"]);
    exit;
  }
}
