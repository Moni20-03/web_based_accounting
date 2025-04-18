<?php
session_start();

// Verify password reset session
if (!isset($_SESSION['password_reset']) || (time() - $_SESSION['password_reset']['verified_at']) > 3600) {
    header("Location: forgot_password.php");
    exit();
}

include 'database/db_connection.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
       
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        // Validate inputs
        if (empty($new_password)) {
            $errors['new_password'] = "New password is required";
        } elseif (strlen($new_password) < 8) {
            $errors['new_password'] = "Password must be at least 8 characters";
        }

        if (empty($confirm_password)) {
            $errors['confirm_password'] = "Please confirm your password";
        } elseif ($new_password !== $confirm_password) {
            $errors['confirm_password'] = "Passwords do not match";
        }

        if (empty($errors)) {
            // Connect to company database
            $company_conn = new mysqli($db_host, $db_user, $db_pass, $_SESSION['password_reset']['company_db']);
            if ($company_conn->connect_error) {
                throw new Exception("Error connecting to company database");
            }

            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $company_conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->bind_param("si", $hashed_password, $_SESSION['password_reset']['user_id']);

            if ($stmt->execute()) {
                // Clear reset session and redirect to login
                unset($_SESSION['password_reset']);
                $_SESSION['password_reset_success'] = true;
                header("Location: login.php");
                exit();
            } else {
                throw new Exception("Error updating password");
            }
        }
    } catch (Exception $e) {
        $errors['system'] = "An error occurred: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - FinPack</title>
    <link rel="stylesheet" href="styles/form_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <style>
        .password-rules {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
        .error-message {
            color: #e74c3c;
            font-size: 0.9rem;
            margin-top: 5px;
            display: block;
        }
    </style>
</head>
<body>
    <div class="main-content login-container">
        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-key"></i> Create New Password</h2>
            </div>
            
            <?php if (!empty($errors['system'])): ?>
                <div class="system-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['system']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="group-form" autocomplete="off">
            
                <div class="form-group <?php echo !empty($errors['new_password']) ? 'has-error' : ''; ?>">
                    <label><i class="fas fa-lock"></i> New Password:</label>
                    <input type="password" name="new_password" required minlength="8">
                    <?php if (!empty($errors['new_password'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['new_password']); ?></span>
                    <?php endif; ?>
                    <div class="password-rules">Minimum 8 characters</div>
                </div>
                
                <div class="form-group <?php echo !empty($errors['confirm_password']) ? 'has-error' : ''; ?>">
                    <label><i class="fas fa-lock"></i> Confirm Password:</label>
                    <input type="password" name="confirm_password" required minlength="8">
                    <?php if (!empty($errors['confirm_password'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['confirm_password']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="submit-button">
                        <i class="fas fa-save"></i> Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>