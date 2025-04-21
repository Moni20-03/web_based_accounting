<?php
// Fetch voucher details
$stmt = $conn->prepare("SELECT * FROM vouchers WHERE voucher_id = ?");
$stmt->bind_param("i", $voucher_id);
$stmt->execute();
$result = $stmt->get_result();
$voucher = $result->fetch_assoc();

// Fetch transactions
$txn_stmt = $conn->prepare("SELECT * FROM transactions WHERE voucher_id = ?");
$txn_stmt->bind_param("i", $voucher_id);
$txn_stmt->execute();
$txn_result = $txn_stmt->get_result();
$transactions = $txn_result->fetch_all(MYSQLI_ASSOC);

// Fetch all ledgers
$ledgers_result = $conn->query("SELECT ledger_id, ledger_name FROM ledgers ORDER BY ledger_name ASC");
$all_ledgers = $ledgers_result->fetch_all(MYSQLI_ASSOC);
?>