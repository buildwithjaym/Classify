<?php
session_start();


if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
  header("Location: classify_login.php");
  exit();
}

$conn = new mysqli("localhost", "root", "", "classify_db");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$month = (int) date('n'); // 1-12

// August–December  → 1st Semester
// January–July     → 2nd Semester
if ($month >= 8 && $month <= 12) {
  $currentSemester = '1st Semester';
} else {
  $currentSemester = '2nd Semester';
}
function log_action($conn, $action)
{
  $user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'unknown';
  $user_name = isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'unknown';

  $sql = "INSERT INTO activity_logs (user_role, user_name, action, timestamp) VALUES (?, ?, ?, NOW())";
  $stmt = $conn->prepare($sql);

  if (!$stmt)
    return;

  $stmt->bind_param("sss", $user_role, $user_name, $action);
  $stmt->execute();
  $stmt->close();
}

function upsert_user_for_student($conn, $name, $email)
{
  if (!$email)
    return;

  $role = 'student';
  $pass = password_hash('99', PASSWORD_DEFAULT);

  $sql = "INSERT INTO users (fullname,email,password,role,created_at)
           VALUES (?,?,?,?,NOW())
           ON DUPLICATE KEY UPDATE fullname=VALUES(fullname), role=VALUES(role)";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ssss", $name, $email, $pass, $role);
  $stmt->execute();
  $stmt->close();
}



if (isset($_POST['autoEnrollStudent'])) {

  $student_id = intval($_POST['student_id']);
  $room = $_POST['room'];
  $subject_ids_raw = trim($_POST['subject_ids'], ", ");
  $subject_ids = array_filter(explode(",", $subject_ids_raw));


  $stmt = $conn->prepare("SELECT name, course, photo FROM students WHERE id=?");
  $stmt->bind_param("i", $student_id);
  $stmt->execute();
  $student = $stmt->get_result()->fetch_assoc();

  if (!$student) {
    echo "<script>alert('Student not found!');</script>";
    exit();
  }

  foreach ($subject_ids as $sid) {

    if (!is_numeric($sid))
      continue;


    $stmtSub = $conn->prepare("SELECT id, year_level, Semester FROM subjects WHERE id=?");
    $stmtSub->bind_param("i", $sid);
    $stmtSub->execute();
    $sub = $stmtSub->get_result()->fetch_assoc();

    if (!$sub)
      continue;


    $dup = $conn->prepare("SELECT id FROM enrollments WHERE student_id=? AND subject_id=?");
    $dup->bind_param("ii", $student_id, $sid);
    $dup->execute();

    if ($dup->get_result()->num_rows > 0)
      continue;


    $stmtIns = $conn->prepare("
            INSERT INTO enrollments 
            (student_id, subject_id, student_name, photo, year_level, semester, course, room)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

    $stmtIns->bind_param(
      "iissssss",
      $student_id,
      $sid,
      $student['name'],
      $student['photo'],
      $sub['year_level'],
      $sub['Semester'],
      $student['course'],
      $room
    );

    $stmtIns->execute();
  }

  echo "<script>alert('Successfully enrolled student in ALL subjects!'); window.location='admin_dashboard.php';</script>";
}


if (isset($_POST['add_section'])) {
  $year_level = $_POST['year_level'];
  $section_name = $_POST['section_name'];
  $semester = $_POST['semester'];
  $course = $_POST['course'];
  $faculty_id = !empty($_POST['faculty_id']) ? intval($_POST['faculty_id']) : null;

  $stmt = $conn->prepare("INSERT INTO sections (year_level, section_name, semester, course, faculty_id) VALUES (?, ?, ?, ?, ?)");
  $stmt->bind_param("ssssi", $year_level, $section_name, $semester, $course, $faculty_id);
  $stmt->execute();
  echo "<script>alert('✅ Section added successfully!'); window.location='admin_dashboard.php';</script>";
}


if (isset($_POST['edit_section'])) {
  $id = intval($_POST['id']);
  $year_level = $_POST['year_level'];
  $section_name = $_POST['section_name'];
  $semester = $_POST['semester'];
  $course = $_POST['course'];
  $faculty_id = !empty($_POST['faculty_id']) ? intval($_POST['faculty_id']) : null;

  $stmt = $conn->prepare("UPDATE sections SET year_level=?, section_name=?, semester=?, course=?, faculty_id=? WHERE id=?");
  $stmt->bind_param("ssssii", $year_level, $section_name, $semester, $course, $faculty_id, $id);
  $stmt->execute();
  echo "<script>alert('✅ Section updated successfully!'); window.location='admin_dashboard.php';</script>";
}


if (isset($_GET['delete_section'])) {
  $id = intval($_GET['delete_section']);
  $conn->query("DELETE FROM sections WHERE id=$id");
  echo "<script>alert('❌ Section deleted'); window.location='admin_dashboard.php';</script>";
}
if (isset($_POST['add_assign'])) {
  $faculty_id = intval($_POST['faculty_id']);
  $subject_id = intval($_POST['subject_id']);
  $section_id = !empty($_POST['section_id']) ? intval($_POST['section_id']) : null;
  $schedule = $_POST['schedule']; // description / notes
  $time_start = $_POST['time_start'];
  $time_end = $_POST['time_end'];

  // Raw days array from form
  $daysArray = isset($_POST['days']) && is_array($_POST['days']) ? $_POST['days'] : [];
  $days = !empty($daysArray) ? implode(', ', $daysArray) : '';

  // ✅ Require at least one day
  if (empty($daysArray)) {
    echo "<script>alert('❌ Please select at least one day for this assignment.'); window.location='admin_dashboard.php';</script>";
    exit();
  }

  // ✅ Validate time range
  if (strtotime($time_start) >= strtotime($time_end)) {
    echo "<script>alert('❌ Invalid time range: End time must be after start time.'); window.location='admin_dashboard.php';</script>";
    exit();
  }

  // ✅ CHECK FOR TIME CONFLICTS PER DAY (same faculty, overlapping time)
  foreach ($daysArray as $day) {
    $confSql = "
      SELECT subject_code, days, time_start, time_end
      FROM assignments
      WHERE faculty_id = ?
        AND days LIKE CONCAT('%', ?, '%')
        AND NOT (time_end <= ? OR time_start >= ?)
      LIMIT 1
    ";

    $confStmt = $conn->prepare($confSql);
    if ($confStmt) {
      $confStmt->bind_param("isss", $faculty_id, $day, $time_start, $time_end);
      $confStmt->execute();
      $confRes = $confStmt->get_result();

      if ($confRes && $confRes->num_rows > 0) {
        $row = $confRes->fetch_assoc();
        $confStmt->close();

        // 🛑 Conflict found – stop and warn
        echo "<script>
          alert('⚠️ Time conflict detected for {$day}.\\n\\n'
            + 'Existing: ' + '{$row['subject_code']}' + ' on ' + '{$row['days']}'
            + ' from ' + '{$row['time_start']}' + ' to ' + '{$row['time_end']}'
            + '\\n\\nPlease adjust the days or time for this assignment.');
          window.location='admin_dashboard.php';
        </script>";
        exit();
      }

      $confStmt->close();
    }
  }

  // Fetch faculty name
  $facQuery = $conn->prepare("SELECT name FROM faculty WHERE id = ?");
  $facQuery->bind_param("i", $faculty_id);
  $facQuery->execute();
  $facResult = $facQuery->get_result();
  $facRow = $facResult->fetch_assoc();
  $faculty_name = $facRow ? $facRow['name'] : '';
  $facQuery->close();

  // Fetch subject code + year level from SUBJECTS
  $subQuery = $conn->prepare("SELECT code, year_level FROM subjects WHERE id = ?");
  $subQuery->bind_param("i", $subject_id);
  $subQuery->execute();
  $subResult = $subQuery->get_result();
  $subRow = $subResult->fetch_assoc();
  $subQuery->close();

  $subject_code = $subRow ? $subRow['code'] : '';
  $year_level = $subRow ? $subRow['year_level'] : '';  // 👈 from subjects table

  // ✅ Prevent duplicate assignment per subject
  $check = $conn->prepare("SELECT id FROM assignments WHERE subject_id = ?");
  $check->bind_param("i", $subject_id);
  $check->execute();
  $result = $check->get_result();
  $check->close();

  if ($result->num_rows > 0) {
    echo "<script>alert('⚠️ This subject is already assigned!'); window.location='admin_dashboard.php';</script>";
  } else {
    $stmt = $conn->prepare("INSERT INTO assignments 
      (faculty_id, subject_id, section_id, faculty_name, subject_code, year_level, days, schedule, time_start, time_end) 
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
      "iiisssssss",
      $faculty_id,
      $subject_id,
      $section_id,
      $faculty_name,
      $subject_code,
      $year_level,
      $days,        // comma-separated days: "Mon, Wed"
      $schedule,
      $time_start,
      $time_end
    );

    if ($stmt->execute()) {
      echo "<script>alert('✅ Subject assigned successfully!'); window.location.href = 'admin_dashboard.php';</script>";
    } else {
      echo "<script>alert('❌ Error adding assignment: " . $conn->error . "');</script>";
    }
    $stmt->close();
  }
}


// ✅ Edit Assignment
if (isset($_POST['edit_assign'])) {
  $id = intval($_POST['id']);
  $year_level = $_POST['year_level'];
  $schedule = $_POST['schedule'];
  $time_start = $_POST['time_start'];
  $time_end = $_POST['time_end'];
  $section_id = !empty($_POST['section_id']) ? intval($_POST['section_id']) : null;
  $days = isset($_POST['days']) ? implode(', ', $_POST['days']) : '';

  if (strtotime($time_start) >= strtotime($time_end)) {
    echo "<script>alert('❌ Invalid time range: End time must be after start time.'); window.location='admin_dashboard.php';</script>";
    exit();
  }

  $stmt = $conn->prepare("UPDATE assignments 
    SET year_level=?, section_id=?, days=?, schedule=?, time_start=?, time_end=? 
    WHERE id=?");
  $stmt->bind_param("sissssi", $year_level, $section_id, $days, $schedule, $time_start, $time_end, $id);

  if ($stmt->execute()) {
    echo "<script>alert('✅ Assignment updated successfully!'); window.location='admin_dashboard.php';</script>";
  } else {
    echo "<script>alert('❌ Failed to update assignment: " . $conn->error . "');</script>";
  }
  $stmt->close();
}

// ✅ Delete Assignment
if (isset($_GET['delete_assign'])) {
  $id = intval($_GET['delete_assign']);
  $conn->query("DELETE FROM assignments WHERE id=$id");
  echo "<script>alert('🗑️ Assignment deleted.'); window.location='admin_dashboard.php';</script>";
}

/* ---------- FACULTY ---------- */

// ➕ Add Faculty
if (isset($_POST['add_faculty'])) {
  $name = $_POST['name'];
  $email = $_POST['email'];
  $contact_number = $_POST['contact_number'];
  $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

  $stmt = $conn->prepare("INSERT INTO faculty (name, email, contact_number, password) VALUES (?, ?, ?, ?)");
  $stmt->bind_param("ssss", $name, $email, $contact_number, $password);

  if ($stmt->execute()) {
    log_action($conn, "Added faculty: $name ($email)");
    echo "<script>alert('✅ Faculty added successfully!'); window.location='admin_dashboard.php';</script>";
  } else {
    echo "<script>alert('❌ Failed to add faculty: " . $conn->error . "');</script>";
  }
  $stmt->close();
}

// ✏️ Edit Faculty
if (isset($_POST['edit_faculty'])) {
  $id = intval($_POST['id']);
  $name = $_POST['name'];
  $email = $_POST['email'];
  $contact_number = $_POST['contact_number'];

  $stmt = $conn->prepare("UPDATE faculty SET name=?, email=?, contact_number=? WHERE id=?");
  $stmt->bind_param("sssi", $name, $email, $contact_number, $id);

  if ($stmt->execute()) {
    log_action($conn, "Edited faculty: $name ($email)");
    echo "<script>alert('✅ Faculty updated successfully!'); window.location='admin_dashboard.php';</script>";
  } else {
    echo "<script>alert('❌ Failed to update faculty: " . $conn->error . "');</script>";
  }
  $stmt->close();
}

// ❌ Delete Faculty
if (isset($_GET['delete_faculty'])) {
  $id = intval($_GET['delete_faculty']);
  $res = $conn->query("SELECT name, email FROM faculty WHERE id=$id");
  $info = $res->fetch_assoc();
  $conn->query("DELETE FROM faculty WHERE id=$id");

  log_action($conn, "Deleted faculty: {$info['name']} ({$info['email']})");
  echo "<script>alert('❌ Faculty deleted'); window.location='admin_dashboard.php';</script>";
}



if (isset($_POST['add_student'])) {
  $student_id = $_POST['student_id'];
  $name = $_POST['name'];
  $gender = $_POST['gender'];
  $course = $_POST['course'];
  $year_level = $_POST['year_level'];
  $email = $_POST['email'];
  $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

  $photo = "default.png";
  if (!empty($_FILES['photo']['name'])) {
    $file = $_FILES['photo'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) && $file['size'] <= 5 * 1024 * 1024) {
      $photo = uniqid() . "." . $ext;
      move_uploaded_file($file['tmp_name'], "uploads/students/" . $photo);
    }
  }

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("
      INSERT INTO students (student_id, name, gender, course, year_level, email, password, photo)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssssss", $student_id, $name, $gender, $course, $year_level, $email, $password, $photo);
    $stmt->execute();
    $stmt->close();

    upsert_user_for_student($conn, $name, $email);

    $conn->commit();
    echo "<script>alert('Student added successfully!'); window.location='admin_dashboard.php';</script>";
  } catch (Throwable $e) {
    $conn->rollback();
    echo "<script>alert('Error adding student');</script>";
  }
}

if (isset($_POST['edit_student'])) {
  $id = $_POST['id'];
  $student_id = $_POST['student_id'];
  $name = $_POST['name'];
  $gender = $_POST['gender'];
  $course = $_POST['course'];
  $year_level = $_POST['year_level'];
  $email = $_POST['email'];

  $oldRow = $conn->query("SELECT email, photo FROM students WHERE id=" . (int) $id)->fetch_assoc();
  $oldEmail = $oldRow ? $oldRow['email'] : '';
  $photo = $oldRow ? $oldRow['photo'] : 'default.png';

  if (!empty($_FILES['photo']['name'])) {
    $file = $_FILES['photo'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) && $file['size'] <= 5 * 1024 * 1024) {
      $photo = uniqid() . "." . $ext;
      move_uploaded_file($file['tmp_name'], "uploads/students/" . $photo);
    }
  }

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("
      UPDATE students
      SET student_id=?, name=?, gender=?, course=?, year_level=?, email=?, photo=?
      WHERE id=?
    ");
    $stmt->bind_param("sssssssi", $student_id, $name, $gender, $course, $year_level, $email, $photo, $id);
    $stmt->execute();
    $stmt->close();

    upsert_user_for_student($conn, $name, $email);

    if ($oldEmail !== $email && $oldEmail !== '') {
      $fix = $conn->prepare("UPDATE users SET email=? WHERE email=? AND role='student'");
      $fix->bind_param("ss", $email, $oldEmail);
      $fix->execute();
      $fix->close();
    }

    $conn->commit();
    echo "<script>alert('Student updated successfully!'); window.location='admin_dashboard.php';</script>";
  } catch (Throwable $e) {
    $conn->rollback();
    echo "<script>alert('Error updating student');</script>";
  }
}

