<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch user role
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$role = $user['role'];
$stmt->close();

// Redirect to respective dashboards
if ($role === 'Company Head') {
    header("Location: dashboard_head.php");
} elseif ($role === 'Accountant') {
    header("Location: dashboard_accountant.php");
} elseif ($role === 'Manager') {
    header("Location: dashboard_manager.php");
} else {
    echo "Unauthorized access!";
    exit();
}
?>
