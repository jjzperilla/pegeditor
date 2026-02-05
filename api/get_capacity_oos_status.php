<?php
header('Content-Type: application/json');
require __DIR__ . '/db.php'; // expects $db as mysqli

try {
  $sql = "
    SELECT
      c.capacity,
      MAX(CASE WHEN p.oos = 1 THEN 1 ELSE 0 END) AS has_oos
    FROM peg_points p
    JOIN peg_configs c ON c.id = p.config_id
    GROUP BY c.capacity
  ";

  $result = $db->query($sql);
  if (!$result) {
    throw new Exception($db->error);
  }

  $map = [];
  while ($row = $result->fetch_assoc()) {
    $cap = $row['capacity'];
    $map[$cap] = (int)$row['has_oos'];
  }

  echo json_encode([
    "status" => "ok",
    "oosByCapacity" => $map
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "status" => "error",
    "message" => $e->getMessage()
  ]);
}
