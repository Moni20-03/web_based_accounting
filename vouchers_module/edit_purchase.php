<?php
include '../database/findb.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$user_id = $_SESSION['user_id'] ?? 0;
$company_db = $_SESSION['company_name'];
$errors = [];
$successMessage = '';

// Get voucher ID from URL
$voucher_id = $_GET['id'] ?? 0;
if (!$voucher_id) {
    header("Location: purchase_vouchers_list.php");
    exit;
}

// Fetch voucher details
$voucher = $conn->query("SELECT * FROM vouchers WHERE voucher_id = $voucher_id")->fetch_assoc();
if (!$voucher) {
    header("Location: purchase_vouchers_list.php");
    exit;
}

// Fetch all transactions for this voucher
$transactions = $conn->query("SELECT * FROM transactions WHERE voucher_id = $voucher_id");

// Get all ledgers
$ledgers = $conn->query("SELECT * FROM ledgers ORDER BY ledger_name ASC");

// Determine credit ledger (party or cash/bank)
$credit_ledger_id = 0;
$mode_of_payment = 'Credit';
foreach ($transactions as $txn) {
    if ($txn['transaction_type'] == 'Credit') {
        $credit_ledger_id = $txn['ledger_id'];
        $mode_of_payment = $txn['mode_of_payment'];
        break;
    }
}

