<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure no output is sent before headers
ob_start();

include 'findb.php';

// Function to send consistent JSON responses
function jsonResponse($status, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

if (!$conn) {
    jsonResponse('error', 'Database connection failed!');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Sanitize inputs
        $head_username = trim($_POST['head_username'] ?? '');
        $head_email = trim($_POST['head_email'] ?? '');
        $head_dob = trim($_POST['head_dob'] ?? '');

        // Validate inputs
        if (empty($head_username) || empty($head_email) || empty($head_dob)) {
            jsonResponse('error', 'All fields are required.');
        }

        if (!preg_match('/^\d{8}$/', $head_dob)) {
            jsonResponse('error', 'Date of Birth must be in DDMMYYYY format (8 digits).');
        }

        // Validate email format
        if (!filter_var($head_email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse('error', 'Invalid email format.');
        }

        // Check if username or email already exists
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $check_stmt->bind_param("ss", $head_username, $head_email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            jsonResponse('error', 'Username or email already exists.');
        }

        // Hash the password (using DOB as password)
        $hashed_password = password_hash($head_dob, PASSWORD_DEFAULT);
        
        // echo $head_dob;
        // Insert into database
        $stmt = $conn->prepare("INSERT INTO users (username, email, dob, password, role) VALUES (?, ?, ?, ?, 'Company Head')");
        $stmt->bind_param("ssss", $head_username, $head_email, $head_dob, $hashed_password);

        if ($stmt->execute()) {
            $_SESSION['role'] = 'Company Head';
            $_SESSION['username'] = $head_username;
            jsonResponse('success', 'Registration successful!', ['redirect' => 'create_roles.php']);
        } else {
            jsonResponse('error', 'Database error: ' . $stmt->error);
        }
    } catch (Exception $e) {
        jsonResponse('error', 'An unexpected error occurred: ' . $e->getMessage());
    }
}

// Clear any output buffer
ob_end_clean();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Head Registration</title>
    <link rel="stylesheet" href="styles/reg_styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
</head>
<body>
    <div class="form-container">
        <h2>Company Head Registration</h2>
        <?php echo $_SESSION['company_db'];?>
        <form id="companyHeadRegisterForm" method="POST">
            <div class="form-group">
                <label><i class="fa-solid fa-user"></i> Username:</label>
                <input type="text" name="head_username" required>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-envelope"></i> Email:</label>
                <input type="email" name="head_email" required>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-calendar"></i> Date of Birth (DDMMYYYY):</label>
                <input type="text" name="head_dob" required placeholder="DDMMYYYY" maxlength="8" pattern="\d{8}" title="Please enter exactly 8 digits (DDMMYYYY)">
            </div>
            <button type="submit">Register <i class="fa-solid fa-check"></i></button>
        </form>
    </div>

    <script>
        document.getElementById('companyHeadRegisterForm').addEventListener('submit', function(event) {
            event.preventDefault();
            const formData = new FormData(this);

            fetch(window.location.href, {
                method: "POST",
                body: formData
            })
            .then(response => {
                // First check if the response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        throw new Error(`Expected JSON, got: ${text.substring(0, 100)}...`);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.status === "success") {
                    window.location.href = data.data.redirect;
                } else {
                    alert("Error: " + data.message);
                }
            })
            .catch(err => {
                console.error("Fetch error:", err);
                alert("An error occurred. Please check the console for details.");
            });
        });
    </script>
</body>
</html>