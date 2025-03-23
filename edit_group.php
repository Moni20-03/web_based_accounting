<?php
session_start();
require 'db_connection.php';

if (!isset($_GET['group_id']) || empty($_GET['group_id'])) {
    die("Invalid Request! Group ID missing in URL.");
} else {
    echo "Group ID received: " . $_GET['group_id'];
}


$group_id = intval($_GET['group_id']); // Ensure it's an integer
$company_id = $_SESSION['company_id']; // Fetch from session

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $group_name = trim($_POST['group_name']);
    $parent_group_id = $_POST['parent_group_id'] ?: NULL;
    $nature = $_POST['nature'];

    // Validate name (no numbers allowed, must be unique within the company)
    if (!preg_match("/^[a-zA-Z\s\p{P}]+$/u", $group_name)) {
        echo "Group name must contain only letters, spaces, and special characters.";
        exit;
    }
    
    $checkQuery = "SELECT group_id FROM groups WHERE group_name = ? AND company_id = ? AND group_id != ?";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("sii", $group_name, $company_id, $group_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo "Group name already exists.";
        exit;
    }
    $stmt->close();

    // Update group details
    $updateQuery = "UPDATE groups SET group_name = ?, parent_group_id = ?, nature = ?, company_id = ? WHERE group_id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("sssii", $group_name, $parent_group_id, $nature, $company_id, $group_id);
    if ($stmt->execute()) {
        echo "Group updated successfully.";
    } else {
        echo "Error updating group.";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Group</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <h2>Edit Group</h2>
    <form method="POST">
        <label>Group Name:</label>
        <input type="text" name="group_name" value="<?php echo htmlspecialchars($group['group_name']); ?>" required>

        <label>Parent Group:</label>
        <select name="parent_group_id">
            <option value="">None</option>
            <?php while ($row = $parentGroups->fetch_assoc()) { ?>
                <option value="<?php echo $row['group_id']; ?>" <?php echo ($group['parent_group_id'] == $row['group_id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($row['group_name']); ?>
                </option>
            <?php } ?>
        </select>

        <label>Nature:</label>
        <select name="nature" required>
            <option value="Asset" <?php if ($group['nature'] == 'Asset') echo 'selected'; ?>>Asset</option>
            <option value="Liability" <?php if ($group['nature'] == 'Liability') echo 'selected'; ?>>Liability</option>
            <option value="Income" <?php if ($group['nature'] == 'Income') echo 'selected'; ?>>Income</option>
            <option value="Expense" <?php if ($group['nature'] == 'Expense') echo 'selected'; ?>>Expense</option>
        </select>

        <button type="submit">Update Group</button>
    </form>
</body>
</html>
