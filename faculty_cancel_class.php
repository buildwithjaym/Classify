<?php
$conn = new mysqli("localhost","root","","classify_db");

$subject_id = intval($_POST['subject_id']);
$reason     = $conn->real_escape_string($_POST['reason']);

// Get enrolled students under this subject
$students = $conn->query("
    SELECT student_id, student_name
    FROM enrollments 
    WHERE subject_id=$subject_id
");

while ($s = $students->fetch_assoc()) {

    $msg = "Your class for Subject ID: $subject_id has been cancelled. Reason: $reason";

    $conn->query("
        INSERT INTO notifications (user_type, user_id, title, message)
        VALUES ('student', {$s['student_id']}, 'Class Cancelled', '$msg')
    ");
}

echo json_encode(["status"=>1,"msg"=>"Class cancelled and students notified"]);
?>
