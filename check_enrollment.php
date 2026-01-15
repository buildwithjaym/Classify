<?php
$conn = new mysqli("localhost","root","","classify_db");

$id = intval($_GET['student_id']);

$q = $conn->query("SELECT COUNT(*) AS cnt FROM enrollments WHERE student_id=$id");
$cnt = $q->fetch_assoc()['cnt'];

echo $cnt;
?>
