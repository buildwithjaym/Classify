<?php
$conn = new mysqli("localhost", "root", "", "classify_db");

// SAFE GET HANDLING
$year = isset($_GET['year']) ? $_GET['year'] : null;
$sem  = isset($_GET['semester']) ? $_GET['semester'] : null;

if (!$year || !$sem) {
    echo json_encode([]);
    exit();
}

$sql = $conn->prepare("
    SELECT id, code, name, year_level, Semester
    FROM subjects
    WHERE year_level=? AND Semester=?
");
$sql->bind_param("ss", $year, $sem);
$sql->execute();

$res = $sql->get_result();
$subjects = [];

while ($row = $res->fetch_assoc()) {
    $subjects[] = $row;
}

echo json_encode($subjects);
?>