if (isset($_GET['delete_student'])) {
  $id = (int) $_GET['delete_student'];
  $row = $conn->query("SELECT email FROM students WHERE id={$id}")->fetch_assoc();
  $email = $row ? $row['email'] : '';

  $conn->begin_transaction();
  try {
    $conn->query("DELETE FROM students WHERE id={$id}");
    if ($email !== '') {
      $del = $conn->prepare("DELETE FROM users WHERE email=? AND role='student'");
      $del->bind_param("s", $email);
      $del->execute();
      $del->close();
    }
    $conn->commit();
    echo "<script>alert('Student deleted'); window.location='admin_dashboard.php';</script>";
  } catch (Throwable $e) {
    $conn->rollback();
    echo "<script>alert('Error deleting student');</script>";
  }
}

/* ---------- SUBJECTS ---------- */

// ➕ Add Subject
if (isset($_POST['add_subject'])) {
  $code = $_POST['code'];
  $name = $_POST['name'];
  $units = $_POST['units'];
  $year_level = $_POST['year_level'];
  $Semester = $_POST['Semester'];

  $stmt = $conn->prepare("INSERT INTO subjects (code, name, units, year_level, Semester) VALUES (?, ?, ?, ?, ?)");
  $stmt->bind_param("ssssi", $code, $name, $units, $year_level, $Semester);

  if ($stmt->execute()) {
    log_action($conn, "Added Subject: $name ($code)");
    echo "<script>
      const audio = new Audio('beep.mp3');
      audio.play();
      alert('✅ Subject added successfully!');
      window.location='admin_dashboard.php';
    </script>";
  } else {
    echo "<script>alert('❌ Error: Failed to add subject.');</script>";
  }
  $stmt->close();
}

// ✏️ Edit Subject
if (isset($_POST['edit_subject'])) {
  $id = intval($_POST['id']);
  $code = $_POST['code'];
  $name = $_POST['name'];
  $units = $_POST['units'];
  $year_level = $_POST['year_level'];
  $Semester = $_POST['Semester'];

  $stmt = $conn->prepare("UPDATE subjects SET code=?, name=?, units=?, year_level=?, Semester=? WHERE id=?");
  $stmt->bind_param("ssssii", $code, $name, $units, $year_level, $Semester, $id);

  if ($stmt->execute()) {
    log_action($conn, "Edited Subject: $name ($code)");
    echo "<script>
      const audio = new Audio('beep.mp3');
      audio.play();
      alert('✅ Subject updated successfully!');
      window.location='admin_dashboard.php';
    </script>";
  } else {
    echo "<script>alert('❌ Failed to update subject: " . $conn->error . "');</script>";
  }
  $stmt->close();
}

// ❌ Delete Subject
if (isset($_GET['delete_subject'])) {
  $id = intval($_GET['delete_subject']);

  $res = $conn->query("SELECT code, name, Semester, id FROM subjects WHERE id=$id");
  $info = $res->fetch_assoc();
  $conn->query("DELETE FROM subjects WHERE id=$id");

  log_action($conn, "Deleted Subject: {$info['code']} ({$info['name']})");
  echo "<script>alert('❌ Subject deleted'); window.location='admin_dashboard.php';</script>";
}

/* ---------- ENROLLMENTS ---------- */

// 🧾 Enroll Student in a Subject
if (isset($_POST['enrollStudent'])) {
  $student_id = intval($_POST['student_id']);
  $subject_id = intval($_POST['subject_id']);
  $year_level = $_POST['year_level'];
  $semester = $_POST['semester'];
  $course = $_POST['course'];
  $section = !empty($_POST['section']) ? $_POST['section'] : null;
  $room = !empty($_POST['room']) ? $_POST['room'] : null;

  // Get student name
  $stuRes = $conn->query("SELECT name FROM students WHERE id = $student_id");
  $stuRow = $stuRes->fetch_assoc();
  $student_name = $stuRow ? $stuRow['name'] : '';

  // Prevent duplicate enrollment (same student, subject, semester)
  $check = $conn->prepare("SELECT id FROM enrollments WHERE student_id=? AND subject_id=? AND semester=?");
  $check->bind_param("iis", $student_id, $subject_id, $semester);
  $check->execute();
  $resChk = $check->get_result();

  if ($resChk->num_rows > 0) {
    echo "<script>alert('⚠️ Student is already enrolled in this subject for this semester.'); window.location='admin_dashboard.php';</script>";
  } else {
    $stmt = $conn->prepare("INSERT INTO enrollments 
      (student_id, subject_id, student_name, year_level, semester, course, section, room) 
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
      "iissssss",
      $student_id,
      $subject_id,
      $student_name,
      $year_level,
      $semester,
      $course,
      $section,
      $room
    );

    if ($stmt->execute()) {
      echo "<script>alert('✅ Student enrolled successfully!'); window.location='admin_dashboard.php';</script>";
    } else {
      echo "<script>alert('❌ Failed to enroll student: " . $conn->error . "');</script>";
    }
    $stmt->close();
  }
}

/* ===========================
   DASHBOARD DATA
=========================== */
$totalFaculty = $conn->query("SELECT COUNT(*) AS total FROM faculty")->fetch_assoc()['total'];
$totalStudents = $conn->query("SELECT COUNT(*) AS total FROM students")->fetch_assoc()['total'];
$totalSubjects = $conn->query("SELECT COUNT(*) AS total FROM subjects")->fetch_assoc()['total'];
$totalCancellations = $conn->query("SELECT COUNT(*) AS total FROM cancellations")->fetch_assoc()['total'];
$totalUnits = $conn->query("SELECT SUM(units) AS unit FROM subjects")->fetch_assoc()['unit'];

