<?php
$conn = new mysqli("localhost","root","","classify_db");

$subject_id = intval($_POST['subject_id']);

if (!empty($_FILES['file']['name'])) {

    $fileName = time() . "_" . basename($_FILES['file']['name']);
    $target   = "uploads/materials/" . $fileName;

    move_uploaded_file($_FILES['file']['tmp_name'], $target);

    $conn->query("
        INSERT INTO materials (subject_id, filename)
        VALUES ($subject_id, '$fileName')
    ");

    echo "Uploaded";
}
?>
