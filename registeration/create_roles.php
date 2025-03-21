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
        echo "<script>alert('Invalid Date of Birth format! Use DDMMYYYY.');</script>";
        exit();
    }

    // Hash the DOB before storing it as a password
    $hashed_password = password_hash($dob, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (company_id, username, email, dob, password, role) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $company_id, $username, $email, $dob, $hashed_password, $role);

    if ($stmt->execute()) {
        echo "<script>alert('$role created successfully!'); window.location.href='create_roles.php';</script>";
    } else {
        echo "<script>alert('Error adding user.');</script>";
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow-lg">
                    <div class="card-body">
                        <h3 class="text-center mb-3">Create User Role</h3>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Username:</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email:</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Date of Birth (DDMMYYYY):</label>
                                <input type="text" name="dob" class="form-control" required placeholder="DDMMYYYY" maxlength="8">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Role:</label>
                                <select name="role" class="form-select" required>
                                    <option value="Accountant">Accountant</option>
                                    <option value="Manager">Manager</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Create User</button>
                        </form>

                        <p class="text-center mt-3">
                            <a href="../dashboard_head.php">Back to Dashboard</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
