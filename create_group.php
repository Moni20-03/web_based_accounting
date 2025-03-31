<?php
session_start();
include 'db_connection.php'; // Database connection

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    header("Location: login.php");
    exit();
}

$company_id = $_SESSION['company_id'];
// echo $company_id;
$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $group_name = trim($_POST['group_name']);
    $parent_group_id = !empty($_POST['parent_group_id']) ? $_POST['parent_group_id'] : NULL;
    $nature = $_POST['nature'];

    // Validate group name (No numbers, Unique check)
    if (!preg_match("/^[a-zA-Z\s\p{P}]+$/u", $group_name)) {
        $error = "Group name must contain only letters, spaces, and special characters.";
    } else {
        $stmt = $conn->prepare("SELECT group_id FROM groups WHERE group_name = ? AND (company_id = ? OR company_id IS NULL)");
        $stmt->bind_param("si", $group_name, $company_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = "Group name already exists.";
        }
        $stmt->close();
    }

    if (!$error) {
        $stmt = $conn->prepare("INSERT INTO groups (company_id, group_name, parent_group_id, nature) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isis", $company_id, $group_name, $parent_group_id, $nature);
        if ($stmt->execute()) {
            header("Location: display_groups.php?success=Group Created Successfully");
            exit();
        } else {
            $error = "Error creating group. Please try again.";
        }
        $stmt->close();
    }
}

// Fetch parent groups
$groups = [];
$result = $conn->query("SELECT group_id, group_name FROM groups WHERE company_id IS NULL");
while ($row = $result->fetch_assoc()) {
    $groups[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Group</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General Styles */
body {
    margin: 0;
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(135deg, #8e44ad, #e74c3c);
    display: flex;
    min-height: 100vh;
    color: #333;
}

/* Sidebar */
.sidebar {
    width: 250px;
    background: #2c3e50;
    padding: 20px;
    box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
    display: flex;
    flex-direction: column;
    transition: width 0.3s ease;
}

.sidebar-header {
    text-align: center;
    margin-bottom: 20px;
}

.sidebar-header h2 {
    font-size: 20px;
    color: #ffffff;
    margin: 0;
}

.sidebar-header i {
    margin-right: 10px;
    color: #e74c3c;
}

.sidebar-menu {
    flex: 1;
}

.sidebar-link {
    display: block;
    padding: 10px;
    color: #ffffff;
    text-decoration: none;
    border-radius: 5px;
    margin-bottom: 10px;
    transition: background 0.3s ease;
}

.sidebar-link:hover {
    background: #34495e;
}

.sidebar-link i {
    margin-right: 10px;
}

/* Main Content */
.main-content {
    flex: 1;
    padding: 20px;
    display: flex;
    justify-content: center;
    align-items: center;
}

/* Form Container */
.form-container {
    background: #ffffff;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    width: 100%;
    max-width: 400px;
    animation: fadeIn 0.5s ease-in-out;
}

/* Form Header */
.form-header {
    text-align: center;
    margin-bottom: 20px;
}

.form-header h2 {
    font-size: 24px;
    color: #2c3e50;
    margin: 0;
}

.form-header i {
    margin-right: 10px;
    color: #8e44ad;
}

/* Form Groups */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 14px;
    font-weight: 600; /* Bolder labels */
    margin-bottom: 5px;
    color: #2c3e50;
}

.form-group i {
    margin-right: 10px;
    color: #8e44ad;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}

.form-group input:focus,
.form-group select:focus {
    border-color: #8e44ad;
    box-shadow: 0 0 5px rgba(142, 68, 173, 0.5);
    outline: none;
}

/* Submit Button */
.submit-button {
    width: 100%;
    padding: 12px;
    background: #8e44ad;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.3s ease, transform 0.3s ease;
}

.submit-button:hover {
    background: #732d91;
    transform: translateY(-2px);
}

.submit-button i {
    margin-right: 10px;
}

/* Error and Success Messages */
.error-message,
.success-message {
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    font-size: 14px;
}

.error-message {
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ef9a9a;
}

.success-message {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.error-message i,
.success-message i {
    margin-right: 10px;
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive Styles */
@media (max-width: 768px) {
    .sidebar {
        width: 60px;
    }

    .sidebar-header h2,
    .sidebar-link span {
        display: none;
    }

    .sidebar-link i {
        margin-right: 0;
    }

    .main-content {
        margin-left: 60px;
    }

    .form-container {
        padding: 20px;
    }
}
    </style>
</head>
<body>
    
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-user-circle"></i> <?php echo $_SESSION['username']; ?></h2>
        </div>
        <div class="sidebar-menu">
            <a href="dashboard.php" class="sidebar-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="logout.php" class="sidebar-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-folder-plus"></i> Create Group</h2>
            </div>
            <?php if ($error) echo "<div class='error-message'><i class='fas fa-exclamation-circle'></i> $error</div>"; ?>
            <?php if (isset($_GET['success'])) echo "<div class='success-message'><i class='fas fa-check-circle'></i> {$_GET['success']}</div>"; ?>
            <form method="POST" class="group-form">
                <div class="form-group">
                    <label for="group_name"><i class="fas fa-tag"></i> Group Name:</label>
                    <input type="text" id="group_name" name="group_name" required>
                </div>
                <div class="form-group">
                    <label for="parent_group_id"><i class="fas fa-sitemap"></i> Parent Group:</label>
                    <select id="parent_group_id" name="parent_group_id">
                        <option value="">None</option>
                        <?php foreach ($groups as $group) echo "<option value='{$group['group_id']}'>{$group['group_name']}</option>"; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="nature"><i class="fas fa-leaf"></i> Nature:</label>
                    <select id="nature" name="nature" required>
                        <option value="Asset">Asset</option>
                        <option value="Liability">Liability</option>
                        <option value="Income">Income</option>
                        <option value="Expense">Expense</option>
                    </select>
                </div>
                <button type="submit" class="submit-button"><i class="fas fa-plus"></i> Create Group</button>
            </form>
        </div>
    </div>
</body>
</html>