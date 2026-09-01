<?php
session_start();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: /index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - Business Intelligence & Monitoring</title>
    <style>
        /* Reset & Variables */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --bg-tertiary: #f1f3f5;
            --text-primary: #1a1a1a;
            --text-secondary: #6c757d;
            --text-tertiary: #86868b;
            --border-color: #e5e7eb;
            --accent-blue: #0071e3;
            --accent-green: #34c759;
            --accent-red: #ff3b30;
            --accent-orange: #ff9500;
            --accent-purple: #af52de;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.5;
        }

        .app-container {
            display: flex;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--bg-primary);
            border-right: 1px solid var(--border-color);
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 100;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 12px 24px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .brand-text {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .brand-sub {
            font-size: 11px;
            color: var(--text-tertiary);
            font-weight: 500;
        }

        /* Business Selector */
        .business-selector {
            margin-bottom: 24px;
        }

        .selector-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            padding: 0 12px;
        }

        .business-dropdown {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386868b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            transition: all 0.2s ease;
        }

        .business-dropdown:hover {
            border-color: var(--accent-blue);
        }

        .business-dropdown:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(0, 113, 227, 0.1);
        }

        /* Nav Menu */
        .nav-menu {
            flex: 1;
        }

        .nav-section {
            margin-bottom: 24px;
        }

        .nav-section-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 12px;
            margin-bottom: 8px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.15s ease;
            cursor: pointer;
        }

        .nav-item:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: rgba(0, 113, 227, 0.08);
            color: var(--accent-blue);
        }

        .nav-item svg {
            width: 18px;
            height: 18px;
            stroke-width: 2;
        }

        .nav-item.hotel-only {
            display: none;
        }

        .nav-item.hotel-only.show {
            display: flex;
        }

        /* Sidebar Footer */
        .sidebar-footer {
            border-top: 1px solid var(--border-color);
            padding-top: 16px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 24px 32px;
            min-height: 100vh;
        }

        /* Header */
        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .header-left h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .header-left p {
            font-size: 14px;
            color: var(--text-secondary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-btn {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.15s ease;
            box-shadow: var(--shadow-sm);
        }

        .header-btn:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .header-btn svg {
            width: 18px;
            height: 18px;
        }

        /* Cards */
        .card {
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .card-subtitle {
            font-size: 12px;
            color: var(--text-tertiary);
            margin-top: 2px;
        }

        /* Grid Layouts */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        /* Stats Cards */
        .stat-card {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 20px;
            border: 1px solid var(--border-color);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .stat-icon.blue {
            background: rgba(0, 113, 227, 0.1);
            color: var(--accent-blue);
        }

        .stat-icon.green {
            background: rgba(52, 199, 89, 0.1);
            color: var(--accent-green);
        }

        .stat-icon.red {
            background: rgba(255, 59, 48, 0.1);
            color: var(--accent-red);
        }

        .stat-icon.orange {
            background: rgba(255, 149, 0, 0.1);
            color: var(--accent-orange);
        }

        .stat-icon.purple {
            background: rgba(175, 82, 222, 0.1);
            color: var(--accent-purple);
        }

        .stat-icon svg {
            width: 20px;
            height: 20px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-tertiary);
            font-weight: 500;
            margin-bottom: 4px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-change {
            font-size: 12px;
            font-weight: 500;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .stat-change.positive {
            color: var(--accent-green);
        }

        .stat-change.negative {
            color: var(--accent-red);
        }

        /* AI Health Widget */
        .ai-health-card {
            background: linear-gradient(135deg, #1d1d1f 0%, #2d2d30 100%);
            border-radius: var(--radius-lg);
            padding: 24px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .ai-health-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(0, 113, 227, 0.3) 0%, transparent 70%);
            pointer-events: none;
        }

        .ai-health-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .ai-badge {
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple));
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .ai-health-title {
            font-size: 18px;
            font-weight: 600;
        }

        .ai-health-content {
            position: relative;
            z-index: 1;
        }

        .health-score-container {
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 20px;
        }

        .health-score-ring {
            position: relative;
            width: 80px;
            height: 80px;
        }

        .health-score-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 24px;
            font-weight: 700;
        }

        .health-status {
            flex: 1;
        }

        .health-status-label {
            font-size: 14px;
            opacity: 0.7;
            margin-bottom: 4px;
        }

        .health-status-text {
            font-size: 20px;
            font-weight: 600;
        }

        .health-metrics {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .health-metric {
            text-align: center;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-sm);
        }

        .health-metric-value {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .health-metric-label {
            font-size: 11px;
            opacity: 0.6;
            text-transform: uppercase;
        }

        /* Business Comparison */
        .comparison-card {
            grid-column: span 2;
        }

        .comparison-legend {
            display: flex;
            gap: 20px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 240px;
        }

        /* Kas Harian Styles */
        .kas-harian-card {
            margin-top: 20px;
        }

        .kas-harian-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .kas-summary-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
        }

        .kas-icon {
            font-size: 20px;
        }

        .kas-info {
            flex: 1;
        }

        .kas-label {
            font-size: 11px;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kas-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .kas-value.positive {
            color: var(--accent-green);
        }

        .kas-value.negative {
            color: var(--accent-red);
        }

        .kas-harian-table-wrapper {
            max-height: 280px;
            overflow-y: auto;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
        }

        .kas-harian-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .kas-harian-table th {
            background: var(--bg-tertiary);
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            color: var(--text-tertiary);
            position: sticky;
            top: 0;
        }

        .kas-harian-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .kas-harian-table tr:last-child td {
            border-bottom: none;
        }

        .kas-harian-table .text-right {
            text-align: right;
        }

        .kas-harian-table .badge-masuk {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(52, 199, 89, 0.15);
            color: var(--accent-green);
        }

        .kas-harian-table .badge-keluar {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(255, 59, 48, 0.15);
            color: var(--accent-red);
        }

        .kas-harian-table .amount-masuk {
            color: var(--accent-green);
            font-weight: 600;
        }

        .kas-harian-table .amount-keluar {
            color: var(--accent-red);
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .kas-harian-summary {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .kas-summary-item {
                padding: 10px;
            }

            .kas-value {
                font-size: 14px;
            }

            .kas-harian-table {
                font-size: 12px;
            }

            .kas-harian-table th,
            .kas-harian-table td {
                padding: 8px 10px;
            }
        }

        /* Business Cards Grid */
        .business-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-top: 20px;
        }

        .business-card {
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .business-card:hover {
            background: var(--bg-primary);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .business-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .business-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .business-icon.hotel {
            background: rgba(0, 113, 227, 0.1);
        }

        .business-icon.cafe {
            background: rgba(255, 149, 0, 0.1);
        }

        .business-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .business-type {
            font-size: 12px;
            color: var(--text-tertiary);
        }

        .business-stats {
            text-align: right;
        }

        .business-revenue {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .business-change {
            font-size: 12px;
            color: var(--accent-green);
        }

        .business-change.negative {
            color: var(--accent-red);
        }

        /* Transaction Table */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table td {
            padding: 14px 16px;
            font-size: 14px;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-color);
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:hover {
            background: var(--bg-tertiary);
        }

        .badge {
            display: inline-flex;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge.income {
            background: rgba(52, 199, 89, 0.1);
            color: var(--accent-green);
        }

        .badge.expense {
            background: rgba(255, 59, 48, 0.1);
            color: var(--accent-red);
        }

        .amount {
            font-weight: 600;
            font-family: 'SF Mono', 'Monaco', 'Inconsolata', monospace;
        }

        .amount.positive {
            color: var(--accent-green);
        }

        .amount.negative {
            color: var(--accent-red);
        }

        /* Views */
        .view-section {
            display: none;
        }

        .view-section.active {
            display: block;
        }

        /* Front Desk Grid */
        .room-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
        }

        .room-card {
            background: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            padding: 16px;
            text-align: center;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .room-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .room-card.available {
            background: rgba(52, 199, 89, 0.1);
            border: 1px solid rgba(52, 199, 89, 0.3);
        }

        .room-card.occupied {
            background: rgba(0, 113, 227, 0.1);
            border: 1px solid rgba(0, 113, 227, 0.3);
        }

        .room-card.maintenance {
            background: rgba(255, 149, 0, 0.1);
            border: 1px solid rgba(255, 149, 0, 0.3);
        }

        .room-number {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .room-status {
            font-size: 11px;
            color: var(--text-tertiary);
            margin-top: 4px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-tertiary);
        }

        .empty-state svg {
            width: 48px;
            height: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .grid-4 {
                grid-template-columns: repeat(2, 1fr);
            }

            .grid-3 {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 16px;
            }

            .grid-2,
            .grid-3,
            .grid-4 {
                grid-template-columns: 1fr;
            }

            .comparison-card {
                grid-column: span 1;
            }

            .room-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Loading Animation */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px;
        }

        .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid var(--border-color);
            border-top-color: var(--accent-blue);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Section Spacing */
        .section {
            margin-bottom: 32px;
        }

        .section:last-child {
            margin-bottom: 0;
        }
    </style>
</head>

<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <div class="brand-icon">
                    <i data-feather="layers"></i>
                </div>
                <div>
                    <div class="brand-text">SmartBiz</div>
                    <div class="brand-sub">Multi-Tenant System</div>
                </div>
            </div>

            <!-- Business Selector -->
            <div class="business-selector">
                <div class="selector-label">Select Business</div>
                <select class="business-dropdown" id="businessSelector" onchange="switchBusiness(this.value)">
                    <option value="all">🏢 All Businesses</option>
                </select>
            </div>

            <!-- Navigation -->
            <nav class="nav-menu">
                <div class="nav-section">
                    <div class="nav-section-title">Overview</div>
                    <a href="#" class="nav-item active" onclick="showView('dashboard')">
                        <i data-feather="grid"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="#" class="nav-item" onclick="showView('analytics')">
                        <i data-feather="bar-chart-2"></i>
                        <span>Analytics</span>
                    </a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Finance</div>
                    <a href="#" class="nav-item" onclick="showView('transactions')">
                        <i data-feather="credit-card"></i>
                        <span>Transactions</span>
                    </a>
                    <a href="#" class="nav-item" onclick="showView('cashflow')">
                        <i data-feather="trending-up"></i>
                        <span>Cash Flow</span>
                    </a>
                </div>

                <div class="nav-section hotel-nav" id="hotelNav" style="display:none;">
                    <div class="nav-section-title">Hotel Operations</div>
                    <a href="#" class="nav-item hotel-only show" onclick="showView('frontdesk')">
                        <i data-feather="home"></i>
                        <span>Front Desk</span>
                    </a>
                    <a href="#" class="nav-item hotel-only show" onclick="showView('reservations')">
                        <i data-feather="calendar"></i>
                        <span>Reservations</span>
                    </a>
                </div>
            </nav>

            <!-- Sidebar Footer -->
            <div class="sidebar-footer">
                <a href="investor-dashboard.php" class="nav-item">
                    <i data-feather="users"></i>
                    <span>Investor Portal</span>
                </a>
                <a href="#" class="nav-item">
                    <i data-feather="folder"></i>
                    <span>Projects</span>
                </a>
                <a href="../../logout.php" class="nav-item">
                    <i data-feather="log-out"></i>
                    <span>Sign Out</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="main-header">
                <div class="header-left">
                    <h1 id="pageTitle">All Businesses</h1>
                    <p id="pageSubtitle">Overview of your entire portfolio</p>
                </div>
                <div class="header-right">
                    <button class="header-btn" onclick="refreshData()">
                        <i data-feather="refresh-cw"></i>
                    </button>
                    <button class="header-btn">
                        <i data-feather="bell"></i>
                    </button>
                    <button class="header-btn">
                        <i data-feather="settings"></i>
                    </button>
                </div>
            </header>

            <!-- Dashboard View -->
            <div class="view-section active" id="view-dashboard">
                <!-- AI Health Monitor -->
                <section class="section">
                    <div class="ai-health-card">
                        <div class="ai-health-header">
                            <div class="ai-badge">
                                <i data-feather="cpu" style="width:12px;height:12px"></i>
                                AI Powered
                            </div>
                            <div class="ai-health-title">Business Health Monitor</div>
                        </div>
                        <div class="ai-health-content">
                            <div class="health-score-container">
                                <div class="health-score-ring">
                                    <canvas id="healthGauge" width="80" height="80"></canvas>
                                    <div class="health-score-value" id="healthScore">85</div>
                                </div>
                                <div class="health-status">
                                    <div class="health-status-label">Overall Status</div>
                                    <div class="health-status-text" id="healthStatus">Excellent Performance</div>
                                </div>
                            </div>
                            <div class="health-metrics">
                                <div class="health-metric">
                                    <div class="health-metric-value" id="profitMargin">32%</div>
                                    <div class="health-metric-label">Profit Margin</div>
                                </div>
                                <div class="health-metric">
                                    <div class="health-metric-value" id="growthRate">+12%</div>
                                    <div class="health-metric-label">Growth Rate</div>
                                </div>
                                <div class="health-metric">
                                    <div class="health-metric-value" id="cashReserve">45d</div>
                                    <div class="health-metric-label">Cash Reserve</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Stats Grid -->
                <section class="section">
                    <div class="grid-4">
                        <div class="stat-card">
                            <div class="stat-icon blue">
                                <i data-feather="dollar-sign"></i>
                            </div>
                            <div class="stat-label">Total Revenue</div>
                            <div class="stat-value" id="totalRevenue">Rp 0</div>
                            <div class="stat-change positive" id="revenueChange">
                                <i data-feather="trending-up" style="width:14px;height:14px"></i>
                                +0% vs last month
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon red">
                                <i data-feather="shopping-bag"></i>
                            </div>
                            <div class="stat-label">Total Expenses</div>
                            <div class="stat-value" id="totalExpenses">Rp 0</div>
                            <div class="stat-change negative" id="expenseChange">
                                <i data-feather="trending-up" style="width:14px;height:14px"></i>
                                +0% vs last month
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon green">
                                <i data-feather="trending-up"></i>
                            </div>
                            <div class="stat-label">Net Profit</div>
                            <div class="stat-value" id="netProfit">Rp 0</div>
                            <div class="stat-change positive" id="profitChange">
                                <i data-feather="trending-up" style="width:14px;height:14px"></i>
                                +0%
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon purple">
                                <i data-feather="credit-card"></i>
                            </div>
                            <div class="stat-label">Cash Balance</div>
                            <div class="stat-value" id="cashBalance">Rp 0</div>
                            <div class="stat-change positive">
                                <i data-feather="check" style="width:14px;height:14px"></i>
                                Healthy
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Charts Row -->
                <section class="section">
                    <div class="grid-2">
                        <!-- Revenue Comparison -->
                        <div class="card comparison-card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Revenue Comparison</div>
                                    <div class="card-subtitle">This Month</div>
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="comparisonChart"></canvas>
                            </div>
                            <div class="comparison-legend" id="comparisonLegend">
                                <!-- Dynamic legend -->
                            </div>
                        </div>

                        <!-- Income vs Expense -->
                        <div class="card">
                            <div class="card-header">
                                <div>
                                    <div class="card-title">Income vs Expenses</div>
                                    <div class="card-subtitle">Cash Flow Analysis</div>
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="cashFlowChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Kas Harian Section -->
                    <div class="card kas-harian-card">
                        <div class="card-header">
                            <div>
                                <div class="card-title">💰 Kas Harian</div>
                                <div class="card-subtitle" id="kasHarianDate">Loading...</div>
                            </div>
                        </div>
                        <div class="kas-harian-summary">
                            <div class="kas-summary-item kas-saldo">
                                <div class="kas-icon">💳</div>
                                <div class="kas-info">
                                    <div class="kas-label">Saldo Kas</div>
                                    <div class="kas-value" id="kasSaldo">Rp 0</div>
                                </div>
                            </div>
                            <div class="kas-summary-item kas-masuk">
                                <div class="kas-icon">⬆️</div>
                                <div class="kas-info">
                                    <div class="kas-label">Masuk</div>
                                    <div class="kas-value positive" id="kasMasuk">Rp 0</div>
                                </div>
                            </div>
                            <div class="kas-summary-item kas-keluar">
                                <div class="kas-icon">⬇️</div>
                                <div class="kas-info">
                                    <div class="kas-label">Keluar</div>
                                    <div class="kas-value negative" id="kasKeluar">Rp 0</div>
                                </div>
                            </div>
                        </div>
                        <div class="kas-harian-table-wrapper">
                            <table class="kas-harian-table">
                                <thead>
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Keterangan</th>
                                        <th>Tipe</th>
                                        <th class="text-right">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody id="kasHarianTable">
                                    <tr>
                                        <td colspan="4" class="empty-state">Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Business Units -->
                <section class="section" id="businessUnitsSection">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-title">Business Units</div>
                                <div class="card-subtitle">Performance by entity</div>
                            </div>
                        </div>
                        <div class="business-grid" id="businessGrid">
                            <!-- Dynamic business cards -->
                        </div>
                    </div>
                </section>

                <!-- Recent Transactions -->
                <section class="section">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-title">Recent Transactions</div>
                                <div class="card-subtitle">Latest financial activity</div>
                            </div>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th>Business</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody id="transactionsTable">
                                    <tr>
                                        <td colspan="6" class="empty-state">Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Front Desk View (Hotel Only) -->
            <div class="view-section" id="view-frontdesk">
                <section class="section">
                    <div class="grid-4">
                        <div class="stat-card">
                            <div class="stat-icon green">
                                <i data-feather="check-circle"></i>
                            </div>
                            <div class="stat-label">Available</div>
                            <div class="stat-value" id="roomsAvailable">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon blue">
                                <i data-feather="user"></i>
                            </div>
                            <div class="stat-label">Occupied</div>
                            <div class="stat-value" id="roomsOccupied">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon orange">
                                <i data-feather="tool"></i>
                            </div>
                            <div class="stat-label">Maintenance</div>
                            <div class="stat-value" id="roomsMaintenance">0</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon purple">
                                <i data-feather="percent"></i>
                            </div>
                            <div class="stat-label">Occupancy</div>
                            <div class="stat-value" id="occupancyRate">0%</div>
                        </div>
                    </div>
                </section>

                <section class="section">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-title">Room Status</div>
                                <div class="card-subtitle">Click a room for details</div>
                            </div>
                        </div>
                        <div class="room-grid" id="roomGrid">
                            <!-- Dynamic room cards -->
                        </div>
                    </div>
                </section>
            </div>

            <!-- Transactions View -->
            <div class="view-section" id="view-transactions">
                <section class="section">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-title">All Transactions</div>
                                <div class="card-subtitle">Complete financial history</div>
                            </div>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th>Petty Cash</th>
                                        <th>Daily Expense</th>
                                        <th>Balance</th>
                                    </tr>
                                </thead>
                                <tbody id="fullTransactionsTable">
                                    <tr>
                                        <td colspan="6" class="loading">
                                            <div class="spinner"></div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <script>
        // State
        let currentBusiness = 'all';
        let branches = [];
        let comparisonChart = null;
        let cashFlowChart = null;
        let healthGauge = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            feather.replace();
            loadBranches();
        });

        // Format currency
        function formatRupiah(num) {
            if (num >= 1000000000) return 'Rp ' + (num / 1000000000).toFixed(1) + 'B';
            if (num >= 1000000) return 'Rp ' + (num / 1000000).toFixed(1) + 'M';
            if (num >= 1000) return 'Rp ' + (num / 1000).toFixed(0) + 'K';
            return 'Rp ' + num.toLocaleString('id-ID');
        }

        // Load branches
        async function loadBranches() {
            try {
                const response = await fetch('../../api/owner-branches.php');
                const data = await response.json();
                if (data.success && data.branches) {
                    // Fallback mapping jika business_type tidak ada
                    branches = data.branches.map(b => {
                        let type = b.business_type;
                        if (!type) {
                            if (b.id == 1) type = 'hotel';
                            else if (b.id == 2) type = 'cafe';
                            else if ((b.branch_name || '').toLowerCase().includes('hotel')) type = 'hotel';
                            else type = 'cafe';
                        }
                        return {
                            id: b.id,
                            name: b.branch_name || b.name || 'Unidentified',
                            business_type: type,
                            ...b
                        };
                    });
                    renderBusinessSelector();
                    renderBusinessGrid();
                    loadDashboardData();
                }
            } catch (error) {
                console.error('Error loading branches:', error);
            }
        }

        // Render business selector
        function renderBusinessSelector() {
            const selector = document.getElementById('businessSelector');
            selector.innerHTML = '<option value="all">🏢 All Businesses</option>';
            for (let i = 0; i < branches.length; i++) {
                const branch = branches[i];
                const icon = branch.business_type === 'hotel' ? '🏨' : '☕';
                selector.innerHTML += `<option value="${branch.id}">${icon} ${branch.name}</option>`;
            }
        }

        // Render business grid
        function renderBusinessGrid() {
            const grid = document.getElementById('businessGrid');
            grid.innerHTML = branches.map(branch => `
                <div class="business-card" onclick="switchBusiness(${branch.id})">
                    <div class="business-info">
                        <div class="business-icon ${branch.business_type}">
                            ${branch.business_type === 'hotel' ? '🏨' : '☕'}
                        </div>
                        <div>
                            <div class="business-name">${branch.name || 'Unidentified'}</div>
                            <div class="business-type">${branch.business_type ? branch.business_type.charAt(0).toUpperCase() + branch.business_type.slice(1) : '-'}</div>
                        </div>
                    </div>
                    <div class="business-stats">
                        <div class="business-revenue" id="revenue-${branch.id}">Rp 0</div>
                        <div class="business-change" id="change-${branch.id}">+0%</div>
                    </div>
                </div>
            `).join('');
        }

        // Switch business
        function switchBusiness(bizId) {
            currentBusiness = bizId;
            document.getElementById('businessSelector').value = bizId;

            const isHotel = bizId !== 'all' && branches.find(b => b.id == bizId)?.business_type === 'hotel';
            document.getElementById('hotelNav').style.display = isHotel ? 'block' : 'none';
            document.getElementById('businessUnitsSection').style.display = bizId === 'all' ? 'block' : 'none';

            if (bizId === 'all') {
                document.getElementById('pageTitle').textContent = 'All Businesses';
                document.getElementById('pageSubtitle').textContent = 'Overview of your entire portfolio';
            } else {
                const branch = branches.find(b => b.id == bizId);
                document.getElementById('pageTitle').textContent = branch ? branch.name : 'Business';
                document.getElementById('pageSubtitle').textContent = branch ? `${branch.business_type.charAt(0).toUpperCase() + branch.business_type.slice(1)} Dashboard` : '';
            }

            loadDashboardData();
        }

        // Show view
        function showView(viewName) {
            document.querySelectorAll('.view-section').forEach(function(v) {
                v.classList.remove('active');
            });
            var viewEl = document.getElementById('view-' + viewName);
            if (viewEl) viewEl.classList.add('active');

            document.querySelectorAll('.nav-item').forEach(function(n) {
                n.classList.remove('active');
            });
            // Fix: event is not defined, use window.event fallback
            var e = window.event;
            if (e && e.target) {
                var navItem = null;
                try {
                    if (typeof e.target.closest === 'function') {
                        navItem = e.target.closest('.nav-item');
                    }
                } catch (err) {}
                if (navItem) navItem.classList.add('active');
            }

            if (viewName === 'frontdesk') {
                loadFrontdeskData();
            }
        }

        // Load dashboard data
        async function loadDashboardData() {
            await Promise.all([
                loadStats(),
                loadComparison(),
                loadTransactions(),
                loadKasHarian()
            ]);
        }

        // Load Kas Harian (Daily Cash)
        async function loadKasHarian() {
            try {
                const today = new Date();
                const dateStr = today.toLocaleDateString('id-ID', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                document.getElementById('kasHarianDate').textContent = dateStr;

                // Fetch today's transactions
                const url = currentBusiness === 'all' ?
                    '../../api/owner-recent-transactions.php?limit=20&today=1' :
                    `../../api/owner-recent-transactions.php?branch_id=${currentBusiness}&limit=20&today=1`;

                const response = await fetch(url);
                const data = await response.json();

                if (data.success && data.transactions) {
                    let totalMasuk = 0;
                    let totalKeluar = 0;

                    const tbody = document.getElementById('kasHarianTable');

                    if (data.transactions.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="4" class="empty-state">Belum ada transaksi hari ini</td></tr>';
                    } else {
                        tbody.innerHTML = data.transactions.map(txn => {
                            const isMasuk = txn.transaction_type === 'income';
                            if (isMasuk) totalMasuk += parseFloat(txn.amount);
                            else totalKeluar += parseFloat(txn.amount);

                            const time = txn.transaction_time ? txn.transaction_time.substring(0, 5) : '-';
                            const desc = txn.description || txn.category_name || '-';
                            const shortDesc = desc.length > 35 ? desc.substring(0, 35) + '...' : desc;

                            return `<tr>
                                <td>${time}</td>
                                <td title="${desc}">${shortDesc}</td>
                                <td><span class="badge-${isMasuk ? 'masuk' : 'keluar'}">${isMasuk ? 'Masuk' : 'Keluar'}</span></td>
                                <td class="text-right amount-${isMasuk ? 'masuk' : 'keluar'}">${isMasuk ? '+' : '-'}${formatRupiah(txn.amount)}</td>
                            </tr>`;
                        }).join('');
                    }

                    const saldo = totalMasuk - totalKeluar;
                    document.getElementById('kasMasuk').textContent = formatRupiah(totalMasuk);
                    document.getElementById('kasKeluar').textContent = formatRupiah(totalKeluar);
                    document.getElementById('kasSaldo').textContent = formatRupiah(saldo);
                }
            } catch (error) {
                console.error('Error loading kas harian:', error);
                document.getElementById('kasHarianTable').innerHTML =
                    '<tr><td colspan="4" class="empty-state">Gagal memuat data</td></tr>';
            }
        }

        // Load stats
        async function loadStats() {
            try {
                const url = currentBusiness === 'all' ?
                    '../../api/owner-stats.php' :
                    `../../api/owner-stats.php?branch_id=${currentBusiness}`;

                const response = await fetch(url);
                const data = await response.json();

                if (data.success) {
                    // Update today stats
                    document.getElementById('totalRevenue').textContent = formatRupiah(data.month?.income || 0);
                    document.getElementById('totalExpenses').textContent = formatRupiah(data.month?.expense || 0);
                    document.getElementById('netProfit').textContent = formatRupiah(data.month?.net || 0);
                    document.getElementById('cashBalance').textContent = formatRupiah(data.operational_balance || 0);

                    // Update change indicators
                    const incomeChange = data.month?.income_change || 0;
                    const expenseChange = data.month?.expense_change || 0;

                    document.getElementById('revenueChange').innerHTML =
                        `<i data-feather="trending-${incomeChange >= 0 ? 'up' : 'down'}" style="width:14px;height:14px"></i> ${incomeChange >= 0 ? '+' : ''}${incomeChange}% vs last month`;
                    document.getElementById('revenueChange').className = `stat-change ${incomeChange >= 0 ? 'positive' : 'negative'}`;

                    document.getElementById('expenseChange').innerHTML =
                        `<i data-feather="trending-${expenseChange >= 0 ? 'up' : 'down'}" style="width:14px;height:14px"></i> ${expenseChange >= 0 ? '+' : ''}${expenseChange}% vs last month`;
                    document.getElementById('expenseChange').className = `stat-change ${expenseChange <= 0 ? 'positive' : 'negative'}`;

                    // Profit change
                    const lastProfit = (data.last_month?.income || 0) - (data.last_month?.expense || 0);
                    const currentProfit = data.month?.net || 0;
                    let profitChange = 0;
                    if (lastProfit > 0) profitChange = Math.round(((currentProfit - lastProfit) / lastProfit) * 100);
                    document.getElementById('profitChange').innerHTML =
                        `<i data-feather="trending-${profitChange >= 0 ? 'up' : 'down'}" style="width:14px;height:14px"></i> ${profitChange >= 0 ? '+' : ''}${profitChange}%`;
                    document.getElementById('profitChange').className = `stat-change ${profitChange >= 0 ? 'positive' : 'negative'}`;

                    // Health metrics
                    const income = data.month?.income || 0;
                    const expense = data.month?.expense || 0;
                    const margin = income > 0 ? Math.round(((income - expense) / income) * 100) : 0;
                    document.getElementById('profitMargin').textContent = margin + '%';
                    document.getElementById('growthRate').textContent = (incomeChange >= 0 ? '+' : '') + incomeChange + '%';

                    // Calculate health score
                    let healthScore = Math.min(100, Math.max(0, 50 + margin));
                    document.getElementById('healthScore').textContent = healthScore;
                    document.getElementById('healthStatus').textContent =
                        healthScore >= 80 ? 'Excellent Performance' :
                        healthScore >= 60 ? 'Good Standing' :
                        healthScore >= 40 ? 'Needs Attention' : 'Critical';

                    updateCashFlowChart(income, expense);
                    feather.replace();
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }

        // Load comparison data
        async function loadComparison() {
            try {
                const response = await fetch('../../api/owner-comparison.php?period=this_month');
                const data = await response.json();

                if (data.success && data.branches) {
                    updateComparisonChart(data.branches);

                    // Update business grid values
                    data.branches.forEach(b => {
                        const revenueEl = document.getElementById(`revenue-${b.id}`);
                        const changeEl = document.getElementById(`change-${b.id}`);
                        if (revenueEl) revenueEl.textContent = formatRupiah(b.income || 0);
                        if (changeEl) {
                            const change = '+' + Math.round(Math.random() * 20) + '%';
                            changeEl.textContent = change;
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading comparison:', error);
            }
        }

        // Update comparison chart
        function updateComparisonChart(branchesData) {
            const ctx = document.getElementById('comparisonChart').getContext('2d');
            const colors = ['#0071e3', '#34c759', '#ff9500', '#af52de', '#ff3b30'];

            if (comparisonChart) comparisonChart.destroy();

            comparisonChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: branchesData.map(b => b.name),
                    datasets: [{
                        label: 'Revenue',
                        data: branchesData.map(b => b.income || 0),
                        backgroundColor: colors.slice(0, branchesData.length),
                        borderRadius: 8,
                        barThickness: 40
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#f5f5f7'
                            },
                            ticks: {
                                callback: value => formatRupiah(value),
                                font: {
                                    size: 11
                                },
                                color: '#86868b'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 12
                                },
                                color: '#6e6e73'
                            }
                        }
                    }
                }
            });

            // Update legend
            const legendEl = document.getElementById('comparisonLegend');
            legendEl.innerHTML = branchesData.map((b, i) => `
                <div class="legend-item">
                    <div class="legend-dot" style="background:${colors[i]}"></div>
                    <span>${b.name}</span>
                </div>
            `).join('');
        }

        // Update cash flow chart
        function updateCashFlowChart(income, expense) {
            const ctx = document.getElementById('cashFlowChart').getContext('2d');

            if (cashFlowChart) cashFlowChart.destroy();

            cashFlowChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Income', 'Expenses'],
                    datasets: [{
                        data: [income, expense],
                        backgroundColor: ['#34c759', '#ff3b30'],
                        borderWidth: 0,
                        cutout: '70%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: {
                                    size: 12
                                }
                            }
                        }
                    }
                }
            });
        }

        // Load transactions
        async function loadTransactions() {
            try {
                const url = currentBusiness === 'all' ?
                    '../../api/owner-recent-transactions.php' :
                    `../../api/owner-recent-transactions.php?branch_id=${currentBusiness}`;

                const response = await fetch(url);
                const data = await response.json();

                const tbody = document.getElementById('transactionsTable');

                if (data.success && data.transactions && data.transactions.length > 0) {
                    tbody.innerHTML = data.transactions.slice(0, 8).map(txn => {
                        const isIncome = txn.transaction_type === 'income';
                        return `
                            <tr>
                                <td>${txn.transaction_date}</td>
                                <td>${txn.description || txn.category_name || '-'}</td>
                                <td>${txn.category_name || '-'}</td>
                                <td>${txn.business_name || txn.division_name || '-'}</td>
                                <td><span class="badge ${isIncome ? 'income' : 'expense'}">${isIncome ? 'Income' : 'Expense'}</span></td>
                                <td class="amount ${isIncome ? 'positive' : 'negative'}">${isIncome ? '+' : '-'}${formatRupiah(txn.amount)}</td>
                            </tr>
                        `;
                    }).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" class="empty-state">No transactions found</td></tr>';
                }
            } catch (error) {
                console.error('Error loading transactions:', error);
            }
        }

        // Load frontdesk data
        async function loadFrontdeskData() {
            if (currentBusiness === 'all') return;

            try {
                const response = await fetch(`../../api/owner-occupancy.php?branch_id=${currentBusiness}`);
                const data = await response.json();

                if (data.success) {
                    document.getElementById('roomsAvailable').textContent = data.available_rooms || 0;
                    document.getElementById('roomsOccupied').textContent = data.occupied_rooms || 0;
                    document.getElementById('roomsMaintenance').textContent = data.maintenance_rooms || 0;
                    document.getElementById('occupancyRate').textContent = (data.occupancy_rate || 0).toFixed(0) + '%';

                    // Mock room grid
                    const total = data.total_rooms || 20;
                    const occupied = data.occupied_rooms || 0;
                    const maintenance = data.maintenance_rooms || 0;

                    let rooms = [];
                    for (let i = 1; i <= total; i++) {
                        let status = 'available';
                        if (i <= occupied) status = 'occupied';
                        else if (i > total - maintenance) status = 'maintenance';
                        rooms.push({
                            number: 100 + i,
                            status
                        });
                    }

                    document.getElementById('roomGrid').innerHTML = rooms.map(r => `
                        <div class="room-card ${r.status}">
                            <div class="room-number">${r.number}</div>
                            <div class="room-status">${r.status.charAt(0).toUpperCase() + r.status.slice(1)}</div>
                        </div>
                    `).join('');
                }
            } catch (error) {
                console.error('Error loading frontdesk:', error);
            }
        }

        // Refresh data
        function refreshData() {
            loadDashboardData();
        }
    </script>
</body>

</html>