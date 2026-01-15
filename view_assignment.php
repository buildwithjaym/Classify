<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "classify_db");

// ========== VALIDATION ==========
if (!isset($_GET['faculty_id'])) {
    die("Missing faculty ID.");
}

$faculty_id = intval($_GET['faculty_id']);

// Get faculty info
$faculty = $conn->query("SELECT * FROM faculty WHERE id=$faculty_id")->fetch_assoc();
if (!$faculty) die("Faculty not found.");

// Get assignment list
$sql = "
SELECT a.*, s.code, s.name AS subject_name, sec.section_name, sec.year_level AS sec_year
FROM assignments a
JOIN subjects s ON a.subject_id = s.id
LEFT JOIN sections sec ON a.section_id = sec.id
WHERE a.faculty_id = $faculty_id
ORDER BY a.id DESC
";
$res = $conn->query($sql);
?>
<!DOCTYPE html>
<html>
<head>
<title>Faculty Assignments</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

<style>
body{
    font-family: Arial, sans-serif;
    background: #f0f2f5;
    padding: 20px;
}

.container{
    background: white;
    padding: 20px;
    border-radius: 10px;
    width: 900px;
    margin: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

table{
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

table th, table td{
    border: 1px solid #ccc;
    padding: 10px;
    text-align: center;
}

.btn{
    padding: 10px 14px;
    background: #0067ff;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    display: inline-block;
}

.btn:hover{
    background:#004fcc;
}

</style>
</head>
<body>

<div class="container">
    <h2>Faculty Assignment List</h2>
    <h3><?= $faculty['name'] ?></h3>

    <button class="btn" onclick="exportPDF()">Export PDF</button>

    <table id="assignTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Subject Code</th>
                <th>Subject Name</th>
                <th>Year Level</th>
                <th>Section</th>
                <th>Days</th>
                <th>Time</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $res->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= $row['code'] ?></td>
                <td><?= $row['subject_name'] ?></td>
                <td><?= $row['year_level'] ?></td>
                <td><?= $row['section_name'] ?: 'N/A' ?></td>
                <td><?= $row['days'] ?></td>
                <td><?= $row['time_start'] ?> - <?= $row['time_end'] ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <br>
    <a href="admin_dashboard.php" class="btn">Back</a>
</div>


<script>
function exportPDF() {
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF("landscape");

    pdf.text("Faculty Assignment List - <?= $faculty['name'] ?>", 14, 15);
    pdf.autoTable({ html: "#assignTable", startY: 20 });
    pdf.save("Faculty_Assignments.pdf");
}
</script>

</body>
</html>
