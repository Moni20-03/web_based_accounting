<?php
include './database/findb.php';
// session_start();

$user_id = $_SESSION['user_id'];
$voucher_id = $_POST['voucher_id'];
$voucher_date = $_POST['voucher_date'];
$reference_number = $_POST['reference_number'];
$narration = $_POST['narration'];
$ledger_ids = $_POST['ledger_ids'];
$amounts = $_POST['amounts'];
$types = $_POST['types'];

// 1. Fetch old voucher & transactions for audit log
$old_voucher = $conn->query("SELECT * FROM vouchers WHERE voucher_id = $voucher_id")->fetch_assoc();
$old_transactions = $conn->query("SELECT * FROM transactions WHERE voucher_id = $voucher_id")->fetch_all(MYSQLI_ASSOC);

// 2. Update voucher
$total_amount = array_sum($amounts);
$update_voucher = $conn->prepare("UPDATE vouchers SET voucher_date = ?, reference_number = ?, total_amount = ? WHERE voucher_id = ?");
$update_voucher->bind_param("ssdi", $voucher_date, $reference_number, $total_amount, $voucher_id);
$update_voucher->execute();

// 3. Audit log for voucher update
$log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) VALUES (?, 'vouchers', ?, 'UPDATE', ?, ?)");
$old_value = json_encode($old_voucher);
$new_value = json_encode([
    'voucher_date' => $voucher_date,
    'reference_number' => $reference_number,
    'narration' => $narration,
    'total_amount' => $total_amount
]);
$log_stmt->bind_param("isss", $user_id, $voucher_id, $old_value, $new_value);
$log_stmt->execute();

// 4. Delete old transactions (and restore balances)
foreach ($old_transactions as $txn) {
    // Reverse ledger balance update
    $multiplier = $txn['transaction_type'] === 'Debit' ? -1 : 1;
    $conn->query("UPDATE ledgers SET current_balance = current_balance + ($multiplier * {$txn['amount']}) WHERE ledger_id = {$txn['ledger_id']}");

    // Audit log for delete
    $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) VALUES (?, 'transactions', ?, 'DELETE', ?, NULL)");
    $old_txn_json = json_encode($txn);
    $log_stmt->bind_param("iss", $user_id, $txn['transaction_id'], $old_txn_json);
    $log_stmt->execute();
}

// Actually delete transactions
$conn->query("DELETE FROM transactions WHERE voucher_id = $voucher_id");

// 5. Insert new transactions
foreach ($ledger_ids as $index => $ledger_id) {
    $amount = $amounts[$index];
    $type = $types[$index];

    // Insert
    $txn_stmt = $conn->prepare("INSERT INTO transactions (voucher_id, ledger_id, amount, transaction_type,user_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $txn_stmt->bind_param("iidss", $voucher_id, $ledger_id, $amount, $type, $user_id);
    $txn_stmt->execute();
    $txn_id = $txn_stmt->insert_id;

    // Update ledger balance
    $multiplier = $type === 'Debit' ? 1 : -1;
    $conn->query("UPDATE ledgers SET current_balance = current_balance + ($multiplier * $amount) WHERE ledger_id = $ledger_id");

    // Get updated balance for logging
    $bal_res = $conn->query("SELECT current_balance FROM ledgers WHERE ledger_id = $ledger_id");
    $bal_row = $bal_res->fetch_assoc();
    $new_balance = $bal_row['balance'];

    // Audit log for insert
    $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) VALUES (?, 'transactions', ?, 'INSERT', NULL, ?)");
    $txn_json = json_encode([
        'voucher_id' => $voucher_id,
        'ledger_id' => $ledger_id,
        'amount' => $amount,
        'type' => $type,
        'closing_balance' => $new_balance
    ]);
    $log_stmt->bind_param("iis", $user_id, $txn_id, $txn_json);
    $log_stmt->execute();
}

// ✅ Done
header("Location: ../report_daybook.php?msg=Voucher+Updated");
exit;
?>
