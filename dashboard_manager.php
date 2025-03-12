<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Manager') {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h2>Welcome, Manager!</h2>
    <ul>
        <li><a href="view_reports.php">View Reports</a></li>
        <li><a href="approve_transactions.php">Approve Transactions</a></li>
    </ul>
    <a href="logout.php">Logout</a>
</body>
</html>
