<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header("Content-Type: application/json");

require __DIR__ . "/auth.php";
requireAuth();

require __DIR__ . "/db.php";

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$workspace_id = (int)($_SESSION["workspace_id"] ?? 1);
if ($workspace_id <= 0) $workspace_id = 1;

$pegPointId = (int)($_GET["peg_point_id"] ?? 0);
if ($pegPointId <= 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "peg_point_id is required"]);
  exit;
}

// Pull OOS episodes
// Assumption:
// - queued_date: DATE or DATETIME (start of OOS)
// - noted_at: DATETIME nullable (end of OOS / resolved)
// If noted_at is NULL => open-ended (still OOS)
$stmt = $db->prepare("
  SELECT
    DATE(queue_day) AS start_date,
    CASE
      WHEN noted_at IS NULL THEN NULL
      ELSE DATE(noted_at)
    END AS end_date
  FROM oos_email_queue
  WHERE workspace_id = ?
    AND peg_point_id = ?
    AND queue_day IS NOT NULL
  ORDER BY DATE(queue_day) ASC
");
$stmt->bind_param("ii", $workspace_id, $pegPointId);
$stmt->execute();
$res = $stmt->get_result();

$ranges = [];
while ($row = $res->fetch_assoc()) {
  $s = $row["start_date"];
  $e = $row["end_date"]; // may be null
  if (!$s) continue;

  // Safety: if end exists but end < start, clamp end to start
  if ($e !== null && $e < $s) $e = $s;

  $ranges[] = ["start" => $s, "end" => $e];
}

// Merge overlapping/adjacent ranges (important if you have multiple entries close together)
usort($ranges, fn($a, $b) => strcmp($a["start"], $b["start"]));

$merged = [];
foreach ($ranges as $r) {
  if (!$merged) {
    $merged[] = $r;
    continue;
  }

  $lastIdx = count($merged) - 1;
  $last = $merged[$lastIdx];

  $lastEnd = $last["end"] ?? "9999-12-31";
  $curStart = $r["start"];
  $curEnd = $r["end"] ?? "9999-12-31";

  // If current starts <= lastEnd + 1 day => merge
  $lastEndPlus1 = (new DateTime($lastEnd))->modify("+1 day")->format("Y-m-d");
  if ($curStart <= $lastEndPlus1) {
    // merged end is max(lastEnd, curEnd), but keep null if any is open-ended
    if ($last["end"] === null || $r["end"] === null) {
      $merged[$lastIdx]["end"] = null;
    } else {
      $merged[$lastIdx]["end"] = max($last["end"], $r["end"]);
    }
  } else {
    $merged[] = $r;
  }
}

echo json_encode([
  "status" => "ok",
  "peg_point_id" => $pegPointId,
  "ranges" => $merged
]);