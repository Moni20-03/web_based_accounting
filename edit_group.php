<?php
include 'findb.php'; // Database connection

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Validate group_id parameter
if (!isset($_GET['group_id']) || empty($_GET['group_id'])) {
    $_SESSION['error_message'] = "Invalid Request! Group ID missing in URL.";
    header("Location: search_group.php");
    exit();
}

$group_id = intval($_GET['group_id']);

// Fetch group details
$query = "SELECT * FROM groups WHERE group_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $group_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error_message'] = "No such group found!";
    header("Location: search_group.php");
    exit();
}

$group = $result->fetch_assoc();
$stmt->close();

// Fetch available parent groups (excluding current group)
$parentGroupsQuery = "SELECT group_id, group_name FROM groups WHERE group_id != ?";
$stmt = $conn->prepare($parentGroupsQuery);
$stmt->bind_param("i", $group_id);
$stmt->execute();
$parentGroups = $stmt->get_result();
$stmt->close();

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $group_name = trim($_POST['group_name']);
    $parent_group_id = !empty($_POST['parent_group_id']) ? (int)$_POST['parent_group_id'] : NULL;
    $nature = $_POST['nature'];

    // Validate inputs
    if (empty($group_name)) {
        $error = "Group name is required.";
    } elseif (!preg_match("/^[a-zA-Z\s\p{P}]+$/u", $group_name)) {
        $error = "Group name must contain only letters, spaces, and special characters.";
    } else {
        // Check for duplicate group name
        $checkQuery = "SELECT group_id FROM groups WHERE group_name = ? AND group_id != ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("si", $group_name, $group_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = "Group name already exists.";
        }
        $stmt->close();
    }

    // Validate nature
    $allowed_natures = ['Asset', 'Liability', 'Income', 'Expense'];
    if (!in_array($nature, $allowed_natures)) {
        $error = "Invalid nature selected.";
    }

    if (empty($error)) {
        // Update group
        $updateQuery = "UPDATE groups SET group_name = ?, parent_group_id = ?, nature = ? WHERE group_id = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("sisi", $group_name, $parent_group_id, $nature, $group_id);
        
        if ($stmt->execute()) {
            $success = "Group updated successfully!";
            // Set a flag to indicate successful update
            $_SESSION['update_success'] = true;
            
            // Use JavaScript to redirect after showing the message
            echo '<script>
                setTimeout(function() {
                    window.location.href = "search_group.php";
                }, 1500);
            </script>';
        } else {
            $error = "Error updating group: " . $stmt->error;
        }
        $stmt->close();
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
    <link rel="stylesheet" href="styles/form_style.css">
    <style>
        /* Add animation for success message */
        .success-message {
            animation: fadeInOut 3s ease-in-out;
        }
        
        @keyframes fadeInOut {
            0% { opacity: 0; }
            20% { opacity: 1; }
            80% { opacity: 1; }
            100% { opacity: 0; }
        }
    </style>
    <script>
        // Clear form if page is loaded from cache (back button)
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="index.html">
                <img class="logo" src="images/logo3.png" alt="Logo">
                <span>FinPack</span> 
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </li>
            <li>
                <a href="#">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </a>
            </li>
            <li>
                <a href="logout.php" style="color:rgb(235, 71, 53);">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="form-container" style="margin-top:3rem;">
            <div class="form-header">
                <h2><i class="fas fa-edit"></i> Edit Group</h2>
            </div>
            
            <?php 
            // Display error messages if they exist
            if (!empty($error)) {
                echo "<div class='error-message'><i class='fas fa-exclamation-circle'></i> " . htmlspecialchars($error) . "</div>";
            }
            
            // Display success message if it exists
            if (!empty($success)) {
                echo "<div class='success-message'><i class='fas fa-check-circle'></i> " . 
                     htmlspecialchars($success) . "</div>";
            }
            ?>
            
            <form method="POST" class="group-form" id="editGroupForm">
                <div class="form-group">
                    <label for="group_name"><i class="fas fa-tag"></i> Group Name:</label>
                    <input type="text" id="group_name" name="group_name" 
                           value="<?php echo htmlspecialchars($group['group_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="parent_group_id"><i class="fas fa-sitemap"></i> Parent Group:</label>
                    <select id="parent_group_id" name="parent_group_id">
                        <option value="">None</option>
                        <?php 
                        $parentGroups->data_seek(0); // Reset pointer
                        while ($row = $parentGroups->fetch_assoc()) { 
                            $selected = ($group['parent_group_id'] == $row['group_id']) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($row['group_id']) . "' $selected>" . 
                                 htmlspecialchars($row['group_name']) . "</option>";
                        } 
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="nature"><i class="fas fa-leaf"></i> Nature:</label>
                    <select id="nature" name="nature" required>
                        <?php 
                        $natures = ['Asset', 'Liability', 'Income', 'Expense'];
                        foreach ($natures as $nat) {
                            $selected = ($group['nature'] == $nat) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($nat) . "' $selected>" . 
                                 htmlspecialchars($nat) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="submit-button">
                        <i class="fas fa-save"></i> Update Group
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Client-side validation
        document.getElementById('editGroupForm').addEventListener('submit', function(e) {
            const groupName = document.getElementById('group_name').value.trim();
            const nature = document.getElementById('nature').value;
            
            if (!groupName) {
                e.preventDefault();
                alert('Group name is required');
                return false;
            }
            
            if (!/^[a-zA-Z\s\p{P}]+$/u.test(groupName)) {
                e.preventDefault();
                alert('Group name must contain only letters, spaces, and special characters');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>