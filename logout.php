<?php
session_start();

// Database Connection
$conn = new mysqli("localhost", "root", "", "classify_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Log function
function log_action($conn, $action) {
    $user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'unknown';
    $user_name = isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'unknown';

    $sql = "INSERT INTO activity_logs (user_role, user_name, action, timestamp) VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sss", $user_role, $user_name, $action);
        $stmt->execute();
        $stmt->close();
    }
}

// Log the logout action before destroying the session
log_action($conn, "Logged out");

// Destroy session and redirect
session_destroy();
header("Location: classify_login.php");
exit();
?>
