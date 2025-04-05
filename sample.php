<?php 
include 'findb.php'; 
$user_id = $_SESSION['user_id'] ?? 0;
$errors = [];

// Auto-generate voucher number
$result = $conn->query("SELECT MAX(CAST(SUBSTRING(voucher_number, 3) AS UNSIGNED)) as last_num 
          FROM vouchers 
          WHERE voucher_type = 'Payment' 
          AND voucher_number LIKE 'P%'");
$row = $result->fetch_assoc();
$nextNum = $row['last_num'] ? $row['last_num'] + 1 : 1;
$voucherNumber = 'PY' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);



if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $voucher_number = trim($_POST['voucher_number']);
    $voucher_date = $_POST['voucher_date'];
    $credit_ledger_id = $_POST['credit_ledger_id'];
    $mode_of_payment = $_POST['mode_of_payment'];
    $reference_number = $_POST['reference_number'] ?? null;
    $narration = $_POST['narration'] ?? null;
    $voucher_type = 'Payment';

    $debit_ledgers = $_POST['debit_ledger_id'] ?? [];
    $debit_amounts = $_POST['debit_amount'] ?? [];
    $debit_narrations = $_POST['debit_narration'] ?? [];

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
            $stmt = $conn->prepare("INSERT INTO vouchers (user_id, voucher_number, reference_number, voucher_type, voucher_date, total_amount, narration) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssds", $user_id, $voucher_number, $reference_number, $voucher_type, $voucher_date, $total_amount, $narration);
            $stmt->execute();
            $voucher_id = $stmt->insert_id;
            $stmt->close();

            // === Insert Credit Entry ===
            $credit_stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
            $credit_stmt->bind_param("i", $credit_ledger_id);
            $credit_stmt->execute();
            $credit_stmt->bind_result($acc_code, $cur_bal, $dc_type);
            $credit_stmt->fetch();
            $credit_stmt->close();

            // Update balance logic
            $new_balance = ($dc_type === 'Credit') ? $cur_bal + $total_amount : $cur_bal - $total_amount;

            $txn_stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, transaction_date, narration) VALUES (?, ?, ?, ?, 'Credit', ?, ?, ?, ?, ?)");
            $txn_stmt->bind_param("iiisdssss", $user_id, $voucher_id, $credit_ledger_id, $acc_code, $total_amount, $new_balance, $mode_of_payment, $voucher_date, $narration);
            $txn_stmt->execute();
            $txn_stmt->close();

            // Update Ledger
            $update_stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
            $update_stmt->bind_param("di", $new_balance, $credit_ledger_id);
            $update_stmt->execute();

            // === Insert Debit Entries ===
            foreach ($debit_ledgers as $i => $ledger_id) {
                $amount = (float)$debit_amounts[$i];
                $note = $debit_narrations[$i] ?? '';

                $stmt = $conn->prepare("SELECT acc_code, current_balance, debit_credit FROM ledgers WHERE ledger_id = ?");
                $stmt->bind_param("i", $ledger_id);
                $stmt->execute();
                $stmt->bind_result($acc_code, $cur_bal, $dc_type);
                $stmt->fetch();
                $stmt->close();

                $new_bal = ($dc_type === 'Debit') ? $cur_bal + $amount : $cur_bal - $amount;

                $txn_stmt = $conn->prepare("INSERT INTO transactions (user_id, voucher_id, ledger_id, acc_code, transaction_type, amount, closing_balance, mode_of_payment, opposite_ledger, transaction_date, narration) VALUES (?, ?, ?, ?, 'Debit', ?, ?, ?, ?, ?, ?)");
                $txn_stmt->bind_param("iiisdssiss", $user_id, $voucher_id, $ledger_id, $acc_code, $amount, $new_bal, $mode_of_payment, $credit_ledger_id, $voucher_date, $note);
                $txn_stmt->execute();
                $txn_stmt->close();

                // Update balance
                $update_stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
                $update_stmt->bind_param("di", $new_bal, $ledger_id);
                $update_stmt->execute();
            }

            $conn->commit();
            echo "Voucher created successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            echo "Error: " . $e->getMessage();
        }
    } else {
        foreach ($errors as $e) echo "<p style='color:red;'>$e</p>";
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Payment Voucher - FINPACK</title>
    <style>
        <?php include 'assets/style.css'; ?>
    </style>
</head>
<body>
<h2>Payment Voucher</h2>

<form method="POST" onsubmit="return validateForm()">

    <!-- 1. Voucher Number -->
    <label>Voucher No:
        <input type="text" name="voucher_number" value="<?= $voucherNumber ?>" readonly>
    </label>

    <!-- 2. Voucher Date -->
    <label>Date:
        <input type="date" name="voucher_date" value="<?= date('Y-m-d') ?>" required>
    </label>

    <!-- 3. Hidden Voucher Type -->
    <input type="hidden" name="voucher_type" value="Payment">

    <br><br>

    <!-- 4. Credit Ledger (Cash/Bank only) -->
    <label>Paid From (Cash/Bank Account):
        <select name="credit_ledger_id" id="credit_ledger" onchange="fetchBalance(this)" data-target="credit_balance" required>
            <option value="">--Select--</option>
           <?php $ledgers = $conn->query("SELECT * FROM ledgers ORDER BY ledger_name ASC");?>
            <?php foreach ($ledgers as $ledger): ?>
                <?php if ($ledger['book_type'] === 'Cash' || $ledger['book_type'] === 'Bank'): ?> 
                    <option value="<?= $ledger['ledger_id'] ?>"><?= $ledger['ledger_name'] ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
    </label>
    <div id="credit_balance" class="balance-box"></div>

    <!-- 5. Show Ledger Balance (Read-only)
    <label>Current Balance:
        <input type="text" id="ledger_balance" readonly>
    </label> -->

    <br><br>

    <!-- 6. Debit Entries Table -->
    <h3>Debit Entries (To Ledger)</h3>
    <table id="debitTable">
        <tr>
            <th>Ledger</th>
            <th>Amount</th>
            <th>Narration</th>
            <th>Action</th>
        </tr>
        <tr>
            <td>
            <?php
                $selected_credit_id = $_POST['credit_ledger_id'] ?? null; 
            ?>
                <select name="debit_ledger_id[]" onchange="fetchBalance(this)" data-target="debit_balance_0" required>
        <option value="">--Select--</option>
        <?php foreach ($ledgers as $ledger): ?>
            <?php if (in_array($ledger['acc_type'], ['Expense', 'Asset']) &&  $ledger['ledger_id'] != $selected_credit_id): ?>
                <option value="<?= $ledger['ledger_id'] ?>"><?= $ledger['ledger_name'] ?></option>
            <?php endif; ?>
        <?php endforeach; ?>
    </select>
    <div id="debit_balance_0" class="balance-box"></div>
            </td>
            <td><input type="number" name="debit_amount[]" step="0.01" required></td>
            <td><input type="text" name="debit_narration[]"></td>
            <td><button type="button" onclick="addRow()">+</button></td>
        </tr>
    </table>

    <br>

    <!-- 7. Mode of Payment -->
    <label>Mode of Payment:
        <select name="mode_of_payment" id="mode_of_payment" onchange="toggleRefFields(this.value)">
            <option value="Cash">Cash</option>
            <option value="Cheque">Cheque</option>
        </select>
    </label>

    <!-- Reference Details shown only for Cheque -->
    <div id="refFields" style="display:none; margin-top: 10px;">
        <label>Cheque No / Ref No:
            <input type="text" name="reference_number">
        </label>
        <label>Reference Date:
            <input type="date" name="reference_date">
        </label>
        <label>Bank Name:
            <input type="text" name="bank_name">
        </label>
    </div>

    <br>

    <!-- Narration (Optional) -->
    <label>Narration:
        <textarea name="narration" rows="3" cols="50"></textarea>
    </label>

    <br><br>
    <button type="submit">Save Voucher</button>

</form>

<script>
function addRow() {
    let table = document.getElementById("debitTable");
    let row = table.rows[1].cloneNode(true);
    row.querySelectorAll("input, select").forEach(input => {
        input.value = '';
    });
    table.appendChild(row);
}

// Show reference fields only when "Cheque" is selected
function toggleRefFields(value) {
    document.getElementById('refFields').style.display = (value === 'Cheque') ? 'block' : 'none';
}

function fetchBalance(selectEl) {
    const ledgerId = selectEl.value;
    const targetId = selectEl.getAttribute('data-target');
    const displayBox = document.getElementById(targetId);

    if (!ledgerId || !displayBox) return;

    fetch(`get_ledger_balance.php?ledger_id=${ledgerId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                displayBox.innerHTML = `<small>Balance: ${data.balance} (${data.type})</small>`;
            } else {
                displayBox.innerHTML = "<small style='color:red;'>Balance not found</small>";
            }
        })
        .catch(() => {
            displayBox.innerHTML = "<small style='color:red;'>Error loading balance</small>";
        });
}

</script>

</body>
</html>
