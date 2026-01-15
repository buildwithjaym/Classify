<?php
header('Content-Type: application/json');
session_start();

$bot_name = "ClassiBot AI+";  // Upgraded bot name

// ==============================
// DATABASE CONNECTION
// ==============================
$conn = new mysqli("localhost", "root", "", "classify_db");
if ($conn->connect_error) {
    echo json_encode(["reply" => "⚠️ Database error: ".$conn->connect_error]);
    exit();
}

// ==============================
// GET USER MESSAGE
// ==============================
$message = isset($_POST['message']) ? trim($message = $_POST['message']) : '';
$raw_message = strtolower($message);

if ($message === "") {
    echo json_encode(["reply" => "⚠️ Please type something first."]);
    exit();
}

// ==============================
// UTILITIES
// ==============================

function has($msg, $keywords) {
    foreach ($keywords as $word) {
        if (stripos($msg, $word) !== false) {
            return true;
        }
    }
    return false;
}

function h($str) { return htmlspecialchars($str, ENT_QUOTES); }

function count_table($conn, $table) {
    $res = $conn->query("SELECT COUNT(*) AS total FROM $table");
    return ($res) ? $res->fetch_assoc()['total'] : 0;
}

function recent_rows($conn, $table, $columns, $limit=5, $order='id DESC', $where='') {
    $cols = implode(',', $columns);
    $sql = "SELECT $cols FROM $table";
    if ($where) $sql .= " WHERE $where";
    $sql .= " ORDER BY $order LIMIT $limit";
    $res = $conn->query($sql);

    $data = [];
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) $data[] = $row;
    }
    return $data;
}

// ==============================
// DB FUNCTIONS (same as before)
// ==============================
function fetch_schedule($conn, $faculty_name) {
    $faculty_name = $conn->real_escape_string($faculty_name);
    $sql = "SELECT subject_code, year_level, schedule, time_start, time_end  
            FROM assignments WHERE faculty_name LIKE '%$faculty_name%'";
    $res = $conn->query($sql);

    if (!$res || $res->num_rows == 0) return "❌ No schedules found for <b>".h($faculty_name)."</b>.";

    $reply = "📅 <b>Schedule for ".h($faculty_name)."</b>:<br>";
    while ($row = $res->fetch_assoc()) {
        $reply .= "• {$row['subject_code']} ({$row['year_level']})<br>
                   ⏱ {$row['schedule']} — {$row['time_start']} to {$row['time_end']}<br><br>";
    }
    return $reply;
}

function fetch_subject_students($conn, $subject_code) {
    $rows = recent_rows($conn, 'enrollments', ['student_name'], 200, 'student_name ASC',
        "subject_code LIKE '%".$conn->real_escape_string($subject_code)."%'"
    );

    if (!$rows) return "❌ No students found for <b>".h($subject_code)."</b>.";

    $list = array_map(function($r){ return h($r['student_name']); }, $rows);

    return "👩‍🎓 <b>Students enrolled in $subject_code:</b><br>" . implode(', ', $list);
}

