<?php
$conn = new mysqli("localhost","root","","classify_db");

$faculty_id = intval($_GET['faculty_id']);
$day        = $_GET['day'];

$res = $conn->query("
    SELECT a.*, s.name AS subject_name
    FROM assignments a
    LEFT JOIN subjects s ON a.subject_id = s.id
    WHERE a.faculty_id=$faculty_id
    AND a.days LIKE '%$day%'
    ORDER BY a.time_start ASC
");

$list = [];
while ($r = $res->fetch_assoc()) {
    $list[] = $r;
}

echo json_encode($list);
?>
