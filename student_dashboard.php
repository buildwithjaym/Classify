<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
  header("Location: classify_login.php");
  exit();
}
date_default_timezone_set("Asia/Manila");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn = new mysqli("localhost", "root", "", "classify_db");
  $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
  http_response_code(500);
  die("Database connection error");
}

function json_api($payload, $code = 200)
{
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}
function sql($conn, $q, $types = "", $params = [])
{
  $stmt = $conn->prepare($q);
  if ($stmt === false)
    throw new Exception("SQL prepare failed");
  if ($types !== "" && !empty($params)) {
    $refs = [];
    foreach ($params as $k => $v)
      $refs[$k] = &$params[$k];
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();
  return $rows;
}
function sql_one($conn, $q, $types = "", $params = [])
{
  $rows = sql($conn, $q, $types, $params);
  return $rows ? $rows[0] : null;
}

$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$user = sql_one($conn, "SELECT * FROM users WHERE id=?", "i", [$user_id]);
if (!$user) {
  die("User not found.");
}

$student = sql_one($conn, "SELECT * FROM students WHERE email=? LIMIT 1", "s", [$user['email']]);
if (!$student) {
  die("Student record not found.");
}

$SID = (int) $student['id'];
$SNAME = $student['name'];
$SPhotoFile = !empty($student['photo']) ? $student['photo'] : "default.png";
$SPhotoPath = "uploads/students/" . $SPhotoFile;

if (isset($_POST['upload_student_photo'])) {
  if (!empty($_FILES['student_photo']['name'])) {
    if ($_FILES['student_photo']['size'] <= 3 * 1024 * 1024) {
      $base = basename($_FILES['student_photo']['name']);
      $new = time() . "_" . preg_replace("/[^A-Za-z0-9_\.-]/", "_", $base);
      $target = "uploads/students/" . $new;
      if (
        is_uploaded_file($_FILES['student_photo']['tmp_name']) &&
        move_uploaded_file($_FILES['student_photo']['tmp_name'], $target)
      ) {
        sql($conn, "UPDATE students SET photo=? WHERE id=?", "si", [$new, $SID]);
      }
    }
  }
  header("Location: student_dashboard.php");
  exit();
}

if (isset($_GET['api'])) {
  try {
    $api = $_GET['api'];

    if ($api === 'schedule_today') {
      $todayShort = date("D");
      $items = sql($conn, "
                SELECT a.subject_id, a.days, a.time_start, a.time_end, a.faculty_id,
                       s.code AS subj_code, s.name AS subj_name,
                       f.name AS fac_name, COALESCE(f.photo,'default.png') AS fac_photo
                FROM enrollments e
                JOIN subjects s    ON e.subject_id = s.id
                JOIN assignments a ON a.subject_id = s.id
                JOIN faculty f     ON f.id = a.faculty_id
                WHERE e.student_id=? AND a.days LIKE CONCAT('%', ?, '%')
                ORDER BY a.time_start
            ", "is", [$SID, $todayShort]);

      foreach ($items as &$r) {
        $cxl = sql_one(
          $conn,
          "SELECT 1 FROM cancellations
                     WHERE subject_code=? AND faculty_name=? AND DATE(`date`)=CURDATE()
                     LIMIT 1",
          "ss",
          [$r['subj_code'], $r['fac_name']]
        );
        $r['cancelled_today'] = $cxl ? 1 : 0;
        $r['fac_photo'] = 'uploads/faculty/' . ($r['fac_photo'] ? $r['fac_photo'] : 'default.png');
      }
      unset($r);
      json_api(['ok' => 1, 'items' => $items, 'todayShort' => $todayShort]);
    }

    if ($api === 'schedule_week') {
      $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
      $out = array_fill_keys($days, []);
      $rows = sql($conn, "
                SELECT a.subject_id, a.days, a.time_start, a.time_end, a.faculty_id,
                       s.code AS subj_code, s.name AS subj_name,
                       f.name AS fac_name, COALESCE(f.photo,'default.png') AS fac_photo
                FROM enrollments e
                JOIN subjects s ON e.subject_id = s.id
                JOIN assignments a ON a.subject_id = s.id
                JOIN faculty f ON f.id = a.faculty_id
                WHERE e.student_id=?
                ORDER BY a.time_start
            ", "i", [$SID]);

      foreach ($rows as $r) {
        $pic = 'uploads/faculty/' . ($r['fac_photo'] ? $r['fac_photo'] : 'default.png');
        foreach ($days as $d) {
          if (strpos($r['days'], $d) !== false) {
            $r2 = $r;
            $r2['fac_photo'] = $pic;
            $out[$d][] = $r2;
          }
        }
      }
      json_api(['ok' => 1, 'schedule' => $out, 'today' => date('D')]);
    }

    if ($api === 'attendance_summary') {
      $summary = ['Present' => 0, 'Absent' => 0, 'Late' => 0];
      $rows = sql(
        $conn,
        "SELECT status, COUNT(*) AS c FROM attendance
                 WHERE student_id=? GROUP BY status",
        "i",
        [$SID]
      );
      foreach ($rows as $r) {
        if (isset($summary[$r['status']]))
          $summary[$r['status']] = (int) $r['c'];
      }

      $perSub = [];
      $rows = sql(
        $conn,
        "SELECT s.code, s.name, at.status, COUNT(*) AS c
                 FROM attendance at
                 JOIN subjects s ON s.id = at.subject_id
                 WHERE at.student_id=? AND at.`date`>=DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY at.subject_id, at.status, s.code, s.name",
        "i",
        [$SID]
      );
      foreach ($rows as $r) {
        $key = $r['code'] . ' — ' . $r['name'];
        if (!isset($perSub[$key]))
          $perSub[$key] = ['Present' => 0, 'Absent' => 0, 'Late' => 0];
        if (isset($perSub[$key][$r['status']]))
          $perSub[$key][$r['status']] = (int) $r['c'];
      }

      $weeklyLabels = [];
      $weeklySeries = [];
      for ($i = 7; $i >= 0; $i--) {
        $monday = strtotime("-$i week Monday");
        $start = date("Y-m-d", $monday);
        $end = date("Y-m-d", strtotime($start . " +6 day"));
        $weeklyLabels[] = date("M j", $monday);
        $row = sql_one(
          $conn,
          "SELECT COUNT(*) AS c
                     FROM attendance
                     WHERE student_id=? AND status='Present' AND `date` BETWEEN ? AND ?",
          "iss",
          [$SID, $start, $end]
        );
        $weeklySeries[] = $row ? (int) $row['c'] : 0;
      }

      json_api([
        'ok' => 1,
        'summary' => $summary,
        'per_subject' => $perSub,
        'weekly_labels' => $weeklyLabels,
        'weekly_series' => $weeklySeries
      ]);
    }

    if ($api === 'notifications') {
      $items = sql(
        $conn,
        "SELECT id,title,message,created_at,is_read
                 FROM notifications
                 WHERE user_type='student' AND user_id=?
                 ORDER BY created_at DESC
                 LIMIT 100",
        "i",
        [$SID]
      );
      $cx = sql(
        $conn,
        "SELECT c.subject_code, c.faculty_name, c.reason, c.`date`, s.name AS subj_name
                 FROM cancellations c
                 JOIN subjects s    ON s.code = c.subject_code
                 JOIN enrollments e ON e.subject_id = s.id
                 WHERE e.student_id=? AND DATE(c.`date`)=CURDATE()
                 ORDER BY c.`date` DESC",
        "i",
        [$SID]
      );
      foreach ($cx as $r) {
        $items[] = [
          'id' => 'cxl_' . $r['subject_code'] . '_' . $r['date'],
          'title' => 'Class Cancelled — ' . $r['subject_code'],
          'message' => $r['subj_name'] . ' with ' . $r['faculty_name'] .
            ' is cancelled today. Reason: ' . ($r['reason'] ? $r['reason'] : 'Not specified'),
          'created_at' => $r['date'],
          'is_read' => 0,
          'type' => 'cancel'
        ];
      }
      usort($items, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
      });
      json_api(['ok' => 1, 'items' => $items]);
    }

    if ($api === 'notice_ack') {
      $nid = isset($_POST['id']) ? $_POST['id'] : '';
      if ($nid === '')
        json_api(['ok' => 0, 'error' => 'Missing id'], 422);
      if (ctype_digit((string) $nid)) {
        sql($conn, "UPDATE notifications SET is_read=1 WHERE id=? AND user_type='student' AND user_id=?", "ii", [(int) $nid, $SID]);
      }
      json_api(['ok' => 1]);
    }

    if ($api === 'notice_ack_all') {
      sql($conn, "UPDATE notifications SET is_read=1 WHERE user_type='student' AND user_id=?", "i", [$SID]);
      json_api(['ok' => 1]);
    }

    if ($api === 'student_grades') {
      // Expect class_record(midterm_grade, final_grade, final_overall). Optional grading_weights(midterm_weight, final_weight).
      $rows = sql(
        $conn,
        "SELECT 
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
                   ON cr.subject_id = e.subject_id
                  AND cr.student_id = ?
                 LEFT JOIN grading_weights gw
                   ON gw.subject_id = e.subject_id
                 WHERE e.student_id = ?
                 ORDER BY s.name ASC",
        "ii",
        [$SID, $SID]
      );
      json_api(['ok' => 1, 'items' => $rows]);
    }

    json_api(['ok' => 0, 'error' => 'Unknown API'], 404);
  } catch (Throwable $e) {
    json_api(['ok' => 0, 'error' => 'Server error (API)'], 500);
  }
}

$todayShort = date("D");
$row = sql_one($conn, "SELECT COUNT(*) c FROM enrollments WHERE student_id=?", "i", [$SID]);
$totalSubjects = $row ? (int) $row['c'] : 0;

$row = sql_one(
  $conn,
  "SELECT COUNT(*) c FROM attendance
     WHERE student_id=? AND `date`=CURDATE() AND status='Present'",
  "i",
  [$SID]
);
$presentToday = $row ? (int) $row['c'] : 0;

$row = sql_one(
  $conn,
  "SELECT COUNT(*) c FROM notifications
     WHERE user_type='student' AND user_id=? AND is_read=0",
  "i",
  [$SID]
);
$notifUnread = $row ? (int) $row['c'] : 0;
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Student Dashboard | CLASSIFY</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --bg-grad: radial-gradient(1200px 600px at 15% -20%, rgba(0, 198, 255, .12), transparent 60%),
        radial-gradient(1000px 520px at 95% -25%, rgba(0, 114, 255, .14), transparent 60%),
        linear-gradient(135deg, #0f2027, #203a43, #2c5364);
      --pane: rgba(255, 255, 255, 0.06);
      --pane-strong: rgba(255, 255, 255, 0.12);
      --ink: #f6fbff;
      --muted: #cfe0f4;
      --accent: #00c6ff;
      --accent2: #0072ff;
      --ok: #22c55e;
      --warn: #f59e0b;
      --danger: #e63946;
      --stroke: rgba(255, 255, 255, 0.14);
      --shadow: 0 10px 30px rgba(0, 0, 0, .4);
      --r: 16px;
      --r-xl: 22px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, sans-serif
    }

    html,
    body {
      min-height: 100vh;
      background: var(--bg-grad);
      color: var(--ink);
      overflow-x: hidden;
      scroll-behavior: smooth
    }

    .layout {
      display: flex;
      min-height: 100vh
    }

    .container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 0 16px
    }

    @keyframes fadeUp {
      from {
        opacity: 0;
        transform: translateY(10px)
      }

      to {
        opacity: 1;
        transform: translateY(0)
      }
    }

    @keyframes subtleGlow {
      from {
        box-shadow: 0 0 0 rgba(0, 198, 255, 0)
      }

      to {
        box-shadow: 0 0 24px rgba(0, 198, 255, .28)
      }
    }

    .sidebar {
      width: 260px;
      background: rgba(5, 12, 25, .78);
      backdrop-filter: blur(18px);
      padding: 22px 14px;
      border-right: 1px solid var(--stroke);
      box-shadow: var(--shadow);
      animation: fadeUp .5s ease-out
    }

    .logo-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin: 2px 4px 22px
    }

    .sidebar .logo {
      font-weight: 800;
      font-size: 22px;
      letter-spacing: .08em
    }

    .sidebar-close {
      display: none;
      border: none;
      background: transparent;
      color: #e2e8f0;
      cursor: pointer;
      padding: 4px;
      border-radius: 999px;
      font-size: 20px;
      transition: .2s
    }

    .sidebar-close:hover {
      background: rgba(148, 163, 184, .25)
    }

    .sidebar a {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 11px 12px;
      margin: 6px 2px;
      border-radius: 12px;
      color: #e7f3ff;
      text-decoration: none;
      transition: .22s
    }

    .sidebar a i {
      font-size: 18px
    }

    .sidebar a:hover {
      background: rgba(255, 255, 255, .08);
      transform: translateX(2px);
      box-shadow: 0 0 20px rgba(0, 198, 255, .32)
    }

    .sidebar a.active {
      background: linear-gradient(135deg, var(--accent2), var(--accent));
      color: #fff;
      box-shadow: 0 0 26px rgba(0, 198, 255, .55)
    }

    .sidebar .logout {
      margin-top: auto;
      background: rgba(230, 57, 70, .16)
    }

    .badge {
      margin-left: auto;
      background: rgba(0, 198, 255, .14);
      border: 1px solid rgba(0, 198, 255, .55);
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px
    }

    .main {
      flex: 1
    }

    .main>.container {
      padding: 18px 16px 28px
    }

    .top {
      background: rgba(0, 0, 0, .33);
      border: 1px solid var(--stroke);
      border-radius: var(--r-xl);
      padding: 16px 18px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
      animation: fadeUp .5s
    }

    .top::after {
      content: "";
      position: absolute;
      width: 260px;
      height: 260px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(0, 198, 255, .5), transparent 65%);
      top: -120px;
      right: -80px;
      opacity: .6
    }

    .subtitle {
      color: var(--muted);
      font-size: .86rem;
      margin-top: 4px
    }

    .header-right {
      display: flex;
      align-items: center;
      gap: 10px
    }

    #hamburger {
      display: none;
      border: none;
      background: var(--pane-strong);
      color: #fff;
      padding: 8px 10px;
      border-radius: 10px;
      cursor: pointer;
      transition: .2s
    }

    #hamburger:hover {
      transform: translateY(-1px);
      box-shadow: 0 0 16px rgba(0, 198, 255, .4)
    }

    .avatar img {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid rgba(0, 198, 255, .75);
      box-shadow: 0 0 12px rgba(0, 198, 255, .45);
      cursor: pointer;
      transition: .2s
    }

    .avatar img:hover {
      transform: scale(1.03);
      animation: subtleGlow .6s ease-out forwards
    }

    .board {
      margin: 18px 0 22px;
      padding: 16px;
      border-radius: 18px;
      border: 2px solid rgba(255, 255, 255, .12);
      position: relative;
      box-shadow: inset 0 0 70px rgba(0, 0, 0, .55), 0 10px 26px rgba(0, 0, 0, .45);
      background:
        radial-gradient(1000px 800px at 20% 10%, rgba(255, 255, 255, .04), transparent 50%),
        radial-gradient(800px 600px at 80% 30%, rgba(255, 255, 255, .03), transparent 45%),
        linear-gradient(135deg, #0c1a14, #0e2a23 55%, #0b1f1a);
    }

    .board::before {
      content: "";
      position: absolute;
      inset: 8px;
      border: 1px dashed rgba(255, 255, 255, .1);
      border-radius: 14px;
      pointer-events: none
    }

    .board-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 10px
    }

    .board-title {
      font-weight: 800;
      font-size: 1.1rem;
      color: #eef7ff;
      text-shadow: 0 1px 0 rgba(255, 255, 255, .1)
    }

    .board-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px
    }

    .board .chip {
      font-size: .78rem;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, .28);
      background: rgba(255, 255, 255, .08);
      color: #e7f3ff;
      cursor: pointer
    }

    .board .chip.active {
      border-color: rgba(0, 198, 255, .7);
      box-shadow: 0 0 0 2px rgba(0, 198, 255, .2) inset;
      background: rgba(0, 198, 255, .18)
    }

    .board-tools {
      display: flex;
      gap: 8px;
      align-items: center
    }

    .board-btn {
      border: none;
      border-radius: 10px;
      padding: 8px 10px;
      background: rgba(255, 255, 255, .12);
      color: #fff;
      cursor: pointer
    }

    .board-btn:hover {
      box-shadow: 0 0 0 2px rgba(255, 255, 255, .15) inset
    }

    .board-list {
      list-style: none;
      margin-top: 6px
    }

    .board-item {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 10px;
      align-items: center;
      padding: 10px 12px;
      border-radius: 12px;
      margin: 8px 0;
      border: 1px solid rgba(255, 255, 255, .12);
      background: rgba(255, 255, 255, .03)
    }

    .board-item .left .title {
      font-weight: 700;
      color: #f6fbff
    }

    .board-item .left .meta {
      color: #dfe9f5;
      font-size: .82rem;
      opacity: .9;
      margin-top: 2px
    }

    .board-item .actions {
      display: flex;
      gap: 6px
    }

    .ack {
      background: linear-gradient(135deg, var(--accent2), var(--accent));
      color: #fff;
      border: none;
      border-radius: 999px;
      padding: 6px 10px;
      cursor: pointer
    }

    .soft {
      background: rgba(255, 255, 255, .12);
      color: #fff;
      border: none;
      border-radius: 999px;
      padding: 6px 10px;
      cursor: pointer
    }

    .board-item.unread {
      box-shadow: 0 0 0 2px rgba(0, 198, 255, .25) inset
    }

    .board-item.cancel {
      border-color: rgba(230, 57, 70, .7);
      background: linear-gradient(135deg, rgba(70, 10, 15, .7), rgba(18, 30, 35, .6))
    }

    .pin-active {
      box-shadow: 0 0 0 2px rgba(255, 238, 88, .4) inset
    }

    .badge-dot {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #ffed4a;
      margin-right: 6px;
      box-shadow: 0 0 8px rgba(255, 237, 74, .75)
    }

    .grid4 {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
      margin: 16px 0 22px
    }

    @media(max-width:1100px) {
      .grid4 {
        grid-template-columns: repeat(2, 1fr)
      }
    }

    @media(max-width:600px) {
      .grid4 {
        grid-template-columns: 1fr
      }
    }

    .card {
      background: var(--pane);
      border: 1px solid var(--stroke);
      border-radius: var(--r);
      padding: 16px;
      box-shadow: var(--shadow);
      transition: .22s;
      animation: fadeUp .45s
    }

    .card:hover {
      transform: translateY(-3px);
      border-color: rgba(0, 198, 255, .6);
      box-shadow: 0 0 24px rgba(0, 198, 255, .3)
    }

    .card i {
      font-size: 22px;
      color: #d7ebff
    }

    .card .label {
      color: #c9dcf4;
      font-size: .82rem;
      margin-top: 4px
    }

    .card .value {
      font-weight: 800;
      font-size: 1.35rem;
      margin-top: 2px
    }

    .section-title {
      font-size: 1.05rem;
      font-weight: 800;
      margin: 8px 0 10px
    }

    .panel {
      background: rgba(4, 10, 24, .9);
      border: 1px solid var(--stroke);
      border-radius: var(--r-xl);
      padding: 14px;
      box-shadow: var(--shadow);
      margin-bottom: 18px;
      animation: fadeUp .6s
    }

    .panel-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px
    }

    .chip {
      font-size: .75rem;
      padding: 3px 10px;
      border-radius: 999px;
      border: 1px solid rgba(0, 198, 255, .6);
      background: rgba(0, 198, 255, .12);
      color: #e6f5ff
    }

    .chip-soft {
      border: 1px solid rgba(148, 163, 184, .6);
      background: rgba(15, 23, 42, .65)
    }

    .schedule-list {
      display: grid;
      grid-template-columns: 1fr;
      gap: 10px
    }

    .schedule-card {
      display: grid;
      grid-template-columns: 68px 1fr auto;
      gap: 12px;
      align-items: center;
      background: rgba(6, 14, 30, .92);
      border: 1px solid var(--stroke);
      border-radius: 14px;
      padding: 10px 12px;
      position: relative;
      transition: .22s
    }

    .schedule-card:hover {
      transform: translateY(-2px) translateX(1px);
      border-color: rgba(0, 198, 255, .7);
      box-shadow: 0 0 26px rgba(0, 198, 255, .35)
    }

    .fac-photo {
      width: 60px;
      height: 60px;
      border-radius: 12px;
      object-fit: cover;
      border: 2px solid rgba(0, 198, 255, .6)
    }

    .s-head {
      display: flex;
      gap: 8px;
      align-items: center
    }

    .s-name {
      font-weight: 700
    }

    .subject-pill {
      display: inline-block;
      margin-left: 6px;
      font-size: 11px;
      padding: 2px 8px;
      border-radius: 999px;
      border: 1px solid rgba(0, 198, 255, .55);
      background: rgba(0, 198, 255, .14)
    }

    .s-meta {
      color: #c6daf4;
      font-size: .82rem;
      margin-top: 2px
    }

    .s-right {
      text-align: right
    }

    .s-time {
      font-size: .86rem
    }

    .s-count {
      color: #8ad0ff;
      font-size: .78rem;
      font-weight: 700;
      margin-top: 4px
    }

    .cancelled {
      border-color: rgba(230, 57, 70, .9) !important;
      background: linear-gradient(135deg, rgba(80, 8, 16, .96), rgba(12, 16, 28, .95)) !important
    }

    .cancel-mark {
      position: absolute;
      top: 8px;
      right: 8px;
      background: #e63946;
      color: #fff;
      font-size: 10px;
      padding: 3px 7px;
      border-radius: 999px
    }

    .grade-pass {
      color: #38ef7d;
      font-weight: 700
    }

    .grade-fail {
      color: #ef4444;
      font-weight: 700
    }

    .tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-bottom: 8px
    }

    .tab {
      background: rgba(255, 255, 255, .06);
      border: 1px solid var(--stroke);
      color: #e5f1ff;
      border-radius: 10px;
      padding: 8px 10px;
      cursor: pointer;
      transition: .2s
    }

    .tab:hover {
      transform: translateY(-1px);
      border-color: rgba(0, 198, 255, .6)
    }

    .tab.active {
      background: linear-gradient(135deg, var(--accent2), var(--accent));
      border-color: transparent
    }

    .notice {
      border: 1px solid var(--stroke);
      border-radius: 12px;
      padding: 10px;
      background: rgba(2, 8, 22, .97);
      box-shadow: var(--shadow);
      margin-bottom: 8px;
      transition: .22s
    }

    .notice:hover {
      transform: translateY(-1px);
      box-shadow: 0 0 20px rgba(0, 198, 255, .2)
    }

    .notice-cancel {
      border-color: rgba(230, 57, 70, .8);
      background: linear-gradient(135deg, rgba(80, 8, 16, .95), rgba(9, 16, 28, .98))
    }

    .notice-title {
      font-weight: 700
    }

    .notice-meta {
      font-size: .75rem;
      color: #cfe0f4;
      margin: 2px 0 4px
    }

    .att-grid {
      display: grid;
      grid-template-columns: 2.1fr 0.9fr;
      gap: 14px;
      align-items: stretch;
      margin-top: 10px
    }

    .att-line,
    .att-donut {
      background: rgba(8, 16, 36, .9);
      border-radius: 18px;
      border: 1px solid var(--stroke);
      padding: 12px;
      box-shadow: 0 10px 26px rgba(0, 0, 0, .45)
    }

    .att-donut {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px
    }

    .att-caption {
      font-size: 12px;
      color: var(--muted)
    }

    .att-legend-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      justify-content: center;
      font-size: 11px
    }

    .att-badge {
      padding: 3px 8px;
      border-radius: 999px;
      border: 1px solid rgba(148, 163, 184, .7);
      background: rgba(15, 23, 42, .9)
    }

    .att-line canvas,
    .att-donut canvas {
      width: 100% !important;
      height: auto !important;
      max-height: 220px
    }

    #perSubject table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 8px;
      font-size: 13px
    }

    #perSubject th {
      text-align: left;
      padding: 4px 8px;
      color: #e5edff
    }

    #perSubject td {
      padding: 6px 8px
    }

    #perSubject tr td:first-child {
      background: #0b1630;
      border: 1px solid var(--stroke);
      border-radius: 8px 0 0 8px
    }

    #perSubject tr td:not(:first-child):not(:last-child) {
      background: #0b1630;
      border-top: 1px solid var(--stroke);
      border-bottom: 1px solid var(--stroke)
    }

    #perSubject tr td:last-child {
      background: #0b1630;
      border: 1px solid var(--stroke);
      border-radius: 0 8px 8px 0
    }

    #toast {
      position: fixed;
      top: -120px;
      left: 50%;
      transform: translateX(-50%);
      background: rgba(3, 8, 23, .98);
      border: 1px solid var(--accent);
      color: #e6f1ff;
      border-radius: 999px;
      padding: 10px 14px;
      z-index: 200;
      transition: top .3s;
      font-size: .85rem
    }

    @media(max-width:1024px) {
      #hamburger {
        display: inline-flex
      }

      .sidebar {
        position: fixed;
        left: -260px;
        top: 0;
        height: 100vh;
        z-index: 150;
        transition: left .28s
      }

      .sidebar.open {
        left: 0;
        box-shadow: 12px 0 36px rgba(0, 0, 0, .7)
      }

      .sidebar-close {
        display: inline-flex
      }
    }

    @media(max-width:900px) {
      .att-grid {
        grid-template-columns: 1fr
      }

      .att-donut {
        max-width: 260px;
        margin: 0 auto
      }
    }

    @media(max-width:720px) {
      .top {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px
      }
    }

    .table-container {
      overflow: auto
    }

    .table-container table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 8px;
      font-size: 13px
    }

    .table-container th {
      text-align: left;
      padding: 6px 8px;
      color: #e5edff
    }

    .table-container td {
      padding: 8px
    }

    .table-container tr td {
      background: #0b1630;
      border-top: 1px solid var(--stroke);
      border-bottom: 1px solid var(--stroke)
    }

    .table-container tr td:first-child {
      border-left: 1px solid var(--stroke);
      border-radius: 8px 0 0 8px
    }

    .table-container tr td:last-child {
      border-right: 1px solid var(--stroke);
      border-radius: 0 8px 8px 0
    }
  </style>
