<?php 
include '../database/findb.php'; 

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$company_db = $_SESSION['company_name'];
$user_id = $_SESSION['user_id'] ?? 0;
$errors = [];

$voucher_id = $_GET['id'] ?? null;

if (!$voucher_id || !is_numeric($voucher_id)) {
    die("Invalid voucher ID");
}

// Fetch existing voucher
$voucher = $conn->query("SELECT * FROM vouchers WHERE voucher_id = $voucher_id")->fetch_assoc();
if (!$voucher) {
    die("Voucher not found");
}

$voucherNumber = $voucher['voucher_number'];
$display_date = date('d-M-Y', strtotime($voucher['voucher_date']));
$successMessage = '';

$ledgers = $conn->query("SELECT * FROM ledgers ORDER BY ledger_name ASC");

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $voucher_date = $_POST['voucher_date'];
    $credit_ledger_id = $_POST['credit_ledger_id'];
    $mode_of_payment = $_POST['mode_of_payment'];
    $reference_number = $_POST['reference_number'] ?? null;

    $debit_ledgers = $_POST['debit_ledger_id'] ?? [];
    $debit_amounts = $_POST['debit_amount'] ?? [];
    $narrations = $_POST['debit_narration'] ?? [];

    // === Validation ===
    if (empty($voucher_date)) $errors[] = "Date is required.";
    if (empty($credit_ledger_id)) $errors[] = "Please select credit (Cash/Bank) account.";
    if (count($debit_ledgers) == 0) $errors[] = "Please add at least one debit entry.";

    $total_amount = 0;
    foreach ($debit_ledgers as $index => $ledger_id) {
        if (empty($ledger_id) || !is_numeric($debit_amounts[$index]) || $debit_amounts[$index] <= 0) {
            $errors[] = "Invalid debit entry at row " . ($index + 1);
        }
        if ($ledger_id == $credit_ledger_id) {
            $errors[] = "Credit and Debit ledger cannot be the same.";
        }
        $total_amount += (float)$debit_amounts[$index];
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // === Fetch and reverse old transactions ===
            $old_txns = $conn->query("SELECT * FROM transactions WHERE voucher_id = $voucher_id");
            while ($txn = $old_txns->fetch_assoc()) {
                $txn_ledger_id = $txn['ledger_id'];
                $txn_amount = $txn['amount'];
                $txn_type = $txn['transaction_type'];

                // Reverse the balance
                $ledger = $conn->query("SELECT debit_credit, current_balance FROM ledgers WHERE ledger_id = $txn_ledger_id")->fetch_assoc();
                $old_bal = $ledger['current_balance'];
                $dc = $ledger['debit_credit'];

                $reversed = ($txn_type === 'Credit') 
                    ? (($dc === 'Credit') ? $old_bal - $txn_amount : $old_bal + $txn_amount)
                    : (($dc === 'Debit') ? $old_bal - $txn_amount : $old_bal + $txn_amount);

                $conn->query("UPDATE ledgers SET current_balance = $reversed WHERE ledger_id = $txn_ledger_id");

                // Log deletion
                $log = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) VALUES (?, 'transactions', ?, 'DELETE', ?, NULL)");
                $old_val = json_encode($txn);
                $log->bind_param("iis", $user_id, $txn['transaction_id'], $old_val);
                $log->execute();
                $log->close();
            }

            // Delete old transactions
            $conn->query("DELETE FROM transactions WHERE voucher_id = $voucher_id");

            // === Update voucher ===
            $stmt = $conn->prepare("UPDATE vouchers SET reference_number = ?, voucher_date = ?, total_amount = ? WHERE voucher_id = ?");
            $stmt->bind_param("ssdi", $reference_number, $voucher_date, $total_amount, $voucher_id);
            $stmt->execute();
            $stmt->close();

            // Log voucher update
            $new_voucher_data = json_encode([
                'reference_number' => $reference_number,
                'voucher_date' => $voucher_date,
                'total_amount' => $total_amount
            ]);
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) VALUES (?, 'vouchers', ?, 'UPDATE', ?, ?)");
            $old_val = json_encode($voucher);
            $log->bind_param("iiss", $user_id, $voucher_id, $old_val, $new_voucher_data);
            $log->execute();
            $log->close();

            // === Insert new credit transaction ===
            $credit_ledger = $conn->query("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = $credit_ledger_id")->fetch_assoc();
            $new_credit_balance = ($credit_ledger['debit_credit'] === 'Credit') 
                ? $credit_ledger['current_balance'] + $total_amount
                : $credit_ledger['current_balance'] - $total_amount;

            $opposite_ledger_ids = implode(',', $debit_ledgers);
            $summary = "Payment transfer from ledger ID: $credit_ledger_id";

            $stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, opposite_ledger, transaction_date, narration) 
                VALUES (?, ?, ?, ?, 'Credit', ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiisdsssss", $user_id, $voucher_id, $credit_ledger_id, $credit_ledger['acc_code'], $total_amount, $new_credit_balance, $mode_of_payment, $opposite_ledger_ids, $voucher_date, $summary);
            $stmt->execute();
            $stmt->close();

            $conn->query("UPDATE ledgers SET current_balance = $new_credit_balance WHERE ledger_id = $credit_ledger_id");

            // === Insert debit transactions ===
            foreach ($debit_ledgers as $i => $ledger_id) {
                $amount = (float)$debit_amounts[$i];
                $narr = $narrations[$i];

                $ledger = $conn->query("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = $ledger_id")->fetch_assoc();
                $new_bal = ($ledger['debit_credit'] === 'Debit') 
                    ? $ledger['current_balance'] + $amount
                    : $ledger['current_balance'] - $amount;

                $stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, opposite_ledger, transaction_date, narration) 
                    VALUES (?, ?, ?, ?, 'Debit', ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiisdssiss", $user_id, $voucher_id, $ledger_id, $ledger['acc_code'], $amount, $new_bal, $mode_of_payment, $credit_ledger_id, $voucher_date, $narr);
                $stmt->execute();
                $stmt->close();

                $conn->query("UPDATE ledgers SET current_balance = $new_bal WHERE ledger_id = $ledger_id");
            }

            $conn->commit();
            header("Location: edit_payment_voucher.php?id=$voucher_id&success=1");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error updating voucher: " . $e->getMessage();
        }
    }
}
?>


