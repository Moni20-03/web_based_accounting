<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Accountant') {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accountant Dashboard</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h2>Welcome, Accountant!</h2>
    <ul>
        <li><a href="manage_vouchers.php">Manage Vouchers</a></li>
        <li><a href="manage_ledgers.php">Manage Ledgers</a></li>
        <li><a href="transactions.php">Manage Transactions</a></li>
    </ul>
    <a href="logout.php">Logout</a>
</body>
</html>
