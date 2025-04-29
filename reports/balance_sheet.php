<?php
include '../database/findb.php';

$company_db = $_SESSION['company_name'];
// Set "As on Date"
$as_on_date = $_GET['as_on_date'] ?? date('Y-m-d');
$report_date = $as_on_date;
// Calculate Net Profit/Loss
function calculate_profit_loss($conn, $as_on_date) {
    $income_total = 0;
    $expense_total = 0;

    $income_ledgers = $conn->query("
        SELECT ledger_id, opening_balance 
        FROM ledgers 
        WHERE group_id IN (SELECT group_id FROM groups WHERE nature='Income')
    ");
    while ($row = $income_ledgers->fetch_assoc()) {
        $ledger_id = $row['ledger_id'];
        $opening = $row['opening_balance'];
        $amount = $conn->query("
            SELECT SUM(CASE WHEN transaction_type='Credit' THEN amount ELSE -amount END) as amt 
            FROM transactions 
            WHERE ledger_id=$ledger_id AND transaction_date <= '$as_on_date'
        ")->fetch_assoc()['amt'] ?? 0;
        $income_total += ($opening + $amount);
    }

    $expense_ledgers = $conn->query("
        SELECT ledger_id, opening_balance 
        FROM ledgers 
        WHERE group_id IN (SELECT group_id FROM groups WHERE nature='Expense')
    ");
    while ($row = $expense_ledgers->fetch_assoc()) {
        $ledger_id = $row['ledger_id'];
        $opening = $row['opening_balance'];
        $amount = $conn->query("
            SELECT SUM(CASE WHEN transaction_type='Debit' THEN amount ELSE -amount END) as amt 
            FROM transactions 
            WHERE ledger_id=$ledger_id AND transaction_date <= '$as_on_date'
        ")->fetch_assoc()['amt'] ?? 0;
        $expense_total += ($opening + $amount);
    }

    return $income_total - $expense_total;
}

$net_profit = calculate_profit_loss($conn, $as_on_date);

// Function to get groups with their totals (only those with transactions)
function get_groups_with_totals($conn, $as_on_date, $nature) {
    $groups = [];
    
    $query = $conn->query("
        SELECT g.group_id, g.group_name, g.nature,
               SUM(l.opening_balance + IFNULL((
                   SELECT SUM(CASE 
                            WHEN t.transaction_type = 'Debit' AND g.nature = 'Asset' THEN t.amount
                            WHEN t.transaction_type = 'Credit' AND g.nature = 'Asset' THEN -t.amount
                            WHEN t.transaction_type = 'Credit' AND g.nature = 'Liability' THEN t.amount
                            WHEN t.transaction_type = 'Debit' AND g.nature = 'Liability' THEN -t.amount
                        END)
                   FROM transactions t 
                   WHERE t.ledger_id = l.ledger_id AND t.transaction_date <= '$as_on_date'
               ), 0)) as group_total
        FROM groups g
        LEFT JOIN ledgers l ON g.group_id = l.group_id
        WHERE g.nature = '$nature'
        GROUP BY g.group_id
        HAVING group_total != 0
        ORDER BY g.group_name
    ");
    
    while ($row = $query->fetch_assoc()) {
        $groups[] = [
            'group_id' => $row['group_id'],
            'group_name' => $row['group_name'],
            'total' => $row['group_total']
        ];
    }
    
    return $groups;
}

// Get assets and liabilities with totals
$assets = get_groups_with_totals($conn, $as_on_date, 'Asset');
$liabilities = get_groups_with_totals($conn, $as_on_date, 'Liability');

// Calculate totals
$total_assets = array_sum(array_column($assets, 'total'));
$total_liabilities = array_sum(array_column($liabilities, 'total'));

// Add net profit/loss to liabilities if not zero (always show as positive value)
if (abs($net_profit) > 0.01) 
{
    $liabilities[] = [
        'group_id' => 'profit_loss',
        'group_name' => $net_profit >= 0 ? 'Profit & Loss Account (Profit)' : 'Profit & Loss Account (Loss)',
        'total' => abs($net_profit)
    ];
    $total_liabilities += abs($net_profit);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Balance Sheet</title>
    <style>
        /* FinPack Day Book Styles */
        :root {
            --primary-blue: #1A2A57;
            --accounting-green: #4CAF50;
            --alert-red: #E53935;
            --light-gray: #F5F7FA;
            --hover-blue: #2A3A77;
            --selected-blue: #3A4A97;
            --white: #FFFFFF;
            --border-gray: #E0E0E0;
        }

        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background-color: var(--light-gray);
            color: var(--primary-blue);
            margin: 0;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 20px auto;
            background-color: var(--white);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            padding: 30px;
        }

        .header-container {
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin-bottom: 20px;
        }

        .company-header {
            font-size: 1.5em;
            font-weight: bold;
            text-align: center;
            color: #2c3e50;
        }

        .report-header {
            text-align: center;
            margin-top: 0;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-blue);
        }

        .back-button {
            background-color: var(--primary-blue);
            color: white;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            transition: all 0.2s ease;
            text-decoration: none;
            position: absolute;
            left: 0;
        }

        .back-button:hover {
            background-color: var(--hover-blue);
        }

        .report-header h2 {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary-blue);
            margin: 0 0 5px 0;
        }

        .report-header h3 {
            font-size: 16px;
            font-weight: 500;
            color: #666;
            margin: 0;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
            padding: 15px;
            background-color: rgba(26, 42, 87, 0.05);
            border-radius: 6px;
            align-items: flex-end;
        }

        .filter-form label {
            display: flex;
            flex-direction: column;
            font-weight: 500;
            color: var(--primary-blue);
            font-size: 14px;
        }

        .filter-form input[type="date"],
        .filter-form select {
            padding: 8px 12px;
            border: 1px solid var(--border-gray);
            border-radius: 4px;
            font-family: inherit;
            transition: border-color 0.2s;
            margin-top: 5px;
        }

        .filter-form input[type="date"]:focus,
        .filter-form select:focus {
            border-color: var(--primary-blue);
            outline: none;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-family: inherit;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
        }

        .btn-primary {
            background-color: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--hover-blue);
        }

        .btn-print {
            background-color: var(--accounting-green);
            color: white;
        }

        .btn-print:hover {
            background-color: #3d8b40;
        }

        .balance-sheet-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .balance-sheet-table thead {
            background-color: var(--primary-blue);
            color: var(--white);
        }

        .balance-sheet-table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .balance-sheet-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .balance-sheet-table tbody tr:nth-child(even) {
            background-color: rgba(245, 247, 250, 0.5);
        }

        .balance-sheet-table .amount {
            text-align: right;
            font-family: 'Courier New', monospace;
        }

        .group-header {
            font-weight: bold;
            background-color: rgba(26, 42, 87, 0.05);
        }

        .total-row {
            font-weight: bold;
            background-color: rgba(26, 42, 87, 0.1);
        }

        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .clickable-row:hover {
            background-color: rgba(26, 42, 87, 0.08) !important;
        }

        /* Print-specific styles */
        @media print {
            body {
                background-color: white;
                padding: 0;
                font-size: 12px;
            }
            
            .container {
                box-shadow: none;
                padding: 0;
                width: 100%;
                margin: 0;
            }
            
            .filter-form, .btn, .back-button {
                display: none;
            }
            
            .balance-sheet-table {
                box-shadow: none;
                width: 100%;
            }
            
            .balance-sheet-table tr {
                page-break-inside: avoid;
            }
            
            .report-header h2 {
                font-size: 18px;
            }
            
            .report-header h3 {
                font-size: 14px;
            }
            
            .company-header {
                font-size: 1.2em;
            }
            
            @page {
                size: auto;
                margin: 5mm;
            }
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .filter-form {
                flex-direction: column;
            }
            
            .balance-sheet-table {
                display: block;
                overflow-x: auto;
            }
            
            .btn-group {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-container">
            <a href="javascript:history.back()" class="back-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
                </svg>
                
            </a>
            <div class="company-header"><?php echo $company_db?> 
            - Balance Sheet</div>
        </div>
        
        <div class="report-header">
            <h3>As on <?= date('d-m-Y', strtotime($as_on_date)) ?></h3>
        </div>

        <form method="get" class="filter-form">
            <label for="as_on_date">
                As on Date
                <input type="date" name="as_on_date" value="<?= htmlspecialchars($as_on_date) ?>" required>
            </label>
            
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/>
                    </svg>
                    Generate
                </button>
                
                <button type="button" class="btn btn-print" onclick="window.print()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M5 1a2 2 0 0 0-2 2v1h10V3a2 2 0 0 0-2-2H5zm6 8H5a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1z"/>
                        <path d="M0 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-1v-2a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2H2a2 2 0 0 1-2-2V7zm2.5 1a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
                    </svg>
                    Print
                </button>
            </div>
        </form>

        <table class="balance-sheet-table">
            <thead>
                <tr>
                    <th>Liabilities</th>
                    <th class="amount">Amount (₹)</th>
                    <th>Assets</th>
                    <th class="amount">Amount (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $max_rows = max(count($liabilities), count($assets));

                for ($i = 0; $i < $max_rows; $i++) {
                    echo "<tr>";
                    
                    // Liability column
                    if (isset($liabilities[$i])) {
                        $liability = $liabilities[$i];
                        if ($liability['group_id'] === 'profit_loss') {
                            // Special handling for Profit & Loss Account
                            echo "<td class='group-header clickable-row' onclick=\"window.location.href='profit_loss.php?report_date=$as_on_date'\">{$liability['group_name']}</td>";
                        } else {
                            // Regular group handling
                            echo "<td class='group-header clickable-row' onclick=\"window.location.href='group_summary.php?group_id={$liability['group_id']}&report_date=$as_on_date'\">{$liability['group_name']}</td>";
                        }
                        echo "<td class='amount'>" . number_format($liability['total'], 2) . "</td>";
                    } else {
                        echo "<td></td><td></td>";
                    }
                    
                    // Asset column
                    if (isset($assets[$i])) {
                        $asset = $assets[$i];
                        echo "<td class='group-header clickable-row' onclick=\"window.location.href='group_summary.php?group_id={$asset['group_id']}&report_date=$as_on_date'\">{$asset['group_name']}</td>";
                        echo "<td class='amount'>" . number_format($asset['total'], 2) . "</td>";
                    } else {
                        echo "<td></td><td></td>";
                    }
                    
                    echo "</tr>";
                }
                ?>
                
                <tr class="total-row">
                    <td>Total Liabilities</td>
                    <td class="amount"><?= number_format($total_liabilities, 2) ?></td>
                    <td>Total Assets</td>
                    <td class="amount"><?= number_format($total_assets, 2) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>