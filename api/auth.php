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

if (!function_exists('isValidCronToken')) {
  function isValidCronToken(?string $token): bool {
    $env = getenv('CRON_TOKEN');
    return !empty($env) && !empty($token) && hash_equals($env, $token);
  }
}

if (!function_exists('requireAuth')) {
  function requireAuth(): void {
    // ✅ Allow CLI cron calls using token=... argument
    if (PHP_SAPI === 'cli') {
      $tokenArg = $_SERVER['argv'][1] ?? '';
      $token = $tokenArg;

      // allow formats: token=XXXXX  OR  just XXXXX
      if (strpos($tokenArg, 'token=') === 0) {
        $token = substr($tokenArg, 6);
      }

      if (!isValidCronToken($token)) {
        echo json_encode(["status" => "unauthorized", "where" => "cli"]);
        exit;
      }
      return;
    }

    //Browser/API auth logic (your existing session check)
    // Example:
    // session_start();
    // if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(["status"=>"unauthorized"]); exit; }
  }
}