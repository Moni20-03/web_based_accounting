<?php
include '../database/findb.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$user_id = $_SESSION['user_id'] ?? 0;
$company_db = $_SESSION['company_name'];
$successMessage = $_GET['success'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Search and filter
$search = $_GET['search'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

// Base query
$query = "SELECT v.*, GROUP_CONCAT(t.ledger_id SEPARATOR ',') as ledger_ids 
          FROM vouchers v
          JOIN transactions t ON v.voucher_id = t.voucher_id
          WHERE v.voucher_type = 'Purchase'";

// Add search conditions
if (!empty($search)) {
    $query .= " AND (v.voucher_number LIKE '%$search%' OR v.reference_number LIKE '%$search%')";
}

// Add date filter
if (!empty($from_date) && !empty($to_date)) {
    $query .= " AND v.voucher_date BETWEEN '$from_date' AND '$to_date'";
} elseif (!empty($from_date)) {
    $query .= " AND v.voucher_date >= '$from_date'";
} elseif (!empty($to_date)) {
    $query .= " AND v.voucher_date <= '$to_date'";
}

// Complete query with grouping and pagination
$query .= " GROUP BY v.voucher_id ORDER BY v.voucher_date DESC, v.voucher_number DESC LIMIT $offset, $perPage";

$vouchers = $conn->query($query);

// Count total records for pagination
$countQuery = "SELECT COUNT(DISTINCT v.voucher_id) as total FROM vouchers v WHERE v.voucher_type = 'Purchase'";
if (!empty($search)) {
    $countQuery .= " AND (v.voucher_number LIKE '%$search%' OR v.reference_number LIKE '%$search%')";
}
if (!empty($from_date) && !empty($to_date)) {
    $countQuery .= " AND v.voucher_date BETWEEN '$from_date' AND '$to_date'";
}
$totalResult = $conn->query($countQuery)->fetch_assoc();
$total = $totalResult['total'];
$totalPages = ceil($total / $perPage);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Purchase Vouchers - FINPACK</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="../styles/list_style.css">
    <link rel="stylesheet" href="../styles/tally_style.css">
    <link rel="stylesheet" href="../styles/navbar_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .action-buttons a {
            padding: 3px 8px;
            border-radius: 4px;
            text-decoration: none;
        }
        .view-btn {
            background-color: #17a2b8;
            color: white;
        }
        .edit-btn {
            background-color: #ffc107;
            color: black;
        }
        .delete-btn {
            background-color: #dc3545;
            color: white;
        }
        .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .search-container input, .search-container button {
            padding: 8px 12px;
        }
        .date-filters {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }
        .pagination a, .pagination span {
            padding: 5px 10px;
            border: 1px solid #ddd;
            text-decoration: none;
        }
        .pagination a:hover {
            background-color: #f0f0f0;
        }
        .pagination .current {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
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

<div class="container">
    <div class="header">
        <h2>Purchase Vouchers</h2>
        <h3><?php echo $company_db ?></h3>
        <div class="current-date"><?= date('d-M-Y') ?></div>
    </div>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message">
            <?= htmlspecialchars($successMessage) ?>
        </div>
    <?php endif; ?>

    <div class="search-container">
        <form method="get" action="purchase_vouchers_list.php" class="search-form">
            <input type="text" name="search" placeholder="Search by voucher no or ref no" value="<?= htmlspecialchars($search) ?>">
            <div class="date-filters">
                <label>From:</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
                <label>To:</label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <button type="submit" class="search-button">
                <i class="fas fa-search"></i> Search
            </button>
            <a href="purchase_vouchers_list.php" class="reset-button">
                <i class="fas fa-sync-alt"></i> Reset
            </a>
        </form>
    </div>

    <div class="action-buttons" style="margin-bottom: 20px;">
        <a href="purchase_voucher.php" class="add-button">
            <i class="bi bi-plus-circle"></i> Create New Purchase Voucher
        </a>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Voucher No</th>
                    <th>Date</th>
                    <th>Reference No</th>
                    <th>Total Amount</th>
                    <th>Ledgers</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($vouchers->num_rows > 0): ?>
                    <?php while($voucher = $vouchers->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($voucher['voucher_number']) ?></td>
                            <td><?= date('d-M-Y', strtotime($voucher['voucher_date'])) ?></td>
                            <td><?= htmlspecialchars($voucher['reference_number']) ?></td>
                            <td><?= number_format($voucher['total_amount'], 2) ?></td>
                            <td>
                                <?php 
                                // Get ledger names for display
                                $ledger_ids = explode(',', $voucher['ledger_ids']);
                                $ledger_names = [];
                                foreach ($ledger_ids as $id) {
                                    $ledger = $conn->query("SELECT ledger_name FROM ledgers WHERE ledger_id = $id")->fetch_assoc();
                                    if ($ledger) $ledger_names[] = $ledger['ledger_name'];
                                }
                                echo implode(', ', array_unique($ledger_names));
                                ?>
                            </td>
                            <td class="action-buttons">
                                <a href="purchase_voucher_view.php?id=<?= $voucher['voucher_id'] ?>" class="view-btn" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="edit_purchase.php?id=<?= $voucher['voucher_id'] ?>" class="edit-btn" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="purchase_voucher_delete.php?id=<?= $voucher['voucher_id'] ?>" class="delete-btn" title="Delete" onclick="return confirm('Are you sure you want to delete this voucher?');">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">No purchase vouchers found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=1&search=<?= urlencode($search) ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">First</a>
                <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">Prev</a>
            <?php endif; ?>

            <?php 
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            
            for ($i = $start; $i <= $end; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">Next</a>
                <a href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>">Last</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Auto-hide success message after 5 seconds
setTimeout(function() {
    const msg = document.querySelector('.success-message');
    if (msg) {
        msg.style.transition = 'opacity 0.5s ease-out';
        msg.style.opacity = '0';
        setTimeout(() => msg.remove(), 500);
    }
}, 3000);
</script>
</body>
</html>