<?php
session_start();
include '../db_connection.php';

if (!isset($_SESSION['company_name'])) {
    die("Company details not found. Please fill out the company details form first.");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_name = $_SESSION['company_name'];
    $company_email = $_SESSION['company_email'];
    $company_phone = $_SESSION['company_phone'];
    $company_address = $_SESSION['company_address'];
    $state = $_SESSION['state'];

    $head_username = trim($_POST['head_username']);
    $head_email = trim($_POST['head_email']);
    $head_dob = trim($_POST['head_dob']); // Store as plain text (not recommended)

    // Validate username and email
    if (empty($head_username) || empty($head_email) || empty($head_dob)) {
        echo "<script>alert('All fields are required.');</script>";
        exit();
    }

    // Validate DOB format (must be exactly 8 digits, DDMMYYYY)
    if (!preg_match('/^\d{8}$/', $head_dob)) {
        echo "<script>alert('Invalid Date of Birth format! Please enter in DDMMYYYY format.');</script>";
        exit();
    }


    // Start database transaction
    $conn->begin_transaction();
    try {
        // Insert Company Details
        $stmt = $conn->prepare("INSERT INTO companies (company_name, company_email, company_phone, company_address, state) 
                                VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Prepare statement failed: " . $conn->error);
        }
        $stmt->bind_param("sssss", $company_name, $company_email, $company_phone, $company_address, $state);
        $stmt->execute();
        $company_id = $stmt->insert_id; // Get last inserted company ID
        $stmt->close();

        // Hash the Date of Birth before storing as a password (recommended)
        $hashed_password = password_hash($head_dob, PASSWORD_DEFAULT);

        // Insert Company Head User
        $stmt = $conn->prepare("INSERT INTO users (company_id, username, email, dob, password, role) 
                                VALUES (?, ?, ?, ?, ?, 'Company Head')");
        if (!$stmt) {
            throw new Exception("Prepare statement failed: " . $conn->error);
        }
        $stmt->bind_param("issss", $company_id, $head_username, $head_email, $head_dob, $hashed_password);
        $stmt->execute();
        $stmt->close();

        // Commit transaction
        $conn->commit();

        // Redirect to role creation page
        echo "<script>
                alert('Company registered successfully! Redirecting to role creation...');
                window.location.href='create_roles.php?company_id=$company_id';
              </script>";
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Head Registration</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-lg">
                <div class="card-header bg-primary text-white text-center">
                    <h4>Company Head Registration</h4>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Username:</label>
                            <input type="text" name="head_username" class="form-control" required placeholder="Enter username">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email:</label>
                            <input type="email" name="head_email" class="form-control" required placeholder="Enter email">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Date of Birth (DDMMYYYY):</label>
                            <input type="text" name="head_dob" class="form-control" required placeholder="DDMMYYYY" maxlength="8">
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Register Company</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

