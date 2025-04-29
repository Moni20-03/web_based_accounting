<?php
include '../database/findb.php';

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$ledger_id = $_GET['ledger_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cash/Bank Book</title>
    <style>
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

        .report-header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-blue);
        }

        .report-header h2 {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary-blue);
            margin: 0 0 5px 0;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
            padding: 15px;
            background-color: rgba(26, 42, 87, 0.05);
            border-radius: 6px;
            align-items: end;
        }

        .filter-form label {
            display: flex;
            flex-direction: column;
            font-weight: 500;
            color: var(--primary-blue);
            font-size: 14px;
        }

        .filter-form select,
        .filter-form input[type="date"] {
            padding: 8px 12px;
            border: 1px solid var(--border-gray);
            border-radius: 4px;
            font-family: inherit;
            transition: border-color 0.2s;
            margin-top: 5px;
        }

        .filter-form select:focus,
        .filter-form input[type="date"]:focus {
            border-color: var(--primary-blue);
            outline: none;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-family: inherit;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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

        .cashbook-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .cashbook-table thead {
            background-color: var(--primary-blue);
            color: var(--white);
        }

        .cashbook-table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .cashbook-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .cashbook-table tbody tr:nth-child(even) {
            background-color: rgba(245, 247, 250, 0.5);
        }

        .cashbook-table .amount {
            text-align: right;
            font-family: 'Courier New', monospace;
        }

        .cashbook-table .debit {
            color: var(--alert-red);
        }

        .cashbook-table .credit {
            color: var(--accounting-green);
        }

        .cashbook-table .balance {
            font-weight: 500;
        }

        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .clickable-row:hover {
            background-color: rgba(26, 42, 87, 0.08) !important;
        }

        .opening-row {
            background-color: rgba(26, 42, 87, 0.1);
            font-weight: bold;
        }

        .total-row {
            background-color: rgba(26, 42, 87, 0.15);
            font-weight: bold;
        }

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
            
            .filter-form, .btn {
                display: none;
            }
            
            .cashbook-table {
                box-shadow: none;
                width: 100%;
            }
            
            .cashbook-table tr {
                page-break-inside: avoid;
            }
            
            @page {
                size: auto;
                margin: 5mm;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="report-header">
            <h2>Cash / Bank Book</h2>
        </div>

        <form method="get" class="filter-form">
            <label>
                Ledger:
                <select name="ledger_id" required>
                    <option value="">Select Ledger</option>
                    <?php 
                    $ledger_list = $conn->query("
                        SELECT ledger_id, ledger_name 
                        FROM ledgers 
                        WHERE group_id IN (
                            SELECT group_id FROM groups WHERE group_name IN ('Cash-in-Hand', 'Bank Accounts')
                        )
                        ORDER BY ledger_name
                    ");
                    while ($ledger = $ledger_list->fetch_assoc()): ?>
                        <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger_id == $ledger['ledger_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ledger['ledger_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </label>

            <label>
                From Date:
                <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" required>
            </label>

            <label>
                To Date:
                <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" required>
            </label>

            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Search</button>
                <button type="button" class="btn btn-print" onclick="window.print()">Print</button>
            </div>
        </form>

        <?php if ($ledger_id): ?>
            <?php
            // Get opening balance
            $open_stmt = $conn->prepare("
                SELECT 
                    SUM(CASE WHEN transaction_type = 'Debit' THEN amount ELSE 0 END) AS total_dr,
                    SUM(CASE WHEN transaction_type = 'Credit' THEN amount ELSE 0 END) AS total_cr
                FROM transactions t
                JOIN vouchers v ON t.voucher_id = v.voucher_id
                WHERE t.ledger_id = ? AND v.voucher_date < ?
            ");
            $open_stmt->bind_param('is', $ledger_id, $from_date);
            $open_stmt->execute();
            $open_result = $open_stmt->get_result()->fetch_assoc();

            $opening_balance = $open_result['total_dr'] - $open_result['total_cr'];
            $running_balance = $opening_balance;

            // Get ledger name for display
            $ledger_name = $conn->query("SELECT ledger_name FROM ledgers WHERE ledger_id = $ledger_id")->fetch_assoc()['ledger_name'];
            ?>

            <table class="cashbook-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Particulars</th>
                        <th>Vch Type</th>
                        <th>Vch No</th>
                        <th class="amount">Dr</th>
                        <th class="amount">Cr</th>
                        <th class="amount">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="opening-row">
                        <td colspan="4">Opening Balance (<?= htmlspecialchars($ledger_name) ?>)</td>
                        <td colspan="2"></td>
                        <td class="amount balance">
                            <?= number_format(abs($running_balance), 2) ?>
                            <small><?= $running_balance >= 0 ? 'Dr' : 'Cr' ?></small>
                        </td>
                    </tr>

                    <?php
                    // Get transactions in date range
                    $txn_stmt = $conn->prepare("
                        SELECT v.voucher_id, v.voucher_date, v.voucher_type, v.voucher_number, 
                               t.amount, t.transaction_type, l.ledger_name
                        FROM transactions t
                        JOIN vouchers v ON t.voucher_id = v.voucher_id
                        JOIN ledgers l ON t.ledger_id = l.ledger_id
                        WHERE t.ledger_id = ? AND v.voucher_date BETWEEN ? AND ?
                        ORDER BY v.voucher_date, v.voucher_number
                    ");
                    $txn_stmt->bind_param('iss', $ledger_id, $from_date, $to_date);
                    $txn_stmt->execute();
                    $transactions = $txn_stmt->get_result();

                    $total_dr = $total_cr = 0;

                    while ($row = $transactions->fetch_assoc()):
                        $dr = $cr = 0;
                        if ($row['transaction_type'] === 'Debit') {
                            $dr = $row['amount'];
                            $total_dr += $dr;
                            $running_balance += $dr;
                        } else {
                            $cr = $row['amount'];
                            $total_cr += $cr;
                            $running_balance -= $cr;
                        }
                    ?>
                        <tr class="clickable-row" onclick="window.location.href='edit_voucher.php?id=<?= $row['voucher_id'] ?>'">
                            <td><?= date('d-M-Y', strtotime($row['voucher_date'])) ?></td>
                            <td><?= htmlspecialchars($row['ledger_name']) ?></td>
                            <td><?= htmlspecialchars($row['voucher_type']) ?></td>
                            <td><?= htmlspecialchars($row['voucher_number']) ?></td>
                            <td class="amount debit"><?= $dr ? number_format($dr, 2) : '' ?></td>
                            <td class="amount credit"><?= $cr ? number_format($cr, 2) : '' ?></td>
                            <td class="amount balance">
                                <?= number_format(abs($running_balance), 2) ?>
                                <small><?= $running_balance >= 0 ? 'Dr' : 'Cr' ?></small>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                    <tr class="total-row">
                        <td colspan="4">Total</td>
                        <td class="amount debit"><?= number_format($total_dr, 2) ?></td>
                        <td class="amount credit"><?= number_format($total_cr, 2) ?></td>
                        <td class="amount balance">
                            <?= number_format(abs($running_balance), 2) ?>
                            <small><?= $running_balance >= 0 ? 'Dr' : 'Cr' ?></small>
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>