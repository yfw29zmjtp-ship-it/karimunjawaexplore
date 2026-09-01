<?php
// DEV MODE - Mock session for API testing
session_start();
if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'DevOwner';
    $_SESSION['user_id'] = 999;
    $_SESSION['role'] = 'developer';
    $_SESSION['business_access'] = 'all';
}
$userName = $_SESSION['username'] ?? 'Dev Owner';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Owner Dashboard - Business Monitoring [DEV]</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --secondary: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #0f172a;
            --gray-900: #0f172a;
            --gray-800: #1e293b;
            --gray-700: #334155;
            --gray-600: #475569;
            --gray-500: #64748b;
            --gray-400: #94a3b8;
            --gray-300: #cbd5e1;
            --gray-200: #e2e8f0;
            --gray-100: #f1f5f9;
            --gray-50: #f8fafc;
            --white: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --text-lighter: #94a3b8;
            --border: #e2e8f0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, sans-serif;
            background: var(--gray-50);
            color: var(--text);
            line-height: 1.5;
            font-size: 14px;
        }
        
        /* Dev Badge */
        .dev-badge {
            position: fixed;
            top: 10px;
            right: 10px;
            background: var(--danger);
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            z-index: 9999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        /* Header Mobile First */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: var(--white);
            padding: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logo-icon {
            width: 32px;
            height: 32px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .logo-text {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.15);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
        }
        
        .user-avatar {
            width: 24px;
            height: 24px;
            background: var(--white);
            color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 11px;
        }
        
        .greeting {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 4px;
        }
        
        .page-title {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        /* Container */
        .container {
            padding: 16px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Section */
        .section {
            margin-bottom: 24px;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title::before {
            content: '';
            width: 3px;
            height: 18px;
            background: var(--primary);
            border-radius: 2px;
        }
        
        /* Card Base */
        .card {
            background: var(--white);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 12px;
        }
        
        /* Business Selector - Fase 2 */
        .business-selector {
            background: var(--white);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .selector-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: block;
        }
        
        .business-dropdown {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            color: var(--dark);
            background: var(--white);
            cursor: pointer;
            transition: all 0.2s ease;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px;
            padding-right: 40px;
        }
        
        .business-dropdown:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .business-dropdown option {
            padding: 12px;
            font-size: 15px;
        }
        
        /* Loading State */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-light);
        }
        
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Financial Overview - Clean 2028 Design */
        .overview-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .stats-panel {
            background: var(--white);
            border: 1px solid var(--border);\n            border-radius: 8px;
            padding: 20px;
        }
        
        .panel-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 16px;
        }
        
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        
        .metric-item {
            text-align: center;
        }
        
        .metric-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1;
            margin-bottom: 6px;
        }
        
        .metric-label {
            font-size: 11px;
            font-weight: 500;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .metric-sublabel {
            font-size: 10px;
            color: var(--gray-400);
            margin-top: 2px;
        }
        
        /* Pie Chart - Side by Side with Stats */
        .chart-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .chart-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 20px;
            align-self: flex-start;
        }
        
        .pie-chart {
            width: 140px;
            height: 140px;
            position: relative;
            margin-bottom: 16px;
        }
        
        .pie-chart canvas {
            width: 100%;
            height: 100%;
        }
        
        .chart-legend {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .legend-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
        }
        
        .legend-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--gray-600);
        }
        
        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .legend-value {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        /* Stats Cards Grid - Compact Mobile First */
        .stats-section {
            margin: 12px 0;
        }
        
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }
        
        .stats-grid.compact {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.08);
            transform: translateY(-1px);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }
        
        .stat-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .stat-icon.green { background: rgba(16, 185, 129, 0.12); }
        .stat-icon.red { background: rgba(239, 68, 68, 0.12); }
        .stat-icon.blue { background: rgba(59, 130, 246, 0.12); }
        .stat-icon.purple { background: rgba(139, 92, 246, 0.12); }
        .stat-icon.orange { background: rgba(251, 146, 60, 0.12); }
        
        .stat-info {
            flex: 1;
            min-width: 0;
        }
        
        .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .stat-period {
            font-size: 9px;
            color: var(--text-lighter);
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            margin-top: 4px;
            line-height: 1.2;
        }
        
        .stat-value.small {
            font-size: 15px;
        }
        
        .stat-value.positive { color: var(--success); }
        .stat-value.negative { color: var(--danger); }
        
        .stat-change {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 10px;
            font-weight: 600;
            padding: 3px 6px;
            border-radius: 4px;
            margin-top: 6px;
        }
        
        .stat-change.up {
            background: rgba(16, 185, 129, 0.12);
            color: var(--success);
        }
        
        .stat-change.down {
            background: rgba(239, 68, 68, 0.12);
            color: var(--danger);
        }
        
        /* Mini visual bar */
        .stat-bar {
            height: 3px;
            background: var(--border);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .stat-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 2px;
            transition: width 0.3s ease;
        }
        
        .stat-divider {
            height: 1px;
            background: var(--border);
            margin: 16px 0;
        }
        
        /* Skeleton Loading */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 6px;
        }
        
        .skeleton-value {
            height: 32px;
            width: 60%;
            margin: 8px 0;
        }
        
        .skeleton-label {
            height: 16px;
            width: 40%;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Placeholder for next phases */
        .placeholder {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
            border-radius: 12px;
            margin: 20px 0;
        }
        
        .placeholder h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }
        
        .placeholder p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        /* Responsive */
        @media (min-width: 768px) {
            body {
                font-size: 15px;
            }
            
            .overview-container {
                grid-template-columns: 2fr 1fr;
            }
            
            .metrics-grid {
                gap: 24px;
            }
            
            .metric-value {
                font-size: 32px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 12px;
            }
            
            .stats-grid.compact {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .stat-value {
                font-size: 20px;
            }
            
            .header {
                padding: 20px 24px;
            }
            
            .logo-text {
                font-size: 20px;
            }
            
            .page-title {
                font-size: 24px;
            }
            
            .container {
                padding: 24px;
            }
            
            .section-title {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <!-- Dev Badge -->
    <div class="dev-badge">DEV MODE</div>
    
    <!-- Header -->
    <header class="header">
        <div class="header-top">
            <div class="logo">
                <div class="logo-icon">📊</div>
                <div class="logo-text">SmartBiz</div>
            </div>
            <div class="user-info">
                <div class="user-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                <span><?= htmlspecialchars($userName) ?></span>
            </div>
        </div>
        <div class="greeting">Welcome back,</div>
        <div class="page-title">Financial Monitoring</div>
    </header>
    
    <!-- Main Container -->
    <main class="container">
        <!-- Phase 2: Business Selector ✓ -->
        <section class="section">
            <div class="business-selector">
                <label class="selector-label">Select Business</label>
                <select class="business-dropdown" id="businessSelector">
                    <option value="">Loading data...</option>
                </select>
            </div>
        </section>
        
        <!-- Financial Overview - Clean & Compact -->
        <div class="overview-container">
            <!-- Stats Panel -->
            <div class="stats-panel">
                <div class="panel-title">This Month Performance</div>
                <div class="metrics-grid">
                    <div class="metric-item">
                        <div class="metric-value" id="metricIncome">0</div>
                        <div class="metric-label">Income</div>
                        <div class="metric-sublabel">This month</div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-value" id="metricExpense">0</div>
                        <div class="metric-label">Expense</div>
                        <div class="metric-sublabel">This month</div>
                    </div>
                    <div class="metric-item">
                        <div class="metric-value" id="metricProfit">0</div>
                        <div class="metric-label">Net Profit</div>
                        <div class="metric-sublabel">This month</div>
                    </div>
                </div>
            </div>
            
            <!-- Pie Chart Panel -->
            <div class="chart-panel">
                <div class="chart-title">Distribution</div>
                <div class="pie-chart">
                    <canvas id="pieChart"></canvas>
                </div>
                <div class="chart-legend">
                    <div class="legend-item">
                        <div class="legend-label">
                            <div class="legend-dot" style="background: var(--gray-900)"></div>
                            <span>Income</span>
                        </div>
                        <div class="legend-value" id="legendIncome">0</div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-label">
                            <div class="legend-dot" style="background: var(--gray-400)"></div>
                            <span>Expense</span>
                        </div>
                        <div class="legend-value" id="legendExpense">0</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Phase 3: Stats Cards - Compact Design -->
        <section class="stats-section">
            <h2 class="section-title">Today's Activity</h2>
            <div class="stats-grid compact">
                <!-- Today Income -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon green">📈</div>
                        <div class="stat-info">
                            <div class="stat-label">Income</div>
                            <div class="stat-period">Today</div>
                        </div>
                    </div>
                    <div class="stat-value positive" id="todayIncome">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
                
                <!-- Today Expense -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon red">📉</div>
                        <div class="stat-info">
                            <div class="stat-label">Expense</div>
                            <div class="stat-period">Today</div>
                        </div>
                    </div>
                    <div class="stat-value negative" id="todayExpense">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
                
                <!-- Today Profit -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon blue">💎</div>
                        <div class="stat-info">
                            <div class="stat-label">Net Profit</div>
                            <div class="stat-period">Today</div>
                        </div>
                    </div>
                    <div class="stat-value" id="todayProfit">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
            </div>
        </section>
        
        <section class="stats-section">
            <h2 class="section-title">📅 Bulan Ini</h2>
            <div class="stats-grid">
                <!-- Month Income -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon green">�</div>
                        <div class="stat-info">
                            <div class="stat-label">Pemasukan</div>
                            <div class="stat-period">Bulan ini</div>
                        </div>
                    </div>
                    <div class="stat-value positive" id="monthIncome">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                    <span class="stat-change up" id="monthIncomeChange" style="display: none;">
                        ↑ +0% dari bulan lalu
                    </span>
                </div>
                
                <!-- Month Expense -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon red">💳</div>
                        <div class="stat-info">
                            <div class="stat-label">Expense</div>
                            <div class="stat-period">This month</div>
                        </div>
                    </div>
                    <div class="stat-value negative" id="monthExpense">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                    <span class="stat-change down" id="monthExpenseChange" style="display: none;">
                        ↑ +0% dari bulan lalu
                    </span>
                </div>
                
                <!-- Month Profit -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon purple">🏆</div>
                        <div class="stat-info">
                            <div class="stat-label">Net Profit</div>
                            <div class="stat-period">This month</div>
                        </div>
                    </div>
                    <div class="stat-value" id="monthProfit">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                    <span class="stat-change" id="monthProfitChange" style="display: none;">
                        ↑ +0% dari bulan lalu
                    </span>
                </div>
            </div>
        </section>
        
        <!-- Hotel Specific: Occupancy (Hidden for cafe/all) -->
        <section class="stats-section" id="hotelStats" style="display: none;">
            <h2 class="section-title">🏨 Occupancy</h2>
            <div class="stats-grid compact">
                <!-- Occupancy Rate -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon orange">📍</div>
                        <div class="stat-info">
                            <div class="stat-label">Occupancy</div>
                            <div class="stat-period">Status saat ini</div>
                        </div>
                    </div>
                    <div class="stat-value small" id="occupancyRate">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
                
                <!-- Available Rooms -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon green">🛏️</div>
                        <div class="stat-info">
                            <div class="stat-label">Tersedia</div>
                            <div class="stat-period">Available</div>
                        </div>
                    </div>
                    <div class="stat-value small" id="availableRooms">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
                
                <!-- Occupied Rooms -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon blue">👥</div>
                        <div class="stat-info">
                            <div class="stat-label">Terisi</div>
                            <div class="stat-period">Occupied</div>
                        </div>
                    </div>
                    <div class="stat-value small" id="occupiedRooms">
                        <div class="skeleton skeleton-value"></div>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- Phase 4: Charts (Next) -->
        <div class="placeholder">
            <h3>📊 Fase 4: Charts</h3>
            <p>Grafik monitoring akan ditambahkan di sini</p>
        </div>
        
        <!-- Phase 5: Navigation (Next) -->
        <div class="placeholder">
            <h3>🧭 Fase 5: Navigation</h3>
            <p>Menu navigasi akan ditambahkan di sini</p>
        </div>
    </main>
    
    <script>
        let currentBusiness = 'all';
        let businessData = [];
        
        // Format currency to Rupiah
        function formatCurrency(amount) {
            const num = parseFloat(amount) || 0;
            return 'Rp ' + num.toLocaleString('id-ID', { 
                minimumFractionDigits: 0, 
                maximumFractionDigits: 0 
            });
        }
        
        // Format percentage
        function formatPercent(value) {
            return (parseFloat(value) || 0).toFixed(1) + '%';
        }
        
        // Format short numbers for big display (like reference image: 26, 12, 37)
        function formatShort(amount) {
            const num = parseFloat(amount) || 0;
            if (num === 0) return '0';
            
            const absNum = Math.abs(num);
            if (absNum >= 1000000000) {
                return (num / 1000000000).toFixed(1) + 'B';
            } else if (absNum >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            } else if (absNum >= 1000) {
                return (num / 1000).toFixed(0) + 'K';
            } else {
                return num.toFixed(0);
            }
        }
        
        // Draw Pie Chart - Clean Minimalist Style
        function drawPieChart(income, expense) {
            const canvas = document.getElementById('pieChart');
            const ctx = canvas.getContext('2d');
            
            // Set canvas size
            canvas.width = 140;
            canvas.height = 140;
            
            const total = income + expense;
            if (total === 0) return;
            
            const incomeAngle = (income / total) * 2 * Math.PI;
            const centerX = 70;
            const centerY = 70;
            const radius = 60;
            
            // Clear canvas
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // Draw Income slice (dark)
            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, -Math.PI / 2, -Math.PI / 2 + incomeAngle);
            ctx.closePath();
            ctx.fillStyle = '#0f172a'; // gray-900
            ctx.fill();
            
            // Draw Expense slice (light)
            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, -Math.PI / 2 + incomeAngle, -Math.PI / 2 + 2 * Math.PI);
            ctx.closePath();
            ctx.fillStyle = '#94a3b8'; // gray-400
            ctx.fill();
        }
        
        // Load businesses from API
        async function loadBusinesses() {
            const selector = document.getElementById('businessSelector');
            
            try {
                // Use simple API for single-database setup
                const response = await fetch('../../api/owner-branches-simple.php');
                const data = await response.json();
                
                console.log('=== BRANCHES API Response ===');
                console.log('Response:', data);
                
                if (data.success && data.branches && data.branches.length > 0) {
                    businessData = data.branches;
                    selector.innerHTML = '<option value="all">All Businesses</option>';
                    
                    data.branches.forEach(branch => {
                        const icon = branch.business_type === 'hotel' ? '🏨' : '☕';
                        const name = branch.branch_name || branch.name || 'Unknown';
                        selector.innerHTML += `<option value="${branch.id}">${icon} ${name}</option>`;
                    });
                    
                    // Load initial stats
                    loadStats('all');
                } else {
                    selector.innerHTML = '<option value="">No business data</option>';
                    console.error('No branches found:', data);
                }
            } catch (error) {
                console.error('Error loading businesses:', error);
                selector.innerHTML = '<option value="">Failed to load data</option>';
            }
        }
        
        // Load financial stats
        async function loadStats(branchId) {
            try {
                // Use simple API for single-database setup
                const response = await fetch(`../../api/owner-stats-simple.php?branch_id=${branchId}`);
                const data = await response.json();
                
                console.log('=== STATS API Response ===');
                console.log('Branch ID:', branchId);
                console.log('Response:', data);
                
                if (data.success) {
                    console.log('Today Income:', data.todayIncome);
                    console.log('Today Expense:', data.todayExpense);
                    console.log('Month Income:', data.monthIncome);
                    console.log('Month Expense:', data.monthExpense);
                    
                    const monthProfit = data.monthIncome - data.monthExpense;
                    const todayProfit = data.todayIncome - data.todayExpense;
                    
                    // Main Metrics Panel
                    document.getElementById('metricIncome').innerHTML = formatShort(data.monthIncome);
                    document.getElementById('metricExpense').innerHTML = formatShort(data.monthExpense);
                    document.getElementById('metricProfit').innerHTML = formatShort(monthProfit);
                    
                    // Pie Chart Legend
                    document.getElementById('legendIncome').innerHTML = formatShort(data.monthIncome);
                    document.getElementById('legendExpense').innerHTML = formatShort(data.monthExpense);
                    
                    // Draw Pie Chart
                    drawPieChart(data.monthIncome, data.monthExpense);
                    
                    // Today stats (detail cards)
                    document.getElementById('todayIncome').innerHTML = formatCurrency(data.todayIncome);
                    document.getElementById('todayExpense').innerHTML = formatCurrency(data.todayExpense);
                    
                    const todayProfitEl = document.getElementById('todayProfit');
                    todayProfitEl.innerHTML = formatCurrency(todayProfit);
                    todayProfitEl.className = 'stat-value ' + (todayProfit >= 0 ? 'positive' : 'negative');
                    
                    // Month stats (detail cards)
                    document.getElementById('monthIncome').innerHTML = formatCurrency(data.monthIncome);
                    document.getElementById('monthExpense').innerHTML = formatCurrency(data.monthExpense);
                    
                    const monthProfitEl = document.getElementById('monthProfit');
                    monthProfitEl.innerHTML = formatCurrency(monthProfit);
                    monthProfitEl.className = 'stat-value ' + (monthProfit >= 0 ? 'positive' : 'negative');
                    
                    // Calculate growth vs last month if available
                    if (data.lastMonth) {
                        const incomeGrowth = data.lastMonth.income > 0 
                            ? ((data.monthIncome - data.lastMonth.income) / data.lastMonth.income * 100).toFixed(1)
                            : 0;
                        const expenseGrowth = data.lastMonth.expense > 0 
                            ? ((data.monthExpense - data.lastMonth.expense) / data.lastMonth.expense * 100).toFixed(1)
                            : 0;
                        
                        // Show growth indicators
                        const incomeChangeEl = document.getElementById('monthIncomeChange');
                        incomeChangeEl.style.display = 'inline-flex';
                        incomeChangeEl.className = 'stat-change ' + (incomeGrowth >= 0 ? 'up' : 'down');
                        incomeChangeEl.innerHTML = `${incomeGrowth >= 0 ? '↑' : '↓'} ${Math.abs(incomeGrowth)}% dari bulan lalu`;
                        
                        const expenseChangeEl = document.getElementById('monthExpenseChange');
                        expenseChangeEl.style.display = 'inline-flex';
                        expenseChangeEl.className = 'stat-change ' + (expenseGrowth > 0 ? 'down' : 'up');
                        expenseChangeEl.innerHTML = `${expenseGrowth >= 0 ? '↑' : '↓'} ${Math.abs(expenseGrowth)}% dari bulan lalu`;
                    }
                } else {
                    console.error('Stats API failed:', data.message || 'Unknown error');
                    alert('Error loading stats: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error loading stats:', error);
                alert('Network error loading stats: ' + error.message);
            }
        }
        
        // Load hotel occupancy stats
        async function loadOccupancy(branchId) {
            const hotelStats = document.getElementById('hotelStats');
            
            // Check if selected business is hotel
            const selectedBusiness = businessData.find(b => b.id == branchId);
            const isHotel = selectedBusiness && selectedBusiness.business_type === 'hotel';
            
            if (!isHotel && branchId !== 'all') {
                hotelStats.style.display = 'none';
                return;
            }
            
            try {
                const response = await fetch(`../../api/owner-occupancy.php?branch_id=${branchId}`);
                const data = await response.json();
                
                if (data.success) {
                    hotelStats.style.display = 'block';
                    
                    document.getElementById('occupancyRate').innerHTML = formatPercent(data.occupancy_rate || 0);
                    document.getElementById('availableRooms').innerHTML = data.available_rooms || 0;
                    document.getElementById('occupiedRooms').innerHTML = data.occupied_rooms || 0;
                } else {
                    hotelStats.style.display = 'none';
                }
            } catch (error) {
                console.error('Error loading occupancy:', error);
                hotelStats.style.display = 'none';
            }
        }
        
        // Handle business change
        function switchBusiness(branchId) {
            currentBusiness = branchId;
            console.log('Switching to business:', branchId);
            
            // Load stats and occupancy for selected business
            loadStats(branchId);
            loadOccupancy(branchId);
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadBusinesses();
        });
        
        // Handle business change
        document.getElementById('businessSelector').addEventListener('change', function() {
            switchBusiness(this.value);
        });
    </script>
</body>
</html>
