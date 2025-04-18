<?php
include '../database/../database/findb.php'; // Database connection

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
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
$query = "SELECT g.*, p.group_name as parent_group_name 
          FROM groups g 
          LEFT JOIN groups p ON g.parent_group_id = p.group_id 
          WHERE g.group_id = ?";
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Group</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../styles/form_style.css">
    <style>
    /* View Page Specific Styles */
.view-form .form-group {
    margin-bottom: 1.5rem;
}

.view-form label {
    display: block;
    font-weight: 500;
    color: var(--primary-dark);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.view-field {
    padding: 12px 15px;
    background-color: #f8f9fa;
    border-radius: 5px;
    border: 1px solid #e9ecef;
    font-size: 1rem;
    color: var(--text-dark);
    transition: all 0.3s ease;
    position: relative;
}

.view-field:hover {
    background-color: #e9ecef;
    border-color: var(--primary-light);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

/* Optional: Add a subtle left border accent on hover */
.view-field:hover::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background-color: var(--primary-light);
    border-radius: 3px 0 0 3px;
}

/* For the "None" state to make it visually different */
.view-field:empty::after {
    content: 'None';
    color: #6c757d;
    font-style: italic;
}

.edit-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: var(--primary-light);
    text-decoration: none;
    font-size: 0.9rem;
    margin-left: 15px;
    transition: color 0.2s ease;
}

.edit-link:hover {
    color: var(--secondary-color);
    text-decoration: underline;
}

.submit-button
{
    text-decoration: none;
    width: 40%;
    margin-left:110px;
}

</style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="../../index.html">
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
        <div class="form-container" style="margin-top:3rem;">
            <div class="form-header">
                <h2><i class="fas fa-eye"></i> View Group Details</h2>
                <a href="edit_group.php?group_id=<?= $group_id ?>" class="edit-link">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class='error-message'><i class='fas fa-exclamation-circle'></i> <?= htmlspecialchars($_SESSION['error_message']) ?></div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
            
            <div class="view-form">
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Group Name:</label>
                    <div class="view-field"><?= htmlspecialchars($group['group_name']) ?></div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-sitemap"></i> Parent Group:</label>
                    <div class="view-field">
                        <?= $group['parent_group_name'] ? htmlspecialchars($group['parent_group_name']) : 'None' ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-leaf"></i> Nature:</label>
                    <div class="view-field"><?= htmlspecialchars($group['nature']) ?></div>
                </div>
                        
                <div class="form-actions">
                    <a href="search_group.php" class="submit-button">
                        <i class="fas fa-arrow-left"></i> Back to Groups
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>