<!DOCTYPE html>
<html>
<head>
    <title>Payment Voucher - FINPACK</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="../styles/form_style.css">
    <link rel="stylesheet" href="styles/navbar_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Simplified styling */
        /* Tally-like styling */
.tally-style {
    /* border: 1px solid #ccc; */
    background-color : #fff;
}

.tally-style .header h1 {
    color: white;
}

        .voucher-container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .voucher-header {
            background-color: #1abc9c;
            color: white;
            padding: 10px 15px;
            border-radius: 4px 4px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .voucher-form {
            background-color: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-group input
        {
            width: 70%;
        }

        .form-group select
        {
            width:78%;
        }

        
        .form-control, select, input, textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        #debitTable {
            width: 100%;
            margin: 1rem 0;
            border-collapse: collapse;
        }
        
        #debitTable th {
            background-color: #f8f9fa;
            padding: 10px;
            text-align: left;
        }
        
        #debitTable td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .btn {
            padding: 8px 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background-color: #28a745;
            color: white;
        }
        
        .btn-outline-danger {
            background-color: transparent;
            border: 1px solid #dc3545;
            color: #dc3545;
        }
        
        .btn-outline-danger:hover {
            background-color: #dc3545;
            color: white;
        }
        
        .error {
            color: #dc3545;
            margin-bottom: 1rem;
        }
        
        .success {
            color: #28a745;
            margin-bottom: 1rem;
        }
        
        .balance-box {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        #refFields {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        
       /* Responsive adjustments */
       @media (max-width: 768px) {
            .voucher-container {
                padding: 0 0.5rem;
            }
            
            .voucher-header {
                flex-direction: column;
                align-items: flex-start;
                padding: 10px;
            }
            
            .voucher-header h1 {
                font-size: 1.3rem;
                margin-bottom: 5px;
            }
            
            .voucher-form {
                padding: 1rem;
            }
            
            .form-group {
                min-width: 100%;
            }
            
            #debitTable {
                display: block;
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            #debitTable th, #debitTable td {
                padding: 8px 5px;
                font-size: 0.9rem;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
                margin-bottom: 5px;
            }
        }
        
        @media (max-width: 480px) {
            .voucher-header h1 {
                font-size: 1.2rem;
            }
            
            .current-date {
                font-size: 0.8rem;
            }
            
            #debitTable th, #debitTable td {
                padding: 6px 3px;
                font-size: 0.85rem;
            }
            
            .form-control, select, input, textarea {
                padding: 6px 8px;
                font-size: 0.9rem;
            }
        }
    </style>
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
            <h2>Edit Payment Voucher</h2>
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
                setTimeout(function() {
                    const msg = document.getElementById('successMessage');
                    if (msg) {
                        msg.style.transition = 'opacity 0.5s ease-out';
                        msg.style.opacity = '0';
                        setTimeout(() => msg.remove(), 300);
                    }
                }, 4000);
            </script>
        <?php endif; ?>

        <form method="POST" id="editPaymentVoucherForm" onsubmit="return validateForm()" class="voucher-form">
            <input type="hidden" name="voucher_id" value="<?= $voucher['voucher_id'] ?>">

            <div class="form-row">
                <div class="form-group">
                    <label>Voucher No:</label>
                    <input class="form-control" type="text" name="voucher_number" value="<?= $voucher['voucher_number'] ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Date:</label>
                    <input type="date" name="voucher_date" value="<?= $voucher['voucher_date'] ?>" required>
                </div>
            </div>

            <input type="hidden" name="voucher_type" value="Payment">

            <div class="form-row">
                <div class="form-group">
                    <label>Paid From (Cash/Bank Account):</label>
                    <select name="credit_ledger_id" class="form-control" onchange="updateDebitLedgers(); fetchBalance(this, 'credit_balance')" required>
                        <?php foreach ($ledgers as $ledger): ?>
                            <?php if ($ledger['book_type'] === 'Cash' || $ledger['book_type'] === 'Bank'): ?>
                                <option value="<?= $ledger['ledger_id'] ?>" <?= ($voucher['credit_ledger_id'] == $ledger['ledger_id']) ? 'selected' : '' ?>>
                                    <?= $ledger['ledger_name'] ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <div id="credit_balance" class="balance-box"></div>
                </div>

                <div class="form-group">
                    <label>Mode of Payment:</label>
                    <select name="mode_of_payment" class="form-control" onchange="toggleRefFields(this.value)">
                        <option value="Cash" <?= ($voucher['mode_of_payment'] == 'Cash') ? 'selected' : '' ?>>Cash</option>
                        <option value="Cheque" <?= ($voucher['mode_of_payment'] == 'Cheque') ? 'selected' : '' ?>>Cheque</option>
                    </select>
                </div>
            </div>

            <div id="refFields" style="<?= ($voucher['mode_of_payment'] == 'Cheque') ? '' : 'display:none;' ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Cheque No / Ref No:</label>
                        <input type="text" name="reference_number" class="form-control" value="<?= $voucher['reference_number'] ?>">
                    </div>
                    <div class="form-group">
                        <label>Reference Date:</label>
                        <input type="date" name="reference_date" class="form-control" value="<?= $voucher['reference_date'] ?>">
                    </div>
                    <div class="form-group">
                        <label>Bank Name:</label>
                        <input type="text" name="bank_name" class="form-control" value="<?= $voucher['bank_name'] ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <h4>Payment Details</h4>
                <table id="debitTable">
                    <thead>
                        <tr>
                            <th width="45%">Ledger Account</th>
                            <th width="25%">Amount</th>
                            <th width="25%">Narration</th>
                            <th width="5%">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $index => $txn): ?>
                        <tr>
                            <td>
                                <select name="debit_ledger_id[]" class="form-control" onchange="fetchBalance(this, 'debit_balance_<?= $index ?>')">
                                    <?php foreach ($ledgers as $ledger): ?>
                                        <?php if (in_array($ledger['acc_type'], ['Expense', 'Asset']) && $ledger['ledger_id'] != $voucher['credit_ledger_id']): ?>
                                            <option value="<?= $ledger['ledger_id'] ?>" <?= ($txn['ledger_id'] == $ledger['ledger_id']) ? 'selected' : '' ?>>
                                                <?= $ledger['ledger_name'] ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <div id="debit_balance_<?= $index ?>" class="balance-box"></div>
                            </td>
                            <td><input type="number" name="debit_amount[]" value="<?= $txn['amount'] ?>" class="form-control" step="0.01" min="0.01"></td>
                            <td><input type="text" name="debit_narration[]" value="<?= $txn['narration'] ?>" class="form-control"></td>
                            <td>
                                <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="button" class="submit-button" onclick="addRow()">
                    <i class="bi bi-plus-circle"></i> Add Row
                </button>
            </div>

            <button class="submit-button" type="submit">
                <i class="bi bi-save"></i> Update Voucher
            </button>
        </form>

        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this voucher?')">
            <input type="hidden" name="delete_voucher_id" value="<?= $voucher['voucher_id'] ?>">
            <button class="btn btn-danger" type="submit">
                <i class="bi bi-trash"></i> Delete Voucher
            </button>
        </form>
    </div>

<script>
// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    updateDebitLedgers();
});

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
    const creditLedgerId = document.getElementById('credit_ledger').value;
    const debitSelects = document.querySelectorAll('select[name="debit_ledger_id[]"]');
    
    debitSelects.forEach(select => {
        const currentValue = select.value;
        const targetId = select.getAttribute('data-target') || 'debit_balance_0';
        
        // Clear and rebuild options
        select.innerHTML = '<option value="">--Select--</option>';
        
        // Add filtered options (using PHP-generated data)
        <?php foreach ($ledgers as $ledger): ?>
            <?php if (in_array($ledger['acc_type'], ['Expense', 'Asset','Liability'])): ?>
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
    
    let creditLedger = document.querySelector('select[name="credit_ledger_id"]');
    let debitLedgers = document.querySelectorAll('select[name="debit_ledger_id[]"]');
    let errors = [];
    
    // Credit ledger validation
    if (!creditLedger.value) {
        creditLedger.classList.add('is-invalid');
        errors.push('Please select a credit ledger (Cash/Bank account)');
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

window.addEventListener("load", function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get("success") === "1") {
        document.getElementById("paymentVoucherForm")?.reset();
        // You can also manually clear dropdowns, date pickers, etc.
    }
});


</script>

</body>
</html>