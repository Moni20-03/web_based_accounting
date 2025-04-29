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
    <link rel="stylesheet" href="../styles/search_style.css">
</head>
<body>
    <?php 
        include('../navbar.php');
   ?>

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
