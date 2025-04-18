<?php
session_start();

// Hardcoded admin credentials (in production, store securely in environment variables)
define('ADMIN_USERNAME', 'finpack_admin');
define('ADMIN_PASSWORD_HASH', password_hash('Admin@123', PASSWORD_BCRYPT));

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
            // Regenerate session ID to prevent fixation
            session_regenerate_id(true);
            
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_last_activity'] = time();
            $_SESSION['admin_ip'] = $_SERVER['REMOTE_ADDR'];
            
            header("Location: admin_dashboard.php");
            exit();
        } else {
            $error = "Invalid credentials";
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinPack - Admin Login</title>
    <link rel="stylesheet" href="../styles/form_style.css">
    <style>
        .admin-login-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            background-color: #fff;
        }
        .admin-login-title {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 30px;
            font-size: 24px;
        }
        .admin-login-form .form-group label {
            font-weight: 500;
        }
        .error-message {
            color: #e74c3c;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="admin-login-container">
        <h2 class="admin-login-title"><i class="fas fa-lock"></i> Admin Portal</h2>
        
        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form class="admin-login-form" method="POST">
            <div class="form-group">
                <label><i class="fas fa-user-shield"></i> Admin Username:</label>
                <input type="text" name="username" required autocomplete="off">
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-key"></i> Password:</label>
                <input type="password" name="password" required autocomplete="off">
            </div>
            
            <div class="form-actions">
                <button type="submit" class="submit-button">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </div>
        </form>
    </div>
</body>
</html>