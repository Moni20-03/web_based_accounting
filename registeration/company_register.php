<?php
session_start();
include '../db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Store company details in session
    $_SESSION['company_name'] = $_POST['company_name'];
    $_SESSION['company_email'] = $_POST['company_email'];
    $_SESSION['company_phone'] = $_POST['company_phone'];
    $_SESSION['company_address'] = $_POST['company_address'];
    $_SESSION['state'] = $_POST['state'];

    // Redirect to company head details form
    header("Location: company_head_register.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Registration</title>
    <link rel="stylesheet" href="reg_styles.css">
</head>
<body>
    <div class="form-container">
        <h2>Company Registration</h2>
        <form method="POST">
            <div class="form-group">
                <label>Company Name:</label>
                <input type="text" name="company_name" required>
            </div>
            <div class="form-group">
                <label>Company Email:</label>
                <input type="email" name="company_email" required>
            </div>
            <div class="form-group">
                <label>Company Phone:</label>
                <input type="text" name="company_phone" required>
            </div>
            <div class="form-group">
                <label>Company Address:</label>
                <textarea name="company_address" required></textarea>
            </div>
            <div class="form-group">
                <label>State:</label>
                <select name="state" required>
                    <option value="">Select State</option>
                    <option value="Tamil Nadu">Tamil Nadu</option>
                    <option value="Karnataka">Karnataka</option>
                    <!-- Add other states here -->
                </select>
            </div>
            <button type="submit">Next</button>
        </form>
    </div>
</body>
</html>