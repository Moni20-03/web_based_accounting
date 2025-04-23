<?php
include '../database/findb.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$user_id = $_SESSION['user_id'] ?? 0;
$company_db = $_SESSION['company_name'];

$errors = [];
$successMessage = '';
$display_date = date('d-M-Y');

// Get voucher ID from URL
$voucher_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$voucher_id) {
    header("Location: journal_vouchers_list.php");
    exit;
}

// Fetch voucher details
$voucher = [];
$stmt = $conn->prepare("SELECT * FROM vouchers WHERE voucher_id = ?");
$stmt->bind_param("i", $voucher_id);
$stmt->execute();
$result = $stmt->get_result();
$voucher = $result->fetch_assoc();
$stmt->close();

if (!$voucher) {
    header("Location: journal_vouchers_list.php");
    exit;
}

// Fetch all transactions for this voucher
$transactions = [];
$stmt = $conn->prepare("SELECT * FROM transactions WHERE voucher_id = ?");
$stmt->bind_param("i", $voucher_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}
$stmt->close();

// Separate debit and credit transactions
$debit_entries = array_filter($transactions, function($txn) { return $txn['transaction_type'] == 'Debit'; });
$credit_entries = array_filter($transactions, function($txn) { return $txn['transaction_type'] == 'Credit'; });

