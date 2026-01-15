<?php
$conn = new mysqli("localhost","root","","classify_db");

session_start();
$faculty_id = $_SESSION['faculty_id'];

$res = $conn->query("
    SELECT COUNT(*) AS c 
    FROM notifications 
    WHERE user_type='faculty' 
    AND user_id=$faculty_id 
    AND is_read=0
");
echo json_encode(["count" => $res->fetch_assoc()['c']]);
?>
