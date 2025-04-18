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
    $successMessage = "Journal voucher created successfully!";
}
$display_date = date('d-M-Y');

// Auto-generate voucher number
$result = $conn->query("SELECT MAX(CAST(SUBSTRING(voucher_number, 2) AS UNSIGNED)) as last_num 
                        FROM vouchers 
                        WHERE voucher_type = 'Journal' AND voucher_number LIKE 'J%'");
$row = $result->fetch_assoc();
$nextNum = $row['last_num'] ? $row['last_num'] + 1 : 1;
$voucherNumber = 'J' . $nextNum;

// Get all ledgers (Cash/Bank)
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
            // Insert into vouchers
            $stmt = $conn->prepare("INSERT INTO vouchers (user_id, voucher_number, voucher_type, voucher_date, total_amount) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isssd", $user_id, $voucher_number, $voucher_type, $voucher_date, $total_debit);
            $stmt->execute();
            $voucher_id = $stmt->insert_id;
            $stmt->close();

            $opposite_ledger_ids = implode(',', $credit_ledger_ids);

            // Handle DEBIT entries
            foreach ($debit_ledger_ids as $i => $ledger_id) {
                $amount = (float)$debit_amounts[$i];
                $narration = (string)($debit_narrations[$i]);

                $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $ledger_id);
                $stmt->execute();
                $stmt->bind_result($acc_code, $balance, $dc);
                $stmt->fetch();
                $stmt->close();

                $new_balance = ($dc == 'Debit') ? $balance + $amount : $balance - $amount;

                $stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, opposite_ledger, transaction_date, narration) VALUES (?, ?, ?, ?, 'Debit', ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiisdssss", $user_id, $voucher_id, $ledger_id, $acc_code, $amount, $new_balance, $opposite_ledger_ids, $voucher_date, $narration);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $stmt->bind_param("di", $new_balance, $ledger_id);
                $stmt->execute();
                $stmt->close();
            }

            // Handle CREDIT entries
            foreach ($credit_ledger_ids as $i => $ledger_id) {
                $amount = (float)$credit_amounts[$i];
                $nar = trim($credit_narrations[$i]);

                $opp_ledger_ids = implode(',', $debit_ledger_ids);

                $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $ledger_id);
                $stmt->execute();
                $stmt->bind_result($acc_code, $balance, $dc);
                $stmt->fetch();
                $stmt->close();

                $new_balance = ($dc == 'Debit') ? $balance - $amount : $balance + $amount;

                $stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, opposite_ledger, transaction_date, narration) VALUES (?, ?, ?, ?, 'Credit', ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiisdssss", $user_id, $voucher_id, $ledger_id, $acc_code, $amount, $new_balance, $opp_ledger_ids ,$voucher_date, $nar);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $stmt->bind_param("di", $new_balance, $ledger_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $success = "Journal Voucher $voucher_number created successfully!";
            $voucherNumber = 'J' . ($nextNum + 1);

            header("Location: journal_voucher.php?success=1");
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
    <title>Journal Voucher - FINPACK</title>
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
        <h2>Journal Voucher</h2>
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

    <form method="POST" id = "JournalVoucherForm" onsubmit="return validateForm()" class="voucher-form" autocomplete="off">
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
                <td><input type="text" name="debit_narration[]" class="form-control"></td>
                <td>
                    <button type="button" class="btn btn-outline-danger" onclick="this.closest('tr').remove()">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
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
        </tbody>
    </table>
    <button type="button" class="submit-button" onclick="addCreditRow()" style="width: 30%; margin-left: 280px;">
        <i class="bi bi-plus-circle"></i> Add Credit Row
    </button>
</div>

<!-- Submit Button -->
<div class="form-group">
    <button type="submit" class="submit-button" style="width: 40%; margin-left: 230px;">
        <i class="bi bi-save"></i> Save Journal Voucher
    </button>
</div>

</form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    updateContraToLedgers();
});

let debitRowCount = 1;
let creditRowCount = 1;

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
        document.getElementById("JournalVoucherForm")?.reset();
        // You can also manually clear dropdowns, date pickers, etc.
    }
});

</script>
</body>
</html>