<?php
include 'db_connection.php';

// Update Group
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_group'])) {
    $group_id = $_POST['group_id'];
    $group_name = $_POST['group_name'];
    $parent_group_id = $_POST['parent_group_id'] ?: NULL;
    $group_type = $_POST['group_type'];
    $description = $_POST['description'];

    $stmt = $conn->prepare("UPDATE groups SET group_name=?, parent_group_id=?, group_type=?, description=? WHERE group_id=?");
    $stmt->bind_param("sissi", $group_name, $parent_group_id, $group_type, $description, $group_id);
    
    if ($stmt->execute()) {
        echo "<script>alert('Group Updated Successfully'); window.location.href='alter_group.php';</script>";
    }
    $stmt->close();
}

// Fetch Groups
$groups = $conn->query("SELECT * FROM groups ORDER BY group_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Alter Group</title>
    <script>
        function loadGroupDetails() {
            let selectedGroup = document.getElementById("group_id").value;
            fetch("fetch_group.php?group_id=" + selectedGroup)
                .then(response => response.json())
                .then(data => {
                    document.getElementById("edit_group_name").value = data.group_name;
                    document.getElementById("edit_parent_group_id").value = data.parent_group_id;
                    document.getElementById("edit_group_type").value = data.group_type;
                    document.getElementById("edit_description").value = data.description;
                });
        }
    </script>
</head>
<body>
    <h2>Alter Group</h2>
    <form method="POST">
        <label>Select Group:</label>
        <select name="group_id" id="group_id" onchange="loadGroupDetails()" required>
            <option value="">Select Group</option>
            <?php while ($row = $groups->fetch_assoc()): ?>
                <option value="<?= $row['group_id'] ?>"><?= $row['group_name'] ?></option>
            <?php endwhile; ?>
        </select><br>

        <label>Group Name:</label>
        <input type="text" id="edit_group_name" name="group_name" required><br>

        <label>Parent Group:</label>
        <select name="parent_group_id" id="edit_parent_group_id">
            <option value="">None</option>
            <?php 
            $groups->data_seek(0);
            while ($row = $groups->fetch_assoc()): ?>
                <option value="<?= $row['group_id'] ?>"><?= $row['group_name'] ?></option>
            <?php endwhile; ?>
        </select><br>

        <label>Group Type:</label>
        <select name="group_type" id="edit_group_type" required>
            <option value="Primary">Primary</option>
            <option value="Subgroup">Subgroup</option>
        </select><br>

        <label>Description:</label>
        <textarea name="description" id="edit_description"></textarea><br>

        <button type="submit" name="update_group">Update Group</button>
    </form>
</body>
</html>
