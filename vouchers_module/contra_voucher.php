<?php
include '../database/findb.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$user_id = $_SESSION['user_id'] ?? 0;
$company_db = $_SESSION['company_name'];

$errors = [];
$successMessage = '';
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $successMessage = "Contra voucher created successfully!";
}
$display_date = date('d-M-Y');

// Auto-generate voucher number
$result = $conn->query("SELECT MAX(CAST(SUBSTRING(voucher_number, 2) AS UNSIGNED)) as last_num 
                        FROM vouchers 
                        WHERE voucher_type = 'Contra' AND voucher_number LIKE 'C%'");
$row = $result->fetch_assoc();
$nextNum = $row['last_num'] ? $row['last_num'] + 1 : 1;
$voucherNumber = 'C' . $nextNum;

// Get all ledgers (Cash/Bank)
$ledgers = $conn->query("SELECT * FROM ledgers WHERE acc_type = 'Asset' AND book_type IN ('cash', 'Bank') ORDER BY ledger_name ASC");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $voucher_number = trim($_POST['voucher_number']);
    $voucher_date = $_POST['voucher_date'];
    $from_ledger_id = $_POST['credit_ledger_id'];
    $to_ledger_ids = $_POST['debit_ledger_id'] ?? [];
    $to_amounts = $_POST['debit_amount'] ?? [];
    $to_narrations = $_POST['debit_narration'] ?? [];
    $mode_of_payment = $_POST['mode_of_payment'] ?? 'Transfer';
    $voucher_type = 'Contra';

    // Validation
    if (empty($voucher_number)) $errors[] = "Voucher number missing.";
    if (empty($voucher_date)) $errors[] = "Date is required.";
    if (empty($from_ledger_id)) $errors[] = "Transfer From ledger is required.";
    if (empty($to_ledger_ids)) $errors[] = "At least one Transfer To entry is required.";

    $total_amount = 0;
    foreach ($to_ledger_ids as $index => $to_id) {
        $amt = (float)$to_amounts[$index];
        if (empty($to_id) || $amt <= 0) {
            $errors[] = "Row " . ($index + 1) . ": Please select a valid 'To' ledger and enter amount.";
        } elseif ($to_id == $from_ledger_id) {
            $errors[] = "Row " . ($index + 1) . ": From and To ledger cannot be the same.";
        } else {
            $total_amount += $amt;
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            // Insert 
            $stmt = $conn->prepare("INSERT INTO vouchers (user_id, voucher_number, voucher_type, voucher_date, total_amount) 
                                    VALUES (?, ?, ?, ?, ?)");
            $narration_summary = "Contra transfer from ledger ID: $from_ledger_id"; // optional
            $stmt->bind_param("isssd", $user_id, $voucher_number, $voucher_type, $voucher_date, $total_amount);
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

            // FROM LEDGER (Credit)
            $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
            $stmt->bind_param("i", $from_ledger_id);
            $stmt->execute();
            $stmt->bind_result($from_acc_code, $from_bal, $from_dc);
            $stmt->fetch();
            $stmt->close();

            $opposite_ledger_ids = implode(',', $to_ledger_ids);

            $new_from_bal = ($from_dc === 'Debit') ? $from_bal - $total_amount : $from_bal + $total_amount;

            $stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, transaction_date, narration, opposite_ledger) 
                                    VALUES (?, ?, ?, ?, 'Credit', ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiisdssssi", $user_id, $voucher_id, $from_ledger_id, $from_acc_code, $total_amount, $new_from_bal, $mode_of_payment, $voucher_date, $narration_summary, $opposite_ledger_ids);
            $stmt->execute();
            $txn_id = $stmt->insert_id;
            $stmt->close();

            // === Log Credit Transaction Insert ===
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) VALUES (?, 'transactions', ?, 'INSERT', NULL, ?)");
            $new_value = json_encode([
                'voucher_id' => $voucher_id,
                'ledger_id' => $from_ledger_id,
                'amount' => $total_amount,
                'type' => 'Credit',
                'closing_balance' => $new_from_bal
            ]);
            $log_stmt->bind_param("iis", $user_id, $txn_id, $new_value);
            $log_stmt->execute();
            $log_stmt->close();

            $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
            $stmt->bind_param("di", $new_from_bal, $from_ledger_id);
            $stmt->execute();
            $stmt->close();

            // TO LEDGERs (Debit)
            foreach ($to_ledger_ids as $index => $to_id) {
                $amount = (float)$to_amounts[$index];
                $narration = (string)($to_narrations[$index] ?? '');

                $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $to_id);
                $stmt->execute();
                $stmt->bind_result($to_acc_code, $to_bal, $to_dc);
                $stmt->fetch();
                $stmt->close();

                $new_to_bal = ($to_dc === 'Debit') ? $to_bal + $amount : $to_bal - $amount;

                $stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, transaction_date, narration, opposite_ledger) 
                                        VALUES (?, ?, ?, ?, 'Debit', ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiisdssssi", $user_id, $voucher_id, $to_id, $to_acc_code, $amount, $new_to_bal, $mode_of_payment, $voucher_date, $narration, $from_ledger_id);
                $stmt->execute();
                $txn_id = $stmt->insert_id;
                $stmt->close();

                 // === Log Debit Transaction Insert ===
                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, table_name, record_id, action, old_value, new_value) 
                VALUES (?, 'transactions', ?, 'INSERT', NULL, ?)");
                $new_value = json_encode([
                    'voucher_id' => $voucher_id,
                    'ledger_id' => $to_id,
                    'amount' => $amount,
                    'type' => 'Debit',
                    'closing_balance' => $new_to_bal
                ]);
                $log_stmt->bind_param("iis", $user_id, $txn_id, $new_value);
                $log_stmt->execute();
                $log_stmt->close();

                $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $stmt->bind_param("di", $new_to_bal, $to_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $success = "Contra voucher $voucherNumber created successfully!";
            $voucherNumber = 'C' . ($nextNum + 1);

            header("Location: contra_voucher.php?success=1");
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
    <title>Contra Voucher - FINPACK</title>
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
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
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
        <h2>Contra Voucher</h2>
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

    <form method="POST" id = "contravoucherform"onsubmit="return validateForm()" class="voucher-form" autocomplete="off">
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

    <input type="hidden" name="voucher_type" value="Contra">


        <div class="form-row">
        <div class="form-group">
        <label>Transfer From (Cash/Bank Account):</label>
        <select name="credit_ledger_id" id="from_ledger" class="form-control" onchange="updateContraToLedgers();fetchBalance(this, 'credit_balance')" required>
            <option value="">--Select--</option>
            <?php foreach ($ledgers as $ledger): ?>
                <option value="<?= $ledger['ledger_id'] ?>" <?= (isset($_POST['credit_ledger_id']) && $_POST['credit_ledger_id'] == $ledger['ledger_id']) ? 'selected' : '' ?>>
                    <?= $ledger['ledger_name'] ?>
                </option>
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
        <h4>Transfer To (Contra Voucher)</h4>
        <table id="creditTable" style="margin-top:0px;">
    <thead>
        <tr>
            <th width="45%">Ledger Account (To)</th>
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
                <?php foreach ($ledgers as $ledger): ?>
                    <?php if ($ledger['ledger_id'] != ($_POST['credit_ledger_id'] ?? null)): ?>
                        <option value="<?= $ledger['ledger_id'] ?>">
                            <?= $ledger['ledger_name'] ?>
                        </option>
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
    </tbody>
</table>

<button type="button" class="submit-button" style="width:20%;margin-left:320px;" onclick="addRow()">
    <i class="bi bi-plus-circle"></i> Add Row
</button>

<button class="submit-button" style="width:40%;margin-left:230px;" type="submit">
    <i class="bi bi-save"></i> Save Voucher
</button>
</form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    updateContraToLedgers();
});

function addRow() {
    let table = document.getElementById("creditTable").getElementsByTagName('tbody')[0];
    let rowCount = table.rows.length;
    
    // Use rowCount + 1 to avoid ID conflicts with the last row
    let newRowId = rowCount;
    let newRow = table.rows[rowCount - 1].cloneNode(true);
    
    // Clear values
    newRow.querySelector('select').value = '';
    newRow.querySelector('input[type="number"]').value = '';
    newRow.querySelector('input[type="text"]').value = '';
    
    // Update select element
    let select = newRow.querySelector('select[name="debit_ledger_id[]"]');
    let newTargetId = 'debit_balance_' + newRowId; // Changed from contra_balance to debit_balance
    
    // Remove existing event listeners to avoid duplicates
    let newSelect = select.cloneNode(true);
    select.parentNode.replaceChild(newSelect, select);
    
    // Set new attributes and event listener
    newSelect.setAttribute('data-target', newTargetId);
    newSelect.addEventListener('change', function() {
        fetchBalance(this, newTargetId);
    });
    
    // Update balance div
    let balanceDiv = newRow.querySelector('div.balance-box');
    balanceDiv.id = newTargetId;
    balanceDiv.innerHTML = '';
    balanceDiv.style.color = ''; // Reset color
    
    // Add the new row
    table.appendChild(newRow);
    
    // Update the dropdown options
    updateContraToLedgers();
}

function toggleRefFields(mode) {
    const refFields = document.getElementById('refFields');
    refFields.style.display = mode === 'Cheque' ? 'block' : 'none';

    const refInputs = refFields.querySelectorAll('input');
    refInputs.forEach(input => {
        input.required = mode === 'Cheque';
    });
}

function updateContraToLedgers() {
    const fromLedgerId = document.getElementById('from_ledger').value;
    const toSelects = document.querySelectorAll('select[name="debit_ledger_id[]"]');

    toSelects.forEach((select, i) => {
        const currentValue = select.value;
        const targetId = 'debit_balance_' + i;
        select.innerHTML = '<option value="">--Select--</option>';

        <?php foreach ($ledgers as $ledger): ?>
            <?php if ($ledger['book_type'] === 'Cash' || $ledger['book_type'] === 'Bank'): ?>
                if ('<?= $ledger['ledger_id'] ?>' !== fromLedgerId) {
                    const option = new Option('<?= $ledger['ledger_name'] ?>', '<?= $ledger['ledger_id'] ?>');
                    select.add(option);
                    // Set selected if this was the previously selected value
                    if ('<?= $ledger['ledger_id'] ?>' === currentValue) {
                        option.selected = true;
                    }
                }
            <?php endif; ?>
        <?php endforeach; ?>

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

function validateContraForm() {
    let fromLedger = document.getElementById('from_ledger');
    let toLedgers = document.querySelectorAll('select[name="to_ledger_id[]"]');
    let errors = [];

    document.querySelectorAll('.is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
    });

    if (!fromLedger.value) {
        fromLedger.classList.add('is-invalid');
        errors.push('Please select the "Transfer From" ledger.');
    }

    toLedgers.forEach((ledger, index) => {
        const row = ledger.closest('tr');
        const amountInput = row.querySelector('input[type="number"]');
        const amount = parseFloat(amountInput.value);

        if (!ledger.value) {
            ledger.classList.add('is-invalid');
            errors.push(`Row ${index + 1}: Please select a "Transfer To" ledger`);
        }

        if (isNaN(amount) || amount <= 0) {
            amountInput.classList.add('is-invalid');
            errors.push(`Row ${index + 1}: Please enter a valid amount`);
        }

        if (ledger.value && ledger.value === fromLedger.value) {
            ledger.classList.add('is-invalid');
            fromLedger.classList.add('is-invalid');
            errors.push(`Row ${index + 1}: From and To ledgers cannot be the same`);
        }
    });

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

window.addEventListener("load", function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get("success") === "1") {
        document.getElementById("contravoucherform")?.reset();
        // You can also manually clear dropdowns, date pickers, etc.
    }
});
</script>
</body>
</html>