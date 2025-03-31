<?php
session_start();
require 'db_connection.php';

if (!isset($_GET['group_id']) || empty($_GET['group_id'])) {
    die("Invalid Request! Group ID missing in URL.");
}

$group_id = intval($_GET['group_id']);
$company_id = $_SESSION['company_id'];

$query = "SELECT * FROM groups WHERE group_id = ? AND (company_id = ? OR company_id IS NULL)";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $group_id, $company_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("No such group found!");
}

$group = $result->fetch_assoc();
$stmt->close();

// Fetch available parent groups
$parentGroupsQuery = "SELECT group_id, group_name FROM groups WHERE (company_id = ? OR company_id IS NULL) AND group_id != ?";
$stmt = $conn->prepare($parentGroupsQuery);
$stmt->bind_param("ii", $company_id, $group_id);
$stmt->execute();
$parentGroups = $stmt->get_result();
$stmt->close();

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $group_name = trim($_POST['group_name']);
    $parent_group_id = $_POST['parent_group_id'] ?: NULL;
    $nature = $_POST['nature'];

    // Validate name
    if (!preg_match("/^[a-zA-Z\s\p{P}]+$/u", $group_name)) {
        $error = "Group name must contain only letters, spaces, and special characters.";
    } else {
        $checkQuery = "SELECT group_id FROM groups WHERE group_name = ? AND company_id = ? AND group_id != ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("sii", $group_name, $company_id, $group_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = "Group name already exists.";
        }
        $stmt->close();
    }

    if (empty($error)) {
        // Update group details
        $updateQuery = "UPDATE groups SET group_name = ?, parent_group_id = ?, nature = ?, company_id = ? WHERE group_id = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("sssii", $group_name, $parent_group_id, $nature, $company_id, $group_id);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Group updated successfully";
            header("Location: search_group.php");
            exit();
        } else {
            $error = "Error updating group.";
        }
        $stmt->close();
    }

    if (isset($_SESSION['success_message'])) {
        echo '<div class="success-message"><i class="fas fa-check-circle"></i> ' . $_SESSION['success_message'] . '</div>';
        //unset($_SESSION['success_message']); // Clear the message after displaying
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Group</title>
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
            font-weight: 600;
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
            <a href="groups.php" class="sidebar-link"><i class="fas fa-folder"></i> Groups</a>
            <a href="logout.php" class="sidebar-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-edit"></i> Edit Group</h2>
            </div>
            <?php if ($error) echo "<div class='error-message'><i class='fas fa-exclamation-circle'></i> $error</div>"; ?>
            <form method="POST" class="group-form">
                <div class="form-group">
                    <label for="group_name"><i class="fas fa-tag"></i> Group Name:</label>
                    <input type="text" id="group_name" name="group_name" value="<?php echo htmlspecialchars($group['group_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="parent_group_id"><i class="fas fa-sitemap"></i> Parent Group:</label>
                    <select id="parent_group_id" name="parent_group_id">
                        <option value="">None</option>
                        <?php while ($row = $parentGroups->fetch_assoc()) { ?>
                            <option value="<?php echo $row['group_id']; ?>" <?php echo ($group['parent_group_id'] == $row['group_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['group_name']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="nature"><i class="fas fa-leaf"></i> Nature:</label>
                    <select id="nature" name="nature" required>
                        <option value="Asset" <?php if ($group['nature'] == 'Asset') echo 'selected'; ?>>Asset</option>
                        <option value="Liability" <?php if ($group['nature'] == 'Liability') echo 'selected'; ?>>Liability</option>
                        <option value="Income" <?php if ($group['nature'] == 'Income') echo 'selected'; ?>>Income</option>
                        <option value="Expense" <?php if ($group['nature'] == 'Expense') echo 'selected'; ?>>Expense</option>
                    </select>
                </div>
                <button type="submit" class="submit-button"><i class="fas fa-save"></i> Update Group</button>
            </form>
        </div>
    </div>
</body>
</html>