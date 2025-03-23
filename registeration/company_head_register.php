<?php
session_start();
include '../db_connection.php';

if (!isset($_GET['company_id'])) {
    die("Invalid Access!");
}

$company_id = $_GET['company_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $head_username = trim($_POST['head_username']);
    $head_email = trim($_POST['head_email']);
    $head_dob = trim($_POST['head_dob']);

    if (empty($head_username) || empty($head_email) || empty($head_dob)) {
        echo json_encode(["status" => "error", "message" => "All fields are required."]);
        exit();
    }

    if (!preg_match('/^\d{8}$/', $head_dob)) {
        echo json_encode(["status" => "error", "message" => "Invalid Date of Birth format!"]);
        exit();
    }

    $hashed_password = password_hash($head_dob, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (company_id, username, email, dob, password, role) VALUES (?, ?, ?, ?, ?, 'Company Head')");
    $stmt->bind_param("issss", $company_id, $head_username, $head_email, $head_dob, $hashed_password);
    
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(["status" => "success", "redirect" => "create_roles.php"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error in registration!"]);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Head Registration</title>
    <link rel="stylesheet" href="reg_styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
</head>
<body>

    <div class="form-container">
        <h2>Company Head Registration</h2>
        <form id="companyHeadRegisterForm">
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
                <input type="text" name="head_dob" required placeholder="DDMMYYYY" maxlength="8">
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
            .then(res => res.json())
            .then(data => {
                if (data.status === "success") {
                    window.location.href = data.redirect;
                } else {
                    alert("Error: " + (data.message || "Unexpected error"));
                }
            })
            .catch(err => console.error("Fetch error:", err));
        });
    </script>

</body>
</html>

 