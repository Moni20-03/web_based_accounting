<?php
include '../database/findb.php';

// Check user session and permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Pagination setup
$records_per_page = 7;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Date filter setup
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : '';
$where_clause = "WHERE v.voucher_type = 'Purchase'";
if (!empty($date_filter)) {
    $where_clause .= " AND DATE(v.voucher_date) = '$date_filter'";
}

// Get total count for pagination
$count_stmt = $conn->prepare("SELECT COUNT(DISTINCT v.voucher_id) as total 
                             FROM vouchers v
                             JOIN transactions t ON v.voucher_id = t.voucher_id AND t.transaction_type = 'Credit'
                             JOIN ledgers c ON t.ledger_id = c.ledger_id
                             JOIN transactions td ON v.voucher_id = td.voucher_id AND td.transaction_type = 'Debit'
                             JOIN ledgers d ON td.ledger_id = d.ledger_id
                             $where_clause");
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $records_per_page);
$count_stmt->close();

// Fetch purchase vouchers with pagination
$stmt = $conn->prepare("SELECT v.voucher_id, v.voucher_number, v.voucher_date, v.total_amount, 
                       GROUP_CONCAT(DISTINCT d.ledger_name SEPARATOR ', ') AS debit_accounts,
                       c.ledger_name AS credit_account
                       FROM vouchers v
                       JOIN transactions t ON v.voucher_id = t.voucher_id AND t.transaction_type = 'Credit'
                       JOIN ledgers c ON t.ledger_id = c.ledger_id
                       JOIN transactions td ON v.voucher_id = td.voucher_id AND td.transaction_type = 'Debit'
                       JOIN ledgers d ON td.ledger_id = d.ledger_id
                       $where_clause
                       GROUP BY v.voucher_id
                       ORDER BY v.voucher_date DESC, v.voucher_number DESC
                       LIMIT $offset, $records_per_page");
$stmt->execute();
$result = $stmt->get_result();
$vouchers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Display success message if redirected from edit/delete
$successMessage = '';
if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

$display_date = date('d-M-Y');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Purchase Vouchers - FINPACK</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="../styles/voucher_list_style.css">
    <link rel="stylesheet" href="../styles/navbar_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        .account-list {
            font-size: 1rem;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="../index.html">
                <img class="logo" src="../images/logo3.png" alt="Logo">
                <span>FinPack</span> 
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="../dashboards/dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </li>
            <li>
                <a href="#">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </a>
            </li>
            <li>
                <a href="../logout.php" style="color:rgb(235, 71, 53);">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </li>
        </ul>
    </nav>
    
    <div class="main-container">
        <div class="header-section">
            <div class="header-title">
                <h2><i class="fas fa-shopping-cart"></i> Purchase Vouchers</h2>
                <p class="record-count"><?= number_format($total_rows) ?> records found</p>
            </div>
            <div class="action-buttons">
                <a href="purchase_voucher.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Purchase
                </a>
                <button class="btn btn-secondary print-btn" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $successMessage; ?>
            </div>
        <?php endif; ?>

        <div class="filter-section">
            <form method="get" class="filter-form">
                <div class="filter-group">
                    <label for="date_filter"><i class="fas fa-calendar-day"></i> Filter by Date:</label>
                    <input type="date" id="date_filter" name="date_filter" value="<?= htmlspecialchars($date_filter) ?>">
                    <button type="submit" class="btn btn-filter">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if (!empty($date_filter)): ?>
                        <a href="purchase_list.php" class="btn btn-clear">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Voucher No</th>
                        <th>Date</th>
                        <th>Purchase Accounts</th>
                        <!-- <th>Supplier/Paid From</th> -->
                        <th class="amount-col">Amount</th>
                        <th class="actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vouchers)): ?>
                        <tr>
                            <td colspan="6" class="no-data">
                                <i class="fas fa-database"></i> No purchase vouchers found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vouchers as $voucher): ?>
                        <tr>
                            <td class="voucher-number"><?= htmlspecialchars($voucher['voucher_number']) ?></td>
                            <td><?= date('d-M-Y', strtotime($voucher['voucher_date'])) ?></td>
                            <td>
                                <div class="account-list debit-accounts">
                                    <?= htmlspecialchars($voucher['debit_accounts']) ?>
                                </div>
                            </td>
                            <!-- <td>
                                <div class="account-list credit-account">
                                    <?= htmlspecialchars($voucher['credit_account']) ?>
                                </div>
                            </td> -->
                            <td class="amount">₹<?= number_format($voucher['total_amount'], 2) ?></td>
                            <td class="actions">
                                <a href="view_purchase.php?id=<?= $voucher['voucher_id'] ?>" class="action-btn view-btn" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit_purchase.php?id=<?= $voucher['voucher_id'] ?>" class="action-btn edit-btn" title="Edit">
                                    <i class="fas fa-pencil-alt"></i>
                                </a>
                                <a href="delete_purchase.php?id=<?= $voucher['voucher_id'] ?>" 
                                   class="action-btn delete-btn" 
                                   title="Delete"
                                   onclick="return confirm('Are you sure you want to delete this voucher?');">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=1<?= !empty($date_filter) ? '&date_filter='.$date_filter : '' ?>" class="page-link">&laquo; First</a>
                <a href="?page=<?= $page-1 ?><?= !empty($date_filter) ? '&date_filter='.$date_filter : '' ?>" class="page-link">&lsaquo; Prev</a>
            <?php endif; ?>

            <?php 
            // Show page numbers
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            
            for ($i = $start; $i <= $end; $i++): ?>
                <a href="?page=<?= $i ?><?= !empty($date_filter) ? '&date_filter='.$date_filter : '' ?>" 
                   class="page-link <?= $i == $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?><?= !empty($date_filter) ? '&date_filter='.$date_filter : '' ?>" class="page-link">Next &rsaquo;</a>
                <a href="?page=<?= $total_pages ?><?= !empty($date_filter) ? '&date_filter='.$date_filter : '' ?>" class="page-link">Last &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-hide the success message after 4 seconds
        setTimeout(function() {
            const msg = document.querySelector('.alert');
            if (msg) {
                msg.style.transition = 'opacity 0.5s ease-out';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            }
        }, 4000);
    </script>
</body>
</html>