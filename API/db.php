<?php
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'pegeditor';


$db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db->connect_errno) {
header('Content-Type: application/json');
http_response_code(500);
echo json_encode(["error" => "DB connection failed: " . $db->connect_error]);
exit;
}
$db->set_charset('utf8mb4');
?>
