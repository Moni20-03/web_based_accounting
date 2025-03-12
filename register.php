<?php
include 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_name = $_POST['company_name'];
    $company_email = $_POST['company_email'];
    $company_phone = $_POST['company_phone'];
    $company_address = $_POST['company_address'];
    $state = $_POST['state'];
    $head_username = $_POST['head_username'];
    $head_email = $_POST['head_email'];
    $head_dob = trim($_POST['head_dob']); // Store as plain text
    $secret_code = bin2hex(random_bytes(4)); // Generates an 8-character secret code

    // Validate DOB format
    if (!preg_match('/^\d{8}$/', $head_dob)) {
        echo "<script>alert('Invalid Date of Birth format! Please enter in DDMMYYYY format.');</script>";
        exit();
    }

    // Start transaction
    $conn->begin_transaction();
    try {
        // Insert Company Details
        $stmt = $conn->prepare("INSERT INTO companies (company_name, company_email, company_phone, company_address, state, secret_code) 
                                VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $company_name, $company_email, $company_phone, $company_address, $state, $secret_code);
        $stmt->execute();
        $company_id = $stmt->insert_id;
        $stmt->close();

        // Insert Company Head User with DOB instead of hashed password
        $stmt = $conn->prepare("INSERT INTO users (company_id, username, email, dob, role) 
                                VALUES (?, ?, ?, ?, 'Company Head')");
        $stmt->bind_param("isss", $company_id, $head_username, $head_email, $head_dob);
        $stmt->execute();
        $stmt->close();

        // Commit transaction
        $conn->commit();

        // Success message and redirect
        echo "<script>
                alert('Company registered successfully! Secret Code: $secret_code. Redirecting to role creation...');
                window.location.href='create_roles.php?company_id=$company_id&secret_code=$secret_code';
              </script>";
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Error: " . $e->getMessage() . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Company</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h2>Company Registration</h2>
    <form method="POST">
        <label>Company Name:</label>
        <input type="text" name="company_name" required>

        <label>Company Email:</label>
        <input type="email" name="company_email" required>

        <label>Company Phone:</label>
        <input type="text" name="company_phone" required>

        <label>Company Address:</label>
        <textarea name="company_address" required></textarea>

        <label>State:</label>
        <select name="state" required>
            <option value="">Select State</option>
            <option value="Tamil Nadu">Tamil Nadu</option>
            <option value="Karnataka">Karnataka</option>
            <!-- Add other states here -->
        </select>

        <h3>Company Head Details</h3>
        <label>Username:</label>
        <input type="text" name="head_username" required>

        <label>Email:</label>
        <input type="email" name="head_email" required>

        <label>Date of Birth (DDMMYYYY):</label>
        <input type="text" name="head_dob" required placeholder="DDMMYYYY" maxlength="8">

        <button type="submit">Register Company</button>
    </form>
</body>
</html>
