<?php
// delete_purchase.php
session_start();
include '../database/findb.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Get voucher ID from URL
$voucher_id = $_GET['id'] ?? 0;

// Check if user has permission (Company Head role)
$user_id = $_SESSION['user_id'];
$role_check = $conn->query("SELECT role FROM users WHERE user_id = $user_id");
$user_role = $role_check->fetch_assoc()['role'] ?? '';

if ($user_role !== 'Company Head') {
    $_SESSION['error'] = "You don't have permission to delete vouchers";
    header("Location: purchase_list.php");
    exit();
}

// Get voucher details before deletion for audit log
$voucher_query = $conn->prepare("
    SELECT v.*, GROUP_CONCAT(CONCAT(t.transaction_type, '|', t.amount, '|', t.ledger_id, '|', l.ledger_name) SEPARATOR ';;') as transactions
    FROM vouchers v
    LEFT JOIN transactions t ON v.voucher_id = t.voucher_id
    LEFT JOIN ledgers l ON t.ledger_id = l.ledger_id
    WHERE v.voucher_id = ?
    GROUP BY v.voucher_id
");
$voucher_query->bind_param("i", $voucher_id);
$voucher_query->execute();
$voucher_data = $voucher_query->get_result()->fetch_assoc();

// Prepare old value for audit log
$old_value = json_encode([
    'voucher' => [
        'voucher_id' => $voucher_data['voucher_id'],
        'voucher_number' => $voucher_data['voucher_number'],
        'voucher_date' => $voucher_data['voucher_date'],
        'voucher_type' => $voucher_data['voucher_type'],
        'narration' => $voucher_data['narration'],
        'created_at' => $voucher_data['created_at']
    ],
    'transactions' => array_map(function($t) {
        $parts = explode('|', $t);
        return [
            'type' => $parts[0],
            'amount' => $parts[1],
            'ledger_id' => $parts[2],
            'ledger_name' => $parts[3]
        ];
    }, explode(';;', $voucher_data['transactions']))
]);

// Start transaction
$conn->begin_transaction();

try {
    // First delete transactions
    $delete_transactions = $conn->prepare("DELETE FROM transactions WHERE voucher_id = ?");
    $delete_transactions->bind_param("i", $voucher_id);
    $delete_transactions->execute();

    // Then delete voucher
    $delete_voucher = $conn->prepare("DELETE FROM vouchers WHERE voucher_id = ?");
    $delete_voucher->bind_param("i", $voucher_id);
    $delete_voucher->execute();

    // Log to audit table
    $audit_log = $conn->prepare("
        INSERT INTO audit_logs 
        (user_id, table_name, record_id, action, old_value, change_time)
        VALUES (?, 'vouchers', ?, 'DELETE', ?, NOW())
    ");
    $audit_log->bind_param("iis", $user_id, $voucher_id, $old_value);
    $audit_log->execute();

    // Commit transaction
    $conn->commit();

    $_SESSION['success'] = "Voucher deleted successfully";
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    $_SESSION['error'] = "Error deleting voucher: " . $e->getMessage();
}

// Redirect back
header("Location: purchase_vouchers_list.php");
exit();
?>