<?php
// faculty_export_grades.php — Excel-friendly export (two-term, with final_overall)
// No Composer; no arrow functions; aligned with latest faculty dashboard

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    http_response_code(403);
    exit('Forbidden');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli('localhost', 'root', '', 'classify_db');
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    http_response_code(500);
    exit('DB connection error');
}

$subjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
if ($subjectId <= 0) exit('Invalid subject_id');

/* ---- Subject info ---- */
$subStmt = $db->prepare('SELECT code, name FROM subjects WHERE id=? LIMIT 1');
$subStmt->bind_param('i', $subjectId);
$subStmt->execute();
$subject = $subStmt->get_result()->fetch_assoc();
$subStmt->close();
if (!$subject) exit('Subject not found');

/* ---- Enrolled students ---- */
$stuStmt = $db->prepare("
    SELECT s.id AS student_pk, s.student_id, s.name
    FROM enrollments e
    JOIN students s ON s.id = e.student_id
    WHERE e.subject_id = ?
    ORDER BY s.name ASC
");
$stuStmt->bind_param('i', $subjectId);
$stuStmt->execute();
$students = $stuStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stuStmt->close();

/* ---- Quizzes (with term and max) ---- */
$qzStmt = $db->prepare("
    SELECT id, quiz_name, term, max_score
    FROM quiz_items
    WHERE subject_id = ?
    ORDER BY id ASC
");
$qzStmt->bind_param('i', $subjectId);
$qzStmt->execute();
$quizzes = $qzStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$qzStmt->close();

/* Group quizzes by term for clearer columns */
$midtermQuizzes = array();
$finalQuizzes   = array();
for ($i = 0; $i < count($quizzes); $i++) {
    $q = $quizzes[$i];
    if ($q['term'] === 'final') $finalQuizzes[]   = $q;
    else                        $midtermQuizzes[] = $q; // default/midterm
}

/* ---- Quiz scores [student_pk][quiz_id] => score ---- */
$quizScores = array();
if (!empty($quizzes) && !empty($students)) {
    $ids = array();
    for ($i = 0; $i < count($students); $i++) $ids[] = (int)$students[$i]['student_pk'];

    $place = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids) + 1); // subject_id + N students

    $sql = "SELECT qs.student_id, qs.quiz_id, qs.score
            FROM quiz_scores qs
            JOIN quiz_items qi ON qi.id = qs.quiz_id
            WHERE qi.subject_id = ? AND qs.student_id IN ($place)";

    $stmt = $db->prepare($sql);
    $params = array_merge(array($types, $subjectId), $ids);
    $refs = array();
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    call_user_func_array(array($stmt, 'bind_param'), $refs);

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['student_id'];
        $qid = (int)$row['quiz_id'];
        if (!isset($quizScores[$sid])) $quizScores[$sid] = array();
        $quizScores[$sid][$qid] = is_null($row['score']) ? null : (int)$row['score'];
    }
    $stmt->close();
}

/* ---- Class record (two-term + final_overall) ---- */
$gradeByStudent = array(); // student_pk => ['midterm'=>, 'final'=>, 'final_overall'=>]
if (!empty($students)) {
    $ids = array();
    for ($i = 0; $i < count($students); $i++) $ids[] = (int)$students[$i]['student_pk'];

    $place = implode(',', array_fill(0, count($ids), '?'));
    $types = 'i' . str_repeat('i', count($ids)); // subject_id + N

    $sql = "SELECT student_id, midterm_grade, final_grade, final_overall
            FROM class_record
            WHERE subject_id = ? AND student_id IN ($place)";
    $stmt = $db->prepare($sql);

    $params = array_merge(array($types, $subjectId), $ids);
    $refs = array();
    foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
    call_user_func_array(array($stmt, 'bind_param'), $refs);

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['student_id'];
        $gradeByStudent[$sid] = array(
            'midterm'       => is_null($row['midterm_grade']) ? null : (float)$row['midterm_grade'],
            'final'         => is_null($row['final_grade'])   ? null : (float)$row['final_grade'],
            'final_overall' => is_null($row['final_overall']) ? null : (float)$row['final_overall'],
        );
    }
    $stmt->close();
}

/* ---- Grading weights (default 50/50) ---- */
$weights = array('midterm_weight'=>50,'final_weight'=>50);
$wStmt = $db->prepare("SELECT midterm_weight, final_weight FROM grading_weights WHERE subject_id=? LIMIT 1");
$wStmt->bind_param('i', $subjectId);
$wStmt->execute();
$wres = $wStmt->get_result()->fetch_assoc();
$wStmt->close();
if ($wres) $weights = $wres;

