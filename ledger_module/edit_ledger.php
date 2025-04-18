
<?php
include '../database/findb.php';

// Check if ledger_id is provided
if (!isset($_GET['ledger_id'])) {
    header("Location: search_ledger.php"); // Redirect if no ID provided
    exit();
}

$ledger_id = $_GET['ledger_id'];

// Fetch ledger data
$ledger_query = $conn->prepare("SELECT * FROM ledgers WHERE ledger_id = ?");
$ledger_query->bind_param("i", $ledger_id);
$ledger_query->execute();
$ledger_result = $ledger_query->get_result();

if ($ledger_result->num_rows === 0) {
    header("Location: ledger_list.php"); // Redirect if ledger not found
    exit();
}

$ledger = $ledger_result->fetch_assoc();

// Fetch balance summary (same as create form)
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

// Form submission for editing
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $ledger_name = trim($_POST['ledger_name']);
    $group_id = $_POST['group_id'] ?? '';
    $opening_balance = $_POST['opening_balance'] ?? '';
    $debit_credit = $_POST['debit_credit'] ?? '';
    
    // Auto-detect book_type
    $book_type = 'Other';
    $lower_name = strtolower($ledger_name);
    if (strpos($lower_name, 'cash') !== false) {
        $book_type = 'Cash';
    } elseif (strpos($lower_name, 'bank') !== false) {
        $book_type = 'Bank';
    }

    // Validation
    if (empty($ledger_name)) {
        $errors['ledger_name'] = "Ledger name is required.";
    } elseif (!preg_match("/^[a-zA-Z\s\p{P}]+$/u", $ledger_name)) {
        $errors['ledger_name'] = "Ledger name must contain only letters, spaces, and punctuation.";
    } else {
        // Check for duplicate ledger name (excluding current ledger)
        $stmt = $conn->prepare("SELECT ledger_id FROM ledgers WHERE ledger_name = ? AND ledger_id != ?");
        $stmt->bind_param("si", $ledger_name, $ledger_id);
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

    // If no errors, update the ledger
    if (empty($errors)) {
        // Fetch group name for account type determination
        $group_query = $conn->prepare("SELECT group_name FROM groups WHERE group_id = ?");
        $group_query->bind_param("i", $group_id);
        $group_query->execute();
        $group_result = $group_query->get_result();
        $group_data = $group_result->fetch_assoc();
        $group_name = $group_data['group_name'] ?? "Unknown";

        // Group nature mapping (same as create form)
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

        $acc_type = $group_nature[$group_name] ?? "Other";
        $group_direct = in_array($acc_type, ["Asset", "Liability"]) ? "D" : "G";

        // Update ledger in DB
        $stmt = $conn->prepare("
            UPDATE ledgers SET
                ledger_name = ?,
                group_id = ?,
                acc_type = ?,
                book_type = ?,
                opening_balance = ?,
                current_balance = ?,
                debit_credit = ?,
                group_direct = ?
            WHERE ledger_id = ?
        ");
        $stmt->bind_param("ssssdsssi", $ledger_name, $group_id, $acc_type,
                         $book_type, $opening_balance, $opening_balance,
                         $debit_credit, $group_direct, $ledger_id);

        if ($stmt->execute()) {
            $success = "Ledger updated successfully!";
            // Refresh ledger data
            $ledger_query->execute();
            $ledger_result = $ledger_query->get_result();
            $ledger = $ledger_result->fetch_assoc();
        } else {
            $errors['db'] = "Error updating ledger: " . $stmt->error;
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
    <title>Edit Ledger</title>
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

    <div class="form-container main-content">
        <div class="form-header">
            <h2><i class="fas fa-edit"></i> Edit Ledger</h2>
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
                    <input type="text" id="ledger_name" name="ledger_name" value="<?= htmlspecialchars($ledger['ledger_name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-hashtag"></i> Account Code:</label>
                    <input type="text" value="<?= htmlspecialchars($ledger['acc_code']) ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label for="group_id"><i class="fas fa-layer-group"></i> Group:</label>
                    <select id="group_id" name="group_id" required onchange="updateAccountType()">
                        <option value="">Select Group</option>
                        <?php
                        $groups = $conn->query("SELECT * FROM groups");
                        while ($row = $groups->fetch_assoc()): 
                            // Determine account type based on group name (same as create form)
                            $group_nature = [
                                "Capital Account" => "Liability",
                                // ... (same as your create form)
                            ];
                            $acc_type = $group_nature[$row['group_name']] ?? "Other";
                        ?>
                            <option value="<?= $row['group_id'] ?>" 
                                data-type="<?= $acc_type ?>"
                                <?= ($row['group_id'] == $ledger['group_id']) ? 'selected' : '' ?>>
                                <?= $row['group_name'] ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="account_type"><i class="fas fa-tag"></i> Account Type:</label>
                    <input type="text" id="account_type" name="account_type" value="<?= htmlspecialchars($ledger['acc_type']) ?>" readonly>
                </div>
            </div>
            
            <!-- Right Column -->
            <div class="form-column" style="margin-left:10px;">
                <div class="form-group">
                    <label for="opening_balance"><i class="fas fa-balance-scale"></i> Opening Balance:</label>
                    <input type="number" id="opening_balance" name="opening_balance" step="0.01" min="0" 
                           value="<?= htmlspecialchars($ledger['opening_balance']) ?>" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-exchange-alt"></i> Debit / Credit:</label>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="debit_credit" value="Debit" 
                                <?= ($ledger['debit_credit'] == 'Debit') ? 'checked' : '' ?> required>
                            <span style="margin-top:0.2rem;">Debit</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="debit_credit" value="Credit" 
                                <?= ($ledger['debit_credit'] == 'Credit') ? 'checked' : '' ?> required>
                            <span style="margin-top:0.2rem;">Credit</span>
                        </label>
                    </div>
                </div>
            
                <!-- Balance Summary (same as create form) -->
                <div class="balance-summary">
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
                    <button type="submit" class="submit-button" style="width:100%;">
                        <i class="fas fa-save"></i> Update Ledger
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
    // Same JavaScript as your create form
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
    </script>
</body>
</html>