<?php
include 'findb.php';

// Ensure only Company Head can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Company Head') {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$company_name = $_SESSION['company_name'];


// --- 1. Total Ledgers ---
$total_ledgers = 0;
$ledger_query = "SELECT COUNT(*) AS total FROM ledgers";
$ledger_result = mysqli_query($conn, $ledger_query);
if ($ledger_row = mysqli_fetch_assoc($ledger_result)) {
    $total_ledgers = $ledger_row['total'];
}

// --- 2. Total Vouchers ---
$total_vouchers = 0;
$voucher_query = "SELECT COUNT(*) AS total FROM vouchers";
$voucher_result = mysqli_query($conn, $voucher_query);
if ($voucher_row = mysqli_fetch_assoc($voucher_result)) {
    $total_vouchers = $voucher_row['total'];
}



// --- 4. Last Voucher Info ---
$last_voucher_type = 'N/A';
$last_voucher_date = 'N/A';

$last_query = "SELECT voucher_type, voucher_date FROM vouchers ORDER BY voucher_id DESC LIMIT 1";
$last_result = mysqli_query($conn, $last_query);
if ($last_row = mysqli_fetch_assoc($last_result)) {
    $last_voucher_type = ucfirst($last_row['voucher_type']);
    $last_voucher_date = date('d-m-Y', strtotime($last_row['voucher_date']));
}

// --- 5. Recent Vouchers List (last 5) ---
$recent_vouchers = [];
$recent_query = "
  SELECT voucher_date, voucher_type, voucher_number, total_amount 
  FROM vouchers 
  ORDER BY voucher_id DESC LIMIT 3
";
$recent_result = mysqli_query($conn, $recent_query);
while ($row = mysqli_fetch_assoc($recent_result)) {
    $recent_vouchers[] = [
        'date' => date('d-m-Y', strtotime($row['voucher_date'])),
        'type' => $row['voucher_type'],
        'voucher_number' => $row['voucher_number'],
        'amount' => $row['total_amount'],
    ];
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard Design</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/dashboard_styles.css">
</head>
<body>
  <div class="sidebar">
    <div class="logo">
      <i class="fas fa-rocket"></i>
      <span><?php echo $company_name; ?></span>
    </div>
    <ul class="menu">
      <li>
        <a href="#">
          <i class="fas fa-layer-group"></i>
          <span>Groups</span>
          <i class="fas fa-chevron-down"></i>
        </a>
        <ul class = "submenu">
            <li><a href="create_group.php"><i class="fas fa-folder-plus"></i>Create</a></li> 
            <li><a href="search_group.php"><i class="fas fa-list"></i>Manage</a></li> 
        </ul>
      </li>


      <li>
        <a href="#">
          <i class="fas fa-book"></i>
          <span>Ledgers</span>
          <i class="fas fa-chevron-down"></i>
        </a>
        <ul class = "submenu">
            <li><a href="create_ledger.php"><i class="fas fa-folder-plus"></i>Create</a></li> 
            <li><a href="search_ledger.php"><i class="fas fa-list"></i>Manage</a></li> 
            <li><a href="create_party.php"><i class="fas fa-folder-plus"></i>Add Customer / Supplier</a></li> 
        </ul>
      </li>


      <li>
        <a href="#">
          <i class="fas fa-receipt"></i>
          <span>Accounting Vouchers</span>
          <i class="fas fa-chevron-down"></i>
        </a>
        <ul class = "submenu">
            <li><a href="payment_voucher.php"><i class="fas fa-credit-card"></i>Payment</a></li> 
            <li><a href="receipt_voucher.php"><i class="fas fa-file-invoice-dollar"></i><span style="margin-left:0px;">Receipt</span></a></li> 
            <li><a href="contra_voucher.php"><i class="fas fa-random"></i>Contra</a></li> 
            <li><a href="journal_voucher.php"><i class="fas fa-pen-to-square"></i>Journal</a></li> 
            <li><a href="sales_voucher.php"><i class="fas fa-tags"></i>Sales</a></li> 
            <li><a href="purchase_voucher.php"><i class="fas fa-boxes"></i>Purchase</a></li> 
        </ul>
      </li>

    
      <li>
        <a href="#">
          <i class="fas fa-chart-pie"></i>
          <span>Reports</span>
          <i class="fas fa-chevron-down"></i>
        </a>
        <ul class = "submenu">
            <li><a href="trial_balance.php"><i class="fas fa-balance-scale"></i> Trial Balance</a></li> 
            <li><a href="profit_balance.php"><i class="fas fa-chart-line"></i> Profit & Loss</a></li> 
        </ul>
      </li>
      
      <li>
        <a href="#">
          <i class="fas fa-user"></i>
          <span>User Management</span>
          <i class="fas fa-chevron-down"></i>
        </a>
      </li>

    </ul>

    <div class="profile">
      <!-- <img src="https://via.placeholder.com/50" alt="Profile"> -->
      <div>
        <i class="fas fa-user"></i>
        <span><?php echo $username ?></span>
      </div>
      <span> <br> </span>
    <button class="logout" onclick= "window.location.href='logout.php'">
      <i class="fas fa-sign-out-alt"></i>
      <span>Logout</span>
    </button>

    </div>
  </div>

  <div class="dashboard-container">
  <!-- Quick Stats -->
  <div class="stats-section">
    <div class="stat-card">
      <h4>Total Ledgers</h4>
      <p><?php echo $total_ledgers; ?></p>
    </div>

    <div class="stat-card">
      <h4>Total Vouchers</h4>
      <p><?php echo $total_vouchers; ?></p>
    </div>

    <div class="stat-card">
      <h4>Last Voucher</h4>
      <p><?php echo $last_voucher_type . ' - ' . $last_voucher_date; ?></p>
    </div>
  </div>

  <!-- Recent Vouchers -->
  <div class="section">
    <h3>Recent Vouchers</h3>
    <table class="recent-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Voucher No</th>
          <th>Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($recent_vouchers as $voucher): ?>
        <tr>
          <td><?php echo $voucher['date']; ?></td>
          <td><?php echo ucfirst($voucher['type']); ?></td>
          <td><?php echo $voucher['voucher_number']; ?></td>
          <td>₹<?php echo number_format($voucher['amount']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Shortcuts -->
  <div class="section">
    <h3>Quick Shortcuts</h3>
    <div class="shortcut-buttons">
      <a href="create_ledger.php" class="shortcut-btn"><i class="fas fa-folder-plus"></i> Ledgers</a>
      <a href="payment_voucher.php" class="shortcut-btn"><i class="fas fa-credit-card"></i> Payment</a>
      <a href="receipt_voucher.php" class="shortcut-btn"><i class="fas fa-file-invoice-dollar"></i> Receipt</a>
      <a href="journal_voucher.php" class="shortcut-btn"><i class="fas fa-pen-to-square"></i> Journal</a>
      <a href="contra_voucher.php" class="shortcut-btn"><i class="fas fa-random"></i> Contra</a>
    </div>
  </div>
</div>


  <script>
   // Toggle submenus on click
          const menuItems = document.querySelectorAll('.sidebar .menu li');

          menuItems.forEach(item => {
            item.addEventListener('click', () => {
              // Close other submenus
              menuItems.forEach(otherItem => {
                if (otherItem !== item) {
                  otherItem.classList.remove('active');
                }
              });
              // Toggle current submenu
              item.classList.toggle('active');
            });
          });
  </script>
</body>
</html>