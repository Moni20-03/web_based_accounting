<?php
include 'db_connection.php';
session_start();

$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$today_date = date('Y-m-d');

// Fetch required data with error handling
try {
    $parties = $conn->query("SELECT party_id, party_name FROM parties WHERE company_id = $company_id")->fetch_all(MYSQLI_ASSOC);
    $inventory_items = $conn->query("SELECT item_id, item_name, item_code, sales_rate, purchase_rate, tax_rate, current_stock, unit FROM inventory_items WHERE company_id = $company_id")->fetch_all(MYSQLI_ASSOC);
    $tax_ledgers = $conn->query("SELECT ledger_id, ledger_name FROM ledgers WHERE company_id = $company_id AND acc_type = 'Duty/Tax'")->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    die("Error fetching data: " . $e->getMessage());
}

// Generate Voucher Number
function getNextVoucherNumber($conn, $company_id, $type) {
    $prefix = ($type === "Sales") ? "SI" : "PI";
    $stmt = $conn->prepare("SELECT MAX(voucher_number) AS last_voucher FROM vouchers WHERE company_id = ? AND voucher_number LIKE ?");
    $like_param = $prefix . '%';
    $stmt->bind_param("is", $company_id, $like_param);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $last_number = isset($result['last_voucher']) ? intval(substr($result['last_voucher'], 3)) + 1 : 1;
    return $prefix . str_pad($last_number, 4, '0', STR_PAD_LEFT);
}

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $voucher_date = $_POST['voucher_date'] ?? $today_date;
    $voucher_type = $_POST['voucher_type'] ?? '';
    $party_id = $_POST['party_id'] ?? 0;
    $invoice_number = $_POST['invoice_number'] ?? '';
    $narration = $_POST['narration'] ?? '';
    $round_off = isset($_POST['round_off']) ? floatval($_POST['round_off']) : 0;
    $items = $_POST['items'] ?? [];

    // Validate
    if (empty($items)) {
        echo "<script>alert('At least one item is required!'); window.history.back();</script>";
        exit;
    }

    $conn->begin_transaction();

    try {
        // Calculate totals
        $total_amount = array_sum(array_column($items, 'amount'));
        $total_tax = array_sum(array_column($items, 'tax_amount'));
        $grand_total = $total_amount + $total_tax + $round_off;

        // Generate voucher number
        $voucher_number = getNextVoucherNumber($conn, $company_id, $voucher_type);

        // Insert Voucher
        $stmt = $conn->prepare("INSERT INTO vouchers (
            company_id, user_id, voucher_number, voucher_type, 
            voucher_date, total_amount, tax_amount, narration, 
            party_id, invoice_number, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        $stmt->bind_param(
            "iisssddsis", 
            $company_id, $user_id, $voucher_number, $voucher_type, 
            $voucher_date, $total_amount, $total_tax, $narration, 
            $party_id, $invoice_number
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Voucher creation failed: " . $stmt->error);
        }
        
        $voucher_id = $stmt->insert_id;

        // Process Items
        foreach ($items as $item) {
            $item_id = $item['item_id'] ?? 0;
            $quantity = floatval($item['quantity'] ?? 0);
            $rate = floatval($item['rate'] ?? 0);
            $amount = floatval($item['amount'] ?? 0);
            $tax_rate = floatval($item['tax_rate'] ?? 0);
            $tax_amount = floatval($item['tax_amount'] ?? 0);
            $discount = isset($item['discount']) ? floatval($item['discount']) : 0;

            // Insert voucher item
            $stmt = $conn->prepare("INSERT INTO voucher_items (
                company_id, user_id, voucher_id, inventory_item_id, quantity, rate, 
                amount, tax_rate, tax_amount, discount, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            $stmt->bind_param(
                "iiiidddddd", 
                $company_id, $user_id, $voucher_id, $item_id, $quantity, $rate,
                $amount, $tax_rate, $tax_amount, $discount
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Item insertion failed: " . $stmt->error);
            }

            // Update inventory
            $operator = ($voucher_type === "Purchase") ? "+" : "-";
            $update_query = "UPDATE inventory_items SET current_stock = current_stock $operator $quantity WHERE item_id = $item_id";
            if (!$conn->query($update_query)) {
                throw new Exception("Inventory update failed: " . $conn->error);
            }
        }

        // Get ledger IDs with validation
        $sales_ledger = $conn->query("SELECT ledger_id FROM ledgers WHERE company_id = $company_id AND acc_type = 'Income' AND ledger_name LIKE 'Sales%' LIMIT 1")->fetch_assoc();
        $purchase_ledger = $conn->query("SELECT ledger_id FROM ledgers WHERE company_id = $company_id AND acc_type = 'Expense' AND ledger_name LIKE 'Purchase%' LIMIT 1")->fetch_assoc();
        $party_ledger = $conn->query("SELECT ledger_id FROM parties WHERE party_id = $party_id LIMIT 1")->fetch_assoc();

        if (!$party_ledger) {
            throw new Exception("Party ledger not found");
        }

        // Prepare transaction statement
$stmt = $conn->prepare("INSERT INTO transactions (
    company_id, user_id, voucher_id, ledger_id, 
    transaction_type, amount, narration, transaction_date, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

if ($voucher_type === "Sales") {
    // Sales Accounting Entries
    if (!$sales_ledger) {
        throw new Exception("Sales ledger not found");
    }
    
    // Define transaction types as variables
    $debit_type = "Debit";
    $credit_type = "Credit";

    // 1. Debit Party Account
    $stmt->bind_param(
        "iiiissss", 
        $company_id, $user_id, $voucher_id, $party_ledger['ledger_id'], 
        $debit_type, $grand_total, $narration, $voucher_date
    );
    $stmt->execute();

    // 2. Credit Sales Account
    $stmt->bind_param(
        "iiiissss", 
        $company_id, $user_id, $voucher_id, $sales_ledger['ledger_id'], 
        $credit_type, $total_amount, $narration, $voucher_date
    );
    $stmt->execute();


            // 3. Credit Tax Account (if tax exists)
            if ($total_tax > 0 && !empty($tax_ledgers)) {
                $tax_ledger = $tax_ledgers[0]['ledger_id'];
                $stmt->bind_param("iiiisss", $company_id, $user_id, $voucher_id, $tax_ledger, "Credit", $total_tax, $narration, $voucher_date);
                $stmt->execute();
            }
        } else {
            // Purchase Accounting Entries
            if (!$purchase_ledger) {
                throw new Exception("Purchase ledger not found");
            }
            
            // 1. Credit Party Account
            $stmt->bind_param("iiiisss", $company_id, $user_id, $voucher_id, $party_ledger['ledger_id'], "Credit", $grand_total, $narration, $voucher_date);
            $stmt->execute();

            // 2. Debit Purchase Account
            $stmt->bind_param("iiiisss", $company_id, $user_id, $voucher_id, $purchase_ledger['ledger_id'], "Debit", $total_amount, $narration, $voucher_date);
            $stmt->execute();

            // 3. Debit Tax Account (if tax exists)
            if ($total_tax > 0 && !empty($tax_ledgers)) {
                $tax_ledger = $tax_ledgers[0]['ledger_id'];
                $stmt->bind_param("iiiisss", $company_id, $user_id, $voucher_id, $tax_ledger, "Debit", $total_tax, $narration, $voucher_date);
                $stmt->execute();
            }
        }

        $conn->commit();
        echo "<script>alert('Voucher created successfully!'); window.location.href='create_sales_purchase.php';</script>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
    }
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales/Purchase Voucher</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { padding: 8px; width: 100%; max-width: 400px; }
        textarea { height: 80px; }
        .item-row { display: flex; gap: 10px; margin-bottom: 10px; padding: 10px; background: #f5f5f5; align-items: center; }
        .item-row select { min-width: 200px; }
        .item-row input[type="number"] { width: 80px; }
        .item-row input[type="text"] { width: 60px; }
        #items-container { margin-bottom: 15px; }
        button { padding: 8px 15px; background: #4CAF50; color: white; border: none; cursor: pointer; }
        button:hover { background: #45a049; }
        .remove-btn { background: #f44336; }
        .remove-btn:hover { background: #d32f2f; }
        .summary { background: #e9f7ef; padding: 15px; margin-top: 20px; }
        .summary div { margin: 5px 0; font-size: 16px; }
        .error { color: red; margin-top: 5px; }
    </style>
</head>
<body>
    <h2>Sales/Purchase Voucher</h2>
    <form method="POST" onsubmit="return validateForm()">
        <div class="form-group">
            <label>Voucher Type: <span class="error" id="type-error"></span></label>
            <select name="voucher_type" id="voucher_type" required>
                <option value="">Select Type</option>
                <option value="Sales">Sales Invoice</option>
                <option value="Purchase">Purchase Invoice</option>
            </select>
        </div>

        <div class="form-group">
            <label>Date:</label>
            <input type="date" name="voucher_date" required value="<?= htmlspecialchars($today_date) ?>">
        </div>

        <div class="form-group">
            <label>Party: <span class="error" id="party-error"></span></label>
            <select name="party_id" id="party_id" required>
                <option value="">Select Party</option>
                <?php foreach($parties as $party): ?>
                    <option value="<?= htmlspecialchars($party['party_id']) ?>">
                        <?= htmlspecialchars($party['party_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Invoice Number:</label>
            <input type="text" name="invoice_number" required>
        </div>

        <h3>Items <span class="error" id="items-error"></span></h3>
        <div id="items-container"></div>
        <button type="button" onclick="addItemRow()">Add Item</button>

        <div class="form-group">
            <label>Narration:</label>
            <textarea name="narration"></textarea>
        </div>

        <div class="form-group">
            <label>Round Off:</label>
            <input type="number" name="round_off" step="0.01" value="0" onchange="calculateTotals()">
        </div>

        <div class="summary">
            <h4>Summary</h4>
            <div>Total Amount: <span id="total-amount">0.00</span></div>
            <div>Total Tax: <span id="total-tax">0.00</span></div>
            <div>Grand Total: <span id="grand-total">0.00</span></div>
        </div>

        <button type="submit">Save Voucher</button>
    </form>

    <script>
        const inventoryItems = <?= json_encode($inventory_items) ?>;
        let stockErrors = [];
        
        function addItemRow() {
            const rowCount = document.querySelectorAll('.item-row').length;
            const row = document.createElement('div');
            row.className = 'item-row';
            row.dataset.index = rowCount;
            row.innerHTML = `
                <select name="items[${rowCount}][item_id]" required onchange="updateItemDetails(this)">
                    <option value="">Select Item</option>
                    ${inventoryItems.map(item => `
                        <option value="${item.item_id}" 
                            data-sales-rate="${item.sales_rate}" 
                            data-purchase-rate="${item.purchase_rate}"
                            data-tax="${item.tax_rate}"
                            data-stock="${item.current_stock}"
                            data-unit="${item.unit}">
                            ${item.item_name} (${item.item_code})
                        </option>
                    `).join('')}
                </select>
                <input type="number" name="items[${rowCount}][quantity]" min="0.01" step="0.01" placeholder="Qty" required onchange="calculateRow(this)">
                <input type="text" name="items[${rowCount}][unit]" placeholder="Unit" readonly>
                <input type="number" name="items[${rowCount}][rate]" min="0" step="0.01" placeholder="Rate" required onchange="calculateRow(this)">
                <input type="number" name="items[${rowCount}][tax_rate]" min="0" max="100" step="0.01" placeholder="Tax %" required onchange="calculateRow(this)">
                <input type="number" name="items[${rowCount}][tax_amount]" placeholder="Tax" readonly>
                <input type="number" name="items[${rowCount}][amount]" placeholder="Amount" readonly>
                <button type="button" class="remove-btn" onclick="removeItemRow(this)">Remove</button>
                <span class="stock-info" style="color:#666; font-style:italic;"></span>
                <span class="error" style="color:red;"></span>
            `;
            document.getElementById('items-container').appendChild(row);
        }

        function updateItemDetails(select) {
            const selectedOption = select.options[select.selectedIndex];
            const row = select.closest('.item-row');
            
            if (!selectedOption.value) {
                row.querySelector('.stock-info').textContent = '';
                return;
            }
            
            const voucherType = document.getElementById('voucher_type').value;
            const rateField = row.querySelector('input[name*="rate"]');
            
            // Set appropriate rate based on voucher type
            if (voucherType === 'Sales') {
                rateField.value = selectedOption.getAttribute('data-sales-rate') || '0';
            } else {
                rateField.value = selectedOption.getAttribute('data-purchase-rate') || '0';
            }
            
            // Set other fields
            row.querySelector('input[name*="tax_rate"]').value = selectedOption.getAttribute('data-tax') || '0';
            row.querySelector('input[name*="unit"]').value = selectedOption.getAttribute('data-unit') || '';
            
            // Show stock info
            const stock = parseFloat(selectedOption.getAttribute('data-stock')) || 0;
            const unit = selectedOption.getAttribute('data-unit') || '';
            row.querySelector('.stock-info').textContent = `Stock: ${stock} ${unit}`;
            
            calculateRow(select);
        }

        function calculateRow(input) {
            const row = input.closest('.item-row');
            const quantity = parseFloat(row.querySelector('input[name*="quantity"]').value) || 0;
            const rate = parseFloat(row.querySelector('input[name*="rate"]').value) || 0;
            const taxRate = parseFloat(row.querySelector('input[name*="tax_rate"]').value) || 0;
            
            const amount = quantity * rate;
            const taxAmount = amount * (taxRate / 100);
            
            row.querySelector('input[name*="amount"]').value = amount.toFixed(2);
            row.querySelector('input[name*="tax_amount"]').value = taxAmount.toFixed(2);
            
            // Validate stock for sales
            if (document.getElementById('voucher_type').value === 'Sales') {
                const stock = parseFloat(row.querySelector('.stock-info').textContent.replace('Stock: ', '').split(' ')[0]) || 0;
                const errorSpan = row.querySelector('.error');
                
                if (quantity > stock) {
                    errorSpan.textContent = `Exceeds stock (${stock})`;
                    stockErrors.push(row.dataset.index);
                } else {
                    errorSpan.textContent = '';
                    stockErrors = stockErrors.filter(i => i !== row.dataset.index);
                }
            }
            
            calculateTotals();
        }

        function removeItemRow(button) {
            const row = button.closest('.item-row');
            row.remove();
            stockErrors = stockErrors.filter(i => i !== row.dataset.index);
            calculateTotals();
            
            // Renumber remaining rows
            document.querySelectorAll('.item-row').forEach((row, index) => {
                row.dataset.index = index;
                [...row.elements].forEach(el => {
                    if (el.name) {
                        el.name = el.name.replace(/\[\d+\]/, `[${index}]`);
                    }
                });
            });
        }

        function calculateTotals() {
            let totalAmount = 0;
            let totalTax = 0;
            
            document.querySelectorAll('.item-row').forEach(row => {
                totalAmount += parseFloat(row.querySelector('input[name*="amount"]').value) || 0;
                totalTax += parseFloat(row.querySelector('input[name*="tax_amount"]').value) || 0;
            });
            
            const roundOff = parseFloat(document.querySelector('input[name="round_off"]').value) || 0;
            const grandTotal = totalAmount + totalTax + roundOff;
            
            document.getElementById('total-amount').textContent = totalAmount.toFixed(2);
            document.getElementById('total-tax').textContent = totalTax.toFixed(2);
            document.getElementById('grand-total').textContent = grandTotal.toFixed(2);
        }

        function validateForm() {
            let isValid = true;
            
            // Validate voucher type
            if (!document.getElementById('voucher_type').value) {
                document.getElementById('type-error').textContent = 'Please select voucher type';
                isValid = false;
            } else {
                document.getElementById('type-error').textContent = '';
            }
            
            // Validate party
            if (!document.getElementById('party_id').value) {
                document.getElementById('party-error').textContent = 'Please select party';
                isValid = false;
            } else {
                document.getElementById('party-error').textContent = '';
            }
            
            // Validate items
            const items = document.querySelectorAll('.item-row');
            if (items.length === 0) {
                document.getElementById('items-error').textContent = 'Please add at least one item';
                isValid = false;
            } else {
                document.getElementById('items-error').textContent = '';
                
                // Check for empty items
                items.forEach(row => {
                    const itemSelect = row.querySelector('select[name*="item_id"]');
                    if (!itemSelect.value) {
                        row.querySelector('.error').textContent = 'Please select an item';
                        isValid = false;
                    }
                });
            }
            
            // Check stock errors
            if (stockErrors.length > 0) {
                isValid = false;
            }
            
            return isValid;
        }

        // Initialize with one row
        addItemRow();
        
        // Update rates when voucher type changes
        document.getElementById('voucher_type').addEventListener('change', function() {
            document.querySelectorAll('.item-row').forEach(row => {
                const select = row.querySelector('select[name*="item_id"]');
                if (select.value) {
                    updateItemDetails(select);
                }
            });
        });
    </script>
</body>
</html>