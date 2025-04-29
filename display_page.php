<?php
include '../finpack_system/database/findb.php';

$as_on_date = $_GET['as_on_date'] ?? date('Y-m-d');
$group_id = $_GET['group_id'] ?? null;
$ledger_id = $_GET['ledger_id'] ?? null;

// Function to get all groups with their ledgers
function get_groups_with_ledgers($conn, $as_on_date) {
    $groups = [];
    
    $query = $conn->query("
        SELECT g.group_id, g.group_name, g.nature
        FROM groups g
        ORDER BY g.group_name
    ");
    
    while ($group = $query->fetch_assoc()) {
        $group_id = $group['group_id'];
        
        // Get ledgers for this group
        $ledgers_query = $conn->query("
            SELECT l.ledger_id, l.ledger_name,
                   (l.opening_balance + IFNULL((
                       SELECT SUM(CASE 
                                WHEN t.transaction_type = 'Debit' AND g.nature = 'Asset' THEN t.amount
                                WHEN t.transaction_type = 'Credit' AND g.nature = 'Asset' THEN -t.amount
                                WHEN t.transaction_type = 'Credit' AND g.nature = 'Liability' THEN t.amount
                                WHEN t.transaction_type = 'Debit' AND g.nature = 'Liability' THEN -t.amount
                                WHEN t.transaction_type = 'Debit' AND g.nature = 'Expense' THEN t.amount
                                WHEN t.transaction_type = 'Credit' AND g.nature = 'Expense' THEN -t.amount
                                WHEN t.transaction_type = 'Credit' AND g.nature = 'Income' THEN t.amount
                                WHEN t.transaction_type = 'Debit' AND g.nature = 'Income' THEN -t.amount
                            END)
                       FROM transactions t 
                       WHERE t.ledger_id = l.ledger_id AND t.transaction_date <= '$as_on_date'
                   ), 0)) as balance
            FROM ledgers l
            JOIN groups g ON l.group_id = g.group_id
            WHERE l.group_id = $group_id
            ORDER BY l.ledger_name
        ");
        
        $ledgers = [];
        while ($ledger = $ledgers_query->fetch_assoc()) {
            $ledgers[] = $ledger;
        }
        
        $group['ledgers'] = $ledgers;
        $groups[] = $group;
    }
    
    return $groups;
}

// Function to get vouchers for a ledger
function get_vouchers_for_ledger($conn, $ledger_id, $as_on_date) {
    $vouchers = [];
    
    $query = $conn->query("
        SELECT v.voucher_id, v.voucher_number, v.voucher_date, v.voucher_type,
               t.transaction_type, t.amount, t.narration
        FROM transactions t
        JOIN vouchers v ON t.voucher_id = v.voucher_id
        WHERE t.ledger_id = $ledger_id AND v.voucher_date <= '$as_on_date'
        ORDER BY v.voucher_date DESC, v.voucher_number DESC
    ");
    
    while ($voucher = $query->fetch_assoc()) {
        $vouchers[] = $voucher;
    }
    
    return $vouchers;
}

$groups = get_groups_with_ledgers($conn, $as_on_date);
$selected_ledger_vouchers = $ledger_id ? get_vouchers_for_ledger($conn, $ledger_id, $as_on_date) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Accounting Explorer</title>
    <style>
        :root {
            --primary-blue: #1A2A57;
            --accounting-green: #4CAF50;
            --light-gray: #F5F7FA;
            --white: #FFFFFF;
            --border-gray: #E0E0E0;
        }

        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background-color: var(--light-gray);
            color: var(--primary-blue);
            margin: 0;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 20px auto;
            background-color: var(--white);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            padding: 30px;
            display: flex;
            gap: 20px;
        }

        .sidebar {
            width: 300px;
            border-right: 1px solid var(--border-gray);
            padding-right: 20px;
        }

        .content {
            flex: 1;
        }

        .filter-form {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .tree-view {
            list-style: none;
            padding-left: 0;
        }

        .tree-view ul {
            list-style: none;
            padding-left: 20px;
        }

        .tree-item {
            padding: 8px 0;
            cursor: pointer;
            transition: all 0.2s;
        }

        .tree-item:hover {
            color: var(--primary-blue);
            font-weight: 500;
        }

        .group-item {
            font-weight: bold;
            color: var(--primary-blue);
        }

        .ledger-item {
            padding-left: 20px;
            color: #555;
        }

        .voucher-item {
            padding-left: 40px;
            color: #777;
            font-size: 14px;
        }

        .active {
            background-color: rgba(26, 42, 87, 0.1);
            border-radius: 4px;
            padding: 8px 10px;
        }

        .voucher-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .voucher-table th {
            background-color: var(--primary-blue);
            color: white;
            padding: 10px 15px;
            text-align: left;
        }

        .voucher-table td {
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .voucher-table tr:hover {
            background-color: rgba(26, 42, 87, 0.05);
        }

        .amount {
            text-align: right;
            font-family: 'Courier New', monospace;
        }

        .credit {
            color: var(--accounting-green);
        }

        .debit {
            color: #E53935;
        }

        .back-button {
            background-color: var(--primary-blue);
            color: white;
            border-radius: 4px;
            padding: 8px 16px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            text-decoration: none;
            margin-bottom: 15px;
            border: none;
        }

        .back-button:hover {
            background-color: #2A3A77;
        }

        .folder-icon {
            margin-right: 8px;
            color: #FFA000;
        }

        .ledger-icon {
            margin-right: 8px;
            color: #5C6BC0;
        }

        .voucher-icon {
            margin-right: 8px;
            color: #66BB6A;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <form method="get" class="filter-form">
                <label for="as_on_date">
                    As on Date
                    <input type="date" name="as_on_date" value="<?= htmlspecialchars($as_on_date) ?>" required>
                </label>
                <input type="hidden" name="group_id" value="<?= htmlspecialchars($group_id) ?>">
                <input type="hidden" name="ledger_id" value="<?= htmlspecialchars($ledger_id) ?>">
                <button type="submit" class="back-button">Apply Date</button>
            </form>

            <ul class="tree-view">
                <?php foreach ($groups as $group): ?>
                    <li>
                        <div class="tree-item group-item <?= $group_id == $group['group_id'] ? 'active' : '' ?>" 
                             onclick="window.location.href='?as_on_date=<?= $as_on_date ?>&group_id=<?= $group['group_id'] ?>'">
                            <span class="folder-icon">📁</span>
                            <?= htmlspecialchars($group['group_name']) ?>
                        </div>
                        <?php if ($group_id == $group['group_id']): ?>
                            <ul>
                                <?php foreach ($group['ledgers'] as $ledger): ?>
                                    <li>
                                        <div class="tree-item ledger-item <?= $ledger_id == $ledger['ledger_id'] ? 'active' : '' ?>" 
                                             onclick="window.location.href='?as_on_date=<?= $as_on_date ?>&group_id=<?= $group['group_id'] ?>&ledger_id=<?= $ledger['ledger_id'] ?>'">
                                            <span class="ledger-icon">📄</span>
                                            <?= htmlspecialchars($ledger['ledger_name']) ?>
                                            <span style="float: right; font-family: 'Courier New', monospace;">
                                                <?= number_format($ledger['balance'], 2) ?>
                                            </span>
                                        </div>
                                        <?php if ($ledger_id == $ledger['ledger_id']): ?>
                                            <ul>
                                                <?php foreach ($selected_ledger_vouchers as $voucher): ?>
                                                    <li>
                                                        <div class="tree-item voucher-item" 
                                                             onclick="window.location.href='voucher_view.php?voucher_id=<?= $voucher['voucher_id'] ?>'">
                                                            <span class="voucher-icon">📋</span>
                                                            <?= htmlspecialchars($voucher['voucher_type']) ?> #<?= htmlspecialchars($voucher['voucher_number']) ?>
                                                            (<?= date('d-m-Y', strtotime($voucher['voucher_date'])) ?>)
                                                            <span style="float: right; font-family: 'Courier New', monospace;" 
                                                                  class="<?= $voucher['transaction_type'] === 'Credit' ? 'credit' : 'debit' ?>">
                                                                <?= number_format($voucher['amount'], 2) ?>
                                                            </span>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="content">
            <?php if ($ledger_id && !empty($selected_ledger_vouchers)): ?>
                <button class="back-button" onclick="window.history.back()">
                    ← Back
                </button>
                <h2><?= htmlspecialchars(array_column($groups, 'ledgers')[$group_id][array_search($ledger_id, array_column(array_column($groups, 'ledgers')[$group_id], 'ledger_id'))]['ledger_name']) ?></h2>
                <h3>Voucher Transactions</h3>
                
                <table class="voucher-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Voucher No.</th>
                            <th>Type</th>
                            <th>Narration</th>
                            <th>Type</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selected_ledger_vouchers as $voucher): ?>
                            <tr onclick="window.location.href='voucher_view.php?voucher_id=<?= $voucher['voucher_id'] ?>'" style="cursor: pointer;">
                                <td><?= date('d-m-Y', strtotime($voucher['voucher_date'])) ?></td>
                                <td><?= htmlspecialchars($voucher['voucher_number']) ?></td>
                                <td><?= htmlspecialchars($voucher['voucher_type']) ?></td>
                                <td><?= htmlspecialchars($voucher['narration']) ?></td>
                                <td><?= htmlspecialchars($voucher['transaction_type']) ?></td>
                                <td class="amount <?= $voucher['transaction_type'] === 'Credit' ? 'credit' : 'debit' ?>">
                                    <?= number_format($voucher['amount'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($group_id): ?>
                <button class="back-button" onclick="window.location.href='?'">
                    ← Back to All Groups
                </button>
                <h2><?= htmlspecialchars($groups[array_search($group_id, array_column($groups, 'group_id'))]['group_name']) ?></h2>
                <h3>Ledgers</h3>
                <p>Select a ledger from the left sidebar to view its vouchers</p>
            <?php else: ?>
                <h2>Accounting Groups</h2>
                <p>Select a group from the left sidebar to view its ledgers</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>