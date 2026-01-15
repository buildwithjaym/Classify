<?php
$conn = new mysqli("localhost","root","","classify_db");

$subject_id = intval($_GET['subject_id']);

$res = $conn->query("
    SELECT e.*, s.photo 
    FROM enrollments e
    LEFT JOIN students s ON e.student_id = s.id
    WHERE e.subject_id=$subject_id
    ORDER BY e.student_name ASC
");

$list = [];
while ($r = $res->fetch_assoc()) $list[] = $r;

echo json_encode($list);
?>