// Get all ledgers
$ledgers = $conn->query("SELECT * FROM ledgers");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $voucher_number = trim($_POST['voucher_number']);
    $voucher_date = $_POST['voucher_date'];
    $voucher_type = 'Journal';

    $debit_ledger_ids = $_POST['debit_ledger_id'] ?? [];
    $debit_amounts = $_POST['debit_amount'] ?? [];
    $debit_narrations = $_POST['debit_narration'] ?? [];

    $credit_ledger_ids = $_POST['credit_ledger_id'] ?? [];
    $credit_amounts = $_POST['credit_amount'] ?? [];
    $credit_narrations = $_POST['credit_narration'] ?? [];
    $transaction_ids = $_POST['transaction_id'] ?? []; // Existing transaction IDs

    // Validation
    if (empty($voucher_number)) $errors[] = "Voucher number missing.";
    if (empty($voucher_date)) $errors[] = "Date is required.";
    if (empty($debit_ledger_ids) || empty($credit_ledger_ids)) {
        $errors[] = "At least one Debit and one Credit entry are required.";
    }

    $total_debit = 0;
    foreach ($debit_ledger_ids as $i => $ledger_id) {
        $amount = (float)$debit_amounts[$i];
        if (!$ledger_id || $amount <= 0) {
            $errors[] = "Debit Row " . ($i + 1) . " is invalid.";
        } else {
            $total_debit += $amount;
        }
    }

    $total_credit = 0;
    foreach ($credit_ledger_ids as $i => $ledger_id) {
        $amount = (float)$credit_amounts[$i];
        if (!$ledger_id || $amount <= 0) {
            $errors[] = "Credit Row " . ($i + 1) . " is invalid.";
        } else {
            $total_credit += $amount;
        }
    }

    if ($total_debit != $total_credit) {
        $errors[] = "Total Debit (₹$total_debit) and Credit (₹$total_credit) must be equal.";
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            // === Update Voucher ===
            $old_voucher_data = [
                'voucher_number' => $voucher['voucher_number'],
                'voucher_date' => $voucher['voucher_date'],
                'total_amount' => $voucher['total_amount']
            ];
            
            $stmt = $conn->prepare("UPDATE vouchers SET 
                                  voucher_number = ?, 
                                  voucher_date = ?, 
                                  total_amount = ?,
                                  updated_at = NOW()
                                  WHERE voucher_id = ?");
            $stmt->bind_param("ssdi", $voucher_number, $voucher_date, $total_debit, $voucher_id);
            $stmt->execute();
            $stmt->close();
            
            // Log voucher update
            $new_voucher_data = [
                'voucher_number' => $voucher_number,
                'voucher_date' => $voucher_date,
                'total_amount' => $total_debit
            ];
            
            $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                      (user_id, table_name, record_id, action, old_value, new_value) 
                                      VALUES (?, 'vouchers', ?, 'UPDATE', ?, ?)");
            $log_stmt->bind_param("iiss", 
                $user_id, 
                $voucher_id, 
                json_encode($old_voucher_data),
                json_encode($new_voucher_data));
            $log_stmt->execute();
            $log_stmt->close();

            // === Process Debit Entries ===
            foreach ($debit_ledger_ids as $i => $ledger_id) {
                $amount = (float)$debit_amounts[$i];
                $narration = (string)($debit_narrations[$i]);
                $txn_id = isset($transaction_ids[$i]) ? $transaction_ids[$i] : 0;
                
                $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $ledger_id);
                $stmt->execute();
                $stmt->bind_result($acc_code, $balance, $dc);
                $stmt->fetch();
                $stmt->close();

                $new_balance = ($dc == 'Debit') ? $balance + $amount : $balance - $amount;
                $opposite_ledger_ids = implode(',', $credit_ledger_ids);

                if ($txn_id > 0) {
                    // Update existing debit transaction
                    // First get old transaction data for audit log
                    $stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_id = ?");
                    $stmt->bind_param("i", $txn_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $old_txn = $result->fetch_assoc();
                    $stmt->close();
                    
                    $old_txn_data = [
                        'ledger_id' => $old_txn['ledger_id'],
                        'amount' => $old_txn['amount'],
                        'closing_balance' => $old_txn['closing_balance'],
                        'narration' => $old_txn['narration']
                    ];
                    
                    $stmt = $conn->prepare("UPDATE transactions SET 
                                          ledger_id = ?, 
                                          acc_code = ?,
                                          amount = ?, 
                                          closing_balance = ?, 
                                          opposite_ledger = ?, 
                                          transaction_date = ?, 
                                          narration = ?,
                                          updated_at = NOW()
                                          WHERE transaction_id = ?");
                    $stmt->bind_param("isdssssi", $ledger_id, $acc_code, $amount, $new_balance, $opposite_ledger_ids, $voucher_date, $narration, $txn_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Log transaction update
                    $new_txn_data = [
                        'ledger_id' => $ledger_id,
                        'amount' => $amount,
                        'closing_balance' => $new_balance,
                        'narration' => $narration
                    ];
                    
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                              (user_id, table_name, record_id, action, old_value, new_value) 
                                              VALUES (?, 'transactions', ?, 'UPDATE', ?, ?)");
                    $log_stmt->bind_param("iiss", 
                        $user_id, 
                        $txn_id, 
                        json_encode($old_txn_data),
                        json_encode($new_txn_data));
                    $log_stmt->execute();
                    $log_stmt->close();
                } else {
                    // Insert new debit transaction
                    $stmt = $conn->prepare("INSERT INTO transactions 
                                          (user_id, voucher_id, ledger_id, acc_code, transaction_type, 
                                           amount, closing_balance, opposite_ledger, transaction_date, narration) 
                                          VALUES (?, ?, ?, ?, 'Debit', ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiisdssss", 
                        $user_id, 
                        $voucher_id, 
                        $ledger_id, 
                        $acc_code,
                        $amount,
                        $new_balance,
                        $opposite_ledger_ids,
                        $voucher_date,
                        $narration);
                    $stmt->execute();
                    $txn_id = $stmt->insert_id;
                    $stmt->close();
                    
                    // Log new transaction
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                              (user_id, table_name, record_id, action, old_value, new_value) 
                                              VALUES (?, 'transactions', ?, 'INSERT', NULL, ?)");
                    $log_stmt->bind_param("iis", 
                        $user_id, 
                        $txn_id, 
                        json_encode([
                            'voucher_id' => $voucher_id,
                            'ledger_id' => $ledger_id,
                            'amount' => $amount,
                            'type' => 'Debit'
                        ]));
                    $log_stmt->execute();
                    $log_stmt->close();
                }
                
                // Update ledger balance
                $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $stmt->bind_param("di", $new_balance, $ledger_id);
                $stmt->execute();
                $stmt->close();
            }

            // === Process Credit Entries ===
            foreach ($credit_ledger_ids as $i => $ledger_id) {
                $amount = (float)$credit_amounts[$i];
                $narration = trim($credit_narrations[$i]);
                $txn_id = isset($transaction_ids[count($debit_ledger_ids) + $i]) ? $transaction_ids[count($debit_ledger_ids) + $i] : 0;
                
                $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $ledger_id);
                $stmt->execute();
                $stmt->bind_result($acc_code, $balance, $dc);
                $stmt->fetch();
                $stmt->close();

                $new_balance = ($dc == 'Debit') ? $balance - $amount : $balance + $amount;
                $opposite_ledger_ids = implode(',', $debit_ledger_ids);

                if ($txn_id > 0) {
                    // Update existing credit transaction
                    // First get old transaction data for audit log
                    $stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_id = ?");
                    $stmt->bind_param("i", $txn_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $old_txn = $result->fetch_assoc();
                    $stmt->close();
                    
                    $old_txn_data = [
                        'ledger_id' => $old_txn['ledger_id'],
                        'amount' => $old_txn['amount'],
                        'closing_balance' => $old_txn['closing_balance'],
                        'narration' => $old_txn['narration']
                    ];
                    
                    $stmt = $conn->prepare("UPDATE transactions SET 
                                          ledger_id = ?, 
                                          acc_code = ?,
                                          amount = ?, 
                                          closing_balance = ?, 
                                          opposite_ledger = ?, 
                                          transaction_date = ?, 
                                          narration = ?,
                                          updated_at = NOW()
                                          WHERE transaction_id = ?");
                    $stmt->bind_param("isdssssi", $ledger_id, $acc_code, $amount, $new_balance, $opposite_ledger_ids, $voucher_date, $narration, $txn_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Log transaction update
                    $new_txn_data = [
                        'ledger_id' => $ledger_id,
                        'amount' => $amount,
                        'closing_balance' => $new_balance,
                        'narration' => $narration
                    ];
                    
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                              (user_id, table_name, record_id, action, old_value, new_value) 
                                              VALUES (?, 'transactions', ?, 'UPDATE', ?, ?)");
                    $log_stmt->bind_param("iiss", 
                        $user_id, 
                        $txn_id, 
                        json_encode($old_txn_data),
                        json_encode($new_txn_data));
                    $log_stmt->execute();
                    $log_stmt->close();
                } else {
                    // Insert new credit transaction
                    $stmt = $conn->prepare("INSERT INTO transactions 
                                          (user_id, voucher_id, ledger_id, acc_code, transaction_type, 
                                           amount, closing_balance, opposite_ledger, transaction_date, narration) 
                                          VALUES (?, ?, ?, ?, 'Credit', ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiisdssss", 
                        $user_id, 
                        $voucher_id, 
                        $ledger_id, 
                        $acc_code,
                        $amount,
                        $new_balance,
                        $opposite_ledger_ids,
                        $voucher_date,
                        $narration);
                    $stmt->execute();
                    $txn_id = $stmt->insert_id;
                    $stmt->close();
                    
                    // Log new transaction
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                              (user_id, table_name, record_id, action, old_value, new_value) 
                                              VALUES (?, 'transactions', ?, 'INSERT', NULL, ?)");
                    $log_stmt->bind_param("iis", 
                        $user_id, 
                        $txn_id, 
                        json_encode([
                            'voucher_id' => $voucher_id,
                            'ledger_id' => $ledger_id,
                            'amount' => $amount,
                            'type' => 'Credit'
                        ]));
                    $log_stmt->execute();
                    $log_stmt->close();
                }
                
                // Update ledger balance
                $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $stmt->bind_param("di", $new_balance, $ledger_id);
                $stmt->execute();
                $stmt->close();
            }

            // === Delete any removed transactions ===
            $all_existing_txn_ids = array_filter($transaction_ids, function($id) { return $id > 0; });
            if (!empty($all_existing_txn_ids)) {
                $placeholders = implode(',', array_fill(0, count($all_existing_txn_ids), '?'));
                $types = str_repeat('i', count($all_existing_txn_ids));
                
                // First log the deletions
                $stmt = $conn->prepare("SELECT * FROM transactions 
                                      WHERE voucher_id = ? 
                                      AND transaction_id NOT IN ($placeholders)");
                $params = array_merge([$voucher_id], $all_existing_txn_ids);
                $stmt->bind_param(str_repeat('i', count($params)), ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $deleted_transactions = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                foreach ($deleted_transactions as $deleted_txn) {
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                              (user_id, table_name, record_id, action, old_value, new_value) 
                                              VALUES (?, 'transactions', ?, 'DELETE', ?, NULL)");
                    $log_stmt->bind_param("iis", 
                        $user_id, 
                        $deleted_txn['transaction_id'], 
                        json_encode([
                            'ledger_id' => $deleted_txn['ledger_id'],
                            'amount' => $deleted_txn['amount'],
                            'type' => $deleted_txn['transaction_type']
                        ]));
                    $log_stmt->execute();
                    $log_stmt->close();
                }
                
                // Then delete the transactions
                $stmt = $conn->prepare("DELETE FROM transactions 
                                      WHERE voucher_id = ? 
                                      AND transaction_id NOT IN ($placeholders)");
                $stmt->bind_param(str_repeat('i', count($params)), ...$params);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $successMessage = "Journal Voucher updated successfully!";
            
            // Refresh the data after update
            header("Location: journal_vouchers_list.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Transaction failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Journal Voucher - FINPACK</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="../styles/form_style.css">
    <link rel="stylesheet" href="../styles/tally_style.css">
    <link rel="stylesheet" href="styles/navbar_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="../index.html">
                <img class="logo" src="../images/logo3.png" alt="Logo">
                <span>FinPack</span> 
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="../dashboards/dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </li>
            <li>
                <a href="#">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </a>
            </li>
            <li>
                <a href="../logout.php" style="color:rgb(235, 71, 53);">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </li>
        </ul>
    </nav>

    <div class="voucher-container tally-style">
    <div class="voucher-header">
        <h2>Edit Journal Voucher</h2>
        <h3><?php echo $company_db ?></h3>
        <div class="current-date"><?= $display_date ?></div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)) : ?>
    <div id="successMessage" style="background-color: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
        <?= htmlspecialchars($successMessage) ?>
    </div>
    <script>
        // Auto-hide the success message after 4 seconds
        setTimeout(function() {
            const msg = document.getElementById('successMessage');
            if (msg) {
                msg.style.transition = 'opacity 0.5s ease-out';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 300); // remove from DOM
            }
        }, 1000);
    </script>
    <?php endif; ?>

    <form method="POST" id="JournalVoucherForm" onsubmit="return validateForm()" class="voucher-form" autocomplete="off">
    <div class="form-row">
        <div class="form-group">
            <label>Voucher No:</label>
            <input class="form-control" type="text" name="voucher_number" value="<?= htmlspecialchars($voucher['voucher_number']) ?>" readonly>
        </div>
        <div class="form-group">
            <label>Date:</label>
            <input type="date" name="voucher_date" value="<?= htmlspecialchars($voucher['voucher_date']) ?>" required>
        </div>
    </div>

    <input type="hidden" name="voucher_type" value="Journal">

    <!-- BY (Debit) Section -->
    <div class="form-group">
        <h4>By Account(s) - Debit</h4>
        <table id="debitTable" style="margin-top: 0px;">
            <thead>
                <tr>
                    <th width="45%">Ledger Account (By)</th>
                    <th width="25%">Amount</th>
                    <th width="25%">Narration</th>
                    <th width="5%">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($debit_entries as $index => $entry): ?>
                <tr>
                    <td>
                        <select name="debit_ledger_id[]" class="form-control" onchange="fetchBalance(this, 'debit_balance_<?= $index ?>');filterToLedgers(this.value)" required>
                            <option value="">--Select--</option>
                            <?php foreach ($ledgers as $ledger): ?>
                                <?php if (in_array($ledger['acc_type'], ['Asset','Expense']) && !in_array($ledger['book_type'],['Cash','Bank'])): ?>
                                    <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger['ledger_id'] == $entry['ledger_id'] ? 'selected' : '' ?>>
                                        <?= $ledger['ledger_name'] ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div id="debit_balance_<?= $index ?>" class="balance-box"></div>
                    </td>
                    <td><input type="number" name="debit_amount[]" class="form-control" oninput="autoFillCreditAmount()" step="0.01" min="0.01" value="<?= htmlspecialchars($entry['amount']) ?>" required></td>
                    <td><input type="text" name="debit_narration[]" class="form-control" oninput="autoFillNarration()" value="<?= htmlspecialchars($entry['narration']) ?>"></td>
                    <td>
                        <input type="hidden" name="transaction_id[]" value="<?= $entry['transaction_id'] ?>">
                        <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($debit_entries)): ?>
                <tr>
                    <td>
                        <select name="debit_ledger_id[]" class="form-control" onchange="fetchBalance(this, 'debit_balance_0');filterToLedgers(this.value)" required>
                            <option value="">--Select--</option>
                            <?php foreach ($ledgers as $ledger): ?>
                                <?php if (in_array($ledger['acc_type'], ['Asset','Expense']) && !in_array($ledger['book_type'],['Cash','Bank'])): ?>
                                    <option value="<?= $ledger['ledger_id'] ?>"><?= $ledger['ledger_name'] ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div id="debit_balance_0" class="balance-box"></div>
                    </td>
                    <td><input type="number" name="debit_amount[]" class="form-control" oninput="autoFillCreditAmount()" step="0.01" min="0.01" required></td>
                    <td><input type="text" name="debit_narration[]" class="form-control" oninput="autoFillNarration()"></td>
                    <td>
                        <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <button type="button" class="submit-button" onclick="addDebitRow()" style="width: 30%; margin-left: 280px;">
            <i class="bi bi-plus-circle"></i> Add Debit Row
        </button>
    </div>

    <!-- TO (Credit) Section -->
    <div class="form-group">
        <h4>To Account(s) - Credit</h4>
        <table id="creditTable" style="margin-top: 0px;">
            <thead>
                <tr>
                    <th width="45%">Ledger Account (To)</th>
                    <th width="25%">Amount</th>
                    <th width="25%">Narration</th>
                    <th width="5%">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($credit_entries as $index => $entry): ?>
                <tr>
                    <td>
                        <select name="credit_ledger_id[]" class="form-control credit-ledger-select" onchange="fetchBalance(this, 'credit_balance_<?= $index ?>')" required>
                            <option value="">--Select--</option>
                            <?php foreach ($ledgers as $ledger): ?>
                                <?php if (in_array($ledger['acc_type'], ['Income', 'Liability'])): ?>
                                    <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger['ledger_id'] == $entry['ledger_id'] ? 'selected' : '' ?>>
                                        <?= $ledger['ledger_name'] ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div id="credit_balance_<?= $index ?>" class="balance-box"></div>
                    </td>
                    <td><input type="number" name="credit_amount[]" class="form-control" step="0.01" min="0.01" value="<?= htmlspecialchars($entry['amount']) ?>" required></td>
                    <td><input type="text" name="credit_narration[]" class="form-control" value="<?= htmlspecialchars($entry['narration']) ?>"></td>
                    <td>
                        <input type="hidden" name="transaction_id[]" value="<?= $entry['transaction_id'] ?>">
                        <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($credit_entries)): ?>
                <tr>
                    <td>
                        <select name="credit_ledger_id[]" class="form-control credit-ledger-select" onchange="fetchBalance(this, 'credit_balance_0')" required>
                            <option value="">--Select--</option>
                            <?php foreach ($ledgers as $ledger): ?>
                                <?php if (in_array($ledger['acc_type'], ['Income', 'Liability'])): ?>
                                    <option value="<?= $ledger['ledger_id'] ?>"><?= $ledger['ledger_name'] ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div id="credit_balance_0" class="balance-box"></div>
                    </td>
                    <td><input type="number" name="credit_amount[]" class="form-control" step="0.01" min="0.01" required></td>
                    <td><input type="text" name="credit_narration[]" class="form-control"></td>
                    <td>
                        <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <button type="button" class="submit-button" onclick="addCreditRow()" style="width: 30%; margin-left: 280px;">
            <i class="bi bi-plus-circle"></i> Add Credit Row
        </button>
    </div>

    <!-- Submit Button -->
    <div class="form-group">
        <button type="submit" class="submit-button" style="width: 40%; margin-left: 230px;">
            <i class="bi bi-save"></i> Update Journal Voucher
        </button>
    </div>
    </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Fetch balances for all existing ledger selections
        document.querySelectorAll('select[name="debit_ledger_id[]"]').forEach((select, index) => {
            if (select.value) {
                fetchBalance(select, `debit_balance_${index}`);
            }
        });
        
        document.querySelectorAll('select[name="credit_ledger_id[]"]').forEach((select, index) => {
            if (select.value) {
                fetchBalance(select, `credit_balance_${index}`);
            }
        });
        
        // Apply ledger filtering based on current selections
        document.querySelectorAll('select[name="debit_ledger_id[]"]').forEach(select => {
            if (select.value) {
                filterToLedgers(select.value);
            }
        });
    });

    let debitRowCount = <?= count($debit_entries) ?: 1 ?>;
    let creditRowCount = <?= count($credit_entries) ?: 1 ?>;

    // Add new Debit (By) row
    function addDebitRow() {
        const table = document.getElementById("debitTable").querySelector("tbody");
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>
                <select name="debit_ledger_id[]" class="form-control debit-ledger-select" onchange="fetchBalance(this, 'debit_balance_${debitRowCount}'); filterToLedgers(this.value)">
                    <option value="">--Select--</option>
                    <?php foreach ($ledgers as $ledger): ?>
                                <?php if (in_array($ledger['acc_type'], ['Asset','Expense']) && !in_array($ledger['book_type'],['Cash','Bank'])): ?>
                                    <option value="<?= $ledger['ledger_id'] ?>"><?= $ledger['ledger_name'] ?></option>
                                <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <div id="debit_balance_${debitRowCount}" class="balance-box"></div>
            </td>
            <td><input type="number" name="debit_amount[]" class="form-control" step="0.01" min="0.01" oninput="autoFillCreditAmount()" required></td>
            <td><input type="text" name="debit_narration[]" class="form-control"></td>
            <td>
                <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove(); autoFillCreditAmount();">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        table.appendChild(row);
        debitRowCount++;
        filterToLedgers();
    }

    // Add new Credit (To) row
    function addCreditRow() {
        const table = document.getElementById("creditTable").querySelector("tbody");
        const row = document.createElement("tr");

        row.innerHTML = `
            <td>
                <select name="credit_ledger_id[]" class="form-control credit-ledger-select" onchange="fetchBalance(this, 'credit_balance_${creditRowCount}')">
                    <option value="">--Select--</option>
                    <?php foreach ($ledgers as $ledger): ?>
                                <?php if (in_array($ledger['acc_type'], ['Income', 'Liability'])): ?>
                                    <option value="<?= $ledger['ledger_id'] ?>"><?= $ledger['ledger_name'] ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                </select>
                <div id="credit_balance_${creditRowCount}" class="balance-box"></div>
            </td>
            <td><input type="number" name="credit_amount[]" class="form-control" step="0.01" min="0.01" required></td>
            <td><input type="text" name="credit_narration[]" class="form-control"></td>
            <td>
                <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        table.appendChild(row);
        creditRowCount++;
    }

    function filterToLedgers(selectedLedgerId) {
        document.querySelectorAll('.credit-ledger-select').forEach(select => {
            Array.from(select.options).forEach(opt => {
                opt.disabled = (opt.value === selectedLedgerId);
            });
        });
    }

    function fetchBalance(selectElem, balanceBoxId) {
        const ledgerId = selectElem.value;
        if (!ledgerId) {
            document.getElementById(balanceBoxId).innerText = '';
            return;
        }

        fetch(`../get_ledger_balance.php?ledger_id=${ledgerId}`)
            .then(response => response.json())
            .then(data => {
                const balanceText = `${data.balance} (${data.type})`;
                document.getElementById(balanceBoxId).innerText = balanceText;
            })
            .catch(error => {
                console.error('Balance fetch error:', error);
            });
    }

    function autoFillCreditAmount() {
        let totalDebit = 0;
        document.querySelectorAll('input[name="debit_amount[]"]').forEach(input => {
            const val = parseFloat(input.value) || 0;
            totalDebit += val;
        });

        document.querySelectorAll('input[name="credit_amount[]"]').forEach(input => {
            input.value = totalDebit.toFixed(2);
        });
    }

    function autoFillNarration() {
        // Get all debit narration inputs
        const debitNarrations = document.querySelectorAll('input[name="debit_narration[]"]');
        
        // Get all credit narration inputs
        const creditNarrations = document.querySelectorAll('input[name="credit_narration[]"]');
        
        // For each debit narration input
        debitNarrations.forEach((debitInput, index) => {
            // If there's a corresponding credit narration input
            if (creditNarrations[index]) {
                // Only auto-fill if the credit narration is empty or matches the debit value
                // This prevents overwriting user edits after initial auto-fill
                const currentDebitValue = debitInput.value;
                const currentCreditValue = creditNarrations[index].value;
                
                if (currentDebitValue && (!currentCreditValue || currentCreditValue === currentDebitValue)) {
                    creditNarrations[index].value = currentDebitValue;
                }
                
                // Add event listener to auto-update when debit changes (if desired)
                debitInput.addEventListener('input', function() {
                    if (!creditNarrations[index].value || creditNarrations[index].value === currentDebitValue) {
                        creditNarrations[index].value = this.value;
                    }
                });
            }
        });
    }

    function validateForm() {
        let errors = [];
        
        // Check at least one debit and one credit entry exists
        const debitRows = document.querySelectorAll('#debitTable tbody tr');
        const creditRows = document.querySelectorAll('#creditTable tbody tr');
        
        if (debitRows.length === 0) errors.push("At least one Debit entry is required.");
        if (creditRows.length === 0) errors.push("At least one Credit entry is required.");
        
        // Check totals match
        let totalDebit = 0;
        document.querySelectorAll('input[name="debit_amount[]"]').forEach(input => {
            totalDebit += parseFloat(input.value) || 0;
        });
        
        let totalCredit = 0;
        document.querySelectorAll('input[name="credit_amount[]"]').forEach(input => {
            totalCredit += parseFloat(input.value) || 0;
        });
        
        if (totalDebit !== totalCredit) {
            errors.push(`Total Debit (${totalDebit.toFixed(2)}) and Credit (${totalCredit.toFixed(2)}) amounts must match.`);
        }
        
        // Check no ledger is used in both debit and credit
        const debitLedgers = Array.from(document.querySelectorAll('select[name="debit_ledger_id[]"]')).map(s => s.value);
        const creditLedgers = Array.from(document.querySelectorAll('select[name="credit_ledger_id[]"]')).map(s => s.value);
        
        const commonLedgers = debitLedgers.filter(id => id && creditLedgers.includes(id));
        if (commonLedgers.length > 0) {
            errors.push("A ledger cannot be used in both Debit and Credit sections.");
        }
        
        if (errors.length > 0) {
            alert("Please fix the following errors:\n\n" + errors.join("\n"));
            return false;
        }
        
        return true;
    }

    window.addEventListener("load", function () {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get("success") === "1") {
            // Success message is already handled at the top
        }  
    });

</script>
</body>
</html>