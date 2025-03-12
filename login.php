<?php
session_start();
include 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $dob = trim($_POST['dob']); // Use DOB instead of password

    if (!preg_match('/^\d{8}$/', $dob)) {
        echo "<script>alert('Invalid Date of Birth format! Use DDMMYYYY.');</script>";
    } else {
        // Check if email exists
        $stmt = $conn->prepare("SELECT user_id, company_id, username, dob, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            // echo $row['dob'];
            if ($dob === $row['dob']) { // Check DOB match
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['company_id'] = $row['company_id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = $row['role'];

                // Redirect users based on role
                if ($row['role'] == 'Company Head') {
                    header("Location: dashboard.php");
                } elseif ($row['role'] == 'Accountant') {
                    header("Location: dashboard_accountant.php");
                } elseif ($row['role'] == 'Manager') {
                    header("Location: dashboard_manager.php");
                } else {
                    echo "<script>alert('Invalid Role Assigned!');</script>";
                }
                exit();
            } else {
                echo "<script>alert('Incorrect Date of Birth! Try again.');</script>";
            }
        } else {
            echo "<script>alert('No account found with this email.');</script>";
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FINPACK Login</title>
    <link rel="stylesheet" href="styles.css">
    <script>
        function validateLogin() {
            let email = document.forms["loginForm"]["email"].value;
            let dob = document.forms["loginForm"]["dob"].value;
            let emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            let dobPattern = /^\d{8}$/; // Should be exactly 8 digits (DDMMYYYY)

            if (!email || !dob) {
                alert("All fields are required!");
                return false;
            }
            if (!emailPattern.test(email)) {
                alert("Enter a valid email address!");
                return false;
            }
            if (!dobPattern.test(dob)) {
                alert("Enter a valid Date of Birth in DDMMYYYY format!");
                return false;
            }
            return true;
        }
    </script>
</head>
<body>
    <h2>FINPACK Login</h2>
    <form name="loginForm" method="POST" onsubmit="return validateLogin();">
        <label>Email:</label>
        <input type="email" name="email" required>

        <label>Date of Birth (DDMMYYYY):</label>
        <input type="text" name="dob" required placeholder="DDMMYYYY" maxlength="8">

        <button type="submit">Login</button>
    </form>
</body>
</html>