// Monthly Cancellations (DB Based)
$monthlyData = [];
for ($i = 1; $i <= 12; $i++) {
  $result = $conn->query("SELECT COUNT(*) AS total FROM cancellations WHERE MONTH(date)=$i");
  $count = $result->fetch_assoc()['total'];
  $monthlyData[] = $count;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CLASSIFY | Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <!-- jsPDF and autoTable libraries -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

  <style>
    :root {
      --bg-gradient: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
      --glass-bg: rgba(255, 255, 255, 0.07);
      --glass-strong: rgba(255, 255, 255, 0.15);
      --accent-primary: #00c6ff;
      --accent-secondary: #0072ff;
      --danger: #e63946;
      --text-main: #ffffff;
      --text-muted: #d0d7e2;
      --card-shadow: 0 4px 15px rgba(0, 0, 0, 0.25);
      --radius-lg: 15px;
      --radius-xl: 20px;
      --transition-fast: 0.25s ease;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html,
    body {
      height: 100%;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: var(--bg-gradient);
      color: var(--text-main);
      display: flex;
      overflow-x: hidden;
    }

    /* ========= LAYOUT ========= */
    .sidebar {
      width: 260px;
      background: var(--glass-bg);
      backdrop-filter: blur(25px);
      display: flex;
      flex-direction: column;
      padding: 25px 15px;
      z-index: 3;
      position: relative;
      border-right: 1px solid rgba(255, 255, 255, 0.15);
      flex-shrink: 0;
    }

    .sidebar h2 {
      text-align: center;
      margin-bottom: 40px;
      font-weight: 700;
      color: #fff;
    }

    .sidebar a {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px 15px;
      margin: 6px 0;
      color: white;
      text-decoration: none;
      border-radius: 10px;
      transition: background .3s, transform .3s;
      font-size: 0.95rem;
    }

    .sidebar a i {
      font-size: 1.2rem;
    }

    .sidebar a:hover,
    .sidebar a.active {
      background: rgba(255, 255, 255, 0.25);
      transform: translateX(4px);
    }

    .main {
      flex: 1;
      padding: 20px 20px 30px;
      overflow-y: auto;
      position: relative;
      z-index: 2;
      scroll-behavior: smooth;
    }

    /* mobile toggle */
    .mobile-toggle {
      display: none;
      border: none;
      background: linear-gradient(135deg, var(--accent-secondary), var(--accent-primary));
      color: #fff;
      padding: 8px 12px;
      border-radius: 999px;
      cursor: pointer;
      margin-bottom: 15px;
      align-items: center;
      gap: 6px;
      box-shadow: var(--card-shadow);
    }

    .mobile-toggle i {
      font-size: 1.2rem;
    }

    /* ========= SECTIONS ========= */
    .section {
      animation: fadeIn .6s ease;
      margin-bottom: 35px;
    }

    .section h1,
    .section h2 {
      margin-bottom: 10px;
    }

    .section p {
      color: var(--text-muted);
      margin-bottom: 18px;
    }

    /* ========= BUTTONS ========= */
    .btn {
      background: linear-gradient(135deg, var(--accent-secondary), var(--accent-primary));
      border: none;
      padding: 10px 18px;
      color: white;
      border-radius: 8px;
      cursor: pointer;
      transition: var(--transition-fast);
      font-size: 0.9rem;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
    }

    .btn:hover {
      transform: translateY(-1px) scale(1.03);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35);
    }

    .btn.delete-btn {
      background: var(--danger);
    }

    .btn.cancel {
      background: linear-gradient(135deg, #ff4e50, #f9d423);
    }

    /* ========= DASHBOARD CARDS ========= */
    .dashboard-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px;
      margin-top: 20px;
    }

    .card {
      padding: 25px;
      border-radius: var(--radius-lg);
      text-align: center;
      transition: .3s;
      box-shadow: var(--card-shadow);
      color: #fff;
    }

    .card i {
      font-size: 40px;
      margin-bottom: 10px;
    }

    .card:nth-child(1) {
      background: linear-gradient(135deg, #1e3c72, #2a5298);
    }

    .card:nth-child(2) {
      background: linear-gradient(135deg, #11998e, #38ef7d);
    }

    .card:nth-child(3) {
      background: linear-gradient(135deg, #2c3e50, #4ca1af);
    }

    .card:nth-child(4) {
      background: linear-gradient(135deg, #8E0E00, #E52D27);
    }

    .card:hover {
      transform: translateY(-6px);
    }

    /* ========= TABLES ========= */
    .table-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 10px 0 15px;
    }

    .table-container {
      width: 100%;
      overflow-x: auto;
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.05);
      backdrop-filter: blur(10px);
      padding: 10px;
    }

    .table-container::-webkit-scrollbar {
      height: 6px;
    }

    .table-container::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.4);
      border-radius: 999px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 650px;
      text-align: center;
    }

    th,
    td {
      padding: 10px 14px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.25);
      color: #f0f0f0;
      font-size: 0.85rem;
    }

    th {
      background: rgba(255, 255, 255, 0.2);
      font-weight: 600;
    }

    tr:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    /* Standalone data-table (sections) */
    .data-table {
      width: 100%;
      border-collapse: collapse;
      min-width: 650px;
    }

    .data-table th,
    .data-table td {
      padding: 10px 14px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    }

    /* ========= FORMS (Glassmorphism) ========= */


    .days-container {
      display: grid;
      grid-template-columns: repeat(4, auto);
      gap: 10px 18px;
      margin: 10px 0;
      padding: 10px;
      background: rgba(255, 255, 255, 0.08);
      border-radius: 10px;
    }

    .days-container label {
      color: #fff;
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      gap: 6px;
    }


    .student-thumb {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid rgba(255, 255, 255, 0.4);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
    }


    .days-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
      margin: 10px 0;
    }

    .day-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.9rem;
      background: rgba(255, 255, 255, 0.12);
      padding: 6px 10px;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, 0.25);
      backdrop-filter: blur(10px);
    }



    /* FIX CHECKBOX BACKGROUND ISSUE */
    input[type="checkbox"] {
      appearance: none;
      -webkit-appearance: none;
      width: 18px;
      height: 18px;
      border: 2px solid #fff;
      border-radius: 4px;
      background: transparent !important;
      display: inline-block;
      position: relative;
      cursor: pointer;
    }

    input[type="checkbox"]:checked {
      background: #00c6ff !important;
      border-color: #00c6ff;
    }

    input[type="checkbox"]::before {
      content: "";
      position: absolute;
      top: 2px;
      left: 5px;
      width: 4px;
      height: 9px;
      border: solid #fff;
      border-width: 0 2px 2px 0;
      transform: rotate(45deg);
      opacity: 0;
    }

    input[type="checkbox"]:checked::before {
      opacity: 1;
    }

    input,
    select {
      width: 100%;
      padding: 10px;
      margin: 8px 0;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, 0.35);
      background: rgba(255, 255, 255, 0.12);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      color: #ffffff;
      outline: none;
      font-size: 0.9rem;
      transition: background .25s ease, box-shadow .25s ease, border-color .25s ease, transform .15s ease;
    }

    input::placeholder {
      color: rgba(255, 255, 255, 0.75);
    }

    input:focus,
    select:focus {
      background: rgba(255, 255, 255, 0.20);
      border-color: rgba(0, 198, 255, 0.7);
      box-shadow: 0 0 12px rgba(0, 198, 255, 0.5);
      transform: translateY(-1px);
    }

    select {
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      background-image:
        linear-gradient(45deg, transparent 50%, rgba(255, 255, 255, 0.9) 50%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.9) 50%, transparent 50%);
      background-position:
        calc(100% - 18px) calc(50% - 3px),
        calc(100% - 12px) calc(50% - 3px);
      background-size: 6px 6px, 6px 6px;
      background-repeat: no-repeat;
      padding-right: 30px;
    }

    select::-ms-expand {
      display: none;
    }

    .assign-form label {
      display: block;
      margin-top: 12px;
      margin-bottom: 5px;
      font-weight: 500;
      color: #dce3ff;
      letter-spacing: .5px;
    }

    .assign-form select,
    .assign-form input[type="text"],
    .assign-form input[type="time"] {
      background: rgba(255, 255, 255, 0.16);
      border: 1px solid rgba(255, 255, 255, 0.3);
    }

    select option,
    .assign-form select option {
      background: rgba(10, 20, 35, 0.95);
      color: #ffffff;
    }

    .assign-form select option[disabled] {
      background-color: rgba(255, 0, 0, 0.55);
      color: #fff;
      font-style: italic;
    }

    .time-container {
      display: flex;
      gap: 10px;
      margin-top: 10px;
    }

    .form-buttons {
      display: flex;
      justify-content: space-between;
      margin-top: 20px;
    }

    /* ========= SEARCH INPUT ========= */
    .search-container {
      margin: 10px 0 5px;
    }

    .search-input {
      width: 260px;
      max-width: 100%;
      padding: 10px 14px;
      border-radius: 10px;
      border: none;
      outline: none;
      font-size: 0.9rem;
      background: rgba(255, 255, 255, 0.15);
      color: #fff;
      transition: .3s ease;
      box-shadow: 0 0 0px rgba(0, 198, 255, 0.4);
    }

    .search-input::placeholder {
      color: #d3d3d3;
      letter-spacing: .5px;
    }

    .search-input:focus {
      background: rgba(255, 255, 255, 0.25);
      box-shadow: 0 0 12px rgba(0, 198, 255, 0.6);
      animation: glowPulse 1.5s infinite alternate;
    }

    /* ========= CHARTS ========= */
    .chart-container {
      margin-top: 30px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: var(--radius-xl);
      padding: 20px;
      backdrop-filter: blur(20px);
      position: relative;
      overflow: hidden;
    }

    .chart-container h3 {
      text-align: center;
      margin-bottom: 15px;
      color: #fff;
    }

    .chart-container canvas {
      width: 100%;
      height: 360px;
    }

    /* ========= MODALS ========= */
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.6);
      justify-content: center;
      align-items: center;
      z-index: 20;
      padding: 10px;
    }

    .modal-content {
      background: rgba(255, 255, 255, 0.1);
      padding: 20px 22px;
      border-radius: var(--radius-lg);
      width: min(420px, 95vw);
      max-height: 90vh;
      overflow-y: auto;
      backdrop-filter: blur(15px);
    }

    .beautiful-modal {
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.2);
      box-shadow: 0 4px 25px rgba(0, 0, 0, 0.3);
      padding: 26px;
      border-radius: 18px;
    }

    /* ========= PARTICLES ========= */
    canvas.particles {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: 0;
      pointer-events: none;
    }

    /* ========= CHATBOT ========= */
    #chatbot-icon {
      position: fixed;
      bottom: 20px;
      right: 20px;
      width: 56px;
      height: 56px;
      background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
      color: white;
      font-size: 26px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      cursor: pointer;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
      transition: transform .3s, box-shadow .3s;
      z-index: 999;
    }

    #chatbot-icon:hover {
      transform: scale(1.1);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
    }

    #chatbot-window {
      position: fixed;
      bottom: 90px;
      right: 20px;
      width: 320px;
      max-width: 90vw;
      background: rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(20px);
      border-radius: 15px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
      overflow: hidden;
      display: none;
      flex-direction: column;
      z-index: 999;
      animation: fadeIn .3s ease;
    }

    #chatbot-header {
      cursor: move;
      background: linear-gradient(135deg, var(--accent-secondary), var(--accent-primary));
      color: white;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 10px;
      font-weight: bold;
      user-select: none;
      font-size: 0.9rem;
    }

    #chatbot-header button {
      background: none;
      border: none;
      color: white;
      font-size: 18px;
      cursor: pointer;
    }

    #chatbot-messages {
      max-height: 270px;
      overflow-y: auto;
      padding: 8px;
    }

    .chatbot-msg {
      margin: 6px 0;
      padding: 8px 11px;
      border-radius: 10px;
      max-width: 80%;
      word-wrap: break-word;
      font-size: 0.85rem;
    }

    .chatbot-user {
      background: var(--accent-secondary);
      color: white;
      margin-left: auto;
    }

    .chatbot-bot {
      background: rgba(255, 255, 255, 0.2);
      color: #fff;
    }

    #chatbot-input-area {
      display: flex;
      border-top: 1px solid rgba(255, 255, 255, 0.2);
    }

    #chatbot-input {
      flex: 1;
      padding: 9px 10px;
      border: none;
      background: transparent;
      color: white;
      outline: none;
      font-size: 0.85rem;
    }

    #chatbot-send {
      background: var(--accent-primary);
      border: none;
      color: white;
      padding: 8px 12px;
      cursor: pointer;
      transition: .3s;
      font-size: 0.9rem;
    }

    #chatbot-send:hover {
      background: var(--accent-secondary);
    }

    /* ========= ANIMATIONS ========= */
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(10px);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    @keyframes glowPulse {
      from {
        box-shadow: 0 0 8px rgba(0, 198, 255, 0.5);
      }

      to {
        box-shadow: 0 0 15px rgba(0, 198, 255, 0.9);
      }
    }

    /* ========= RESPONSIVE ========= */
    @media (max-width:1024px) {
      .chart-container canvas {
        height: 300px;
      }
    }

    @media (max-width:900px) {
      body {
        flex-direction: column;
      }

      .sidebar {
        position: fixed;
        top: 0;
        left: -260px;
        height: 100vh;
        z-index: 30;
        transition: left .3s ease;
      }

      .sidebar.open {
        left: 0;
      }

      .main {
        padding: 15px 14px 30px;
        margin-left: 0;
      }

      .mobile-toggle {
        display: inline-flex;
      }
    }

    @media (max-width:768px) {
      .dashboard-cards {
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      }

      table,
      .data-table {
        min-width: 520px;
        font-size: 0.78rem;
      }

      th,
      td {
        padding: 8px 10px;
      }

      .time-container {
        flex-direction: column;
      }
    }

    @media (max-width:480px) {
      .card {
        padding: 18px;
      }

      #chatbot-window {
        right: 10px;
        bottom: 80px;
        width: 92vw;
      }

      #chatbot-icon {
        bottom: 14px;
        right: 14px;
      }
    }
  </style>