// Get debit transactions (purchase entries)
$debit_transactions = [];
foreach ($transactions as $txn) {
    if ($txn['transaction_type'] == 'Debit') {
        $debit_transactions[] = $txn;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($errors)) {
    $voucher_number = trim($_POST['voucher_number']);
    $voucher_date = $_POST['voucher_date'];
    $mode_of_payment = $_POST['mode_of_payment'];
    $reference_number = $_POST['reference_number'] ?? null;
    $narration = $_POST['debit_narration'] ?? [];

    if ($mode_of_payment === 'Credit') {
        $credit_ledger_id = $_POST['party_ledger_id'] ?? null;
    } else {
        $credit_ledger_id = $_POST['cashbank_ledger_id'] ?? null;
    }

    $debit_ledgers = $_POST['debit_ledger_id'] ?? [];
    $debit_amounts = $_POST['debit_amount'] ?? [];

    // Validation
    if (empty($voucher_number)) $errors[] = "Voucher number missing.";
    if (empty($voucher_date)) $errors[] = "Date is required.";
    if (empty($credit_ledger_id)) $errors[] = "Please select a party/cash/bank account.";

    if (count($debit_ledgers) == 0) $errors[] = "Please add at least one purchase ledger entry.";

    $total_amount = 0;
    foreach ($debit_ledgers as $index => $ledger_id) {
        if (empty($ledger_id) || !is_numeric($debit_amounts[$index]) || $debit_amounts[$index] <= 0) {
            $errors[] = "Invalid debit entry at row " . ($index + 1);
        }
        $total_amount += (float)$debit_amounts[$index];

        if ($ledger_id == $credit_ledger_id) {
            $errors[] = "Purchase and Credit ledger cannot be the same.";
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            // Update Voucher
            $stmt = $conn->prepare("UPDATE vouchers SET voucher_number = ?, reference_number = ?, voucher_date = ?, total_amount = ? WHERE voucher_id = ?");
            $stmt->bind_param("sssdi", $voucher_number, $reference_number, $voucher_date, $total_amount, $voucher_id);
            $stmt->execute();
            $stmt->close();

            // Log voucher update
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) 
            VALUES (?, 'vouchers', ?, 'UPDATE', ?, ?)");
            $old_value = json_encode($voucher);
            $new_value = json_encode([
                'voucher_number' => $voucher_number,
                'reference_number' => $reference_number,
                'voucher_date' => $voucher_date,
                'total_amount' => $total_amount
            ]);
            $log_stmt->bind_param("iiss", $user_id, $voucher_id, $old_value, $new_value);
            $log_stmt->execute();
            $log_stmt->close();

            // Delete old transactions (we'll recreate them)
            $conn->query("DELETE FROM transactions WHERE voucher_id = $voucher_id");

            $narration_summary = "Purchase made to ledger ID: $credit_ledger_id";

            // Credit Entry (Party/Cash/Bank)
            $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
            $stmt->bind_param("i", $credit_ledger_id);
            $stmt->execute();
            $stmt->bind_result($acc_code, $cur_bal, $dc_type);
            $stmt->fetch();
            $stmt->close();

            $new_balance = ($dc_type === 'Debit') ? $cur_bal - $total_amount : $cur_bal + $total_amount;

            $opposite_ledger_ids = implode(',', $debit_ledgers);

            $txn_stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, opposite_ledger, transaction_date, narration) 
            VALUES (?, ?, ?, ?, 'Credit', ?, ?, ?, ?, ?, ?)");
            $txn_stmt->bind_param("iiisdsssss", $user_id, $voucher_id, $credit_ledger_id, $acc_code, $total_amount, $new_balance, $mode_of_payment, $opposite_ledger_ids, $voucher_date, $narration_summary);
            $txn_stmt->execute();
            $txn_id = $txn_stmt->insert_id;
            $txn_stmt->close();

            // Log credit entry
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) VALUES (?, 'transactions', ?, 'INSERT', NULL, ?)");
            $new_value = json_encode([
                'voucher_id' => $voucher_id,
                'ledger_id' => $credit_ledger_id,
                'amount' => $total_amount,
                'type' => 'Credit',
                'closing_balance' => $new_balance
            ]);
            $log_stmt->bind_param("iis", $user_id, $txn_id, $new_value);
            $log_stmt->execute();
            $log_stmt->close();

            // Update credit ledger balance
            $update_stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
            $update_stmt->bind_param("di", $new_balance, $credit_ledger_id);
            $update_stmt->execute();

            // Debit Entries (Purchase Ledgers)
            foreach ($debit_ledgers as $i => $ledger_id) {
                $amount = (float)$debit_amounts[$i];
                $nar = (string)$narration[$i] ?? '';

                $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $ledger_id);
                $stmt->execute();
                $stmt->bind_result($acc_code, $cur_bal, $dc_type);
                $stmt->fetch();
                $stmt->close();

                $new_bal = ($dc_type === 'Debit') ? $cur_bal + $amount : $cur_bal - $amount;

                $txn_stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, opposite_ledger, transaction_date, narration) 
                VALUES (?, ?, ?, ?, 'Debit', ?, ?, ?, ?, ?, ?)");
                $txn_stmt->bind_param("iiisdssiss", $user_id, $voucher_id, $ledger_id, $acc_code, $amount, $new_bal, $mode_of_payment, $credit_ledger_id, $voucher_date, $nar);
                $txn_stmt->execute();
                $txn_id = $txn_stmt->insert_id;
                $txn_stmt->close();

                // Log debit entry
                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) VALUES (?, 'transactions', ?, 'INSERT', NULL, ?)");
                $new_value = json_encode([
                    'voucher_id' => $voucher_id,
                    'ledger_id' => $ledger_id,
                    'amount' => $amount,
                    'type' => 'Debit',
                    'closing_balance' => $new_bal
                ]);
                $log_stmt->bind_param("iis", $user_id, $txn_id, $new_value);
                $log_stmt->execute();
                $log_stmt->close();

                $update_stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $update_stmt->bind_param("di", $new_bal, $ledger_id);
                $update_stmt->execute();
            }

            $conn->commit();
            $successMessage = "Purchase voucher updated successfully!";
            header("Location: purchase_vouchers_list.php");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Purchase Voucher - FINPACK</title>
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
        <h2>Edit Purchase Voucher</h2>
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

    <form id="editForm" method="POST" onsubmit="return validateForm()" class="voucher-form" autocomplete="off">
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

        <div class="form-row">
            <!-- Supplier Ledger for Credit Purchase -->
            <div class="form-group" id="creditPartySection" style="<?= $mode_of_payment !== 'Credit' ? 'display:none;' : '' ?>">
                <label>Supplier Ledger (Sundry Creditors):</label>
                <select name="party_ledger_id" id="party_ledger" class="form-control" onchange="fetchBalance(this, 'party_balance');updateDebitLedgers()">
                    <option value="">--Select Supplier--</option>
                    <?php foreach ($ledgers as $ledger): ?>
                        <?php if ($ledger['group_id'] === '24'): ?>
                            <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger['ledger_id'] == $credit_ledger_id ? 'selected' : '' ?>>
                                <?= $ledger['ledger_name'] ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <div id="party_balance" class="balance-box"></div>
            </div>

            <!-- Cash/Bank Ledger for Cash/Bank Purchase -->
            <div class="form-group" id="cashBankSection" style="<?= $mode_of_payment === 'Credit' ? 'display:none;' : '' ?>">
                <label>Paid From (Cash/Bank Ledger):</label>
                <select name="cashbank_ledger_id" id="cashbank_ledger" class="form-control" onchange="fetchBalance(this, 'cashbank_balance');updateDebitLedgers();">
                    <option value="">--Select Ledger--</option>
                    <?php foreach ($ledgers as $ledger): ?>
                        <?php if (($ledger['acc_type'] === 'Asset') && ($ledger['book_type'] === 'Cash' || $ledger['book_type'] === 'Bank')):?>
                            <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger['ledger_id'] == $credit_ledger_id ? 'selected' : '' ?>>
                                <?= $ledger['ledger_name'] ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <div id="cashbank_balance" class="balance-box"></div>
            </div>

            <!-- Mode of Sale -->
            <div class="form-group">
                <label>Mode of Sale:</label>
                <select name="mode_of_payment" id="mode_of_payment" class="form-control" onchange="toggleLedgerFields(this.value)">
                    <option value="Credit" <?= $mode_of_payment === 'Credit' ? 'selected' : '' ?>>Credit</option>
                    <option value="Cash" <?= $mode_of_payment === 'Cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="Bank" <?= $mode_of_payment === 'Bank' ? 'selected' : '' ?>>Bank</option>
                    <option value="Cheque" <?= $mode_of_payment === 'Cheque' ? 'selected' : '' ?>>Cheque</option>
                </select>
            </div>
        </div>

        <!-- Reference Section -->
        <div id="refFields" style="<?= $mode_of_payment === 'Cheque' ? '' : 'display:none;' ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Invoice No / Ref No:</label>
                    <input type="text" name="reference_number" class="form-control" style="width: 70%;" value="<?= htmlspecialchars($voucher['reference_number']) ?>">
                </div>
                <div class="form-group">
                    <label>Reference Date:</label>
                    <input type="date" name="reference_date" class="form-control" style="width: 70%;" value="<?= htmlspecialchars($voucher['reference_date']) ?>">
                </div>
                <div class="form-group">
                    <label>Bank Name:</label>
                    <input type="text" name="bank_name" class="form-control" style="width: 70%;" value="<?= htmlspecialchars($voucher['bank_name']) ?>">
                </div>
            </div>
        </div>

        <!-- Purchase Details Section -->
        <div class="form-group">
            <h4>Purchase Details</h4>
            <table id="debitTable" style="margin-top:0px;">
                <thead>
                    <tr>
                        <th width="45%">Purchase Ledger (Expense A/c)</th>
                        <th width="25%">Amount</th>
                        <th width="25%">Narration</th>
                        <th width="5%">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($debit_transactions as $index => $txn): ?>
                        <tr>
                            <td>
                                <select name="debit_ledger_id[]" class="form-control" onchange="fetchBalance(this, 'debit_balance_<?= $index ?>')" required>
                                    <option value="">--Select--</option>
                                    <?php foreach ($ledgers as $ledger): ?>
                                        <?php if (($ledger['acc_type'] === 'Expense' || ($ledger['acc_type'] === 'Asset' && !in_array($ledger['book_type'], ['Cash', 'Bank']))) &&
                                                $ledger['ledger_id'] != $credit_ledger_id): ?>
                                            <option value="<?= $ledger['ledger_id'] ?>" <?= $ledger['ledger_id'] == $txn['ledger_id'] ? 'selected' : '' ?>>
                                                <?= $ledger['ledger_name'] ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <div id="debit_balance_<?= $index ?>" class="balance-box"></div>
                            </td>
                            <td><input type="number" name="debit_amount[]" class="form-control" step="0.01" min="0.01" value="<?= htmlspecialchars($txn['amount']) ?>" required></td>
                            <td><input type="text" name="debit_narration[]" class="form-control" value="<?= htmlspecialchars($txn['narration']) ?>"></td>
                            <td>
                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>  
            </table>

            <button type="button" class="submit-button" style="width:20%;margin-left:320px;" onclick="addRow()">
                <i class="bi bi-plus-circle"></i> Add Row
            </button>

            <button class="submit-button" style="width:40%;margin-left:230px;" type="submit">
                <i class="bi bi-save"></i> Update Voucher
            </button>
        </div>
    </form>
