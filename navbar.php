<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Add this CSS to ensure proper mobile menu behavior */
        .mobile-menu-toggle {
            display: none;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                width: 30px;
                height: 21px;
                z-index: 1001;
            }
            
            .mobile-menu-toggle span {
                display: block;
                height: 3px;
                width: 100%;
                background-color: white;
                border-radius: 3px;
                transition: all 0.3s ease;
            }
            
            .nav-links {
                display: none;
                position: absolute;
                top: 70px;
                left: 0;
                width: 100%;
                background-color: #2c3e50;
                flex-direction: column;
                padding: 10px 0;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            
            .nav-links.active {
                display: flex;
            }
        }
    </style>
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
                    <?php echo htmlspecialchars($_SESSION['username'] ?? 'Guest'); ?>
                </a>
            </li>
            <li>
                <a href="../logout.php" style="color:rgb(235, 71, 53);">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </li>
        </ul>
        <div class="mobile-menu-toggle">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </nav>

    <script>
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.querySelector('.mobile-menu-toggle');
            const navLinks = document.querySelector('.nav-links');
            
            if (toggle && navLinks) {
                toggle.addEventListener('click', function() {
                    this.classList.toggle('active');
                    navLinks.classList.toggle('active');
                    
                    // Animate hamburger icon
                    const spans = this.querySelectorAll('span');
                    if (this.classList.contains('active')) {
                        spans[0].style.transform = 'translateY(9px) rotate(45deg)';
                        spans[1].style.opacity = '0';
                        spans[2].style.transform = 'translateY(-9px) rotate(-45deg)';
                    } else {
                        spans[0].style.transform = '';
                        spans[1].style.opacity = '';
                        spans[2].style.transform = '';
                    }
                });
            }
            
            // Close menu when clicking on a nav link (for mobile)
            const navItems = document.querySelectorAll('.nav-links a');
            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        toggle.classList.remove('active');
                        navLinks.classList.remove('active');
                        const spans = toggle.querySelectorAll('span');
                        spans[0].style.transform = '';
                        spans[1].style.opacity = '';
                        spans[2].style.transform = '';
                    }
                });
            });
        });
    </script>
</body>
</html>