</head>

<body>
  <canvas class="particles"></canvas>

  <div class="sidebar">
    <h2>CLASSIFY</h2>
    <a href="#" class="active" onclick="showSection('dashboard', event)"><i class="ri-dashboard-fill"></i>
      <span>Dashboard</span></a>
    <a href="#" onclick="showSection('faculty', event)"><i class="ri-user-2-fill"></i> <span>Manage Faculty</span></a>
    <a href="#" onclick="showSection('students', event)"><i class="ri-user-fill"></i> <span>Manage Students</span></a>
    <a href="#" onclick="showSection('enrollments', event)"><i class="ri-clipboard-fill"></i>
      <span>Enrollments</span></a>
    <a href="#" onclick="showSection('subjects', event)"><i class="ri-book-2-fill"></i> <span>Manage Subjects</span></a>
    <a href="#" onclick="showSection('Assigned', event)"><i class="ri-user-4-fill"></i> <span>Assign Faculty</span></a>
    <a href="#" onclick="showSection('cancellations', event)"><i class="ri-calendar-close-fill"></i>
      <span>Cancellations</span></a>
    <a href="#" onclick="showSection('sections', event)"><i class="ri-dashboard-fill"></i> <span>Manage
        Sections</span></a>
    <a href="#" onclick="showSection('View', event)"><i class="ri-user-5-fill"></i> <span>View Logs</span></a>
    <a href="logout.php"><i class="ri-logout-box-fill"></i> <span>Logout</span></a>
  </div>

  <div class="main">
    <button class="mobile-toggle" onclick="toggleSidebar()">
      <i class="ri-menu-fill"></i> Menu
    </button>

    <!-- DASHBOARD -->
    <section id="dashboard" class="section">
      <h1>Welcome, Admin 👋</h1>
      <p>Here’s your real-time overview of the system.</p>

      <div class="dashboard-cards">
        <div class="card"><i class="ri-user-2-fill"></i>
          <h3>Faculty</h3>
          <p><?= $totalFaculty ?> Total</p>
        </div>
        <div class="card"><i class="ri-user-fill"></i>
          <h3>Students</h3>
          <p><?= $totalStudents ?> Total</p>
        </div>
        <div class="card"><i class="ri-book-2-fill"></i>
          <h3>Subjects</h3>
          <p><?= $totalSubjects ?> Total</p>
        </div>
        <div class="card"><i class="ri-calendar-close-fill"></i>
          <h3>Cancellations</h3>
          <p><?= $totalCancellations ?> Total</p>
        </div>
      </div>

      <div class="chart-container">
        <h3>System Overview</h3>
        <canvas id="overviewChart"></canvas>
      </div>

      <div class="chart-container">
        <h3>Monthly Class Cancellations Trend</h3>
        <canvas id="barChart"></canvas>
      </div>
    </section>

    <!-- Assigned -->
    <section id="Assigned" class="section" style="display:none;">
      <h2>Faculty Assignments</h2>
      <div class="table-actions">
        <button class="btn" onclick="openModal('addAssign')">+ Assign</button>
      </div>

      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Faculty Name</th>
              <th>Subject</th>
              <th>Year Level</th>
              <th>Days</th>
              <th>Schedule</th>
              <th>Time Start</th>
              <th>Time End</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $query = "
          SELECT a.id, a.year_level, a.schedule, a.time_start, a.time_end, a.days, a.section_id,
                 f.name AS faculty_name, 
                 s.code AS subject_code, s.name AS subject_name
          FROM assignments a
          JOIN faculty f ON a.faculty_id = f.id
          JOIN subjects s ON a.subject_id = s.id
          ORDER BY a.id DESC";
            $res = $conn->query($query);
            while ($r = $res->fetch_assoc()) {
              $daysValue = isset($r['days']) ? $r['days'] : '';
              $daysJs = htmlspecialchars($daysValue, ENT_QUOTES, 'UTF-8');
              $sectionId = isset($r['section_id']) && $r['section_id'] !== null ? (int) $r['section_id'] : 0;
              $yearJs = htmlspecialchars($r['year_level'], ENT_QUOTES, 'UTF-8');
              $schedJs = htmlspecialchars($r['schedule'], ENT_QUOTES, 'UTF-8');
              $startJs = htmlspecialchars($r['time_start'], ENT_QUOTES, 'UTF-8');
              $endJs = htmlspecialchars($r['time_end'], ENT_QUOTES, 'UTF-8');

              echo "<tr>
            <td>{$r['id']}</td>
            <td>{$r['faculty_name']}</td>
            <td>{$r['subject_code']} - {$r['subject_name']}</td>
            <td>{$r['year_level']}</td>
            <td>{$r['days']}</td>
            <td>{$r['schedule']}</td>
            <td>{$r['time_start']}</td>
            <td>{$r['time_end']}</td>
            <td>
              <button class='btn' onclick=\"edit_assign(
                {$r['id']},
                '{$yearJs}',
                '{$schedJs}',
                '{$startJs}',
                '{$endJs}',
                '{$daysJs}',
                {$sectionId}
              )\">Edit</button>
              <button class='btn delete-btn' onclick=\"confirmDelete('?delete_assign={$r['id']}')\">Delete</button>
            </td>
          </tr>";
            }
            ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- View Reports and Logs -->
    <section id="View" class="section" style="display:none;">
      <h2>View Reports and Logs</h2>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Role</th>
              <th>Name</th>
              <th>Action</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $res = $conn->query("SELECT * FROM activity_logs ORDER BY timestamp DESC");
            while ($r = $res->fetch_assoc()) {
              echo "<tr>
            <td>{$r['id']}</td>
            <td>{$r['user_role']}</td>
            <td>{$r['user_name']}</td>
            <td>{$r['action']}</td>
            <td>{$r['timestamp']}</td>
          </tr>";
            }
            ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Faculty -->
    <section id="faculty" class="section" style="display:none;">
      <h2>Faculty List</h2>

      <div class="search-container">
        <input type="text" id="facultySearch" class="search-input" placeholder="🔍 Search faculty...">
      </div>

      <div class="table-actions">
        <button class="btn" onclick="openModal('addFaculty')">+ Add Faculty</button>
        <button id="exportFaculty" class="btn">
          <i class="ri-file-download-line"></i> Export as PDF
        </button>
      </div>

      <div class="table-container" id="facultyTableWrapper">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Email</th>
              <th>Contact Number</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $res = $conn->query("SELECT * FROM faculty");
            while ($r = $res->fetch_assoc()) {
              $nameJs = htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8');
              $emailJs = htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8');
              $contactJs = htmlspecialchars($r['contact_number'], ENT_QUOTES, 'UTF-8');
              echo "<tr>
            <td>{$r['id']}</td>
            <td>{$r['name']}</td>
            <td>{$r['email']}</td>
            <td>{$r['contact_number']}</td>
            <td>
  <button class='btn' onclick=\"editFaculty({$r['id']}, '{$nameJs}', '{$emailJs}', '{$contactJs}')\">Edit</button>
  <a class='btn' href='view_assignment.php?faculty_id={$r['id']}'>View</a>
  <button class='btn delete-btn' onclick=\"confirmDelete('?delete_faculty={$r['id']}')\">Delete</button>
</td>

          </tr>";
            }
            ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ===================== ENROLLMENTS SECTION ===================== -->
    <section id="enrollments" class="section" style="display:none;">
      <h2>Manage Enrollments</h2>

      <div class="form-container">
        <form method="POST" action="">

          <div class="form-group">
            <label>Student</label>
            <select name="student_id" id="studentSelect" required>
              <option value="">Select Student</option>
              <?php
              $students = $conn->query("
  SELECT 
    s.id,
    s.name,
    s.course,
    s.year_level      AS base_year_level,
    e.year_level      AS current_year_level,
    e.semester        AS current_semester,
    (
      SELECT COUNT(*)
      FROM enrollments e2
      WHERE e2.student_id = s.id
    ) AS enrolled_count
  FROM students s
  LEFT JOIN enrollments e
    ON e.id = (
      SELECT e3.id
      FROM enrollments e3
      WHERE e3.student_id = s.id
      ORDER BY e3.id DESC
      LIMIT 1
    )
  ORDER BY s.name ASC
");

              while ($s = $students->fetch_assoc()):
                $name = htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8');
                $course = htmlspecialchars($s['course'], ENT_QUOTES, 'UTF-8');

                $status = ($s['enrolled_count'] > 0) ? " (Already Enrolled)" : "";
                $style = ($s['enrolled_count'] > 0) ? "style='color:#72e572;font-weight:bold;'" : "";

                // Use enrollment year_level if available, otherwise student.year_level
                $rawYear = !empty($s['current_year_level'])
                  ? $s['current_year_level']
                  : (!empty($s['base_year_level']) ? $s['base_year_level'] : '');

                $currYear = htmlspecialchars($rawYear, ENT_QUOTES, 'UTF-8');

                $currSem = isset($s['current_semester'])
                  ? htmlspecialchars($s['current_semester'], ENT_QUOTES, 'UTF-8')
                  : '';
                ?>
                <option value="<?= $s['id'] ?>" data-course="<?= $course ?>" data-year="<?= $currYear ?>"
                  data-semester="<?= $currSem ?>" <?= $style ?>>
                  <?= $name ?> (<?= $course ?>) <?= $status ?>
                </option>
              <?php endwhile; ?>

            </select>
          </div>

          <div class="form-group">
            <label>Course</label>
            <input type="text" name="course" id="courseInput" readonly>
          </div>

          <div class="form-row" style="display:flex;gap:12px;flex-wrap:wrap;">
            <div>
              <label>Year Level</label>
              <select name="year_level" id="yearSelect" required>
                <option value="">Select Year</option>
                <option>1st Year</option>
                <option>2nd Year</option>
                <option>3rd Year</option>
                <option>4th Year</option>
              </select>
            </div>

            <div>
              <label>Semester</label>
              <select name="semester" id="semesterSelect" required>
                <option value="">Select Semester</option>
                <option>1st Semester</option>
                <option>2nd Semester</option>
                <option>Summer</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label>Subjects to Enroll Automatically:</label>
            <ul id="subjectsListAuto" style="margin-left:20px;color:white;font-size:0.9rem;"></ul>
          </div>

          <input type="hidden" name="subject_ids" id="subject_ids_auto">

          <div class="form-group">
            <label>Room</label>
            <input type="text" name="room" placeholder="e.g. RM-203" required>
          </div>

          <button class="btn" type="submit" name="autoEnrollStudent">
            Enroll Student in ALL Subjects
          </button>

        </form>
      </div>
    </section>



    <!-- Students -->
    <!-- STUDENTS SECTION -->
    <section id="students" class="section" style="display:none;">
      <h2>Students List</h2>

      <div class="search-container">
        <input type="text" id="studentSearch" class="search-input" placeholder="🔍 Search student...">
      </div>

      <div class="table-actions">
        <button class="btn" onclick="openModal('addStudent')">+ Add Student</button>
        <button id="exportStudents" class="btn">
          <i class="ri-file-download-line"></i> Export as PDF
        </button>
      </div>

      <div class="table-container" id="studentsTableWrapper">
        <table>
          <thead>
            <tr>
              <th>Photo</th>
              <th>Student ID</th>
              <th>Name</th>
              <th>Gender</th>
              <th>Course</th>
              <th>Year Level</th>
              <th>Email</th>
              <th>Action</th>
            </tr>
          </thead>

          <tbody>
            <?php
            $res = $conn->query("SELECT * FROM students ORDER BY name ASC");
            while ($r = $res->fetch_assoc()):
              $photo = !empty($r['photo']) ? 'uploads/students/' . $r['photo'] : 'uploads/students/default.png';
              ?>
              <tr>
                <td>
                  <img src="<?= $photo ?>" class="student-thumb">
                </td>
                <td class="hidden-db-id" style="display:none;"><?= $r['id'] ?></td>
                <td><?= $r['student_id'] ?></td>
                <td><?= $r['name'] ?></td>
                <td><?= $r['gender'] ?></td>
                <td><?= $r['course'] ?></td>
                <td><?= $r['year_level'] ?></td>
                <td><?= $r['email'] ?></td>

                <td>
                  <button class="btn" onclick="edit_student(
                '<?= $r['id'] ?>',
                '<?= htmlspecialchars($r['student_id'], ENT_QUOTES) ?>',
                '<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>',
                '<?= htmlspecialchars($r['gender'], ENT_QUOTES) ?>',
                '<?= htmlspecialchars($r['course'], ENT_QUOTES) ?>',
                '<?= htmlspecialchars($r['year_level'], ENT_QUOTES) ?>',
                '<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>'
              )">Edit</button>

                  <button class="btn delete-btn"
                    onclick="confirmDelete('?delete_student=<?= $r['id'] ?>')">Delete</button>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </section>


    <!-- Floating Chatbot -->
    <div id="chatbot-icon">💬</div>
    <div id="chatbot-window">
      <div id="chatbot-header">
        <span>Classify Assistant 🤖</span>
        <button id="chatbot-close" onclick="toggleChatbot()">✖</button>
      </div>
      <div id="chatbot-messages"></div>
      <div id="chatbot-input-area">
        <input type="text" id="chatbot-input" placeholder="Ask me something..." />
        <button id="chatbot-send">Send</button>
      </div>
    </div>

    <!-- Subjects -->
    <section id="subjects" class="section" style="display:none;">
      <h2>Subjects List</h2>

      <div class="search-container">
        <input type="text" id="subjectSearch" class="search-input" placeholder="🔍 Search subject...">
      </div>

      <div class="table-actions">
        <button class="btn" onclick="openModal('addSubject')">+ Add Subject</button>
      </div>

      <div class="table-container" id="subjectsTableWrapper">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Code</th>
              <th>Name</th>
              <th>Year Level</th>
              <th>Units</th>
              <th>Semester</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $res = $conn->query("SELECT * FROM subjects");
            while ($r = $res->fetch_assoc()) {
              $codeJs = htmlspecialchars($r['code'], ENT_QUOTES, 'UTF-8');
              $nameJs = htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8');
              $ylJs = htmlspecialchars($r['year_level'], ENT_QUOTES, 'UTF-8');
              $uJs = htmlspecialchars($r['units'], ENT_QUOTES, 'UTF-8');
              $semJs = htmlspecialchars($r['Semester'], ENT_QUOTES, 'UTF-8');
              echo "<tr>
            <td>{$r['id']}</td>
            <td>{$r['code']}</td>
            <td>{$r['name']}</td>
            <td>{$r['year_level']}</td>
            <td>{$r['units']}</td>
            <td>{$r['Semester']}</td>
            <td>
  <button class='btn' onclick=\"editSubject({$r['id']},'{$codeJs}','{$nameJs}','{$ylJs}','{$uJs}','{$semJs}')\">Edit</button>
  <a class='btn' href='view_classlist.php?subject_id={$r['id']}'>View Classlist</a>
  <button class='btn delete-btn' onclick=\"confirmDelete('?delete_subject={$r['id']}')\">Delete</button>
</td>

          </tr>";
            }
            ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Cancellations -->
    <section id="cancellations" class="section" style="display:none;">
      <h2>Cancelled Classes</h2>

      <div class="search-container">
        <input type="text" id="cancellationSearch" class="search-input" placeholder="🔍 Search faculty...">
      </div>

      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>Faculty Name</th>
              <th>Subject</th>
              <th>Year Level</th>
              <th>Course</th>
              <th>Reason</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $res = $conn->query("SELECT * FROM cancellations ORDER BY date DESC");
            while ($r = $res->fetch_assoc()) {
              echo "<tr>
            <td>{$r['faculty_name']}</td>
            <td>{$r['subject_code']}</td>
            <td>{$r['year_level']}</td>
            <td>{$r['course']}</td>
            <td>{$r['reason']}</td>
            <td>{$r['date']}</td>
          </tr>";
            }
            ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Add Section Modal -->
    <div id="addSectionModal" class="modal">
      <div class="modal-content">
        <h3>Add Section</h3>
        <form method="POST">
          <label>Year Level</label>
          <select name="year_level" required>
            <option>1st Year</option>
            <option>2nd Year</option>
            <option>3rd Year</option>
            <option>4th Year</option>
          </select>

          <label>Section Name</label>
          <input type="text" name="section_name" placeholder="e.g. Set A" required>

          <label>Semester</label>
          <select name="semester" required>
            <option>1st Semester</option>
            <option>2nd Semester</option>
            <option>Summer</option>
          </select>

          <label>Course</label>
          <input type="text" name="course" placeholder="e.g. BS Computer Science" required>

          <label>Faculty Adviser</label>
          <select name="faculty_id">
            <option value="">None</option>
            <?php
            $faculty = $conn->query("SELECT id, name FROM faculty ORDER BY name");
            while ($f = $faculty->fetch_assoc()) {
              echo "<option value='{$f['id']}'>{$f['name']}</option>";
            }
            ?>
          </select>

          <button class="btn" name="add_section">Save Section</button>
          <button type="button" class="btn cancel" onclick="closeAddSectionModal()">Cancel</button>
        </form>
      </div>
    </div>

    <!-- Edit Section Modal -->
    <div id="editSectionModal" class="modal">
      <div class="modal-content">
        <h3>Edit Section</h3>
        <form method="POST">
          <input type="hidden" name="id" id="edit_section_id">

          <label>Year Level</label>
          <select name="year_level" id="edit_year_level" required>
            <option>1st Year</option>
            <option>2nd Year</option>
            <option>3rd Year</option>
            <option>4th Year</option>
          </select>

          <label>Section Name</label>
          <input type="text" name="section_name" id="edit_section_name" required>

          <label>Semester</label>
          <select name="semester" id="edit_semester" required>
            <option>1st Semester</option>
            <option>2nd Semester</option>
            <option>Summer</option>
          </select>

          <label>Course</label>
          <input type="text" name="course" id="edit_course" required>

          <label>Faculty Adviser</label>
          <select name="faculty_id" id="edit_faculty_id">
            <option value="">None</option>
            <?php
            $faculty2 = $conn->query("SELECT id, name FROM faculty ORDER BY name");
            while ($f2 = $faculty2->fetch_assoc()) {
              echo "<option value='{$f2['id']}'>{$f2['name']}</option>";
            }
            ?>
          </select>

          <button class="btn" name="edit_section">Update Section</button>
          <button type="button" class="btn cancel" onclick="closeEditSectionModal()">Cancel</button>
        </form>
      </div>
    </div>

    <!-- Sections table -->
    <section id="sections" class="section" style="display:none;">
      <h2>Manage Sections</h2>
      <div class="table-actions">
        <button class="btn" onclick="openAddSectionModal()">➕ Add Section</button>
      </div>

      <div class="table-container">
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Year Level</th>
              <th>Section Name</th>
              <th>Semester</th>
              <th>Course</th>
              <th>Faculty</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $result = $conn->query("
          SELECT s.*, f.name AS faculty_name
          FROM sections s
          LEFT JOIN faculty f ON s.faculty_id = f.id
          ORDER BY s.year_level, s.section_name
        ");
            while ($row = $result->fetch_assoc()) {
              $facultyName = !empty($row['faculty_name']) ? $row['faculty_name'] : '<i>Unassigned</i>';
              $rowJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');

              echo "
          <tr>
            <td>{$row['id']}</td>
            <td>{$row['year_level']}</td>
            <td>{$row['section_name']}</td>
            <td>{$row['semester']}</td>
            <td>{$row['course']}</td>
            <td>{$facultyName}</td>
            <td>
              <button class='btn' onclick='openEditSectionModal(JSON.parse(this.dataset.row))' data-row=\"{$rowJson}\">Edit</button>
              <button class='btn delete-btn' onclick=\"confirmDelete('?delete_section={$row['id']}')\">Delete</button>
            </td>
          </tr>";
            }
            ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Add Assignment Modal -->
    <div class="modal" id="addAssign">
      <div class="modal-content beautiful-modal">
        <h2>Assign Faculty to Subject</h2>

        <form method="POST" class="assign-form">

          <!-- Faculty -->
          <label>Faculty</label>
          <select name="faculty_id" required>
            <option value="" disabled selected>Select Faculty</option>
            <?php
            $facRes = $conn->query("SELECT id, name FROM faculty ORDER BY name ASC");
            while ($f = $facRes->fetch_assoc()):
              ?>
              <option value="<?= $f['id'] ?>"><?= $f['name'] ?></option>
            <?php endwhile; ?>
          </select>

          <!-- Subject -->
          <label>Subject <small style="font-weight:normal;color:#ddd;">
              (Current: <?= $currentSemester ?> – only these are selectable)
            </small></label>

          <select name="subject_id" required>
            <option value="" disabled selected>Select Subject</option>
            <?php
            // Get subjects with their semester
            $subjects = $conn->query("SELECT id, code, name, Semester FROM subjects ORDER BY year_level ASC");

            // Already assigned subjects
            $assigned = [];
            $check = $conn->query("SELECT subject_id FROM assignments");
            while ($a = $check->fetch_assoc()) {
              $assigned[] = $a['subject_id'];
            }

            while ($s = $subjects->fetch_assoc()):
              $isAssigned = in_array($s['id'], $assigned);
              $isRightSem = ($s['Semester'] === $currentSemester);

              $disabled = "";
              $label = "";
              $style = "";

              // If already assigned → lock
              if ($isAssigned) {
                $disabled = "disabled";
                $label .= " (Assigned)";
                $style = "style='background:#c94a4a;color:#fff;font-style:italic;'";
              }

              if (!$isRightSem) {
                $disabled = "disabled";
                $label .= $label ? " / " : " ";
                $label .= $s['Semester'];

                $style = "style='background:#555;color:#ccc;font-style:italic;'";
              }
              ?>
              <option value="<?= $s['id'] ?>" <?= $disabled ?>   <?= $style ?>>
                <?= $s['code'] ?> - <?= $s['name'] ?>   <?= $label ?>
              </option>
            <?php endwhile; ?>
          </select>





          <!-- Days -->
          <label>Days</label>

          <div class="days-container">
            <label><input type="checkbox" name="days[]" value="Sun"> Sun</label>
            <label><input type="checkbox" name="days[]" value="Mon"> Mon</label>
            <label><input type="checkbox" name="days[]" value="Tue"> Tue</label>
            <label><input type="checkbox" name="days[]" value="Wed"> Wed</label>
            <label><input type="checkbox" name="days[]" value="Thu"> Thu</label>
            <label><input type="checkbox" name="days[]" value="Fri"> Fri</label>
            <label><input type="checkbox" name="days[]" value="Sat"> Sat</label>
          </div>

          <!-- Schedule Notes -->
          <label>Schedule Description</label>
          <input type="text" name="schedule" placeholder="e.g. Lecture / Main Building" required>

          <!-- Time -->
          <label>Time Schedule</label>
          <div class="time-container">
            <input type="time" name="time_start" required>
            <input type="time" name="time_end" required>
          </div>

          <!-- Buttons -->
          <div class="form-buttons">
            <button class="btn" name="add_assign">Save Assignment</button>
            <button type="button" class="btn cancel" onclick="closeModal('addAssign')">Cancel</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Assignment Modal -->
    <div class="modal" id="editAssign">
      <div class="modal-content">
        <h2>Edit Assignment</h2>
        <form method="POST">
          <input type="hidden" name="id" id="edit_assign_id">

          <label>Year Level</label>
          <input type="text" name="year_level" id="edit_assign_year" placeholder="Year Level" required>




          </select>

          <label>Days</label>
          <div class="time-container" style="flex-wrap:wrap;">
            <label><input type="checkbox" name="days[]" value="Mon"> Mon</label>
            <label><input type="checkbox" name="days[]" value="Tue"> Tue</label>
            <label><input type="checkbox" name="days[]" value="Wed"> Wed</label>
            <label><input type="checkbox" name="days[]" value="Thu"> Thu</label>
            <label><input type="checkbox" name="days[]" value="Fri"> Fri</label>
            <label><input type="checkbox" name="days[]" value="Sat"> Sat</label>
          </div>

          <label>Schedule</label>
          <input type="text" name="schedule" id="edit_assign_schedule" placeholder="Schedule" required>

          <label>Time Start / End</label>
          <input type="time" name="time_start" id="edit_assign_start" required>
          <input type="time" name="time_end" id="edit_assign_end" required>

          <button class="btn" name="edit_assign">Update</button>
          <button class="btn cancel" type="button" onclick="closeModal('editAssign')">Cancel</button>
        </form>
      </div>
    </div>

    <!-- Add Faculty Modal -->
    <div class="modal" id="addFaculty">
      <div class="modal-content">
        <h2>Add Faculty</h2>
        <form method="POST">
          <input type="text" name="name" placeholder="Full Name" required>
          <input type="email" name="email" placeholder="Email" required>
          <input type="password" name="password" placeholder="Password" required>
          <input type="tel" name="contact_number" placeholder="e.g. 09356295960" pattern="[0-9]{10,12}" maxlength="12"
            required>
          <button class="btn" name="add_faculty">Save</button>
          <button class="btn cancel" type="button" onclick="closeModal('addFaculty')">Cancel</button>
        </form>
      </div>
    </div>

    <!-- ADD STUDENT -->
    <div class="modal" id="addStudent">
      <div class="modal-content">
        <h2>Add Student</h2>

        <form method="POST" enctype="multipart/form-data">
          <input type="text" name="student_id" placeholder="Student ID" required>
          <input type="text" name="name" placeholder="Full Name" required>
          <input type="text" name="gender" placeholder="Gender" required>
          <input type="text" name="course" placeholder="Course" required>
          <input type="text" name="year_level" placeholder="Year Level" required>
          <input type="email" name="email" placeholder="Email" required>


          <label style="margin-top:6px;">Upload Photo (Optional)</label>
          <input type="file" name="photo" accept="image/*">

          <button class="btn" name="add_student">Save</button>
          <button class="btn cancel" type="button" onclick="closeModal('addStudent')">Cancel</button>
        </form>
      </div>
    </div>


    <!-- Edit Faculty Modal -->
    <div class="modal" id="editFaculty">
      <div class="modal-content">
        <h2>Edit Faculty</h2>
        <form method="POST">
          <input type="hidden" id="edit_faculty_id" name="id">
          <input type="text" id="edit_faculty_name" name="name" placeholder="Full Name" required>
          <input type="email" id="edit_faculty_email" name="email" placeholder="Email" required>
          <input type="text" id="edit_faculty_contact" name="contact_number" placeholder="Contact Number" required>
          <button class="btn" name="edit_faculty">Update</button>
          <button class="btn cancel" type="button" onclick="closeModal('editFaculty')">Cancel</button>
        </form>
      </div>
    </div>

    <!-- EDIT STUDENT -->
    <div class="modal" id="editStudent">
      <div class="modal-content">
        <h2>Edit Student</h2>

        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="id" id="edit_student_record_id">

          <input type="text" id="edit_student_id" name="student_id" required>
          <input type="text" id="edit_student_name" name="name" required>
          <input type="text" id="edit_student_gender" name="gender" required>
          <input type="text" id="edit_student_course" name="course" required>
          <input type="text" id="edit_student_year" name="year_level" required>
          <input type="email" id="edit_student_email" name="email" required>

          <label style="margin-top:6px;">Replace Photo</label>
          <input type="file" name="photo" accept="image/*">

          <button class="btn" name="edit_student">Update</button>
          <button class="btn cancel" type="button" onclick="closeModal('editStudent')">Cancel</button>
        </form>
      </div>
    </div>


    <!-- Edit Subject Modal -->
    <div class="modal" id="editSubject">
      <div class="modal-content">
        <h2>Edit Subject</h2>
        <form method="POST">
          <input type="hidden" id="edit_subject_id" name="id">
          <input type="text" id="edit_subject_code" name="code" placeholder="Subject Code" required>
          <input type="text" id="edit_subject_name" name="name" placeholder="Subject Name" required>
          <input type="number" id="edit_subject_units" name="units" placeholder="Units" required>
          <input type="text" id="edit_subject_year_level" name="year_level" placeholder="Year Level" required>
          <input type="text" id="edit_subject_Semester" name="Semester" placeholder="Semester" required>
          <button class="btn" name="edit_subject">Update</button>
          <button class="btn cancel" type="button" onclick="closeModal('editSubject')">Cancel</button>
        </form>
      </div>
    </div>

    <!-- Add Subject Modal -->
    <div class="modal" id="addSubject">
      <div class="modal-content">
        <h2>Add Subject</h2>
        <form method="POST">
          <label>Subject Code</label>
          <input type="text" name="code" placeholder="e.g. CS101" required>

          <label>Subject Name</label>
          <input type="text" name="name" placeholder="e.g. Introduction to Computing" required>

          <label>Units</label>
          <input type="number" name="units" placeholder="e.g. 3" min="1" required>

          <label>Year Level</label>
          <select name="year_level" required>
            <option value="">Select Year</option>
            <option>1st Year</option>
            <option>2nd Year</option>
            <option>3rd Year</option>
            <option>4th Year</option>
          </select>

          <label>Semester</label>
          <select name="Semester" required>
            <option value="">Select Semester</option>
            <option value="1st Semester">1st Semester</option>
            <option value="2nd Semester">2nd Semester</option>
            <option value="Summer">Summer</option>
          </select>

          <button class="btn" name="add_subject">Save</button>
          <button class="btn cancel" type="button" onclick="closeModal('addSubject')">Cancel</button>
        </form>
      </div>
    </div>

    <!-- Success sound -->
    <audio id="successSound" src="beep.mp3.mp3" preload="auto"></audio>

  </div>

  <script>
    // ========= NAVIGATION =========
    function toggleSidebar() {
      const sidebar = document.querySelector('.sidebar');
      sidebar.classList.toggle('open');
    }

    function showSection(id, e) {
      document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
      const section = document.getElementById(id);
      if (section) section.style.display = 'block';

      document.querySelectorAll('.sidebar a').forEach(a => a.classList.remove('active'));
      if (e && e.target.closest('a')) {
        e.target.closest('a').classList.add('active');
      }

      const sidebar = document.querySelector('.sidebar');
      if (window.innerWidth <= 900 && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
      }
    }

    function openModal(id) {
      const modal = document.getElementById(id);
      if (modal) modal.style.display = 'flex';
    }
    function closeModal(id) {
      const modal = document.getElementById(id);
      if (modal) modal.style.display = 'none';
    }
    function confirmDelete(url) {
      if (confirm('Are you sure you want to delete this record?')) window.location.href = url;
    }

    // ========= EDIT HELPERS =========
    function editFaculty(id, name, email, contact_number) {
      document.getElementById('edit_faculty_id').value = id;
      document.getElementById('edit_faculty_name').value = name;
      document.getElementById('edit_faculty_email').value = email;
      document.getElementById('edit_faculty_contact').value = contact_number;
      openModal('editFaculty');
    }

    function edit_assign(id, year_level, schedule, start, end, days, section_id) {
      document.getElementById('edit_assign_id').value = id;
      document.getElementById('edit_assign_year').value = year_level;
      document.getElementById('edit_assign_schedule').value = schedule;
      document.getElementById('edit_assign_start').value = start;
      document.getElementById('edit_assign_end').value = end;

      const sect = document.getElementById('edit_assign_section');
      if (sect) sect.value = section_id ? String(section_id) : "";

      const selected = (days || '').split(',').map(d => d.trim()).filter(Boolean);
      document.querySelectorAll('#editAssign input[name="days[]"]').forEach(cb => {
        cb.checked = selected.includes(cb.value);
      });

      openModal('editAssign');
    }

    function edit_student(id, student_id, name, gender, course, year_level, email) {
      document.getElementById('edit_student_record_id').value = id;
      document.getElementById('edit_student_id').value = student_id;
      document.getElementById('edit_student_name').value = name;
      document.getElementById('edit_student_gender').value = gender;
      document.getElementById('edit_student_course').value = course;
      document.getElementById('edit_student_year').value = year_level;
      document.getElementById('edit_student_email').value = email;
      openModal('editStudent');
    }

    function editSubject(id, code, name, year_level, units, Semester) {
      document.getElementById('edit_subject_id').value = id;
      document.getElementById('edit_subject_code').value = code;
      document.getElementById('edit_subject_name').value = name;
      document.getElementById('edit_subject_units').value = units;
      document.getElementById('edit_subject_year_level').value = year_level;
      document.getElementById('edit_subject_Semester').value = Semester;
      openModal('editSubject');
    }

    // Sections modals
    function openAddSectionModal() {
      document.getElementById('addSectionModal').style.display = 'flex';
    }
    function closeAddSectionModal() {
      document.getElementById('addSectionModal').style.display = 'none';
    }
    function openEditSectionModal(data) {
      document.getElementById('editSectionModal').style.display = 'flex';
      document.getElementById('edit_section_id').value = data.id;
      document.getElementById('edit_year_level').value = data.year_level;
      document.getElementById('edit_section_name').value = data.section_name;
      document.getElementById('edit_semester').value = data.semester;
      document.getElementById('edit_course').value = data.course;
      document.getElementById('edit_faculty_id').value = data.faculty_id;
    }
    function closeEditSectionModal() {
      document.getElementById('editSectionModal').style.display = 'none';
    }

    // ========= CHARTS =========
    const ctx = document.getElementById('overviewChart').getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, 400);
    grad.addColorStop(0, 'rgba(0,198,255,0.6)');
    grad.addColorStop(1, 'rgba(0,114,255,0.2)');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: ['Faculty', 'Students', 'Subjects', 'Units', 'Cancellations'],
        datasets: [{
          label: 'System Overview',
          data: [<?= $totalFaculty ?>, <?= $totalStudents ?>, <?= $totalSubjects ?>, <?= $totalUnits ?>, <?= $totalCancellations ?>],
          borderColor: '#00c6ff',
          backgroundColor: grad,
          fill: true,
          tension: .4,
          pointBackgroundColor: '#fff'
        }]
      },
      options: {
        plugins: { legend: { labels: { color: '#fff' } } },
        scales: {
          x: { ticks: { color: '#fff' } },
          y: { ticks: { color: '#fff' }, beginAtZero: true }
        }
      }
    });

    const bar = document.getElementById('barChart').getContext('2d');
    new Chart(bar, {
      type: 'bar',
      data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        datasets: [{
          label: 'Cancelled Classes',
          data: [<?= implode(',', $monthlyData) ?>],
          backgroundColor: 'rgba(246,10,10,0.85)',
          borderRadius: 10
        }]
      },
      options: {
        plugins: { legend: { labels: { color: '#fff' } } },
        scales: {
          x: { ticks: { color: '#fff' } },
          y: { ticks: { color: '#fff' }, beginAtZero: true }
        }
      }
    });

    // ========= PARTICLES =========
    const canvas = document.querySelector('.particles');
    const ctx2 = canvas.getContext('2d');
    let particles = [];

    function createParticle() {
      return {
        x: Math.random() * innerWidth,
        y: Math.random() * innerHeight,
        r: Math.random() * 2 + 1,
        dx: (Math.random() - 0.5) * 0.8,
        dy: (Math.random() - 0.5) * 0.8
      };
    }
    for (let i = 0; i < 150; i++) particles.push(createParticle());

    function animateParticles() {
      ctx2.clearRect(0, 0, canvas.width, canvas.height);
      ctx2.fillStyle = 'rgba(221,225,230,0.5)';
      particles.forEach(p => {
        ctx2.beginPath();
        ctx2.arc(p.x, p.y, p.r, 0, Math.PI * 2);
        ctx2.fill();
        p.x += p.dx;
        p.y += p.dy;
        if (p.x < 0 || p.x > innerWidth) p.dx *= -1;
        if (p.y < 0 || p.y > innerHeight) p.dy *= -1;
      });
      requestAnimationFrame(animateParticles);
    }
    function resizeCanvas() {
      canvas.width = innerWidth;
      canvas.height = innerHeight;
    }
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    document.addEventListener('mousemove', e => {
      particles.forEach(p => {
        let dx = p.x - e.x, dy = p.y - e.y, dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 100) { p.x += dx / 10; p.y += dy / 10; }
      });
    });
    animateParticles();

    // ========= FIXED EXPORT PDF: STUDENTS =========
    document.getElementById("exportStudents")?.addEventListener("click", async function () {
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF("portrait");

      doc.setFont("helvetica", "bold");
      doc.setFontSize(18);
      doc.text("CLASSIFY - Student Master List", 105, 15, { align: "center" });

      doc.setFontSize(11);
      doc.setFont("helvetica", "normal");
      doc.text(`Export Date: ${new Date().toLocaleString()}`, 105, 23, { align: "center" });

      const rows = document.querySelectorAll("#studentsTableWrapper table tbody tr");
      let tableData = [];

      for (let r of rows) {
        let cols = r.querySelectorAll("td");

        const realID = cols[1]?.innerText.trim() || "";
        const studentID = cols[2]?.innerText.trim() || "";
        const name = cols[3]?.innerText.trim() || "";
        const gender = cols[4]?.innerText.trim() || "";
        const course = cols[5]?.innerText.trim() || "";
        const year = cols[6]?.innerText.trim() || "";
        const email = cols[7]?.innerText.trim() || "";

        let status = "Not Enrolled";
        try {
          const count = await fetch("check_enrollment.php?student_id=" + realID).then(res => res.text());
          if (parseInt(count) > 0) status = "Enrolled";
        } catch (err) { }

        tableData.push([
          studentID,
          name,
          gender,
          course,
          year,
          email,
          status
        ]);
      }

      doc.autoTable({
        head: [["ID", "Name", "Gender", "Course", "Year Level", "Email", "Status"]],
        body: tableData,
        startY: 32,
        theme: "grid",
        headStyles: {
          fillColor: [25, 118, 210],
          textColor: 255,
          fontStyle: "bold"
        },
        styles: { fontSize: 9, halign: "center" },
        alternateRowStyles: { fillColor: [240, 240, 240] }
      });

      const finalY = doc.lastAutoTable.finalY + 10;
      doc.text(`Total Students: ${tableData.length}`, 14, finalY);

      doc.save("Students_List.pdf");
    });

    // ========= AUTO SUBJECTS (UNIFIED) =========
    function loadAutoSubjects() {
      const yearSelect = document.getElementById("yearSelect");
      const semesterSelect = document.getElementById("semesterSelect");
      const listEl = document.getElementById("subjectsListAuto");
      const idsEl = document.getElementById("subject_ids_auto");

      const year = yearSelect ? yearSelect.value : "";
      const semester = semesterSelect ? semesterSelect.value : "";

      if (!year || !semester) {
        if (listEl) listEl.innerHTML = "";
        if (idsEl) idsEl.value = "";
        return;
      }

      fetch(
        "fetch_subjects.php?year=" +
        encodeURIComponent(year) +
        "&semester=" +
        encodeURIComponent(semester)
      )
        .then(res => res.json())
        .then(data => {
          if (!listEl || !idsEl) return;

          const ids = [];
          listEl.innerHTML = "";

          data.forEach(sub => {
            listEl.innerHTML += "<li>" + sub.code + " - " + sub.name + "</li>";
            ids.push(sub.id);
          });

          idsEl.value = ids.join(",");
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
      const yearSelect = document.getElementById("yearSelect");
      const semesterSelect = document.getElementById("semesterSelect");

      if (yearSelect) {
        yearSelect.addEventListener("change", loadAutoSubjects);
      }
      if (semesterSelect) {
        semesterSelect.addEventListener("change", loadAutoSubjects);
      }
    });

    // ========= EXPORT PDF: FACULTY =========
    document.getElementById("exportFaculty")?.addEventListener("click", function () {
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF("portrait");

      doc.setFontSize(16);
      doc.setFont("helvetica", "bold");
      doc.text("CLASSIFY - Faculty Master List", 105, 15, { align: "center" });

      doc.setFontSize(10);
      doc.setFont("helvetica", "normal");
      doc.text("Basilan State College", 105, 21, { align: "center" });
      doc.text(`Generated: ${new Date().toLocaleString()}`, 105, 27, { align: "center" });

      const table = document.querySelector("#facultyTableWrapper table");
      if (!table) return;

      doc.autoTable({
        html: table,
        startY: 34,
        theme: "grid",
        headStyles: {
          fillColor: [32, 64, 122],
          textColor: 255,
          halign: "center"
        },
        styles: {
          fontSize: 9,
          halign: "center"
        }
      });

      const total = table.querySelectorAll("tbody tr").length;
      const finalY = doc.lastAutoTable.finalY + 10;

      doc.text(`Total Faculty: ${total}`, 14, finalY);
      doc.save("Faculty_List.pdf");
    });
    // ========= SEARCH FILTERS + ENROLLMENT HELPERS =========
    function setupSearch(inputId, tableSelector) {
      const input = document.getElementById(inputId);
      const table = document.querySelector(tableSelector);
      if (!input || !table) return;

      input.addEventListener("keyup", function () {
        const filter = this.value.toLowerCase();
        const rows = table.querySelectorAll("tbody tr");

        rows.forEach(row => {
          const text = row.innerText.toLowerCase();
          row.style.display = text.includes(filter) ? "" : "none";
        });
      });
    }

    // normalizers so different formats still match
    function normalizeYear(str) {
      if (!str) return "";
      const s = str.toString().trim().toLowerCase();

      if (s === "1" || s === "1st" || s.includes("1st year")) return "1st year";
      if (s === "2" || s === "2nd" || s.includes("2nd year")) return "2nd year";
      if (s === "3" || s === "3rd" || s.includes("3rd year")) return "3rd year";
      if (s === "4" || s === "4th" || s.includes("4th year")) return "4th year";

      return s;
    }

    function normalizeSem(str) {
      if (!str) return "";
      const s = str.toString().trim().toLowerCase();

      if (s.startsWith("1")) return "1st semester";
      if (s.startsWith("2")) return "2nd semester";
      if (s.includes("summer")) return "summer";

      return s;
    }

    document.addEventListener("DOMContentLoaded", function () {
      // search filters (tables)
      setupSearch("facultySearch", "#facultyTableWrapper table");
      setupSearch("studentSearch", "#studentsTableWrapper table");
      setupSearch("subjectSearch", "#subjectsTableWrapper table");
      setupSearch("cancellationSearch", "#cancellations table");

      // ===== ENROLLMENT AUTOFILL =====
      const studentSelectEl = document.getElementById("studentSelect");
      const courseInputEl = document.getElementById("courseInput");
      const yearSelectEl = document.getElementById("yearSelect");
      const semSelectEl = document.getElementById("semesterSelect");

      if (studentSelectEl) {
        studentSelectEl.addEventListener("change", function () {
          const opt = this.options[this.selectedIndex];

          // If nothing selected, clear things
          if (!opt || !opt.value) {
            if (courseInputEl) courseInputEl.value = "";
            if (yearSelectEl) yearSelectEl.value = "";
            if (semSelectEl) semSelectEl.value = "";

            const listEl = document.getElementById("subjectsListAuto");
            const idsEl = document.getElementById("subject_ids_auto");
            if (listEl) listEl.innerHTML = "";
            if (idsEl) idsEl.value = "";
            return;
          }

          // 1) COURSE autofill
          const course = opt.dataset.course || "";
          if (courseInputEl) courseInputEl.value = course;

          // optional console log for checking
          const status = opt.textContent.includes("Already Enrolled") ? "YES" : "NO";
          console.log("Enrollment Status:", status);

          // 2) YEAR LEVEL autofill
          const yearAttr = normalizeYear(opt.dataset.year || "");
          if (yearSelectEl) {
            if (yearAttr) {
              let matched = false;
              Array.from(yearSelectEl.options).forEach(o => {
                const txt = normalizeYear(o.textContent);
                if (txt === yearAttr) {
                  yearSelectEl.value = o.value;
                  matched = true;
                }
              });
              if (!matched) yearSelectEl.value = "";
            } else {
              yearSelectEl.value = "";
            }
          }

          // 3) SEMESTER autofill
          const semAttr = normalizeSem(opt.dataset.semester || "");
          if (semSelectEl) {
            if (semAttr) {
              let matched = false;
              Array.from(semSelectEl.options).forEach(o => {
                const txt = normalizeSem(o.textContent);
                if (txt === semAttr) {
                  semSelectEl.value = o.value;
                  matched = true;
                }
              });
              if (!matched) semSelectEl.value = "";
            } else {
              semSelectEl.value = "";
            }
          }

          // 4) Auto-load subjects if both are set
          if (typeof loadAutoSubjects === "function" &&
            yearSelectEl && semSelectEl &&
            yearSelectEl.value && semSelectEl.value) {
            loadAutoSubjects();
          }
        });
      }

      // Optional: subject dropdown filtering by year + semester
      const yearSelect = document.getElementById("yearSelect");
      const semSelect = document.getElementById("semesterSelect");
      const subjSelect = document.getElementById("subjectSelect");

      if (yearSelect && semSelect && subjSelect) {
        function filterSubjects() {
          const y = yearSelect.value;
          const se = semSelect.value;

          Array.from(subjSelect.options).forEach((opt, idx) => {
            if (idx === 0) {
              opt.style.display = "";
              return;
            }
            const oy = opt.dataset.year || "";
            const os = opt.dataset.sem || "";
            const matchYear = !y || oy === y;
            const matchSem = !se || os === se;
            opt.style.display = (matchYear && matchSem) ? "" : "none";
          });
        }
        yearSelect.addEventListener("change", filterSubjects);
        semSelect.addEventListener("change", filterSubjects);
      }
    });


    // ========= CHATBOT =========
    function toggleChatbot() {
      const chatbot = document.getElementById("chatbot-window");
      chatbot.style.display = (chatbot.style.display === "flex") ? "none" : "flex";
    }

    document.getElementById("chatbot-send").addEventListener("click", sendMessage);
    document.getElementById("chatbot-input").addEventListener("keypress", e => {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });

    let typingTimeout;
    function showTyping(callback) {
      clearTimeout(typingTimeout);
      const typing = document.createElement("div");
      typing.classList.add("chatbot-msg", "chatbot-bot");
      typing.textContent = "💬 Typing...";
      const chatBox = document.getElementById("chatbot-messages");
      chatBox.appendChild(typing);
      chatBox.scrollTop = chatBox.scrollHeight;

      typingTimeout = setTimeout(() => {
        typing.remove();
        callback();
      }, 700 + Math.random() * 500);
    }

    function speak(text) {
      window.speechSynthesis.cancel();
      const utter = new SpeechSynthesisUtterance(text.replace(/<[^>]*>/g, ''));
      utter.lang = "en-US";
      utter.pitch = 1;
      utter.rate = 1;
      utter.volume = 1;
      window.speechSynthesis.speak(utter);
    }
    function appendMessage(text, sender) {
      const msg = document.createElement("div");
      msg.classList.add("chatbot-msg", sender === "user" ? "chatbot-user" : "chatbot-bot");

      if (sender === "bot") {
        msg.innerHTML = text;
      } else {
        msg.textContent = text;
      }

      const box = document.getElementById("chatbot-messages");
      box.appendChild(msg);
      box.scrollTop = box.scrollHeight;

      if (sender === "bot") speak(text);
    }

    function sendMessage() {
      const input = document.getElementById("chatbot-input");
      const msg = input.value.trim();
      if (!msg) return;

      appendMessage(msg, "user");
      input.value = "";

      fetch("chatbot_admin.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "message=" + encodeURIComponent(msg)
      })
        .then(res => res.text())
        .then(text => {
          let data;
          try { data = JSON.parse(text); }
          catch { data = { reply: text }; }
          showTyping(() => appendMessage(data.reply || text, "bot"));
        })
        .catch(() => appendMessage("⚠️ The chatbot is offline or unreachable.", "bot"));
    }

    // floating draggable icon
    document.addEventListener("DOMContentLoaded", () => {
      const icon = document.getElementById("chatbot-icon");
      const win = document.getElementById("chatbot-window");

      let isDragging = false;
      let startX, startY, startLeft, startTop;

      icon.addEventListener("mousedown", (e) => {
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;
        const rect = icon.getBoundingClientRect();
        startLeft = rect.left;
        startTop = rect.top;
        icon.style.transition = "none";
      });

      window.addEventListener("mousemove", (e) => {
        if (!isDragging) return;
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        icon.style.left = startLeft + dx + "px";
        icon.style.top = startTop + dy + "px";
        icon.style.right = "auto";
        icon.style.bottom = "auto";
      });

      window.addEventListener("mouseup", () => {
        if (!isDragging) return;
        isDragging = false;
        icon.style.transition = "left 0.2s, top 0.2s";
      });

      icon.addEventListener("click", () => {
        if (!isDragging) {
          win.style.display = (win.style.display === "flex") ? "none" : "flex";
        }
      });
    });
  </script>

</body>

</html>