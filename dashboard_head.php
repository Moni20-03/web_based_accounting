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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar -->
    <nav id="sidebar">
        <div class="sidebar-header">
            <h3>Dashboard</h3>
            <!-- <p class="text-white"><?= htmlspecialchars($username); ?></p> -->
        </div>
        <ul class="list-unstyled components">
            <li><a href="registeration/create_roles.php"><i class="fas fa-users"></i> Manage Users</a></li>
            <li><a href="view_reports.php"><i class="fas fa-chart-line"></i> View Reports</a></li>
            <li><a href="manage_vouchers.php"><i class="fas fa-receipt"></i> Manage Vouchers</a></li>
            <li><a href="manage_ledgers.php"><i class="fas fa-book"></i> Manage Ledgers</a></li>
            <li><a href="transactions.php"><i class="fas fa-exchange-alt"></i> Manage Transactions</a></li>
        </ul>
        <div class="logout-btn">
            <a href="logout.php" class="btn btn-danger w-100"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>

    <!-- Page Content -->
    <div id="content">
        <nav class="navbar navbar-light bg-white shadow">
            <button type="button" id="sidebarCollapse" class="btn btn-primary">
                <i class="fas fa-bars"></i>
            </button>
            <h4 class="m-0">Welcome, <?= htmlspecialchars($username); ?>!</h4>
        </nav>

        <div class="container mt-4">
            <div class="alert alert-info text-center">
                <h5>Company Head Dashboard</h5>
                <p>Manage your company’s users, vouchers, transactions, and reports.</p>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).ready(function () {
        $('#sidebarCollapse').on('click', function () {
            $('#sidebar').toggleClass('active');
        });
    });
</script>
</body>
</html>
