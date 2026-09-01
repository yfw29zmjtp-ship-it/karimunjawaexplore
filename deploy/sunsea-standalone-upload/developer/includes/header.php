<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Panel - ADF System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dev-primary: #6f42c1;
            --dev-secondary: #8b5cf6;
            --dev-success: #10b981;
            --dev-warning: #f59e0b;
            --dev-danger: #ef4444;
            --dev-info: #3b82f6;
            --dev-dark: #1e1e2d;
            --dev-darker: #151521;
            --dev-light: #f8f9fa;
            --sidebar-width: 260px;
        }
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: #f0f2f5;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--dev-dark) 0%, var(--dev-darker) 100%);
            z-index: 1000;
            transition: all 0.3s;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 1.2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }
        
        .sidebar-header .dev-badge {
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            color: white;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 0.6rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        
        .sidebar-menu {
            padding: 15px 0;
        }
        
        .menu-section {
            padding: 8px 16px;
            color: rgba(255,255,255,0.4);
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            font-size: 0.875rem;
        }
        
        .sidebar-menu a:hover {
            background: rgba(255,255,255,0.05);
            color: white;
            border-left-color: var(--dev-secondary);
        }
        
        .sidebar-menu a.active {
            background: rgba(111, 66, 193, 0.2);
            color: white;
            border-left-color: var(--dev-primary);
        }
        
        .sidebar-menu a i {
            width: 18px;
            margin-right: 10px;
            font-size: 0.95rem;
        }
        
        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }
        
        /* Top Navbar */
        .top-navbar {
            background: white;
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .navbar-left h4 {
            margin: 0;
            font-weight: 600;
            color: var(--dev-dark);
        }
        
        .navbar-left .breadcrumb {
            margin: 0;
            font-size: 0.85rem;
        }
        
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-dropdown {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .user-info {
            text-align: right;
        }
        
        .user-info .name {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--dev-dark);
        }
        
        .user-info .role {
            font-size: 0.7rem;
            color: #666;
        }
        
        /* Cards */
        .welcome-card {
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            border-radius: 16px;
            padding: 30px;
            color: white;
        }
        
        .welcome-card h2 {
            font-weight: 600;
        }
        
        .welcome-card p {
            color: rgba(255,255,255,0.8);
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 12px;
        }
        
        .stat-users .stat-icon { background: rgba(111, 66, 193, 0.15); color: var(--dev-primary); }
        .stat-businesses .stat-icon { background: rgba(59, 130, 246, 0.15); color: var(--dev-info); }
        .stat-active .stat-icon { background: rgba(16, 185, 129, 0.15); color: var(--dev-success); }
        .stat-menus .stat-icon { background: rgba(245, 158, 11, 0.15); color: var(--dev-warning); }
        
        .stat-info h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dev-dark);
        }
        
        .stat-info p {
            color: #666;
            margin: 0;
            font-size: 0.8rem;
        }
        
        .stat-link {
            position: absolute;
            bottom: 15px;
            right: 20px;
            font-size: 0.85rem;
            color: var(--dev-primary);
            text-decoration: none;
            font-weight: 500;
        }
        
        .stat-link:hover {
            color: var(--dev-secondary);
        }
        
        .content-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
        }
        
        .card-header-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
        }
        
        .card-header-custom h5 {
            margin: 0;
            font-weight: 600;
            color: var(--dev-dark);
        }
        
        /* Table Styles */
        .table {
            margin: 0;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 0.75rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 10px 12px;
            border: none;
        }
        
        .table td {
            padding: 10px 12px;
            vertical-align: middle;
            border-color: #f0f0f0;
            font-size: 0.85rem;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            padding: 20px;
        }
        
        .quick-action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 15px;
            background: #f8f9fa;
            border-radius: 12px;
            text-decoration: none;
            color: var(--dev-dark);
            transition: all 0.3s;
        }
        
        .quick-action-btn:hover {
            background: var(--dev-primary);
            color: white;
            transform: translateY(-3px);
        }
        
        .quick-action-btn i {
            font-size: 1.5rem;
            margin-bottom: 8px;
        }
        
        .quick-action-btn span {
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        /* Recent List */
        .recent-list {
            padding: 10px 0;
        }
        
        .recent-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            transition: background 0.3s;
        }
        
        .recent-item:hover {
            background: #f8f9fa;
        }
        
        .recent-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--dev-primary), var(--dev-secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 12px;
        }
        
        .recent-info {
            flex: 1;
        }
        
        .recent-info strong {
            display: block;
            font-size: 0.9rem;
            color: var(--dev-dark);
        }
        
        .recent-info small {
            color: #666;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        .status-dot.active { background: var(--dev-success); }
        .status-dot.inactive { background: var(--dev-danger); }
        
        /* Forms */
        .form-label {
            font-weight: 500;
            color: var(--dev-dark);
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dev-primary);
            box-shadow: 0 0 0 3px rgba(111, 66, 193, 0.15);
        }
        
        /* Buttons */
        .btn {
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: var(--dev-primary);
            border-color: var(--dev-primary);
        }
        
        .btn-primary:hover {
            background: var(--dev-secondary);
            border-color: var(--dev-secondary);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: var(--dev-success);
            border-color: var(--dev-success);
        }
        
        /* Responsive */
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Compact Form Styling */
        .form-label-sm {
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
            font-weight: 500;
            display: block;
        }
        
        .form-control-sm, .form-select-sm {
            font-size: 0.8rem;
            padding: 0.35rem 0.5rem;
            height: 2rem;
        }
        
        .table-sm {
            font-size: 0.8rem;
            margin-bottom: 0;
        }
        
        .table-sm th, .table-sm td {
            padding: 0.6rem 0.75rem;
            vertical-align: middle;
        }
        
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }
        
        .card {
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">ADF System</div>
            <span class="dev-badge"><i class="bi bi-code-slash"></i> Developer</span>
        </div>
        
        <nav class="sidebar-menu">
            <div class="menu-section">Main</div>
            <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i>Dashboard
            </a>
            
            <div class="menu-section">Management</div>
            <a href="index.php?section=user-setup" class="<?php echo isset($_GET['section']) && $_GET['section'] == 'user-setup' ? 'active' : ''; ?>">
                <i class="bi bi-person-plus"></i>User Setup
            </a>
            <a href="businesses.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'businesses.php' ? 'active' : ''; ?>">
                <i class="bi bi-building"></i>Businesses
            </a>
            <a href="menus.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'menus.php' ? 'active' : ''; ?>">
                <i class="bi bi-grid-3x3-gap"></i>Menu Items
            </a>
            <a href="permissions.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'permissions.php' ? 'active' : ''; ?>">
                <i class="bi bi-shield-lock"></i>Permissions
            </a>
            <a href="owner-access.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'owner-access.php' ? 'active' : ''; ?>">
                <i class="bi bi-eye"></i>Owner Monitoring
            </a>
            <a href="staff-accounts.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'staff-accounts.php' ? 'active' : ''; ?>">
                <i class="bi bi-person-badge"></i>Staff Portal
            </a>
            
            <div class="menu-section">System</div>
            <a href="database.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'database.php' ? 'active' : ''; ?>">
                <i class="bi bi-database"></i>Database
            </a>
            <a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
                <i class="bi bi-gear"></i>Settings
            </a>
            <a href="audit.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit.php' ? 'active' : ''; ?>">
                <i class="bi bi-journal-text"></i>Audit Logs
            </a>
            <a href="developer-settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'developer-settings.php' ? 'active' : ''; ?>">
                <i class="bi bi-sliders"></i>Developer Settings
            </a>
            <a href="web-settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'web-settings.php' ? 'active' : ''; ?>">
                <i class="bi bi-globe"></i>Web Settings
            </a>
            <a href="design.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'design.php' || basename($_SERVER['PHP_SELF']) == 'design-room-image.php' ? 'active' : ''; ?>">
                <i class="bi bi-palette"></i>Design Tools
            </a>
            
            <div class="menu-section">Account</div>
            <a href="logout.php">
                <i class="bi bi-box-arrow-left"></i>Logout
            </a>
        </nav>
    </aside>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="navbar-left">
                <h4><?php echo $pageTitle ?? 'Dashboard'; ?></h4>
            </div>
            <div class="navbar-right">
                <div class="user-dropdown dropdown">
                    <div data-bs-toggle="dropdown">
                        <div class="d-flex align-items-center">
                            <div class="user-info me-2">
                                <div class="name"><?php echo htmlspecialchars($user['full_name'] ?? 'Developer'); ?></div>
                                <div class="role">Developer Admin</div>
                            </div>
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['full_name'] ?? 'D', 0, 1)); ?>
                            </div>
                        </div>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
