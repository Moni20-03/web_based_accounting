<?php
include '../database/findb.php'; // Database connection

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$error = "";
$success = ""; // Added success message variable

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $group_name = trim($_POST['group_name']);
    $parent_group_id = !empty($_POST['parent_group_id']) ? (int)$_POST['parent_group_id'] : NULL;
    $nature = $_POST['nature'];

    // Validate group name (No numbers, Unique check)
    if (empty($group_name)) {
        $error = "Group name is required.";
    } elseif (!preg_match("/^[a-zA-Z\s\p{P}]+$/u", $group_name)) {
        $error = "Group name must contain only letters, spaces, and special characters.";
    } else {
        // Check if group name already exists
        $stmt = $conn->prepare("SELECT group_id FROM groups WHERE group_name = ?");
        $stmt->bind_param("s", $group_name);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = "Group name already exists.";
        }
        $stmt->close();
    }

    // Validate nature is one of the allowed values
    $allowed_natures = ['Asset', 'Liability', 'Income', 'Expense'];
    if (!in_array($nature, $allowed_natures)) {
        $error = "Invalid nature selected.";
    }

    if (empty($error)) {
        $stmt = $conn->prepare("INSERT INTO groups (group_name, parent_group_id, nature) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $group_name, $parent_group_id, $nature);
        if ($stmt->execute()) {
            $success = "Group created successfully!";
            // Clear form fields by not repopulating them
            $group_name = '';
            $parent_group_id = NULL;
            $nature = '';

            // After inserting into DB successfully
            header("Location: create_group.php?success=1");
            exit;
            
        } else {
            $error = "Error creating group. Please try again.";
        }
        $stmt->close();
    }
}

// Fetch parent groups
$groups = [];
$result = $conn->query("SELECT * FROM groups");
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/form_style.css">
    <script>
    function updateNatureByParentGroup() {
            const groupDropdown = document.getElementById("parent_group_id");
            const selectedOption = groupDropdown.options[groupDropdown.selectedIndex];
            const nature = selectedOption.getAttribute("data-nature");
        
            if (nature) {
                document.getElementById("nature").value = nature;
            } else {
                console.log("No nature attribute found");
            }
        }

        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listener
            document.getElementById("parent_group_id").addEventListener("change", updateNatureByParentGroup);
            
            // Call it once to set initial value
            updateNatureByParentGroup();
        });

// Clear form when page is loaded (in case of back navigation)
document.addEventListener('DOMContentLoaded', function() {
    // Clear form fields
    document.getElementById('group_name').value = '';
    document.getElementById('parent_group_id').selectedIndex = 0;
    document.getElementById('nature').selectedIndex = 0;

    // Client-side validation
    document.querySelector('.group-form').addEventListener('submit', function(e) {
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
        
        const allowedNatures = ['Asset', 'Liability', 'Income', 'Expense'];
        if (!allowedNatures.includes(nature)) {
            e.preventDefault();
            alert('Please select a valid nature');
            return false;
        }
        
        return true;
    });
});

// Clear form when navigating back
window.addEventListener('pageshow', function(event) {
    if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
        document.getElementById('group_name').value = '';
        document.getElementById('parent_group_id').selectedIndex = 0;
        document.getElementById('nature').selectedIndex = 0;
    }
});


window.addEventListener("load", function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get("success") === "1") {
        document.getElementById("groupCreationform")?.reset();
        // You can also manually clear dropdowns, date pickers, etc.
    }
});

</script>

</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="../index.html">
                <img class="logo" src="../images/logo3.png" alt="Logo">
                <span>FinPack</span> 
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="../dashboards/dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </li>
            <li>
                <a href="#">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </a>
            </li>
            <li>
                <a href="../logout.php" style="color:rgb(235, 71, 53);">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-folder-plus"></i> Create Group</h2>
            </div>
            <?php if ($error) echo "<div class='error-message'><i class='fas fa-exclamation-circle'></i> $error</div>"; ?>
            <?php if ($success) echo "<div class='success-message'><i class='fas fa-check-circle'></i> $success</div>"; ?>
            <form method="POST" id = "groupCreationform"class="group-form" autocomplete="off">
                <div class="form-group">
                    <label for="group_name"><i class="fas fa-tag"></i> Group Name:</label>
                    <input type="text" id="group_name" name="group_name" required>
                </div>
                <div class="form-group">
                    <label for="parent_group_id"><i class="fas fa-sitemap"></i> Parent Group:</label>
                    <select id="parent_group_id" name="parent_group_id" onchange="updateNatureByParentGroup()">
                        <option value="">None</option>
                        <?php foreach ($groups as $group): ?>
                            <?php
                                $group_id = htmlspecialchars($group['group_id']);
                                $group_name = htmlspecialchars($group['group_name']);
                                $group_nature = isset($group['nature']) ? htmlspecialchars($group['nature']) : '';
                            ?>
                            <option value="<?= $group_id ?>" data-nature="<?= $group_nature ?>">
                                <?= $group_name ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="nature"><i class="fas fa-leaf"></i> Nature:</label>
                    <select id="nature" name="nature" required>
                        <option value="">None</option>
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