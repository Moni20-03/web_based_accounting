<?php
include 'database/findb.php';

if (isset($_GET['ledger_id'])) {
    $ledger_id = intval($_GET['ledger_id']);

    $stmt = $conn->prepare("SELECT current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
    $stmt->bind_param("i", $ledger_id);
    $stmt->execute();
    $stmt->bind_result($balance, $dc_type);
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => true,
            'balance' => number_format($balance, 2),
            'type' => $dc_type
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
    $stmt->close();
}
?>
