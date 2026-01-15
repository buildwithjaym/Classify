<?php
// grades_sse.php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
  http_response_code(403);
  exit();
}
date_default_timezone_set("Asia/Manila");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost", "root", "", "classify_db");
$conn->set_charset("utf8mb4");

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
// Helpful for proxies
header('X-Accel-Buffering: no');

$studentId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
// Resolve to real student.id by email (same as dashboard)
$user = $conn->prepare("SELECT email FROM users WHERE id=?");
$user->bind_param("i",$studentId);
$user->execute();
$ue = $user->get_result()->fetch_assoc();
$user->close();

if (!$ue) { echo "event: error\ndata: {\"error\":\"user\"}\n\n"; @ob_flush(); flush(); exit(); }
$st = $conn->prepare("SELECT id FROM students WHERE email=? LIMIT 1");
$st->bind_param("s",$ue['email']);
$st->execute();
$sr = $st->get_result()->fetch_assoc();
$st->close();
if(!$sr){ echo "event: error\ndata: {\"error\":\"student\"}\n\n"; @ob_flush(); flush(); exit(); }
$SID = (int)$sr['id'];

function grades_payload($conn, $SID){
  $q = $conn->prepare("
    SELECT 
      s.name  AS subject_name,
      s.code  AS subject_code,
      cr.midterm_grade AS midterm,
      cr.final_grade   AS final,
      COALESCE(
        cr.final_overall,
        CASE 
          WHEN (gw.midterm_weight IS NOT NULL AND gw.final_weight IS NOT NULL
                AND cr.midterm_grade IS NOT NULL AND cr.final_grade IS NOT NULL
                AND (gw.midterm_weight + gw.final_weight) > 0)
          THEN ROUND((gw.midterm_weight*cr.midterm_grade + gw.final_weight*cr.final_grade)/(gw.midterm_weight+gw.final_weight),2)
          ELSE NULL
        END
      ) AS overall
    FROM enrollments e
    JOIN subjects s ON s.id = e.subject_id
    LEFT JOIN class_record cr
      ON cr.subject_id = e.subject_id AND cr.student_id = ?
    LEFT JOIN grading_weights gw
      ON gw.subject_id = e.subject_id
    WHERE e.student_id = ?
    ORDER BY s.name ASC
  ");
  $q->bind_param("ii", $SID, $SID);
  $q->execute();
  $res = $q->get_result()->fetch_all(MYSQLI_ASSOC);
  $q->close();
  return $res;
}

function grades_version($conn, $SID){
  // Single “version” string that changes when any relevant row changes
  $sql = $conn->prepare("
    SELECT
      COALESCE(
        DATE_FORMAT(MAX(cr.updated_at),'%Y-%m-%d %H:%i:%s'),
        '1970-01-01 00:00:00'
      ) AS v
    FROM enrollments e
    LEFT JOIN class_record cr
      ON cr.subject_id = e.subject_id AND cr.student_id = ?
    WHERE e.student_id = ?
  ");
  $sql->bind_param("ii", $SID, $SID);
  $sql->execute();
  $ver = $sql->get_result()->fetch_assoc();
  $sql->close();
  return $ver ? $ver['v'] : '1970-01-01 00:00:00';
}

$last = '';
$start = time();
$MAX_SECONDS = 300; // keep open ~5 minutes; frontend will reconnect

while (true) {
  if (connection_aborted()) { break; }
  $nowVer = grades_version($conn, $SID);
  if ($nowVer !== $last) {
    $last = $nowVer;
    $payload = grades_payload($conn, $SID);
    echo "event: grades\n";
    echo "data: " . json_encode(['version'=>$nowVer,'items'=>$payload]) . "\n\n";
    @ob_flush(); flush();
  } else {
    // heartbeat to keep the connection alive (and proxies happy)
    echo "event: ping\ndata: {}\n\n";
    @ob_flush(); flush();
  }
  if ((time() - $start) > $MAX_SECONDS) { break; }
  sleep(3); // poll DB every 3s; adjust as you like
}
