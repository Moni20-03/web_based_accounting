<?php
include './database/findb.php';

$voucher_id = '108' ?? null;

if (!$voucher_id) {
    die("Invalid voucher ID");
}

// Fetch voucher + transaction data
include './get_voucher_data.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Voucher</title>
</head>
<body>
    <h2>Edit Voucher #<?= htmlspecialchars($voucher['voucher_number']) ?></h2>

    <form method="POST" action="./update_voucher.php">
        <input type="hidden" name="voucher_id" value="<?= $voucher_id ?>">

        <label>Voucher Date:</label>
        <input type="date" name="voucher_date" value="<?= $voucher['voucher_date'] ?>" required><br><br>

        <label>Reference Number:</label>
        <input type="text" name="reference_number" value="<?= $voucher['reference_number'] ?>"><br><br>

        <label>Narration:</label>
        <textarea name="narration"><?= $voucher['narration'] ?></textarea><br><br>

        <!-- Render existing transactions dynamically -->
        <h3>Transactions:</h3>
        <div id="transactions">
        <?php foreach ($transactions as $i => $txn): ?>
            <div class="txn-row">
                <select name="ledger_ids[]">
                    <?php foreach ($all_ledgers as $ledger): ?>
                        <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger['ledger_id'] == $txn['ledger_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ledger['ledger_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="amounts[]" step="0.01" value="<?= $txn['amount'] ?>" required>
                <select name="types[]">
                    <option value="Debit" <?= $txn['transaction_type'] === 'Debit' ? 'selected' : '' ?>>Debit</option>
                    <option value="Credit" <?= $txn['transaction_type'] === 'Credit' ? 'selected' : '' ?>>Credit</option>
                </select>
                <br><br>
            </div>
        <?php endforeach; ?>
        </div>

        <button type="submit">Update Voucher</button>
    </form>
</body>
</html>