/* ---- Helpers to compute term % from quizzes ---- */
function compute_term_percent($studentPk, $quizList, $quizScores) {
    if (empty($quizList)) return null;
    $parts = array();
    for ($i = 0; $i < count($quizList); $i++) {
        $q  = $quizList[$i];
        $qid = (int)$q['id'];
        $max = (int)$q['max_score'];
        if ($max <= 0) continue;
        $sc = (isset($quizScores[$studentPk]) && array_key_exists($qid, $quizScores[$studentPk]))
              ? $quizScores[$studentPk][$qid] : null;
        if ($sc === null) continue;
        $parts[] = ($sc / $max) * 100.0;
    }
    if (empty($parts)) return null;
    $sum = 0.0; for ($i = 0; $i < count($parts); $i++) $sum += $parts[$i];
    return round($sum / count($parts), 2);
}

/* ---- Output as Excel-friendly HTML table ---- */
$filename = 'Grades_'.$subject['code'].'_'.date('Ymd_His').'.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

echo "<html><head><meta charset=\"utf-8\"></head><body>";
echo "<h3>Subject: ".htmlspecialchars($subject['code'])." — ".htmlspecialchars($subject['name'])."</h3>";
echo "<p>Weights: Midterm ".$weights['midterm_weight']."% &nbsp; | &nbsp; Final ".$weights['final_weight']."%</p>";

echo "<table border='1' cellspacing='0' cellpadding='5'>";

/* --- Header row --- */
echo "<tr>";
echo "<th>Student ID</th><th>Student Name</th>";
// Midterm quiz columns
for ($i = 0; $i < count($midtermQuizzes); $i++) {
    echo "<th>".htmlspecialchars($midtermQuizzes[$i]['quiz_name'])." (Midterm)</th>";
}
// Final quiz columns
for ($i = 0; $i < count($finalQuizzes); $i++) {
    echo "<th>".htmlspecialchars($finalQuizzes[$i]['quiz_name'])." (Final)</th>";
}
echo "<th>Midterm %</th><th>Final %</th><th>Final Grade</th>";
echo "</tr>";

/* --- Data rows --- */
for ($i = 0; $i < count($students); $i++) {
    $s   = $students[$i];
    $sid = (int)$s['student_pk'];

    // quiz scores per term
    $midPctComputed = compute_term_percent($sid, $midtermQuizzes, $quizScores);
    $finPctComputed = compute_term_percent($sid, $finalQuizzes,   $quizScores);

    // saved grades (if present) override computed %
    $saved = isset($gradeByStudent[$sid]) ? $gradeByStudent[$sid] : array(
        'midterm'=>null,'final'=>null,'final_overall'=>null
    );

    $midForOverall = is_null($saved['midterm']) ? $midPctComputed : (float)$saved['midterm'];
    $finForOverall = is_null($saved['final'])   ? $finPctComputed : (float)$saved['final'];

    // final grade: saved final_overall else compute if both terms available
    $finalOverall = $saved['final_overall'];
    if (is_null($finalOverall) && $midForOverall !== null && $finForOverall !== null) {
        $mw = (int)$weights['midterm_weight']; $fw = (int)$weights['final_weight'];
        $sumW = ($mw + $fw); if ($sumW <= 0) $sumW = 100;
        $finalOverall = round((($midForOverall * $mw) + ($finForOverall * $fw)) / $sumW, 2);
    }

    echo "<tr>";
    echo "<td>".htmlspecialchars($s['student_id'])."</td>";
    echo "<td>".htmlspecialchars($s['name'])."</td>";

    // Midterm quiz cells
    for ($j = 0; $j < count($midtermQuizzes); $j++) {
        $qid = (int)$midtermQuizzes[$j]['id'];
        $val = (isset($quizScores[$sid]) && array_key_exists($qid, $quizScores[$sid]))
               ? $quizScores[$sid][$qid] : '';
        echo "<td>".htmlspecialchars((string)$val)."</td>";
    }
    // Final quiz cells
    for ($j = 0; $j < count($finalQuizzes); $j++) {
        $qid = (int)$finalQuizzes[$j]['id'];
        $val = (isset($quizScores[$sid]) && array_key_exists($qid, $quizScores[$sid]))
               ? $quizScores[$sid][$qid] : '';
        echo "<td>".htmlspecialchars((string)$val)."</td>";
    }

    // Midterm % (prefer saved grade if there; else computed)
    $midShow = !is_null($saved['midterm']) ? number_format((float)$saved['midterm'], 2)
               : (!is_null($midPctComputed) ? number_format($midPctComputed, 2) : '');
    // Final % (prefer saved grade if there; else computed)
    $finShow = !is_null($saved['final']) ? number_format((float)$saved['final'], 2)
               : (!is_null($finPctComputed) ? number_format($finPctComputed, 2) : '');

    echo "<td>".htmlspecialchars($midShow)."</td>";
    echo "<td>".htmlspecialchars($finShow)."</td>";
    echo "<td>".htmlspecialchars(is_null($finalOverall) ? '' : number_format($finalOverall, 2))."</td>";
    echo "</tr>";
}

echo "</table>";
echo "</body></html>";
