<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check if session variables are correctly set
if (!isset($_SESSION['role'])) {
    // Fetch user role if not set
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT company_id, role FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $_SESSION['company_id'] = $user['company_id']; // Store role in session
        $_SESSION['role'] = $user['role']; // Store role in session
    } else {
        echo "Unauthorized access!";
        exit();
    }
    $stmt->close();
}

// Redirect based on role
$role = $_SESSION['role'];
if ($role === 'Company Head') {
    header("Location: dashboard_head.php");
    exit();
} elseif ($role === 'Accountant') {
    header("Location: dashboard_accountant.php");
    exit();
} elseif ($role === 'Manager') {
    header("Location: dashboard_manager.php");
    exit();
} else {
    echo "Unauthorized access!";
    exit();
}
?>
