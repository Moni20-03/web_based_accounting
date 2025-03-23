<?php
session_start();
include '../db_connection.php'; // Ensure correct DB connection

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_name = trim($_POST['company_name']);
    $company_email = trim($_POST['company_email']);
    $company_phone = trim($_POST['company_phone']);
    $company_address = trim($_POST['company_address']);
    $state = trim($_POST['state']);

    if (empty($company_name) || empty($company_email) || empty($company_phone) || empty($company_address) || empty($state)) {
        echo json_encode(["status" => "error", "message" => "All fields are required."]);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO companies (company_name, company_email, company_phone, company_address, state) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $company_name, $company_email, $company_phone, $company_address, $state);

    if ($stmt->execute()) {
        $company_id = $stmt->insert_id;
        $stmt->close();
        echo json_encode(["status" => "success", "redirect" => "company_head_register.php?company_id=$company_id"]);
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
    <title>Company Registration</title>
    <link rel="stylesheet" href="reg_styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
</head>
<body>

    <div class="form-container">
        <h2>Company Registration</h2>
        <form id="companyRegisterForm">
            <div class="form-group">
                <label><i class="fa-solid fa-building"></i> Company Name:</label>
                <input type="text" name="company_name" required>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-envelope"></i> Company Email:</label>
                <input type="email" name="company_email" required>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-phone"></i> Company Phone:</label>
                <input type="text" name="company_phone" required>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-location-dot"></i> Company Address:</label>
                <textarea name="company_address" required></textarea>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-map-marker-alt"></i> State:</label>
                <select name="state" required>
                    <option value=""></option>
                    <option value="Tamil Nadu">Tamil Nadu</option>
                    <option value="Karnataka">Karnataka</option>
                </select>
            </div>
            <button type="button" onclick="registerCompany()">Next <i class="fa-solid fa-arrow-right"></i></button>
        </form>
    </div>

    <script>
        function registerCompany() {
    const formData = new FormData(document.getElementById('companyRegisterForm'));

    fetch('company_register.php', {
        method: "POST",
        body: formData
    })
    .then(res => res.json()) // Ensure JSON parsing
    .then(data => {
        if (data.status === "success") {
            window.location.href = data.redirect;
        } else {
            alert("Error: " + (data.message || "Unexpected error"));
        }
    })
    .catch(err => console.error("Fetch error:", err));
}

    </script>

</body>
</html>

