<?php
include 'findb.php';

// Clear POST data if coming from a successful submission redirect
if (isset($_SESSION['form_success'])) {
    unset($_SESSION['form_success']);
    $_POST = [];
}

// Fetch the next available account code
$acc_code_query = $conn->query("SELECT LPAD(COALESCE(MAX(CAST(acc_code AS UNSIGNED)) + 1, 1), 5, '0') AS new_acc_code FROM ledgers");
$acc_code_result = $acc_code_query->fetch_assoc();
$acc_code = $acc_code_result['new_acc_code'] ?? '00001';

$errors = [];
$success = "";

// Form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $party_name = trim($_POST['party_name']);
    $contact_person = trim($_POST['contact_person']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $party_type = $_POST['party_type'] ?? '';
    $opening_balance = $_POST['opening_balance'] ?? 0;

    // === Mapping for party type ===
    $group_map = [
        'Sundry Debtor' => ['group_id' => 23, 'acc_type' => 'Asset', 'group_direct' => 'G', 'debit_credit' => 'Debit'],
        'Sundry Creditor' => ['group_id' => 24, 'acc_type' => 'Liability', 'group_direct' => 'G', 'debit_credit' => 'Credit']
    ];

    $group_id = $group_map[$party_type]['group_id'] ?? null;
    $acc_type = $group_map[$party_type]['acc_type'] ?? null;
    $group_direct = $group_map[$party_type]['group_direct'] ?? null;
    $debit_credit = $group_map[$party_type]['debit_credit'] ?? null;

    // === Determine book type based on name (optional enhancement) ===
    $book_type = 'Other';
    // if (strpos(strtolower($party_name), 'cash') !== false) $book_type = 'Cash';
    // elseif (strpos(strtolower($party_name), 'bank') !== false) $book_type = 'Bank';

    // === Validation ===
    if (empty($party_name)) {
        $errors['party_name'] = "Party name is required.";
    } else {
        $stmt = $conn->prepare("SELECT party_id FROM parties WHERE party_name = ?");
        $stmt->bind_param("s", $party_name);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors['party_name'] = "Party name already exists.";
        }
        $stmt->close();
    }

    if (!empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Invalid email format.";
        } else {
            $stmt = $conn->prepare("SELECT party_id FROM parties WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors['email'] = "Email already in use.";
            }
            $stmt->close();
        }
    }

    if (!empty($phone)) {
        if (!preg_match("/^[0-9]{6,15}$/", $phone)) {
            $errors['phone'] = "Invalid phone number.";
        } else {
            $stmt = $conn->prepare("SELECT party_id FROM parties WHERE phone = ?");
            $stmt->bind_param("s", $phone);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors['phone'] = "Phone number already exists.";
            }
            $stmt->close();
        }
    }

    if (empty($party_type) || !$group_id || !$acc_type || !$debit_credit) {
        $errors['party_type'] = "Invalid party type.";
    }

    if (!is_numeric($opening_balance) || $opening_balance < 0) {
        $errors['opening_balance'] = "Opening balance must be a non-negative number.";
    }

    // === Insert if no errors ===
    if (empty($errors)) {
        // 1. Insert into parties
        $stmt = $conn->prepare("INSERT INTO parties (party_name, contact_person, email, phone, address, party_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssssss", $party_name, $contact_person, $email, $phone, $address, $party_type);
        if ($stmt->execute()) {
            // 2. Insert into ledgers
            $ledger_name = $party_name;
            $stmt = $conn->prepare("
                INSERT INTO ledgers 
                (acc_code, ledger_name, group_id, group_direct, acc_type, book_type, 
                 opening_balance, current_balance, debit_credit, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("ssssssdss", $acc_code, $ledger_name, $group_id, $group_direct, $acc_type,
                            $book_type, $opening_balance, $opening_balance,
                            $debit_credit);

            if ($stmt->execute()) {
                $success = "Party and corresponding ledger created successfully!";
                $_POST = [];
                $_SESSION['form_success'] = true;
                header("Location: ".$_SERVER['PHP_SELF']);
                exit;
            } else {
                $errors['ledger'] = "Ledger creation failed: " . $stmt->error;
            }
        } else {
            $errors['party'] = "Party creation failed: " . $stmt->error;
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
    <link rel="stylesheet" href="styles/form_style.css">
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

document.addEventListener('DOMContentLoaded', function () {
    const partyTypeRadios = document.querySelectorAll('input[name="party_type"]');
    const dr_cr = document.getElementById('dr_cr');
    const groupNameInput = document.getElementById('group_name');
    const accTypeInput = document.getElementById('acc_type');

    partyTypeRadios.forEach(radio => {
        radio.addEventListener('change', function () {
            if (this.value === 'Sundry Debtor') {
                dr_cr.value = 'Debit';
                groupNameInput.value = 'Sundry Debtors';
                accTypeInput.value = 'Asset';
            } else if (this.value === 'Sundry Creditor') {
                dr_cr.value = 'Credit';
                groupNameInput.value = 'Sundry Creditors';
                accTypeInput.value = 'Liability';
            }
        });
    });
});

</script>


</head>
<body>
        <!-- Navbar -->
        <nav class="navbar">
        <div class="navbar-brand">
            <a href="index.html">
                <img class="logo" src="images/logo3.png" alt="Logo">
                <span>FinPack</span> 
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </li>
            <li>
                <a href="#">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </a>
            </li>
            <li>
                <a href="logout.php" style="color:rgb(235, 71, 53);">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </li>
        </ul>
    </nav>

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
            <label for="acc_code"><i class="fas fa-hashtag"></i> Account Code:</label>
            <input type="text" id="acc_code" name="acc_code" value="<?= $acc_code ?>" readonly>
        </div>

        <div class="form-group">
            <label for="party_name"><i class="fas fa-user-tag"></i> Party Name:</label>
            <input type="text" id="party_name" name="party_name" required>
        </div>

        <div class="form-group">
            <label for="contact_person"><i class="fas fa-user-circle"></i> Contact Person:</label>
            <input type="text" id="contact_person" name="contact_person">
        </div>

        <div class="form-group">
            <label for="email"><i class="fas fa-envelope"></i> Email:</label>
            <input type="email" id="email" name="email">
        </div>

        <div class="form-group">
            <label for="phone"><i class="fas fa-phone"></i> Phone:</label>
            <input type="text" id="phone" name="phone">
        </div>

        <div class="form-group">
            <label for="address"><i class="fas fa-map-marker-alt"></i> Address:</label>
            <textarea id="address" name="address" rows="3"></textarea>
        </div>

    </div>
    
    <!-- Right Column -->
    <div class="form-column" style="margin-left:10px;">
        
        <div class="form-group">
            <label for="party_type"><i class="fas fa-briefcase"></i> Party Type:</label>
            <div class="radio-group">
                <label class="radio-option">
                    <input type="radio" name="party_type" value="Sundry Debtor" required>
                    <span style="margin-top:0.2rem;">Customer (Sundry Debtor)</span>
                </label>
                <label class="radio-option">
                    <input type="radio" name="party_type" value="Sundry Creditor" required>
                    <span style="margin-top:0.1rem;">Supplier (Sundry Creditor)</span>
                </label>
            </div>
        </div>

        <div class="form-group">
            <label for="opening_balance"><i class="fas fa-balance-scale"></i> Opening Balance:</label>
            <input type="number" id="opening_balance" name="opening_balance" step="0.01" min="0" value="0" required>
        </div>

        <div class="form-group">
            <label for="group_name"><i class="fas fa-exchange"></i> Debit/Credit:</label>
            <input type="text" id="dr_cr" name="dr_cr" value="Debit / Credit" readonly>
        </div>

<div class="form-group">
    <label for="group_name"><i class="fas fa-layer-group"></i> Group:</label>
    <input type="text" id="group_name" name="group_name" value="Sundry Debtors / Creditors" readonly>
</div>

<div class="form-group">
    <label for="acc_type"><i class="fas fa-tag"></i> Account Type:</label>
    <input type="text" id="acc_type" name="acc_type" value="Asset / Liability" readonly>
</div>


        <div class="form-group" style="width: 100%; text-align: center;">
            <button type="submit" class="submit-button" style="width:100%;">
                <i class="fas fa-user-plus"></i> Add Customer / Supplier
            </button>
        </div>
    </div>
</form>
</div>
</body>
</html>

  