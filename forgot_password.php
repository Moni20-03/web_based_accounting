<?php
session_start();

include 'database/db_connection.php';

$errors = [];
$formData = [
    'company_name' => '',
    'email' => '',
    'dob' => ''
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
    
        // Sanitize inputs
        $formData['company_name'] = trim($_POST['company_name'] ?? '');
        $formData['email'] = trim($_POST['email'] ?? '');
        $formData['dob'] = trim($_POST['dob'] ?? '');

        // Validate inputs
        if (empty($formData['company_name'])) {
            $errors['company_name'] = "Company name is required";
        }

        if (empty($formData['email'])) {
            $errors['email'] = "Email is required";
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Invalid email format";
        }

        if (empty($formData['dob'])) {
            $errors['dob'] = "Date of birth is required";
        } elseif (!preg_match('/^\d{8}$/', $formData['dob'])) {
            $errors['dob'] = "Must be in DDMMYYYY format";
        } else {
            // Validate date components
            $day = substr($formData['dob'], 0, 2);
            $month = substr($formData['dob'], 2, 2);
            $year = substr($formData['dob'], 4, 4);
            
            if (!checkdate($month, $day, $year)) {
                $errors['dob'] = "Invalid date (DDMMYYYY)";
            }
        }

        // Only proceed if no validation errors
        if (empty($errors)) {
            // Step 1: Find the company database
            $stmt = $conn->prepare("SELECT company_id FROM companies WHERE LOWER(company_name) = LOWER(?)");
            $stmt->bind_param("s", $formData['company_name']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $errors['company_name'] = "Company not found";
            } else {
                $company = $result->fetch_assoc();
                $company_db_name = "finpack_company_" . $company['company_id'];
                
                // Step 2: Connect to company database
                $company_conn = new mysqli($db_host, $db_user, $db_pass, $company_db_name);
                if ($company_conn->connect_error) {
                    throw new Exception("Error connecting to company database");
                }

                // Step 3: Verify user credentials
                $stmt = $company_conn->prepare("SELECT user_id FROM users WHERE email = ? AND dob = ?");
                $stmt->bind_param("ss", $formData['email'], $formData['dob']);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    $errors['email'] = "No matching account found";
                } else {
                    $user = $result->fetch_assoc();
                    
                    // Store verification in session for password reset step
                    $_SESSION['password_reset'] = [
                        'user_id' => $user['user_id'],
                        'company_db' => $company_db_name,
                        'verified_at' => time()
                    ];
                    
                    // Redirect to password reset page
                    header("Location: reset_password.php");
                    exit();
                }
                $company_conn->close();
            }
            $stmt->close();
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
    <title>Forgot Password - FinPack</title>
    <link rel="stylesheet" href="styles/form_style.css">
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
        .system-error {
            background-color: #fde8e8;
            color: #e74c3c;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
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
    </nav>

    <div class="main-content login-container">
        <div class="form-container" style="max-width: 400px;">
            <div class="form-header">
                <h2><i class="fas fa-key"></i> Password Recovery</h2>
            </div>

            <?php if (!empty($errors['system'])): ?>
                <div class="system-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['system']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="group-form" autocomplete="off">
            
                <div class="form-group <?php echo !empty($errors['company_name']) ? 'has-error' : ''; ?>">
                    <label><i class="fas fa-building"></i> Company Name:</label>
                    <input type="text" name="company_name" value="<?php echo htmlspecialchars($formData['company_name']); ?>" required>
                    <?php if (!empty($errors['company_name'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['company_name']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group <?php echo !empty($errors['email']) ? 'has-error' : ''; ?>">
                    <label><i class="fas fa-envelope"></i> Email:</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>" required>
                    <?php if (!empty($errors['email'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['email']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group <?php echo !empty($errors['dob']) ? 'has-error' : ''; ?>">
                    <label><i class="fas fa-calendar-day"></i> Date of Birth (DDMMYYYY):</label>
                    <input type="text" name="dob" value="<?php echo htmlspecialchars($formData['dob']); ?>" 
                           placeholder="DDMMYYYY" maxlength="8" required>
                    <?php if (!empty($errors['dob'])): ?>
                        <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['dob']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class ="forgot-password">
                        <a href="login.php" class="cancel-button">
                            <i class="fas fa-times-circle"></i> Back to Login
                        </a>
                </div>

                <div class="form-actions">
                    <button type="submit" class="submit-button">
                        <i class="fas fa-check-circle"></i> Verify Identity
                    </button>
                </div>
                    
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Format DOB input (digits only)
            const dobInput = document.querySelector('input[name="dob"]');
            dobInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/\D/g, '');
                if (this.value.length > 8) {
                    this.value = this.value.slice(0, 8);
                }
            });
            
            // Clear form cache
            if (window.performance && window.performance.navigation.type === window.performance.navigation.TYPE_BACK_FORWARD) {
                document.querySelector('form').reset();
            }
        });
    </script>
</body>
</html>