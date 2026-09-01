<?php
/**
 * CQC Enjiniring - Dedicated Menu/Dashboard
 * Menu khusus untuk CQC dengan navigation bar sederhana
 */

session_start();

// Check if user is logged in and assigned to CQC
if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_business_id'])) {
    header('Location: login.php');
    exit;
}

// Check if user is CQC user
if ($_SESSION['active_business_id'] != 'cqc') {
    echo '<div style="padding: 20px; color: #d32f2f;">Anda harus masuk ke CQC terlebih dahulu untuk mengakses menu ini.</div>';
    exit;
}

$pageTitle = 'Menu CQC Enjiniring';
$currentUser = $_SESSION['user_name'] ?? 'User';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen',
                'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue',
                sans-serif;
            background: #f5f5f5;
            color: #333;
        }

        /* Top Navigation Bar */
        .nav-top {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-top .logo {
            font-size: 24px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-top .logo-icon {
            font-size: 32px;
        }

        .nav-top .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 14px;
        }

        .nav-top .user-name {
            font-weight: 500;
        }

        .nav-top .logout-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s ease;
        }

        .nav-top .logout-btn:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
        }

        /* Menu Bar */
        .menu-bar {
            background: white;
            border-bottom: 2px solid #e0e0e0;
            padding: 0;
            display: flex;
            justify-content: flex-start;
            gap: 0;
            overflow-x: auto;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .menu-item {
            flex: 1;
            min-width: 150px;
            padding: 16px 20px;
            text-align: center;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #666;
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .menu-item:hover {
            background: #f9f9f9;
            color: #059669;
            border-bottom-color: #059669;
        }

        .menu-item.active {
            color: #059669;
            border-bottom-color: #059669;
            background: #f0fdf4;
        }

        .menu-icon {
            font-size: 24px;
        }

        .menu-label {
            font-size: 13px;
        }

        /* Main Content */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .welcome-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .welcome-section h2 {
            color: #059669;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .welcome-section p {
            color: #666;
            line-height: 1.6;
        }

        /* Feature Cards */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .feature-card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border-left: 4px solid #059669;
            cursor: pointer;
        }

        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.1);
            border-left-color: #047857;
        }

        .feature-card.project {
            border-left-color: #0891b2;
        }

        .feature-card.financial {
            border-left-color: #7c3aed;
        }

        .feature-card.resource {
            border-left-color: #dc2626;
        }

        .feature-card.client {
            border-left-color: #f59e0b;
        }

        .feature-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .feature-card h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 18px;
        }

        .feature-card p {
            color: #999;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 15px;
        }

        .feature-card .submenu {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .feature-card .submenu-item {
            padding: 10px;
            background: #f9f9f9;
            border-left: 3px solid #e0e0e0;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #666;
        }

        .feature-card .submenu-item:hover {
            background: #f0fdf4;
            border-left-color: #059669;
            color: #059669;
        }

        .feature-card.project .submenu-item:hover {
            background: #ecf9ff;
            border-left-color: #0891b2;
            color: #0891b2;
        }

        .feature-card.financial .submenu-item:hover {
            background: #faf5ff;
            border-left-color: #7c3aed;
            color: #7c3aed;
        }

        .feature-card.resource .submenu-item:hover {
            background: #fff5f5;
            border-left-color: #dc2626;
            color: #dc2626;
        }

        .feature-card.client .submenu-item:hover {
            background: #fffbeb;
            border-left-color: #f59e0b;
            color: #f59e0b;
        }

        /* Content Section */
        .content-section {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .content-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .section-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .section-content h3 {
            color: #059669;
            margin-bottom: 20px;
            font-size: 24px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 15px;
        }

        .menu-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 6px;
            text-decoration: none;
            color: #333;
            transition: all 0.2s ease;
            border-left: 3px solid #e0e0e0;
        }

        .menu-link:hover {
            background: #f0fdf4;
            border-left-color: #059669;
            color: #059669;
        }

        .menu-link-icon {
            font-size: 28px;
        }

        .menu-link-text {
            font-weight: 500;
            font-size: 14px;
        }

        /* Quick Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .stat-card {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
            padding: 20px;
            border-radius: 6px;
            text-align: center;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 13px;
            opacity: 0.9;
        }

        /* Footer */
        .footer {
            text-align: center;
            color: #999;
            font-size: 13px;
            margin-top: 40px;
            padding: 20px;
            border-top: 1px solid #e0e0e0;
        }

        @media (max-width: 768px) {
            .features-grid {
                grid-template-columns: 1fr;
            }

            .menu-bar {
                flex-wrap: wrap;
            }

            .menu-item {
                min-width: 130px;
                padding: 12px 15px;
                font-size: 12px;
            }

            .nav-top {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .nav-top .user-info {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <div class="nav-top">
        <div class="logo">
            <span class="logo-icon">🏢</span>
            <span>CQC Enjiniring</span>
        </div>
        <div class="user-info">
            <span class="user-name">👤 <?php echo htmlspecialchars($currentUser); ?></span>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </div>

    <!-- Menu Bar -->
    <div class="menu-bar">
        <div class="menu-item active" onclick="switchTab('overview')">
            <span class="menu-icon">🏠</span>
            <span class="menu-label">Overview</span>
        </div>
        <div class="menu-item" onclick="switchTab('projects')">
            <span class="menu-icon">📋</span>
            <span class="menu-label">Project Management</span>
        </div>
        <div class="menu-item" onclick="switchTab('financial')">
            <span class="menu-icon">💰</span>
            <span class="menu-label">Financial</span>
        </div>
        <div class="menu-item" onclick="switchTab('resources')">
            <span class="menu-icon">👥</span>
            <span class="menu-label">Resource Management</span>
        </div>
        <div class="menu-item" onclick="switchTab('clients')">
            <span class="menu-icon">🤝</span>
            <span class="menu-label">Client Management</span>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container">
        <!-- Overview Section -->
        <div id="overview" class="content-section active">
            <div class="welcome-section">
                <h2>👋 Selamat datang di CQC Enjiniring</h2>
                <p>Sistem manajemen terintegrasi khusus untuk bisnis contractor engineering Anda. Kelola proyek, sumber daya, keuangan, dan klien dengan mudah.</p>
            </div>

            <h3 style="color: #059669; margin: 30px 0 20px; font-size: 20px;">📊 Menu Utama</h3>
            <div class="features-grid">
                <!-- Project Management -->
                <div class="feature-card project" onclick="switchTab('projects')">
                    <div class="feature-icon">📋</div>
                    <h3>Project Management</h3>
                    <p>Kelola semua proyek, task, timeline, dan deliverable dengan terorganisir.</p>
                    <div class="submenu">
                        <div class="submenu-item">📌 Daftar Proyek</div>
                        <div class="submenu-item">✅ Task & Checklist</div>
                        <div class="submenu-item">📅 Timeline & Gantt</div>
                        <div class="submenu-item">📊 Progress Report</div>
                    </div>
                </div>

                <!-- Financial Management -->
                <div class="feature-card financial" onclick="switchTab('financial')">
                    <div class="feature-icon">💰</div>
                    <h3>Financial Management</h3>
                    <p>Kelola cashbook, invoice, budget, dan laporan keuangan harian.</p>
                    <div class="submenu">
                        <div class="submenu-item">💳 Cashbook</div>
                        <div class="submenu-item">📄 Invoice & Billing</div>
                        <div class="submenu-item">💵 Budget Control</div>
                        <div class="submenu-item">📈 Financial Reports</div>
                    </div>
                </div>

                <!-- Resource Management -->
                <div class="feature-card resource" onclick="switchTab('resources')">
                    <div class="feature-icon">👥</div>
                    <h3>Resource Management</h3>
                    <p>Kelola tenaga kerja, equipment, dan alokasi resource proyek.</p>
                    <div class="submenu">
                        <div class="submenu-item">👨‍💼 Manajemen Team</div>
                        <div class="submenu-item">🔧 Equipment & Tools</div>
                        <div class="submenu-item">📍 Alokasi Resource</div>
                        <div class="submenu-item">⏱️ Time Tracking</div>
                    </div>
                </div>

                <!-- Client Management -->
                <div class="feature-card client" onclick="switchTab('clients')">
                    <div class="feature-icon">🤝</div>
                    <h3>Client Management</h3>
                    <p>Kelola data klien, kontrak, dan hubungan dalam satu tempat.</p>
                    <div class="submenu">
                        <div class="submenu-item">📇 Database Klien</div>
                        <div class="submenu-item">📜 Kontrak & PO</div>
                        <div class="submenu-item">📞 Communication Log</div>
                        <div class="submenu-item">⭐ Performance Rating</div>
                    </div>
                </div>
            </div>

            <h3 style="color: #059669; margin: 40px 0 20px; font-size: 20px;">🔝 Quick Access</h3>
            <div class="menu-links">
                <a href="index.php?menu=cashbook" class="menu-link">
                    <span class="menu-link-icon">💳</span>
                    <span class="menu-link-text">Cashbook</span>
                </a>
                <a href="index.php?menu=reports" class="menu-link">
                    <span class="menu-link-icon">📊</span>
                    <span class="menu-link-text">Reports</span>
                </a>
                <a href="index.php?menu=divisions" class="menu-link">
                    <span class="menu-link-icon">📂</span>
                    <span class="menu-link-text">Divisions</span>
                </a>
                <a href="index.php?menu=settings" class="menu-link">
                    <span class="menu-link-icon">⚙️</span>
                    <span class="menu-link-text">Settings</span>
                </a>
                <a href="index.php?menu=procurement" class="menu-link">
                    <span class="menu-link-icon">🛒</span>
                    <span class="menu-link-text">Procurement</span>
                </a>
                <a href="index.php?menu=sales" class="menu-link">
                    <span class="menu-link-icon">📈</span>
                    <span class="menu-link-text">Sales</span>
                </a>
            </div>
        </div>

        <!-- Projects Section -->
        <div id="projects" class="content-section">
            <div class="section-content">
                <h3>📋 Project Management</h3>
                <p>Kelola semua proyek construction dan engineering di satu dashboard terpusat.</p>
                
                <h4 style="color: #0891b2; margin-top: 25px; margin-bottom: 15px; font-size: 16px;">Fitur Tersedia:</h4>
                <div class="menu-links">
                    <a href="modules/projects/list.php" class="menu-link" style="border-left-color: #0891b2;">
                        <span class="menu-link-icon">📌</span>
                        <span class="menu-link-text">Daftar Proyek</span>
                    </a>
                    <a href="modules/projects/tasks.php" class="menu-link" style="border-left-color: #0891b2;">
                        <span class="menu-link-icon">✅</span>
                        <span class="menu-link-text">Task Management</span>
                    </a>
                    <a href="modules/projects/timeline.php" class="menu-link" style="border-left-color: #0891b2;">
                        <span class="menu-link-icon">📅</span>
                        <span class="menu-link-text">Timeline / Gantt Chart</span>
                    </a>
                    <a href="modules/projects/progress.php" class="menu-link" style="border-left-color: #0891b2;">
                        <span class="menu-link-icon">📊</span>
                        <span class="menu-link-text">Progress Report</span>
                    </a>
                    <a href="modules/projects/budget.php" class="menu-link" style="border-left-color: #0891b2;">
                        <span class="menu-link-icon">💰</span>
                        <span class="menu-link-text">Budget Tracking</span>
                    </a>
                    <a href="modules/projects/issues.php" class="menu-link" style="border-left-color: #0891b2;">
                        <span class="menu-link-icon">⚠️</span>
                        <span class="menu-link-text">Issue / Risk Log</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Financial Section -->
        <div id="financial" class="content-section">
            <div class="section-content">
                <h3>💰 Financial Management</h3>
                <p>Kelola arus kas, invoice, dan laporan keuangan untuk semua proyek.</p>
                
                <h4 style="color: #7c3aed; margin-top: 25px; margin-bottom: 15px; font-size: 16px;">Fitur Tersedia:</h4>
                <div class="menu-links">
                    <a href="index.php?menu=cashbook" class="menu-link" style="border-left-color: #7c3aed;">
                        <span class="menu-link-icon">💳</span>
                        <span class="menu-link-text">Cashbook Daily</span>
                    </a>
                    <a href="modules/financial/invoicing.php" class="menu-link" style="border-left-color: #7c3aed;">
                        <span class="menu-link-icon">📄</span>
                        <span class="menu-link-text">Invoice & Billing</span>
                    </a>
                    <a href="modules/financial/expenses.php" class="menu-link" style="border-left-color: #7c3aed;">
                        <span class="menu-link-icon">💸</span>
                        <span class="menu-link-text">Expense Tracking</span>
                    </a>
                    <a href="index.php?menu=bills" class="menu-link" style="border-left-color: #7c3aed;">
                        <span class="menu-link-icon">📋</span>
                        <span class="menu-link-text">Bills & Payments</span>
                    </a>
                    <a href="index.php?menu=reports" class="menu-link" style="border-left-color: #7c3aed;">
                        <span class="menu-link-icon">📊</span>
                        <span class="menu-link-text">Financial Reports</span>
                    </a>
                    <a href="modules/financial/budget.php" class="menu-link" style="border-left-color: #7c3aed;">
                        <span class="menu-link-icon">📈</span>
                        <span class="menu-link-text">Budget & Forecast</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Resources Section -->
        <div id="resources" class="content-section">
            <div class="section-content">
                <h3>👥 Resource Management</h3>
                <p>Kelola tenaga kerja, equipment, dan alokasi resource ke proyek.</p>
                
                <h4 style="color: #dc2626; margin-top: 25px; margin-bottom: 15px; font-size: 16px;">Fitur Tersedia:</h4>
                <div class="menu-links">
                    <a href="modules/resources/team.php" class="menu-link" style="border-left-color: #dc2626;">
                        <span class="menu-link-icon">👨‍💼</span>
                        <span class="menu-link-text">Team Management</span>
                    </a>
                    <a href="modules/resources/equipment.php" class="menu-link" style="border-left-color: #dc2626;">
                        <span class="menu-link-icon">🔧</span>
                        <span class="menu-link-text">Equipment Inventory</span>
                    </a>
                    <a href="modules/resources/allocation.php" class="menu-link" style="border-left-color: #dc2626;">
                        <span class="menu-link-icon">📍</span>
                        <span class="menu-link-text">Resource Allocation</span>
                    </a>
                    <a href="modules/resources/timesheet.php" class="menu-link" style="border-left-color: #dc2626;">
                        <span class="menu-link-icon">⏱️</span>
                        <span class="menu-link-text">Timesheet & Hours</span>
                    </a>
                    <a href="modules/resources/skills.php" class="menu-link" style="border-left-color: #dc2626;">
                        <span class="menu-link-icon">🎯</span>
                        <span class="menu-link-text">Skills Management</span>
                    </a>
                    <a href="index.php?menu=payroll" class="menu-link" style="border-left-color: #dc2626;">
                        <span class="menu-link-icon">💵</span>
                        <span class="menu-link-text">Payroll</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Clients Section -->
        <div id="clients" class="content-section">
            <div class="section-content">
                <h3>🤝 Client Management</h3>
                <p>Kelola data klien, kontrak, dan komunikasi dalam satu sistem terpadu.</p>
                
                <h4 style="color: #f59e0b; margin-top: 25px; margin-bottom: 15px; font-size: 16px;">Fitur Tersedia:</h4>
                <div class="menu-links">
                    <a href="modules/clients/directory.php" class="menu-link" style="border-left-color: #f59e0b;">
                        <span class="menu-link-icon">📇</span>
                        <span class="menu-link-text">Client Directory</span>
                    </a>
                    <a href="modules/clients/contracts.php" class="menu-link" style="border-left-color: #f59e0b;">
                        <span class="menu-link-icon">📜</span>
                        <span class="menu-link-text">Contracts & PO</span>
                    </a>
                    <a href="modules/clients/communications.php" class="menu-link" style="border-left-color: #f59e0b;">
                        <span class="menu-link-icon">📞</span>
                        <span class="menu-link-text">Communications Log</span>
                    </a>
                    <a href="modules/clients/projects.php" class="menu-link" style="border-left-color: #f59e0b;">
                        <span class="menu-link-icon">📋</span>
                        <span class="menu-link-text">Client Projects</span>
                    </a>
                    <a href="modules/clients/feedback.php" class="menu-link" style="border-left-color: #f59e0b;">
                        <span class="menu-link-icon">⭐</span>
                        <span class="menu-link-text">Feedback & Rating</span>
                    </a>
                    <a href="modules/clients/payments.php" class="menu-link" style="border-left-color: #f59e0b;">
                        <span class="menu-link-icon">💳</span>
                        <span class="menu-link-text">Payment History</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>© 2026 CQC Enjiniring. Semua hak dilindungi. | Versi 1.0</p>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all sections
            const sections = document.querySelectorAll('.content-section');
            sections.forEach(section => {
                section.classList.remove('active');
            });

            // Remove active from all menu items
            const menuItems = document.querySelectorAll('.menu-item');
            menuItems.forEach(item => {
                item.classList.remove('active');
            });

            // Show selected section
            const selectedSection = document.getElementById(tabName);
            if (selectedSection) {
                selectedSection.classList.add('active');
                
                // Find and activate corresponding menu item
                const menuItems = document.querySelectorAll('.menu-item');
                menuItems.forEach((item, index) => {
                    if ((index === 0 && tabName === 'overview') ||
                        (index === 1 && tabName === 'projects') ||
                        (index === 2 && tabName === 'financial') ||
                        (index === 3 && tabName === 'resources') ||
                        (index === 4 && tabName === 'clients')) {
                        item.classList.add('active');
                    }
                });
            }

            // Smooth scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function logout() {
            if (confirm('Apakah Anda yakin ingin logout?')) {
                window.location.href = 'logout.php';
            }
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            // Set first menu item as active
            const firstMenuItem = document.querySelector('.menu-item');
            if (firstMenuItem) {
                firstMenuItem.classList.add('active');
            }
        });
    </script>
</body>
</html>
