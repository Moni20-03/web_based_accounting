<?php
include '../database/findb.php';

$company_db = $_SESSION['company_name'];
// Default date range (current month)
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$voucher_type = $_GET['voucher_type'] ?? '';
$ledger_id = $_GET['ledger_id'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 9;
$offset = ($page - 1) * $per_page;

// Get ledgers for dropdown
$ledger_options = $conn->query("SELECT ledger_id, ledger_name FROM ledgers ORDER BY ledger_name ASC");

// Base query for counting total records
$count_query = "SELECT COUNT(*) as total 
                FROM transactions t 
                JOIN vouchers v ON t.voucher_id = v.voucher_id 
                JOIN ledgers l ON t.ledger_id = l.ledger_id 
                WHERE v.voucher_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];
$types = 'ss';

if ($voucher_type) {
    $count_query .= " AND v.voucher_type = ?";
    $params[] = $voucher_type;
    $types .= 's';
}

if ($ledger_id) {
    $count_query .= " AND t.ledger_id = ?";
    $params[] = $ledger_id;
    $types .= 'i';
}

// Get total count
$stmt = $conn->prepare($count_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);

// Main query with pagination
$query = "SELECT v.voucher_id, v.voucher_number, v.voucher_type, v.voucher_date, 
                 l.ledger_name, l.ledger_id, t.transaction_type, t.amount 
          FROM transactions t 
          JOIN vouchers v ON t.voucher_id = v.voucher_id 
          JOIN ledgers l ON t.ledger_id = l.ledger_id 
          WHERE v.voucher_date BETWEEN ? AND ?";

$query_params = [$from_date, $to_date];
$query_types = 'ss';

if ($voucher_type) {
    $query .= " AND v.voucher_type = ?";
    $query_params[] = $voucher_type;
    $query_types .= 's';
}

if ($ledger_id) {
    $query .= " AND t.ledger_id = ?";
    $query_params[] = $ledger_id;
    $query_types .= 'i';
}

$query .= " ORDER BY v.voucher_date ASC, v.voucher_number ASC, t.transaction_id ASC
            LIMIT ? OFFSET ?";

// Add pagination parameters
$query_params[] = $per_page;
$query_params[] = $offset;
$query_types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($query_types, ...$query_params);
$stmt->execute();
$result = $stmt->get_result();

