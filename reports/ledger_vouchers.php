<?php
include '../database/findb.php';

$company_db = $_SESSION['company_name'];

// Get parameters from both POST and GET
$group_id = $_POST['group_id'] ?? $_GET['group_id'] ?? 0;
$ledger_id = $_POST['ledger_id'] ?? $_GET['ledger_id'] ?? 0;
$to_date = $_POST['to_date'] ?? $_GET['to_date'] ?? date('Y-m-d');

if ($ledger_id <= 0) die("Invalid ledger");

$ledger = $conn->query("SELECT ledger_name, opening_balance FROM ledgers WHERE ledger_id = $ledger_id")->fetch_assoc();
if (!$ledger) die("Ledger not found");

$ledger_name = $ledger['ledger_name'];
$opening_balance = (float)$ledger['opening_balance'];

// Pagination parameters
$rows_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $rows_per_page;

// Get total number of transactions for this ledger
$total_transactions_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM transactions 
    WHERE ledger_id = $ledger_id AND transaction_date <= '$to_date'
");
$total_transactions = $total_transactions_query->fetch_assoc()['total'];
$total_pages = ceil($total_transactions / $rows_per_page);

// Calculate cumulative totals up to current page
$cumulative_total_dr = $cumulative_total_cr = 0;
if ($current_page > 1) {
    $prev_transactions = $conn->query("
        SELECT transaction_type, amount
        FROM transactions
        WHERE ledger_id = $ledger_id AND transaction_date <= '$to_date'
        ORDER BY transaction_date, transaction_id
        LIMIT " . (($current_page - 1) * $rows_per_page)
    );
    
    while ($row = $prev_transactions->fetch_assoc()) {
        if ($row['transaction_type'] === 'Debit') {
            $cumulative_total_dr += $row['amount'];
        } else {
            $cumulative_total_cr += $row['amount'];
        }
    }
}

