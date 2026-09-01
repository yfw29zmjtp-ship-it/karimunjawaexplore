<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Check if user is owner or admin
if (!$auth->hasRole('owner') && !$auth->hasRole('admin')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Get company name from settings
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value']) 
    ? $companyNameSetting['setting_value'] 
    : 'Narayana';

$pageTitle = 'Laporan Kesehatan Perusahaan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?> - <?php echo $displayCompanyName; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            -webkit-tap-highlight-color: transparent;
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            padding: 0;
            background: #f8f9fa;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .mobile-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: linear-gradient(135deg, #1e1b4b, #4338ca);
            padding: 1rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            color: white;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .header-title {
            font-size: 1.125rem;
            font-weight: 700;
            margin: 0;
        }
        
        .header-subtitle {
            font-size: 0.75rem;
            opacity: 0.9;
        }
        
        .content-wrapper {
            padding: 1rem;
            padding-bottom: 100px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .health-score-card {
            background: white;
            padding: 2rem 1.5rem;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .score-circle {
            width: 150px;
            height: 150px;
            margin: 0 auto 1rem;
            position: relative;
        }
        
        .score-svg {
            transform: rotate(-90deg);
        }
        
        .score-bg {
            fill: none;
            stroke: #e5e7eb;
            stroke-width: 12;
        }
        
        .score-progress {
            fill: none;
            stroke-width: 12;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease;
        }
        
        .score-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 2.5rem;
            font-weight: 800;
        }
        
        .score-label {
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: -0.5rem;
        }
        
        .health-status {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 1rem 0 0.5rem;
        }
        
        .branch-selector {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1rem;
        }
        
        .branch-select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.938rem;
            font-weight: 600;
        }
        
        .section-card {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1rem;
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .metric-item {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
        }
        
        .metric-label {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
        }
        
        .metric-value {
            font-size: 1.25rem;
            font-weight: 700;
        }
        
        .alert-item {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 0.75rem;
            border-left: 4px solid;
        }
        
        .alert-urgent {
            background: #fef2f2;
            border-color: #dc2626;
        }
        
        .alert-high {
            background: #fef2f2;
            border-color: #ef4444;
        }
        
        .alert-medium {
            background: #fffbeb;
            border-color: #f59e0b;
        }
        
        .alert-title {
            font-weight: 700;
            font-size: 0.938rem;
            margin-bottom: 0.25rem;
        }
        
        .alert-message {
            font-size: 0.813rem;
            color: #6b7280;
        }
        
        .recommendation-item {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid #4338ca;
        }
        
        .rec-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.75rem;
        }
        
        .rec-title {
            font-weight: 700;
            font-size: 0.938rem;
            color: #111827;
        }
        
        .rec-priority {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .priority-urgent {
            background: #fef2f2;
            color: #dc2626;
        }
        
        .priority-high {
            background: #fffbeb;
            color: #f59e0b;
        }
        
        .priority-medium {
            background: #eff6ff;
            color: #3b82f6;
        }
        
        .rec-category {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
        }
        
        .rec-actions {
            list-style: none;
            padding: 0;
            margin: 0.5rem 0 0 0;
        }
        
        .rec-actions li {
            padding: 0.5rem 0;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.875rem;
            color: #374151;
        }
        
        .rec-actions li:last-child {
            border-bottom: none;
        }
        
        .rec-actions li:before {
            content: "→ ";
            color: #4338ca;
            font-weight: 700;
            margin-right: 0.5rem;
        }
        
        .strength-item {
            padding: 0.75rem;
            background: #f0fdf4;
            border-left: 4px solid #10b981;
            border-radius: 4px;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: #065f46;
        }
        
        .loading-container {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e5e7eb;
            border-top-color: #4338ca;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 1rem;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-around;
            padding: 0.75rem 0;
            box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.1);
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            color: #6b7280;
            font-size: 0.75rem;
            text-decoration: none;
            padding: 0.25rem 1rem;
        }
        
        .nav-item.active {
            color: #4338ca;
        }
        
        @media (min-width: 768px) {
            .content-wrapper {
                padding: 2rem;
            }
            
            .metric-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .bottom-nav {
                display: none;
            }
        }
    </style>
<body>
    <div class="mobile-header">
        <div class="header-content">
            <a href="dashboard.php" style="color: white; text-decoration: none;">
                <i data-feather="arrow-left" style="width: 20px; height: 20px;"></i>
            </a>
            <div style="display: flex; align-items: center; gap: 1rem; flex: 1; margin-left: 1rem;">
                <!-- Logo Hotel -->
                <img src="../../uploads/logos/logo-alt.png" 
                     alt="Narayana Hotel" 
                     style="height: 40px; width: auto; object-fit: contain; background: white; padding: 0.5rem; border-radius: 8px;"
                     onerror="console.error('Logo error'); this.style.display='none';">
                <div>
                    <div class="header-title">Company Health Report</div>
                    <div class="header-subtitle" id="branchName">All Branches</div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="content-wrapper">
        <div class="branch-selector">
            <label style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.5rem; display: block;">
                <i data-feather="map-pin" style="width: 14px; height: 14px;"></i>
                Select Branch
            </label>
            <select class="branch-select" id="branchSelect" onchange="loadHealthData()">
                <option value="">Loading branches...</option>
            </select>
        </div>
        
        <!-- Health Score Card -->
        <div class="health-score-card">
            <div class="score-circle">
                <svg class="score-svg" width="150" height="150">
                    <circle class="score-bg" cx="75" cy="75" r="65"></circle>
                    <circle class="score-progress" id="scoreCircle" cx="75" cy="75" r="65" 
                            stroke-dasharray="408.4" stroke-dashoffset="408.4"></circle>
                </svg>
                <div class="score-text" id="scoreText">-</div>
            </div>
            <div class="score-label">Health Score</div>
            <div class="health-status" id="healthStatus">Loading...</div>
            <div style="font-size: 0.813rem; color: #6b7280;" id="analysisDate">-</div>
        </div>
        
        <!-- Strengths -->
        <div class="section-card" id="strengthsSection" style="display: none;">
            <div class="section-title">
                <i data-feather="check-circle" style="width: 20px; height: 20px; color: #10b981;"></i>
                Business Strengths
            </div>
            <div id="strengthsList"></div>
        </div>
        
        <!-- Alerts -->
        <div class="section-card" id="alertsSection" style="display: none;">
            <div class="section-title">
                <i data-feather="alert-triangle" style="width: 20px; height: 20px; color: #ef4444;"></i>
                Attention Needed
            </div>
            <div id="alertsList"></div>
        </div>
        
        <!-- Key Metrics -->
        <div class="section-card">
            <div class="section-title">
                <i data-feather="trending-up" style="width: 20px; height: 20px; color: #4338ca;"></i>
                Key Metrics
            </div>
            <div class="metric-grid" id="metricsGrid">
                <div class="loading-container">
                    <div class="loading-spinner"></div>
                    <p>Analyzing data...</p>
                </div>
            </div>
        </div>
        
        <!-- AI Recommendations -->
        <div class="section-card">
            <div class="section-title">
                <i data-feather="cpu" style="width: 20px; height: 20px; color: #4338ca;"></i>
                AI Recommendations
            </div>
            <div id="recommendationsList">
                <div class="loading-container">
                    <div class="loading-spinner"></div>
                    <p>Generating recommendations...</p>
                </div>
            </div>
        </div>
        
        <div style="height: 80px;"></div>
    </div>
    
    <div class="bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i data-feather="home" style="width: 20px; height: 20px;"></i>
            Dashboard
        </a>
        <a href="health-report.php" class="nav-item active">
            <i data-feather="activity" style="width: 20px; height: 20px;"></i>
            Health
        </a>
        <a href="../../logout.php" class="nav-item">
            <i data-feather="log-out" style="width: 20px; height: 20px;"></i>
            Logout
        </a>
    </div>
    
    <script>
        let currentBranchId = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            feather.replace();
            loadBranches();
        });
        
        async function loadBranches() {
            try {
                const response = await fetch('../../api/owner-branches.php');
                const data = await response.json();
                
                if (data.success) {
                    const select = document.getElementById('branchSelect');
                    select.innerHTML = '<option value="">All Branches</option>';
                    
                    data.branches.forEach(branch => {
                        const option = document.createElement('option');
                        option.value = branch.id;
                        option.textContent = `${branch.branch_name} - ${branch.city}`;
                        select.appendChild(option);
                    });
                    
                    if (data.branches.length > 0) {
                        select.selectedIndex = 1;
                        currentBranchId = data.branches[0].id;
                        loadHealthData();
                    }
                }
            } catch (error) {
                console.error('Error loading branches:', error);
            }
        }
        
        async function loadHealthData() {
            const select = document.getElementById('branchSelect');
            currentBranchId = select.value;
            
            // Update branch name in header
            const selectedOption = select.options[select.selectedIndex];
            document.getElementById('branchName').textContent = selectedOption.text;
            
            try {
                const url = currentBranchId 
                    ? `../../api/owner-health-analysis.php?branch_id=${currentBranchId}`
                    : '../../api/owner-health-analysis.php';
                    
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    updateHealthScore(data.health_score, data.health_status, data.health_color);
                    updateMetrics(data.metrics, data.occupancy);
                    updateAlerts(data.alerts);
                    updateStrengths(data.strengths);
                    updateRecommendations(data.recommendations);
                    
                    document.getElementById('analysisDate').textContent = 
                        'Analyzed: ' + new Date(data.analysis_date).toLocaleString('en-US');
                }
            } catch (error) {
                console.error('Error loading health data:', error);
            }
        }
        
        function updateHealthScore(score, status, color) {
            // Update score text
            document.getElementById('scoreText').textContent = Math.round(score);
            document.getElementById('scoreText').style.color = color;
            document.getElementById('healthStatus').textContent = status;
            document.getElementById('healthStatus').style.color = color;
            
            // Animate circle
            const circle = document.getElementById('scoreCircle');
            const circumference = 408.4;
            const offset = circumference - (score / 100) * circumference;
            
            circle.style.stroke = color;
            circle.style.strokeDashoffset = offset;
        }
        
        function updateMetrics(metrics, occupancy) {
            const grid = document.getElementById('metricsGrid');
            grid.innerHTML = `
                <div class="metric-item">
                    <div class="metric-label">Profit Margin</div>
                    <div class="metric-value" style="color: ${metrics.profit_margin >= 15 ? '#10b981' : '#ef4444'};">
                        ${metrics.profit_margin.toFixed(1)}%
                    </div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Revenue Growth</div>
                    <div class="metric-value" style="color: ${metrics.income_growth >= 0 ? '#10b981' : '#ef4444'};">
                        ${metrics.income_growth >= 0 ? '+' : ''}${metrics.income_growth.toFixed(1)}%
                    </div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Expense Ratio</div>
                    <div class="metric-value" style="color: ${metrics.expense_ratio <= 70 ? '#10b981' : '#ef4444'};">
                        ${metrics.expense_ratio.toFixed(1)}%
                    </div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Occupancy Rate</div>
                    <div class="metric-value" style="color: ${occupancy.occupancy_rate >= 70 ? '#10b981' : '#f59e0b'};">
                        ${occupancy.occupancy_rate.toFixed(1)}%
                    </div>
                </div>
            `;
        }
        
        function updateAlerts(alerts) {
            const section = document.getElementById('alertsSection');
            const list = document.getElementById('alertsList');
            
            if (alerts.length === 0) {
                section.style.display = 'none';
                return;
            }
            
            section.style.display = 'block';
            list.innerHTML = alerts.map(alert => `
                <div class="alert-item alert-${alert.severity}">
                    <div class="alert-title">${alert.title}</div>
                    <div class="alert-message">${alert.message}</div>
                </div>
            `).join('');
        }
        
        function updateStrengths(strengths) {
            const section = document.getElementById('strengthsSection');
            const list = document.getElementById('strengthsList');
            
            if (strengths.length === 0) {
                section.style.display = 'none';
                return;
            }
            
            section.style.display = 'block';
            list.innerHTML = strengths.map(strength => `
                <div class="strength-item">${strength}</div>
            `).join('');
        }
        
        function updateRecommendations(recommendations) {
            const list = document.getElementById('recommendationsList');
            
            if (recommendations.length === 0) {
                list.innerHTML = '<p style="text-align: center; color: #10b981; padding: 2rem;">🎉 No recommendations - business is in excellent condition!</p>';
                return;
            }
            
            list.innerHTML = recommendations.map(rec => `
                <div class="recommendation-item">
                    <div class="rec-header">
                        <div>
                            <div class="rec-title">${rec.title}</div>
                            <div class="rec-category">📂 ${rec.category}</div>
                        </div>
                        <span class="rec-priority priority-${rec.priority}">${rec.priority.toUpperCase()}</span>
                    </div>
                    <ul class="rec-actions">
                        ${rec.actions.map(action => `<li>${action}</li>`).join('')}
                    </ul>
                </div>
            `).join('');
        }
        
        function formatRupiah(number) {
            return 'Rp ' + Number(number).toLocaleString('id-ID');
        }
    </script>
</body>
</html>
