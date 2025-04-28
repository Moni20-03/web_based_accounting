<?php
session_start();

if (!isset($_SESSION['company_db'])) {
    // die(json_encode(["status" => "error", "message" => "Company database is not set in session!"]));
    header("Location:login.php");
}

// $company_name = $_SESSION['company_name'];
$company_db = $_SESSION['company_db'];
$conn = new mysqli("localhost", "root", "", $company_db);

if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]));
}

?>
