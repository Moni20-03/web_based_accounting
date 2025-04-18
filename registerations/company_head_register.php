<?php
// Start secure session
include '../database/../database/findb.php';

// Check if coming from company registration
if (!isset($_SESSION['company_db']) || !isset($_SESSION['company_name'])) {
    header("Location: company_register.php");
    exit();
}


// Initialize variables
$errors = [];
$formData = [
    'head_username' => '',
    'head_email' => '',
    'head_dob' => ''
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {

        // Sanitize inputs
        $formData['head_username'] = trim($_POST['head_username'] ?? '');
        $formData['head_email'] = trim($_POST['head_email'] ?? '');
        $formData['head_dob'] = trim($_POST['head_dob'] ?? '');

        // Validate inputs
        if (empty($formData['head_username'])) {
            $errors['head_username'] = "Username is required";
        } elseif (strlen($formData['head_username']) > 255) {
            $errors['head_username'] = "Username is too long";
        }

        if (empty($formData['head_email'])) {
            $errors['head_email'] = "Email is required";
        } elseif (!filter_var($formData['head_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['head_email'] = "Invalid email format";
        }

        if (empty($formData['head_dob'])) {
            $errors['head_dob'] = "Date of Birth is required";
        } elseif (!preg_match('/^\d{8}$/', $formData['head_dob'])) {
            $errors['head_dob'] = "Must be in DDMMYYYY format (8 digits)";
        } else {
            // Validate the date components
            $day = substr($formData['head_dob'], 0, 2);
            $month = substr($formData['head_dob'], 2, 2);
            $year = substr($formData['head_dob'], 4, 4);
            
            if (!checkdate($month, $day, $year)) {
                $errors['head_dob'] = "Invalid date (DDMMYYYY)";
            }
        }

        // Only proceed if no validation errors
        if (empty($errors)) {
            // Check if username or email already exists
            $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
            $check_stmt->bind_param("ss", $formData['head_username'], $formData['head_email']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $errors['head_email'] = "Username or email already exists";
            } else {
                // Hash the password (using DOB as initial password)
                $hashed_password = password_hash($formData['head_dob'], PASSWORD_DEFAULT);
                
                // Insert into database
                $stmt = $conn->prepare("INSERT INTO users (username, email, dob, password, role) VALUES (?, ?, ?, ?, 'Company Head')");
                $stmt->bind_param("ssss", $formData['head_username'], $formData['head_email'], $formData['head_dob'], $hashed_password);

                if ($stmt->execute()) {
                    // Store minimal session data
                    $_SESSION['user_id'] = $stmt->insert_id;
                    $_SESSION['username'] = $formData['head_username'];
                    $_SESSION['role'] = 'Company Head';
                    $_SESSION['last_activity'] = time();
                    
                    // Redirect to next step
                    header("Location: create_roles.php");
                    exit();
                } else {
                    throw new Exception("Database error: Could not register user");
                }
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
    <!-- Prevent caching -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Company Head Registration</title>
    <link rel="stylesheet" href="../styles/reg_styles.css">
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
    <div class="form-container">
        <h2>Company Head Registration</h2>
        
        <?php if (!empty($errors['system'])): ?>
            <div class="system-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['system']); ?>
            </div>
        <?php endif; ?>
        
        <form id="companyHeadRegisterForm" method="POST" autocomplete="off">
            
            <div class="form-group <?php echo !empty($errors['head_username']) ? 'has-error' : ''; ?>">
                <label><i class="fa-solid fa-user"></i> Username:</label>
                <input type="text" name="head_username" value="<?php echo htmlspecialchars($formData['head_username']); ?>" required>
                <?php if (!empty($errors['head_username'])): ?>
                    <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['head_username']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group <?php echo !empty($errors['head_email']) ? 'has-error' : ''; ?>">
                <label><i class="fa-solid fa-envelope"></i> Email:</label>
                <input type="email" name="head_email" value="<?php echo htmlspecialchars($formData['head_email']); ?>" required>
                <?php if (!empty($errors['head_email'])): ?>
                    <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['head_email']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group <?php echo !empty($errors['head_dob']) ? 'has-error' : ''; ?>">
                <label><i class="fa-solid fa-calendar"></i> Date of Birth (DDMMYYYY):</label>
                <input type="text" name="head_dob" value="<?php echo htmlspecialchars($formData['head_dob']); ?>" required 
                       placeholder="DDMMYYYY" maxlength="8" pattern="\d{8}" 
                       title="Please enter exactly 8 digits (DDMMYYYY)">
                <?php if (!empty($errors['head_dob'])): ?>
                    <span class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['head_dob']); ?></span>
                <?php endif; ?>
            </div>
            
            <button type="submit">Register <i class="fa-solid fa-check"></i></button>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Format DOB input (auto-add slashes)
            const dobInput = document.querySelector('input[name="head_dob"]');
            
            dobInput.addEventListener('input', function(e) {
                // Remove any non-digit characters
                this.value = this.value.replace(/\D/g, '');
                
                // Validate length
                if (this.value.length > 8) {
                    this.value = this.value.slice(0, 8);
                }
            });
            
            // Clear form cache on page load
            if (window.performance && window.performance.navigation.type === window.performance.navigation.TYPE_BACK_FORWARD) {
                document.getElementById('companyHeadRegisterForm').reset();
            }
        });
    </script>
</body>
</html>