<?php
session_start();
include 'db_connection.php';

// Ensure only Company Head can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Company Head') {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard Design</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
/* General Styles */
body {
  margin: 0;
  font-family: 'Poppins', sans-serif;
  background: linear-gradient(135deg, #1abc9c, #3498db);
  color: #ffffff;
  display: flex;
  min-height: 100vh;
}

/* Sidebar Styles */
.sidebar {
  width: 250px;
  background: #2c3e50;
  padding: 20px;
  box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
  display: flex;
  flex-direction: column;
  transition: width 0.3s ease;
}

.sidebar .logo {
  font-size: 24px;
  font-weight: 600;
  margin-bottom: 30px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.sidebar .menu {
  list-style: none;
  padding: 0;
  flex: 1;
}

.sidebar .menu li {
  margin: 10px 0;
}

.sidebar .menu li a {
  color: #ffffff;
  text-decoration: none;
  display: flex;
  align-items: center;
  padding: 10px;
  border-radius: 5px;
  transition: background 0.3s ease, padding 0.3s ease;
}

.sidebar .menu li a:hover {
  background: #34495e;
  padding-left: 15px;
}

.sidebar .menu li a i {
  margin-right: 10px;
  font-size: 18px;
}

.sidebar .menu li a .fa-chevron-down {
  margin-left: auto;
  transition: transform 0.3s ease;
}

.sidebar .menu li.active a .fa-chevron-down {
  transform: rotate(180deg);
}

.sidebar .menu li .submenu {
  list-style: none;
  padding-left: 20px;
  margin-top: 5px;
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.5s ease, opacity 0.3s ease;
  opacity: 0;
}

.sidebar .menu li.active .submenu {
  max-height: 200px;
  opacity: 1;
}

.sidebar .menu li .submenu li a {
  padding: 8px 10px;
  font-size: 14px;
}

.sidebar .profile {
  text-align: center;
  padding: 20px 0;
  border-top: 1px solid #34495e;
}

.sidebar .profile img {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  margin-bottom: 10px;
}

.sidebar .profile div {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  font-size: 16px;
}

.sidebar .logout {
  background: #e74c3c;
  color: #ffffff;
  border: none;
  padding: 10px;
  border-radius: 5px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  transition: background 0.3s ease;
}

.sidebar .logout:hover {
  background: #c0392b;
}

/* Content Page Styles */
.content {
  flex: 1;
  padding: 20px;
  background: #ecf0f1;
  color: #2c3e50;
  transition: margin-left 0.3s ease;
}

.content .welcome {
  font-size: 36px;
  font-weight: 600;
  animation: fadeInUp 1s ease-in-out;
}

.content .cards {
  display: flex;
  gap: 20px;
  margin-top: 20px;
  flex-wrap: wrap;
}

.content .card {
  background: #ffffff;
  padding: 20px;
  border-radius: 10px;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
  transition: transform 0.3s ease, box-shadow 0.3s ease;
  flex: 1 1 calc(33.333% - 40px);
  text-align: center;
}

.content .card:hover {
  transform: translateY(-5px);
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
}

.content .card i {
  font-size: 36px;
  margin-bottom: 10px;
  color: #3498db;
}

/* Animations */
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Responsive Styles */
@media (max-width: 768px) {
  .sidebar {
    width: 60px;
  }

  .sidebar .logo span,
  .sidebar .menu li a span,
  .sidebar .logout span,
  .sidebar .profile div span {
    display: none;
  }

  .sidebar .menu li a i.fa-chevron-down {
    display: none;
  }

  .sidebar .menu li .submenu {
    position: absolute;
    left: 60px;
    background: #2c3e50;
    border-radius: 0 5px 5px 0;
    box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.1);
    min-width: 150px;
    z-index: 1;
  }

  .sidebar .menu li.active .submenu {
    max-height: 200px;
    opacity: 1;
  }

  .content {
    margin-left: 60px;
  }

  .content .cards {
    flex-direction: column;
  }
}
    </style>
</head>
<body>
  <div class="sidebar">
    <div class="logo">
      <i class="fas fa-rocket"></i>
      <span>Dashboard</span>
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
            <li><a href="create_group.php"><i class="fas fa-folder-plus"></i>Create</a></li> 
            <li><a href="search_group.php"><i class="fas fa-list"></i>Manage</a></li> 
        </ul>
      </li>


      <li>
        <a href="#">
          <i class="fas fa-receipt"></i>
          <span>Accounting Vouchers</span>
          <i class="fas fa-chevron-down"></i>
        </a>
        <ul class = "submenu">
            <li><a href="create_group.php"><i class="fas fa-folder-plus"></i>Create</a></li> 
            <li><a href="search_group.php"><i class="fas fa-list"></i>Manage</a></li> 
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