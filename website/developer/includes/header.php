<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Developer Panel'; ?> - Narayana Karimunjawa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dev-primary: #0c2340;
            --dev-secondary: #1a3a5c;
            --dev-accent: #c8a45e;
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
            background: linear-gradient(180deg, var(--dev-primary) 0%, var(--dev-darker) 100%);
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
            background: linear-gradient(135deg, var(--dev-accent), #d4af6a);
            color: var(--dev-primary);
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
            border-left-color: var(--dev-accent);
        }
        
        .sidebar-menu a.active {
            background: rgba(200, 164, 94, 0.15);
            color: white;
            border-left-color: var(--dev-accent);
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
            margin-bottom: 24px;
        }
        
        .welcome-card h2 {
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .welcome-card p {
            color: rgba(255,255,255,0.8);
            margin-bottom: 0;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
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
        
        .btn-accent {
            background: var(--dev-accent);
            border-color: var(--dev-accent);
            color: var(--dev-primary);
        }
        
        .btn-accent:hover {
            background: #d4af6a;
            border-color: #d4af6a;
            color: var(--dev-primary);
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
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">Narayana Karimunjawa</div>
            <span class="dev-badge"><i class="bi bi-code-slash"></i> Developer</span>
        </div>
        
        <nav class="sidebar-menu">
            <div class="menu-section">Main</div>
            <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && !isset($_GET['section']) ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i>Dashboard
            </a>
            
            <div class="menu-section">Website Management</div>
            <a href="web-settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'web-settings.php' ? 'active' : ''; ?>">
                <i class="bi bi-globe"></i>Web Settings
            </a>
            <a href="../public/" target="_blank">
                <i class="bi bi-box-arrow-up-right"></i>View Website
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
                                <div class="role"><?php echo htmlspecialchars($user['role_name'] ?? 'Admin'); ?></div>
                            </div>
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['full_name'] ?? 'D', 0, 1)); ?>
                            </div>
                        </div>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-left me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Page Content -->
        <div class="container-fluid py-4">
