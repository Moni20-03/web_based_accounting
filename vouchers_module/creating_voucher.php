<!-- vouchers_module/creating_voucher.php -->
<?php 
include '../database/findb.php'; // Database connection

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Type Selection</title>
    <link rel="stylesheet" href="../styles/navbar_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fa;
            margin: 0;
            padding: 0;
            color: #333;
        }
        
        .main-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 60px);
            padding: 20px;
        }
        
        .voucher-menu-box {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 800px;
            padding: 30px;
            margin-top: 5%;
            text-align: center;
        }
        
        .voucher-menu-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 30px;
            color: #2c3e50;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }
        
        .voucher-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .voucher-option {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid #e0e0e0;
        }
        
        .voucher-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-color: #3498db;
        }
        
        .voucher-option i {
            font-size: 32px;
            color: #3498db;
            margin-bottom: 15px;
        }
        
        .voucher-option h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .voucher-option p {
            font-size: 14px;
            color: #7f8c8d;
            margin-top: 10px;
        }
        
        @media (max-width: 600px) {
            .voucher-options {
                grid-template-columns: 1fr;
            }
            
            .voucher-menu-box {
                padding: 20px;
            }
        }

        @media (min-width: 900px) {
                .voucher-options {
                    grid-template-columns: repeat(3, 1fr);
                }
            }

            @media (max-width: 600px) {
                .voucher-options {
                    grid-template-columns: 1fr;
                }
            }
    </style>
</head>
<body>
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

    <div class="main-container">
        <div class="voucher-menu-box">
            <h1 class="voucher-menu-title">Create New Voucher</h1>
            <p>Select the type of voucher you want to create:</p>
            
            <div class="voucher-options">
                <div class="voucher-option" onclick="location.href='payment_voucher.php'">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>Payment Voucher</h3>
                    <p>Record outgoing payments</p>
                </div>
                
                <div class="voucher-option" onclick="location.href='receipt_voucher.php'">
                    <i class="fas fa-receipt"></i>
                    <h3>Receipt Voucher</h3>
                    <p>Record incoming payments</p>
                </div>
                
                <div class="voucher-option" onclick="location.href='journal_voucher.php'">
                    <i class="fas fa-book"></i>
                    <h3>Journal Voucher</h3>
                    <p>Record non-cash transactions</p>
                </div>
                
                <div class="voucher-option" onclick="location.href='contra_voucher.php'">
                    <i class="fas fa-exchange-alt"></i>
                    <h3>Contra Voucher</h3>
                    <p>Record cash transfers</p>
                </div>

                <div class="voucher-option" onclick="location.href='sales_voucher.php'">
                    <i class="fas fa-cash-register"></i>
                    <h3>Sales Voucher</h3>
                    <p>Record sales transactions</p>
                </div>
            
                <div class="voucher-option" onclick="location.href='purchase_voucher.php'">
                    <i class="fas fa-shopping-cart"></i>
                    <h3>Purchase Voucher</h3>
                    <p>Record purchase transactions</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>