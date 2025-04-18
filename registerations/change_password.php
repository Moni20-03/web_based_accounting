<?php
include '../database/../database/findb.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
      
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        // Validate inputs
        if (empty($new_password) || empty($confirm_password)) {
            $errors['general'] = "All fields are required";
        } elseif (strlen($new_password) < 8) {
            $errors['new_password'] = "Password must be at least 8 characters";
        } elseif ($new_password !== $confirm_password) {
            $errors['confirm_password'] = "Passwords do not match";
        }

        // Only proceed if no validation errors
        if (empty($errors)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->bind_param("si", $hashed_password, $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['password_changed'] = true;
                header("Location: ../dashboards/dashboard.php");
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
    <!-- Prevent caching -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Change Password</title>
    <link rel="stylesheet" href="../styles/form_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <style>
        .error-message {
            color: #e74c3c;
            font-size: 0.9rem;
            margin-top: 5px;
            display: block;
        }
        .form-group.has-error input {
            border-color: #e74c3c;
        }
        .system-error, .general-error {
            background-color: #fde8e8;
            color: #e74c3c;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .password-hint {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <!-- Navbar (consistent with your login page) -->
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="index.html">
                <img class="logo" src="../images/logo3.png" alt="Logo">
                <span>FinPack</span> 
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content login-container">
        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-key"></i> Change Password</h2>
            </div>
            
            <?php if (!empty($errors['system'])): ?>
                <div class="system-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['system']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors['general'])): ?>
                <div class="general-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['general']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="group-form" autocomplete="off">
                <div class="form-group <?php echo !empty($errors['new_password']) ? 'has-error' : ''; ?>">
                    <label for="new_password"><i class="fas fa-lock"></i> New Password:</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8">
                    <?php if (!empty($errors['new_password'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['new_password']); ?></span>
                    <?php endif; ?>
                    <div class="password-hint">Password must be at least 8 characters long</div>
                </div>
                
                <div class="form-group <?php echo !empty($errors['confirm_password']) ? 'has-error' : ''; ?>">
                    <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    <?php if (!empty($errors['confirm_password'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['confirm_password']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="submit-button">
                        <i class="fas fa-save"></i> Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Clear form cache on page load
            if (window.performance && window.performance.navigation.type === window.performance.navigation.TYPE_BACK_FORWARD) {
                document.querySelector('form').reset();
            }
            
            // Password strength indicator (optional)
            const passwordInput = document.getElementById('new_password');
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                // You could add visual feedback about password strength here
            });
        });
    </script>
</body>
</html>