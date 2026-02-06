<?php
header('Content-Type: application/json');
require __DIR__ . '/auth.php';
requireAuth();

require __DIR__ . '/db.php'; // $db = mysqli

$workspaceId = isset($_GET['workspace_id']) ? (int)$_GET['workspace_id'] : 0;
if ($workspaceId <= 0) {
  echo json_encode(["status" => "error", "message" => "Missing workspace_id"]);
  exit;
}

try {
  $sql = "
    SELECT
      TRIM(c.capacity) AS capacity,
      MAX(CASE WHEN p.oos = 1 THEN 1 ELSE 0 END) AS has_oos
    FROM peg_points p
    JOIN peg_configs c ON c.id = p.config_id
    WHERE c.workspace_id = ?
    GROUP BY TRIM(c.capacity)
  ";

  $stmt = $db->prepare($sql);
  if (!$stmt) throw new Exception($db->error);

  $stmt->bind_param("i", $workspaceId);
  if (!$stmt->execute()) throw new Exception($stmt->error);

  $result = $stmt->get_result();
  $map = [];

  while ($row = $result->fetch_assoc()) {
    $map[$row['capacity']] = (int)$row['has_oos'];
  }

  echo json_encode(["status" => "ok", "oosByCapacity" => $map]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
