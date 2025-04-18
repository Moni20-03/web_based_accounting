<?php
include '../database/findb.php';

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$voucher_type = $_GET['voucher_type'] ?? '';
$ledger_id = $_GET['ledger_id'] ?? '';

// Get ledgers for dropdown
$ledger_options = $conn->query("SELECT ledger_id, ledger_name FROM ledgers ORDER BY ledger_name ASC");
?>

<h2>Day Book</h2>

<form method="get" style="margin-bottom: 15px;">
    <label>From:</label>
    <input type="date" name="from_date" value="<?= $from_date ?>" required>

    <label>To:</label>
    <input type="date" name="to_date" value="<?= $to_date ?>" required>

    <label>Voucher Type:</label>
    <select name="voucher_type">
        <option value="">All</option>
        <?php
        $types = ['Payment', 'Receipt', 'Sales', 'Purchase', 'Contra', 'Journal'];
        foreach ($types as $type): ?>
            <option value="<?= $type ?>" <?= $voucher_type === $type ? 'selected' : '' ?>><?= $type ?></option>
        <?php endforeach; ?>
    </select>

    <label>Ledger:</label>
    <select name="ledger_id">
        <option value="">All</option>
        <?php while ($ledger = $ledger_options->fetch_assoc()): ?>
            <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger_id == $ledger['ledger_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($ledger['ledger_name']) ?>
            </option>
        <?php endwhile; ?>
    </select>

    <button type="submit">Search</button>
    <button type="button" onclick="window.print()">🖨️ Print</button>
</form>

<table border="1" width="100%" cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <thead>
        <tr style="background:#f0f0f0">
            <th>Date</th>
            <th>Particulars</th>
            <th>Vch Type</th>
            <th>Vch No</th>
            <th>Debit (Dr)</th>
            <th>Credit (Cr)</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $query = "SELECT v.voucher_id, v.voucher_number, v.voucher_type, v.voucher_date, 
                         l.ledger_name, t.transaction_type, t.amount 
                  FROM transactions t 
                  JOIN vouchers v ON t.voucher_id = v.voucher_id 
                  JOIN ledgers l ON t.ledger_id = l.ledger_id 
                  WHERE v.voucher_date BETWEEN ? AND ?";

        $params = [$from_date, $to_date];
        $types = 'ss';

        if ($voucher_type) {
            $query .= " AND v.voucher_type = ?";
            $params[] = $voucher_type;
            $types .= 's';
        }

        if ($ledger_id) {
            $query .= " AND t.ledger_id = ?";
            $params[] = $ledger_id;
            $types .= 'i';
        }

        $query .= " ORDER BY v.voucher_date ASC, v.voucher_number ASC, t.transaction_type DESC";

        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()):
            $is_debit = $row['transaction_type'] === 'Debit';
        ?>
            <tr class="clickable-row" data-id="<?= $row['voucher_id'] ?>" style="cursor: pointer;">
                <td><?= date('d-M-Y', strtotime($row['voucher_date'])) ?></td>
                <td><?= htmlspecialchars($row['ledger_name']) ?></td>
                <td><?= $row['voucher_type'] ?></td>
                <td><?= $row['voucher_number'] ?></td>
                <td align="right"><?= $is_debit ? number_format($row['amount'], 2) : '' ?></td>
                <td align="right"><?= !$is_debit ? number_format($row['amount'], 2) : '' ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>

<script>
    // Handle row click
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            const voucherId = row.getAttribute('data-id');
            window.location.href = `view_voucher.php?voucher_id=${voucherId}`;
        });
    });
</script>
