<?php
include '../database/findb.php';
$company_db = $_SESSION['company_name'];

// Check if this is a print request
$is_print = isset($_GET['print']) && $_GET['print'] == 'true';

// 1. Get parameters
$group_id = $_POST['group_id'] ?? $_GET['group_id'] ?? 0;
$report_date = $_POST['report_date'] ?? $_GET['report_date'] ?? date('Y-m-d');

// 2. Validate
if ($group_id <= 0) die("Invalid group access");

// 3. Fetch group name
$group = $conn->query("SELECT group_name FROM groups WHERE group_id = $group_id")->fetch_assoc();
if (!$group) die("Group not found");
$group_name = $group['group_name'];

// Adjust pagination for printing
if ($is_print) {
    $rows_per_page = PHP_INT_MAX; // Show all records when printing
    $current_page = 1;
} else {
    $rows_per_page = 15;
    $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($current_page < 1) $current_page = 1;
}
$offset = ($current_page - 1) * $rows_per_page;

// Get total number of ledgers in this group
$total_ledgers_query = $conn->query("SELECT COUNT(*) as total FROM ledgers WHERE group_id = $group_id");
$total_ledgers = $total_ledgers_query->fetch_assoc()['total'];
$total_pages = ceil($total_ledgers / $rows_per_page);

