<?php 
include '../database/findb.php'; 

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$company_db = $_SESSION['company_name'];
$user_id = $_SESSION['user_id'] ?? 0;
$errors = [];

// ===== 1. FETCH EXISTING VOUCHER DATA =====
$voucher_id = '109' ?? 0;
$voucher_data = [];
$transactions = [];
$credit_entry = [];
$debit_entries = [];

if ($voucher_id) {
    // Fetch voucher master data
    $stmt = $conn->prepare("SELECT * FROM vouchers WHERE voucher_id = ?");
    $stmt->bind_param("i", $voucher_id);
    $stmt->execute();
    $voucher_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($voucher_data) {
        // Fetch all transactions for this voucher
        $stmt = $conn->prepare("SELECT * FROM transactions WHERE voucher_id = ?");
        $stmt->bind_param("i", $voucher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            if ($row['transaction_type'] == 'Credit') {
                $credit_entry = $row;
            } else {
                $debit_entries[] = $row;
            }
        }
        $stmt->close();
    }
}

// Get all ledgers for dropdowns
$ledgers = $conn->query("SELECT * FROM ledgers ORDER BY ledger_name ASC");
$display_date = date('d-M-Y');

?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Payment Voucher - FINPACK</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="../styles/form_style.css">
    <link rel="stylesheet" href="../styles/tally_style.css">
    <link rel="stylesheet" href="styles/navbar_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
    
<style>
        .view-mode {
            background-color: #f8f9fa;
            pointer-events: none;
        }
        .view-mode input, 
        .view-mode select, 
        .view-mode textarea {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
        }
    </style>
