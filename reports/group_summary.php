<?php
include '../database/findb.php';
$company_db = $_SESSION['company_name'];
// 1. Always prioritize the NEW date from the form submission
$group_id = $_POST['group_id'] ?? $_GET['group_id'] ?? 0; // Supports both GET/POST
$report_date = $_POST['report_date'] ?? $_GET['report_date'] ?? date('Y-m-d'); // Updated line

// 2. Validate
if ($group_id <= 0) die("Invalid group access");

// 3. Fetch group name (unchanged)
$group = $conn->query("SELECT group_name FROM groups WHERE group_id = $group_id")->fetch_assoc();
if (!$group) die("Group not found");
$group_name = $group['group_name'];

// 4. Fetch ledgers WITH UPDATED DATE FILTER
$ledgers = $conn->query("SELECT ledger_id, ledger_name FROM ledgers WHERE group_id = $group_id ORDER BY ledger_name");
$ledger_balances = [];

while ($ledger = $ledgers->fetch_assoc()) {
    $ledger_id = $ledger['ledger_id'];
    $ledger_name = $ledger['ledger_name'];
    // KEY CHANGE: Use the CURRENT $report_date (not hardcoded)
    $stmt = $conn->prepare("
        SELECT transaction_type, SUM(amount) AS total 
        FROM transactions 
        WHERE ledger_id = ? AND transaction_date <= ? 
        GROUP BY transaction_type
    ");
    $stmt->bind_param("is", $ledger_id, $report_date); // Dynamic date
    $stmt->execute();
    $res = $stmt->get_result();

    $dr = $cr = 0;
    while ($row = $res->fetch_assoc()) {
        if ($row['transaction_type'] === 'Debit') $dr = $row['total'];
        else if ($row['transaction_type'] === 'Credit') $cr = $row['total'];
    }

    $closing = $dr - $cr;
    $ledger_balances[] = [
        'ledger_id' => $ledger_id,
        'ledger_name' => $ledger_name,
        'dr' => $closing > 0 ? $closing : 0,
        'cr' => $closing < 0 ? abs($closing) : 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Summary</title>
    <link rel="stylesheet" href="../styles/trial-bal_style.css">
    <link rel="stylesheet" href="../styles/grpsummary_style.css">
    <script>
        // Prevent back navigation - redirect to dashboard instead
        history.pushState(null, null, document.URL);
        window.addEventListener('popstate', function() {
            window.location.href = '../dashboards/dashboard.php'; // Change to your dashboard path
        });
        
        // Also handle direct access by replacing the initial history entry
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
        <h1 class="group-summary-title">
        <?= htmlspecialchars($company_db) ?> - Group Summary: <?= htmlspecialchars($group_name) ?>
            <span>(As on <?= date('d-M-Y', strtotime($report_date)) ?>)</span>
        </h1>
        
        <form method="get" class="group-summary-form">
            <input type="hidden" name="group_id" value="<?= $group_id ?>">
            <label for="report_date">Report Date:</label>
            <input type="date" id="report_date" name="report_date" value="<?= $report_date ?>" required>
            <button type="submit" class="button">Generate</button>
            <button type="button" class="button print" onclick="window.print()">Print Report</button>
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
            <?php
            $total_dr = $total_cr = 0;
            foreach ($ledger_balances as $ledger):
                $total_dr += $ledger['dr'];
                $total_cr += $ledger['cr'];
            ?>
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
                <td align="right">TOTAL</td>
                <td class="amount"><?= number_format($total_dr, 2) ?></td>
                <td class="amount"><?= number_format($total_cr, 2) ?></td>
            </tr>
        </tbody>
    </table>
</div>

</body>
</html>