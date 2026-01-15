<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
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

function sql($conn, $query, $types = "", $params = array()) {
    $stmt = $conn->prepare($query);
    if ($stmt === false) { throw new Exception("SQL prepare failed"); }
    if ($types !== "" && !empty($params)) {
        $refs = array();
        foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
        array_unshift($refs, $types);
        call_user_func_array(array($stmt, 'bind_param'), $refs);
    }
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : array();
    $stmt->close();
    return $rows;
}
function sql_one($conn, $query, $types = "", $params = array()) {
    $rows = sql($conn, $query, $types, $params);
    return $rows ? $rows[0] : null;
}
function json_api($payload, $code = 200) {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}
function faculty_owns_subject_php($conn, $fid, $subject_id) {
    $row = sql_one($conn,
        "SELECT 1 AS ok FROM assignments WHERE faculty_id=? AND subject_id=? LIMIT 1",
        "ii", array($fid, (int)$subject_id)
    );
    return $row ? true : false;
}

/* user + faculty */
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$user    = sql_one($conn, "SELECT * FROM users WHERE id=?", "i", array($user_id));
if (!$user) { die("User not found."); }
$faculty = sql_one($conn, "SELECT * FROM faculty WHERE email=? LIMIT 1", "s", array($user['email']));
if (!$faculty) { die("Faculty record not found."); }

$fid       = (int)$faculty['id'];
$fname     = $faculty['name'];
$photoFile = !empty($faculty['photo']) ? $faculty['photo'] : "default.png";
$photoPath = "uploads/faculty/" . $photoFile;

/* Photo upload */
if (isset($_POST['upload_faculty_photo'])) {
    if (isset($_FILES['faculty_photo']['name']) && strlen($_FILES['faculty_photo']['name']) > 0) {
        if ($_FILES['faculty_photo']['size'] <= 3 * 1024 * 1024) {
            $safeBase = preg_replace("/[^A-Za-z0-9_\.-]/", "_", basename($_FILES['faculty_photo']['name']));
            $newName  = time() . "_" . $safeBase;
            $target   = "uploads/faculty/" . $newName;
            if (is_uploaded_file($_FILES['faculty_photo']['tmp_name']) &&
                move_uploaded_file($_FILES['faculty_photo']['tmp_name'], $target)) {
                sql($conn, "UPDATE faculty SET photo=? WHERE id=?", "si", array($newName, $fid));
            }
        }
    }
    header("Location: " . basename(__FILE__));
    exit();
}

