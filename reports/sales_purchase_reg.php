<?php
include "../database/findb.php";

// Pagination parameters
$per_page = 20; // Records per page
$page = $_GET['page'] ?? 1;
$offset = ($page - 1) * $per_page;

// Default date range
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// Fetch group IDs for Sales and Purchases
$sales_group_ids = getGroupIdsByName($conn, ['Sales Accounts']);
$purchase_group_ids = getGroupIdsByName($conn, ['Purchase Accounts']);

// Get total count for sales
$sales_count_query = "
    SELECT COUNT(*) as total 
    FROM transactions t
    JOIN vouchers v ON t.voucher_id = v.voucher_id
    JOIN ledgers l ON t.ledger_id = l.ledger_id
    WHERE l.group_id IN (" . implode(",", $sales_group_ids) . ")
      AND t.transaction_type = 'Credit'
      AND v.voucher_date BETWEEN '$from_date' AND '$to_date'
";
$sales_count_result = mysqli_query($conn, $sales_count_query);
$sales_total = mysqli_fetch_assoc($sales_count_result)['total'];
$sales_total_pages = ceil($sales_total / $per_page);

// Get total count for purchases
$purchase_count_query = "
    SELECT COUNT(*) as total 
    FROM transactions t
    JOIN vouchers v ON t.voucher_id = v.voucher_id
    JOIN ledgers l ON t.ledger_id = l.ledger_id
    WHERE l.group_id IN (" . implode(",", $purchase_group_ids) . ")
      AND t.transaction_type = 'Debit'
      AND v.voucher_date BETWEEN '$from_date' AND '$to_date'
";
$purchase_count_result = mysqli_query($conn, $purchase_count_query);
$purchase_total = mysqli_fetch_assoc($purchase_count_result)['total'];
$purchase_total_pages = ceil($purchase_total / $per_page);

// Get sales vouchers with pagination
$sales_query = "
    SELECT v.voucher_id, v.voucher_date, v.voucher_number, v.voucher_type, 
           l.ledger_name AS ledger_name, t.amount, t.narration
    FROM transactions t
    JOIN vouchers v ON t.voucher_id = v.voucher_id
    JOIN ledgers l ON t.ledger_id = l.ledger_id
    WHERE l.group_id IN (" . implode(",", $sales_group_ids) . ")
      AND t.transaction_type = 'Credit'
      AND v.voucher_date BETWEEN '$from_date' AND '$to_date'
    ORDER BY v.voucher_date ASC
    LIMIT $per_page OFFSET $offset
";
$sales_result = mysqli_query($conn, $sales_query);

// Get purchase vouchers with pagination
$purchase_query = "
    SELECT v.voucher_id, v.voucher_date, v.voucher_number, v.voucher_type, 
           l.ledger_name AS ledger_name, t.amount, t.narration
    FROM transactions t
    JOIN vouchers v ON t.voucher_id = v.voucher_id
    JOIN ledgers l ON t.ledger_id = l.ledger_id
    WHERE l.group_id IN (" . implode(",", $purchase_group_ids) . ")
      AND t.transaction_type = 'Debit'
      AND v.voucher_date BETWEEN '$from_date' AND '$to_date'
    ORDER BY v.voucher_date ASC
    LIMIT $per_page OFFSET $offset
";
$purchase_result = mysqli_query($conn, $purchase_query);

