<?php
include '../database/findb.php';

// Set default dates to current financial year (April 1 to March 31)
$current_year = date('Y');
$from_date = $_GET['from_date'] ?? $current_year . '-04-01';
$to_date = $_GET['to_date'] ?? (date('m') >= 4 ? ($current_year + 1) . '-03-31' : $current_year . '-03-31');

// Group IDs
$expense_groups = ['Direct Expenses', 'Indirect Expenses', 'Purchase Accounts'];
$income_groups = ['Direct Incomes', 'Indirect Incomes', 'Sales Accounts'];

// Fetch Group IDs
function getGroupIdsByNames($conn, $names) {
    $in = str_repeat('?,', count($names) - 1) . '?';
    $stmt = $conn->prepare("SELECT group_id, group_name FROM groups WHERE group_name IN ($in)");
    $stmt->bind_param(str_repeat('s', count($names)), ...$names);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$expense_group_ids = getGroupIdsByNames($conn, $expense_groups);
$income_group_ids = getGroupIdsByNames($conn, $income_groups);

// Get ledger totals
function getLedgerTotals($conn, $group_ids, $from_date, $to_date, $type) {
    if (empty($group_ids)) return [];
    
    $ids = array_column($group_ids, 'group_id');
    $in = implode(',', $ids);
    
    $sql = "SELECT l.ledger_name, 
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

$expenses = getLedgerTotals($conn, $expense_group_ids, $from_date, $to_date, 'Debit');
$incomes = getLedgerTotals($conn, $income_group_ids, $from_date, $to_date, 'Credit');

// Calculate totals
$total_expense = array_sum(array_column($expenses, 'total'));
$total_income = array_sum(array_column($incomes, 'total'));

$net_profit = max(0, $total_income - $total_expense);
$net_loss = max(0, $total_expense - $total_income);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit and Loss Statement</title>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --border-color: #bdc3c7;
            --shadow-color: rgba(0,0,0,0.1);
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f9f9f9;
            margin: 0;
            padding: 20px;
        }
        
        .pl-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 20px var(--shadow-color);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .pl-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px;
            text-align: center;
            border-bottom: 4px solid var(--light-color);
        }
        
        .pl-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        
        .pl-header h3 {
            margin: 10px 0 0;
            font-weight: 400;
            opacity: 0.9;
        }
        
        .date-filter {
            background: var(--light-color);
            padding: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid var(--border-color);
        }
        
        .date-filter label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        
        .date-filter input[type="date"] {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-family: inherit;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-color);
        }
        
        .btn-print {
            background-color: var(--dark-color);
            color: white;
        }
        
        .btn-print:hover {
            background-color: #1a252f;
        }
        
        .pl-content {
            padding: 20px;
            overflow-x: auto;
        }
        
        .pl-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .pl-table th {
            background-color: var(--light-color);
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
        }
        
        .pl-table td {
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }
        
        .pl-table tr:last-child td {
            border-bottom: none;
        }
        
        .pl-table tr:hover {
            background-color: rgba(0,0,0,0.02);
        }
        
        .amount {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: 500;
        }
        
        .total-row {
            background-color: rgba(0,0,0,0.05);
            font-weight: bold;
        }
        
        .total-row td {
            border-top: 2px solid var(--border-color);
            border-bottom: 2px solid var(--border-color);
        }
        
        .profit {
            color: var(--success-color);
            font-weight: bold;
        }
        
        .loss {
            color: var(--danger-color);
            font-weight: bold;
        }
        
        .empty-cell {
            color: #95a5a6;
            font-style: italic;
        }
        
        @media print {
            body {
                background: none;
                padding: 0;
                font-size: 12px;
            }
            
            .pl-container {
                box-shadow: none;
                border-radius: 0;
            }
            
            .date-filter {
                display: none;
            }
            
            .pl-table {
                page-break-inside: avoid;
            }
        }
        
        @media (max-width: 768px) {
            .pl-header h1 {
                font-size: 22px;
            }
            
            .pl-table {
                font-size: 14px;
            }
            
            .pl-table th, 
            .pl-table td {
                padding: 8px 10px;
            }
        }
    </style>
</head>
<body>

<div class="pl-container">
    <div class="pl-header">
        <h1>Profit and Loss Statement</h1>
        <h3><?= date('d M Y', strtotime($from_date))  ?> to <?= date('d M Y', strtotime($to_date)) ?></h3>
    </div>

    <div class="date-filter">
        <form method="get" class="filter-form">
            <label>From: <input type="date" name="from_date" value="<?= $from_date ?>" required>
            To: <input type="date" name="to_date" value="<?= $to_date ?>" required></label><br>
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
                <?php
                $max_rows = max(count($expenses), count($incomes));
                for ($i = 0; $i < $max_rows; $i++):
                    $exp = $expenses[$i] ?? null;
                    $inc = $incomes[$i] ?? null;
                ?>
                <tr>
                    <td><?= $exp ? htmlspecialchars($exp['ledger_name']) : '<span class="empty-cell">-</span>' ?></td>
                    <td class="amount"><?= $exp ? number_format($exp['total'], 2) : '<span class="empty-cell">-</span>' ?></td>
                    <td><?= $inc ? htmlspecialchars($inc['ledger_name']) : '<span class="empty-cell">-</span>' ?></td>
                    <td class="amount"><?= $inc ? number_format($inc['total'], 2) : '<span class="empty-cell">-</span>' ?></td>
                </tr>
                <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td>Total Expenses</td>
                    <td class="amount"><?= number_format($total_expense, 2) ?></td>
                    <td>Total Income</td>
                    <td class="amount"><?= number_format($total_income, 2) ?></td>
                </tr>
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