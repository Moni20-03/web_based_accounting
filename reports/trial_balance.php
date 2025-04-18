<?php
include '../database/findb.php';

$report_date = $_GET['report_date'] ?? date('Y-m-d');

$groups = $conn->query("SELECT group_id, group_name FROM groups ORDER BY group_name");

$trial_data = [];

while ($group = $groups->fetch_assoc()) {
    $group_id = $group['group_id'];
    $group_name = $group['group_name'];

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

<h2>Trial Balance as on <?= date('d-M-Y', strtotime($report_date)) ?></h2>

<form method="get" style="margin-bottom: 15px;">
    <label>Report Date:</label>
    <input type="date" name="report_date" value="<?= $report_date ?>" required>
    <button type="submit">Generate</button>
    <button type="button" onclick="window.print()">🖨️ Print</button>
</form>

<table border="1" width="100%" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>Account Group</th>
            <th>Debit (Dr)</th>
            <th>Credit (Cr)</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $total_dr = $total_cr = 0;
        foreach ($trial_data as $group):
            $total_dr += $group['total_dr'];
            $total_cr += $group['total_cr'];
        ?>
        <tr class="group-row" onclick="window.location.href='group_summary.php?group_id=<?= $group['group_id'] ?>&report_date=<?= $report_date ?>';" style="cursor: pointer; background: #f1f1f1;">
            <td><?= htmlspecialchars($group['group_name']) ?></td>
            <td align="right"><?= $group['total_dr'] > 0 ? number_format($group['total_dr'], 2) :'' ?></td>
            <td align="right"><?= $group['total_cr'] > 0 ? number_format($group['total_cr'], 2) :'' ?></td>
        </tr>
        <?php endforeach; ?>

        <tr style="font-weight: bold; background: #f8f8f8;">
            <td align="right">TOTAL</td>
            <td align="right"><?= number_format($total_dr, 2) ?></td>
            <td align="right"><?= number_format($total_cr, 2) ?></td>
        </tr>

        <?php if ($total_dr !== $total_cr): ?>
        <tr style="background-color:#ffefef;">
            <td align="right"><i>Difference in Opening Balances</i></td>
            <?php if ($total_dr > $total_cr): ?>
                <td></td><td align="right"><?= number_format($total_dr - $total_cr, 2) ?></td>
            <?php else: ?>
                <td align="right"><?= number_format($total_cr - $total_dr, 2) ?></td><td></td>
            <?php endif; ?>
        </tr>
        <?php endif; ?>
    </tbody>
</table>