</head>
<body> <!-- Navbar -->
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
        
        <!-- Add view/edit toggle button -->
        <button id="toggleEditBtn" class="btn btn-primary" onclick="toggleEditMode()">
            <i class="bi bi-pencil"></i> Edit Mode
        </button>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="paymentVoucherForm" onsubmit="return validateForm()" class="voucher-form" autocomplete="off">
        <input type="hidden" name="voucher_id" value="<?= $voucher_id ?>">
        
        <div class="form-row">
            <div class="form-group">
                <label>Voucher No:</label>
                <input class="form-control" type="text" name="voucher_number" 
                    value="<?= htmlspecialchars($voucher_data['voucher_number'] ?? '') ?>" readonly>
            </div>
            <div class="form-group">
                <label>Date:</label>
                <input type="date" name="voucher_date" 
                    value="<?= htmlspecialchars($voucher_data['voucher_date'] ?? date('Y-m-d')) ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Paid From (Cash/Bank Account):</label>
                <select name="credit_ledger_id" id="credit_ledger" class="form-control" 
                    onchange="updateDebitLedgers(); fetchBalance(this, 'credit_balance')" required>
                    <option value="">--Select--</option>
                    <?php foreach ($ledgers as $ledger): ?>
                        <?php if (($ledger['acc_type'] === 'Asset') && ($ledger['book_type'] === 'Cash' || $ledger['book_type'] === 'Bank')): ?> 
                            <option value="<?= $ledger['ledger_id'] ?>" 
                                <?= (($credit_entry['ledger_id'] ?? 0) == $ledger['ledger_id']) ? 'selected' : '' ?>>
                                <?= $ledger['ledger_name'] ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <div id="credit_balance" class="balance-box">
                    <?php if (isset($credit_entry)): ?>
                        Balance: <?= $credit_entry['closing_balance'] ?? 0 ?> 
                        (<?= $credit_entry['debit_credit'] ?? '' ?>)
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-group">
                <label>Mode of Payment:</label>
                <select name="mode_of_payment" id="mode_of_payment" class="form-control" 
                    onchange="toggleRefFields(this.value)">
                    <option value="Cash" <?= ($credit_entry['mode_of_payment'] ?? 'Cash') == 'Cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="Cheque" <?= ($credit_entry['mode_of_payment'] ?? 'Cash') == 'Cheque' ? 'selected' : '' ?>>Cheque</option>
                </select>
            </div>
        </div>

        <div id="refFields" style="<?= ($credit_entry['mode_of_payment'] ?? 'Cash') == 'Cheque' ? '' : 'display:none;' ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Cheque No / Ref No:</label>
                    <input type="text" name="reference_number" class="form-control" 
                        value="<?= htmlspecialchars($voucher_data['reference_number'] ?? '') ?>" style="width: 70%;">
                </div>
                <div class="form-group">
                    <label>Reference Date:</label>
                    <input type="date" name="reference_date" class="form-control" 
                        value="<?= htmlspecialchars($credit_entry['reference_date'] ?? '') ?>" style="width: 70%;">
                </div>
                <div class="form-group">
                    <label>Bank Name:</label>
                    <input type="text" name="bank_name" class="form-control" 
                        value="<?= htmlspecialchars($credit_entry['bank_name'] ?? '') ?>" style="width: 70%;">
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
                    <?php if (!empty($debit_entries)): ?>
                        <?php foreach ($debit_entries as $index => $entry): ?>
                            <tr>
                                <td>
                                    <select name="debit_ledger_id[]" class="form-control" 
                                        onchange="fetchBalance(this, 'debit_balance_<?= $index ?>')" required>
                                        <option value="">--Select--</option>
                                        <?php foreach ($ledgers as $ledger): ?>
                                            <?php if (in_array($ledger['acc_type'], ['Expense', 'Asset','Liability'])): ?>
                                                <option value="<?= $ledger['ledger_id'] ?>" 
                                                    <?= $entry['ledger_id'] == $ledger['ledger_id'] ? 'selected' : '' ?>>
                                                    <?= $ledger['ledger_name'] ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="debit_balance_<?= $index ?>" class="balance-box">
                                        Balance: <?= $entry['closing_balance'] ?? 0 ?> 
                                        (<?= $entry['debit_credit'] ?? '' ?>)
                                    </div>
                                </td>
                                <td>
                                    <input type="number" name="debit_amount[]" class="form-control" 
                                        value="<?= $entry['amount'] ?? '' ?>" step="0.01" min="0.01" required>
                                </td>
                                <td>
                                    <input type="text" name="debit_narration[]" class="form-control" 
                                        value="<?= htmlspecialchars($entry['narration'] ?? '') ?>">
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Default empty row -->
                        <tr>
                            <td>
                                <select name="debit_ledger_id[]" class="form-control" onchange="fetchBalance(this, 'debit_balance_0')" required>
                                    <option value="">--Select--</option>
                                    <?php foreach ($ledgers as $ledger): ?>
                                        <?php if (in_array($ledger['acc_type'], ['Expense', 'Asset','Liability'])): ?>
                                            <option value="<?= $ledger['ledger_id'] ?>"><?= $ledger['ledger_name'] ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
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
                    <?php endif; ?>
                </tbody>
            </table>
            
            <button type="button" class="submit-button" style="width:20%;margin-left:320px;" onclick="addRow()">
                <i class="bi bi-plus-circle"></i> Add Row
            </button>
        </div>

        <button id="submitBtn" class="submit-button" style="width:40%;margin-left:230px;" type="submit" disabled>
            <i class="bi bi-save"></i> Update Voucher
        </button>
    </form>
</div>

<script>
// ===== EDIT MODE TOGGLE =====
let isEditMode = false;

function toggleEditMode() {
    isEditMode = !isEditMode;
    const form = document.getElementById('paymentVoucherForm');
    const btn = document.getElementById('toggleEditBtn');
    const submitBtn = document.getElementById('submitBtn');
    
    if (isEditMode) {
        form.classList.remove('view-mode');
        btn.innerHTML = '<i class="bi bi-eye"></i> View Mode';
        submitBtn.disabled = false;
    } else {
        form.classList.add('view-mode');
        btn.innerHTML = '<i class="bi bi-pencil"></i> Edit Mode';
        submitBtn.disabled = true;
    }
}

// Initialize in view mode
document.addEventListener('DOMContentLoaded', function() {
    toggleEditMode(); // Start in view mode
    updateDebitLedgers();
    
    <?php if (!empty($debit_entries)): ?>
        // If editing existing voucher, disable credit ledger change
        document.getElementById('credit_ledger').disabled = true;
    <?php endif; ?>
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
    if (!isEditMode) {
        alert("Please switch to Edit Mode first");
        return false;
    }
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

</script>
</body>
</html>