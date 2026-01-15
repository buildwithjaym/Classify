<?php
$conn = new mysqli("localhost","root","","classify_db");

$subject_id = intval($_POST['subject_id']);
$msg        = $conn->real_escape_string($_POST['message']);

$students = $conn->query("
    SELECT student_id FROM enrollments
    WHERE subject_id=$subject_id
");

while ($s = $students->fetch_assoc()) {
    $conn->query("
        INSERT INTO notifications (user_type, user_id, title, message)
        VALUES ('student', {$s['student_id']}, 'New Announcement', '$msg')
    ");
}

echo "Announcement Sent!";
?>
