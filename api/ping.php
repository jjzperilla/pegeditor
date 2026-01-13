<?php
header("Content-Type: application/json");
require "auth.php";
requireAuth();

echo json_encode([
  "status" => "ok"
]);
