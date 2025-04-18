<?php
session_start();

// Authentication check (same as before)
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit();
}

include '../database/db_connection.php';

// Get company statistics (same query as before)
$companies = [];
$total_users = 0;
$company_result = $conn->query("SELECT * FROM companies ORDER BY company_id DESC");

if ($company_result) {
    while ($company = $company_result->fetch_assoc()) {
        // Get user count for each company
        $company_db_name = "finpack_company_" . $company['company_id'];
        $user_count = 0;
        $company_conn = new mysqli($db_host, $db_user, $db_pass, $company_db_name);
        
        if (!$company_conn->connect_error) {
            $user_result = $company_conn->query("SELECT COUNT(*) as count FROM users");
            if ($user_result) {
                $user_count = $user_result->fetch_assoc()['count'];
                $total_users += $user_count;
            }
            $company_conn->close();
        }
        
        $companies[] = [
            'id' => $company['company_id'],
            'name' => $company['company_name'],
            'email' => $company['company_email'],
            'users' => $user_count,
            'created_at' => $company['created_at'] ?? 'N/A'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinPack Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #eef2ff;
            --secondary: #3f37c9;
            --dark: #1e1e24;
            --light: #f8f9fa;
            --gray: #6c757d;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --border: #e9ecef;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background-color: #f5f7fb;
            color: var(--dark);
        }
        
        .admin-container {
            display: grid;
            grid-template-columns: 240px 1fr;
            min-height: 100vh;
        }
        
        /* Sidebar Styles */
        .sidebar {
            background: white;
            border-right: 1px solid var(--border);
            padding: 1.5rem;
            position: sticky;
            top: 0;
            height: 100vh;
        }
        
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }
        
        .brand-icon {
            width: 32px;
            height: 32px;
            background: var(--primary);
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .brand-text {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .admin-menu {
            list-style: none;
        }
        
        .menu-item {
            margin-bottom: 0.5rem;
        }
        
        .menu-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: 8px;
            color: var(--gray);
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .menu-link:hover, .menu-link.active {
            background: var(--primary-light);
            color: var(--primary);
        }
        
        .menu-link i {
            width: 20px;
            text-align: center;
        }
        
        /* Main Content Styles */
        .main-content {
            padding: 2rem;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .logout-btn {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .logout-btn:hover {
            color: var(--danger);
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .stat-title {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 500;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-icon.companies {
            background: #eef2ff;
            color: var(--primary);
        }
        
        .stat-icon.users {
            background: #ecfdf5;
            color: #10b981;
        }
        
        .stat-value {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .stat-change {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        /* Companies Table */
        .data-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-weight: 500;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        
        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        
        .table tr:last-child td {
            border-bottom: none;
        }
        
        .table tr:hover td {
            background: var(--primary-light);
        }
        
        .badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge.primary {
            background: var(--primary-light);
            color: var(--primary);
        }
        
        .action-btn {
            background: var(--primary-light);
            color: var(--primary);
            border: none;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        /* Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1rem;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 800px;
            max-height: 80vh;
            overflow: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        
        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--gray);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .admin-container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-icon">
                    <img src="../images/logo3.png" 
                    style = "height: 50px;
                        margin-left: 10px;
                        margin-right: 10px;
                        border-radius: 50%; /* Made logo circular */
                        object-fit: cover;"alt="logo">
                </div>
                <span class="brand-text">FinPack</span>
            </div>
            
            <ul class="admin-menu">
                <li class="menu-item">
                    <a href="#" class="menu-link active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="#" class="menu-link">
                        <i class="fas fa-building"></i>
                        <span>Companies</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="#" class="menu-link">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="#" class="menu-link">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </li>
            </ul>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1 class="page-title">Admin Dashboard</h1>
                <div class="user-menu">
                    <div class="user-avatar">A</div>
                    <form action="admin_logout.php" method="POST">
                        <button type="submit" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <h3 class="stat-title">Total Companies</h3>
                        <div class="stat-icon companies">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                    <h2 class="stat-value"><?php echo count($companies); ?></h2>
                    <p class="stat-change">All registered companies</p>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <h3 class="stat-title">Total Users</h3>
                        <div class="stat-icon users">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <h2 class="stat-value"><?php echo $total_users; ?></h2>
                    <p class="stat-change">Across all companies</p>
                </div>
            </div>
            
            <!-- Companies Table -->
            <div class="data-card">
                <div class="card-header">
                    <h2 class="card-title">Registered Companies</h2>
                </div>
                
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Company Name</th>
                            <th>Email</th>
                            <th>Users</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($companies as $company): ?>
                        <tr>
                            <td><?php echo $company['id']; ?></td>
                            <td><?php echo htmlspecialchars($company['name']); ?></td>
                            <td><?php echo htmlspecialchars($company['email']); ?></td>
                            <td>
                                <span class="badge primary"><?php echo $company['users']; ?> users</span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($company['created_at'])); ?></td>
                            <td>
                                <button class="action-btn" onclick="viewUsers(<?php echo $company['id']; ?>, '<?php echo htmlspecialchars($company['name'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-eye"></i> View Users
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    
    <!-- Users Modal -->
    <div class="modal" id="usersModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Company Users</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <!-- Filled by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function viewUsers(companyId, companyName) {
            const modal = document.getElementById('usersModal');
            const modalTitle = document.getElementById('modalTitle');
            const tableBody = document.getElementById('usersTableBody');
            
            // Show loading state
            modalTitle.textContent = `Loading users for ${companyName}...`;
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem;">
                        <i class="fas fa-spinner fa-spin"></i> Loading users...
                    </td>
                </tr>
            `;
            
            // Show modal
            modal.style.display = 'flex';
            
            // Fetch users data
            fetch(`admin_get_users.php?company_id=${companyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        tableBody.innerHTML = `
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--danger); padding: 2rem;">
                                    ${data.error}
                                </td>
                            </tr>
                        `;
                        return;
                    }
                    
                    // Update modal title
                    modalTitle.textContent = `Users: ${data.company_name}`;
                    
                    // Populate table
                    let html = '';
                    data.users.forEach(user => {
                        html += `
                            <tr>
                                <td>${user.user_id}</td>
                                <td>${user.username}</td>
                                <td>${user.email}</td>
                                <td>${user.role}</td>
                                <td>${new Date(user.created_at).toLocaleDateString()}</td>
                            </tr>
                        `;
                    });
                    
                    tableBody.innerHTML = html || `
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 2rem;">
                                No users found for this company
                            </td>
                        </tr>
                    `;
                })
                .catch(error => {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--danger); padding: 2rem;">
                                Error loading users
                            </td>
                        </tr>
                    `;
                    console.error('Error:', error);
                });
        }
        
        function closeModal() {
            document.getElementById('usersModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('usersModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>