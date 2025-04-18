<?php
include '../database/../database/findb.php'; // Database connection

// Fetch all groups for this company + predefined groups
$sql = "SELECT * FROM groups ORDER BY group_name ASC";
$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
$groups = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Groups</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../styles/form_style.css">
    <style>
        /* Main Content Styles */
.main-content {
    padding: 2rem;
    max-width: 800px;
    margin: 0 auto;
}

/* Groups Container */
.groups-container {
    background-color: var(--white);
    border-radius: 10px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15), 
                0 2px 6px rgba(0, 0, 0, 0.1);
    padding: 2rem;
    margin-top: 2rem;
    border: 1px solid rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    height: 60vh; /* Fixed height for scrollable container */
}

.groups-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
    flex-shrink: 0; /* Prevent header from shrinking */
}

.groups-header h2 {
    font-size: 1.5rem;
    color: var(--primary-dark);
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

/* Search Bar */
.search-bar {
    display: flex;
    align-items: center;
    background: var(--light-bg);
    border-radius: 30px;
    padding: 0.5rem 1rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    width: 300px;
    flex-shrink: 0;
}

.search-bar input {
    border: none;
    background: transparent;
    padding: 0.5rem;
    width: 100%;
    outline: none;
    font-size: 0.9rem;
    font-family: 'Poppins', sans-serif;
}

.search-bar button {
    background: transparent;
    border: none;
    color: var(--primary-light);
    cursor: pointer;
    font-size: 1rem;
}

/* Scrollable Table Container */
.groups-table-container {
    overflow: auto; /* Enables scrolling */
    flex-grow: 1; /* Takes remaining space */
    position: relative;
    margin-top: 1rem;
}

/* Table Styling */
.groups-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 600px; /* Minimum table width */
}

.groups-table thead {
    display: table-header-group; /* Ensures header always stays visible */
    background-color: var(--primary-dark);
    color: var(--white);
    position: sticky;
    top: 0;
    z-index: 10;
}

.groups-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.9rem;
    letter-spacing: 0.5px;
    /* text-align: center; */
}

.groups-table tbody
{
    display: table-row-group;
}

.groups-table tbody tr {
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    transition: background-color 0.2s ease;
}

.groups-table tbody tr:hover {
    background-color: rgba(16, 231, 188, 0.15);
}

.groups-table td {
    padding: 1rem;
    color: var(--text-dark);
}

/* Action Buttons */
.action-button {
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-size: 0.9rem;
    margin-right: 0.5rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    text-decoration: none;
    transition: all 0.2s ease;
}

.action-button.edit {
    background-color: rgba(52, 152, 219, 0.1);
    color: var(--primary-light);
    border: 1px solid rgba(52, 152, 219, 0.3);
}

.action-button.edit:hover {
    background-color: rgba(52, 152, 219, 0.2);
}

.action-button.view {
    background-color: rgba(46, 204, 113, 0.1);
    color: var(--success);
    border: 1px solid rgba(46, 204, 113, 0.3);
}

.action-button.view:hover {
    background-color: rgba(46, 204, 113, 0.2);
}

/* Responsive Design */
@media (max-width: 768px) {
    .groups-container {
        height: auto;
        max-height: 80vh;
        padding: 1rem;
    }
    
    .groups-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .search-bar {
        width: 100%;
    }
    
    .groups-table th, 
    .groups-table td {
        padding: 0.75rem 0.5rem;
        font-size: 0.85rem;
    }
    
    .action-button {
        padding: 0.4rem 0.8rem;
        margin-bottom: 0.3rem;
    }
}
    </style>
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
    <div class="groups-container">
        <div class="groups-header">
            <h2><i class="fas fa-list"></i> Manage Groups</h2>
            <div class="search-bar">
                <input type="text" id="search" class = "search-bar" placeholder="Search groups...">
                <button><i class="fas fa-search"></i></button>
            </div>
        </div>
        <div class="groups-table-container">
            <table class="groups-table" id="groupTable">
            <thead>
                    <tr>
                        <th>Group Name</th>
                        <!-- <th>Parent Group</th> -->
                        <th>Nature</th>
                        <th style="padding-left:10%;">Actions</th>
                    </tr>
            </thead>
            <tbody id="groupList">
                    <?php foreach ($groups as $group) { ?>
                <tr>
                    <td><?= htmlspecialchars($group['group_name']) ?></td>
                    <!-- <td><?= htmlspecialchars($group['parent_group_id']) ?></td> -->
                    <td><?= htmlspecialchars($group['nature']) ?></td>
                    <td>
                        <a href="edit_group.php?group_id=<?= $group['group_id']; ?>" class="action-button edit"><i class="fas fa-edit"></i> Edit</a>
                        <a href="view_group.php?group_id=<?= $group['group_id']; ?>" class="action-button view"><i class="fas fa-eye"></i> View</a>
        
                    </td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById("search").addEventListener("input", function () {
    let searchValue = this.value.toLowerCase();
    document.querySelectorAll("#groupTable tbody tr").forEach(row => {
        // Skip the header row (already handled by only selecting tbody rows)
        row.style.display = row.textContent.toLowerCase().includes(searchValue) ? "" : "none";
    });
    
    // Always keep the header visible
    document.querySelector("#groupTable thead").style.display = "";
});
</script>

</body>
</html>
