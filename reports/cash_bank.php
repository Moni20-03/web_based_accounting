<?php
include '../database/findb.php';

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$ledger_id = $_GET['ledger_id'] ?? '';

// Get only Cash & Bank ledgers
$ledger_list = $conn->query("
    SELECT ledger_id, ledger_name 
    FROM ledgers 
    WHERE group_id IN (
        SELECT group_id FROM groups WHERE group_name IN ('Cash-in-Hand', 'Bank Accounts')
    )
    ORDER BY ledger_name
");
?>

<h2>Cash / Bank Book</h2>

<form method="get">
    <label>Ledger:</label>
    <select name="ledger_id" required>
        <option value="">Select Ledger</option>
        <?php while ($ledger = $ledger_list->fetch_assoc()): ?>
            <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger_id == $ledger['ledger_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($ledger['ledger_name']) ?>
            </option>
        <?php endwhile; ?>
    </select>

    <label>From:</label>
    <input type="date" name="from_date" value="<?= $from_date ?>" required>

    <label>To:</label>
    <input type="date" name="to_date" value="<?= $to_date ?>" required>

    <button type="submit">Search</button>
    <button type="button" onclick="window.print()">🖨️ Print</button>
</form>

<?php
if ($ledger_id) {
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

    echo "<br><b>Opening Balance:</b> " . number_format(abs($opening_balance), 2) . ' ' . ($opening_balance >= 0 ? 'Dr' : 'Cr');

    // Get transactions in date range
    $txn_stmt = $conn->prepare("
        SELECT v.voucher_date, v.voucher_type, v.voucher_number, 
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

    echo "<table border='1' width='100%' cellpadding='6'>";
    echo "<thead>
            <tr>
                <th>Date</th>
                <th>Particulars</th>
                <th>Vch Type</th>
                <th>Vch No</th>
                <th>Dr</th>
                <th>Cr</th>
                <th>Balance</th>
            </tr>
        </thead><tbody>";

    echo "<tr><td colspan='6'>Opening Balance</td><td align='right'>" . number_format(abs($running_balance), 2) . ' ' . ($running_balance >= 0 ? 'Dr' : 'Cr') . "</td></tr>";

    $total_dr = $total_cr = 0;

    while ($row = $transactions->fetch_assoc()) {
        $dr = $cr = '';
        if ($row['transaction_type'] === 'Debit') {
            $dr = $row['amount'];
            $total_dr += $dr;
            $running_balance += $dr;
        } else {
            $cr = $row['amount'];
            $total_cr += $cr;
            $running_balance -= $cr;
        }

        echo "<tr>
            <td>" . date('d-M-Y', strtotime($row['voucher_date'])) . "</td>
            <td>{$row['ledger_name']}</td>
            <td>{$row['voucher_type']}</td>
            <td>{$row['voucher_number']}</td>
            <td align='right'>" . ($dr ? number_format($dr, 2) : '') . "</td>
            <td align='right'>" . ($cr ? number_format($cr, 2) : '') . "</td>
            <td align='right'>" . number_format(abs($running_balance), 2) . ' ' . ($running_balance >= 0 ? 'Dr' : 'Cr') . "</td>
        </tr>";
    }

    echo "<tr style='font-weight:bold'>
        <td colspan='4' align='right'>Total</td>
        <td align='right'>" . number_format($total_dr, 2) . "</td>
        <td align='right'>" . number_format($total_cr, 2) . "</td>
        <td></td>
    </tr>";

    echo "</tbody></table>";
}
?>