// Calculate cumulative totals up to current page
$cumulative_total_dr = $cumulative_total_cr = 0;
if ($current_page > 1) {
    $prev_ledgers = $conn->query("
        SELECT ledger_id 
        FROM ledgers 
        WHERE group_id = $group_id 
        ORDER BY ledger_name 
        LIMIT " . (($current_page - 1) * $rows_per_page)
    );
    
    while ($ledger = $prev_ledgers->fetch_assoc()) {
        $ledger_id = $ledger['ledger_id'];
        
        $stmt = $conn->prepare("
            SELECT transaction_type, SUM(amount) as total
            FROM transactions
            WHERE ledger_id = ? AND transaction_date <= ?
            GROUP BY transaction_type
        ");
        $stmt->bind_param("is", $ledger_id, $report_date);
        $stmt->execute();
        $result = $stmt->get_result();

        $dr = $cr = 0;
        while ($row = $result->fetch_assoc()) {
            if ($row['transaction_type'] === 'Debit') $dr = $row['total'];
            else $cr = $row['total'];
        }

        $closing = $dr - $cr;
        $cumulative_total_dr += $closing > 0 ? $closing : 0;
        $cumulative_total_cr += $closing < 0 ? abs($closing) : 0;
    }
}

// Get current page ledgers
$ledgers = $conn->query("
    SELECT ledger_id, ledger_name 
    FROM ledgers 
    WHERE group_id = $group_id 
    ORDER BY ledger_name 
    LIMIT $offset, $rows_per_page
");

$ledger_balances = [];
$page_total_dr = $page_total_cr = 0;

while ($ledger = $ledgers->fetch_assoc()) {
    $ledger_id = $ledger['ledger_id'];
    $ledger_name = $ledger['ledger_name'];

    $stmt = $conn->prepare("
        SELECT transaction_type, SUM(amount) AS total 
        FROM transactions 
        WHERE ledger_id = ? AND transaction_date <= ? 
        GROUP BY transaction_type
    ");
    $stmt->bind_param("is", $ledger_id, $report_date);
    $stmt->execute();
    $res = $stmt->get_result();

    $dr = $cr = 0;
    while ($row = $res->fetch_assoc()) {
        if ($row['transaction_type'] === 'Debit') $dr = $row['total'];
        else if ($row['transaction_type'] === 'Credit') $cr = $row['total'];
    }

    $closing = $dr - $cr;
    $page_total_dr += $closing > 0 ? $closing : 0;
    $page_total_cr += $closing < 0 ? abs($closing) : 0;

    $ledger_balances[] = [
        'ledger_id' => $ledger_id,
        'ledger_name' => $ledger_name,
        'dr' => $closing > 0 ? $closing : 0,
        'cr' => $closing < 0 ? abs($closing) : 0
    ];
}

// Calculate cumulative totals including current page
$cumulative_total_dr += $page_total_dr;
$cumulative_total_cr += $page_total_cr;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Summary</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/trial-bal_style.css">
    <link rel="stylesheet" href="../styles/grpsummary_style.css">
    <style>
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
        
        .back-button {
            background-color:var(--primary-blue);
            color: white;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 18px;
            transition: all 0.2s ease;
        }

        .back-button:hover {
            background-color: #e9ecef;
            border-color: #adb5bd;
        }
        
        .header-top {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
    </style>
    <script>
    function goBack() {
        window.history.back();
    }
    
    function printFullReport() {
        // Get current URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        
        // Create new URL with all current parameters plus print=true
        const printUrl = new URL(window.location.pathname, window.location.origin);
        
        // Add all existing parameters
        urlParams.forEach((value, key) => {
            printUrl.searchParams.set(key, value);
        });
        
        // Add print parameter
        printUrl.searchParams.set('print', 'true');
        
        // Force page=1 to get all records
        printUrl.searchParams.set('page', '1');
        
        window.open(printUrl.toString(), '_blank').print();
    }
    
    window.onload = function() {
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    };
</script>
</head>
<body>
    
<div class="group-summary-container">
    <!-- Breadcrumb navigation -->
    <div class="breadcrumb">
        <a href="trial_balance.php">Trial Balance</a> &raquo; 
        <span>Group Summary: <?= htmlspecialchars($group_name) ?></span>
    </div>
    
    <div class="group-summary-header">
        <div class="header-top">
            <button class="back-button" style = "margin-right:20px;"onclick="goBack()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1 style = "margin-right:0%" class="group-summary-title">
                <?= htmlspecialchars($company_db) ?><br>
                Group Summary: <?= htmlspecialchars($group_name) ?>
                <span>(As on <?= date('d-M-Y', strtotime($report_date)) ?>)</span>
            </h1>
        </div>
        
        <form method="get" class="group-summary-form">
            <input type="hidden" name="group_id" value="<?= $group_id ?>">
            <input type="hidden" name="page" value="1">
            <label for="report_date">Report Date:</label>
            <input type="date" id="report_date" name="report_date" value="<?= $report_date ?>" required>
            <button type="submit" class="button">Generate</button>
            <!-- <button type="button" class="button print" onclick="printFullReport()">Print Report</button> -->
        </form>
    </div>

    <table class="group-summary-table">
        <thead>
            <tr>
                <th>Ledger Name</th>
                <th class="amount">Debit (Dr)</th>
                <th class="amount">Credit (Cr)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ledger_balances as $ledger): ?>
            <form id="ledgerForm<?= $ledger['ledger_id'] ?>" method="post" action="ledger_vouchers.php" style="display:none;">
                <input type="hidden" name="ledger_id" value="<?= $ledger['ledger_id'] ?>">
                <input type="hidden" name="group_id" value="<?= $group_id ?>">
                <input type="hidden" name="to_date" value="<?= $report_date ?>">
            </form>
            <tr class="ledger-row" onclick="document.getElementById('ledgerForm<?= $ledger['ledger_id'] ?>').submit();">
                <td><?= htmlspecialchars($ledger['ledger_name']) ?></td>
                <td class="amount"><?= $ledger['dr'] > 0 ? number_format($ledger['dr'], 2) : '' ?></td>
                <td class="amount"><?= $ledger['cr'] > 0 ? number_format($ledger['cr'], 2) : '' ?></td>
            </tr>
            <?php endforeach; ?>
            
            <tr class="total-row">
                <td align="right">PAGE TOTAL</td>
                <td class="amount"><?= number_format($page_total_dr, 2) ?></td>
                <td class="amount"><?= number_format($page_total_cr, 2) ?></td>
            </tr>
            
            <tr class="cumulative-total">
                <td align="right">CUMULATIVE TOTAL</td>
                <td class="amount"><?= number_format($cumulative_total_dr, 2) ?></td>
                <td class="amount"><?= number_format($cumulative_total_cr, 2) ?></td>
            </tr>
        </tbody>
    </table>
    
    <?php if ($total_pages > 1 && !$is_print): ?>
    <div class="pagination">
        <?php if ($current_page > 1): ?>
            <a href="?group_id=<?= $group_id ?>&report_date=<?= $report_date ?>&page=1">First</a>
            <a href="?group_id=<?= $group_id ?>&report_date=<?= $report_date ?>&page=<?= $current_page - 1 ?>">Previous</a>
        <?php else: ?>
            <span class="disabled">First</span>
            <span class="disabled">Previous</span>
        <?php endif; ?>
        
        <?php
        $start_page = max(1, $current_page - 2);
        $end_page = min($total_pages, $current_page + 2);
        
        if ($start_page > 1) echo '<span>...</span>';
        
        for ($i = $start_page; $i <= $end_page; $i++): ?>
            <a href="?group_id=<?= $group_id ?>&report_date=<?= $report_date ?>&page=<?= $i ?>" <?= $i == $current_page ? 'class="active"' : '' ?>><?= $i ?></a>
        <?php endfor;
        
        if ($end_page < $total_pages) echo '<span>...</span>';
        ?>
        
        <?php if ($current_page < $total_pages): ?>
            <a href="?group_id=<?= $group_id ?>&report_date=<?= $report_date ?>&page=<?= $current_page + 1 ?>">Next</a>
            <a href="?group_id=<?= $group_id ?>&report_date=<?= $report_date ?>&page=<?= $total_pages ?>">Last</a>
        <?php else: ?>
            <span class="disabled">Next</span>
            <span class="disabled">Last</span>
        <?php endif; ?>
    </div>
    
    <div class="pagination-info">
        Page <?= $current_page ?> of <?= $total_pages ?> | Showing <?= count($ledger_balances) ?> of <?= $total_ledgers ?> ledgers
    </div>
    <?php endif; ?>
</div>
</body>
</html>