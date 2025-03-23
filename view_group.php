<?php
session_start();
include 'db_connection.php'; // Database connection

if (!isset($_SESSION['company_id'])) {
    die("Unauthorized access");
}

$company_id = $_SESSION['company_id'];

// Fetch all groups belonging to the company
$query = "SELECT * FROM groups WHERE company_id = ? OR company_id IS NULL ORDER BY group_name ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Display Groups</title>
    <link rel="stylesheet" href="../assets/styles.css"> <!-- Add CSS file for styling -->
</head>
<body>
    <h2>Group List</h2>
    <input type="text" id="search" placeholder="Search groups..." onkeyup="filterGroups()">
    <table border="1">
        <thead>
            <tr>
                <th>Group Name</th>
                <th>Nature</th>
                <th>Parent Group</th>
            </tr>
        </thead>
        <tbody id="groupTable">
            <?php while ($row = $result->fetch_assoc()) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['nature']); ?></td>
                    <td>
                        <?php
                        if ($row['parent_group_id']) {
                            $parentQuery = "SELECT group_name FROM groups WHERE group_id = ?";
                            $parentStmt = $conn->prepare($parentQuery);
                            $parentStmt->bind_param("i", $row['parent_group_id']);
                            $parentStmt->execute();
                            $parentResult = $parentStmt->get_result()->fetch_assoc();
                            echo htmlspecialchars($parentResult['group_name']);
                        } else {
                            echo "N/A";
                        }
                        ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <script>
        function filterGroups() {
            let input = document.getElementById("search").value.toLowerCase();
            let table = document.getElementById("groupTable");
            let rows = table.getElementsByTagName("tr");
            
            for (let i = 0; i < rows.length; i++) {
                let td = rows[i].getElementsByTagName("td")[0];
                if (td) {
                    let textValue = td.textContent || td.innerText;
                    rows[i].style.display = textValue.toLowerCase().includes(input) ? "" : "none";
                }
            }
        }
    </script>
</body>
</html>

<?php
$stmt->close();
$conn->close();
?>





