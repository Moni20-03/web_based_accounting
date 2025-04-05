<?php
session_start();
include 'db_connection.php'; // Ensure correct DB connection

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $company_name = trim($_POST['company_name'] ?? '');
        $company_email = trim($_POST['company_email'] ?? '');
        $company_phone = trim($_POST['company_phone'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');
        $state = trim($_POST['state'] ?? '');

        // Validation
        if (empty($company_name) || empty($company_email) || empty($company_phone) || empty($company_address) || empty($state)) {
            throw new Exception("All fields are required.");
        }

        // Check for existing company name
        $stmt = $conn->prepare("SELECT company_id FROM companies WHERE company_name = ?");
        $stmt->bind_param("s", $company_name);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            throw new Exception("Company name already exists.");
        }
        $stmt->close();

        // Validate email format
        if (!filter_var($company_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }

        // Validate phone number format
        if (!preg_match('/^\d{10}$/', $company_phone)) {
            throw new Exception("Phone number must be 10 digits long.");
        }

        // Insert company into the global companies table
        $stmt = $conn->prepare("INSERT INTO companies (company_name, company_email, company_phone, company_address, state) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $company_name, $company_email, $company_phone, $company_address, $state);

        
        if (!$stmt->execute()) {
            throw new Exception("Error inserting company data: " . $stmt->error);
        }
        
        $company_id = $stmt->insert_id;
        $stmt->close();

        // Generate unique database name
        $company_db_name = "finpack_company_" . $company_id;

        $_SESSION['company_db'] = $company_db_name;
        
        // Create a new database for the company
        if ($conn->query("CREATE DATABASE `$company_db_name`") !== TRUE) {
            throw new Exception("Error creating company database: " . $conn->error);
        }
        
        $_SESSION['company_name'] = $company_name; 

        // Connect to the new database
        $company_conn = new mysqli($db_host, $db_user, $db_pass, $company_db_name);
        if ($company_conn->connect_error) {
            throw new Exception("Error connecting to new company database: " . $company_conn->connect_error);
        }

        // Create necessary tables in the new company database (your existing SQL here)
        $create_tables_query = "
        -- Users Table (remains unchanged)
    CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    dob CHAR(8) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('Company Head', 'Accountant', 'Viewer') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Groups Table (removed company_id)
CREATE TABLE groups (
    group_id INT AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(255) NOT NULL,
    parent_group_id INT NULL,
    nature ENUM('Asset', 'Liability', 'Expense', 'Income') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_group_id) REFERENCES groups(group_id) ON DELETE SET NULL
);

-- Ledgers Table (removed company_id)
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

-- Vouchers Table (removed company_id, added more voucher types)
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

-- Transactions Table (removed company_id, added updated_at)
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

-- Parties Table (For Sundry Debtors and Creditors)
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

-- Audit Logs Table (Tracks changes to financial records)
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
        "; // Your table creation SQL here
        
        if (!$company_conn->multi_query($create_tables_query)) {
            throw new Exception("Error creating tables in new company database: " . $company_conn->error);
        }

        $_SESSION['company_db'] = $company_db_name;
        echo json_encode(["status" => "success", "redirect" => "company_head_register.php"]);
        exit();

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Registration</title>
    <link rel="stylesheet" href="styles/reg_styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
</head>
<body>

    <div class="form-container">
        <h2>Company Registration</h2>
        <form id="companyRegisterForm">
            <div class="form-group">
                <label><i class="fa-solid fa-building"></i> Company Name:</label>
                <input type="text" name="company_name" required>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-envelope"></i> Company Email:</label>
                <input type="email" name="company_email" required>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-phone"></i> Company Phone:</label>
                <input type="text" name="company_phone" required>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-location-dot"></i> Company Address:</label>
                <textarea name="company_address" required></textarea>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-map-marker-alt"></i> State:</label>
                <select name="state" required>
                    <option value=""></option>
                    <option value="Tamil Nadu">Tamil Nadu</option>
                    <option value="Karnataka">Karnataka</option>
                </select>
            </div>
            <button type="submit">Next <i class="fa-solid fa-arrow-right"></i></button>
        </form>
    </div>

    <script>
        document.getElementById('companyRegisterForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('company_register.php', {
                method: "POST",
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.status === "success") {
                    window.location.href = data.redirect;
                } else {
                    alert("Error: " + (data.message || "Registration failed"));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert("An error occurred. Please check console for details.");
            });
        });
    </script>

</body>
</html>

