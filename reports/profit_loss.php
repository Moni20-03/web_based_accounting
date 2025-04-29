<?php
include '../database/findb.php';

$company_db = $_SESSION['company_name'];
// Default dates
$current_year = date('Y');
$from_date = $_GET['from_date'] ?? $current_year . '-04-01';
$to_date = $_GET['to_date'] ?? (date('m') >= 4 ? ($current_year + 1) . '-03-31' : $current_year . '-03-31');

$report_date = $to_date;
// Groups
$direct_expense_groups = ['Direct Expenses', 'Purchase Accounts'];
$indirect_expense_groups = ['Indirect Expenses'];
$direct_income_groups = ['Direct Incomes', 'Sales Accounts'];
$indirect_income_groups = ['Indirect Incomes'];

// Fetch Group IDs
function getGroupIdsByNames($conn, $names) {
    $in = str_repeat('?,', count($names) - 1) . '?';
    $stmt = $conn->prepare("SELECT group_id, group_name FROM groups WHERE group_name IN ($in)");
    $stmt->bind_param(str_repeat('s', count($names)), ...$names);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$direct_expense_group_ids = getGroupIdsByNames($conn, $direct_expense_groups);
$indirect_expense_group_ids = getGroupIdsByNames($conn, $indirect_expense_groups);
$direct_income_group_ids = getGroupIdsByNames($conn, $direct_income_groups);
$indirect_income_group_ids = getGroupIdsByNames($conn, $indirect_income_groups);

// Get ledger totals
function getLedgerTotals($conn, $group_ids, $from_date, $to_date, $type) {
    if (empty($group_ids)) return [];
    
    $ids = array_column($group_ids, 'group_id');
    $in = implode(',', $ids);
    
    $sql = "SELECT l.ledger_id, l.ledger_name,
               SUM(CASE WHEN t.transaction_type = ? THEN t.amount ELSE 0 END) AS total
            FROM ledgers l
            LEFT JOIN transactions t ON l.ledger_id = t.ledger_id
            WHERE l.group_id IN ($in)
            AND (t.transaction_date BETWEEN ? AND ? OR t.transaction_id IS NULL)
            GROUP BY l.ledger_id
            HAVING total > 0
            ORDER BY total DESC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $type, $from_date, $to_date);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Fetch ledgers
$direct_expenses = getLedgerTotals($conn, $direct_expense_group_ids, $from_date, $to_date, 'Debit');
$indirect_expenses = getLedgerTotals($conn, $indirect_expense_group_ids, $from_date, $to_date, 'Debit');
$direct_incomes = getLedgerTotals($conn, $direct_income_group_ids, $from_date, $to_date, 'Credit');
$indirect_incomes = getLedgerTotals($conn, $indirect_income_group_ids, $from_date, $to_date, 'Credit');

// Totals
$total_direct_expense = array_sum(array_column($direct_expenses, 'total'));
$total_indirect_expense = array_sum(array_column($indirect_expenses, 'total'));
$total_direct_income = array_sum(array_column($direct_incomes, 'total'));
$total_indirect_income = array_sum(array_column($indirect_incomes, 'total'));

// Gross Profit or Gross Loss
$gross_profit = max(0, $total_direct_income - $total_direct_expense);
$gross_loss = max(0, $total_direct_expense - $total_direct_income);

// Net Profit or Net Loss
$net_profit = 0;
$net_loss = 0;

if ($gross_profit > 0) {
    $net_profit = $gross_profit + $total_indirect_income - $total_indirect_expense;
    if ($net_profit < 0) {
        $net_loss = abs($net_profit);
        $net_profit = 0;
    }
} else {
    $net_loss = $gross_loss + $total_indirect_expense - $total_indirect_income;
    if ($net_loss < 0) {
        $net_profit = abs($net_loss);
        $net_loss = 0;
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit and Loss Statement</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/profit-and-loss.css?v=1.2">
</head>
<style>
    .back-button{
    background-color:var(--primary-blue);
    color: white;
    border-radius: 4px;
    padding: 6px 12px;
    cursor: pointer;
    display: flex; 
    align-items: left; 
    gap: 15px;
    font-size:18px;
    transition: all 0.2s ease;
    }    

</style>
<script>
    function goBack() {
        window.history.back();
    }
</script>
<body>

<div class="pl-container">
    <div class="company-header" style="display: flex; justify-content: space-between; align-items: center; position: relative;">
            <button class="back-button" onclick="goBack()">
                <i class="fas fa-arrow-left"></i>
            </button>
        <span style="margin: 0 auto; text-align: center;">
            <?php echo $company_db?>
            - Profit and Loss Statement</div>
        </span>
    <div class="pl-header">
        <h3><?= date('d M Y', strtotime($from_date)) ?> to <?= date('d M Y', strtotime($to_date)) ?></h3>
    </div>

    <div class="date-filter">
        <form method="get" class="filter-form">
            <label>From: <input type="date" name="from_date" value="<?= $from_date ?>" required></label>
            <label>To: <input type="date" name="to_date" value="<?= $to_date ?>" required></label>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/>
                        <path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/>
                    </svg>
                    Generate Report
                </button>
                <button type="button" class="btn btn-print" onclick="window.print()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M5 1a2 2 0 0 0-2 2v1h10V3a2 2 0 0 0-2-2H5zm6 8H5a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1z"/>
                        <path d="M0 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-1v-2a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2H2a2 2 0 0 1-2-2V7zm2.5 1a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
                    </svg>
                    Print Report
                </button>
            </div>
        </form>
    </div>

    <div class="pl-content">
        <table class="pl-table">
            <thead>
                <tr>
                    <th colspan="2">Expenses (Debit)</th>
                    <th colspan="2">Income (Credit)</th>
                </tr>
                <tr>
                    <th>Account</th>
                    <th class="amount">Amount</th>
                    <th>Account</th>
                    <th class="amount">Amount</th>
                </tr>
            </thead>
          <tbody>
    <!-- Direct Expenses and Direct Incomes -->
    <?php
    $max_direct = max(count($direct_expenses), count($direct_incomes));
    for ($i = 0; $i < $max_direct; $i++):
        $exp = $direct_expenses[$i] ?? null;
        $inc = $direct_incomes[$i] ?? null;
        
        // Expense form
        if ($exp) {
            echo '<form id="expForm'.$exp['ledger_id'].'" method="post" action="ledger_vouchers.php" style="display:none;">';
            echo '<input type="hidden" name="ledger_id" value="'.$exp['ledger_id'].'">';
            echo '<input type="hidden" name="to_date" value="'.$report_date.'">';
            echo '</form>';
        }
        
        // Income form
        if ($inc) {
            echo '<form id="incForm'.$inc['ledger_id'].'" method="post" action="ledger_vouchers.php" style="display:none;">';
            echo '<input type="hidden" name="ledger_id" value="'.$inc['ledger_id'].'">';
            echo '<input type="hidden" name="to_date" value="'.$report_date.'">';
            echo '</form>';
        }
    ?>
    <tr>
        <td onclick="<?= $exp ? 'document.getElementById(\'expForm'.$exp['ledger_id'].'\').submit()' : 'void(0)' ?>">
            <?= $exp ? htmlspecialchars($exp['ledger_name']) : '<span class="empty-cell">-</span>' ?>
        </td>
        <td class="amount" onclick="<?= $exp ? 'document.getElementById(\'expForm'.$exp['ledger_id'].'\').submit()' : 'void(0)' ?>">
            <?= $exp ? number_format($exp['total'], 2) : '<span class="empty-cell">-</span>' ?>
        </td>
        <td onclick="<?= $inc ? 'document.getElementById(\'incForm'.$inc['ledger_id'].'\').submit()' : 'void(0)' ?>">
            <?= $inc ? htmlspecialchars($inc['ledger_name']) : '<span class="empty-cell">-</span>' ?>
        </td>
        <td class="amount" onclick="<?= $inc ? 'document.getElementById(\'incForm'.$inc['ledger_id'].'\').submit()' : 'void(0)' ?>">
            <?= $inc ? number_format($inc['total'], 2) : '<span class="empty-cell">-</span>' ?>
        </td>
    </tr>
    <?php endfor; ?>

    <!-- Gross Profit/Loss -->
    <?php if ($gross_profit > 0): ?>
    <tr class="profit">
        <td colspan="3" style="text-align: right;">Gross Profit Carried Down</td>
        <td class="amount"><?= number_format($gross_profit, 2) ?></td>
    </tr>
    <?php elseif ($gross_loss > 0): ?>
    <tr class="loss">
        <td colspan="1">Gross Loss Carried Down</td>
        <td class="amount"><?= number_format($gross_loss, 2) ?></td>
        <td colspan="2"></td>
    </tr>
    <?php endif; ?>

    <!-- Indirect Expenses and Incomes -->
    <?php
    $max_indirect = max(count($indirect_expenses), count($indirect_incomes));
    for ($i = 0; $i < $max_indirect; $i++):
        $exp = $indirect_expenses[$i] ?? null;
        $inc = $indirect_incomes[$i] ?? null;
        
        // Expense form
        if ($exp) {
            echo '<form id="expForm'.$exp['ledger_id'].'" method="post" action="ledger_vouchers.php" style="display:none;">';
            echo '<input type="hidden" name="ledger_id" value="'.$exp['ledger_id'].'">';
            echo '<input type="hidden" name="to_date" value="'.$report_date.'">';
            echo '</form>';
        }
        
        // Income form
        if ($inc) {
            echo '<form id="incForm'.$inc['ledger_id'].'" method="post" action="ledger_vouchers.php" style="display:none;">';
            echo '<input type="hidden" name="ledger_id" value="'.$inc['ledger_id'].'">';
            echo '<input type="hidden" name="to_date" value="'.$report_date.'">';
            echo '</form>';
        }
    ?>
    <tr>
        <td onclick="<?= $exp ? 'document.getElementById(\'expForm'.$exp['ledger_id'].'\').submit()' : 'void(0)' ?>">
            <?= $exp ? htmlspecialchars($exp['ledger_name']) : '<span class="empty-cell">-</span>' ?>
        </td>
        <td class="amount" onclick="<?= $exp ? 'document.getElementById(\'expForm'.$exp['ledger_id'].'\').submit()' : 'void(0)' ?>">
            <?= $exp ? number_format($exp['total'], 2) : '<span class="empty-cell">-</span>' ?>
        </td>
        <td onclick="<?= $inc ? 'document.getElementById(\'incForm'.$inc['ledger_id'].'\').submit()' : 'void(0)' ?>">
            <?= $inc ? htmlspecialchars($inc['ledger_name']) : '<span class="empty-cell">-</span>' ?>
        </td>
        <td class="amount" onclick="<?= $inc ? 'document.getElementById(\'incForm'.$inc['ledger_id'].'\').submit()' : 'void(0)' ?>">
            <?= $inc ? number_format($inc['total'], 2) : '<span class="empty-cell">-</span>' ?>
        </td>
    </tr>
    <?php endfor; ?>
</tbody>

            <tfoot>
                <tr class="total-row">
                    <td>Total Expenses</td>
                    <td class="amount"><?= number_format($total_direct_expense + $total_indirect_expense, 2) ?></td>
                    <td>Total Income</td>
                    <td class="amount"><?= number_format($total_direct_income + $total_indirect_income, 2) ?></td>
                </tr>

                <!-- Net Profit / Net Loss -->
                <?php if ($net_profit > 0): ?>
                <tr class="profit">
                    <td colspan="3" style="text-align: right">Net Profit:</td>
                    <td class="amount"><?= number_format($net_profit, 2) ?></td>
                </tr>
                <?php elseif ($net_loss > 0): ?>
                <tr class="loss">
                    <td colspan="3" style="text-align: right">Net Loss:</td>
                    <td class="amount"><?= number_format($net_loss, 2) ?></td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>
    </div>
</div>

</body>
</html>