/* -------- API (Two-term only: midterm + final) -------- */
if (isset($_GET['api'])) {
    try {
        $api = $_GET['api'];

        if ($api === 'classlist') {
            $sid       = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
            $searchRaw = isset($_GET['search']) ? $_GET['search'] : '';
            $like      = "%".$searchRaw."%";
            $rows = sql($conn,
                "SELECT 
                     e.student_id AS id, 
                     s.name, 
                     s.student_id, 
                     s.course, 
                     s.year_level,
                     at.status AS status_today
                 FROM enrollments e
                 JOIN students s    ON e.student_id = s.id
                 JOIN assignments a ON e.subject_id = a.subject_id
                 LEFT JOIN attendance at 
                    ON at.subject_id = e.subject_id 
                   AND at.student_id = s.id 
                   AND at.`date` = CURDATE()
                 WHERE e.subject_id=? AND a.faculty_id=? AND s.name LIKE ?
                 ORDER BY s.name ASC",
                "iis", array($sid, $fid, $like)
            );
            json_api(array('ok'=>1,'list'=>$rows));
        }

        if ($api === 'class_record_load') {
            $sid = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
            if (!faculty_owns_subject_php($conn, $fid, $sid)) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);
            $rows = sql($conn,
                "SELECT s.id, s.name, s.student_id,
                        cr.midterm_grade, cr.final_grade, cr.final_overall
                 FROM enrollments e
                 JOIN students s ON s.id = e.student_id
                 LEFT JOIN class_record cr 
                    ON cr.subject_id = e.subject_id AND cr.student_id = s.id
                 WHERE e.subject_id = ?
                 ORDER BY s.name ASC",
                "i", array($sid)
            );
            json_api(array('ok'=>1, 'list'=>$rows));
        }

        if ($api === 'class_record_save') {
            $subject = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
            $student = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $column  = isset($_POST['column']) ? $_POST['column'] : '';
            $value   = isset($_POST['value']) ? $_POST['value'] : null;

            if (!faculty_owns_subject_php($conn, $fid, $subject)) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);

            $allowed = array('midterm','final');
            if (!in_array($column, $allowed, true)) json_api(array('ok'=>0,'error'=>'Invalid column'),422);

            $col = $column . '_grade';

            if ($value === '' || $value === null) {
                $value = null;
            } else {
                if (!is_numeric($value)) json_api(array('ok'=>0,'error'=>'Invalid number'),422);
                $value = round((float)$value, 2);
                if ($value < 0 || $value > 100) json_api(array('ok'=>0,'error'=>'Out of range'),422);
            }

            sql($conn,
                "INSERT INTO class_record (subject_id, student_id, $col)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE $col = VALUES($col)",
                "iid", array($subject, $student, $value)
            );

            $cr = sql_one($conn,
                "SELECT midterm_grade, final_grade
                 FROM class_record
                 WHERE subject_id=? AND student_id=?",
                "ii", array($subject, $student)
            );
            $mid = isset($cr['midterm_grade']) ? (float)$cr['midterm_grade'] : null;
            $fin = isset($cr['final_grade'])   ? (float)$cr['final_grade']   : null;

            $w = sql_one($conn,
                "SELECT midterm_weight, final_weight
                 FROM grading_weights WHERE subject_id=?",
                "i", array($subject)
            );
            $midW = $w && isset($w['midterm_weight']) ? (int)$w['midterm_weight'] : 50;
            $finW = $w && isset($w['final_weight'])   ? (int)$w['final_weight']   : 50;

            $final_overall = null;
            if ($mid !== null && $fin !== null) {
                $sumW = $midW + $finW;
                if ($sumW <= 0) $sumW = 100;
                $final_overall = round(($mid*$midW + $fin*$finW) / $sumW, 2);
            }

            sql($conn,
                "UPDATE class_record
                 SET final_overall = ?
                 WHERE subject_id=? AND student_id=?",
                "dii", array($final_overall, $subject, $student)
            );

            json_api(array('ok'=>1, 'final_overall'=>$final_overall));
        }

        if ($api === 'weights_get') {
            $sid = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
            if (!faculty_owns_subject_php($conn, $fid, $sid)) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);
            $w = sql_one($conn,
                "SELECT midterm_weight, final_weight
                 FROM grading_weights WHERE subject_id=?",
                "i", array($sid)
            );
            if (!$w) $w = array('midterm_weight'=>50,'final_weight'=>50);
            json_api(array('ok'=>1,'weights'=>$w));
        }

        if ($api === 'weights_save') {
            $sid = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
            $mid = isset($_POST['midterm']) ? (int)$_POST['midterm'] : 50;
            $fin = isset($_POST['final']) ? (int)$_POST['final'] : 50;

            if (!faculty_owns_subject_php($conn, $fid, $sid)) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);
            if ($mid < 0 || $fin < 0 || ($mid+$fin) !== 100) {
                json_api(array('ok'=>0,'error'=>'Weights must be non-negative and sum to 100'),422);
            }

            sql($conn,
                "INSERT INTO grading_weights (subject_id, midterm_weight, final_weight)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE
                   midterm_weight=VALUES(midterm_weight),
                   final_weight=VALUES(final_weight)",
                "iii", array($sid,$mid,$fin)
            );
            json_api(array('ok'=>1));
        }

        if ($api === 'quiz_list') {
            $sid = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
            if (!faculty_owns_subject_php($conn, $fid, $sid)) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);
            $rows = sql($conn,
                "SELECT id, subject_id, quiz_name, term, max_score
                 FROM quiz_items
                 WHERE subject_id=?
                 ORDER BY id ASC",
                "i", array($sid)
            );
            json_api(array('ok'=>1, 'items'=>$rows));
        }

        if ($api === 'quiz_add') {
            $sid = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
            $name = isset($_POST['quiz_name']) ? trim($_POST['quiz_name']) : '';
            $term = isset($_POST['term']) ? strtolower(trim($_POST['term'])) : 'midterm';
            $max  = isset($_POST['max_score']) ? (int)$_POST['max_score'] : 100;

            if (!faculty_owns_subject_php($conn, $fid, $sid)) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);
            if (strlen($name) < 2) json_api(array('ok'=>0,'error'=>'Name too short'),422);
            if (!in_array($term, array('midterm','final'), true)) json_api(array('ok'=>0,'error'=>'Invalid term'),422);
            if ($max < 1 || $max > 1000) json_api(array('ok'=>0,'error'=>'Invalid max score'),422);

            sql($conn,
                "INSERT INTO quiz_items (subject_id, quiz_name, term, max_score)
                 VALUES (?,?,?,?)",
                "issi", array($sid, $name, $term, $max)
            );
            json_api(array('ok'=>1));
        }

        if ($api === 'quiz_delete') {
            $qid = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;
            $item = sql_one($conn, "SELECT subject_id FROM quiz_items WHERE id=?", "i", array($qid));
            if (!$item) json_api(array('ok'=>0,'error'=>'Quiz not found'),404);
            if (!faculty_owns_subject_php($conn, $fid, (int)$item['subject_id'])) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);

            sql($conn, "DELETE FROM quiz_scores WHERE quiz_id=?", "i", array($qid));
            sql($conn, "DELETE FROM quiz_items  WHERE id=?", "i", array($qid));
            json_api(array('ok'=>1));
        }

        if ($api === 'quiz_scores') {
            $qid = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
            $qi = sql_one($conn, "SELECT id, subject_id, max_score FROM quiz_items WHERE id=?", "i", array($qid));
            if (!$qi) json_api(array('ok'=>0,'error'=>'Quiz not found'),404);
            if (!faculty_owns_subject_php($conn, $fid, (int)$qi['subject_id'])) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);

            $rows = sql($conn,
                "SELECT s.id, s.name, s.student_id, qs.score
                 FROM enrollments e
                 JOIN students s ON s.id = e.student_id
                 LEFT JOIN quiz_scores qs ON qs.quiz_id=? AND qs.student_id=s.id
                 WHERE e.subject_id=?
                 ORDER BY s.name ASC",
                "ii", array($qid, (int)$qi['subject_id'])
            );

            json_api(array('ok'=>1, 'items'=>$rows, 'max_score'=>(int)$qi['max_score']));
        }

        if ($api === 'quiz_save_score') {
            $qid   = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0;
            $sid   = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $score = isset($_POST['score']) ? trim($_POST['score']) : '';

            $qi = sql_one($conn, "SELECT id, subject_id, max_score FROM quiz_items WHERE id=?", "i", array($qid));
            if (!$qi) json_api(array('ok'=>0,'error'=>'Quiz not found'),404);
            if (!faculty_owns_subject_php($conn, $fid, (int)$qi['subject_id'])) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);

            if ($score === '') {
                $score = null;
            } else {
                if (!is_numeric($score)) json_api(array('ok'=>0,'error'=>'Invalid score'),422);
                $score = (int)$score;
                if ($score < 0 || $score > (int)$qi['max_score']) json_api(array('ok'=>0,'error'=>'Score out of range'),422);
            }

            sql($conn,
                "INSERT INTO quiz_scores (quiz_id, student_id, score)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE score=VALUES(score)",
                "iii", array($qid, $sid, $score)
            );
            json_api(array('ok'=>1));
        }

        if ($api === 'compute_terms') {
            $subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
            if (!faculty_owns_subject_php($conn, $fid, $subject_id)) json_api(array('ok'=>0,'error'=>'Unauthorized'),403);

            $students = sql($conn,
                "SELECT s.id, s.name, s.student_id
                 FROM enrollments e
                 JOIN students s ON s.id = e.student_id
                 WHERE e.subject_id=?
                 ORDER BY s.name ASC",
                "i", array($subject_id)
            );

            $quizzes = sql($conn,
                "SELECT id, term, max_score FROM quiz_items WHERE subject_id=?",
                "i", array($subject_id)
            );
            $by_term = array('midterm'=>array(), 'final'=>array());
            foreach ($quizzes as $q) {
                $t = $q['term'];
                if ($t !== 'midterm' && $t !== 'final') { continue; }
                if (!isset($by_term[$t])) $by_term[$t] = array();
                $by_term[$t][] = $q;
            }

            $quiz_ids = array();
            foreach ($quizzes as $q) {
                if ($q['term']==='midterm' || $q['term']==='final') $quiz_ids[] = (int)$q['id'];
            }

            $scores = array();
            if (!empty($quiz_ids)) {
                $in = implode(',', array_fill(0, count($quiz_ids), '?'));
                $types = str_repeat('i', count($quiz_ids));
                $params = $quiz_ids;
                $rows = sql($conn,
                    "SELECT quiz_id, student_id, score FROM quiz_scores WHERE quiz_id IN ($in)",
                    $types, $params
                );
                foreach ($rows as $r) {
                    $qid = (int)$r['quiz_id'];
                    $sid = (int)$r['student_id'];
                    if (!isset($scores[$qid])) $scores[$qid] = array();
                    $scores[$qid][$sid] = is_null($r['score']) ? null : (int)$r['score'];
                }
            }

            $out = array();
            foreach ($students as $st) {
                $sid = (int)$st['id'];
                $computed = array('midterm'=>null,'final'=>null);

                foreach (array('midterm','final') as $term) {
                    if (empty($by_term[$term])) { $computed[$term] = null; continue; }
                    $percents = array();
                    foreach ($by_term[$term] as $q) {
                        $qid = (int)$q['id'];
                        $max = (int)$q['max_score'];
                        if ($max <= 0) continue;
                        $sc = (isset($scores[$qid]) && isset($scores[$qid][$sid])) ? $scores[$qid][$sid] : null;
                        if ($sc === null) continue;
                        $percents[] = ($sc / $max) * 100.0;
                    }
                    if (!empty($percents)) {
                        $sum = 0.0; foreach ($percents as $p) { $sum += $p; }
                        $avg = $sum / count($percents);
                        $computed[$term] = round($avg, 2);
                    } else {
                        $computed[$term] = null;
                    }
                }

                $out[] = array(
                    'student_id' => $sid,
                    'name'       => $st['name'],
                    'student_no' => $st['student_id'],
                    'midterm_computed' => $computed['midterm'],
                    'final_computed'   => $computed['final'],
                );
            }

            json_api(array('ok'=>1,'rows'=>$out));
        }

        if ($api === 'save_att') {
            $sid    = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
            $st     = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $status = isset($_POST['status']) ? $_POST['status'] : '';
            $date   = date("Y-m-d");
            if ($sid>0 && $st>0 && ($status==='Present'||$status==='Absent'||$status==='Late')) {
                sql($conn, "DELETE FROM attendance WHERE subject_id=? AND student_id=? AND `date`=?",
                    "iis", array($sid,$st,$date));
                sql($conn, "INSERT INTO attendance (subject_id,student_id,`date`,status) VALUES (?,?,?,?)",
                    "iiss", array($sid,$st,$date,$status));
                json_api(array('ok'=>1,'status'=>$status));
            }
            json_api(array('ok'=>0,'error'=>'Invalid params'),422);
        }

        if ($api === 'cancel_class') {
            $subjectID = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
            $reasonRaw = isset($_POST['reason']) ? trim($_POST['reason']) : '';
            if (!($subjectID>0 && strlen($reasonRaw)>=3)) json_api(array('ok'=>0,'error'=>'Invalid subject or reason'),422);

            $info = sql_one($conn,
                "SELECT a.*, s.name AS subj_name, s.code AS subj_code
                 FROM assignments a JOIN subjects s ON a.subject_id = s.id
                 WHERE a.subject_id=? AND a.faculty_id=? LIMIT 1",
                "ii", array($subjectID,$fid)
            );
            if (!$info) json_api(array('ok'=>0,'error'=>'Class not found for this faculty'),404);

            $subjectCode = $info['subj_code'];
            $subjectName = $info['subj_name'] ? $info['subj_name'] : $subjectCode;
            $yearLevel   = isset($info['year_level']) ? $info['year_level'] : '';
            $course      = isset($info['course']) && $info['course']!=='' ? $info['course'] : 'N/A';

            $exists = sql_one($conn,
                "SELECT id FROM cancellations
                 WHERE subject_code=? AND faculty_id=? AND DATE(`date`)=CURDATE() LIMIT 1",
                "si", array($subjectCode,$fid)
            );
            if ($exists) json_api(array('ok'=>0,'error'=>'You already cancelled this class today.'),409);

            sql($conn,
                "INSERT INTO cancellations (faculty_id, faculty_name, subject_code, year_level, course, reason)
                 VALUES (?,?,?,?,?,?)",
                "isssss", array($fid,$fname,$subjectCode,$yearLevel,$course,$reasonRaw)
            );

            $students = sql($conn,
                "SELECT DISTINCT e.student_id AS sid
                 FROM enrollments e WHERE e.subject_id=?",
                "i", array((int)$info['subject_id'])
            );
            if ($students) {
                $title = 'Class Cancelled';
                foreach ($students as $sr) {
                    $sidInt = (int)$sr['sid'];
                    $msg = 'Your class for '.$subjectName.' has been cancelled. Reason: '.$reasonRaw;
                    sql($conn,
                        "INSERT INTO notifications (user_type,user_id,title,message)
                         VALUES ('student',?,?,?)","iss",
                        array($sidInt,$title,$msg)
                    );
                }
            }
            json_api(array('ok'=>1,'subject_name'=>$subjectName,'reason'=>$reasonRaw));
        }

        if ($api === 'profile') {
            $sid = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
            $row = sql_one($conn, "SELECT * FROM students WHERE id=?", "i", array($sid));
            json_api(array('ok'=>1,'profile'=>$row ? $row : array()));
        }

        if ($api === 'profile_attendance') {
            $sid = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
            $sub = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
            $rows = sql($conn,
                "SELECT `date`, status
                 FROM attendance
                 WHERE student_id=? AND subject_id=?
                 ORDER BY `date` ASC",
                "ii", array($sid,$sub)
            );
            json_api(array('ok'=>1,'rows'=>$rows));
        }

        if ($api === 'schedule_week') {
            $rows = sql($conn,
                "SELECT a.*, s.name AS subject_name, s.code AS subject_code
                 FROM assignments a JOIN subjects s ON a.subject_id = s.id
                 WHERE a.faculty_id=?
                 ORDER BY a.time_start","i",array($fid)
            );
            $days = array('Mon','Tue','Wed','Thu','Fri','Sat','Sun');
            $out  = array(); foreach ($days as $d) { $out[$d]=array(); }
            foreach ($rows as $r) {
                $pack = array(
                    'subject_id'   => (int)$r['subject_id'],
                    'subject_name' => $r['subject_name'],
                    'subject_code' => $r['subject_code'],
                    'room'         => isset($r['room']) ? $r['room'] : '',
                    'time_start'   => substr($r['time_start'],0,5),
                    'time_end'     => substr($r['time_end'],0,5)
                );
                foreach ($days as $d) { if (strpos($r['days'],$d)!==false) $out[$d][]=$pack; }
            }
            json_api(array('ok'=>1,'schedule'=>$out));
        }

        if ($api === 'schedule_today') {
            $todayShort = date("D");
            $rows = sql($conn,
                "SELECT a.*, s.name AS subject_name, s.code AS subject_code
                 FROM assignments a JOIN subjects s ON a.subject_id = s.id
                 WHERE a.faculty_id=? AND a.days LIKE CONCAT('%', ?, '%')
                 ORDER BY a.time_start",
                "is", array($fid,$todayShort)
            );
            json_api(array('ok'=>1,'items'=>$rows,'todayShort'=>$todayShort));
        }

        if ($api === 'cancellations') {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 150;
            if ($limit<1) $limit=1; if ($limit>500) $limit=500;
            $rows = sql($conn,
                "SELECT id, subject_code, year_level, course, reason, `date`
                 FROM cancellations
                 WHERE faculty_id=?
                 ORDER BY `date` DESC
                 LIMIT ?",
                 "ii", array($fid,$limit)
            );
            json_api(array('ok'=>1,'items'=>$rows));
        }

        if ($api === 'attendance_sheet') {
            $sub = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
            $rows = sql($conn,
                "SELECT at.`date`, s.name AS student_name, s.student_id, at.status
                 FROM attendance at
                 JOIN students s ON s.id = at.student_id
                 WHERE at.subject_id=? AND at.`date`>=DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY at.`date` DESC, s.name ASC",
                "i", array($sub)
            );
            json_api(array('ok'=>1,'rows'=>$rows));
        }

        json_api(array('ok'=>0,'error'=>'Unknown API'),404);
    } catch (Throwable $e) {
        json_api(array('ok'=>0,'error'=>'Server error (API)'),500);
    }
}

