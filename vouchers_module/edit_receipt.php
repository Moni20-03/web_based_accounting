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
    header("Location: receipt_vouchers_list.php");
    exit;
}

// Fetch existing voucher data
$voucher = [];
$transactions = [];
$debit_entry = null;
$credit_entries = [];

$stmt = $conn->prepare("SELECT * FROM vouchers WHERE voucher_id = ? AND voucher_type = 'Receipt'");
$stmt->bind_param("i", $voucher_id);
$stmt->execute();
$result = $stmt->get_result();
$voucher = $result->fetch_assoc();
$stmt->close();

if (!$voucher) {
    header("Location: receipt_vouchers_list.php");
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
        $debit_entry = $txn;
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
    $debit_ledger_id = $_POST['debit_ledger_id'];
    $mode_of_payment = $_POST['mode_of_payment'];
    $reference_number = $_POST['reference_number'] ?? null;
    
    $credit_ledgers = $_POST['credit_ledger_id'] ?? [];
    $credit_amounts = $_POST['credit_amount'] ?? [];
    $narrations = $_POST['credit_narration'] ?? [];
    $transaction_ids = $_POST['transaction_id'] ?? []; // For existing credit entries

    // === Basic Validation ===
    if (empty($voucher_number)) $errors[] = "Voucher number missing.";
    if (empty($voucher_date)) $errors[] = "Date is required.";
    if (empty($debit_ledger_id)) $errors[] = "Please select Cash/Bank account.";

    if (count($credit_ledgers) == 0) $errors[] = "Please add at least one credit entry.";

    $total_amount = 0;
    foreach ($credit_ledgers as $index => $ledger_id) {
        $amount = (float)$credit_amounts[$index];

        if (empty($ledger_id) || !is_numeric($amount) || $amount <= 0) {
            $errors[] = "Invalid credit entry at row " . ($index + 1);
            continue;
        }

        $total_amount += $amount;

        if ($ledger_id == $debit_ledger_id) {
            $errors[] = "Debit and Credit ledger cannot be the same.";
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            // === Update Voucher ===
            $old_voucher_data = [
                'voucher_number' => $voucher['voucher_number'],
                'reference_number' => $voucher['reference_number'],
                'voucher_date' => $voucher['voucher_date'],
                'total_amount' => $voucher['total_amount']
            ];
            
            $stmt = $conn->prepare("UPDATE vouchers SET 
                                  voucher_number = ?, 
                                  reference_number = ?, 
                                  voucher_date = ?, 
                                  total_amount = ?,
                                  updated_at = NOW()
                                  WHERE voucher_id = ?");
            $stmt->bind_param("sssdi", $voucher_number, $reference_number, $voucher_date, $total_amount, $voucher_id);
            $stmt->execute();
            $stmt->close();
            
            // Log voucher update
            $new_voucher_data = [
                'voucher_number' => $voucher_number,
                'reference_number' => $reference_number,
                'voucher_date' => $voucher_date,
                'total_amount' => $total_amount
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

            // === Process Debit Entry ===
            $old_debit_data = [
                'ledger_id' => $debit_entry['ledger_id'],
                'amount' => $debit_entry['amount'],
                'closing_balance' => $debit_entry['closing_balance'],
                'mode_of_payment' => $debit_entry['mode_of_payment']
            ];
            
            // Get current balance of the debit ledger
            $stmt = $conn->prepare("SELECT current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
            $stmt->bind_param("i", $debit_ledger_id);
            $stmt->execute();
            $stmt->bind_result($current_balance, $dc_type);
            $stmt->fetch();
            $stmt->close();
            
            // Calculate new balance
            $new_debit_balance = ($dc_type === 'Debit') ? 
                $current_balance + $total_amount : 
                $current_balance - $total_amount;
            
            // Update debit transaction
            $opposite_ledger_ids = implode(',', $credit_ledgers);
            $narration_summary = "Receipt made from ledger ID: $debit_ledger_id";
            
            $stmt = $conn->prepare("UPDATE transactions SET
                                  ledger_id = ?,
                                  amount = ?,
                                  closing_balance = ?,
                                  mode_of_payment = ?,
                                  opposite_ledger = ?,
                                  transaction_date = ?,
                                  narration = ?,
                                  updated_at = NOW()
                                  WHERE transaction_id = ?");
            $stmt->bind_param("idsssssi", 
                $debit_ledger_id,
                $total_amount,
                $new_debit_balance,
                $mode_of_payment,
                $opposite_ledger_ids,
                $voucher_date,
                $narration_summary,
                $debit_entry['transaction_id']);
            $stmt->execute();
            $stmt->close();
            
            // Log debit transaction update
            $new_debit_data = [
                'ledger_id' => $debit_ledger_id,
                'amount' => $total_amount,
                'closing_balance' => $new_debit_balance,
                'mode_of_payment' => $mode_of_payment
            ];
            
            $log_stmt = $conn->prepare("INSERT INTO audit_logs 
                                      (user_id, table_name, record_id, action, old_value, new_value) 
                                      VALUES (?, 'transactions', ?, 'UPDATE', ?, ?)");
            $log_stmt->bind_param("iiss", 
                $user_id, 
                $debit_entry['transaction_id'], 
                json_encode($old_debit_data),
                json_encode($new_debit_data));
            $log_stmt->execute();
            $log_stmt->close();
            
            // Update debit ledger balance
            $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
            $stmt->bind_param("di", $new_debit_balance, $debit_ledger_id);
            $stmt->execute();
            $stmt->close();

            // === Process Credit Entries ===
            foreach ($credit_ledgers as $index => $ledger_id) {
                $amount = (float)$credit_amounts[$index];
                $narration = $narrations[$index] ?? '';
                $transaction_id = $transaction_ids[$index] ?? 0;
                
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
                        $debit_ledger_id,
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
                                           amount, closing_balance, mode_of_payment, opposite_ledger, 
                                           transaction_date, narration)
                                          VALUES (?, ?, ?, ?, 'Credit', ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiisdsssss", 
                        $user_id,
                        $voucher_id,
                        $ledger_id,
                        $credit_entries[$index]['acc_code'] ?? '',
                        $amount,
                        $new_credit_balance,
                        $mode_of_payment,
                        $debit_ledger_id,
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
            
            // Delete any removed credit transactions
            $existing_credit_ids = array_filter($transaction_ids, function($id) { return $id > 0; });
            if (!empty($existing_credit_ids)) {
                $placeholders = implode(',', array_fill(0, count($existing_credit_ids), '?'));
                $types = str_repeat('i', count($existing_credit_ids));
                
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
            $_SESSION['success_message'] = "Receipt voucher updated successfully!";
            header("Location: receipt_vouchers_list.php");
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
    <title>Edit Receipt Voucher - FINPACK</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="../styles/form_style.css">
    <link rel="stylesheet" href="styles/navbar_style.css">
    <link rel="stylesheet" href="../styles/tally_style.css">
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
        <h2>Edit Receipt Voucher</h2>
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

    <form method="POST" id="receiptVoucherForm" onsubmit="return validateForm()" class="voucher-form" autocomplete="off">
        <input type="hidden" name="voucher_id" value="<?= $voucher_id ?>">
        
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

        <input type="hidden" name="voucher_type" value="Receipt">

        <div class="form-row">
            <div class="form-group">
                <label>Received In (Cash/Bank Account):</label>
                <select name="debit_ledger_id" id="debit_ledger" class="form-control" onchange="updateCreditLedgers(); fetchBalance(this, 'debit_balance')" required>
                    <option value="">--Select--</option>
                    <?php foreach ($ledgers as $ledger): ?>
                        <?php if (($ledger['acc_type'] === 'Asset') && ($ledger['book_type'] === 'Cash' || $ledger['book_type'] === 'Bank')): ?> 
                            <option value="<?= $ledger['ledger_id'] ?>" 
                                <?= ($debit_entry && $debit_entry['ledger_id'] == $ledger['ledger_id']) ? 'selected' : '' ?>>
                                <?= $ledger['ledger_name'] ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <div id="debit_balance" class="balance-box">
                    <?php if ($debit_entry): ?>
                        Balance: <?= $debit_entry['closing_balance'] ?> (<?= $debit_entry['debit_credit'] ?>)
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-group">
                <label>Mode of Payment:</label>
                <select name="mode_of_payment" id="mode_of_payment" class="form-control" onchange="toggleRefFields(this.value)">
                    <option value="Cash" <?= ($debit_entry && $debit_entry['mode_of_payment'] === 'Cash') ? 'selected' : '' ?>>Cash</option>
                    <option value="Cheque" <?= ($debit_entry && $debit_entry['mode_of_payment'] === 'Cheque') ? 'selected' : '' ?>>Cheque</option>
                </select>
            </div>
        </div>
        
        <div id="refFields" style="<?= ($debit_entry && $debit_entry['mode_of_payment'] === 'Cheque') ? '' : 'display:none;' ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Cheque No / Ref No:</label>
                    <input type="text" name="reference_number" class="form-control" style="width: 70%;" 
                           value="<?= htmlspecialchars($voucher['reference_number']) ?>">
                </div>
                <div class="form-group">
                    <label>Reference Date:</label>
                    <input type="date" name="reference_date" class="form-control" style="width: 70%;" 
                           value="<?= htmlspecialchars($voucher['reference_date']) ?>">
                </div>
                <div class="form-group">
                    <label>Bank Name:</label>
                    <input type="text" name="bank_name" class="form-control" style="width: 70%;" 
                           value="<?= htmlspecialchars($voucher['bank_name']) ?>">
                </div>
            </div>
        </div>

        <div class="form-group">
            <h4>Receipt Details</h4>
            <table id="creditTable" style="margin-top:0px;">
                <thead>
                    <tr>
                        <th width="45%">Ledger Account (From)</th>
                        <th width="25%">Amount</th>
                        <th width="25%">Narration</th>
                        <th width="5%">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($credit_entries as $index => $entry): ?>
                    <tr>
                        <td>
                            <input type="hidden" name="transaction_id[]" value="<?= $entry['transaction_id'] ?>">
                            <select name="credit_ledger_id[]" class="form-control" onchange="fetchBalance(this, 'credit_balance_<?= $index ?>')" required>
                                <option value="">--Select--</option>
                                <?php foreach ($ledgers as $ledger): ?>
                                    <?php if (in_array($ledger['acc_type'], ['Income', 'Liability', 'Asset'])): ?>
                                        <?php if (!$debit_entry || $ledger['ledger_id'] != $debit_entry['ledger_id']): ?>
                                            <option value="<?= $ledger['ledger_id'] ?>" 
                                                <?= ($entry['ledger_id'] == $ledger['ledger_id']) ? 'selected' : '' ?>>
                                                <?= $ledger['ledger_name'] ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div id="credit_balance_<?= $index ?>" class="balance-box">
                                Balance: <?= $entry['closing_balance'] ?> (<?= $entry['debit_credit'] ?>)
                            </div>
                        </td>
                        <td><input type="number" name="credit_amount[]" class="form-control" step="0.01" min="0.01" 
                                  value="<?= htmlspecialchars($entry['amount']) ?>" required></td>
                        <td><input type="text" name="credit_narration[]" class="form-control" 
                                  value="<?= htmlspecialchars($entry['narration']) ?>"></td>
                        <td>
                            <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Add one empty row for new entries -->
                    <tr>
                        <td>
                            <select name="credit_ledger_id[]" class="form-control" onchange="fetchBalance(this, 'credit_balance_new')">
                                <option value="">--Select--</option>
                                <?php foreach ($ledgers as $ledger): ?>
                                    <?php if (in_array($ledger['acc_type'], ['Income', 'Liability', 'Asset'])): ?>
                                        <?php if (!$debit_entry || $ledger['ledger_id'] != $debit_entry['ledger_id']): ?>
                                            <option value="<?= $ledger['ledger_id'] ?>"><?= $ledger['ledger_name'] ?></option>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div id="credit_balance_new" class="balance-box"></div>
                        </td>
                        <td><input type="number" name="credit_amount[]" class="form-control" step="0.01" min="0.01"></td>
                        <td><input type="text" name="credit_narration[]" class="form-control"></td>
                        <td>
                            <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <button type="button" class="submit-button" style="width:20%;margin-left:320px;" onclick="addRow()">
                <i class="bi bi-plus-circle"></i> Add Row
            </button>
        </div>

        <button class="submit-button" style="width:40%;margin-left:230px;" type="submit">
            <i class="bi bi-save"></i> Update Voucher
        </button>
    </form>
</div>

<script>
// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    updateCreditLedgers();
    // Show/hide reference fields based on initial payment mode
    toggleRefFields(document.getElementById('mode_of_payment').value);
});

function addRow() {
    let table = document.getElementById("creditTable").getElementsByTagName('tbody')[0];
    let rowCount = table.rows.length;
    let newRow = table.rows[rowCount-1].cloneNode(true);
    
    // Clear values
    newRow.querySelector('select').value = '';
    newRow.querySelector('input[type="number"]').value = '';
    newRow.querySelector('input[type="text"]').value = '';
    
    // Update balance target ID
    let select = newRow.querySelector('select');
    let newTargetId = 'credit_balance_' + Date.now(); // Unique ID
    select.setAttribute('data-target', newTargetId);
    select.onchange = function() { fetchBalance(this, newTargetId); };
    
    // Update balance display div
    let balanceDiv = newRow.querySelector('.balance-box');
    balanceDiv.id = newTargetId;
    balanceDiv.innerHTML = '';
    
    table.appendChild(newRow);
    updateCreditLedgers();
}

function updateCreditLedgers() {
    const debitLedgerId = document.getElementById('debit_ledger').value;
    const creditSelects = document.querySelectorAll('select[name="credit_ledger_id[]"]');
    
    creditSelects.forEach(select => {
        const currentValue = select.value;
        const targetId = select.getAttribute('data-target') || select.parentNode.querySelector('.balance-box').id;
        
        // Skip if this is a hidden input for existing transactions
        if (select.closest('tr').querySelector('input[name="transaction_id[]"]')) {
            return;
        }
        
        // Clear and rebuild options
        select.innerHTML = '<option value="">--Select--</option>';
        
        // Add filtered options (using PHP-generated data)
        <?php foreach ($ledgers as $ledger): ?>
            <?php if (in_array($ledger['acc_type'], ['Income', 'Liability', 'Asset'])): ?>
                if (<?= $ledger['ledger_id'] ?> != debitLedgerId) {
                    const option = new Option(
                        '<?= $ledger['ledger_name'] ?>', 
                        '<?= $ledger['ledger_id'] ?>'
                    );
                    select.add(option);
                    
                    // Restore selection if still valid
                    if ('<?= $ledger['ledger_id'] ?>' == currentValue) {
                        option.selected = true;
                    }
                }
            <?php endif; ?>
        <?php endforeach; ?>
        
        // Update balance display if this select has a value
        if (select.value) {
            fetchBalance(select, targetId);
        }
    });
}

function fetchBalance(selectEl, targetId) {
    const ledgerId = selectEl.value;
    const displayBox = document.getElementById(targetId);

    if (!ledgerId || !displayBox) return;

    fetch(`../get_ledger_balance.php?ledger_id=${ledgerId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                displayBox.innerHTML = `Balance: ${data.balance} (${data.type})`;
                displayBox.style.color = data.type === 'Debit' ? '#dc3545' : '#28a745';
            } else {
                displayBox.innerHTML = "Balance not found";
                displayBox.style.color = '#6c757d';
            }
        })
        .catch(() => {
            displayBox.innerHTML = "Error loading balance";
            displayBox.style.color = '#dc3545';
        });
}

function toggleRefFields(mode) {
    const refFields = document.getElementById('refFields');
    refFields.style.display = mode === 'Cheque' ? 'block' : 'none';
    
    // Set required fields based on mode
    const refInputs = refFields.querySelectorAll('input');
    refInputs.forEach(input => {
        input.required = mode === 'Cheque';
    });
}

function validateForm() {
    // Clear previous errors
    const existingErrors = document.querySelectorAll('.error');
    existingErrors.forEach(el => el.remove());
    
    document.querySelectorAll('.is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
    });
    
    let debitLedger = document.querySelector('select[name="debit_ledger_id"]');
    let creditLedgers = document.querySelectorAll('select[name="credit_ledger_id[]"]');
    let errors = [];
    
    // Debit ledger validation
    if (!debitLedger.value) {
        debitLedger.classList.add('is-invalid');
        errors.push('Please select a debit ledger (Cash/Bank account)');
    }
    
    // Check if payment mode is Cheque and validate reference fields
    const paymentMode = document.getElementById('mode_of_payment').value;
    if (paymentMode === 'Cheque') {
        const refNumber = document.querySelector('input[name="reference_number"]');
        const refDate = document.querySelector('input[name="reference_date"]');
        const bankName = document.querySelector('input[name="bank_name"]');
        
        if (!refNumber.value.trim()) {
            refNumber.classList.add('is-invalid');
            errors.push('Cheque/Reference number is required');
        }
        if (!refDate.value) {
            refDate.classList.add('is-invalid');
            errors.push('Reference date is required');
        }
        if (!bankName.value.trim()) {
            bankName.classList.add('is-invalid');
            errors.push('Bank name is required');
        }
    }
    
    // Credit entries validation
    let totalAmount = 0;
    creditLedgers.forEach((ledger, index) => {
        const row = ledger.closest('tr');
        const amountInput = row.querySelector('input[type="number"]');
        const amount = parseFloat(amountInput.value);
        
        if (!ledger.value) {
            ledger.classList.add('is-invalid');
            errors.push(`Row ${index + 1}: Please select a credit ledger`);
        }
        
        if (isNaN(amount) || amount <= 0) {
            amountInput.classList.add('is-invalid');
            errors.push(`Row ${index + 1}: Please enter a valid amount`);
        } else {
            totalAmount += amount;
        }
        
        if (ledger.value && ledger.value === debitLedger.value) {
            ledger.classList.add('is-invalid');
            debitLedger.classList.add('is-invalid');
            errors.push(`Row ${index + 1}: Debit and Credit ledgers cannot be the same`);
        }
    });
        
    // If errors exist, display them
    if (errors.length > 0) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error';
        errorDiv.innerHTML = '<strong>Please fix the following errors:</strong><ul>' + 
            errors.map(error => `<li>${error}</li>`).join('') + '</ul>';
        
        // Insert error message at top of form
        const form = document.querySelector('.voucher-form');
        form.insertBefore(errorDiv, form.firstChild);
        
        // Scroll to first error
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