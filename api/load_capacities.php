<?php
header('Content-Type: application/json');
require 'db.php';


$res = $db->query("SELECT capacity FROM capacities ORDER BY id ASC");
$rows = [];
if ($res) {
while ($r = $res->fetch_assoc()) $rows[] = $r;
}


echo json_encode(["capacities" => $rows]);
?>
