<?php
include '../database/findb.php';

$ledger_id = $_GET['ledger_id'] ?? 0;
$to_date = $_GET['to_date'] ?? date('Y-m-d');

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
    <link rel="stylesheet" href="../styles/ledger_voucher.css">
    <style>
        .voucher-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .voucher-table th, .voucher-table td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .amount { text-align: right; }
        .breadcrumb { margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="breadcrumb">
        <a href="trial_balance.php">Trial Balance</a> &raquo;
        <a href="group_summary.php?group_id=<?= $_GET['group_id'] ?? '' ?>&report_date=<?= $to_date ?>">Group Summary</a> &raquo;
        <span>Ledger: <?= htmlspecialchars($ledger_name) ?></span>
    </div>

    <h2>Ledger: <?= htmlspecialchars($ledger_name) ?> (Up to <?= date('d-M-Y', strtotime($to_date)) ?>)</h2>

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
</body>
</html>
