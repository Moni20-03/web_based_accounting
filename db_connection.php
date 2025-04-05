<?php
$db_host = "localhost"; // Your MySQL Host
$db_user = "root"; // MySQL Username
$db_pass = ""; // MySQL Password (default is empty for XAMPP)
$db_global_db = "finpack_global"; // Your global database

$conn = new mysqli($db_host, $db_user, $db_pass, $db_global_db);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>
