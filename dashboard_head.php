<?php
session_start();
include 'db_connection.php';

// Ensure only Company Head can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Company Head') {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Head Dashboard</title>
    <link rel="stylesheet" href="./dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="./dashboard.js" defer></script>
</head>
<body>

<!-- Sidebar -->
<div id="sidebar">
    <div class="sidebar-header">
        <h3>Dashboard</h3>
        <button id="closeSidebar">&times;</button>
    </div>
    <ul class="menu">
        <li class="dropdown">
            <span>👤 Manage Users</span>
            <ul class="submenu">
                <li><a href="registeration/create_roles.php">Role Creation</a></li>
                <li><a href="registeration/update_roles.php">Role Updation</a></li>
                <li><a href="edit_company.php">Edit Company Details</a></li>
            </ul>
        </li>
        <li class="dropdown">
            <span>📂 Masters</span>
            <ul class="submenu">
                <li><a href="accounts_info.php">Accounts Info</a></li>
                <li><a href="inventory_info.php">Inventory Info</a></li>
            </ul>
        </li>
        <li class="dropdown">
            <span>💳 Transactions</span>
            <ul class="submenu">
                <li><a href="accounting_vouchers.php">Accounting Vouchers</a></li>
                <li><a href="inventory_vouchers.php">Inventory Vouchers</a></li>
            </ul>
        </li>
        <li class="dropdown">
            <span>📈 Reports</span>
            <ul class="submenu">
                <li><a href="balance_sheet.php">Balance Sheet</a></li>
                <li><a href="profit_loss.php">Profit & Loss A/C</a></li>
                <li><a href="trial_balance.php">Trial Balance</a></li>
            </ul>
        </li>
        <li class="dropdown">
            <span>📊 Display</span>
            <ul class="submenu">
                <li><a href="ledgers_view.php">Ledgers View</a></li>
                <li><a href="receipts_payments.php">Receipts & Payments</a></li>
            </ul>
        </li>
        <li><a href="profile.php">👤 Profile</a></li>
        <li><a href="logout.php" class="logout">🚪 Logout</a></li>
    </ul>
</div>

<!-- Page Content -->
<div id="content">
    <nav class="navbar">
        <button id="openSidebar">☰</button>
        <h4>Welcome, <?= htmlspecialchars($username); ?>!</h4>
        <button id="themeToggle">🌙</button>
    </nav>
    <div class="container">
        <h2>Company Head Dashboard</h2>
        <p>Manage your company’s users, transactions, reports, and settings efficiently.</p>
    </div>
</div>

</body>
</html>
