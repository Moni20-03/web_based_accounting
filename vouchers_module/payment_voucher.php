<?php 
include '../database/findb.php'; 

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$company_db = $_SESSION['company_name'];
$user_id = $_SESSION['user_id'] ?? 0;
$errors = [];

$successMessage = '';
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $successMessage = "Payment voucher created successfully!";
}

// Handle JavaScript validation errors
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['js_validation_errors'])) {
    $js_errors = json_decode($_POST['js_validation_errors'], true);
    if (is_array($js_errors)) {
        $errors = array_merge($errors, $js_errors);
    }
}

$display_date = date('d-M-Y');

// Auto-generate voucher number
$result = $conn->query("SELECT MAX(CAST(SUBSTRING(voucher_number, 2) AS UNSIGNED)) as last_num 
          FROM vouchers 
          WHERE voucher_type = 'Payment' 
          AND voucher_number LIKE 'P%'");
$row = $result->fetch_assoc();
$nextNum = $row['last_num'] ? $row['last_num'] + 1 : 1;
$voucherNumber = 'P' . $nextNum;

// Get all ledgers for dropdowns
$ledgers = $conn->query("SELECT * FROM ledgers ORDER BY ledger_name ASC");

if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($errors)){
    $voucher_number = trim($_POST['voucher_number']);
    $voucher_date = $_POST['voucher_date'];
    $credit_ledger_id = $_POST['credit_ledger_id'];
    $mode_of_payment = $_POST['mode_of_payment'];
    $reference_number = $_POST['reference_number'] ?? null;
    $voucher_type = 'Payment';

    $debit_ledgers = $_POST['debit_ledger_id'] ?? [];

    $debit_amounts = $_POST['debit_amount'] ?? [];
    $narrations = $_POST['debit_narration'] ?? [];

    // === Basic Validation ===
    if (empty($voucher_number)) $errors[] = "Voucher number missing.";
    if (empty($voucher_date)) $errors[] = "Date is required.";
    if (empty($credit_ledger_id)) $errors[] = "Please select credit (Cash/Bank) account.";

    if (count($debit_ledgers) == 0) $errors[] = "Please add at least one debit entry.";

    $total_amount = 0;
    foreach ($debit_ledgers as $index => $ledger_id) {
        if (empty($ledger_id) || !is_numeric($debit_amounts[$index]) || $debit_amounts[$index] <= 0) {
            $errors[] = "Invalid debit entry at row " . ($index + 1);
        }
        $total_amount += (float)$debit_amounts[$index];

        if ($ledger_id == $credit_ledger_id) {
            $errors[] = "Credit and Debit ledger cannot be the same.";
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            // === Insert Voucher ===
            $stmt = $conn->prepare("INSERT INTO vouchers (user_id, voucher_number, reference_number, voucher_type, voucher_date, total_amount) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssd", $user_id, $voucher_number, $reference_number, $voucher_type, $voucher_date, $total_amount);
            $stmt->execute();
            $voucher_id = $stmt->insert_id;
            $stmt->close();
        
                // === Log Voucher Insert ===
                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) VALUES (?, 'vouchers', ?, 'INSERT', NULL, ?)");
                $new_value = json_encode([
                    'voucher_number' => $voucher_number,
                    'reference_number' => $reference_number,
                    'voucher_type' => $voucher_type,
                    'voucher_date' => $voucher_date,
                    'total_amount' => $total_amount
                ]);
                $log_stmt->bind_param("iis", $user_id, $voucher_id, $new_value);
                $log_stmt->execute();
                $log_stmt->close();

            $narration_summary = "Payment transfer from ledger ID: $credit_ledger_id"; // optional

            // === Insert Credit Entry ===
            $credit_stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
            $credit_stmt->bind_param("i", $credit_ledger_id);
            $credit_stmt->execute();
            $credit_stmt->bind_result($acc_code, $cur_bal, $dc_type);
            $credit_stmt->fetch();
            $credit_stmt->close();

            $opposite_ledger_ids = implode(',', $debit_ledgers);

            // Update balance logic
            $new_balance = ($dc_type === 'Credit') ? $cur_bal + $total_amount : $cur_bal - $total_amount;

            $txn_stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, opposite_ledger, transaction_date, narration) VALUES (?, ?, ?, ?, 'Credit', ?, ?, ?, ?, ?, ?)");
            $txn_stmt->bind_param("iiisdsssss", $user_id, $voucher_id, $credit_ledger_id, $acc_code, $total_amount, $new_balance, $mode_of_payment, $opposite_ledger_ids , $voucher_date, $narration_summary);
            $txn_stmt->execute();
            $txn_id = $txn_stmt->insert_id;
            $txn_stmt->close();

            // === Log Credit Transaction Insert ===
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

            // Update Ledger
            $update_stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
            $update_stmt->bind_param("di", $new_balance, $credit_ledger_id);
            $update_stmt->execute();

            // === Insert Debit Entries ===
            foreach ($debit_ledgers as $i => $ledger_id) {
                $amount = (float)$debit_amounts[$i];
                $narr = (string)$narrations[$i];

                $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $ledger_id);
                $stmt->execute();
                $stmt->bind_result($acc_code, $cur_bal, $dc_type);
                $stmt->fetch();
                $stmt->close();

                $new_bal = ($dc_type === 'Debit') ? $cur_bal + $amount : $cur_bal - $amount;

                $txn_stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, opposite_ledger, transaction_date, narration) VALUES (?, ?, ?, ?, 'Debit', ?, ?, ?, ?, ?, ?)");
                $txn_stmt->bind_param("iiisdssiss", $user_id, $voucher_id, $ledger_id, $acc_code, $amount, $new_bal, $mode_of_payment, $credit_ledger_id, $voucher_date, $narr);
                $txn_stmt->execute();
                $txn_id = $txn_stmt->insert_id;
                $txn_stmt->close();

                // === Log Debit Transaction Insert ===
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

                // Update balance
                $update_stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $update_stmt->bind_param("di", $new_bal, $ledger_id);
                $update_stmt->execute();
            }

            $conn->commit();
            $success = "Payment voucher $voucherNumber created successfully!";
            // Regenerate voucher number for next entry
            $voucherNumber = 'P' . ($nextNum + 1);

            // After inserting into DB successfully
            header("Location: payment_voucher.php?success=1");
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
        <h2>Payment Voucher</h2>
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
        }, 4000);
    </script>
    <?php endif; ?>

    <form method="POST" id = "paymentVoucherForm" onsubmit="return validateForm()" class="voucher-form" autocomplete="off">
        <div class="form-row">
            <div class="form-group">
                <label>Voucher No:</label>
                <input class="form-control" type="text" name="voucher_number" value="<?= htmlspecialchars($voucherNumber) ?>" readonly>
            </div>
            <div class="form-group">
                <label>Date:</label>
                <input type="date" name="voucher_date" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <input type="hidden" name="voucher_type" value="Payment">

        <div class="form-row">
            <div class="form-group">
                <label>Paid From (Cash/Bank Account):</label>
                <select name="credit_ledger_id" id="credit_ledger" class="form-control" onchange="updateDebitLedgers(); fetchBalance(this, 'credit_balance')" required>
                    <option value="">--Select--</option>
                    <?php foreach ($ledgers as $ledger): ?>
                        <?php if (($ledger['acc_type'] === 'Asset') && ($ledger['book_type'] === 'Cash' || $ledger['book_type'] === 'Bank')): ?> 
                            <option value="<?= $ledger['ledger_id'] ?>" 
                                <?= (isset($_POST['credit_ledger_id']) && $_POST['credit_ledger_id'] == $ledger['ledger_id']) ? 'selected' : '' ?>>
                                <?= $ledger['ledger_name'] ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <div id="credit_balance" class="balance-box"></div>
            </div>
            
            <!-- <div class="form-row"> -->
                <div class="form-group">
                    <label>Mode of Payment:</label>
                    <select name="mode_of_payment" id="mode_of_payment" class="form-control" onchange="toggleRefFields(this.value)">
                        <option value="Cash">Cash</option>
                        <option value="Cheque">Cheque</option>
                    </select>
                </div>
            <!-- </div> -->
            
        </div>
        <div id="refFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label>Cheque No / Ref No:</label>
                    <input type="text" name="reference_number" class="form-control" style="width: 70%;">
                </div>
                <div class="form-group">
                    <label>Reference Date:</label>
                    <input type="date" name="reference_date" class="form-control" style="width: 70%;">
                </div>
                <div class="form-group">
                    <label>Bank Name:</label>
                    <input type="text" name="bank_name" class="form-control" style="width: 70%;">
                </div>
            </div>
        </div>

        <div class="form-group">

            <h4>Payment Details</h4>
            <table id="debitTable" style="margin-top:0px;">
                <thead>
                    <tr>
                        <th width="45%">Ledger Account</th>
                        <th width="25%">Amount</th>
                        <th width="25%">Narration</th>
                        <th width="5%">Action</th>
                    </tr>
                </thead>
            <tbody>
                <tr>
                    <td>
                        <select name="debit_ledger_id[]" class="form-control" onchange="fetchBalance(this, 'debit_balance_0')" required>
                            <option value="">--Select--</option>
                            <?php 
                            $credit_ledger_id = $_POST['credit_ledger_id'] ?? 0;
                            foreach ($ledgers as $ledger): 
                                if (in_array($ledger['acc_type'], ['Expense', 'Asset']) && $ledger['ledger_id'] != $credit_ledger_id): 
                                    ?>
                                <option value="<?= $ledger['ledger_id'] ?>"><?= $ledger['ledger_name'] ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                            <div id="debit_balance_0" class="balance-box"></div>
                    </td>
                    <td><input type="number" name="debit_amount[]" class="form-control" step="0.01" min="0.01" required></td>
                    <td><input type="text" name="debit_narration[]" class="form-control"></td>
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
            <i class="bi bi-save"></i> Save Voucher
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