/* dashboard aggregates */
$today      = date("Y-m-d");
$todayShort = date("D");

$row = sql_one($conn,"SELECT COUNT(*) AS c FROM assignments WHERE faculty_id=?","i",array($fid));
$totalSubjects = $row ? (int)$row['c'] : 0;

$row = sql_one($conn,
    "SELECT COUNT(DISTINCT e.student_id) AS c
     FROM enrollments e JOIN assignments a ON e.subject_id = a.subject_id
     WHERE a.faculty_id=?","i",array($fid)
);
$totalStudents = $row ? (int)$row['c'] : 0;

$row = sql_one($conn,
    "SELECT COUNT(*) AS c FROM assignments
     WHERE faculty_id=? AND days LIKE CONCAT('%', ?, '%')",
    "is", array($fid,$todayShort)
);
$todayClasses = $row ? (int)$row['c'] : 0;

$row = sql_one($conn,
    "SELECT COUNT(*) AS c
     FROM attendance at
     JOIN assignments a ON at.subject_id = a.subject_id
     WHERE a.faculty_id=? AND at.`date`=?",
    "is", array($fid,$today)
);
$attMarkedToday = $row ? (int)$row['c'] : 0;

$row = sql_one($conn,
    "SELECT COUNT(DISTINCT at.subject_id) AS c
     FROM attendance at
     JOIN assignments a ON at.subject_id=a.subject_id
     WHERE a.faculty_id=? AND at.`date`=?",
    "is", array($fid,$today)
);
$markedSubjectsToday = $row ? (int)$row['c'] : 0;
$unmarkedToday = $todayClasses - $markedSubjectsToday;
if ($unmarkedToday < 0) $unmarkedToday = 0;

$stats = sql($conn,
    "SELECT at.status, COUNT(*) AS c
     FROM attendance at
     JOIN assignments a ON at.subject_id=a.subject_id
     WHERE a.faculty_id=? AND at.`date`>=DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY at.status",
    "i", array($fid)
);
$present=0;$absent=0;$late=0;
foreach ($stats as $r) {
    if ($r['status']==='Present') $present=(int)$r['c'];
    elseif ($r['status']==='Absent') $absent=(int)$r['c'];
    elseif ($r['status']==='Late') $late=(int)$r['c'];
}
$totalForRate = $present+$absent+$late;
$onTimeRate = $totalForRate>0 ? round(($present/$totalForRate)*100) : 0;

$labels7 = array(); $data7 = array();
for ($i=6;$i>=0;$i--){
    $d = date("Y-m-d", strtotime("-$i day"));
    $labels7[] = date("M j", strtotime($d));
    $row = sql_one($conn,
        "SELECT COUNT(*) AS c
         FROM attendance at
         JOIN assignments a ON at.subject_id=a.subject_id
         WHERE a.faculty_id=? AND at.status='Present' AND at.`date`=?",
        "is", array($fid,$d)
    );
    $data7[] = $row ? (int)$row['c'] : 0;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Faculty Dashboard | CLASSIFY</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{
  --bg-gradient: linear-gradient(135deg,#0f2027,#203a43,#2c5364);
  --glass-bg: rgba(255,255,255,0.07);
  --accent-primary: #00c6ff;
  --accent-secondary: #0072ff;
  --danger: #e63946;
  --text-main: #ffffff;
  --text-muted: #d0d7e2;
  --card-shadow: 0 4px 15px rgba(0,0,0,0.25);
  --radius-lg: 15px;
  --radius-xl: 20px;
}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
body{font-family:'Poppins',sans-serif;background:var(--bg-gradient);color:var(--text-main);display:flex;overflow-x:hidden;}

.header-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;}
.header-avatar{display:flex;align-items:center;gap:12px;}
.avatar{position:relative;width:64px;height:64px;border-radius:50%;cursor:pointer;}
.avatar img{width:100%;height:100%;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.55);box-shadow:var(--card-shadow);}
.avatar .edit-badge{position:absolute;right:-4px;bottom:-4px;width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:12px;color:#fff;background:linear-gradient(135deg,var(--accent-secondary),var(--accent-primary));border:2px solid rgba(0,0,0,0.25);}
@media (max-width:600px){ .header-bar{flex-direction:column;align-items:flex-start;gap:10px;} }

.sidebar{width:260px;background:var(--glass-bg);backdrop-filter:blur(25px);display:flex;flex-direction:column;padding:25px 15px;z-index:3;position:relative;border-right:1px solid rgba(255,255,255,0.15);flex-shrink:0;}
.sidebar h2{text-align:center;margin-bottom:40px;font-weight:700;color:#fff;}
.sidebar a{display:flex;align-items:center;gap:10px;padding:12px 15px;margin:6px 0;color:white;text-decoration:none;border-radius:10px;transition:background .3s,transform .3s;font-size:0.95rem;}
.sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,0.25);transform:translateX(4px);}