// Build base URL for pagination links
$base_url = '?'.http_build_query([
    'from_date' => $from_date,
    'to_date' => $to_date,
    'voucher_type' => $voucher_type,
    'ledger_id' => $ledger_id
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Day Book Report</title>
    <style>
        /* FinPack Day Book Styles */
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

        .company-header {
            font-size: 1.5em;
            font-weight: bold;
            text-align: center;
            margin: 10px 0;
            color: #2c3e50;
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

        .report-header h3 {
            font-size: 16px;
            font-weight: 500;
            color: #666;
            margin: 0;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
            padding: 15px;
            background-color: rgba(26, 42, 87, 0.05);
            border-radius: 6px;
        }

        .filter-form label {
            display: flex;
            flex-direction: column;
            font-weight: 500;
            color: var(--primary-blue);
            font-size: 14px;
        }

        .filter-form input[type="date"],
        .filter-form select {
            padding: 8px 12px;
            border: 1px solid var(--border-gray);
            border-radius: 4px;
            font-family: inherit;
            transition: border-color 0.2s;
            margin-top: 5px;
        }

        .filter-form input[type="date"]:focus,
        .filter-form select:focus {
            border-color: var(--primary-blue);
            outline: none;
        }

        .btn-group
        {
            margin-top: 28px;
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

        .daybook-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .daybook-table thead {
            background-color: var(--primary-blue);
            color: var(--white);
        }

        .daybook-table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .daybook-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-gray);
        }

        .daybook-table tbody tr:nth-child(even) {
            background-color: rgba(245, 247, 250, 0.5);
        }

        .daybook-table .amount {
            text-align: right;
            font-family: 'Courier New', monospace;
        }

        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .clickable-row:hover {
            background-color: rgba(26, 42, 87, 0.08) !important;
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
        }

        .pagination a:hover {
            background-color: rgba(26, 42, 87, 0.08);
        }

        .pagination .current {
            background-color: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .report-summary {
            margin-top: 20px;
            padding: 15px;
            background-color: rgba(26, 42, 87, 0.05);
            border-radius: 6px;
            font-size: 14px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .filter-form {
                flex-direction: column;
            }
            
            .daybook-table {
                display: block;
                overflow-x: auto;
            }
            
            .btn-group {
                display: flex;
                gap: 10px;
            }
        }

        /* Print-specific styles */
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
            
            .daybook-table {
                box-shadow: none;
                width: 100%;
            }
            
            .daybook-table tr {
                page-break-inside: avoid;
            }
            
            .report-header h2 {
                font-size: 18px;
            }
            
            .report-header h3 {
                font-size: 14px;
            }
            
            .company-header {
                font-size: 1.2em;
            }
            
            .report-summary {
                display: none;
            }
            
            @page {
                size: auto;
                margin: 5mm;
            }
        }
        
        .no-print {
            display: none;
        }
    </style>
</head>
<body>
<div class="container">
    
<div class="container">
    <!-- Improved Header Section -->
    <div class="report-header" style="display: flex; justify-content: space-between; align-items: center; position: relative;">
        <!-- Dashboard Button (Left-aligned) -->
        <a href="../dashboards/dashboard.php" class="btn btn-primary" style="position: absolute; left: 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
            </svg>
        </a>
        
        <!-- Centered Title -->
        <div style="margin: 0 auto; text-align: center;">
            <h2 style="margin: 0;"><?php echo htmlspecialchars($company_db)?> - Day Book Report</h2>
            <h3 style="margin: 5px 0 0 0; font-weight: normal; color: #666;">
                <?= date('d M Y', strtotime($from_date)) ?> to <?= date('d M Y', strtotime($to_date)) ?>
            </h3>
        </div>
    </div>


    <form method="get" class="filter-form">
        <label>
            From Date
            <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" required>
        </label>
        
        <label>
            To Date
            <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" required>
        </label>
        
        <label>
            Voucher Type
            <select name="voucher_type">
                <option value="">All</option>
                <?php
                $types = ['Payment', 'Receipt', 'Sales', 'Purchase', 'Contra', 'Journal'];
                foreach ($types as $type): ?>
                    <option value="<?= htmlspecialchars($type) ?>" <?= $voucher_type === $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        
        <label>
            Ledger Account
            <select name="ledger_id">
                <option value="">All</option>
                <?php 
                // Reset pointer for ledger options
                $ledger_options->data_seek(0);
                while ($ledger = $ledger_options->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($ledger['ledger_id']) ?>" <?= $ledger_id == $ledger['ledger_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ledger['ledger_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        
        <div class="btn-group">
            <button type="submit" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/>
                    <path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/>
                </svg>
                Search
            </button>
            <button type="button" class="btn btn-print" onclick="window.print()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M5 1a2 2 0 0 0-2 2v1h10V3a2 2 0 0 0-2-2H5zm6 8H5a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1z"/>
                    <path d="M0 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-1v-2a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2H2a2 2 0 0 1-2-2V7zm2.5 1a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
                </svg>
                Print
            </button>
        </div>
    </form>

    <div class="report-summary">
        Showing <?= number_format($total_records) ?> transactions total
        <?php if ($voucher_type): ?> | Filtered by voucher type: <?= htmlspecialchars($voucher_type) ?><?php endif; ?>
        <?php if ($ledger_id): ?> | Filtered by ledger account<?php endif; ?>
    </div>

    <table class="daybook-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Particulars</th>
                <th>Vch Type</th>
                <th>Vch No</th>
                <th class="amount">Debit (Dr)</th>
                <th class="amount">Credit (Cr)</th>
            </tr>
        </thead>
        <tbody>
    <?php
    if ($result->num_rows > 0):
        while ($row = $result->fetch_assoc()):
            $is_debit = $row['transaction_type'] === 'Debit';
            
            // Define the edit URL based on voucher_type
            switch ($row['voucher_type']) {
                case 'Payment': 
                    $edit_url = "../vouchers_module/edit_payment.php?id=" . $row['voucher_id']; 
                    break;
                case 'Receipt': 
                    $edit_url = "../vouchers_module/edit_receipt.php?id=" . $row['voucher_id']; 
                    break;
                case 'Contra': 
                    $edit_url = "../vouchers_module/edit_contra.php?id=" . $row['voucher_id']; 
                    break;
                case 'Journal': 
                    $edit_url = "../vouchers_module/edit_journal.php?id=" . $row['voucher_id']; 
                    break;
                case 'Sales': 
                    $edit_url = "../vouchers_module/edit_sales.php?id=" . $row['voucher_id']; 
                    break;
                case 'Purchase': 
                    $edit_url = "../vouchers_module/edit_purchase.php?id=" . $row['voucher_id']; 
                    break;
                default: 
                    $edit_url = "#"; 
                    break;
            }
    ?>
            <tr class="clickable-row" data-href="<?= htmlspecialchars($edit_url) ?>">
                <td><?= date('d-M-Y', strtotime($row['voucher_date'])) ?></td>
                <td><?= htmlspecialchars($row['ledger_name']) ?></td>
                <td><?= htmlspecialchars($row['voucher_type']) ?></td>
                <td><?= htmlspecialchars($row['voucher_number']) ?></td>
                <td class="amount"><?= $is_debit ? number_format($row['amount'], 2) : '' ?></td>
                <td class="amount"><?= !$is_debit ? number_format($row['amount'], 2) : '' ?></td>
            </tr>
    <?php 
        endwhile;
    else: ?>
        <tr>
            <td colspan="6" style="text-align: center;">No transactions found for the selected criteria</td>
        </tr>
    <?php endif; ?>
</tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="<?= $base_url ?>&page=1">First</a>
            <a href="<?= $base_url ?>&page=<?= $page - 1 ?>">Prev</a>
        <?php endif; ?>
        
        <?php
        // Show page numbers
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        
        for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i == $page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="<?= $base_url ?>&page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="<?= $base_url ?>&page=<?= $page + 1 ?>">Next</a>
            <a href="<?= $base_url ?>&page=<?= $total_pages ?>">Last</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
    // Handle clickable rows
    document.addEventListener('DOMContentLoaded', function() {
        const rows = document.querySelectorAll('.clickable-row');
        rows.forEach(row => {
            row.addEventListener('click', function() {
                const url = this.getAttribute('data-href');
                if (url && url !== '#') {
                    window.location.href = url;
                }
            });
        });
    });

    // Print button functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Add print button event listener if needed
    });
</script>
</body>
</html>