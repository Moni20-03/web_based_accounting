<?php
session_start();
include 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT user_id, company_id, username, dob, password, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stored_password = $row['password'];

        if (password_verify($password, $stored_password)) {
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['company_id'] = $row['company_id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];

            // Check if the password is still DOB
            if (password_verify($row['dob'], $stored_password)) {
                header("Location: change_password.php"); // Force password change
            } else {
                header("Location: dashboard.php"); // Redirect to dashboard
            }
            exit();
        } else {
            echo "<script>alert('Incorrect Password!');</script>";
        }
    } else {
        echo "<script>alert('No account found with this email.');</script>";
    }
}
?>
  

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FINPACK Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow-lg">
                    <div class="card-body">
                        <h3 class="text-center">FINPACK Login</h3>
                        <form method="POST" action="login.php">
                            <div class="mb-3">
                                <label class="form-label">Email:</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Password:</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>

                            <div class="text-center">
                                <button type="submit" class="btn btn-primary w-100">Login</button>
                            </div>
                        </form>
                        <p class="text-center mt-3">
                            <a href="forgot_password.php">Forgot Password?</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
