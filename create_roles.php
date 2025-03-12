<?php
include 'db_connection.php';

if (!isset($_GET['company_id']) || !isset($_GET['secret_code'])) {
    die("Invalid access.");
}

$company_id = $_GET['company_id'];
$secret_code = $_GET['secret_code'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_id = $_POST['company_id'];
    $role = $_POST['role'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $dob = trim($_POST['dob']); // Store as plain text

    // Validate DOB format
    if (!preg_match('/^\d{8}$/', $dob)) {
        echo "<script>alert('Invalid Date of Birth format! Use DDMMYYYY.');</script>";
        exit();
    }

    // Insert new role
    $stmt = $conn->prepare("INSERT INTO users (company_id, username, email, dob, role) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $company_id, $username, $email, $dob, $role);
    if ($stmt->execute()) {
        echo "<script>alert('User $username created successfully!'); window.location.href='create_roles.php?company_id=$company_id&secret_code=$secret_code';</script>";
    } else {
        echo "<script>alert('Error adding $role.');</script>";
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
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h2>Create Users for Company</h2>
    <p>Secret Code: <strong><?php echo $secret_code; ?></strong></p>

    <form method="POST">
        <label>Username:</label>
        <input type="text" name="username" required>

        <label>Email:</label>
        <input type="email" name="email" required>

        <label>Date of Birth (DDMMYYYY):</label>
        <input type="text" name="dob" required placeholder="DDMMYYYY" maxlength="8">

        <label>Role:</label>
        <select name="role" required>
            <option value="Accountant">Accountant</option>
            <option value="Manager">Manager</option>
        </select>

        <button type="submit">Create User</button>
    </form>
</body>
</html>
