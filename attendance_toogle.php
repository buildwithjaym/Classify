<?php
$conn = new mysqli("localhost","root","","classify_db");

$subject_id = intval($_POST['subject_id']);
$student_id = intval($_POST['student_id']);
$status     = $_POST['status'];

$today = date('Y-m-d');

// Check if already exists
$check = $conn->query("
    SELECT id FROM attendance
    WHERE subject_id=$subject_id AND student_id=$student_id AND date='$today'
");

if ($check->num_rows > 0) {
    $conn->query("
        UPDATE attendance 
        SET status='$status'
        WHERE subject_id=$subject_id AND student_id=$student_id AND date='$today'
    ");
} else {
    $conn->query("
        INSERT INTO attendance (subject_id, student_id, date, status)
        VALUES ($subject_id, $student_id, '$today', '$status')
    ");
}

echo "OK";
?>
