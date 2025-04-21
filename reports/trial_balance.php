<?php
include '../database/findb.php';

$report_date = $_GET['report_date'] ?? date('Y-m-d');

$groups = $conn->query("SELECT group_id, group_name FROM groups ORDER BY group_name");

$trial_data = [];

while ($group = $groups->fetch_assoc()) {
    $group_id = $group['group_id'];
    $group_name = $group['group_name'];

    // $_SESSION['group_id'] = $group_id;
    $ledgers = $conn->query("SELECT ledger_id, ledger_name FROM ledgers WHERE group_id = $group_id ORDER BY ledger_name");

    $group_total_dr = $group_total_cr = 0;

    while ($ledger = $ledgers->fetch_assoc()) {
        $ledger_id = $ledger['ledger_id'];
        $ledger_name = $ledger['ledger_name'];

        $stmt = $conn->prepare("
            SELECT transaction_type, SUM(amount) as total
            FROM transactions
            WHERE ledger_id = ? AND transaction_date <= ?
            GROUP BY transaction_type
        ");
        $stmt->bind_param("is", $ledger_id, $report_date);
        $stmt->execute();
        $result = $stmt->get_result();

        $dr = $cr = 0;
        while ($row = $result->fetch_assoc()) {
            if ($row['transaction_type'] === 'Debit') $dr = $row['total'];
            else $cr = $row['total'];
        }

        $closing = $dr - $cr;
        $group_total_dr += $closing > 0 ? $closing : 0;
        $group_total_cr += $closing < 0 ? abs($closing) : 0;
    }

    if ($group_total_dr !== 0 || $group_total_cr !== 0) {
        $trial_data[] = [
            'group_id' => $group_id,
            'group_name' => $group_name,
            'total_dr' => $group_total_dr,
            'total_cr' => $group_total_cr,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trial Balance</title>
    <link rel="stylesheet" href="../styles/trial-bal_style.css">
</head>
<body>

<div class="container">
    <div class="header">
        <h1 class="title">Trial Balance as on <?= date('d-M-Y', strtotime($report_date)) ?></h1>
        
        <form method="get" class="date-form">
            <label for="report_date">Report Date:</label>
            <input type="date" id="report_date" name="report_date" value="<?= $report_date ?>" required>
            <button type="submit" class="button">Generate</button>
            <button type="button" class="button print" onclick="window.print()">Print Report</button>
        </form>
    </div>

    <table class="trial-balance-table">
        <thead>
            <tr>
                <th>Account Group</th>
                <th class="amount">Debit (Dr)</th>
                <th class="amount">Credit (Cr)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $total_dr = $total_cr = 0;
            foreach ($trial_data as $group):
                $total_dr += $group['total_dr'];
                $total_cr += $group['total_cr'];
            ?>
            <tr class="group-row" onclick="document.getElementById('form-<?= $group['group_id'] ?>').submit()">
                <td><?= htmlspecialchars($group['group_name']) ?></td>
                <td class="amount"><?= $group['total_dr'] > 0 ? number_format($group['total_dr'], 2) : '' ?></td>
                <td class="amount"><?= $group['total_cr'] > 0 ? number_format($group['total_cr'], 2) : '' ?></td>
            </tr>

            <!-- Hidden form for each row -->
            <form id="form-<?= $group['group_id'] ?>" method="post" action="group_summary.php" style="display:none;">
            <input type="hidden" name="group_id" value="<?= $group['group_id'] ?>">
            <input type="hidden" name="report_date" value="<?= $report_date ?>">
            </form>
            <?php endforeach; ?>

            <tr class="total-row">
                <td align="right">TOTAL</td>
                <td class="amount"><?= number_format($total_dr, 2) ?></td>
                <td class="amount"><?= number_format($total_cr, 2) ?></td>
            </tr>

            <?php if ($total_dr !== $total_cr): ?>
            <tr class="difference-row">
                <td align="right"><i>Difference in Opening Balances</i></td>
                <?php if ($total_dr > $total_cr): ?>
                    <td></td>
                    <td class="amount"><?= number_format($total_dr - $total_cr, 2) ?></td>
                <?php else: ?>
                    <td class="amount"><?= number_format($total_cr - $total_dr, 2) ?></td>
                    <td></td>
                <?php endif; ?>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>