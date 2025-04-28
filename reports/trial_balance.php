<?php
include '../database/findb.php';

$company_db = $_SESSION['company_name'];
$report_date = $_GET['report_date'] ?? date('Y-m-d');

// Check if this is a print request
$is_print = isset($_GET['print']) && $_GET['print'] == 'true';

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

// Get total number of groups
$total_groups_query = $conn->query("SELECT COUNT(*) as total FROM groups");
$total_groups = $total_groups_query->fetch_assoc()['total'];
$total_pages = ceil($total_groups / $rows_per_page);

// Calculate cumulative totals up to current page
$cumulative_total_dr = $cumulative_total_cr = 0;
if ($current_page > 1) {
    $prev_groups = $conn->query("
        SELECT g.group_id 
        FROM groups g 
        ORDER BY g.group_name 
        LIMIT " . (($current_page - 1) * $rows_per_page)
    );
    
    while ($group = $prev_groups->fetch_assoc()) {
        $group_id = $group['group_id'];
        $ledgers = $conn->query("SELECT ledger_id FROM ledgers WHERE group_id = $group_id");
        
        while ($ledger = $ledgers->fetch_assoc()) {
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
}

// Get current page groups
$groups = $conn->query("
    SELECT group_id, group_name 
    FROM groups 
    ORDER BY group_name 
    LIMIT $offset, $rows_per_page
");

$trial_data = [];
$page_total_dr = $page_total_cr = 0;

while ($group = $groups->fetch_assoc()) {
    $group_id = $group['group_id'];
    $group_name = $group['group_name'];

    $ledgers = $conn->query("SELECT ledger_id, ledger_name FROM ledgers WHERE group_id = $group_id ORDER BY ledger_name");

    $group_total_dr = $group_total_cr = 0;

    while ($ledger = $ledgers->fetch_assoc()) {
        $ledger_id = $ledger['ledger_id'];
        $ledger_name = $ledger['ledger_name'];

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
        $group_total_dr += $closing > 0 ? $closing : 0;
        $group_total_cr += $closing < 0 ? abs($closing) : 0;
    }

    $page_total_dr += $group_total_dr;
    $page_total_cr += $group_total_cr;

    if ($group_total_dr !== 0 || $group_total_cr !== 0) {
        $trial_data[] = [
            'group_id' => $group_id,
            'group_name' => $group_name,
            'total_dr' => $group_total_dr,
            'total_cr' => $group_total_cr,
        ];
    }
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
    <title>Trial Balance</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/trial-bal_style.css">
    <script>
        function goBack() {
            window.location.href = '../dashboards/dashboard.php';
        }
        
        function printFullReport() {
            const url = new URL(window.location.href);
            url.searchParams.set('print', 'true');
            window.open(url.toString(), '_blank').print();
        }

           
    window.onload = function() {
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    };
    
    </script>
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
        
        @media print {
            body {
                background-color: white;
                padding: 0;
                margin: 0;
                font-size: 11pt;
            }
            
            .container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                width: 100%;
            }
            
            .date-form, .button, .back-button, .pagination, .pagination-info {
                display: none !important;
            }
            
            .trial-balance-table {
                box-shadow: none;
                width: 100%;
                page-break-inside: auto;
            }
            
            .trial-balance-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            .trial-balance-table th, 
            .trial-balance-table td {
                padding: 4px 8px !important;
            }
            
            .header {
                flex-direction: column;
                align-items: center;
                margin-bottom: 10px;
            }
            
            .title {
                margin-top: 10px;
                font-size: 14pt;
            }
            
            @page {
                size: A4 portrait;
                margin: 1cm;
                
                @top-center {
                    content: "<?= htmlspecialchars($company_db) ?> - Trial Balance";
                    font-size: 10pt;
                }
                @bottom-right {
                    content: "Page " counter(page) " of " counter(pages);
                    font-size: 10pt;
                }
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <button class="back-button" onclick="goBack()">
            <i class="fas fa-arrow-left"></i>
        </button>
        <h1 class="title"><?= htmlspecialchars($company_db) ?> - Trial Balance as on <?= date('d-M-Y', strtotime($report_date)) ?></h1>
        
        <form method="get" class="date-form">
            <input type="hidden" name="page" value="1">
            <label for="report_date">Report Date:</label>
            <input type="date" id="report_date" name="report_date" value="<?= $report_date ?>" required>
            <button type="submit" class="button">Generate</button>
            <button type="button" class="button print" onclick="printFullReport()">Print Report</button>
        </form>
    </div>

    <table class="trial-balance-table">
        <thead>
            <tr>
                <th>Account Group</th>
                <th class="amount">Debit (Dr)</th>
                <th class="amount">Credit (Cr)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($trial_data as $group): ?>
            <tr class="group-row" onclick="document.getElementById('form-<?= $group['group_id'] ?>').submit()">
                <td><?= htmlspecialchars($group['group_name']) ?></td>
                <td class="amount"><?= $group['total_dr'] > 0 ? number_format($group['total_dr'], 2) : '' ?></td>
                <td class="amount"><?= $group['total_cr'] > 0 ? number_format($group['total_cr'], 2) : '' ?></td>
            </tr>
            <form id="form-<?= $group['group_id'] ?>" method="post" action="group_summary.php" style="display:none;">
                <input type="hidden" name="group_id" value="<?= $group['group_id'] ?>">
                <input type="hidden" name="report_date" value="<?= $report_date ?>">
            </form>
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

            <?php if ($current_page == $total_pages && $cumulative_total_dr != $cumulative_total_cr): ?>
            <tr class="difference-row">
                <td align="right"><i>Difference in Opening Balances</i></td>
                <?php if ($cumulative_total_dr > $cumulative_total_cr): ?>
                    <td></td>
                    <td class="amount"><?= number_format($cumulative_total_dr - $cumulative_total_cr, 2) ?></td>
                <?php else: ?>
                    <td class="amount"><?= number_format($cumulative_total_cr - $cumulative_total_dr, 2) ?></td>
                    <td></td>
                <?php endif; ?>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php if ($total_pages > 1 && !$is_print): ?>
    <div class="pagination">
        <?php if ($current_page > 1): ?>
            <a href="?report_date=<?= $report_date ?>&page=1">First</a>
            <a href="?report_date=<?= $report_date ?>&page=<?= $current_page - 1 ?>">Previous</a>
        <?php else: ?>
            <span class="disabled">First</span>
            <span class="disabled">Previous</span>
        <?php endif; ?>
        
        <?php
        $start_page = max(1, $current_page - 2);
        $end_page = min($total_pages, $current_page + 2);
        
        if ($start_page > 1) echo '<span>...</span>';
        
        for ($i = $start_page; $i <= $end_page; $i++): ?>
            <a href="?report_date=<?= $report_date ?>&page=<?= $i ?>" <?= $i == $current_page ? 'class="active"' : '' ?>><?= $i ?></a>
        <?php endfor;
        
        if ($end_page < $total_pages) echo '<span>...</span>';
        ?>
        
        <?php if ($current_page < $total_pages): ?>
            <a href="?report_date=<?= $report_date ?>&page=<?= $current_page + 1 ?>">Next</a>
            <a href="?report_date=<?= $report_date ?>&page=<?= $total_pages ?>">Last</a>
        <?php else: ?>
            <span class="disabled">Next</span>
            <span class="disabled">Last</span>
        <?php endif; ?>
    </div>
    
    <div class="pagination-info">
        Page <?= $current_page ?> of <?= $total_pages ?> | Showing <?= count($trial_data) ?> of <?= $total_groups ?> groups
    </div>
    <?php endif; ?>
</div>
</body>
</html>