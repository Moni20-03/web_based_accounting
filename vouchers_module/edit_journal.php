<?php
include '../database/findb.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$company_db = $_SESSION['company_name'];
$user_id = $_SESSION['user_id'] ?? 0;
$errors = [];

// Get voucher ID from URL
$voucher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$voucher_id) {
    header("Location: journal_vouchers_list.php");
    exit;
}

// Fetch existing voucher data
$voucher = [];
$transactions = [];
$debit_entries = [];
$credit_entries = [];

$stmt = $conn->prepare("SELECT * FROM vouchers WHERE voucher_id = ? AND voucher_type = 'Journal'");
$stmt->bind_param("i", $voucher_id);
$stmt->execute();
$result = $stmt->get_result();
$voucher = $result->fetch_assoc();
$stmt->close();

if (!$voucher) {
    header("Location: journal_vouchers_list.php");
    exit;
}

// Fetch transactions for this voucher
$stmt = $conn->prepare("SELECT t.*, l.ledger_name, l.acc_code, l.debit_credit 
                        FROM transactions t
                        JOIN ledgers l ON t.ledger_id = l.ledger_id
                        WHERE t.voucher_id = ?");
$stmt->bind_param("i", $voucher_id);
$stmt->execute();
$result = $stmt->get_result();
$transactions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Separate debit and credit entries
foreach ($transactions as $txn) {
    if ($txn['transaction_type'] === 'Debit') {
        $debit_entries[] = $txn;
    } else {
        $credit_entries[] = $txn;
    }
}

// Get all ledgers for dropdowns
$ledgers = $conn->query("SELECT * FROM ledgers ORDER BY ledger_name ASC");

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($errors)) {
    $voucher_number = trim($_POST['voucher_number']);
    $voucher_date = $_POST['voucher_date'];
    
    $debit_ledgers = $_POST['debit_ledger_id'] ?? [];
    $debit_amounts = $_POST['debit_amount'] ?? [];
    $debit_narrations = $_POST['debit_narration'] ?? [];
    $debit_transaction_ids = $_POST['debit_transaction_id'] ?? [];
    
    $credit_ledgers = $_POST['credit_ledger_id'] ?? [];
    $credit_amounts = $_POST['credit_amount'] ?? [];
    $credit_narrations = $_POST['credit_narration'] ?? [];
    $credit_transaction_ids = $_POST['credit_transaction_id'] ?? [];

    // === Basic Validation ===
    if (empty($voucher_number)) $errors[] = "Voucher number missing.";
    if (empty($voucher_date)) $errors[] = "Date is required.";
    if (count($debit_ledgers) == 0) $errors[] = "Please add at least one debit entry.";
    if (count($credit_ledgers) == 0) $errors[] = "Please add at least one credit entry.";

    $total_debit = 0;
    foreach ($debit_ledgers as $index => $ledger_id) {
        $amount = (float)$debit_amounts[$index];

        if (empty($ledger_id) || !is_numeric($amount) || $amount <= 0) {
            $errors[] = "Invalid debit entry at row " . ($index + 1);
            continue;
        }

        $total_debit += $amount;
    }

    $total_credit = 0;
    foreach ($credit_ledgers as $index => $ledger_id) {
        $amount = (float)$credit_amounts[$index];

        if (empty($ledger_id) || !is_numeric($amount) || $amount <= 0) {
            $errors[] = "Invalid credit entry at row " . ($index + 1);
            continue;
        }

        $total_credit += $amount;
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

            $old_voucher_json = json_encode($old_voucher_data);
            $new_voucher_json = json_encode($new_voucher_data);
            $log_stmt->bind_param("iiss", 
                $user_id, 
                $voucher_id, 
                $old_voucher_json,
                $new_voucher_json);
            $log_stmt->execute();
            $log_stmt->close();

            // === Process Debit Entries ===
            foreach ($debit_ledgers as $index => $ledger_id) {
                $amount = (float)$debit_amounts[$index];
                $narration = $debit_narrations[$index] ?? '';
                $transaction_id = $debit_transaction_ids[$index] ?? 0;
                $opposite_ledger_ids = implode(',', $credit_ledgers);
                
                // Get current balance of the debit ledger
                $stmt = $conn->prepare("SELECT current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $ledger_id);
                $stmt->execute();
                $stmt->bind_result($current_balance, $dc_type);
                $stmt->fetch();
                $stmt->close();
                
                // Calculate new balance
                $new_debit_balance = ($dc_type === 'Debit') ? 
                    $current_balance + $amount : 
                    $current_balance - $amount;
                
                if ($transaction_id > 0) {
                    // Update existing debit transaction
                    $stmt = $conn->prepare("UPDATE transactions SET
                                          ledger_id = ?,
                                          amount = ?,
                                          closing_balance = ?,
                                          opposite_ledger = ?,
                                          transaction_date = ?,
                                          narration = ?,
                                          updated_at = NOW()
                                          WHERE transaction_id = ?");
                    $stmt->bind_param("idssssi", 
                        $ledger_id,
                        $amount,
                        $new_debit_balance,
                        $opposite_ledger_ids,
                        $voucher_date,
                        $narration,
                        $transaction_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Log transaction update
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                              (user_id, table_name, record_id, action, old_value, new_value) 
                                              VALUES (?, 'transactions', ?, 'UPDATE', ?, ?)");
                    $log_stmt->bind_param("iiss", 
                        $user_id, 
                        $transaction_id, 
                        json_encode(['ledger_id' => $ledger_id, 'amount' => $amount]),
                        json_encode(['ledger_id' => $ledger_id, 'amount' => $amount]));
                    $log_stmt->execute();
                    $log_stmt->close();
                } else {
                    // Insert new debit transaction
                    $stmt = $conn->prepare("INSERT INTO transactions 
                                          (user_id, voucher_id, ledger_id, acc_code, transaction_type, 
                                           amount, closing_balance, opposite_ledger, 
                                           transaction_date, narration)
                                          VALUES (?, ?, ?, ?, 'Debit', ?, ?, ?, ?, ?)");
                   $acc_code = $debit_entries[$index]['acc_code'] ?? '';
                   $stmt->bind_param("iiisdssss", 
                       $user_id,
                       $voucher_id,
                       $ledger_id,
                       $acc_code,
                       $amount,
                       $new_debit_balance,
                       $opposite_ledger_ids,
                       $voucher_date,
                       $narration);
                    
                    // Log new transaction
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                              (user_id, table_name, record_id, action, old_value, new_value) 
                                              VALUES (?, 'transactions', ?, 'INSERT', NULL, ?)");
                   // First encode the data to JSON
                    $log_data = json_encode([
                        'voucher_id' => $voucher_id,
                        'ledger_id' => $ledger_id,
                        'amount' => $amount,
                        'type' => 'Debit'
                    ]);

                    // Then bind the variable
                    $log_stmt->bind_param("iis", 
                        $user_id, 
                        $transaction_id, 
                        $log_data);  // Pass the variable instead of the function call
                    $log_stmt->execute();
                    $log_stmt->close();
                }
                
                // Update debit ledger balance
                $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $stmt->bind_param("di", $new_debit_balance, $ledger_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // === Process Credit Entries ===
            foreach ($credit_ledgers as $index => $ledger_id) {
                $amount = (float)$credit_amounts[$index];
                $narration = $credit_narrations[$index] ?? '';
                $transaction_id = $credit_transaction_ids[$index] ?? 0;
                $opposite_ledger_ids = implode(',', $debit_ledgers);
                
                // Get current balance of the credit ledger
                $stmt = $conn->prepare("SELECT current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $ledger_id);
                $stmt->execute();
                $stmt->bind_result($current_balance, $dc_type);
                $stmt->fetch();
                $stmt->close();
                
                // Calculate new balance
                $new_credit_balance = ($dc_type === 'Debit') ? 
                    $current_balance - $amount : 
                    $current_balance + $amount;
                
                if ($transaction_id > 0) {
                    // Update existing credit transaction
                    $stmt = $conn->prepare("UPDATE transactions SET
                                          ledger_id = ?,
                                          amount = ?,
                                          closing_balance = ?,
                                          opposite_ledger = ?,
                                          transaction_date = ?,
                                          narration = ?,
                                          updated_at = NOW()
                                          WHERE transaction_id = ?");
                    $stmt->bind_param("idssssi", 
                        $ledger_id,
                        $amount,
                        $new_credit_balance,
                        $opposite_ledger_ids,
                        $voucher_date,
                        $narration,
                        $transaction_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Log transaction update
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                              (user_id, table_name, record_id, action, old_value, new_value) 
                                              VALUES (?, 'transactions', ?, 'UPDATE', ?, ?)");
                    $log_stmt->bind_param("iiss", 
                        $user_id, 
                        $transaction_id, 
                        json_encode(['ledger_id' => $ledger_id, 'amount' => $amount]),
                        json_encode(['ledger_id' => $ledger_id, 'amount' => $amount]));
                    $log_stmt->execute();
                    $log_stmt->close();
                } else {
                    // Insert new credit transaction
                    $stmt = $conn->prepare("INSERT INTO transactions 
                                          (user_id, voucher_id, ledger_id, acc_code, transaction_type, 
                                           amount, closing_balance, opposite_ledger, 
                                           transaction_date, narration)
                                          VALUES (?, ?, ?, ?, 'Credit', ?, ?, ?, ?, ?)");
                    // Store all values in variables first
                    $acc_code = $credit_entries[$index]['acc_code'] ?? '';
                    $stmt->bind_param("iiisdssss", 
                        $user_id,
                        $voucher_id,
                        $ledger_id,
                        $acc_code, 
                        $amount,
                        $new_credit_balance,
                        $opposite_ledger_ids,
                        $voucher_date,
                        $narration);
                    $stmt->execute();
                    $transaction_id = $stmt->insert_id;
                    $stmt->close();
                    
                    // Log new transaction
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                              (user_id, table_name, record_id, action, old_value, new_value) 
                                              VALUES (?, 'transactions', ?, 'INSERT', NULL, ?)");
                    $log_stmt->bind_param("iis", 
                        $user_id, 
                        $transaction_id, 
                        json_encode([
                            'voucher_id' => $voucher_id,
                            'ledger_id' => $ledger_id,
                            'amount' => $amount,
                            'type' => 'Credit'
                        ]));
                    $log_stmt->execute();
                    $log_stmt->close();
                }
                
                // Update credit ledger balance
                $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $stmt->bind_param("di", $new_credit_balance, $ledger_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // === Delete any removed transactions ===
            // For debit entries
            $existing_debit_ids = array_filter($debit_transaction_ids, function($id) { return $id > 0; });
            if (!empty($existing_debit_ids)) {
                $placeholders = implode(',', array_fill(0, count($existing_debit_ids), '?'));
                
                // First log the deletions
                $stmt = $conn->prepare("SELECT * FROM transactions 
                                      WHERE voucher_id = ? 
                                      AND transaction_type = 'Debit'
                                      AND transaction_id NOT IN ($placeholders)");
                $params = array_merge([$voucher_id], $existing_debit_ids);
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
                            'amount' => $deleted_txn['amount']
                        ]));
                    $log_stmt->execute();
                    $log_stmt->close();
                }
                
                // Then delete the transactions
                $stmt = $conn->prepare("DELETE FROM transactions 
                                      WHERE voucher_id = ? 
                                      AND transaction_type = 'Debit'
                                      AND transaction_id NOT IN ($placeholders)");
                $stmt->bind_param(str_repeat('i', count($params)), ...$params);
                $stmt->execute();
                $stmt->close();
            }
            
            // For credit entries
            $existing_credit_ids = array_filter($credit_transaction_ids, function($id) { return $id > 0; });
            if (!empty($existing_credit_ids)) {
                $placeholders = implode(',', array_fill(0, count($existing_credit_ids), '?'));
                
                // First log the deletions
                $stmt = $conn->prepare("SELECT * FROM transactions 
                                      WHERE voucher_id = ? 
                                      AND transaction_type = 'Credit'
                                      AND transaction_id NOT IN ($placeholders)");
                $params = array_merge([$voucher_id], $existing_credit_ids);
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
                            'amount' => $deleted_txn['amount']
                        ]));
                    $log_stmt->execute();
                    $log_stmt->close();
                }
                
                // Then delete the transactions
                $stmt = $conn->prepare("DELETE FROM transactions 
                                      WHERE voucher_id = ? 
                                      AND transaction_type = 'Credit'
                                      AND transaction_id NOT IN ($placeholders)");
                $stmt->bind_param(str_repeat('i', count($params)), ...$params);
                $stmt->execute();
                $stmt->close();
            }
            
            $conn->commit();
            $_SESSION['success_message'] = "Journal voucher updated successfully!";
            header("Location: journal_vouchers_list.php");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error updating voucher: " . $e->getMessage();
        }
    }
}

// Display success message if set
$successMessage = '';
if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

$display_date = date('d-M-Y');
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
        <div class="current-date"><?= date('d-M-Y') ?></div>
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
        setTimeout(function() {
            const msg = document.getElementById('successMessage');
            if (msg) {
                msg.style.transition = 'opacity 0.5s ease-out';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 300);
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
                    <?php foreach ($debit_entries as $index => $txn): ?>
                        <tr>
                            <td>
                                <select name="debit_ledger_id[]" class="form-control" onchange="fetchBalance(this, 'debit_balance_<?= $index ?>'); filterToLedgers(this.value)" required>
                                    <option value="">--Select--</option>
                                    <?php foreach ($ledgers as $ledger): ?>
                                        <?php if (in_array($ledger['acc_type'], ['Asset','Expense']) && !in_array($ledger['book_type'],['Cash','Bank'])): ?>
                                            <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger['ledger_id'] == $txn['ledger_id'] ? 'selected' : '' ?>>
                                                <?= $ledger['ledger_name'] ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <div id="debit_balance_<?= $index ?>" class="balance-box"></div>
                            </td>
                            <td><input type="number" name="debit_amount[]" class="form-control" oninput="autoFillCreditAmount()" step="0.01" min="0.01" value="<?= htmlspecialchars($txn['amount']) ?>" required></td>
                            <td><input type="text" name="debit_narration[]" class="form-control" oninput="autoFillNarration()" value="<?= htmlspecialchars($txn['narration']) ?>"></td>
                            <td>
                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove(); autoFillCreditAmount();">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
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
                    <?php foreach ($credit_entries as $index => $txn): ?>
                        <tr>
                            <td>
                                <select name="credit_ledger_id[]" class="form-control credit-ledger-select" onchange="fetchBalance(this, 'credit_balance_<?= $index ?>')" required>
                                    <option value="">--Select--</option>
                                    <?php foreach ($ledgers as $ledger): ?>
                                        <?php if (in_array($ledger['acc_type'], ['Income', 'Liability'])): ?>
                                            <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger['ledger_id'] == $txn['ledger_id'] ? 'selected' : '' ?>>
                                                <?= $ledger['ledger_name'] ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <div id="credit_balance_<?= $index ?>" class="balance-box"></div>
                            </td>
                            <td><input type="number" name="credit_amount[]" class="form-control" step="0.01" min="0.01" value="<?= htmlspecialchars($txn['amount']) ?>" required></td>
                            <td><input type="text" name="credit_narration[]" class="form-control" value="<?= htmlspecialchars($txn['narration']) ?>"></td>
                            <td>
                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
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
    // Initialize balances for existing rows
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
    
    // Initialize the filter for credit ledgers
    filterToLedgers();
});

let debitRowCount = <?= count($debit_txns) ?>;
let creditRowCount = <?= count($credit_txns) ?>;

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

function filterToLedgers() {
    // Get all selected debit ledger IDs
    const selectedDebitLedgers = Array.from(document.querySelectorAll('select[name="debit_ledger_id[]"]'))
        .map(select => select.value)
        .filter(value => value !== "");
    
    // For each credit ledger select, disable options that are selected in debit ledgers
    document.querySelectorAll('.credit-ledger-select').forEach(select => {
        Array.from(select.options).forEach(option => {
            option.disabled = selectedDebitLedgers.includes(option.value);
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
    let debitLedgers = document.querySelectorAll('select[name="debit_ledger_id[]"]');
    let creditLedgers = document.querySelectorAll('select[name="credit_ledger_id[]"]');
    let errors = [];

    document.querySelectorAll('.is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
    });

    if (debitLedgers.length === 0) {
        errors.push("At least one Debit entry is required.");
    }

    if (creditLedgers.length === 0) {
        errors.push("At least one Credit entry is required.");
    }

    // Validate debit entries
    debitLedgers.forEach((ledger, index) => {
        const row = ledger.closest('tr');
        const amountInput = row.querySelector('input[type="number"]');
        const amount = parseFloat(amountInput.value);

        if (!ledger.value) {
            ledger.classList.add('is-invalid');
            errors.push(`Debit Row ${index + 1}: Please select a ledger`);
        }

        if (isNaN(amount) || amount <= 0) {
            amountInput.classList.add('is-invalid');
            errors.push(`Debit Row ${index + 1}: Please enter a valid amount`);
        }
    });

    // Validate credit entries
    creditLedgers.forEach((ledger, index) => {
        const row = ledger.closest('tr');
        const amountInput = row.querySelector('input[type="number"]');
        const amount = parseFloat(amountInput.value);

        if (!ledger.value) {
            ledger.classList.add('is-invalid');
            errors.push(`Credit Row ${index + 1}: Please select a ledger`);
        }

        if (isNaN(amount) || amount <= 0) {
            amountInput.classList.add('is-invalid');
            errors.push(`Credit Row ${index + 1}: Please enter a valid amount`);
        }
    });

    // Check for duplicate ledgers
    const allLedgerIds = Array.from(document.querySelectorAll('select[name="debit_ledger_id[]"], select[name="credit_ledger_id[]"]'))
        .map(select => select.value)
        .filter(value => value !== "");
    
    const uniqueLedgerIds = [...new Set(allLedgerIds)];
    if (allLedgerIds.length !== uniqueLedgerIds.length) {
        errors.push("The same ledger cannot be used in both Debit and Credit entries.");
    }

    if (errors.length > 0) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error';
        errorDiv.innerHTML = '<strong>Please fix the following errors:</strong><ul>' +
            errors.map(error => `<li>${error}</li>`).join('') + '</ul>';

        const form = document.querySelector('.voucher-form');
        form.insertBefore(errorDiv, form.firstChild);

        const firstInvalid = document.querySelector('.is-invalid');
        if (firstInvalid) {
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.focus();
        }

        return false;
    }

    return true;
}
</script>
</body>
</html>