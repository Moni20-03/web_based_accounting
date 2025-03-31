<?php
include 'db_connection.php';
session_start();

$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$today_date = date('Y-m-d');

// Fetch Ledgers based on their types
$cash_bank_ledgers = $conn->query("SELECT ledger_id, ledger_name, current_balance, acc_code, book_type FROM ledgers WHERE company_id = $company_id AND book_type IN ('Cash', 'Bank')")->fetch_all(MYSQLI_ASSOC);
$payment_to_ledgers = $conn->query("SELECT ledger_id, ledger_name, current_balance, acc_code FROM ledgers WHERE company_id = $company_id AND (acc_type = 'Expense' OR acc_type = 'Liability')")->fetch_all(MYSQLI_ASSOC);
$receipt_from_ledgers = $conn->query("SELECT ledger_id, ledger_name, current_balance, acc_code FROM ledgers WHERE company_id = $company_id AND (acc_type = 'Income' OR acc_type = 'Asset')")->fetch_all(MYSQLI_ASSOC);

// Generate Voucher Number
function getNextVoucherNumber($conn, $company_id, $type) {
    $prefix = ($type === "Payment") ? "P" : "R";
    $stmt = $conn->prepare("SELECT MAX(voucher_number) AS last_voucher FROM vouchers WHERE company_id = ? AND voucher_number LIKE ?");
    $like_param = $prefix . '%';
    $stmt->bind_param("is", $company_id, $like_param);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $last_number = isset($result['last_voucher']) ? intval(substr($result['last_voucher'], 1)) + 1 : 1;
    return $prefix . str_pad($last_number, 4, '0', STR_PAD_LEFT);
}

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $voucher_date = $_POST['voucher_date'];
    $voucher_type = $_POST['voucher_type'];
    $from_ledger = $_POST['from_ledger'];
    $narration = $_POST['narration'];
    $mode = $_POST['mode']; // Cash or Bank
    $transactions = $_POST['transactions'];

    // Validate date
    if ($voucher_date > date('Y-m-d')) {
        echo "<script>alert('Future dates are not allowed!'); window.history.back();</script>";
        exit;
    }

    if (empty($transactions)) {
        echo "<script>alert('At least one transaction is required!'); window.history.back();</script>";
        exit;
    }

    // Start transaction for atomic operations
    $conn->begin_transaction();

    try {
        // Generate voucher number
        $voucher_number = getNextVoucherNumber($conn, $company_id, $voucher_type);

        // Calculate total amount
        $total_amount = array_sum(array_column($transactions, 'amount'));

        // Insert Voucher
        $stmt = $conn->prepare("INSERT INTO vouchers (company_id, user_id, voucher_number, voucher_type, voucher_date, total_amount, narration, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iisssds", $company_id, $user_id, $voucher_number, $voucher_type, $voucher_date, $total_amount, $narration);
        $stmt->execute();
        $voucher_id = $stmt->insert_id;

        // Process Each Transaction
        foreach ($transactions as $txn) {
            $to_ledger = $txn['to_ledger'];
            $amount = floatval($txn['amount']);

            if ($amount <= 0) continue;

            // Fetch ledger details with locking to prevent concurrent updates
            $stmt = $conn->prepare("SELECT ledger_id, acc_code, current_balance FROM ledgers WHERE ledger_id IN (?, ?) FOR UPDATE");
            $stmt->bind_param("ii", $from_ledger, $to_ledger);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $ledgers = [];
            while ($row = $result->fetch_assoc()) {
                $ledgers[$row['ledger_id']] = $row;
            }

            if (count($ledgers) !== 2) {
                throw new Exception("Invalid ledger selected");
            }

            $from_acc = $ledgers[$from_ledger];
            $to_acc = $ledgers[$to_ledger];

            // Calculate closing balances based on voucher type
            if ($voucher_type === "Payment") {
                $from_closing_balance = $from_acc['current_balance'] - $amount;
                $to_closing_balance = $to_acc['current_balance'] + $amount;
                $from_txn_type = "Credit";
                $to_txn_type = "Debit";
            } else { // Receipt
                $from_closing_balance = $from_acc['current_balance'] + $amount;
                $to_closing_balance = $to_acc['current_balance'] - $amount;
                $from_txn_type = "Debit";
                $to_txn_type = "Credit";
            }

            // Set mode of payment based on selection
            $mode_of_payment = ($mode === "Bank") ? "Bank Payment" : "Cash Payment";
            if ($voucher_type === "Receipt") {
                $mode_of_payment = ($mode === "Bank") ? "Bank Receipt" : "Cash Receipt";
            }

            // Insert transactions (double entry) with all required fields
            $stmt = $conn->prepare("INSERT INTO transactions (
                company_id, user_id, voucher_id, ledger_id, acc_code, 
                transaction_type, amount, closing_balance, mode_of_payment, 
                opposite_ledger, narration, transaction_date, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            // First entry (From account)
            $stmt->bind_param(
                "iiiisssssiss", 
                $company_id, $user_id, $voucher_id, $from_ledger, $from_acc['acc_code'],
                $from_txn_type, $amount, $from_closing_balance, $mode_of_payment,
                $to_ledger, $narration, $voucher_date
            );
            $stmt->execute();
            
            // Second entry (To account)
            $stmt->bind_param(
                "iiiisssssiss", 
                $company_id, $user_id, $voucher_id, $to_ledger, $to_acc['acc_code'],
                $to_txn_type, $amount, $to_closing_balance, $mode_of_payment,
                $from_ledger, $narration, $voucher_date
            );
            $stmt->execute();

            // Update Ledger Balances
            $stmt = $conn->prepare("UPDATE ledgers SET current_balance = ? WHERE ledger_id = ?");
            $stmt->bind_param("di", $from_closing_balance, $from_ledger);
            $stmt->execute();
            $stmt->bind_param("di", $to_closing_balance, $to_ledger);
            $stmt->execute();
        }

        // Commit transaction if all operations succeed
        $conn->commit();
        echo "<script>alert('Voucher created successfully!'); window.location.href='create_voucher.php';</script>";
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        echo "<script>alert('Error creating voucher: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment/Receipt Voucher</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            line-height: 1.6;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input, select, textarea {
            padding: 8px;
            width: 100%;
            max-width: 400px;
            box-sizing: border-box;
        }
        textarea {
            height: 80px;
        }
        .transaction-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 4px;
        }
        .transaction-row select, .transaction-row input {
            flex: 1;
            max-width: 200px;
        }
        .balance-display {
            color: #666;
            font-style: italic;
        }
        button {
            padding: 8px 15px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .remove-btn {
            background-color: #f44336;
        }
        .remove-btn:hover {
            background-color: #d32f2f;
        }
        #transaction-container {
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <h2>Payment/Receipt Voucher</h2>
    <form method="POST" onsubmit="return validateForm()">
        
        <div class="form-group">
            <label>Voucher Type:</label>
            <select name="voucher_type" id="voucher_type" required onchange="updateLedgerOptions()">
                <option value="">Select Type</option>
                <option value="Payment">Payment</option>
                <option value="Receipt">Receipt</option>
            </select>
        </div>

        <div class="form-group">
            <label>Voucher Date:</label>
            <input type="date" name="voucher_date" id="voucher_date" required value="<?= $today_date ?>">
        </div>

        <div class="form-group">
            <label>From Account:</label>
            <select name="from_ledger" id="from_ledger" required onchange="showBalance(this, 'from_balance')">
                <option value="">Select Account</option>
                <!-- Options will be populated by JavaScript -->
            </select>
            <span id="from_balance" class="balance-display"></span>
        </div>

        <div class="form-group">
            <label>Narration:</label>
            <textarea name="narration" required></textarea>
        </div>

        <div class="form-group">
            <label>Book Type:</label>
            <label><input type="radio" name="mode" value="Bank" required checked> Bank</label>
            <label><input type="radio" name="mode" value="Cash" required> Cash</label>
        </div>

        <h3>Transactions</h3>
        <div id="transaction-container">
            <!-- Transaction rows will be added here -->
        </div>
        
        <div class="form-group">
            <button type="button" onclick="addTransactionRow()">Add Transaction</button>
        </div>

        <div class="form-group">
            <button type="submit">Save Voucher</button>
        </div>

    </form>

    <script>
           // Ledger data from PHP
           const ledgerData = {
            cashBank: <?= json_encode($cash_bank_ledgers) ?>,
            paymentTo: <?= json_encode($payment_to_ledgers) ?>,
            receiptFrom: <?= json_encode($receipt_from_ledgers) ?>
        };

        // Updated JavaScript to filter cash/bank accounts based on selection
        function updateLedgerOptions() {
            const voucherType = document.getElementById('voucher_type').value;
            const fromLedgerSelect = document.getElementById('from_ledger');
            const bookType = document.querySelector('input[name="mode"]:checked').value;
            
            fromLedgerSelect.innerHTML = '<option value="">Select Account</option>';
            
            if (voucherType === 'Payment') {
                // Filter cash/bank accounts based on selected book type
                ledgerData.cashBank
                    .filter(ledger => ledger.book_type === bookType)
                    .forEach(ledger => {
                        fromLedgerSelect.innerHTML += `
                            <option value="${ledger.ledger_id}" data-balance="${ledger.current_balance}">
                                ${ledger.ledger_name}
                            </option>`;
                    });
            } else if (voucherType === 'Receipt') {
                ledgerData.receiptFrom.forEach(ledger => {
                    fromLedgerSelect.innerHTML += `
                        <option value="${ledger.ledger_id}" data-balance="${ledger.current_balance}">
                            ${ledger.ledger_name}
                        </option>`;
                });
            }
            
            document.getElementById('transaction-container').innerHTML = '';
        }

        // Add event listener for book type change
        document.querySelectorAll('input[name="mode"]').forEach(radio => {
            radio.addEventListener('change', updateLedgerOptions);
        });

        // Show balance when ledger is selected
        function showBalance(select, targetId) {
            const selectedOption = select.options[select.selectedIndex];
            const balance = selectedOption.getAttribute('data-balance');
            document.getElementById(targetId).textContent = balance ? `Current Balance: ${balance}` : '';
        }

        // Add a new transaction row
        function addTransactionRow() {
            const voucherType = document.getElementById('voucher_type').value;
            if (!voucherType) {
                alert('Please select voucher type first');
                return;
            }
            
            const rowCount = document.querySelectorAll('.transaction-row').length;
            const rowId = 'transaction_' + rowCount;
            
            // Determine which ledgers to show based on voucher type
            const transactionLedgers = (voucherType === 'Payment') ? 
                ledgerData.paymentTo : ledgerData.cashBank;
            
            const row = document.createElement('div');
            row.className = 'transaction-row';
            row.id = rowId;
            
            row.innerHTML = `
                <select name="transactions[${rowCount}][to_ledger]" required onchange="showBalance(this, 'balance_${rowCount}')">
                    <option value="">Select Account</option>
                    ${transactionLedgers.map(ledger => 
                        `<option value="${ledger.ledger_id}" data-balance="${ledger.current_balance}">${ledger.ledger_name}</option>`
                    ).join('')}
                </select>
                <span id="balance_${rowCount}" class="balance-display"></span>
                <input type="number" name="transactions[${rowCount}][amount]" min="0.01" step="0.01" required placeholder="Amount">
                <button type="button" class="remove-btn" onclick="removeTransactionRow('${rowId}')">Remove</button>
            `;
            
            document.getElementById('transaction-container').appendChild(row);
        }

        // Remove a transaction row
        function removeTransactionRow(rowId) {
            const row = document.getElementById(rowId);
            if (row) {
                row.remove();
            }
        }

        // Form validation
        function validateForm() {
            const voucherType = document.getElementById('voucher_type').value;
            const fromLedger = document.getElementById('from_ledger').value;
            const transactionRows = document.querySelectorAll('.transaction-row');
            
            if (!voucherType) {
                alert('Please select voucher type');
                return false;
            }
            
            if (!fromLedger) {
                alert('Please select from account');
                return false;
            }
            
            if (transactionRows.length === 0) {
                alert('Please add at least one transaction');
                return false;
            }
            
            // Validate each transaction amount
            let valid = true;
            document.querySelectorAll('input[name^="transactions"]').forEach(input => {
                if (parseFloat(input.value) <= 0) {
                    alert('Transaction amount must be greater than 0');
                    valid = false;
                }
            });
            
            return valid;
        }
        
    </script>
</body>
</html>