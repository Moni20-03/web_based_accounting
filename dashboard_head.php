<?php
include 'findb.php';

// Ensure only Company Head can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Company Head') {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$company_name = $_SESSION['company_name'];

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
            <li><a href="receipt_voucher.php"><i class="fas fa-file-invoice-dollar"></i><span style="margin-left:7px;">Receipt</span></a></li> 
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
            <li><a href="create_group.php"><i class="fas fa-balance-scale"></i> Trial Balance</a></li> 
            <li><a href="create_group.php"><i class="fas fa-chart-line"></i> Profit & Loss</a></li> 
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

  <div class="content">
    <div class="welcome">Welcome back, <?php echo $username ?></div>
    <div class="cards">
      <div class="card">
        <i class="fas fa-chart-line"></i>
        <h3>Overview</h3>
        <p>View your dashboard overview.</p>
      </div>
      <div class="card">
        <i class="fas fa-tasks"></i>
        <h3>Tasks</h3>
        <p>Check your active tasks.</p>
      </div>
      <div class="card">
        <i class="fas fa-bell"></i>
        <h3>Notifications</h3>
        <p>See recent notifications.</p>
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