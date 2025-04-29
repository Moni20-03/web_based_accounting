<?php
include '../database/../database/findb.php';

// Clear POST data if coming from a successful submission redirect
if (isset($_SESSION['form_success'])) {
    unset($_SESSION['form_success']);
    $_POST = [];
}

// Fetch the next available account code
$acc_code_query = $conn->query("SELECT LPAD(COALESCE(MAX(CAST(acc_code AS UNSIGNED)) + 1, 1), 5, '0') AS new_acc_code FROM ledgers");
$acc_code_result = $acc_code_query->fetch_assoc();
$acc_code = $acc_code_result['new_acc_code'] ?? '00001';

// Fetch total Debit and Credit balance
$balance_query = $conn->query("
    SELECT 
        SUM(CASE WHEN debit_credit = 'Debit' THEN current_balance ELSE 0 END) AS total_debit,
        SUM(CASE WHEN debit_credit = 'Credit' THEN current_balance ELSE 0 END) AS total_credit
    FROM ledgers 
");
$balance_result = $balance_query->fetch_assoc();
$total_debit = $balance_result['total_debit'] ?? 0.00;
$total_credit = $balance_result['total_credit'] ?? 0.00;
$difference = $total_debit - $total_credit;

$errors = [];
$success = "";

// Form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $ledger_name = trim($_POST['ledger_name']);
    $group_id = $_POST['group_id'] ?? '';
    $opening_balance = $_POST['opening_balance'] ?? '';
    $debit_credit = $_POST['debit_credit'] ?? '';


    // === Auto-detect book_type from ledger_name ===
    $book_type = 'Other'; // Default
    $lower_name = strtolower($ledger_name);
    if (strpos($lower_name, 'cash') !== false) {
        $book_type = 'Cash';
    } elseif (strpos($lower_name, 'bank') !== false) {
        $book_type = 'Bank';
    }


    // === Validation ===
    if (empty($ledger_name)) {
        $errors['ledger_name'] = "Ledger name is required.";
    } elseif (!preg_match("/^[a-zA-Z\s\p{P}]+$/u", $ledger_name)) {
        $errors['ledger_name'] = "Ledger name must contain only letters, spaces, and punctuation.";
    } else {
        // Check for duplicate ledger name
        $stmt = $conn->prepare("SELECT ledger_id FROM ledgers WHERE ledger_name = ?");
        $stmt->bind_param("s", $ledger_name);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors['ledger_name'] = "Ledger name already exists.";
        }
        $stmt->close();
    }

    if (empty($group_id)) {
        $errors['group_id'] = "Please select a group.";
    }

    if (!is_numeric($opening_balance) || $opening_balance < 0) {
        $errors['opening_balance'] = "Opening balance must be a valid non-negative number.";
    }

    if (empty($debit_credit)) {
        $errors['debit_credit'] = "Please select Debit or Credit.";
    }

    if (empty($book_type)) {
        $errors['book_type'] = "Please select a Book Type.";
    }

    // === If No Errors, Proceed to Insert ===
    if (empty($errors)) {
        // Fetch group name
        $group_query = $conn->prepare("SELECT group_name FROM groups WHERE group_id = ?");
        $group_query->bind_param("i", $group_id);
        $group_query->execute();
        $group_result = $group_query->get_result();
        $group_data = $group_result->fetch_assoc();
        $group_name = $group_data['group_name'] ?? "Unknown";

        // Group nature mapping
        $group_nature = [
            "Capital Account" => "Liability",
            "Reserves & Surplus" => "Liability",
            "Current Liabilities" => "Liability",
            "Loans (Liability)" => "Liability",
            "Bank Accounts" => "Asset",
            "Cash-in-Hand" => "Asset",
            "Current Assets" => "Asset",
            "Fixed Assets" => "Asset",
            "Investments" => "Asset",
            "Branch/Divisions" => "Asset",
            "Direct Expenses" => "Expense",
            "Indirect Expenses" => "Expense",
            "Purchase Accounts" => "Expense",
            "Sales Accounts" => "Income",
            "Direct Incomes" => "Income",
            "Indirect Incomes" => "Income",
            "Duties & Taxes" => "Liability",
            "Provisions" => "Liability",
            "Secured Loans" => "Liability",
            "Unsecured Loans" => "Liability",
            "Stock-in-Hand" => "Asset",
            "Deposits (Asset)" => "Asset",
            "Sundry Debtors" => "Asset",
            "Sundry Creditors" => "Liability",
            "Loans & Advances (Asset)" => "Asset"
        ];

        // $selected_group_id = $_POST['group_id'] ?? '';

        $acc_type = $group_nature[$group_name] ?? "Other";
        $group_direct = in_array($acc_type, ["Asset", "Liability"]) ? "D" : "G";

        // Insert into DB
        $stmt = $conn->prepare("
            INSERT INTO ledgers 
            (acc_code, ledger_name, group_id, acc_type, book_type, 
             opening_balance, current_balance, debit_credit, group_direct, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("sssssdsss", $acc_code, $ledger_name, $group_id, $acc_type,
                         $book_type, $opening_balance, $opening_balance,
                         $debit_credit, $group_direct);

        if ($stmt->execute()) {
            $success = "Ledger created successfully!";
            
            // Clear form values after success
            $_POST = [];
            $_SESSION['form_success'] = true;
            // Redirect to same page to prevent form resubmission
            header("Location: ".$_SERVER['PHP_SELF']);
        } else {
            $errors['db'] = "Error creating ledger: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Ledger</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../styles/form_style.css">
    <style>
.form-container {
    max-width: 800px; /* Increased from 500px to accommodate two columns */
}

/* Two Column Layout */
.ledger-form {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
}

.form-column {
    flex: 1;
    min-width: 300px;
}

.form-group label
{
    color: var(--primary-dark);
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.1rem;
    font-weight: 550;
}

.form-group input 
{
    padding: 12px 15px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 1rem;
    transition: all 0.3s ease;
    font-family: 'Poppins', sans-serif;
}

/* Radio Button Styles */
.radio-group {
    display: flex;
    gap: 1rem;
    margin-top: 0.5rem;
}

.radio-group input
{
    margin-left:5px;
}

.radio-option {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    cursor: pointer;
    position: relative;
    padding-left: 2rem;
    margin-bottom: 0.5rem;
    user-select: none;
    transition: all 0.2s ease;
}

.radio-option input[type="radio"] {
    position: absolute;
    top: 0;
    left: 0;
    height: 1.25rem;
    width: 1.25rem;
    background-color: var(--white);
    border: 2px solid var(--primary-light);
    border-radius: 50%;
    transition: all 0.2s ease;
}

.radio-option input[type="radio"]:checked {
    border-color: var(--accent-green);
    background-color: var(--accent-green);
}

.radio-option input[type="radio"]:checked::after {
    content: "";
    position: absolute;
    display: none;
    top: 50%;
    left: 50%;
    width: 0.625rem;
    height: 0.625rem;
    border-radius: 50%;
    background: var(--white);
    transform: translate(-50%, -50%);
}

/* Balance Summary - Integrated into form */
.balance-summary {
    background-color: rgba(52, 152, 219, 0.05);
    padding: 1rem;
    border-radius: 8px;
    margin: 1.5rem 0;
    border-left: 3px solid var(--primary-light);
    width: 95%;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-top: 0.5rem;
}

.summary-item {
    text-align: center;
    padding: 0.5rem;
    background-color: rgba(255, 255, 255, 0.7);
    border-radius: 5px;
}

.summary-item div:first-child {
    font-size: 0.9rem;
    color: var( --text-dark);
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.summary-value {
    font-weight: 600;
    font-size: 1.1rem;
}

/* Submit Button Centering */
.form-actions {
    width: 100%;
    text-align: center;
    margin-top: 1rem;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .form-column {
        min-width: 100%;
    }
    
    .summary-grid {
        grid-template-columns: 1fr;
    }
    
    .radio-group {
        flex-direction: column;
        gap: 0.5rem;
    }
}

/* View Field Styling */
.view-field {
    padding: 0.75rem 1rem;
    background-color: rgba(0, 0, 0, 0.03);
    border-radius: 5px;
    border: 1px solid rgba(0, 0, 0, 0.08);
    font-size: 1rem;
    color: var(--text-dark);
}
</style>


<script>
   function updateAccountType() {
    const groupDropdown = document.getElementById("group_id");
    const selectedOption = groupDropdown.options[groupDropdown.selectedIndex];
    const accountType = selectedOption.getAttribute("data-type") || "Other";
    document.getElementById("account_type").value = accountType;
}

// Call on page load to update based on pre-selected value
window.addEventListener('DOMContentLoaded', function() {
    updateAccountType();
});
    document.getElementById('ledgerForm').addEventListener('submit', function(event) {
    let ledgerName = document.querySelector('input[name="ledger_name"]').value.trim();
    let openingBalance = document.querySelector('input[name="opening_balance"]').value.trim();

    if (ledgerName === "" || openingBalance === "") {
        alert("Please fill in all fields.");
        event.preventDefault();
    }

    if (isNaN(openingBalance) || parseFloat(openingBalance) < 0) {
        alert("Opening balance must be a valid positive number.");
        event.preventDefault();
    }
    });

    // Clear form on page load if coming from a successful submission
window.addEventListener('DOMContentLoaded', function() {
    updateAccountType();
    
    // If the page was reloaded (not a back navigation), clear the form
    if (performance.navigation.type === 1) {
        document.getElementById('ledgerForm').reset();
        document.getElementById('account_type').value = '';
    }
});

// Reset form after successful submission
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>



</head>
<body>
    <?php 
        include('../navbar.php');
   ?>
    <div class="form-container main-content">
        <div class="form-header">
            <h2><i class="fas fa-book"></i> Create New Ledger</h2>
        </div>
        
       
            <?php if (!empty($success)): ?>
            <div class="success-message"><i class='fas fa-check-circle'></i><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="error-message">
                        <?php foreach ($errors as $error): ?>
                            <i class='fas fa-exclamation-circle'></i><?= htmlspecialchars($error) ?>
                        <?php endforeach; ?>
                </div>
            <?php endif; ?>

        
        <form method="POST" class="ledger-form" autocomplete="off">
            <!-- Left Column -->
            <div class="form-column">
                <div class="form-group">
                    <label for="ledger_name"><i class="fas fa-signature"></i> Ledger Name:</label>
                    <input type="text" id="ledger_name" name="ledger_name" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-hashtag"></i> Account Code:</label>
                    <input type="text" value="<?= $acc_code ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label for="group_id"><i class="fas fa-layer-group"></i> Group:</label>
                    <select id="group_id" name="group_id" required onchange="updateAccountType()">
                        <option value="">Select Group</option>
                        <?php
        $groups = $conn->query("SELECT * FROM groups");
        while ($row = $groups->fetch_assoc()): 
            // Determine account type based on group name
            $group_nature = [
                "Capital Account" => "Liability",
                "Reserves & Surplus" => "Liability",
                "Current Liabilities" => "Liability",
                "Loans (Liability)" => "Liability",
                "Bank Accounts" => "Asset",
                "Cash-in-Hand" => "Asset",
                "Current Assets" => "Asset",
                "Fixed Assets" => "Asset",
                "Investments" => "Asset",
                "Branch/Divisions" => "Asset",
                "Direct Expenses" => "Expense",
                "Indirect Expenses" => "Expense",
                "Purchase Accounts" => "Expense",
                "Sales Accounts" => "Income",
                "Direct Incomes" => "Income",
                "Indirect Incomes" => "Income",
                "Duties & Taxes" => "Liability",
                "Provisions" => "Liability",
                "Secured Loans" => "Liability",
                "Unsecured Loans" => "Liability",
                "Stock-in-Hand" => "Asset",
                "Deposits (Asset)" => "Asset",
                "Sundry Debtors" => "Asset",
                "Sundry Creditors" => "Liability",
                "Loans & Advances (Asset)" => "Asset"
            ];
            $acc_type = $group_nature[$row['group_name']] ?? "Other";
        ?>
            <option value="<?= $row['group_id'] ?>" data-type="<?= $acc_type ?>"><?= $row['group_name'] ?></option>
        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="account_type"><i class="fas fa-tag"></i> Account Type:</label>
                    <input type="text" id="account_type" name="account_type" readonly>
                </div>

            </div>
            
            <!-- Right Column -->
            <div class="form-column" style="margin-left:10px;">
                
                <div class="form-group">
                    <label for="opening_balance"><i class="fas fa-balance-scale"></i> Opening Balance:</label>
                    <input type="number" id="opening_balance" name="opening_balance" step="0.01" min="0" value="0" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-exchange-alt"></i> Debit / Credit:</label>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="debit_credit" value="Debit" required> <span style="margin-top:0.2rem;">Debit</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="debit_credit" value="Credit" required> <span style="margin-top:0.2rem;">Credit</span>
                        </label>
                    </div>
                </div>
            
            
            <!-- Balance Summary -->
             <div class = "balance-summary">
                 <h3><i class="fas fa-calculator"></i> Balance Summary</h3>
                 <div class="summary-grid">
                     <div class="summary-item">
                         <div>Total Debit</div>
                         <div class="summary-value"><?= number_format($total_debit, 2) ?></div>
                        </div>
                        <div class="summary-item">
                            <div>Total Credit</div>
                            <div class="summary-value"><?= number_format($total_credit, 2) ?></div>
                        </div>
                        <div class="summary-item">
                            <div>Difference</div>
                            <div class="summary-value" style="color: <?= $difference == 0 ? 'green' : ($difference > 0 ? 'blue' : 'red') ?>">
                                <?= number_format(abs($difference), 2) ?>
                                <?php if($difference != 0): ?>
                                    <i class="fas fa-exclamation-triangle" style="margin-top:0.5rem;"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                    </div>
                </div>
                
                <div class="form-group" style="width: 100%; text-align: center;">
                    <button type="submit" class="submit-button" style = "width:100%;">
                        <i class="fas fa-save"></i> Create Ledger
                    </button>
                </div>
            </div>
        </form>
    </div>
</body>
</html>

  