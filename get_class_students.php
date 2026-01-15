<?php
session_start();
$conn = new mysqli("localhost","root","","classify_db");

$subject_id = intval($_GET['subject_id']);

$q = $conn->query("
    SELECT e.student_id, e.student_name, s.photo
    FROM enrollments e
    LEFT JOIN students s ON e.student_id = s.id
    WHERE e.subject_id = $subject_id
    ORDER BY e.student_name
");

$data = [];
while($row = $q->fetch_assoc()){
    $data[] = $row;
}

echo json_encode($data);