function getGroupIdsByName($conn, $group_names) {
    $escaped = array_map(fn($g) => "'" . mysqli_real_escape_string($conn, $g) . "'", $group_names);
    $query = "SELECT group_id FROM groups WHERE group_name IN (" . implode(',', $escaped) . ")";
    $result = mysqli_query($conn, $query);
    $ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $ids[] = $row['group_id'];
    }
    return $ids;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Sales & Purchase Register</title>
    <style>
        :root {
            --primary-blue: #1A2A57;
            --accounting-green: #4CAF50;
            --alert-red: #E53935;
            --light-gray: #F5F7FA;
            --hover-blue: #2A3A77;
            --selected-blue: #3A4A97;
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
        }

        .report-header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-blue);
        }

        .report-header h2 {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary-blue);
            margin: 0 0 5px 0;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
            padding: 15px;
            background-color: rgba(26, 42, 87, 0.05);
            border-radius: 6px;
            align-items: end;
        }

        .filter-form label {
            display: flex;
            flex-direction: column;
            font-weight: 500;
            color: var(--primary-blue);
            font-size: 14px;
        }

        .filter-form input[type="date"] {
            padding: 8px 12px;
            border: 1px solid var(--border-gray);
            border-radius: 4px;
            font-family: inherit;
            transition: border-color 0.2s;
            margin-top: 5px;
        }

        .filter-form input[type="date"]:focus {
            border-color: var(--primary-blue);
            outline: none;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-family: inherit;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary {
            background-color: var(--primary-blue);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--hover-blue);
        }

        .btn-print {
            background-color: var(--accounting-green);
            color: white;
        }

        .btn-print:hover {
            background-color: #3d8b40;
        }

        .register-container {
            display: flex;
            gap: 30px;
            margin-top: 20px;
        }

        .register-column {
            flex: 1;
        }

        .register-title {
            background-color: var(--primary-blue);
            color: var(--white);
            padding: 10px 15px;
            border-radius: 4px 4px 0 0;
            font-weight: 600;
            margin-bottom: 0;
        }

        .register-table {
            width: 100%;
            border-collapse: collapse;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .register-table thead {
            background-color: var(--primary-blue);
            color: var(--white);
        }

        .register-table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .register-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .register-table tbody tr:nth-child(even) {
            background-color: rgba(245, 247, 250, 0.5);
        }

        .register-table .amount {
            text-align: right;
            font-family: 'Courier New', monospace;
        }

        .register-table .narration {
            font-style: italic;
            color: #555;
            font-size: 13px;
        }

        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .clickable-row:hover {
            background-color: rgba(26, 42, 87, 0.08) !important;
        }

        .no-data {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }

        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid var(--border-gray);
            border-radius: 4px;
            text-decoration: none;
            color: var(--primary-blue);
            transition: all 0.2s;
        }

        .pagination a:hover {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .pagination .current {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
            font-weight: bold;
        }

        .pagination .disabled {
            color: #999;
            pointer-events: none;
        }

        @media print {
            body {
                background-color: white;
                padding: 0;
                font-size: 12px;
            }
            
            .container {
                box-shadow: none;
                padding: 0;
                width: 100%;
                margin: 0;
            }
            
            .filter-form, .btn, .pagination {
                display: none;
            }
            
            .register-table {
                box-shadow: none;
                width: 100%;
            }
            
            .register-table tr {
                page-break-inside: avoid;
            }
            
            @page {
                size: auto;
                margin: 5mm;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="report-header">
            <h2>Sales and Purchase Register</h2>
        </div>

        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="1">
            
            <label>
                From Date:
                <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" required>
            </label>

            <label>
                To Date:
                <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" required>
            </label>

            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Search</button>
                <button type="button" class="btn btn-print" onclick="window.print()">Print</button>
            </div>
        </form>

        <div class="register-container">
            <!-- Sales Column -->
            <div class="register-column">
                <h3 class="register-title">Sales</h3>
                <table class="register-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Vch No</th>
                            <th>Party</th>
                            <th class="amount">Amount</th>
                            <th>Narration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($sales_result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($sales_result)): ?>
                                <tr class="clickable-row" onclick="window.location.href='edit_voucher.php?id=<?= $row['voucher_id'] ?>'">
                                    <td><?= date('d-M-Y', strtotime($row['voucher_date'])) ?></td>
                                    <td><?= htmlspecialchars($row['voucher_number']) ?></td>
                                    <td><?= htmlspecialchars($row['ledger_name']) ?></td>
                                    <td class="amount"><?= number_format($row['amount'], 2) ?></td>
                                    <td class="narration"><?= htmlspecialchars($row['narration']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="no-data">No sales found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if ($sales_total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">&laquo; First</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&lsaquo; Prev</a>
                        <?php else: ?>
                            <span class="disabled">&laquo; First</span>
                            <span class="disabled">&lsaquo; Prev</span>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($sales_total_pages, $page + 2);
                        
                        if ($start_page > 1) echo '<span>...</span>';
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" <?= $i == $page ? 'class="current"' : '' ?>>
                                <?= $i ?>
                            </a>
                        <?php endfor;
                        
                        if ($end_page < $sales_total_pages) echo '<span>...</span>';
                        ?>

                        <?php if ($page < $sales_total_pages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next &rsaquo;</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $sales_total_pages])) ?>">Last &raquo;</a>
                        <?php else: ?>
                            <span class="disabled">Next &rsaquo;</span>
                            <span class="disabled">Last &raquo;</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Purchase Column -->
            <div class="register-column">
                <h3 class="register-title">Purchases</h3>
                <table class="register-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Vch No</th>
                            <th>Party</th>
                            <th class="amount">Amount</th>
                            <th>Narration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($purchase_result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($purchase_result)): ?>
                                <tr class="clickable-row" onclick="window.location.href='edit_voucher.php?id=<?= $row['voucher_id'] ?>'">
                                    <td><?= date('d-M-Y', strtotime($row['voucher_date'])) ?></td>
                                    <td><?= htmlspecialchars($row['voucher_number']) ?></td>
                                    <td><?= htmlspecialchars($row['ledger_name']) ?></td>
                                    <td class="amount"><?= number_format($row['amount'], 2) ?></td>
                                    <td class="narration"><?= htmlspecialchars($row['narration']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="no-data">No purchases found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if ($purchase_total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">&laquo; First</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&lsaquo; Prev</a>
                        <?php else: ?>
                            <span class="disabled">&laquo; First</span>
                            <span class="disabled">&lsaquo; Prev</span>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($purchase_total_pages, $page + 2);
                        
                        if ($start_page > 1) echo '<span>...</span>';
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" <?= $i == $page ? 'class="current"' : '' ?>>
                                <?= $i ?>
                            </a>
                        <?php endfor;
                        
                        if ($end_page < $purchase_total_pages) echo '<span>...</span>';
                        ?>

                        <?php if ($page < $purchase_total_pages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next &rsaquo;</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $purchase_total_pages])) ?>">Last &raquo;</a>
                        <?php else: ?>
                            <span class="disabled">Next &rsaquo;</span>
                            <span class="disabled">Last &raquo;</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>