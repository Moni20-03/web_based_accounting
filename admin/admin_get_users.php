<?php
session_start();

// Verify admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include '../database/db_connection.php';

$company_id = intval($_GET['company_id'] ?? 0);

if ($company_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid company ID']);
    exit();
}

// Get company name
$company_name = '';
$stmt = $conn->prepare("SELECT company_name FROM companies WHERE company_id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $company = $result->fetch_assoc();
    $company_name = $company['company_name'];
}

// Connect to company database
$company_db_name = "finpack_company_" . $company_id;
$company_conn = new mysqli($db_host, $db_user, $db_pass, $company_db_name);

if ($company_conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Could not connect to company database']);
    exit();
}

// Get users
$users = [];
$result = $company_conn->query("SELECT * FROM users ORDER BY user_id DESC");

if ($result) {
    while ($user = $result->fetch_assoc()) {
        $users[] = [
            'user_id' => $user['user_id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'created_at' => $user['created_at'] ?? 'N/A'
        ];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'company_name' => $company_name,
    'users' => $users
]);
?>