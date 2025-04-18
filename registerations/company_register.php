<?php
// Start secure session
session_start();

include '../database/db_connection.php';

// Initialize variables
$errors = [];
$formData = [
    'company_name' => '',
    'company_email' => '',
    'company_phone' => '',
    'company_address' => '',
    'state' => ''
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        
        // Sanitize inputs
        $formData['company_name'] = trim($_POST['company_name'] ?? '');
        $formData['company_email'] = trim($_POST['company_email'] ?? '');
        $formData['company_phone'] = trim($_POST['company_phone'] ?? '');
        $formData['company_address'] = trim($_POST['company_address'] ?? '');
        $formData['state'] = trim($_POST['state'] ?? '');

        // Validate inputs
        if (empty($formData['company_name'])) {
            $errors['company_name'] = "Company name is required";
        } elseif (strlen($formData['company_name']) > 255) {
            $errors['company_name'] = "Company name is too long";
        }

        if (empty($formData['company_email'])) {
            $errors['company_email'] = "Company email is required";
        } elseif (!filter_var($formData['company_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['company_email'] = "Invalid email format";
        }

        if (empty($formData['company_phone'])) {
            $errors['company_phone'] = "Phone number is required";
        } elseif (!preg_match('/^\d{10}$/', $formData['company_phone'])) {
            $errors['company_phone'] = "Phone must be 10 digits";
        }

        if (empty($formData['company_address'])) {
            $errors['company_address'] = "Address is required";
        }

        if (empty($formData['state'])) {
            $errors['state'] = "State is required";
        }

        // Only proceed if no validation errors
        if (empty($errors)) {
            // Check for existing company name (case insensitive)
            $stmt = $conn->prepare("SELECT company_id FROM companies WHERE LOWER(company_name) = LOWER(?)");
            $stmt->bind_param("s", $formData['company_name']);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $errors['company_name'] = "Company name already exists";
            }
            $stmt->close();

            // If still no errors, proceed with registration
            if (empty($errors)) {
                // Insert company into the global companies table
                $stmt = $conn->prepare("INSERT INTO companies (company_name, company_email, company_phone, company_address, state) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $formData['company_name'], $formData['company_email'], $formData['company_phone'], $formData['company_address'], $formData['state']);

                if (!$stmt->execute()) {
                    throw new Exception("Database error: Could not register company");
                }
                
                $company_id = $stmt->insert_id;
                $stmt->close();

                // Generate unique database name
                $company_db_name = "finpack_company_" . $company_id;

                // Create a new database for the company
                if ($conn->query("CREATE DATABASE `$company_db_name`") !== TRUE) {
                    throw new Exception("Could not create company database");
                }
                
                // Store in session
                $_SESSION['company_db'] = $company_db_name;
                $_SESSION['company_name'] = $formData['company_name'];

                // Connect to the new database
                $company_conn = new mysqli($db_host, $db_user, $db_pass, $company_db_name);
                if ($company_conn->connect_error) {
                    throw new Exception("Could not connect to company database");
                }

                // Create necessary tables in the new company database
                $create_tables_query = "
                CREATE TABLE users (
                    user_id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    dob CHAR(8) NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    role ENUM('Company Head', 'Accountant', 'Viewer') NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE groups (
                    group_id INT AUTO_INCREMENT PRIMARY KEY,
                    group_name VARCHAR(255) NOT NULL,
                    parent_group_id INT NULL,
                    nature ENUM('Asset', 'Liability', 'Expense', 'Income') NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (parent_group_id) REFERENCES groups(group_id) ON DELETE SET NULL
                );

                CREATE TABLE ledgers (
                    ledger_id INT AUTO_INCREMENT PRIMARY KEY,
                    acc_code VARCHAR(10) NOT NULL UNIQUE,
                    ledger_name VARCHAR(255) NOT NULL,
                    group_id INT NOT NULL,
                    group_direct ENUM('D', 'G') NOT NULL, 
                    acc_type ENUM('Asset', 'Liability', 'Expense', 'Income') NOT NULL,
                    book_type ENUM('Cash', 'Bank', 'Other') NOT NULL,
                    opening_balance DECIMAL(15,2) DEFAULT 0,
                    current_balance DECIMAL(15,2) DEFAULT 0,
                    debit_credit ENUM('Debit', 'Credit') NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (group_id) REFERENCES groups(group_id) ON DELETE CASCADE
                );

                CREATE TABLE vouchers (
                    voucher_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    voucher_number VARCHAR(50) NOT NULL UNIQUE,
                    reference_number VARCHAR(50) NULL,
                    voucher_type ENUM('Payment', 'Receipt', 'Sales', 'Purchase', 'Journal', 'Contra') NOT NULL,
                    voucher_date DATE NOT NULL DEFAULT CURRENT_DATE,
                    total_amount DECIMAL(15,2) NOT NULL,
                    narration TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
                );

                CREATE TABLE transactions (
                    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    voucher_id INT NOT NULL,
                    ledger_id INT NOT NULL,
                    acc_code VARCHAR(10) NOT NULL,
                    transaction_type ENUM('Debit', 'Credit') NOT NULL,
                    amount DECIMAL(15,2) NOT NULL,
                    closing_balance DECIMAL(15,2) DEFAULT 0,
                    mode_of_payment VARCHAR(50) NULL,
                    opposite_ledger INT NULL,
                    narration TEXT NULL,
                    transaction_date DATE NOT NULL DEFAULT CURRENT_DATE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                    FOREIGN KEY (voucher_id) REFERENCES vouchers(voucher_id) ON DELETE CASCADE,
                    FOREIGN KEY (ledger_id) REFERENCES ledgers(ledger_id) ON DELETE CASCADE
                );

                CREATE TABLE parties (
                    party_id INT AUTO_INCREMENT PRIMARY KEY,
                    party_name VARCHAR(255) NOT NULL,
                    contact_person VARCHAR(255) NULL,
                    email VARCHAR(255) NULL,
                    phone VARCHAR(20) NULL,
                    address TEXT NULL,
                    party_type ENUM('Sundry Debtor', 'Sundry Creditor') NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE audit_logs (
                    log_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    table_name VARCHAR(50) NOT NULL,
                    record_id INT NOT NULL,
                    action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
                    old_value TEXT NULL,
                    new_value TEXT NULL,
                    change_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
                );
                ";

                if (!$company_conn->multi_query($create_tables_query)) {
                    throw new Exception("Could not create company database tables");
                }

                // Clear all output before redirect
                ob_end_clean();
                header("Location: company_head_register.php");
                exit();
            }
        }

    } catch (Exception $e) {
        $errors['system'] = "An error occurred: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Prevent caching -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Company Registration</title>
    <link rel="stylesheet" href="../styles/reg_styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <style>
        .error-message {
            color: #e74c3c;
            font-size: 0.9rem;
            margin-top: 5px;
            display: block;
        }
        .form-group.has-error input,
        .form-group.has-error textarea,
        .form-group.has-error select {
            border-color: #e74c3c;
        }
        .system-error {
            background-color: #fde8e8;
            color: #e74c3c;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Company Registration</h2>
        
        <?php if (!empty($errors['system'])): ?>
            <div class="system-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['system']); ?>
            </div>
        <?php endif; ?>
        
        <form id="companyRegisterForm" method="POST" autocomplete="off">    
            <div class="form-group <?php echo !empty($errors['company_name']) ? 'has-error' : ''; ?>">
                <label><i class="fa-solid fa-building"></i> Company Name:</label>
                <input type="text" name="company_name" value="<?php echo htmlspecialchars($formData['company_name']); ?>" required>
                <?php if (!empty($errors['company_name'])): ?>
                    <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['company_name']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group <?php echo !empty($errors['company_email']) ? 'has-error' : ''; ?>">
                <label><i class="fa-solid fa-envelope"></i> Company Email:</label>
                <input type="email" name="company_email" value="<?php echo htmlspecialchars($formData['company_email']); ?>" required>
                <?php if (!empty($errors['company_email'])): ?>
                    <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['company_email']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group <?php echo !empty($errors['company_phone']) ? 'has-error' : ''; ?>">
                <label><i class="fa-solid fa-phone"></i> Company Phone:</label>
                <input type="text" name="company_phone" value="<?php echo htmlspecialchars($formData['company_phone']); ?>" required maxlength="10">
                <?php if (!empty($errors['company_phone'])): ?>
                    <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['company_phone']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group <?php echo !empty($errors['company_address']) ? 'has-error' : ''; ?>">
                <label><i class="fa-solid fa-location-dot"></i> Company Address:</label>
                <textarea name="company_address" required><?php echo htmlspecialchars($formData['company_address']); ?></textarea>
                <?php if (!empty($errors['company_address'])): ?>
                    <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['company_address']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group <?php echo !empty($errors['state']) ? 'has-error' : ''; ?>">
                <label><i class="fa-solid fa-map-marker-alt"></i> State:</label>
                <select name="state" required>
                    <option value=""></option>
                    <option value="Tamil Nadu" <?php echo $formData['state'] === 'Tamil Nadu' ? 'selected' : ''; ?>>Tamil Nadu</option>
                    <option value="Karnataka" <?php echo $formData['state'] === 'Karnataka' ? 'selected' : ''; ?>>Karnataka</option>
                </select>
                <?php if (!empty($errors['state'])): ?>
                    <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['state']); ?></span>
                <?php endif; ?>
            </div>
            
            <button type="submit">Next <i class="fa-solid fa-arrow-right"></i></button>
        </form>
    </div>

    <script>
        // Clear form cache
        document.addEventListener('DOMContentLoaded', function() {
            // Clear form when page is loaded (in case of back navigation)
            if (window.performance && window.performance.navigation.type === window.performance.navigation.TYPE_BACK_FORWARD) {
                document.getElementById('companyRegisterForm').reset();
            }
            
            // Phone number validation (client-side)
            document.querySelector('input[name="company_phone"]').addEventListener('input', function(e) {
                this.value = this.value.replace(/\D/g, '');
            });
        });
    </script>
</body>
</html>