<?php
session_start();
include '../db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Store company details in session
    $_SESSION['company_name'] = $_POST['company_name'];
    $_SESSION['company_email'] = $_POST['company_email'];
    $_SESSION['company_phone'] = $_POST['company_phone'];
    $_SESSION['company_address'] = $_POST['company_address'];
    $_SESSION['state'] = $_POST['state'];

    // Redirect to company head details form
    header("Location: company_head_register.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Registration</title>
    <style> 
       /* Import Google Font */
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap');

/* Gradient Background */
body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(to right,rgb(120, 177, 239),rgb(77, 204, 229)); /* Gradient */
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100vh;
    margin: 0;
    padding: 20px;
}

/* Transparent Form Container */
.form-container {
    /* background: rgba(255, 255, 255, 0.15); Transparent effect */
    /* backdrop-filter: blur(10px); Blur effect */
    padding: 30px;
    border-radius: 15px;
    /* box-shadow: 0px 4px 20px rgba(0, 0, 0, 0.2); */
    max-width: 400px;
    width: 100%;
    text-align: center;
    /* border: 2px solid rgba(255, 255, 255, 0.3); Soft border */
}

/* Form Header */
.form-container h2 {
    margin-top: 0px;
    font-weight: 600;
    color: #1E1E1E  ;
    margin-bottom: 25px;
    text-shadow: 2px 2px 5px rgba(0, 0, 0, 0.3);
}

/* Form Fields */
.form-group {
    margin-bottom: 15px;
    text-align: left;
}

/* Labels */
.form-group label {
    font-weight: 400;
    display: block;
    color: #004C66 ;
    font-size: 18px;
    margin-bottom: 8px;
}

/* Input Fields */
.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 8px;
    font-size: 18px;
    transition: 0.3s;
    background: rgba(255, 255, 255, 0.2); /* Transparent input background */
    color: #0A2A43 ;
    backdrop-filter: blur(5px);
    border-bottom: 2px solid rgba(255, 255, 255, 0.5);
}

.form-group select
{
    width:106%;
    color:black;
}
/* Placeholder Text */
.form-group input::placeholder, .form-group textarea::placeholder {
    color: rgba(255, 255, 255, 0.7);
}

/* Focus Effect */
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    border-bottom: 2px solid #ffffff;
    outline: none;
    box-shadow: 0 0 8px rgba(255, 255, 255, 0.5);
}

/* Submit Button */
button {
    background: #FF6600 ;
    color: white;
    padding: 15px 5px;
    border: none;
    border-radius: 8px;
    width: 40%;
    font-size: 18px;
    cursor: pointer;
    transition: 0.3s;
    font-weight: bold;
}

button:hover {
    background: #cc5500;
    transform: scale(1.05);
}

/* Login Link */
.login-link {
    margin-top: 10px;
    display: block;
    color: #ffffff;
    text-decoration: none;
    font-size: 14px;
}

.login-link:hover {
    text-decoration: underline;
}

/* Responsive Design */
@media (max-width: 768px) {
    .form-container {
        max-width: 85%;
    }
}
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <div class="form-container">
        <h2>  Company Registration</h2>
        <form method="POST">
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
                    <!-- Add other states here -->
                </select>
            </div>
            <button type="submit"><i class="fa-solid fa-arrow-right"></i> Next</button>
            <p class="login-link">Already registered? <a href="../login.php">Login here</a></p>
        </form>
    </div>
</body>
</html>