// Fetch transactions for current page
$stmt = $conn->prepare("
    SELECT t.transaction_id, t.transaction_date, t.amount, t.transaction_type, t.narration, 
           v.voucher_id, v.voucher_number, v.voucher_type,
           l.ledger_name AS opposite_ledger_name
    FROM transactions t
    JOIN vouchers v ON t.voucher_id = v.voucher_id
    LEFT JOIN ledgers l ON t.opposite_ledger = l.ledger_id
    WHERE t.ledger_id = ? AND t.transaction_date <= ?
    ORDER BY t.transaction_date, t.transaction_id
    LIMIT ?, ?
");

$stmt->bind_param("isii", $ledger_id, $to_date, $offset, $rows_per_page);
$stmt->execute();
$result = $stmt->get_result();

$page_total_dr = $page_total_cr = 0;
$transactions = [];
while ($row = $result->fetch_assoc()) {
    if ($row['transaction_type'] === 'Debit') {
        $page_total_dr += $row['amount'];
    } else {
        $page_total_cr += $row['amount'];
    }
    $transactions[] = $row;
}

// Calculate cumulative totals including current page
$cumulative_total_dr += $page_total_dr;
$cumulative_total_cr += $page_total_cr;

// Calculate closing balance
$closing_balance = $opening_balance + ($cumulative_total_dr - $cumulative_total_cr);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Ledger Voucher Details</title>
    <link rel="stylesheet" href="../styles/trial-bal_style.css">
    <link rel="stylesheet" href="../styles/grpsummary_style.css">
    <link rel="stylesheet" href="../styles/ledger_voucher.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .voucher-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .voucher-table th, .voucher-table td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .amount { text-align: right; }
        .dr { color: #d32f2f; } /* Red for debit */
        .cr { color: #388e3c; } /* Green for credit */
        .accounting-indicator { font-size: 1 rem; margin-left: 5px; }
        .closing-balance { font-weight: bold; }
        .action-bar { margin: 15px 0; display: flex; gap: 10px; align-items: center; }
        .action-bar input, .action-bar button { padding: 5px 10px; }
        .print-only { display: none; }
        .clickable-row { cursor: pointer; }
        
        /* Pagination styles */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 16px;
            margin: 0 4px;
            text-decoration: none;
            color: var(--primary-blue);
            border: 1px solid #ddd;
            border-radius: 4px;
            transition: all 0.3s;
        }
        .pagination a:hover {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }
        .pagination .active {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            font-weight: bold;
        }
        .pagination .disabled {
            color: #aaa;
            pointer-events: none;
            border-color: #ddd;
        }
        .pagination-info {
            text-align: center;
            margin-top: 10px;
            color: var(--primary-blue);
            font-size: 0.9em;
        }
        .cumulative-total {
            font-weight: 600;
            background-color: rgba(76, 175, 80, 0.1);
        }
        
        @media print {
            .no-print { display: none; }
            .print-only { display: block; }
            body { font-size: 12px; }
            .voucher-table { width: 100%; }
        }
    </style>
</head>
<body>
<div class="group-summary-container">
    <div class="breadcrumb">
        <a href="trial_balance.php">Trial Balance</a> &raquo; 
        <a href="group_summary.php?group_id=<?= $group_id ?>&report_date=<?= $to_date ?>">Group Summary</a> &raquo;
        <span>Ledger: <?= htmlspecialchars($ledger_name) ?></span>
    </div>

    <div class="group-summary-header">
        <h1 class="group-summary-title">
        <?= htmlspecialchars($company_db) ?> - Ledger Voucher: <?= htmlspecialchars($ledger_name) ?>
            <span>(As on <?= date('d-M-Y', strtotime($to_date)) ?>)</span>
        </h1>
        
        <form method="post" class="group-summary-form" action="" id="dateForm">
            <input type="hidden" name="group_id" value="<?= $group_id ?>">
            <input type="hidden" name="ledger_id" value="<?= $ledger_id ?>">
            <input type="hidden" name="page" value="1">
            <label for="to_date">Report Date:</label>
            <input type="date" id="to_date" name="to_date" value="<?= $to_date ?>" required>
            <button type="submit" class="button">Generate</button>
            <button type="button" class="button print" onclick="window.print()">Print Report</button>
        </form>
    </div>

    <table class="voucher-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Particulars</th>
                <th>Voucher No.</th>
                <th>Voucher Type</th>
                <th>Narration</th>
                <th>Debit (Dr)</th>
                <th>Credit (Cr)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($transactions as $row): 
            $dr = $row['transaction_type'] === 'Debit' ? $row['amount'] : '';
            $cr = $row['transaction_type'] === 'Credit' ? $row['amount'] : '';
            
            // Define the URL based on voucher_type
            switch ($row['voucher_type']) {
                case 'Payment': $edit_url = "../vouchers_module/edit_payment.php?id=" . $row['voucher_id']; break;
                case 'Receipt': $edit_url = "../vouchers_module/edit_receipt.php?id=" . $row['voucher_id']; break;
                case 'Contra': $edit_url = "../vouchers_module/edit_contra.php?id=" . $row['voucher_id']; break;
                case 'Journal': $edit_url = "../vouchers_module/edit_journal.php?id=" . $row['voucher_id']; break;
                case 'Sales': $edit_url = "../vouchers_module/edit_sales.php?id=" . $row['voucher_id']; break;
                case 'Purchase': $edit_url = "../vouchers_module/edit_purchase.php?id=" . $row['voucher_id']; break;
                default: $edit_url = "#"; break;
            }
        ?>
            <tr class="clickable-row" data-href="<?= $edit_url ?>">
                <td><?= date('d-M-Y', strtotime($row['transaction_date'])) ?></td>
                <td><?= htmlspecialchars($row['opposite_ledger_name']) ?></td>
                <td><?= htmlspecialchars($row['voucher_number']) ?></td>
                <td><?= htmlspecialchars($row['voucher_type']) ?></td>
                <td><?= htmlspecialchars($row['narration']) ?></td>
                <td class="amount"><?= $dr ? number_format($dr, 2) : '' ?></td>
                <td class="amount"><?= $cr ? number_format($cr, 2) : '' ?></td>
            </tr>
        <?php endforeach; ?>

            <tr class="total-row">
                <th colspan="5" align="right">PAGE TOTAL</th>
                <th class="amount"><?= number_format($page_total_dr, 2) ?></th>
                <th class="amount"><?= number_format($page_total_cr, 2) ?></th>
            </tr>
            <tr class="cumulative-total">
                <th colspan="5" align="right">CUMULATIVE TOTAL</th>
                <th class="amount"><?= number_format($cumulative_total_dr, 2) ?></th>
                <th class="amount"><?= number_format($cumulative_total_cr, 2) ?></th>
            </tr>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
        <div class="pagination">
    <?php if ($current_page > 1): ?>
        <a href="?ledger_id=<?= $ledger_id ?>&group_id=<?= $group_id ?>&to_date=<?= $to_date ?>&page=1">First</a>
        <a href="?ledger_id=<?= $ledger_id ?>&group_id=<?= $group_id ?>&to_date=<?= $to_date ?>&page=<?= $current_page - 1 ?>">Previous</a>
    <?php else: ?>
        <span class="disabled">First</span>
        <span class="disabled">Previous</span>
    <?php endif; ?>
    
    <?php
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) echo '<span>...</span>';
    
    for ($i = $start_page; $i <= $end_page; $i++): ?>
        <a href="?ledger_id=<?= $ledger_id ?>&group_id=<?= $group_id ?>&to_date=<?= $to_date ?>&page=<?= $i ?>" <?= $i == $current_page ? 'class="active"' : '' ?>><?= $i ?></a>
    <?php endfor;
    
    if ($end_page < $total_pages) echo '<span>...</span>';
    ?>
    
    <?php if ($current_page < $total_pages): ?>
        <a href="?ledger_id=<?= $ledger_id ?>&group_id=<?= $group_id ?>&to_date=<?= $to_date ?>&page=<?= $current_page + 1 ?>">Next</a>
        <a href="?ledger_id=<?= $ledger_id ?>&group_id=<?= $group_id ?>&to_date=<?= $to_date ?>&page=<?= $total_pages ?>">Last</a>
    <?php else: ?>
        <span class="disabled">Next</span>
        <span class="disabled">Last</span>
    <?php endif; ?>
</div>
    
    <div class="pagination-info">
        Page <?= $current_page ?> of <?= $total_pages ?> | Showing <?= count($transactions) ?> of <?= $total_transactions ?> transactions
    </div>
    <?php endif; ?>

    <div class="summary-container">
        <table class="summary-table">
            <tr>
                <th>Opening Balance</th>
                <td class="<?= $opening_balance >= 0 ? 'dr' : 'cr' ?>">
                    <?= number_format(abs($opening_balance), 2) ?> 
                    <span class="accounting-indicator"><?= $opening_balance >= 0 ? 'Dr' : 'Cr' ?></span>
                </td>
            </tr>
            <tr>
                <th>Total Debits</th>
                <td class="dr">
                    <?= number_format($cumulative_total_dr, 2) ?> 
                    <span class="accounting-indicator">Dr</span>
                </td>
            </tr>
            <tr>
                <th>Total Credits</th>
                <td class="cr">
                    <?= number_format($cumulative_total_cr, 2) ?> 
                    <span class="accounting-indicator">Cr</span>
                </td>
            </tr>
            <tr class="closing-balance">
                <th>Closing Balance</th>
                <td class="<?= $closing_balance >= 0 ? 'dr' : 'cr' ?>">
                    <?= number_format(abs($closing_balance), 2) ?> 
                    <span class="accounting-indicator"><?= $closing_balance >= 0 ? 'Dr' : 'Cr' ?></span>
                </td>
            </tr>
        </table>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll(".clickable-row").forEach(row => {
            row.addEventListener("click", () => {
                const href = row.getAttribute("data-href");
                if (href && href !== "#") {
                    window.location.href = href;
                }
            });
        });
        
        // Focus on date field for quick navigation
        document.getElementById('to_date').focus();
    });
</script>
</body>
</html>