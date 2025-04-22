<?php
include '../database/findb.php';

$company_db = $_SESSION['company_name'];

$group_id = $_POST['group_id'] ?? 0;
$ledger_id = $_POST['ledger_id'] ?? 0;
$to_date = $_POST['to_date'] ?? date('Y-m-d');

if ($ledger_id <= 0) die("Invalid ledger");

$ledger = $conn->query("SELECT ledger_name, opening_balance FROM ledgers WHERE ledger_id = $ledger_id")->fetch_assoc();
if (!$ledger) die("Ledger not found");

$ledger_name = $ledger['ledger_name'];
$opening_balance = (float)$ledger['opening_balance'];

// Fetch all transactions for this ledger up to the report date
$stmt = $conn->prepare("
    SELECT t.transaction_id, t.transaction_date, t.amount, t.transaction_type, t.narration, 
           v.voucher_number, v.voucher_type,
           l.ledger_name AS opposite_ledger_name
    FROM transactions t
    JOIN vouchers v ON t.voucher_id = v.voucher_id
    LEFT JOIN ledgers l ON t.opposite_ledger = l.ledger_id
    WHERE t.ledger_id = ? AND t.transaction_date <= ?
    ORDER BY t.transaction_date, t.transaction_id
");

$stmt->bind_param("is", $ledger_id, $to_date);
$stmt->execute();
$result = $stmt->get_result();
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
        /* .breadcrumb { margin-bottom: 10px; } */
        .dr { color: #d32f2f; } /* Red for debit */
        .cr { color: #388e3c; } /* Green for credit */
        .accounting-indicator { font-size: 1 rem; margin-left: 5px; }
        .closing-balance { font-weight: bold; }
        .action-bar { margin: 15px 0; display: flex; gap: 10px; align-items: center; }
        .action-bar input, .action-bar button { padding: 5px 10px; }
        .print-only { display: none; }
        @media print {
            .no-print { display: none; }
            .print-only { display: block; }
            body { font-size: 12px; }
            .voucher-table { width: 100%; }
        }
    </style>
</head>
<body>
<div class = "group-summary-container">
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
            <?php
            $total_dr = $total_cr = 0;
            while ($row = $result->fetch_assoc()):
                $dr = $row['transaction_type'] === 'Debit' ? $row['amount'] : '';
                $cr = $row['transaction_type'] === 'Credit' ? $row['amount'] : '';
                if ($dr) $total_dr += $row['amount'];
                if ($cr) $total_cr += $row['amount'];
            ?>
            <tr>
                <td><?= date('d-M-Y', strtotime($row['transaction_date'])) ?></td>
                <td><?= htmlspecialchars($row['opposite_ledger_name']) ?></td>
                <td><?= htmlspecialchars($row['voucher_number']) ?></td>
                <td><?= htmlspecialchars($row['voucher_type']) ?></td>
                <td><?= htmlspecialchars($row['narration']) ?></td>
                <td class="amount"><?= $dr ? number_format($dr, 2) : '' ?></td>
                <td class="amount"><?= $cr ? number_format($cr, 2) : '' ?></td>
            </tr>
            <?php endwhile; ?>
            <tr>
                <th colspan="5" align="right">TOTAL</th>
                <th class="amount"><?= number_format($total_dr, 2) ?></th>
                <th class="amount"><?= number_format($total_cr, 2) ?></th>
            </tr>
        </tbody>
        <?php $closing_balance = $opening_balance + ($total_dr - $total_cr); ?>
    </table>

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
                    <?= number_format($total_dr, 2) ?> 
                    <span class="accounting-indicator">Dr</span>
                </td>
            </tr>
            <tr>
                <th>Total Credits</th>
                <td class="cr">
                    <?= number_format($total_cr, 2) ?> 
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
        // Focus on date field for quick navigation
        document.getElementById('to_date').focus();
    </script>
</body>
</html>