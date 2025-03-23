<?php
session_start();
include 'db_connection.php'; // Database connection

$company_id = $_SESSION['company_id']; // Get logged-in company ID

// Fetch all groups for this company + predefined groups
$sql = "SELECT * FROM groups WHERE company_id = ? OR company_id IS NULL ORDER BY group_name ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $company_id);
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
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
        }

        .sidebar-header {
            text-align: center;
            margin-bottom: 20px;
            color: #ffffff;
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
            margin-left: 250px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        /* Groups Container */
        .groups-container {
            background: #ffffff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            display: flex;
            flex-direction: column;
            height: 500px; /* Fixed height */
        }

        .groups-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .groups-header h2 {
            font-size: 22px;
            color: #2c3e50;
        }

        /* Search Bar */
        .search-bar {
            display: flex;
            align-items: center;
        }

        .search-bar input {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px 0 0 8px;
            font-size: 14px;
            width: 250px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .search-bar input:focus {
            border-color: #8e44ad;
            box-shadow: 0 0 5px rgba(142, 68, 173, 0.5);
            outline: none;
        }

        .search-bar button {
            padding: 10px 15px;
            background: #8e44ad;
            color: #ffffff;
            border: none;
            border-radius: 0 8px 8px 0;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .search-bar button:hover {
            background: #732d91;
        }

        /* Groups Table */
        .groups-table-container {
            flex: 1;
            overflow-y: auto; /* Enable vertical scroll */
            border-radius: 8px;
        }

        .groups-table {
            width: 100%;
            border-collapse: collapse;
        }

        .groups-table th, .groups-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .groups-table th {
            background: #8e44ad;
            color: #ffffff;
            position: sticky;
            top: 0; /* Keeps the header sticky while scrolling */
        }

        .groups-table tr:hover {
            background: #f9f9f9;
        }

        /* Action Buttons */
        .action-button {
            padding: 8px 12px;
            border-radius: 5px;
            font-size: 14px;
            text-decoration: none;
            margin-right: 10px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s ease, transform 0.3s ease;
        }

        .action-button.edit {
            background: #3498db;
            color: #ffffff;
        }

        .action-button.edit:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .action-button.view {
            background: #2ecc71;
            color: #ffffff;
        }

        .action-button.view:hover {
            background: #27ae60;
            transform: translateY(-2px);
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

            .main-content {
                margin-left: 60px;
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
        <a href="create_group.php" class="sidebar-link"><i class="fas fa-folder-plus"></i> Create Group</a>
        <a href="logout.php" class="sidebar-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="groups-container">
        <div class="groups-header">
            <h2><i class="fas fa-list"></i> Manage Groups</h2>
            <div class="search-bar">
                <input type="text" id="search" class = "search-bar"placeholder="Search groups...">
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
                        <th>Actions</th>
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
    document.querySelectorAll("#groupTable tr").forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(searchValue) ? "" : "none";
    });
});
</script>

</body>
</html>