</div>

<script>
// Same JavaScript functions as in the create page
function toggleLedgerFields(mode) {
    const partySection = document.getElementById('creditPartySection');
    const cashBankSection = document.getElementById('cashBankSection');
    const refFields = document.getElementById('refFields');

    if (mode === 'Credit') {
        partySection.style.display = 'block';
        cashBankSection.style.display = 'none';
    } else {
        partySection.style.display = 'none';
        cashBankSection.style.display = 'block';
    }

    refFields.style.display = mode === 'Cheque' ? 'block' : 'none';
}

function addRow() {
    let table = document.getElementById("debitTable").getElementsByTagName('tbody')[0];
    let rowCount = table.rows.length;
    let newRow = table.rows[rowCount-1].cloneNode(true);
    
    // Clear values
    newRow.querySelector('select').value = '';
    newRow.querySelector('input[type="number"]').value = '';
    newRow.querySelector('input[type="text"]').value = '';
    
    // Update balance target ID
    let select = newRow.querySelector('select');
    let newTargetId = 'debit_balance_' + rowCount;
    select.setAttribute('data-target', newTargetId);
    select.onchange = function() { fetchBalance(this, newTargetId); };
    
    // Update balance display div
    let balanceDiv = newRow.querySelector('.balance-box');
    balanceDiv.id = newTargetId;
    balanceDiv.innerHTML = '';
    
    table.appendChild(newRow);
    updateDebitLedgers();
}