.main{flex:1;padding:20px 20px 30px;overflow-y:auto;position:relative;z-index:2;scroll-behavior:smooth;}
.mobile-toggle{display:none;border:none;background:linear-gradient(135deg,var(--accent-secondary),var(--accent-primary));color:#fff;padding:8px 12px;border-radius:999px;cursor:pointer;margin-bottom:15px;align-items:center;gap:6px;box-shadow:var(--card-shadow);}
@media (max-width:900px){
  body{flex-direction:column;}
  .sidebar{position:fixed;top:0;left:-260px;height:100vh;z-index:30;transition:left .3s ease;}
  .sidebar.open{left:0;}
  .main{padding:15px 14px 30px;margin-left:0;}
  .mobile-toggle{display:inline-flex;}
}

.section{animation:fadeIn .6s ease;margin-bottom:35px;}
.section p{color:var(--text-muted);margin-bottom:18px;}

.btn{background:linear-gradient(135deg,var(--accent-secondary),var(--accent-primary));border:none;padding:10px 18px;color:white;border-radius:8px;cursor:pointer;transition:.25s;font-size:0.9rem;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
.btn:hover{transform:translateY(-1px) scale(1.03);box-shadow:0 4px 12px rgba(0,0,0,0.35);}
.btn.delete-btn{background:var(--danger);}
.btn.cancel{background:linear-gradient(135deg,#ff4e50,#f9d423);}
/* Record-like primary action buttons */
.btn--focus{
  background: linear-gradient(135deg, var(--accent-secondary), var(--accent-primary));
  border: 1px solid rgba(255,255,255,0.28);
  padding: 12px 18px;
  font-weight: 700;
  font-size: 0.95rem;
  border-radius: 12px;
  letter-spacing: .2px;
  box-shadow: 0 8px 18px rgba(0,0,0,.35);
}
.actions-group .btn--focus{ height:44px; }
.actions-group .btn--focus i{ font-size:18px; }

/* Give Actions column more breathing room on wide screens */
@media (min-width: 1100px){
  #subjectsTable th:last-child,
  #subjectsTable td.actions-cell { width: 720px; }
}

.table-actions{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 15px;}
.table-container{width:100%;overflow-x:auto;border-radius:12px;background:rgba(255,255,255,0.05);backdrop-filter:blur(10px);padding:10px;}
.table-container::-webkit-scrollbar{height:6px;}
.table-container::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.4);border-radius:999px;}
table{width:100%;border-collapse:collapse;min-width:650px;text-align:center;}
th,td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,0.25);color:#f0f0f0;font-size:0.85rem;}
th{background:rgba(255,255,255,0.2);font-weight:600;}
tr:hover{background:rgba(255,255,255,0.08);}

.dashboard-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-top:20px;}
.card{padding:25px;border-radius:var(--radius-lg);text-align:center;transition:.3s;box-shadow:var(--card-shadow);color:#fff;}
.card i{font-size:40px;margin-bottom:10px;}
.card:nth-child(1){background:linear-gradient(135deg,#1e3c72,#2a5298);}
.card:nth-child(2){background:linear-gradient(135deg,#11998e,#38ef7d);}
.card:nth-child(3){background:linear-gradient(135deg,#2c3e50,#4ca1af);}
.card:nth-child(4){background:linear-gradient(135deg,#8E0E00,#E52D27);}
.card:hover{transform:translateY(-6px);}

.searchbar{position:relative;width:min(420px,100%);border-radius:14px;background:rgba(255,255,255,0.12);backdrop-filter:blur(14px);box-shadow:0 6px 20px rgba(0,0,0,0.25), inset 0 0 0 1px rgba(255,255,255,0.20);}
.searchbar .icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:18px;color:#d0d7e2;}
.searchbar .search-input{width:100%;height:44px;padding:10px 42px 10px 40px;border:none;border-radius:14px;background:transparent;color:#fff;font-size:0.95rem;}
.searchbar .clear{position:absolute;right:10px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:none;border-radius:50%;background:rgba(255,255,255,0.18);color:#fff;cursor:pointer;}
.searchbar.small{width:100%;}
.searchbar.small .search-input{height:38px;}

.att-toggle{display:flex;gap:6px;justify-content:center;}
.att-btn{min-width:40px;padding:6px 10px;border:none;border-radius:8px;cursor:pointer;background:rgba(255,255,255,0.12);color:#fff;transition:.2s;}
.att-btn.neutral{opacity:.65;}
.att-btn.selected{opacity:1;box-shadow:0 6px 16px rgba(0,0,0,0.25);}
.att-p.selected{background:#22c55e;color:#0b1b0b;}
.att-a.selected{background:#ef4444;}
.att-l.selected{background:#f59e0b;color:#1f2937;}

tr.cancelled-row{background:linear-gradient(135deg, rgba(230,57,70,0.20), rgba(230,57,70,0.08));}
.badge-cancelled{display:inline-block;padding:2px 8px;border-radius:999px;background:#e63946;color:#fff;font-size:11px;margin-left:6px;}

.actions-cell{vertical-align:middle;padding-right:0;}
.actions-group{display:flex;flex-wrap:nowrap;align-items:center;gap:8px;overflow-x:auto;white-space:nowrap;}
.actions-group .btn, .actions-group a.btn{flex:0 0 auto;height:36px;padding:0 12px;line-height:1;font-size:0.9rem;border-radius:8px;}

#subjectsTable th:last-child, #subjectsTable td.actions-cell{width:560px;}

.chart-container{margin-top:30px;background:rgba(255,255,255,0.1);border-radius:20px;padding:20px;backdrop-filter:blur(20px);position:relative;overflow:hidden;}
.chart-container h3{text-align:center;margin-bottom:10px;color:#fff;}
.chart-container canvas{width:100%;height:auto;display:block;}

.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);justify-content:center;align-items:center;z-index:20;padding:10px;}
.modal-content{background:rgba(255,255,255,0.1);padding:22px;border-radius:15px;width:min(520px,95vw);max-height:90vh;overflow-y:auto;backdrop-filter:blur(15px);}
.beautiful-modal{background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.2);box-shadow:0 4px 25px rgba(0,0,0,0.3);padding:26px;border-radius:18px;}
.num{width:90px;padding:8px;border-radius:8px;border:1px solid #555;background:rgba(255,255,255,.15);color:#fff;text-align:center}

/* Wider Class Record modal so all columns fit without horizontal scroll */
#recordModal .modal-content{
  width:96vw !important;
  max-width:1400px !important;
  max-height:none !important;
  overflow:visible !important;

}

#classlistModal .modal-content,
#sheetModal .modal-content,
#quizModal .modal-content,
#quizScoreModal .modal-content,
#recordModal .modal-content {
  width: 96vw !important;
  max-width: 1400px !important;
  max-height: 90vh !important;   
  overflow-y: auto !important;  
  overflow-x: hidden;
}
.modal-content .table-container {
  overflow-x: auto !important;
  max-height: 70vh;
}

#classlistModal .table-container,
#sheetModal .table-container,
#quizModal .table-container,
#quizScoreModal .table-container {
  overflow-x: visible !important;
  padding: 0; 
}


#classlistModal table,
#sheetModal table,
#quizModal table,
#quizScoreModal table {
  min-width: 0 !important;
  width: 100% !important;
  table-layout: fixed; 
  scroll-behavior:smooth;
}

#classlistModal th, #classlistModal td,
#sheetModal th,     #sheetModal td,
#quizModal th,      #quizModal td,
#quizScoreModal th, #quizScoreModal td {
  padding: 10px 12px;
  white-space: nowrap;       
}


#classlistModal th:nth-child(1), #classlistModal td:nth-child(1) { text-align: left; width: 38%; }
#classlistModal th:nth-child(2), #classlistModal td:nth-child(2) { width: 14%; }
#classlistModal th:nth-child(3), #classlistModal td:nth-child(3) { width: 18%; }
#classlistModal th:nth-child(4), #classlistModal td:nth-child(4) { width: 10%; }
#classlistModal th:nth-child(5), #classlistModal td:nth-child(5) { width: 20%; }

#sheetModal th:nth-child(1), #sheetModal td:nth-child(1) { width: 18%; }
#sheetModal th:nth-child(2), #sheetModal td:nth-child(2) { text-align: left; width: 47%; }
#sheetModal th:nth-child(3), #sheetModal td:nth-child(3) { width: 15%; }
#sheetModal th:nth-child(4), #sheetModal td:nth-child(4) { width: 20%; }

#quizModal th:nth-child(1), #quizModal td:nth-child(1) { text-align: left; width: 46%; }
#quizModal th:nth-child(2), #quizModal td:nth-child(2) { width: 14%; }
#quizModal th:nth-child(3), #quizModal td:nth-child(3) { width: 12%; }
#quizModal th:nth-child(4), #quizModal td:nth-child(4) { width: 28%; }

#quizScoreModal th:nth-child(1), #quizScoreModal td:nth-child(1) { text-align: left; width: 56%; }
#quizScoreModal th:nth-child(2), #quizScoreModal td:nth-child(2) { width: 16%; }
#quizScoreModal th:nth-child(3), #quizScoreModal td:nth-child(3) { width: 28%; }


#subjectsTable th:last-child, #subjectsTable td.actions-cell { width: 560px; }

.cute-pop{animation:popIn .18s ease-out;}
@keyframes popIn{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.pulse{animation:pulse .9s ease-out;}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(0,198,255,.6)}100%{box-shadow:0 0 0 14px rgba(0,198,255,0)}}
.shake{animation:shake .35s ease-in-out;}
@keyframes shake{10%,90%{transform:translateX(-1px)}20%,80%{transform:translateX(2px)}30%,50%,70%{transform:translateX(-4px)}40%,60%{transform:translateX(4px)}}
tbody tr{animation:fadeIn .35s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}


#toastBox{
  position:fixed; right:18px; top:18px; z-index:9999;
  display:flex; flex-direction:column; gap:10px;
}
.toast{
  background:rgba(20,20,20,0.9);
  color:#eaffff; border-left:4px solid #38ef7d;
  padding:10px 14px; border-radius:10px; min-width:220px; max-width:60vw;
  box-shadow:0 6px 18px rgba(0,0,0,0.35);
  font-size:14px; line-height:1.3; opacity:0; transform:translateY(-6px);
  animation:tIn .16s ease-out forwards, tOut .2s ease-in 3.5s forwards;
}
@keyframes tIn{to{opacity:1; transform:none}}
@keyframes tOut{to{opacity:0; transform:translateY(-6px)}}
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
  <h2>CLASSIFY</h2>
  <a class="active"><i class="ri-dashboard-line"></i> Dashboard</a>
  <a href="#weekly"><i class="ri-calendar-schedule-line"></i> Weekly Schedule</a>
  <a href="#subjects"><i class="ri-book-2-line"></i> Subjects</a>
  <a href="#cancellations"><i class="ri-megaphone-line"></i> Cancellations</a>
  <a href="logout.php"><i class="ri-logout-box-line"></i> Logout</a>
</aside>

<main class="main">
  <button class="mobile-toggle" id="openSidebar"><i class="ri-menu-2-line"></i> Menu</button>

  <section class="section">
    <div class="header-bar">
      <div>
        <h1>Welcome, <?= htmlspecialchars($fname) ?> 👋</h1>
        <p>Empowering students every day.</p>
      </div>
      <div class="header-avatar">
        <form id="uploadFacultyPhotoForm" method="POST" enctype="multipart/form-data" style="display:none">
          <input type="hidden" name="upload_faculty_photo" value="1">
          <input type="file" name="faculty_photo" id="facultyPhotoInput" accept="image/*">
        </form>
        <div class="avatar" title="Change photo" onclick="document.getElementById('facultyPhotoInput').click()">
          <img src="<?= htmlspecialchars($photoPath) ?>" alt="Faculty photo">
          <span class="edit-badge"><i class="ri-camera-fill"></i></span>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="dashboard-cards">
      <div class="card"><i class="ri-calendar-check-line"></i><div>Today’s Classes</div><h2><?= (int)$todayClasses ?></h2></div>
      <div class="card"><i class="ri-book-open-line"></i><div>Assigned Subjects</div><h2><?= (int)$totalSubjects ?></h2></div>
      <div class="card"><i class="ri-team-line"></i><div>Students Handled</div><h2><?= (int)$totalStudents ?></h2></div>
      <div class="card"><i class="ri-checkbox-circle-line"></i><div>Attendance Marked Today</div><h2><?= (int)$attMarkedToday ?></h2></div>
    </div>
    <div class="table-actions" style="gap:12px">
      <div class="kpi-chip"><i class="ri-timer-line"></i> Unmarked Today: <b style="margin-left:6px"><?= (int)$unmarkedToday ?></b></div>
      <div class="kpi-chip"><i class="ri-sparkling-2-line"></i> On-Time Rate (30d): <b style="margin-left:6px"><?= (int)$onTimeRate ?>%</b></div>
    </div>
  </section>

  <section class="section">
    <div class="chart-container">
      <h3>Weekly Attendance Trend (Last 7 Days)</h3>
      <canvas id="trendChart"></canvas>
    </div>
  </section>

  <section class="section" id="today">
    <h2>Today’s Schedule (<?= htmlspecialchars($todayShort) ?>)</h2>
    <div class="table-container" id="todayScheduleWrap">
      <table>
        <thead><tr><th>Time</th><th>Subject</th><th>Code</th><th>Days</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="todayScheduleBody">
        <?php
          $rows = sql($conn,
            "SELECT a.*, s.name AS subject_name, s.code AS subject_code
             FROM assignments a JOIN subjects s ON a.subject_id=s.id
             WHERE a.faculty_id=? AND a.days LIKE CONCAT('%', ?, '%')
             ORDER BY a.time_start","is",array($fid,$todayShort)
          );
          if (empty($rows)) {
              echo '<tr><td colspan="6" style="text-align:left;color:#d0d7e2">No classes scheduled for today.</td></tr>';
          } else {
              $nowTs = time(); $todayDate = date("Y-m-d");
              foreach ($rows as $r) {
                  $subId     = (int)$r['subject_id'];
                  $timeStart = substr($r['time_start'],0,5);
                  $timeEnd   = substr($r['time_end'],0,5);
                  $classTs   = strtotime($todayDate." ".$r['time_start']);
                  $remaining = $classTs - $nowTs; if ($remaining < 0) $remaining = 0;

                  $cxl = sql_one($conn,
                    "SELECT 1 FROM cancellations
                     WHERE subject_code=? AND faculty_id=? AND DATE(`date`)=CURDATE()
                     LIMIT 1",
                    "si", array($r['subject_code'], $fid)
                  );
                  $isCancelled = $cxl ? true : false;

                  echo '<tr data-remaining="'.$remaining.'" data-subject-id="'.$subId.'"'.
                         ($isCancelled?' class="cancelled-row"':'').'>
                          <td>'.htmlspecialchars($timeStart).' – '.htmlspecialchars($timeEnd).'</td>
                          <td>'.htmlspecialchars($r['subject_name']).'</td>
                          <td>'.htmlspecialchars($r['subject_code']).'</td>
                          <td>'.htmlspecialchars($r['days']).'</td>
                          <td>'.
                            ($isCancelled
                              ? '<span class="badge-cancelled">Cancelled today</span>'
                              : '<span id="cd-'.$subId.'" style="color:#8ad0ff;font-weight:600"></span>'
                            ).
                          '</td>
                          <td>';
                  echo    '<button class="btn btn--focus" onclick="openClasslist('.$subId.', \''.htmlspecialchars(addslashes($r['subject_name'])).'\')"><i class="ri-user-check-line"></i> Classlist</button> ';

                  if ($isCancelled) {
                      echo  '<button class="btn cancel" disabled><i class="ri-close-circle-line"></i> Cancel</button>';
                  } else {
                      echo  '<button class="btn cancel" onclick="openCancelModal('.$subId.', \''.htmlspecialchars(addslashes($r['subject_name'])).'\')"><i class="ri-close-circle-line"></i> Cancel</button>';
                  }
                  echo   '</td></tr>';
              }
          }
        ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="section" id="subjects">
    <h2>Your Subjects</h2>
    <div class="table-actions">
      <div class="searchbar" id="subjectSearchWrap">
        <i class="ri-search-line icon"></i>
        <input id="subjectSearch" class="search-input" placeholder="Search subjects by code or name…" oninput="filterSubjects()">
        <button type="button" class="clear" aria-label="Clear search" onclick="clearSubjectSearch()">
          <i class="ri-close-circle-fill"></i>
        </button>
      </div>
    </div>

    <div class="table-container">
      <table id="subjectsTable">
        <thead><tr><th>Subject</th><th>Code</th><th>Days</th><th>Time</th><th>Actions</th></tr></thead>
        <tbody>
        <?php
        $subs = sql($conn,
          "SELECT a.*, s.name AS subject_name, s.code AS subject_code
           FROM assignments a JOIN subjects s ON a.subject_id=s.id
           WHERE a.faculty_id=? ORDER BY s.name ASC",
          "i", array($fid)
        );
        foreach ($subs as $s):
            $sid        = (int)$s['subject_id'];
            $sname_html = htmlspecialchars($s['subject_name'], ENT_QUOTES, 'UTF-8');
            $sname_js   = json_encode($s['subject_name']);
            $scode_html = htmlspecialchars($s['subject_code'], ENT_QUOTES, 'UTF-8');
            $days_html  = htmlspecialchars($s['days'], ENT_QUOTES, 'UTF-8');
            $tstart     = substr($s['time_start'], 0, 5);
            $tend       = substr($s['time_end'],   0, 5);
        ?>
        <tr>
          <td><?= $sname_html ?></td>
          <td><?= $scode_html ?></td>
          <td><?= $days_html ?></td>
          <td><?= $tstart ?> – <?= $tend ?></td>
          <td class="actions-cell">
            <div class="actions-group">
              <button class="btn btn--focus" onclick='openClasslist(<?= $sid ?>, <?= $sname_js ?>)'><i class="ri-user-check-line"></i> Open</button>
<button class="btn btn--focus" onclick='openAttendanceSheet(<?= $sid ?>, <?= $sname_js ?>)'><i class="ri-file-list-2-line"></i> Sheet</button>
<button class="btn btn--focus" onclick='openClassRecord(<?= $sid ?>, <?= $sname_js ?>)'><i class="ri-file-list-line"></i> Record</button>
<button class="btn btn--focus" onclick='openQuizManager(<?= $sid ?>, <?= $sname_js ?>)'><i class="ri-list-check"></i> Quizzes</button>
<a class="btn" href="faculty_export_grades.php?subject_id=<?= (int)$sid ?>'><i class="ri-file-excel-line"></i> Export Excel</a>
<button class="btn cancel" onclick='openCancelModal(<?= $sid ?>, <?= $sname_js ?>)'><i class="ri-close-circle-line"></i> Cancel</button>
 </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="section" id="weekly">
    <h2>Weekly Schedule</h2>
    <div class="table-actions" id="weekTabs"></div>
    <div class="table-container">
      <table>
        <thead><tr><th>Subject</th><th>Code</th><th>Day</th><th>Room</th><th>Time</th></tr></thead>
        <tbody id="weekContent"><tr><td colspan="5" style="text-align:left;color:#d0d7e2">Loading…</td></tr></tbody>
      </table>
    </div>
  </section>

  <section class="section" id="cancellations">
    <h2>My Cancellations</h2>
    <div class="table-container">
      <table>
        <thead><tr><th>Subject</th><th>Year</th><th>Course</th><th>Reason</th><th>Date</th></tr></thead>
        <tbody id="cancelList"><tr><td colspan="5" style="text-align:left;color:#d0d7e2">Loading…</td></tr></tbody>
      </table>
    </div>
  </section>
</main>

<!-- Classlist Modal -->
<div class="modal" id="classlistModal" aria-hidden="true">
  <div class="modal-content beautiful-modal cute-pop">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <h3 id="classlistTitle" style="margin:0">Classlist</h3>
      <button class="btn delete-btn" onclick="closeModal('classlistModal')"><i class="ri-close-line"></i></button>
    </div>
    <div class="searchbar small" id="studentSearchWrap">
      <i class="ri-search-line icon"></i>
      <input id="searchStudent" class="search-input" placeholder="Search students…" oninput="loadClasslist()">
      <button type="button" class="clear" aria-label="Clear search" onclick="clearStudentSearch()">
        <i class="ri-close-circle-fill"></i>
      </button>
    </div>
    <div id="classlistContainer" style="margin-top:10px;color:#d0d7e2;font-size:.9rem">Loading…</div>
  </div>
</div>

<!-- Class Record (Two-Term) -->
<div class="modal" id="recordModal" aria-hidden="true">
  <div class="modal-content beautiful-modal cute-pop">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <h3 id="recordTitle" style="margin:0">Class Record</h3>
      <div style="display:flex;gap:8px;align-items:center">
        <span id="chipMidterm" class="btn" style="padding:6px 10px;height:auto">Midterm —</span>
        <span id="chipFinal" class="btn" style="padding:6px 10px;height:auto">Final —</span>
        <button class="btn" onclick="openWeights()"><i class="ri-equalizer-2-line"></i> Weights</button>
        <button class="btn" onclick="recomputeIntoInputs()"><i class="ri-cpu-line"></i> Recompute</button>
        <button class="btn delete-btn" onclick="closeModal('recordModal')"><i class="ri-close-line"></i></button>
      </div>
    </div>
    <div id="recordContainer" class="table-container">
      <table>
        <thead>
          <tr>
            <th style="text-align:left">Student</th>
            <th>ID</th>
            <th>Midterm</th>
            <th>Final</th>
            <th>Final&nbsp;Grade</th>
          </tr>
        </thead>
        <tbody id="recordRows">
          <tr><td colspan="5" style="text-align:left;color:#d0d7e2">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Quiz Manager -->
<div class="modal" id="quizModal" aria-hidden="true">
  <div class="modal-content beautiful-modal cute-pop" style="max-width:800px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
      <h3 id="quizTitle" style="margin:0">Quizzes</h3>
      <button class="btn delete-btn" onclick="closeModal('quizModal')"><i class="ri-close-line"></i></button>
    </div>
    <div style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap">
      <input id="newQuizName" style="flex:1;min-width:200px;padding:8px;border-radius:8px;border:1px solid #555;background:rgba(255,255,255,0.15);color:white;" placeholder="Quiz name (e.g., Quiz 1)">
      <select id="newQuizTerm" style="padding:8px;border-radius:8px;border:1px solid #555;background:rgba(255,255,255,0.15);color:white;">
        <option value="midterm">Midterm</option>
        <option value="final">Final</option>
      </select>
      <input id="newQuizMax" type="number" min="1" max="1000" value="100" style="width:110px;padding:8px;border-radius:8px;border:1px solid #555;background:rgba(255,255,255,0.15);color:white;" placeholder="Max">
      <button class="btn" onclick="addQuiz()"><i class="ri-add-line"></i> Add Quiz</button>
    </div>
    <div id="quizList" style="margin-top:12px;color:#d0d7e2;font-size:.9rem">Loading quizzes…</div>
  </div>
</div>

<!-- Quiz Scores -->
<div class="modal" id="quizScoreModal" aria-hidden="true">
  <div class="modal-content beautiful-modal cute-pop" style="max-width:900px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
      <h3 id="quizScoreTitle">Quiz Scores</h3>
      <button class="btn delete-btn" onclick="closeModal('quizScoreModal')"><i class="ri-close-line"></i></button>
    </div>
    <div id="quizScoreList">Loading…</div>
  </div>
</div>

<!-- Student Profile -->
<div class="modal" id="profileModal" aria-hidden="true">
  <div class="modal-content beautiful-modal cute-pop">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <h3 style="margin:0">Student Profile</h3>
      <button class="btn delete-btn" onclick="closeModal('profileModal')"><i class="ri-close-line"></i></button>
    </div>
    <div id="profileContent" style="font-size:13px;margin-bottom:10px"></div>
    <h4 style="font-size:14px;margin-bottom:6px">Attendance Breakdown</h4>
    <canvas id="profileChart" height="110"></canvas>
  </div>
</div>

<!-- Attendance Sheet -->
<div class="modal" id="sheetModal" aria-hidden="true">
  <div class="modal-content beautiful-modal cute-pop">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <h3 id="sheetTitle" style="margin:0">Attendance Sheet</h3>
      <button class="btn delete-btn" onclick="closeModal('sheetModal')"><i class="ri-close-line"></i></button>
    </div>
    <div id="sheetBody" class="table-container" style="margin-top:8px;">
      <table>
        <thead><tr><th>Date</th><th>Student</th><th>ID</th><th>Status</th></tr></thead>
        <tbody id="sheetRows"><tr><td colspan="4" style="text-align:left;color:#d0d7e2">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Cancel Class -->
<div class="modal" id="cancelModal" aria-hidden="true">
  <div class="modal-content beautiful-modal cute-pop">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <h3 id="cancelModalTitle" style="margin:0">Cancel Class</h3>
      <button class="btn delete-btn" onclick="closeModal('cancelModal')"><i class="ri-close-line"></i></button>
    </div>
    <div id="cancelMeta" style="font-size:.85rem;color:#d0d7e2;margin-bottom:8px"></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
      <button class="btn" type="button" onclick="setReason('Faculty meeting')">Faculty meeting</button>
      <button class="btn" type="button" onclick="setReason('Health reasons')">Health reasons</button>
      <button class="btn" type="button" onclick="setReason('Weather advisory')">Weather advisory</button>
      <button class="btn" type="button" onclick="setReason('Room unavailable')">Room unavailable</button>
    </div>
    <textarea id="cancelReason" placeholder="Tell students why the class is cancelled..." style="width:100%;height:90px;border-radius:10px;border:1px solid rgba(255,255,255,0.35);background:rgba(255,255,255,0.12);color:#fff;padding:8px;"></textarea>
    <input type="hidden" id="cancelSubjectID">
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
      <button class="btn" onclick="closeModal('cancelModal')">Close</button>
      <button id="cancelSubmitBtn" class="btn delete-btn" onclick="submitCancel()">Confirm</button>
    </div>
  </div>
</div>

<div id="toastBox" aria-live="polite" aria-atomic="true"></div>

<script>
function $(id){ return document.getElementById(id); }
function openModal(id){ var m=$(id); if(m){ m.style.display='flex'; m.setAttribute('aria-hidden','false'); } }
function closeModal(id){ var m=$(id); if(m){ m.style.display='none'; m.setAttribute('aria-hidden','true'); } }
function escapeHtml(s){ s = s==null ? '' : String(s); return s.replace(/[&<>"]/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]||ch;}); }
function escapeQuotes(s){ s = s==null ? '' : String(s); return s.replace(/['"\\]/g,'\\$&'); }
function toast(msg){
  var box = $('toastBox');
  var t = document.createElement('div');
  t.className = 'toast';
  t.textContent = msg;
  box.appendChild(t);
  setTimeout(function(){ if (t && t.parentNode) t.parentNode.removeChild(t); }, 3800);
}

(function(){
  var openBtn=$('openSidebar'), sb=$('sidebar');
  if(openBtn){ openBtn.addEventListener('click',function(){ sb.classList.add('open'); }); }
})();

/* Trend chart */
(function(){
  var labels = <?= json_encode($labels7) ?>;
  var data   = <?= json_encode($data7) ?>;
  var ctx=document.getElementById('trendChart').getContext('2d');
  new Chart(ctx,{
    type:'line',
    data:{ labels:labels, datasets:[{ label:'Presents', data:data, borderColor:'#8ad0ff', backgroundColor:'rgba(138,208,255,.18)', tension:.35, fill:true, pointRadius:3 }] },
    options:{ responsive:true, maintainAspectRatio:true, aspectRatio:2.4,
      plugins:{ legend:{ labels:{ color:'#fff', font:{ size:11 } } } },
      scales:{ x:{ ticks:{ color:'#d0d7e2', font:{ size:10 } }, grid:{ display:false } },
               y:{ ticks:{ color:'#d0d7e2', font:{ size:10 } }, grid:{ color:'rgba(255,255,255,0.15)' }, beginAtZero:true } }
    }
  });
})();

/* Today countdown */
(function(){
  var rows=document.querySelectorAll('#todayScheduleBody tr[data-subject-id]');
  for(var i=0;i<rows.length;i++){
    (function(row){
      var sid=row.getAttribute('data-subject-id');
      var remain=parseInt(row.getAttribute('data-remaining')||'0',10);
      var el=$('cd-'+sid); if(!el) return;
      (function tick(){
        if(remain<=0){ el.innerHTML='<span style="color:#38ef7d;font-weight:700">Ongoing / Finished</span>'; return; }
        var h=Math.floor(remain/3600), m=Math.floor((remain%3600)/60), s=remain%60;
        el.textContent='Starts in '+h+'h '+m+'m '+s+'s';
        remain--; setTimeout(tick,1000);
      })();
    })(rows[i]);
  }
})();

/* Classlist + Attendance */
var currentSubject=0, currentStudent=0, profileChartInstance=null;

function openClasslist(subjectId, subjectName){
  currentSubject=subjectId;
  $('classlistTitle').textContent=subjectName+' — Classlist';
  openModal('classlistModal');
  loadClasslist();
}
function buttonClassesFor(code, selected){
  var base='att-btn '; if(code==='P') base+='att-p '; if(code==='A') base+='att-a '; if(code==='L') base+='att-l ';
  var isSel=(selected==='Present'&&code==='P')||(selected==='Absent'&&code==='A')||(selected==='Late'&&code==='L');
  return base + (isSel ? 'selected' : 'neutral');
}
function loadClasslist(){
  var query=$('searchStudent').value || '';
  fetch('?api=classlist&subject_id='+currentSubject+'&search='+encodeURIComponent(query))
    .then(function(r){return r.json();})
    .then(function(res){
      var c=$('classlistContainer');
      if(!(res && res.ok)){ c.textContent='Failed to load.'; return; }
      var list=res.list||[];
      if(list.length===0){ c.innerHTML='<div>No students found.</div>'; return; }
      var html='<div class="table-container"><table><thead><tr><th style="text-align:left">Student</th><th>ID</th><th>Course</th><th>Year</th><th>Today</th></tr></thead><tbody>';
      for(var i=0;i<list.length;i++){
        var s=list[i], st = s.status_today ? s.status_today : null;
        html+='<tr id="row-'+s.id+'">'+
              '<td style="text-align:left">'+escapeHtml(s.name)+'</td>'+
              '<td>'+escapeHtml(s.student_id||"")+'</td>'+
              '<td>'+escapeHtml(s.course||"")+'</td>'+
              '<td>'+escapeHtml(s.year_level||"")+'</td>'+
              '<td>'+
                '<div class="att-toggle" id="attgrp-'+s.id+'">'+
                  '<button id="btnP-'+s.id+'" class="'+buttonClassesFor('P', st)+'" onclick="setAttendance('+s.id+',\'Present\')">P</button>'+
                  '<button id="btnA-'+s.id+'" class="'+buttonClassesFor('A', st)+'" onclick="setAttendance('+s.id+',\'Absent\')">A</button>'+
                  '<button id="btnL-'+s.id+'" class="'+buttonClassesFor('L', st)+'" onclick="setAttendance('+s.id+',\'Late\')">L</button>'+
                '</div>'+
              '</td>'+
            '</tr>';
      }
      html+='</tbody></table></div>';
      c.innerHTML=html;
    });
}
function updateButtonGroup(studentId, status){
  var bp=$('btnP-'+studentId), ba=$('btnA-'+studentId), bl=$('btnL-'+studentId);
  if(!bp||!ba||!bl) return;
  bp.classList.remove('selected'); ba.classList.remove('selected'); bl.classList.remove('selected');
  bp.classList.add('neutral'); ba.classList.add('neutral'); bl.classList.add('neutral');
  if(status==='Present'){ bp.classList.remove('neutral'); bp.classList.add('selected'); }
  else if(status==='Absent'){ ba.classList.remove('neutral'); ba.classList.add('selected'); }
  else if(status==='Late'){ bl.classList.remove('neutral'); bl.classList.add('selected'); }
}
function setAttendance(studentId, status){
  var fd=new FormData();
  fd.append('subject_id',currentSubject); fd.append('student_id',studentId); fd.append('status',status);
  fetch('?api=save_att',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(res){ if(res && res.ok){ updateButtonGroup(studentId, status); toast('Attendance saved'); } });
}

/* Profile */
function openProfile(studentId){
  currentStudent=studentId;
  openModal('profileModal');
  fetch('?api=profile&student_id='+studentId).then(function(r){return r.json();}).then(function(res){
    var s=(res&&res.profile)?res.profile:null, pc=$('profileContent');
    if(!s){ pc.innerHTML='<span style="color:#d0d7e2">Student not found.</span>'; return; }
    var photo=s.photo ? 'uploads/students/'+s.photo : 'uploads/students/default.png';
    pc.innerHTML='<div style="text-align:center;margin-bottom:6px;">'+
                 '<img src="'+photo+'" style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.6)">'+
                 '</div>'+
                 '<p><strong>'+escapeHtml(s.name)+'</strong></p>'+
                 '<p>ID: '+escapeHtml(s.student_id||"")+'</p>'+
                 '<p>Course: '+escapeHtml(s.course||"")+'</p>'+
                 '<p>Year level: '+escapeHtml(s.year_level||"")+'</p>';
  });
  fetch('?api=profile_attendance&student_id='+studentId+'&subject_id='+currentSubject)
    .then(function(r){return r.json();}).then(function(res){
      var rows=res.rows||[], p=0,a=0,l=0;
      for(var i=0;i<rows.length;i++){ var st=rows[i].status; if(st==='Present')p++; else if(st==='Absent')a++; else if(st==='Late')l++; }
      var ctx=$('profileChart').getContext('2d');
      if(profileChartInstance){ profileChartInstance.destroy(); }
      profileChartInstance=new Chart(ctx,{
        type:'doughnut',
        data:{ labels:['Present','Absent','Late'], datasets:[{ data:[p,a,l], backgroundColor:['#38ef7d','#ef4444','#f59e0b'], borderWidth:0 }] },
        options:{ plugins:{ legend:{ labels:{ color:'#fff', font:{ size:11 } } } } }
      });
    });
}

/* Weekly schedule */
var DAYS=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
function loadWeekly(){
  fetch('?api=schedule_week').then(function(r){return r.json();}).then(function(res){
    var tabs=$('weekTabs'); tabs.innerHTML='';
    var content=$('weekContent');
    if(!(res && res.ok)){ content.innerHTML='<tr><td colspan="5" style="text-align:left;color:#d0d7e2">Failed to load.</td></tr>'; return; }
    DAYS.forEach(function(d){
      var b=document.createElement('button');
      b.className='btn'; b.style.padding='6px 10px'; b.textContent=d;
      b.onclick=function(){ renderDay(d,res.schedule[d]||[]); };
      tabs.appendChild(b);
    });
    var today='<?= htmlspecialchars($todayShort) ?>';
    var start = DAYS.indexOf(today)>=0 ? today : 'Mon';
    renderDay(start, res.schedule[start]||[]);
  });
}
function renderDay(day,items){
  var c=$('weekContent');
  if(!items || items.length===0){ c.innerHTML='<tr><td colspan="5" style="text-align:left;color:#d0d7e2">No classes on '+day+'.</td></tr>'; return; }
  var html='';
  for(var i=0;i<items.length;i++){
    var it=items[i], room = it.room ? it.room : '';
    html+='<tr>'+
            '<td style="text-align:left">'+escapeHtml(it.subject_name)+'</td>'+
            '<td>'+escapeHtml(it.subject_code)+'</td>'+
            '<td>'+day+'</td>'+
            '<td>'+escapeHtml(room)+'</td>'+
            '<td>'+it.time_start+' – '+it.time_end+'</td>'+
          '</tr>';
  }
  c.innerHTML=html;
}

/* Cancellations */
function loadCancellations(){
  fetch('?api=cancellations').then(function(r){return r.json();}).then(function(res){
    var list=$('cancelList');
    if(!(res && res.ok)){ list.innerHTML='<tr><td colspan="5" style="text-align:left;color:#d0d7e2">Failed to load.</td></tr>'; return; }
    var items=res.items||[];
    if(items.length===0){ list.innerHTML='<tr><td colspan="5" style="text-align:left;color:#d0d7e2">No cancellations yet.</td></tr>'; return; }
    var html='';
    for(var i=0;i<items.length;i++){
      var x=items[i]; var ds=new Date(String(x.date).replace(' ','T')); var out=isNaN(ds.getTime())?String(x.date):ds.toLocaleString();
      html+='<tr>'+
              '<td><strong>'+escapeHtml(x.subject_code||'')+'</strong></td>'+
              '<td>'+escapeHtml(x.year_level||'')+'</td>'+
              '<td>'+escapeHtml(x.course||'')+'</td>'+
              '<td style="text-align:left">'+escapeHtml(x.reason||'')+'</td>'+
              '<td>'+escapeHtml(out)+'</td>'+
            '</tr>';
    }
    list.innerHTML=html;
  });
}

/* Attendance Sheet */
function openAttendanceSheet(subjectId, subjectName){
  $('sheetTitle').textContent=subjectName+' — Attendance (last 30 days)';
  openModal('sheetModal');
  fetch('?api=attendance_sheet&subject_id='+subjectId).then(function(r){return r.json();}).then(function(res){
    var tb=$('sheetRows');
    if(!(res && res.ok)){ tb.innerHTML='<tr><td colspan="4" style="text-align:left;color:#d0d7e2">Failed to load.</td></tr>'; return; }
    var rows=res.rows||[];
    if(rows.length===0){ tb.innerHTML='<tr><td colspan="4" style="text-align:left;color:#d0d7e2">No attendance records yet.</td></tr>'; return; }
    var html='';
    for(var i=0;i<rows.length;i++){
      var r=rows[i]; var ds=new Date(String(r.date).replace(' ','T')); var dateOut=isNaN(ds.getTime())?String(r.date):ds.toLocaleDateString();
      var col='#d0d7e2'; if(r.status==='Present') col='#38ef7d'; else if(r.status==='Absent') col='#ef4444'; else if(r.status==='Late') col='#f59e0b';
      html+='<tr>'+
            '<td>'+escapeHtml(dateOut)+'</td>'+
            '<td style="text-align:left">'+escapeHtml(r.student_name||"")+'</td>'+
            '<td>'+escapeHtml(r.student_id||"")+'</td>'+
            '<td style="color:'+col+';font-weight:700">'+escapeHtml(r.status||"")+'</td>'+
            '</tr>';
    }
    tb.innerHTML=html;
  });
}

/* Cancel flow */
function openCancelModal(subjectId, subjectName){
  $('cancelSubjectID').value=subjectId;
  $('cancelModalTitle').textContent='Cancel '+subjectName;
  $('cancelMeta').textContent=subjectName;
  $('cancelReason').value='';
  openModal('cancelModal');
}
function setReason(text){ var t=$('cancelReason'); t.value=text; t.focus(); }
function submitCancel(){
  var sid=$('cancelSubjectID').value;
  var reason=($('cancelReason').value||'').trim();
  if(reason.length<3){
    var card=document.querySelector('#cancelModal .modal-content');
    card.classList.remove('shake'); void card.offsetWidth; card.classList.add('shake');
    return;
  }
  var btn=$('cancelSubmitBtn'); btn.disabled=true; btn.textContent='Saving…';
  var fd=new FormData(); fd.append('subject_id',sid); fd.append('reason',reason);
  fetch('?api=cancel_class',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
    if(res && res.ok){
      closeModal('cancelModal');
      loadCancellations();
      toast('Class cancelled');
      setTimeout(function(){ location.reload(); }, 600);
    }
    else { alert((res && res.error)?res.error:'Error'); }
  }).finally(function(){ btn.disabled=false; btn.textContent='Confirm'; });
}

/* Subject filter */
function filterSubjects(){
  var q=($('subjectSearch').value||'').toLowerCase();
  var rows=document.querySelectorAll('#subjectsTable tbody tr');
  for(var i=0;i<rows.length;i++){
    var txt=rows[i].innerText.toLowerCase();
    rows[i].style.display = txt.indexOf(q)>=0 ? '' : 'none';
  }
}
function clearSubjectSearch(){ var el=$('subjectSearch'); if(el){ el.value=''; filterSubjects(); } }
function clearStudentSearch(){ var el=$('searchStudent'); if(el){ el.value=''; loadClasslist(); } }

/* Avatar auto-submit */
(function(){
  var input = document.getElementById('facultyPhotoInput');
  if (input) { input.addEventListener('change', function(){ if (this.files && this.files.length) { document.getElementById('uploadFacultyPhotoForm').submit(); } }); }
})();

/* Class Record + Two-Term Weights + Recompute */
var currentRecordSubject = 0;
var _computedByStudent = {};
var currentQuizSubject = 0;
var currentQuizId = 0;

function openClassRecord(subId, subName) {
  currentRecordSubject = subId;
  $('recordTitle').textContent = subName + ' — Class Record';
  openModal('recordModal');
  loadClassRecord();
}
function loadClassRecord() {
  fetch('?api=class_record_load&subject_id=' + currentRecordSubject)
    .then(function(r){return r.json();})
    .then(function(res){
      var tb = $('recordRows');
      if(!(res && res.ok)){ tb.innerHTML='<tr><td colspan="5" style="color:#d0d7e2;text-align:left">Failed to load.</td></tr>'; return; }
      var rows = res.list || [];
      if(rows.length===0){ tb.innerHTML='<tr><td colspan="5" style="color:#d0d7e2;text-align:left">No students found.</td></tr>'; return; }
      var html = '';
      for(var i=0;i<rows.length;i++){
        var r=rows[i];
        var mid = (r.midterm_grade == null ? '' : r.midterm_grade);
        var fin = (r.final_grade   == null ? '' : r.final_grade);
        var finalOverall = (r.final_overall == null ? '—' : Number(r.final_overall).toFixed(2));
        html += '<tr data-stud="'+r.id+'">'+
                '<td style="text-align:left">'+escapeHtml(r.name)+'</td>'+
                '<td>'+escapeHtml(r.student_id)+'</td>'+
                '<td><input class="inp-midterm" type="number" min="0" max="100" value="'+mid+'" onblur="saveClassRecord('+r.id+', \'midterm\', this.value)" style="width:75px;padding:6px;border-radius:6px;border:1px solid #555;background:rgba(255,255,255,0.15);color:white;"></td>'+
                '<td><input class="inp-final"   type="number" min="0" max="100" value="'+fin+'" onblur="saveClassRecord('+r.id+', \'final\', this.value)" style="width:75px;padding:6px;border-radius:6px;border:1px solid #555;background:rgba(255,255,255,0.15);color:white;"></td>'+
                '<td><strong id="fg-'+r.id+'">'+finalOverall+'</strong></td>'+
                '</tr>';
      }
      tb.innerHTML = html;

      fetch('?api=compute_terms&subject_id=' + currentRecordSubject)
        .then(function(a){return a.json();})
        .then(function(comp){
          if (!(comp && comp.ok)) { return; }
          _computedByStudent = {};
          var avgM = [], avgF = [];
          var items = comp.rows || [];
          for(var j=0;j<items.length;j++){
            var row = items[j];
            _computedByStudent[row.student_id] = {
              midterm: row.midterm_computed,
              final:   row.final_computed
            };
            if (row.midterm_computed != null) avgM.push(row.midterm_computed);
            if (row.final_computed   != null) avgF.push(row.final_computed);
          }
          var m = avgM.length ? Math.round((avgM.reduce(function(a,b){return a+b;},0)/avgM.length)*100)/100 : '—';
          var f = avgF.length ? Math.round((avgF.reduce(function(a,b){return a+b;},0)/avgF.length)*100)/100 : '—';
          $('chipMidterm').textContent = 'Midterm: ' + m;
          $('chipFinal').textContent   = 'Final: '   + f;
        });

      fetch('?api=weights_get&subject_id=' + currentRecordSubject)
        .then(function(r){return r.json();}).then(function(w){
          if(w && w.ok){
            $('chipMidterm').title = 'Weight: ' + w.weights.midterm_weight + '%';
            $('chipFinal').title   = 'Weight: ' + w.weights.final_weight   + '%';
          }
        });
    });
}
function saveClassRecord(studentId, type, val) {
  var fd = new FormData();
  fd.append('subject_id', currentRecordSubject);
  fd.append('student_id', studentId);
  fd.append('column', type);
  fd.append('value', val);
  fetch('?api=class_record_save', { method: 'POST', body: fd })
    .then(function(r){return r.json();})
    .then(function(res){
      if (res && res.ok){
        if (typeof res.final_overall !== 'undefined') {
          var cell = document.getElementById('fg-' + studentId);
          if (cell) cell.textContent = (res.final_overall == null ? '—' : Number(res.final_overall).toFixed(2));
        }
        toast('Grade saved');
      } else {
        alert(res && res.error ? res.error : 'Failed to save.');
      }
    });
}
function openWeights(){
  openModal('weightsModal');
  fetch('?api=weights_get&subject_id='+currentRecordSubject)
    .then(function(r){return r.json();})
    .then(function(res){
      if(res && res.ok){
        $('wMid').value = res.weights.midterm_weight;
        $('wFin').value = res.weights.final_weight;
        $('wError').textContent = '';
      }
    });
}
function saveWeights(){
  var mid = parseInt($('wMid').value||'0',10),
      fin = parseInt($('wFin').value||'0',10);
  if (mid<0||fin<0 || mid+fin !== 100){
    $('wError').textContent = 'Weights must be non-negative and total 100%.';
    var mc = $('weightsModal').querySelector('.modal-content'); mc.classList.remove('shake'); void mc.offsetWidth; mc.classList.add('shake');
    return;
  }
  var fd = new FormData();
  fd.append('subject_id', currentRecordSubject);
  fd.append('midterm', mid);
  fd.append('final', fin);
  var btn = $('wSave'); btn.disabled = true; btn.textContent = 'Saving…';
  fetch('?api=weights_save', {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(res){
      if(res && res.ok){
        $('wError').textContent = '';
        btn.classList.add('pulse');
        setTimeout(function(){ btn.classList.remove('pulse'); }, 900);
        closeModal('weightsModal');
        loadClassRecord();
        toast('Weights saved');
      } else {
        $('wError').textContent = (res && res.error) ? res.error : 'Failed to save weights.';
      }
    }).finally(function(){ btn.disabled=false; btn.textContent='Save'; });
}
function recomputeIntoInputs() {
  var rows = document.querySelectorAll('#recordRows tr[data-stud]');
  for (var i=0;i<rows.length;i++) {
    var tr = rows[i];
    var sid = parseInt(tr.getAttribute('data-stud'),10);
    var comp = _computedByStudent[sid] || {};
    if (comp.midterm != null) { tr.querySelector('.inp-midterm').value = comp.midterm; saveClassRecord(sid, 'midterm', comp.midterm); }
    if (comp.final   != null) { tr.querySelector('.inp-final').value   = comp.final;   saveClassRecord(sid, 'final',   comp.final); }
  }
}

/* Quiz Manager */
function openQuizManager(subId, subName) {
  currentQuizSubject = subId;
  $('quizTitle').textContent = subName + ' — Quizzes';
  openModal('quizModal');
  loadQuizItems();
}
function loadQuizItems() {
  fetch('?api=quiz_list&subject_id=' + currentQuizSubject)
    .then(function(r){return r.json();})
    .then(function(res){
      var q = $('quizList');
      if(!(res && res.ok)){ q.innerHTML = '<div>Failed to load.</div>'; return; }
      var quizzes = res.items || [];
      if(quizzes.length===0){ q.innerHTML = '<div>No quizzes yet. Add one above.</div>'; return; }
      var html = '<div class="table-container"><table><thead><tr><th style="text-align:left">Quiz</th><th>Term</th><th>Max</th><th>Actions</th></tr></thead><tbody>';
      for(var i=0;i<quizzes.length;i++){
        var quiz = quizzes[i];
        html += '<tr>'+
                  '<td style="text-align:left">'+escapeHtml(quiz.quiz_name)+'</td>'+
                  '<td>'+escapeHtml(quiz.term)+'</td>'+
                  '<td>'+(quiz.max_score||0)+'</td>'+
                  '<td>'+
                    '<button class="btn" onclick="openQuizScores('+quiz.id+', \''+escapeQuotes(quiz.quiz_name)+'\')"><i class="ri-edit-line"></i> Scores</button> '+
                    '<button class="btn delete-btn" onclick="deleteQuiz('+quiz.id+')"><i class="ri-delete-bin-line"></i></button>'+
                  '</td>'+
                '</tr>';
      }
      html += '</tbody></table></div>';
      q.innerHTML = html;
    });
}
function addQuiz() {
  var name = $('newQuizName').value.trim();
  var term = $('newQuizTerm').value;
  var max  = parseInt(($('newQuizMax').value||'100'), 10);
  if (name.length < 2) { alert('Quiz name too short.'); return; }
  if (!/^(midterm|final)$/.test(term)) { alert('Choose Midterm or Final.'); return; }
  if (!(max >= 1 && max <= 1000)) { alert('Max score must be 1..1000'); return; }
  var fd = new FormData();
  fd.append('subject_id', currentQuizSubject);
  fd.append('quiz_name', name);
  fd.append('term', term);
  fd.append('max_score', String(max));
  fetch('?api=quiz_add', { method:'POST', body:fd })
    .then(function(r){return r.json();})
    .then(function(res){
      if(res.ok){
        $('newQuizName').value = '';
        $('newQuizMax').value = '100';
        $('newQuizTerm').value = 'midterm';
        loadQuizItems();
        toast('Quiz added');
      } else {
        alert(res.error || 'Failed.');
      }
    });
}
function deleteQuiz(id) {
  if (!confirm('Delete this quiz?')) return;
  var fd = new FormData(); fd.append('quiz_id', id);
  fetch('?api=quiz_delete', { method:'POST', body:fd })
    .then(function(r){return r.json();})
    .then(function(res){ if(res.ok){ loadQuizItems(); toast('Quiz deleted'); } else { alert(res.error || 'Failed.'); } });
}
function openQuizScores(id, name) {
  currentQuizId = id;
  $('quizScoreTitle').textContent = name + ' — Scores';
  openModal('quizScoreModal');
  loadQuizScores();
}
function loadQuizScores() {
  fetch('?api=quiz_scores&quiz_id=' + currentQuizId)
    .then(function(r){return r.json();})
    .then(function(res){
      var c = $('quizScoreList');
      if(!(res && res.ok)){ c.innerHTML = 'Failed to load.'; return; }
      var rows = res.items || [];
      if(rows.length===0){ c.innerHTML = 'No students enrolled.'; return; }
      var max = res.max_score || 100;
      var html = '<div class="table-container"><table>'+
                 '<thead><tr><th style="text-align:left">Student</th><th>ID</th><th>Score (0-'+max+')</th></tr></thead><tbody>';
      for(var i=0;i<rows.length;i++){
        var r=rows[i]; var v = (r.score == null ? '' : r.score);
        html += '<tr>'+
                  '<td style="text-align:left">'+escapeHtml(r.name)+'</td>'+
                  '<td>'+escapeHtml(r.student_id)+'</td>'+
                  '<td><input type="number" min="0" max="'+max+'" value="'+v+'" onblur="saveQuizScore('+r.id+', this.value)" style="width:100px;padding:6px;border-radius:6px;border:1px solid #555;background:rgba(255,255,255,0.15);color:white;"></td>'+
                '</tr>';
      }
      html += '</tbody></table></div>';
      c.innerHTML = html;
    });
}
function saveQuizScore(studentId, value) {
  var fd = new FormData();
  fd.append('quiz_id', currentQuizId);
  fd.append('student_id', studentId);
  fd.append('score', value);
  fetch('?api=quiz_save_score', { method:'POST', body: fd })
    .then(function(r){return r.json();})
    .then(function(res){ if(res.ok){ toast('Score saved'); } else { alert(res.error || 'Failed to save score.'); } });
}
</script>

<!-- Weights Modal (Two-Term) -->
<div class="modal" id="weightsModal" aria-hidden="true">
  <div class="modal-content beautiful-modal cute-pop">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
      <h3 style="margin:0">Grading Weights</h3>
      <button class="btn delete-btn" onclick="closeModal('weightsModal')"><i class="ri-close-line"></i></button>
    </div>
    <p style="color:#d0d7e2;margin-bottom:10px">Set the percentage for each term (must total 100%).</p>
    <div style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;max-width:380px">
      <label>Midterm</label><input id="wMid" type="number" min="0" max="100" value="50" class="num">
      <label>Final</label><input id="wFin" type="number" min="0" max="100" value="50" class="num">
    </div>
    <div id="wError" style="color:#ffd2d2;margin-top:8px;min-height:18px"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
      <button class="btn" onclick="closeModal('weightsModal')">Close</button>
      <button class="btn" id="wSave" onclick="saveWeights()"><i class="ri-save-3-line"></i> Save</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
  loadWeekly();
  loadCancellations();
});
</script>
</body>
</html>
