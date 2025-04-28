<?php
// Strict session security
session_start();

// Clear any existing session data if accessing login page
session_unset();
session_destroy();
session_start();

// Database connection with error handling
$global_conn = new mysqli("localhost", "root", "", "finpack_global");
if ($global_conn->connect_error) {
    die("Global DB Connection Failed: " . $global_conn->connect_error);
}

$error = '';
$company_name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  
    // Sanitize and validate inputs
    $company_name = trim($_POST['company_name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    // Basic validation
    if (empty($company_name) || empty($email) || empty($password)) {
        $error = "All fields are required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters";
    } else {
        // Case-insensitive company name check
        $stmt = $global_conn->prepare("SELECT * FROM companies WHERE LOWER(company_name) = LOWER(?)");
        $stmt->bind_param("s", $company_name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $error = "Invalid Company Name!";
        } else {
            $row = $result->fetch_assoc();
            $company_db_name = "finpack_company_" . $row['company_id'];
            
            // Connect to company database with error handling
            $company_conn = new mysqli("localhost", "root", "", $company_db_name);
            if ($company_conn->connect_error) {
                $error = "Error connecting to company database";
            } else {
                // Check user credentials with prepared statement

                $stmt = $company_conn->prepare("SELECT user_id, username, dob, password, role FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    // Generic error message to prevent user enumeration
                    $error = "Invalid credentials";
                } else {
                    $row = $result->fetch_assoc();
                    $stored_password = $row['password'];

                    if (password_verify($password, $stored_password)) {
                        
                        // Store minimal necessary session data
                        $_SESSION['user_id'] = $row['user_id'];
                        $_SESSION['username'] = $row['username'];
                        $_SESSION['role'] = $row['role'];
                        $_SESSION['company_db'] = $company_db_name;
                        $_SESSION['company_name'] = strtoupper($company_name);
                        $_SESSION['last_activity'] = time();

                        // Force password change if password is still DOB
                        if (password_verify($row['dob'], $stored_password)) {
                            header("Location:../finpack_system/registerations/change_password.php");
                        } else {
                            header("Location: ../finpack_system/dashboards/dashboard.php");
                        }
                        exit();
                    } else {
                        // Generic error message to prevent user enumeration
                        $error = "Invalid credentials";
                    }
                }
                $company_conn->close();
            }
        }
        $stmt->close();
    }
}
$global_conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Prevent caching of the login page -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>FINPACK Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .form-header h2 {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 1.5rem;
        }
        
        .forgot-password {
            text-align: right;
            margin-top: -0.5rem;
        }
        
        .forgot-password a {
            color: #3498db;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .forgot-password a:hover {
            text-decoration: underline;
        }
        
        .error-message {
            color: #e74c3c;
            background-color: #fde8e8;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
        }
        .password-hint {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
    </style>
    <link rel="stylesheet" href="styles/form_style.css">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="index.html">
                <img class="logo" src="images/logo3.png" alt="Logo">
                <span>FinPack</span> 
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="registerations/company_register.php">
                <i class="fas fa-building"></i> To Register Company</a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div class="main-content login-container">
        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-sign-in-alt"></i> FINPACK LOGIN</h2>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class='error-message'><i class='fas fa-exclamation-circle'></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            
            <form method="POST" class="group-form" autocomplete="off">
                
                <div class="form-group">
                    <label for="company_name"><i class="fas fa-building"></i> Company Name:</label>
                    <input type="text" id="company_name" name="company_name" value="" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email:</label>
                    <input type="email" id="email" name="email" value="" required>
                </div>
                
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password:</label>
                    <input type="password" id="password" name="password" value="" required minlength="8">
                    <span class="password-hint">First-time login? Use your DOB as password</span>
                    <div class="forgot-password">
                        <a href="forgot_password.php">Forgot Password?</a>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="submit-button">
                        LOGIN
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Clear form on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('company_name').value = '';
            document.getElementById('email').value = '';
            document.getElementById('password').value = '';
            
            // Disable form caching
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
        
        // Clear form when page is shown (for back button)
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                document.getElementById('company_name').value = '';
                document.getElementById('email').value = '';
                document.getElementById('password').value = '';
            }
        });
    </script>
</body>
</html>