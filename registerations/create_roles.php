<?php
include '../database/findb.php';

// Ensure user is logged in and is a Company Head
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Company Head') {
    die("Unauthorized access.");
}

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

    $stmt = $conn->prepare("INSERT INTO users (username, email, dob, password, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sssss", $username, $email, $dob, $hashed_password, $role);

    if ($stmt->execute()) {
        echo "<script>alert('$role created successfully!'); window.location.href='../dashboards/dashboard_head.php';</script>";
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
    <title>Create User Role</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/form_style.css">
</head>
<body>
    <?php
        include('../navbar.php');
    ?>
    <div class="main-content">
        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-user-plus"></i> Create User Role</h2>
            </div>
            
            <form method="POST" class="group-form">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Username:</label>
                    <input type="text" name="username" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email:</label>
                    <input type="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Date of Birth (DDMMYYYY):</label>
                    <input type="text" name="dob" required placeholder="DDMMYYYY" maxlength="8">
                </div>
     
                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> Role:</label>
                    <select name="role" required>
                        <option value="">Select a role</option>
                        <option value="Accountant">Accountant</option>
                        <option value="Manager">Manager</option>
                    </select>
                </div>

                <button type="submit" class="submit-button">
                    Create Role <i class="fas fa-check"></i>
                </button>
            </form>
            
            <div class="back-link" style="text-align: center;margin-top:5px;">
                <a href="../dashboards/dashboard_head.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>