<?php
header("Content-Type: application/json");
session_start();

echo json_encode([
  "session_id" => session_id(),
  "session" => $_SESSION
]);