</head>

<body>
  <div class="layout">
    <aside class="sidebar" id="sidebar">
      <div class="logo-row">
        <div class="logo">CLASSIFY</div>
        <button class="sidebar-close" id="closeSidebar" title="Close menu"><i class="ri-close-line"></i></button>
      </div>
      <a class="active"><i class="ri-dashboard-line"></i> <span>Dashboard</span></a>
      <a href="#" id="openWeekly"><i class="ri-calendar-schedule-line"></i> <span>Weekly Schedule</span></a>
      <a href="#" id="openAttendance"><i class="ri-bar-chart-2-line"></i> <span>My Attendance</span></a>
      <a href="#" id="openNotifications"><i class="ri-notification-3-line"></i> <span>Notifications</span> <span
          class="badge" id="notifCount"><?= htmlspecialchars((string) $notifUnread) ?></span></a>
      <a href="logout.php" class="logout"><i class="ri-logout-box-line"></i> <span>Logout</span></a>
    </aside>

    <main class="main">
      <div class="container">
        <div class="top">
          <div>
            <h1>Hi, <?= htmlspecialchars($SNAME) ?> 👋</h1>
            <div class="subtitle">Stay on track. Learn smart. Breathe easy.</div>
          </div>
          <div class="header-right">
            <button id="hamburger" title="Menu"><i class="ri-menu-3-line"></i></button>
            <form id="uploadStudentPhotoForm" method="POST" enctype="multipart/form-data" style="display:none">
              <input type="hidden" name="upload_student_photo" value="1">
              <input type="file" name="student_photo" id="studentPhotoInput" accept="image/*">
            </form>
            <div class="avatar" title="Change photo">
              <img src="<?= htmlspecialchars($SPhotoPath) ?>" alt="Me"
                onclick="document.getElementById('studentPhotoInput').click()">
            </div>
          </div>
        </div>

        <section class="board" id="bulletinBoard" aria-label="Announcements and cancellations">
          <div class="board-header">
            <div class="board-title"><span class="badge-dot"></span> Bulletin Board</div>
            <div class="board-tools">
              <div class="board-actions" role="tablist" aria-label="Bulletin filters">
                <button class="chip active" data-filter="all">All</button>
                <button class="chip" data-filter="unread">Unread</button>
                <button class="chip" data-filter="cancel">Cancellations</button>
                <button class="chip" data-filter="announce">Announcements</button>
              </div>
              <button class="board-btn" id="btnMarkAll"><i class="ri-check-double-line"></i> Mark all</button>
              <button class="board-btn" id="btnRefresh"><i class="ri-refresh-line"></i></button>
            </div>
          </div>
          <ul class="board-list" id="boardList"></ul>
        </section>

        <div class="grid4">
          <div class="card"><i class="ri-book-2-line"></i>
            <div class="label">Subjects Enrolled</div>
            <div class="value"><?= htmlspecialchars((string) $totalSubjects) ?></div>
          </div>
          <div class="card"><i class="ri-mental-health-line"></i>
            <div class="label">Present Today</div>
            <div class="value"><?= htmlspecialchars((string) $presentToday) ?></div>
          </div>
          <div class="card"><i class="ri-calendar-event-line"></i>
            <div class="label">Today</div>
            <div class="value"><?= htmlspecialchars($todayShort) ?></div>
          </div>
          <div class="card"><i class="ri-notification-3-line"></i>
            <div class="label">Unread Notices</div>
            <div class="value" id="unreadCountCardValue"><?= htmlspecialchars((string) $notifUnread) ?></div>
          </div>
        </div>

        <h2 class="section-title">Today’s Schedule</h2>
        <div id="todayList" class="schedule-list"></div>

        <div class="panel" id="attendancePanel">
          <div class="panel-header">
            <h3>My Attendance</h3>
            <div class="chip chip-soft">Last 8 weeks • Weekly trend</div>
          </div>
          <div class="att-grid">
            <div class="att-line"><canvas id="lineWeekly" aria-label="Weekly attendance trend"></canvas></div>
            <div class="att-donut">
              <div style="font-size:13px;font-weight:600;margin-bottom:4px;">Overall Attendance</div>
              <canvas id="donut" aria-label="Overall attendance donut"></canvas>
              <div class="att-caption">Tap or hover points to inspect values.</div>
              <div class="att-legend-badges"><span class="att-badge">Present</span><span
                  class="att-badge">Absent</span><span class="att-badge">Late</span></div>
            </div>
          </div>
          <div class="section-title" style="margin-top:16px">Per Subject (last 30 days)</div>
          <div id="perSubject"></div>
        </div>

        <div class="panel">
          <h3 class="section-title" style="margin-top:0">My Grades</h3>
          <div id="gradeList">
            <div style="color:#9cb8ff;font-size:13px">Loading…</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header">
            <h3>Weekly Schedule</h3>
            <div class="chip">Tap a day to view</div>
          </div>
          <div class="tabs" id="weekTabs"></div>
          <div class="tab-content" id="weekContent">
            <div style="color:#cfe0f4;font-size:13px">Loading…</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header">
            <h3>Notifications (History)</h3>
            <div class="chip chip-soft">Includes today’s cancellations</div>
          </div>
          <div id="noticeList" style="margin-top:8px"></div>
        </div>
      </div>
    </main>
  </div>

  <div id="toast"></div>

  <script>
    function $(id) { return document.getElementById(id); }
    function toast(m) { var t = $('toast'); if (!t) return; t.textContent = m; t.style.top = '18px'; setTimeout(function () { t.style.top = '-120px'; }, 2300); }

    (function () {
      var btn = $('hamburger'), sb = $('sidebar'), closeBtn = $('closeSidebar');
      if (btn) { btn.addEventListener('click', function () { if (sb) sb.classList.toggle('open'); }); }
      if (closeBtn) { closeBtn.addEventListener('click', function () { if (sb) sb.classList.remove('open'); }); }
    })();

    (function () {
      var input = document.getElementById('studentPhotoInput');
      if (input) input.addEventListener('change', function () {
        if (this.files && this.files.length) { var f = document.getElementById('uploadStudentPhotoForm'); if (f) f.submit(); }
      });
    })();

    async function j(url, opts) {
      try {
        var r = await fetch(url, opts || { headers: { 'Accept': 'application/json' } });
        var txt = await r.text();
        try { return JSON.parse(txt); }
        catch (e) { console.error('Invalid JSON from', url, '→', txt); toast('Load error'); return { ok: 0 }; }
      } catch (e) { console.error('Network error', url, e); toast('Network error'); return { ok: 0 }; }
    }

    function escapeHtml(s) { s = s || ''; return s.replace(/[&<>"]/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]; }); }
    function countdown(hhmm) { var p = (hhmm || '').split(':'), h = parseInt(p[0] || '0', 10), m = parseInt(p[1] || '0', 10); var now = new Date(), start = new Date(); start.setHours(h, m, 0, 0); var d = Math.floor((start - now) / 1000); return d > 0 ? d : 0; }
    function classEnded(hhmm) { var p = (hhmm || '').split(':'), h = parseInt(p[0] || '0', 10), m = parseInt(p[1] || '0', 10); var now = new Date(), end = new Date(); end.setHours(h, m, 0, 0); return now >= end; }
    function runTimer(id, sec) { var el = $(id); if (!el) return; (function tick() { if (sec <= 0) { el.textContent = 'Ongoing'; return; } el.textContent = 'Starts in ' + formatCountdown(sec); sec--; setTimeout(tick, 1000); })(); }
    function formatCountdown(s) { var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), ss = s % 60; return h + 'h ' + m + 'm ' + ss + 's'; }
    function isSyntheticId(id) { return typeof id === 'string' && id.indexOf('cxl_') === 0; }

    var bulletinItems = [];
    var currentFilter = 'all';

    function getLocal(key, fallback) { try { var v = localStorage.getItem(key); return v ? JSON.parse(v) : fallback; } catch (e) { return fallback; } }
    function setLocal(key, val) { try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) { } }

    function isAckedLocally(id) { var map = getLocal('student_ack_local', {}); return !!map[id]; }
    function setAckLocal(id, val) { var map = getLocal('student_ack_local', {}); if (val) { map[id] = 1; } else { delete map[id]; } setLocal('student_ack_local', map); }
    function isSnoozed(id) { var map = getLocal('student_snooze', {}); var until = map[id]; return until && Date.now() < until; }
    function snoozeOneHour(id) { var map = getLocal('student_snooze', {}); map[id] = Date.now() + 60 * 60 * 1000; setLocal('student_snooze', map); }
    function togglePin(id) { var pins = getLocal('student_pins', {}); pins[id] = pins[id] ? 0 : 1; if (!pins[id]) delete pins[id]; setLocal('student_pins', pins); }
    function isPinned(id) { var pins = getLocal('student_pins', {}); return !!pins[id]; }

    function applyFilter(list) {
      return list.filter(function (it) {
        if (isSnoozed(it.id)) return false;
        if (currentFilter === 'unread') {
          var unreadDB = (String(it.is_read) === '0' || it.is_read === 0) && !isSyntheticId(it.id);
          var unreadSynth = isSyntheticId(it.id) && !isAckedLocally(it.id);
          return unreadDB || unreadSynth;
        }
        if (currentFilter === 'cancel') return it.type === 'cancel';
        if (currentFilter === 'announce') return it.type !== 'cancel';
        return true;
      });
    }

    function sortItems(list) {
      return list.slice().sort(function (a, b) {
        var ap = isPinned(a.id) ? 1 : 0, bp = isPinned(b.id) ? 1 : 0;
        if (ap !== bp) return bp - ap;
        var ac = a.type === 'cancel' ? 1 : 0, bc = b.type === 'cancel' ? 1 : 0;
        if (ac !== bc) return bc - ac;
        return (a.created_at < b.created_at) ? 1 : -1;
      });
    }

    function formatDateTime(s) {
      s = s || '';
      var d = new Date(s.replace(' ', 'T'));
      if (isNaN(d.getTime())) return s;
      return d.toLocaleString();
    }

    function renderBulletin() {
      var ul = $('boardList'); if (!ul) return;
      var filtered = sortItems(applyFilter(bulletinItems));
      if (filtered.length === 0) {
        ul.innerHTML = '<li class="board-item"><div class="left"><div class="title">No items</div><div class="meta">You are all caught up.</div></div></li>';
        updateUnreadCounts();
        return;
      }
      ul.innerHTML = filtered.map(function (it) {
        var synth = isSyntheticId(it.id);
        var unread = synth ? !isAckedLocally(it.id) : (String(it.is_read) === '0' || it.is_read === 0);
        var pin = isPinned(it.id);
        var cancel = it.type === 'cancel';
        var cls = ['board-item', unread ? 'unread' : '', cancel ? 'cancel' : '', pin ? 'pin-active' : ''].join(' ');
        return '' +
          '<li class="' + cls + '" id="board-' + escapeHtml(String(it.id)) + '">' +
          '<div class="left">' +
          '<div class="title">' + (cancel ? '<i class="ri-alert-line"></i> ' : '') + escapeHtml(it.title ? it.title : '') + '</div>' +
          '<div class="meta">' + escapeHtml(it.message ? it.message : '') + '</div>' +
          '<div class="meta">Posted: ' + escapeHtml(formatDateTime(it.created_at ? it.created_at : '')) + '</div>' +
          '</div>' +
          '<div class="actions">' +
          '<button class="ack" title="Acknowledge" onclick="ackOne(\'' + escapeHtml(String(it.id)) + '\')"><i class="ri-check-line"></i></button>' +
          '<button class="soft" title="Pin" onclick="pinOne(\'' + escapeHtml(String(it.id)) + '\')"><i class="ri-pushpin-2-line"></i></button>' +
          '<button class="soft" title="Snooze 1 hour" onclick="snoozeOne(\'' + escapeHtml(String(it.id)) + '\')"><i class="ri-time-line"></i></button>' +
          '</div>' +
          '</li>';
      }).join('');
      updateUnreadCounts();
    }

    async function loadBulletin() {
      var res = await j('?api=notifications');
      if (!res.ok) { toast('Unable to load bulletin'); return; }
      var arr = res.items || [];
      bulletinItems = arr.map(function (x) { x.type = x.type ? x.type : 'announce'; return x; });
      renderBulletin();
    }

    function setFilter(name) {
      currentFilter = name;
      var chips = document.querySelectorAll('.board .chip');
      chips.forEach(function (c) { c.classList.remove('active'); });
      var btn = null; chips.forEach(function (b) { if (b.getAttribute('data-filter') === name) btn = b; });
      if (btn) btn.classList.add('active');
      renderBulletin();
    }

    async function ackOne(id) {
      if (isSyntheticId(id)) {
        setAckLocal(id, true);
      } else {
        var fd = new FormData(); fd.append('id', id);
        var res = await j('?api=notice_ack', { method: 'POST', body: fd });
        if (!(res && res.ok)) toast('Failed to acknowledge');
      }
      renderBulletin();
      loadNotices();
    }

    async function markAll() {
      await j('?api=notice_ack_all');
      (bulletinItems || []).forEach(function (it) { if (isSyntheticId(it.id)) setAckLocal(it.id, true); });
      renderBulletin();
      loadNotices();
    }

    function pinOne(id) { togglePin(id); renderBulletin(); }
    function snoozeOne(id) { snoozeOneHour(id); renderBulletin(); }

    function loadToday() {
      j('?api=schedule_today').then(function (res) {
        var wrap = $('todayList'); if (!wrap) return;
        wrap.innerHTML = '';
        if (!res.ok) { wrap.innerHTML = '<div class="s-meta">Unable to load schedule.</div>'; return; }
        if (!res.items || res.items.length === 0) { wrap.innerHTML = '<div class="s-meta">No classes today.</div>'; return; }

        res.items.forEach(function (it) {
          var start = it.time_start, end = it.time_end, cd = countdown(start), ended = classEnded(end);
          var card = document.createElement('div'); card.className = 'schedule-card' + (parseInt(it.cancelled_today ? it.cancelled_today : 0) ? ' cancelled' : '');

          card.innerHTML =
            '<img class="fac-photo" src="' + (it.fac_photo || 'uploads/faculty/default.png') + '" alt="Faculty">' +
            '<div>' +
            '<div class="s-head">' +
            '<div class="s-name">' + escapeHtml(it.subj_name) + ' <span class="subject-pill">' + escapeHtml(it.subj_code) + '</span></div>' +
            '</div>' +
            '<div class="s-meta">' + escapeHtml(it.fac_name) + '</div>' +
            '</div>' +
            '<div class="s-right">' +
            '<div class="s-time">' + escapeHtml(start) + ' – ' + escapeHtml(end) + '</div>' +
            '<div class="s-count" id="cd-' + escapeHtml(String(it.subject_id)) + '">' +
            (parseInt(it.cancelled_today ? it.cancelled_today : 0) ? 'Class cancelled' : (ended ? 'Class ended' : (cd <= 0 ? 'Ongoing' : 'Starts in ' + formatCountdown(cd)))) +
            '</div>' +
            '</div>';
          if (parseInt(it.cancelled_today ? it.cancelled_today : 0)) {
            var mark = document.createElement('div'); mark.className = 'cancel-mark'; mark.textContent = 'Cancelled'; card.appendChild(mark);
          } else if (!ended && cd > 0) { runTimer('cd-' + it.subject_id, cd); }
          wrap.appendChild(card);
        });
      });
    }

    var DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    function loadWeek() {
      j('?api=schedule_week').then(function (res) {
        var tabs = $('weekTabs'), content = $('weekContent');
        if (!tabs || !content) return;
        tabs.innerHTML = '';
        if (!res.ok) { content.innerHTML = '<div class="s-meta">Unable to load weekly schedule.</div>'; return; }
        DAYS.forEach(function (d) {
          var b = document.createElement('button'); b.className = 'tab' + (d === res.today ? ' active' : ''); b.textContent = d;
          b.onclick = function () {
            selectDay(d, (res.schedule && res.schedule[d]) ? res.schedule[d] : []);
            var all = document.querySelectorAll('.tab'); all.forEach(function (x) { x.classList.remove('active'); }); b.classList.add('active');
          };
          tabs.appendChild(b);
        });
        selectDay(res.today, (res.schedule && res.schedule[res.today]) ? res.schedule[res.today] : []);
      });
    }
    function selectDay(day, items) {
      var c = $('weekContent'); if (!c) return;
      if (!items || items.length === 0) { c.innerHTML = '<div class="s-meta">No classes on ' + escapeHtml(day) + '.</div>'; return; }
      c.innerHTML = items.map(function (it) {
        return '' +
          '<div class="schedule-card">' +
          '<img class="fac-photo" src="' + (it.fac_photo || 'uploads/faculty/default.png') + '" alt="Faculty">' +
          '<div>' +
          '<div class="s-head">' +
          '<div class="s-name">' + escapeHtml(it.subj_name) + ' <span class="subject-pill">' + escapeHtml(it.subj_code) + '</span></div>' +
          '</div>' +
          '<div class="s-meta">' + escapeHtml(it.fac_name) + '</div>' +
          '</div>' +
          '<div class="s-right">' +
          '<div class="s-time">' + escapeHtml(day) + ' • ' + escapeHtml(it.time_start) + ' – ' + escapeHtml(it.time_end) + '</div>' +
          '</div>' +
          '</div>';
      }).join('');
    }

    var donutChart, weeklyChart;
    function loadAttendance() {
      j('?api=attendance_summary').then(function (res) {
        if (!res.ok) {
          var p = $('perSubject'); if (p) p.innerHTML = '<div class="s-meta">Unable to load attendance.</div>'; return;
        }
        var d = res.summary || { Present: 0, Absent: 0, Late: 0 };
        var weeklyLabels = res.weekly_labels || [];
        var weeklySeries = res.weekly_series || [];

        var donutCtx = document.getElementById('donut').getContext('2d');
        if (donutChart) donutChart.destroy();
        donutChart = new Chart(donutCtx, {
          type: 'doughnut',
          data: { labels: ['Present', 'Absent', 'Late'], datasets: [{ data: [d.Present || 0, d.Absent || 0, d.Late || 0], backgroundColor: ['#22c55e', '#ef4444', '#f59e0b'], borderWidth: 0 }] },
          options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { display: false } }, animation: { animateScale: true, duration: 800, easing: 'easeOutQuad' } }
        });

        var lineCtx = document.getElementById('lineWeekly').getContext('2d');
        if (weeklyChart) weeklyChart.destroy();
        weeklyChart = new Chart(lineCtx, {
          type: 'line',
          data: { labels: weeklyLabels, datasets: [{ label: 'Presents per week', data: weeklySeries, borderColor: '#8ad0ff', backgroundColor: 'rgba(138,208,255,.18)', tension: .35, fill: true, pointRadius: 3, pointHoverRadius: 5 }] },
          options: { responsive: true, maintainAspectRatio: false, animation: { duration: 900, easing: 'easeOutCubic' }, plugins: { legend: { labels: { color: '#e6f1ff', font: { size: 11 } } } }, scales: { x: { ticks: { color: '#cfe0f4', font: { size: 10 } }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: '#cfe0f4', font: { size: 10 }, precision: 0 }, grid: { color: 'rgba(255,255,255,.08)' } } } }
        });

        var ps = res.per_subject || {};
        var wrap = $('perSubject'); if (!wrap) return;
        var out = '';
        var keys = Object.keys(ps);
        if (keys.length === 0) { out = '<div class="s-meta">No attendance yet.</div>'; }
        else {
          out = '<div style="overflow:auto"><table><tr><th>Subject</th><th>Present</th><th>Absent</th><th>Late</th></tr>';
          keys.forEach(function (k) {
            var r = ps[k];
            var pr = r && r.Present ? r.Present : 0;
            var ab = r && r.Absent ? r.Absent : 0;
            var lt = r && r.Late ? r.Late : 0;
            out += '<tr>' +
              '<td>' + escapeHtml(k) + '</td>' +
              '<td style="text-align:center;color:#22c55e;font-weight:600">' + pr + '</td>' +
              '<td style="text-align:center;color:#ef4444;font-weight:600">' + ab + '</td>' +
              '<td style="text-align:center;color:#f59e0b;font-weight:600">' + lt + '</td>' +
              '</tr>';
          });
          out += '</table></div>';
        }
        wrap.innerHTML = out;
      });
    }

    function computeUnreadCount(items) {
      var count = 0;
      (items || []).forEach(function (it) {
        if (isSnoozed(it.id)) return;
        if (isSyntheticId(it.id)) { if (!isAckedLocally(it.id)) count++; }
        else { if (String(it.is_read) === '0' || it.is_read === 0) count++; }
      });
      return count;
    }
    function updateUnreadCounts() {
      var badge = $('notifCount'); var card = $('unreadCountCardValue');
      var unread = computeUnreadCount(bulletinItems);
      if (badge) badge.textContent = String(unread);
      if (card) card.textContent = String(unread);
    }
    function loadNotices() {
      j('?api=notifications').then(function (res) {
        var c = $('noticeList'); if (!c) return;
        if (!res.ok) { c.innerHTML = '<div class="s-meta">Unable to load notifications.</div>'; return; }
        var items = res.items || [];
        bulletinItems = items.map(function (x) { x.type = x.type ? x.type : 'announce'; return x; });
        updateUnreadCounts();
        if (items.length === 0) { c.innerHTML = '<div class="s-meta">You are all caught up. No notifications.</div>'; return; }
        c.innerHTML = items.slice(0, 30).map(function (n) {
          var isCancel = (n.type === 'cancel');
          return '' +
            '<div class="notice ' + (isCancel ? 'notice-cancel' : '') + '">' +
            '<div class="notice-title">' + (isCancel ? '<i class="ri-alert-line"></i> ' : '') + escapeHtml(n.title ? n.title : '') + '</div>' +
            '<div class="notice-meta">' + escapeHtml(formatDateTime(n.created_at ? n.created_at : '')) + '</div>' +
            '<div>' + escapeHtml(n.message ? n.message : '') + '</div>' +
            '</div>';
        }).join('');
      });
    }

    function loadGrades() {
      j('?api=student_grades').then(function (res) {
        var c = $('gradeList'); if (!c) return;
        if (!res.ok) { c.innerHTML = '<div class="s-meta">Unable to load grades.</div>'; return; }
        var items = res.items || [];
        if (items.length === 0) { c.innerHTML = '<div class="s-meta">No grade records yet.</div>'; return; }

        var html = '' +
          '<div class="table-container">' +
          '<table>' +
          '<thead>' +
          '<tr>' +
          '<th style="text-align:left">Subject</th>' +
          '<th>Midterm</th>' +
          '<th>Final</th>' +
          '<th>Final Grade</th>' +
          '</tr>' +
          '</thead>' +
          '<tbody>';

        items.forEach(function (it) {
          var mid = (it.midterm !== null && it.midterm !== undefined) ? parseFloat(it.midterm) : null;
          var fin = (it.final !== null && it.final !== undefined) ? parseFloat(it.final) : null;
          var ov = (it.overall !== null && it.overall !== undefined) ? parseFloat(it.overall) : null;

          var midTxt = mid !== null && !isNaN(mid) ? mid.toFixed(2) : '—';
          var finTxt = fin !== null && !isNaN(fin) ? fin.toFixed(2) : '—';
          var ovTxt = ov !== null && !isNaN(ov) ? ov.toFixed(2) : '—';

          var passed = ov !== null && !isNaN(ov) ? (ov >= 75) : false;
          var cls = passed ? 'grade-pass' : 'grade-fail';

          html += '' +
            '<tr>' +
            '<td style="text-align:left">' + escapeHtml(it.subject_name ? it.subject_name : '') + '</td>' +
            '<td>' + midTxt + '</td>' +
            '<td>' + finTxt + '</td>' +
            '<td class="' + (ovTxt === '—' ? '' : cls) + '">' + ovTxt + '</td>' +
            '</tr>';
        });

        html += '</tbody></table></div>';
        c.innerHTML = html;
      });
    }

    $('openWeekly').onclick = function (e) { e.preventDefault(); var el = document.querySelector('#weekTabs'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    $('openAttendance').onclick = function (e) { e.preventDefault(); var el = document.querySelector('#attendancePanel'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    $('openNotifications').onclick = function (e) { e.preventDefault(); var el = document.querySelector('#bulletinBoard'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); };

    document.addEventListener('click', function (ev) {
      var f = ev.target.closest('.board .chip');
      if (f && f.dataset.filter) { setFilter(f.dataset.filter); }
    });
    (function () {
      var mark = $('btnMarkAll'); if (mark) mark.addEventListener('click', markAll);
      var ref = $('btnRefresh'); if (ref) ref.addEventListener('click', loadBulletin);
    })();

    document.addEventListener('DOMContentLoaded', function () {
      function refreshAll() {

        if (document.hidden) return;

        loadBulletin();
        loadToday();
        loadAttendance();
        loadWeek();
        loadNotices();
        loadGrades();
      }

      refreshAll();

      setInterval(refreshAll, 10000);

      if (!sessionStorage.getItem('scrolledBoard')) {
        var el = $('bulletinBoard');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        sessionStorage.setItem('scrolledBoard', '1');
      }
    });

  </script>
</body>

</html>