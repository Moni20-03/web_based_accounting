<?php
include '../database/findb.php';

// Get date for balance sheet
$as_on_date = $_GET['as_on_date'] ?? date('Y-m-d');

// Pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20; // Items per page
$offset = ($page - 1) * $per_page;

// Step 1: Fetch all groups (Asset / Liability)
$groups = [];
$res = $conn->query("SELECT group_id, group_name, nature FROM groups");
while ($row = $res->fetch_assoc()) {
    $groups[$row['group_id']] = [
        'group_id' => $row['group_id'],
        'group_name' => $row['group_name'],
        'nature' => $row['nature'], // 'Asset' or 'Liability'
        'ledgers' => []
    ];
}

// Step 2: Fetch ledger balances with pagination
$ledgers_query = $conn->query("
    SELECT l.ledger_id, l.ledger_name, l.group_id, l.opening_balance,
           IFNULL(SUM(
                CASE WHEN t.transaction_type = 'Debit' THEN t.amount
                     WHEN t.transaction_type = 'Credit' THEN -t.amount
                END
           ), 0) as transaction_total
    FROM ledgers l
    LEFT JOIN transactions t ON l.ledger_id = t.ledger_id AND t.transaction_date <= '$as_on_date'
    GROUP BY l.ledger_id
    LIMIT $per_page OFFSET $offset
");

// Get total count for pagination
$total_count = $conn->query("SELECT COUNT(*) as total FROM ledgers")->fetch_assoc()['total'];
$total_pages = ceil($total_count / $per_page);

$total_opening_balance = 0;

while ($row = $ledgers_query->fetch_assoc()) {
    $closing_balance = $row['opening_balance'] + $row['transaction_total'];

    if (!isset($groups[$row['group_id']])) continue; // Skip orphan ledgers

    $groups[$row['group_id']]['ledgers'][] = [
        'ledger_id' => $row['ledger_id'],
        'ledger_name' => $row['ledger_name'],
        'closing_balance' => $closing_balance
    ];

    $total_opening_balance += $row['opening_balance'];
}

// Step 3: Separate into assets and liabilities
$assets = [];
$liabilities = [];

foreach ($groups as $group) {
    if (empty($group['ledgers'])) continue;

    if ($group['nature'] === 'Asset') {
        $assets[] = $group;
    } elseif ($group['nature'] === 'Liability') {
        $liabilities[] = $group;
    }
}

// Step 4: Calculate grand totals
function calculate_total($groups) {
    $total = 0;
    foreach ($groups as $group) {
        foreach ($group['ledgers'] as $ledger) {
            $total += $ledger['closing_balance'];
        }
    }
    return $total;
}

$total_assets = calculate_total($assets);
$total_liabilities = calculate_total($liabilities);

// Step 5: Calculate difference
$difference = $total_assets - $total_liabilities;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Sheet</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        th {
            background-color: #f0f0f0;
            text-align: left;
        }
        .amount {
            text-align: right;
        }
        .clickable-row {
            cursor: pointer;
        }
        .clickable-row:hover {
            background-color: #f5f5f5;
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
        }
        .pagination a {
            margin: 0 5px;
            padding: 5px 10px;
            border: 1px solid #ddd;
            text-decoration: none;
            color: #333;
        }
        .pagination a.active {
            background-color: #1A2A57;
            color: white;
        }
        .difference-row {
            background-color: #ffe6e6;
            color: red;
        }
    </style>
</head>
<body>
    <h2 style="text-align: center;">Balance Sheet as on <?= date('d-m-Y', strtotime($as_on_date)) ?></h2>

    <div style="margin-bottom: 20px;">
        <form method="get">
            <label for="as_on_date">As on Date:</label>
            <input type="date" name="as_on_date" value="<?= htmlspecialchars($as_on_date) ?>" required>
            <button type="submit">Generate</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>Liabilities</th>
                <th class="amount">Amount (₹)</th>
                <th>Assets</th>
                <th class="amount">Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $max_rows = max(count($liabilities), count($assets));
            for ($i = 0; $i < $max_rows; $i++) {
                echo "<tr>";

                // Liabilities side
                if (isset($liabilities[$i])) {
                    $group_id = $liabilities[$i]['group_id'];
                    echo "<td class='clickable-row' onclick=\"window.location.href='group_summary.php?group_id=$group_id'\"><strong>{$liabilities[$i]['group_name']}</strong>";
                    foreach ($liabilities[$i]['ledgers'] as $ledger) {
                        $ledger_id = $ledger['ledger_id'];
                        echo "<div class='clickable-row' style='margin-left: 15px;' onclick=\"window.location.href='ledger_summary.php?ledger_id=$ledger_id'\">{$ledger['ledger_name']}</div>";
                    }
                    echo "</td><td class='amount'>";
                    $group_total = array_sum(array_column($liabilities[$i]['ledgers'], 'closing_balance'));
                    echo number_format($group_total, 2);
                    echo "</td>";
                } else {
                    echo "<td></td><td></td>";
                }

                // Assets side
                if (isset($assets[$i])) {
                    $group_id = $assets[$i]['group_id'];
                    echo "<td class='clickable-row' onclick=\"window.location.href='group_summary.php?group_id=$group_id'\"><strong>{$assets[$i]['group_name']}</strong>";
                    foreach ($assets[$i]['ledgers'] as $ledger) {
                        $ledger_id = $ledger['ledger_id'];
                        echo "<div class='clickable-row' style='margin-left: 15px;' onclick=\"window.location.href='ledger_summary.php?ledger_id=$ledger_id'\">{$ledger['ledger_name']}</div>";
                    }
                    echo "</td><td class='amount'>";
                    $group_total = array_sum(array_column($assets[$i]['ledgers'], 'closing_balance'));
                    echo number_format($group_total, 2);
                    echo "</td>";
                } else {
                    echo "<td></td><td></td>";
                }

                echo "</tr>";
            }
            ?>

            <!-- Grand Total Row -->
            <tr style="background-color: #f0f0f0; font-weight: bold;">
                <td>Total</td>
                <td class="amount"><?= number_format($total_liabilities, 2) ?></td>
                <td>Total</td>
                <td class="amount"><?= number_format($total_assets, 2) ?></td>
            </tr>

            <!-- Difference Row (if any) -->
            <?php if (abs($difference) > 0.01) { ?>
            <tr class="difference-row">
                <td colspan="2" style="text-align: center;">
                    Difference in Opening Balance: <?= ($difference < 0) ? number_format(abs($difference),2) . ' Cr' : number_format(abs($difference),2) . ' Dr' ?>
                </td>
                <td colspan="2" style="text-align: center;">Please check ledgers!</td>
            </tr>
            <?php } ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">First</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Prev</a>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page - 2); $i <= min($page + 2, $total_pages); $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" <?= $i === $page ? 'class="active"' : '' ?>>
                <?= $i ?>
            </a>
        <?php endfor; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">Last</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <script>
        // Make rows clickable
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('.clickable-row');
            rows.forEach(row => {
                row.addEventListener('click', function(e) {
                    // Don't navigate if clicking on a link inside the cell
                    if (e.target.tagName !== 'A' && e.target.tagName !== 'BUTTON') {
                        window.location.href = this.getAttribute('data-href') || this.parentElement.getAttribute('data-href');
                    }
                });
            });
        });
    </script>
</body>
</html>