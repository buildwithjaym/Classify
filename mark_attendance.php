<?php
session_start();
$conn = new mysqli("localhost","root","","classify_db");

$faculty_id = $_SESSION['faculty_id'];

$subject_id = intval($_POST['subject_id']);
$student_id = intval($_POST['student_id']);
$status     = $_POST['status']; // Present / Absent / Late

$date       = date("Y-m-d");

$stmt = $conn->prepare("
   INSERT INTO attendance (subject_id, student_id, faculty_id, status, date)
   VALUES (?, ?, ?, ?, ?)
");
$stmt->bind_param("iiiss", $subject_id, $student_id, $faculty_id, $status, $date);
$stmt->execute();

echo json_encode(["status"=>"success"]);
