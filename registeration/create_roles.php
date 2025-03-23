<?php
session_start();
include '../db_connection.php';

// Ensure user is logged in and is a Company Head
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Company Head') {
    die("Unauthorized access.");
}

// Get company ID from session or URL
if (!isset($_SESSION['company_id']) && isset($_GET['company_id'])) {
    $_SESSION['company_id'] = $_GET['company_id'];  // Store company ID in session
}

$company_id = $_SESSION['company_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $role = trim($_POST['role']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $dob = trim($_POST['dob']);

    if (!preg_match('/^\d{8}$/', $dob)) {
        echo "<script>alert('Invalid Date of Birth format! Use DDMMYYYY.'); window.history.back();</script>";
        exit();
    }

    // Hash DOB before storing it as a password
    $hashed_password = password_hash($dob, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (company_id, username, email, dob, password, role) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $company_id, $username, $email, $dob, $hashed_password, $role);

    if ($stmt->execute()) {
        echo "<script>alert('$role created successfully!'); window.location.href='dashboard_head.php';</script>";
    } else {
        echo "<script>alert('Error adding user. Please try again.');</script>";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Roles</title>
    <link rel="stylesheet" href="reg_styles.css"> 
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <!-- Ensure this matches your design -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
    <div class="form-container">
        <h2>Create User Role</h2>
            <form method="POST">
                        <div class="form-group">
                                <label><i class="fa-solid fa-user"></i> Username:</label>
                                <input type="text" name="username" required>
                        </div>

                        <div class="form-group">
                                <label><i class="fa-solid fa-envelope"></i> Email:</label>
                                <input type="email" name="email" required>
                        </div>
                        
                        <div class="form-group">
                                <label><i class="fa-solid fa-calendar"></i> Date of Birth (DDMMYYYY):</label>
                                <input type="text" name="dob" required placeholder="DDMMYYYY" maxlength="8">
                        </div>
             
                        <div class="form-group">
                                <label><i class="fa-solid fa-map-marker-alt"></i> Role:</label>
                                <select name="role" required>
                                    <option value=""></option>
                                    <option value="Accountant">Accountant</option>
                                    <option value="Manager">Manager</option>
                                </select>
                        </div>

                        <button type="submit">Create Role <i class="fa-solid fa-check"></i></button>
                        
                        <p class="text-center mt-3">
                            <a href="dashboard_head.php">Back to Dashboard</a>
                        </p>
            </form>
    </div>
</body>
</html>
