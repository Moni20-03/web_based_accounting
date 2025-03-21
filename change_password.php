<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($new_password !== $confirm_password) {
        echo "<script>alert('Passwords do not match!');</script>";
    } elseif (strlen($new_password) < 6) {
        echo "<script>alert('Password must be at least 6 characters long!');</script>";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($stmt->execute()) {
            echo "<script>alert('Password changed successfully!'); window.location.href='dashboard.php';</script>";
            exit();
        } else {
            echo "<script>alert('Error updating password.');</script>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
</head>
<body>
    <h2>Change Your Password</h2>
    <form method="POST">
        <label>New Password:</label>
        <input type="password" name="new_password" required>

        <label>Confirm Password:</label>
        <input type="password" name="confirm_password" required>

        <button type="submit">Change Password</button>
    </form>
</body>
</html>
