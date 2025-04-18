<?php
include '../database/findb.php';

$as_on_date = $_GET['as_on_date'] ?? date('Y-m-d');

// Fetch grouped ledgers
function fetchGroupBalance($conn, $nature, $as_on_date) {
    $query = "
        SELECT g.group_name, l.ledger_id, l.ledger_name,
            SUM(CASE WHEN t.transaction_type = 'Debit' THEN t.amount ELSE 0 END) AS total_dr,
            SUM(CASE WHEN t.transaction_type = 'Credit' THEN t.amount ELSE 0 END) AS total_cr
        FROM ledgers l
        JOIN groups g ON l.group_id = g.group_id
        LEFT JOIN transactions t ON t.ledger_id = l.ledger_id
        LEFT JOIN vouchers v ON v.voucher_id = t.voucher_id AND v.voucher_date <= ?
        WHERE g.nature = ?
        GROUP BY l.ledger_id
        ORDER BY g.group_name, l.ledger_name
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $as_on_date, $nature);
    $stmt->execute();
    $result = $stmt->get_result();

    $groups = [];
    while ($row = $result->fetch_assoc()) {
        $balance = $row['total_dr'] - $row['total_cr'];
        if (!isset($groups[$row['group_name']])) {
            $groups[$row['group_name']] = [];
        }
        $groups[$row['group_name']][] = [
            'ledger_name' => $row['ledger_name'],
            'balance' => $balance
        ];
    }
    return $groups;
}

// Calculate total
function calculateTotal($groups) {
    $total = 0;
    foreach ($groups as $ledgers) {
        foreach ($ledgers as $ledger) {
            $total += $ledger['balance'];
        }
    }
    return $total;
}

// Get assets & liabilities
$asset_groups = fetchGroupBalance($conn, 'Asset', $as_on_date);
$liability_groups = fetchGroupBalance($conn, 'Liability', $as_on_date);

$total_assets = calculateTotal($asset_groups);
$total_liabilities = calculateTotal($liability_groups);
$net_worth = $total_assets - $total_liabilities;
?>

<h2>🧾 Balance Sheet</h2>

<form method="get" style="margin-bottom: 20px;">
    <label>As on:</label>
    <input type="date" name="as_on_date" value="<?= $as_on_date ?>" required>
    <button type="submit">Show</button>
    <button type="button" onclick="window.print()">🖨️ Print</button>
</form>

<table border="1" width="100%" cellpadding="8" cellspacing="0">
    <thead>
        <tr style="background-color: #e0e0e0;">
            <th width="50%">Liabilities</th>
            <th align="right">Amount (₹)</th>
            <th width="50%">Assets</th>
            <th align="right">Amount (₹)</th>
        </tr>
    </thead>
    <tbody>
        <?php
        // Get max number of rows to align columns
        $max_rows = max(count($asset_groups, COUNT_RECURSIVE), count($liability_groups, COUNT_RECURSIVE));
        $rows = max(count($asset_groups), count($liability_groups));
        $liability_rows = [];
        $asset_rows = [];

        foreach ($liability_groups as $group => $ledgers) {
            $liability_rows[] = ["<strong>$group</strong>", ''];
            foreach ($ledgers as $ledger) {
                $liability_rows[] = [$ledger['ledger_name'], number_format($ledger['balance'], 2)];
            }
        }

        foreach ($asset_groups as $group => $ledgers) {
            $asset_rows[] = ["<strong>$group</strong>", ''];
            foreach ($ledgers as $ledger) {
                $asset_rows[] = [$ledger['ledger_name'], number_format($ledger['balance'], 2)];
            }
        }

        $row_count = max(count($liability_rows), count($asset_rows));
        for ($i = 0; $i < $row_count; $i++) {
            echo "<tr>";
            echo "<td>" . ($liability_rows[$i][0] ?? '') . "</td>";
            echo "<td align='right'>" . ($liability_rows[$i][1] ?? '') . "</td>";
            echo "<td>" . ($asset_rows[$i][0] ?? '') . "</td>";
            echo "<td align='right'>" . ($asset_rows[$i][1] ?? '') . "</td>";
            echo "</tr>";
        }
        ?>
        <tr style="font-weight: bold; background-color: #f9f9f9;">
            <td align="right">Total Liabilities</td>
            <td align="right"><?= number_format($total_liabilities, 2) ?></td>
            <td align="right">Total Assets</td>
            <td align="right"><?= number_format($total_assets, 2) ?></td>
        </tr>
        <tr style="font-weight: bold; background-color: #e7f4e4;">
            <td colspan="3" align="right">🧾 Net Worth (Owner's Equity)</td>
            <td align="right"><?= number_format($net_worth, 2) ?></td>
        </tr>
        <?php if ($net_worth < 0): ?>
            <tr><td colspan="4" style="color:red; font-weight:bold;" align="center">⚠️ Warning: Net Worth is Negative!</td></tr>
        <?php endif; ?>
    </tbody>
</table>
