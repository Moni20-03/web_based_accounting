<?php
include '../database/findb.php';

$group_id = $_GET['group_id'] ?? 0;
$report_date = $_GET['report_date'] ?? date('Y-m-d');

// Get group name
$group_stmt = $conn->prepare("SELECT group_name FROM groups WHERE group_id = ?");
$group_stmt->bind_param("i", $group_id);
$group_stmt->execute();
$group_result = $group_stmt->get_result();
$group = $group_result->fetch_assoc();

if (!$group) {
    echo "Invalid Group.";
    exit;
}

$group_name = $group['group_name'];

// Get ledgers under this group
$ledgers_stmt = $conn->prepare("
    SELECT ledger_id, ledger_name 
    FROM ledgers 
    WHERE group_id = ? 
    ORDER BY ledger_name
");
$ledgers_stmt->bind_param("i", $group_id);
$ledgers_stmt->execute();
$ledgers_result = $ledgers_stmt->get_result();

$ledger_balances = [];

while ($ledger = $ledgers_result->fetch_assoc()) {
    $ledger_id = $ledger['ledger_id'];
    $ledger_name = $ledger['ledger_name'];

    // Calculate Debit and Credit totals for each ledger
    $stmt = $conn->prepare("
        SELECT transaction_type, SUM(amount) AS total 
        FROM transactions 
        WHERE ledger_id = ? AND transaction_date <= ? 
        GROUP BY transaction_type
    ");
    $stmt->bind_param("is", $ledger_id, $report_date);
    $stmt->execute();
    $res = $stmt->get_result();

    $dr = $cr = 0;
    while ($row = $res->fetch_assoc()) {
        if ($row['transaction_type'] === 'Debit') $dr = $row['total'];
        else if ($row['transaction_type'] === 'Credit') $cr = $row['total'];
    }

    $closing = $dr - $cr;
    $ledger_balances[] = [
        'ledger_id' => $ledger_id,
        'ledger_name' => $ledger_name,
        'dr' => $closing > 0 ? $closing : 0,
        'cr' => $closing < 0 ? abs($closing) : 0
    ];
}
?>

<h2>Group Summary: <?= htmlspecialchars($group_name) ?> (As on <?= date('d-M-Y', strtotime($report_date)) ?>)</h2>

<form method="get" style="margin-bottom: 15px;">
    <input type="hidden" name="group_id" value="<?= $group_id ?>">
    <label>Report Date:</label>
    <input type="date" name="report_date" value="<?= $report_date ?>" required>
    <button type="submit">Refresh</button>
    <button type="button" onclick="window.print()">🖨️ Print</button>
</form>

<table border="1" width="100%" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>Ledger Name</th>
            <th>Debit (Dr)</th>
            <th>Credit (Cr)</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $total_dr = $total_cr = 0;
        foreach ($ledger_balances as $ledger):
            $total_dr += $ledger['dr'];
            $total_cr += $ledger['cr'];
        ?>
        <tr onclick="window.location.href='ledger_report.php?ledger_id=<?= $ledger['ledger_id'] ?>&from_date=2024-04-01&to_date=<?= $report_date ?>';" style="cursor:pointer;">
            <td><?= htmlspecialchars($ledger['ledger_name']) ?></td>
            <td align="right"><?= $ledger['dr'] > 0 ? number_format($ledger['dr'], 2) : '' ?></td>
            <td align="right"><?= $ledger['cr'] > 0 ? number_format($ledger['cr'], 2) : '' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:bold; background: #f0f0f0;">
            <td align="right">TOTAL</td>
            <td align="right"><?= number_format($total_dr, 2) ?></td>
            <td align="right"><?= number_format($total_cr, 2) ?></td>
        </tr>
    </tbody>
</table>
