<?php
include '../database/findb.php';

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// Helper function to get total amount for group type
function get_group_totals($conn, $nature, $from_date, $to_date) {
    $stmt = $conn->prepare("
        SELECT l.ledger_name, 
               SUM(CASE WHEN t.transaction_type = 'Debit' THEN t.amount ELSE 0 END) as total_debit,
               SUM(CASE WHEN t.transaction_type = 'Credit' THEN t.amount ELSE 0 END) as total_credit
        FROM ledgers l
        JOIN groups g ON l.group_id = g.group_id
        LEFT JOIN transactions t ON l.ledger_id = t.ledger_id 
             AND t.transaction_date BETWEEN ? AND ?
        WHERE g.nature = ?
        GROUP BY l.ledger_id
    ");
    $stmt->bind_param("sss", $from_date, $to_date, $nature);
    $stmt->execute();
    return $stmt->get_result();
}

// Get income and expense ledger summaries
$income_ledgers = get_group_totals($conn, 'Income', $from_date, $to_date);
$expense_ledgers = get_group_totals($conn, 'Expense', $from_date, $to_date);
?>

<h2>Profit & Loss Statement</h2>

<form method="get" style="margin-bottom: 15px;">
    <label>From:</label>
    <input type="date" name="from_date" value="<?= $from_date ?>" required>
    <label>To:</label>
    <input type="date" name="to_date" value="<?= $to_date ?>" required>
    <button type="submit">Generate</button>
    <button type="button" onclick="window.print()">🖨️ Print</button>
</form>

<table width="100%" border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>Particulars</th>
            <th align="right">Amount (₹)</th>
        </tr>
    </thead>
    <tbody>
        <tr style="background: #f0f0f0;">
            <td colspan="2"><strong>Income</strong></td>
        </tr>
        <?php
        $total_income = 0;
        while ($row = $income_ledgers->fetch_assoc()):
            $amount = $row['total_credit'] - $row['total_debit'];
            if ($amount <= 0) continue;
            $total_income += $amount;
        ?>
        <tr>
            <td><?= htmlspecialchars($row['ledger_name']) ?></td>
            <td align="right"><?= number_format($amount, 2) ?></td>
        </tr>
        <?php endwhile; ?>
        <tr style="font-weight:bold;">
            <td align="right">Total Income</td>
            <td align="right"><?= number_format($total_income, 2) ?></td>
        </tr>

        <tr style="background: #f0f0f0;">
            <td colspan="2"><strong>Expenses</strong></td>
        </tr>
        <?php
        $total_expense = 0;
        $expense_ledgers->data_seek(0); // Reset pointer
        while ($row = $expense_ledgers->fetch_assoc()):
            $amount = $row['total_debit'] - $row['total_credit'];
            if ($amount <= 0) continue;
            $total_expense += $amount;
        ?>
        <tr>
            <td><?= htmlspecialchars($row['ledger_name']) ?></td>
            <td align="right"><?= number_format($amount, 2) ?></td>
        </tr>
        <?php endwhile; ?>
        <tr style="font-weight:bold;">
            <td align="right">Total Expenses</td>
            <td align="right"><?= number_format($total_expense, 2) ?></td>
        </tr>

        <tr style="font-weight:bold; background: #e0ffe0;">
            <td align="right">
                <?= ($total_income >= $total_expense) ? 'Net Profit' : 'Net Loss' ?>
            </td>
            <td align="right">
                <?= number_format(abs($total_income - $total_expense), 2) ?>
            </td>
        </tr>
    </tbody>
</table>
