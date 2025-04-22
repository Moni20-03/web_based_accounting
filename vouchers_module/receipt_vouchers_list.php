<?php
include '../database/findb.php';

// Check user session and permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Fetch all receipt vouchers
$stmt = $conn->prepare("SELECT v.voucher_id, v.voucher_number, v.voucher_date, v.total_amount, 
                       l.ledger_name as debit_ledger
                       FROM vouchers v
                       JOIN transactions t ON v.voucher_id = t.voucher_id AND t.transaction_type = 'Debit'
                       JOIN ledgers l ON t.ledger_id = l.ledger_id
                       WHERE v.voucher_type = 'Receipt'
                       ORDER BY v.voucher_date DESC, v.voucher_number DESC");
$stmt->execute();
$result = $stmt->get_result();
$vouchers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Display success message if redirected from edit/delete
$successMessage = '';
if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

$display_date = date('d-M-Y');
?>

<!DOCTYPE html>
<html>
<head>
    <title>Receipt Vouchers - FINPACK</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="../styles/form_style.css">
    <link rel="stylesheet" href="styles/navbar_style.css">
    <link rel="stylesheet" href="../styles/tally_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="../index.html">
                <img class="logo" src="../images/logo3.png" alt="Logo">
                <span>FinPack</span> 
            </a>
        </div>
        <ul class="nav-links">
            <li><a href="../dashboards/dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </li>
            <li>
                <a href="#">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </a>
            </li>
            <li>
                <a href="../logout.php" style="color:rgb(235, 71, 53);">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </li>
        </ul>
    </nav>

<div class="container">
    <div class="header">
        <h2>Receipt Vouchers</h2>
        <div class="current-date"><?= $display_date ?></div>
    </div>

    <?php if (!empty($successMessage)) : ?>
    <div id="successMessage" style="background-color: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
        <?= htmlspecialchars($successMessage) ?>
    </div>

    <script>
        // Auto-hide the success message after 4 seconds
        setTimeout(function() {
            const msg = document.getElementById('successMessage');
            if (msg) {
                msg.style.transition = 'opacity 0.5s ease-out';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 300);
            }
        }, 1000);
    </script>
    <?php endif; ?>

    <div class="action-bar">
        <a href="receipt_voucher.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Create New Receipt
        </a>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Voucher No</th>
                <th>Date</th>
                <th>Received In</th>
                <th>Amount</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vouchers as $voucher): ?>
            <tr>
                <td><?= htmlspecialchars($voucher['voucher_number']) ?></td>
                <td><?= htmlspecialchars($voucher['voucher_date']) ?></td>
                <td><?= htmlspecialchars($voucher['debit_ledger']) ?></td>
                <td><?= number_format($voucher['total_amount'], 2) ?></td>
                <td>
                    <a href="edit_receipt.php?id=<?= $voucher['voucher_id'] ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                    <!-- Add delete button if needed -->
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>