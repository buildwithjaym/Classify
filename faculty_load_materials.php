<?php
$conn = new mysqli("localhost","root","","classify_db");

$subject_id = intval($_GET['subject_id']);

$res = $conn->query("
    SELECT * FROM materials 
    WHERE subject_id=$subject_id 
    ORDER BY uploaded_at DESC
");

$list = [];
while ($r = $res->fetch_assoc()) $list[] = $r;

echo json_encode($list);
?>