function updateDebitLedgers() {
    const mode = document.getElementById('mode_of_payment').value;
    const creditLedgerId = mode === 'Credit' 
        ? document.getElementById('party_ledger').value 
        : document.getElementById('cashbank_ledger').value;
    
    const debitSelects = document.querySelectorAll('select[name="debit_ledger_id[]"]');
    
    debitSelects.forEach(select => {
        const currentValue = select.value;
        const targetId = select.getAttribute('data-target') || 'debit_balance_0';
        
        // Clear and rebuild options
        select.innerHTML = '<option value="">--Select--</option>';
        
        // Add filtered options
        <?php foreach ($ledgers as $ledger): ?>
            <?php if (($ledger['acc_type'] === 'Expense' || ($ledger['acc_type'] === 'Asset' && 
                      !in_array($ledger['book_type'], ['Cash', 'Bank']))) &&
                      $ledger['ledger_id'] != $credit_ledger_id): ?>
                if (<?= $ledger['ledger_id'] ?> != creditLedgerId) {
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

function validateForm() {
    let mode = document.querySelector('select[name="mode_of_payment"]').value;
    let creditLedger = mode === 'Credit' 
        ? document.querySelector('select[name="party_ledger_id"]')
        : document.querySelector('select[name="cashbank_ledger_id"]');
    
    let debitLedgers = document.querySelectorAll('select[name="debit_ledger_id[]"]');
    let errors = [];
    
    // Reset error styling
    document.querySelectorAll('.is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
    });
    
    // Credit ledger validation
    if (!creditLedger.value) {
        creditLedger.classList.add('is-invalid');
        errors.push('Please select a credit ledger (Party/Cash/Bank account)');
    }
    
    // Debit entries validation
    debitLedgers.forEach((ledger, index) => {
        const row = ledger.closest('tr');
        const amountInput = row.querySelector('input[type="number"]');
        const amount = parseFloat(amountInput.value);
        
        if (!ledger.value) {
            ledger.classList.add('is-invalid');
            errors.push(`Row ${index + 1}: Please select a debit ledger`);
        }
        
        if (isNaN(amount) || amount <= 0) {
            amountInput.classList.add('is-invalid');
            errors.push(`Row ${index + 1}: Please enter a valid amount`);
        }
        
        if (ledger.value && ledger.value === creditLedger.value) {
            ledger.classList.add('is-invalid');
            creditLedger.classList.add('is-invalid');
            errors.push(`Row ${index + 1}: Credit and Debit ledgers cannot be the same`);
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

// Initialize balances on page load
document.addEventListener('DOMContentLoaded', function() {
    // Fetch balances for all select elements
    const selects = document.querySelectorAll('select');
    selects.forEach(select => {
        const targetId = select.getAttribute('data-target') || 
                        (select.name === 'party_ledger_id' ? 'party_balance' : 
                         select.name === 'cashbank_ledger_id' ? 'cashbank_balance' : null);
        
        if (targetId && select.value) {
            fetchBalance(select, targetId);
        }
    });
});
</script>
</body>
</html>