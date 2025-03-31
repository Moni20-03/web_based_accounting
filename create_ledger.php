<?php
include 'db_connection.php';
session_start();

$company_id = $_SESSION['company_id'];

// Fetch the next available account code
$acc_code_query = $conn->query("SELECT LPAD(COALESCE(MAX(CAST(acc_code AS UNSIGNED)) + 1, 1), 5, '0') AS new_acc_code FROM ledgers WHERE company_id = $company_id");
$acc_code_result = $acc_code_query->fetch_assoc();
$acc_code = $acc_code_result['new_acc_code'];

// Fetch total Debit and Credit balance
$balance_query = $conn->query("
    SELECT 
        SUM(CASE WHEN debit_credit = 'Debit' THEN opening_balance ELSE 0 END) AS total_debit,
        SUM(CASE WHEN debit_credit = 'Credit' THEN opening_balance ELSE 0 END) AS total_credit
    FROM ledgers WHERE company_id = $company_id
");
$balance_result = $balance_query->fetch_assoc();
$total_debit = $balance_result['total_debit'] ?? 0.00;
$total_credit = $balance_result['total_credit'] ?? 0.00;

// Predefined Group Nature Mapping
$group_nature = [
    "Capital Account" => "Liability",
    "Reserves & Surplus" => "Liability",
    "Current Liabilities" => "Liability",
    "Loans (Liability)" => "Liability",
    "Bank Accounts" => "Asset",
    "Cash-in-Hand" => "Asset",
    "Current Assets" => "Asset",
    "Fixed Assets" => "Asset",
    "Investments" => "Asset",
    "Branch/Divisions" => "Asset",
    "Direct Expenses" => "Expense",
    "Indirect Expenses" => "Expense",
    "Purchase Accounts" => "Expense",
    "Sales Accounts" => "Income",
    "Direct Incomes" => "Income",
    "Indirect Incomes" => "Income",
    "Duties & Taxes" => "Liability",
    "Provisions" => "Liability",
    "Secured Loans" => "Liability",
    "Unsecured Loans" => "Liability",
    "Stock-in-Hand" => "Asset",
    "Deposits (Asset)" => "Asset",
    "Sundry Debtors" => "Asset",
    "Sundry Creditors" => "Liability",
    "Loans & Advances (Asset)" => "Asset"
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $ledger_name = trim($_POST['ledger_name']);
    $group_id = $_POST['group_id'];
    $opening_balance = floatval($_POST['opening_balance']);
    $debit_credit = $_POST['debit_credit'];
    $book_type = $_POST['book_type'];

    // Fetch the group name and determine Account Type
    $group_query = $conn->prepare("SELECT group_name FROM groups WHERE group_id = ?");
    $group_query->bind_param("i", $group_id);
    $group_query->execute();
    $group_result = $group_query->get_result();
    $group_data = $group_result->fetch_assoc();
    $group_name = $group_data['group_name'];
    $acc_type = $group_nature[$group_name] ?? "Other";

    // Determine Group Direct (D for Balance Sheet accounts, G for Profit & Loss)
    $group_direct = in_array($acc_type, ["Asset", "Liability"]) ? "D" : "G";

    // Check if ledger name already exists
    $check_ledger = $conn->prepare("SELECT * FROM ledgers WHERE ledger_name = ? AND company_id = ?");
    $check_ledger->bind_param("si", $ledger_name, $company_id);
    $check_ledger->execute();
    $result = $check_ledger->get_result();

    if ($result->num_rows > 0) {
        echo "<script>alert('Ledger name already exists for this company. Choose a different name.');</script>";
    } else {
        // Fetch the last balance
        $last_balance_query = $conn->query("
            SELECT SUM(CASE WHEN debit_credit = 'Debit' THEN current_balance ELSE -current_balance END) AS last_balance 
            FROM ledgers WHERE company_id = $company_id
        ");
        $last_balance_result = $last_balance_query->fetch_assoc();
        $last_balance = $last_balance_result['last_balance'] ?? 0.00;

        // Set current balance logic
        $current_balance = ($debit_credit === 'Debit') ? $last_balance + $opening_balance : $last_balance - $opening_balance;

        // Insert new ledger
        $stmt = $conn->prepare("
            INSERT INTO ledgers (company_id, acc_code, ledger_name, group_id, acc_type, book_type, opening_balance, current_balance, debit_credit, group_direct, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isssssdsss", $company_id, $acc_code, $ledger_name, $group_id, $acc_type, $book_type, $opening_balance, $current_balance, $debit_credit, $group_direct);

        if ($stmt->execute()) {
            echo "<script>alert('Ledger created successfully!'); window.location.href='create_ledger.php';</script>";
        } else {
            echo "<script>alert('Error creating ledger.');</script>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Ledger</title>
    <link rel="stylesheet" href="styles.css">
    <script>
        function updateAccountType() {
            let groupDropdown = document.querySelector("select[name='group_id']");
            let selectedGroup = groupDropdown.options[groupDropdown.selectedIndex].text;
            let accountTypeField = document.getElementById("account_type");
            let groupMapping = JSON.parse('<?php echo json_encode($group_nature); ?>');
            accountTypeField.value = groupMapping[selectedGroup] || "Other";
        }

        function updateBalances() {
            let openingBalance = parseFloat(document.getElementById("opening_balance").value) || 0;
            let totalDebit = parseFloat(document.getElementById("total_debit").value) || 0;
            let totalCredit = parseFloat(document.getElementById("total_credit").value) || 0;
            let debitCredit = document.querySelector('input[name="debit_credit"]:checked')?.value;

            let updatedDebit = totalDebit;
            let updatedCredit = totalCredit;

            if (debitCredit === "Debit") {
                updatedDebit += openingBalance;
            } else if (debitCredit === "Credit") {
                updatedCredit += openingBalance;
            }

            let difference = Math.abs(updatedDebit - updatedCredit);

            document.getElementById("updated_debit").value = updatedDebit.toFixed(2);
            document.getElementById("updated_credit").value = updatedCredit.toFixed(2);
            document.getElementById("difference").value = difference.toFixed(2);
        }
    </script>
</head>
<body>
    <h2>Create New Ledger</h2>
    <form method="POST">
        <label>Ledger Name:</label>
        <input type="text" name="ledger_name" required>

        <label>Account Code:</label>
        <input type="text" value="<?php echo $acc_code; ?>" disabled>

        <label>Group:</label>
        <select name="group_id" required onchange="updateAccountType()">
            <option value="">Select Group</option>
            <?php
            $groups = $conn->query("SELECT * FROM groups WHERE company_id = $company_id || company_id IS NULL");
            while ($row = $groups->fetch_assoc()) {
                echo "<option value='{$row['group_id']}'>{$row['group_name']}</option>";
            }
            ?>
        </select>

        <label>Account Type:</label>
        <input type="text" id="account_type" name="account_type" readonly>

        <label>Opening Balance:</label>
        <input type="number" id="opening_balance" name="opening_balance" step="0.01" min="0" value="0" oninput="updateBalances()">


        <label>Book Type:</label>
        <input type="radio" name="book_type" value="Bank" required > Bank
        <input type="radio" name="book_type" value="Cash" required > Cash


        <label>Debit / Credit:</label>
        <input type="radio" name="debit_credit" value="Debit" required onclick="updateBalances()"> Debit
        <input type="radio" name="debit_credit" value="Credit" required onclick="updateBalances()"> Credit

        <label>Total Debit:</label>
        <input type="text" id="total_debit" value="<?php echo number_format($total_debit, 2); ?>" disabled>

        <label>Total Credit:</label>
        <input type="text" id="total_credit" value="<?php echo number_format($total_credit, 2); ?>" disabled>

        <label>Difference:</label>
        <input type="text" id="difference" value="<?php echo number_format(abs($total_debit - $total_credit), 2); ?>" disabled>

        <button type="submit">Create Ledger</button>
    </form>
</body>
</html>