function fetch_faculty_subjects($conn, $faculty_name) {
    $faculty_name = $conn->real_escape_string($faculty_name);
    $res = $conn->query("SELECT DISTINCT subject_code 
                         FROM assignments 
                         WHERE faculty_name LIKE '%$faculty_name%'
                         ORDER BY subject_code ASC");

    if (!$res || $res->num_rows == 0) return "❌ No subjects found for <b>".h($faculty_name)."</b>.";

    $subjects = [];
    while ($r = $res->fetch_assoc()) $subjects[] = h($r['subject_code']);

    return "📚 <b>Subjects taught by ".h($faculty_name)."</b>:<br>" . implode("<br>", $subjects);
}

function fetch_student_schedule($conn, $student_name) {
    $student_name = $conn->real_escape_string($student_name);
    $sql = "SELECT subject_code, schedule, faculty_name 
            FROM enrollments e 
            JOIN assignments a ON e.subject_code = a.subject_code
            WHERE e.student_name LIKE '%$student_name%'
            ORDER BY a.day ASC";
    $res = $conn->query($sql);

    if (!$res || $res->num_rows == 0) return "❌ No schedule found for <b>".h($student_name)."</b>.";

    $reply = "📅 <b>Schedule for ".h($student_name)."</b>:<br>";
    while ($row = $res->fetch_assoc()) {
        $reply .= "• {$row['subject_code']} — {$row['schedule']}<br>
                   👨‍🏫 Instructor: {$row['faculty_name']}<br><br>";
    }
    return $reply;
}

function fetch_cancellations($conn, $faculty='', $date='') {
    $conditions = [];
    if ($faculty) $conditions[] = "faculty_name LIKE '%".$conn->real_escape_string($faculty)."%'";
    if ($date) $conditions[] = "DATE(date) = '".$conn->real_escape_string($date)."'";

    $where = implode(" AND ", $conditions);

    $rows = recent_rows($conn, 'cancellations', ['faculty_name','subject_code','date','reason'], 50, 'date DESC', $where);

    if (!$rows) return "📭 No cancellations found.";

    $reply = "📅 <b>Class Cancellations:</b><br>";
    foreach ($rows as $r) {
        $reply .= "• <b>".h($r['faculty_name'])."</b> — ".h($r['subject_code'])."<br>
                    📅 {$r['date']}<br>
                    📝 Reason: ".h($r['reason'])."<br><br>";
    }
    return $reply;
}

function fetch_logs($conn, $user='') {
    $where = ($user) ? "user_name LIKE '%".$conn->real_escape_string($user)."%'" : '';
    $rows = recent_rows($conn, 'activity_logs', 
        ['user_role','user_name','action','timestamp'], 30, 'id DESC', $where);

    if (!$rows) return "📭 No activity logs available.";

    $reply = "🧾 <b>Recent Activity Logs:</b><br>";
    foreach ($rows as $r) {
        $reply .= "• [{$r['user_role']}] ".h($r['user_name'])."<br>
                    👉 ".h($r['action'])."<br>
                    🕒 {$r['timestamp']}<br><br>";
    }
    return $reply;
}

// ==============================
// ADVANCED INTENT ENGINE
// ==============================

$intent = "";

// GREETINGS
if (has($raw_message, ["hi","hello","hey","greetings","yo"])) {
    $intent = "greet";
}
// STATUS
elseif (has($raw_message, ["status","overview","system","stats"])) {
    $intent = "overview";
}
// FACULTY SCHEDULE
elseif (preg_match('/(schedule|timetable).* of (.+)/i', $message, $m)) {
    $intent = "faculty_schedule";
    $faculty_input = trim($m[2]);
}
// SUBJECT STUDENTS
elseif (preg_match('/(students|enrolled).*(in|of) (.+)/i', $message, $m)) {
    $intent = "subject_students";
    $subject_input = trim($m[3]);
}
// FACULTY SUBJECTS
elseif (preg_match('/(subjects|teaches|handled).* by (.+)/i', $message, $m)) {
    $intent = "faculty_subjects";
    $faculty_input = trim($m[2]);
}
// STUDENT SCHEDULE
elseif (preg_match('/schedule.* student (.+)/i', $message, $m)) {
    $intent = "student_schedule";
    $student_input = trim($m[1]);
}
// CANCELLATIONS
elseif (has($raw_message, ["cancel","cancellation"])) {
    $intent = "cancellations";
}
// LOGS
elseif (has($raw_message, ["log","logs","activities"])) {
    $intent = "logs";
}
// JOKE
elseif (has($raw_message, ["joke","funny","laugh"])) {
    $intent = "joke";
}
// PLAY SONG
elseif (preg_match('/play (.+)/i', $message, $m)) {
    $intent = "play_song";
    $song_name = trim($m[1]);
}
// DEFAULT
else {
    $intent = "unknown";
}

// ==============================
// INTENT RESPONSES
// ==============================

$jokes = [
    "😂 Why don't programmers like nature? It has too many bugs.",
    "🤣 Debugging: Being the detective in a crime movie where you are also the murderer.",
    "😆 My code works… I have no idea why."
];

$songs = [
    "Birds of a Feather" => "Birds of a Feather.mp3",
    "California King Bed" => "Cali.m4a",
    "Lihim by Arthur Miguel" => "Lihim.mp3"
];

switch ($intent) {

    case "greet":
        $reply = "👋 Hello Admin! How can I assist you today?";
        break;

    case "overview":
        $reply = "📊 <b>SYSTEM STATUS</b><br>
        • Faculty: <b>".count_table($conn,'faculty')."</b><br>
        • Students: <b>".count_table($conn,'students')."</b><br>
        • Subjects: <b>".count_table($conn,'subjects')."</b><br>
        • Assignments: <b>".count_table($conn,'assignments')."</b><br>
        • Cancellations: <b>".count_table($conn,'cancellations')."</b>";
        break;

    case "faculty_schedule":
        $reply = fetch_schedule($conn, $faculty_input);
        break;

    case "subject_students":
        $reply = fetch_subject_students($conn, $subject_input);
        break;

    case "faculty_subjects":
        $reply = fetch_faculty_subjects($conn, $faculty_input);
        break;

    case "student_schedule":
        $reply = fetch_student_schedule($conn, $student_input);
        break;

    case "cancellations":
        $reply = fetch_cancellations($conn);
        break;

    case "logs":
        $reply = fetch_logs($conn);
        break;

    case "joke":
        $reply = $jokes[array_rand($jokes)];
        break;

    case "play_song":
        if (isset($songs[$song_name])) {
            $reply = "🎵 Playing <b>$song_name</b><br>
                      👉 <a href='".$songs[$song_name]."' target='_blank'>Click to play</a>";
        } else {
            $reply = "❌ Song not found. Available songs:<br>".implode(", ", array_keys($songs));
        }
        break;

    default:
        $reply = "🤖 I'm not sure what you mean, but I'm getting smarter!
                  Try asking:  
                  schedule of Prof. Santos  
                  students in IT101  
                  system overview  
                  show cancellations";
}

echo json_encode(["reply" => $reply]);
?>
