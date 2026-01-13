<?php
header("Content-Type: application/json");
session_start();

/* Destroy session */
session_unset();
session_destroy();

/* Optional: clear session cookie (recommended) */
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(
    session_name(),
    '',
    time() - 42000,
    $params["path"],
    $params["domain"],
    $params["secure"],
    $params["httponly"]
  );
}

echo json_encode([
  "status" => "logged_out"
]);
