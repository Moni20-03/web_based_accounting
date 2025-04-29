<?php
// Database connection
$host = 'localhost';
$db = 'finpack_global';
$user = 'root';
$pass = ''; // Update with your DB password if needed

$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get input values safely
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $message = trim($_POST['message']);

    // Prepare and execute insert query
    $stmt = $conn->prepare("INSERT INTO contact (name, email, message) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $name, $email, $message);

    if ($stmt->execute()) {
        echo "<script>alert('Thank you! Your message has been sent.'); window.history.back();</script>";
    } else {
        echo "<script>alert('Something went wrong. Please try again.'); window.history.back();</script>";
    }

    $stmt->close();
}

$conn->close();
?>
