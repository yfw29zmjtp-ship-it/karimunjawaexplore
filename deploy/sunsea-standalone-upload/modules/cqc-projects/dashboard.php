<?php
/**
 * CQC Projects Dashboard
 * Dashboard untuk solar panel projects dengan grafik progress
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('cqc-projects')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once 'db-helper.php';

// Get database connection untuk CQC
try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle Start Project Action
if (isset($_GET['action']) && $_GET['action'] === 'start' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE cqc_projects SET status = 'installation' WHERE id = ? AND status IN ('planning', 'on_hold')");
        $stmt->execute([$_GET['id']]);
        header('Location: dashboard.php?success=started');
        exit;
    } catch (Exception $e) {
        // Ignore error, proceed to dashboard
    }
}

// Handle Finish/Complete Project Action
if (isset($_GET['action']) && $_GET['action'] === 'finish' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE cqc_projects SET status = 'completed', progress_percentage = 100, actual_completion = CURDATE() WHERE id = ? AND status NOT IN ('completed', 'planning')");
        $stmt->execute([$_GET['id']]);
        header('Location: report.php?id=' . intval($_GET['id']) . '&just_completed=1');
        exit;
    } catch (Exception $e) {
        // Ignore error, proceed to dashboard
    }
}

// Get project statistics
$stats = [];
try {
    // Total projects
    $result = $pdo->query("SELECT COUNT(*) as count FROM cqc_projects");
    $stats['total'] = (int)$result->fetch()['count'];
    
    // By status
    $result = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM cqc_projects 
        GROUP BY status
    ");
    $stats['by_status'] = [];
    while ($row = $result->fetch()) {
        $stats['by_status'][$row['status']] = (int)$row['count'];
    }
    
    // Total budget vs spent
    $result = $pdo->query("
        SELECT 
            SUM(budget_idr) as total_budget,
            SUM(spent_idr) as total_spent
        FROM cqc_projects
    ");
    $budget = $result->fetch();
    $stats['total_budget'] = (float)($budget['total_budget'] ?? 0);
    $stats['total_spent'] = (float)($budget['total_spent'] ?? 0);
    $stats['remaining'] = $stats['total_budget'] - $stats['total_spent'];
    
    // Active projects (ongoing + installation)
    $result = $pdo->query("
        SELECT COUNT(*) as count 
        FROM cqc_projects 
        WHERE status IN ('procurement', 'installation', 'testing')
    ");
    $stats['active'] = (int)$result->fetch()['count'];
    
    // Average progress
    $result = $pdo->query("
        SELECT AVG(progress_percentage) as avg_progress 
        FROM cqc_projects 
        WHERE status != 'planning'
    ");
    $progress = $result->fetch();
    $stats['avg_progress'] = (int)($progress['avg_progress'] ?? 0);
    
} catch (Exception $e) {
    // Table might not exist yet
    $stats = [
        'total' => 0,
        'by_status' => [],
        'total_budget' => 0,
        'total_spent' => 0,
        'remaining' => 0,
        'active' => 0,
        'avg_progress' => 0
    ];
}

// Get running projects (untuk quick view)
$running_projects = [];
try {
    $stmt = $pdo->query("
        SELECT id, project_name, client_name, status, progress_percentage, 
               budget_idr, spent_idr, start_date, estimated_completion, solar_capacity_kwp
        FROM cqc_projects
        WHERE status IN ('procurement', 'installation', 'testing')
        ORDER BY progress_percentage DESC
        LIMIT 5
    ");
    $running_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist
}

// Get unpaid invoice totals per project (for Finish gate check)
$unpaid_by_project = [];
try {
    $stmt = $pdo->query("
        SELECT project_id, COUNT(*) as unpaid_count, SUM(total_amount) as unpaid_total
        FROM cqc_termin_invoices
        WHERE payment_status NOT IN ('paid')
        GROUP BY project_id
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $unpaid_by_project[$row['project_id']] = $row;
    }
} catch (Exception $e) {
    // table may not exist yet
}

// Get ALL projects (including planning, on_hold, completed)
$all_projects = [];
try {
    $stmt = $pdo->query("
        SELECT id, project_name, project_code, client_name, status, progress_percentage, 
               budget_idr, spent_idr, start_date, estimated_completion, location
        FROM cqc_projects
        ORDER BY 
            CASE status 
                WHEN 'installation' THEN 1
                WHEN 'testing' THEN 2
                WHEN 'procurement' THEN 3
                WHEN 'planning' THEN 4
                WHEN 'on_hold' THEN 5
                WHEN 'completed' THEN 6
            END,
            updated_at DESC
    ");
    $all_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist
}

// Get recent transactions (combined from cash_book and cqc_project_expenses)
$recent_transactions = [];
try {
    // Get from cash_book with project info
    $stmt = $pdo->query("
        SELECT 
            cb.id,
            cb.transaction_date,
            cb.transaction_time,
            cb.transaction_type,
            cb.amount,
            cb.description,
            cb.source_type,
            cb.created_at,
            'cashbook' as source_table
        FROM cash_book cb
        ORDER BY cb.transaction_date DESC, cb.transaction_time DESC
        LIMIT 15
    ");
    $cashbook_txns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get from cqc_project_expenses with project info
    $stmt = $pdo->query("
        SELECT 
            pe.id,
            pe.expense_date as transaction_date,
            TIME(pe.created_at) as transaction_time,
            'expense' as transaction_type,
            pe.amount,
            pe.description,
            'cqc_project' as source_type,
            pe.created_at,
            pe.project_id,
            p.project_code,
            p.project_name,
            ec.category_name,
            ec.category_icon,
            'project_expense' as source_table
        FROM cqc_project_expenses pe
        LEFT JOIN cqc_projects p ON pe.project_id = p.id
        LEFT JOIN cqc_expense_categories ec ON pe.category_id = ec.id
        ORDER BY pe.expense_date DESC, pe.created_at DESC
        LIMIT 15
    ");
    $expense_txns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge and sort by date
    $all_recent = array_merge($cashbook_txns, $expense_txns);
    usort($all_recent, function($a, $b) {
        $dateA = $a['transaction_date'] . ' ' . ($a['transaction_time'] ?? '00:00:00');
        $dateB = $b['transaction_date'] . ' ' . ($b['transaction_time'] ?? '00:00:00');
        return strtotime($dateB) - strtotime($dateA);
    });
    $recent_transactions = array_slice($all_recent, 0, 10);
    
    // Get project map for cashbook transactions
    $project_map = [];
    foreach ($all_projects as $p) {
        $project_map[$p['id']] = $p;
    }
    
} catch (Exception $e) {
    error_log('Recent transactions error: ' . $e->getMessage());
}

$pageTitle = "CQC Projects Dashboard";
$pageSubtitle = "Solar Panel Installation Project Management";

$additionalCSS = [];
$inlineStyles = '<style>
/* Chart.js */
</style>';

include '../../includes/header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<style>
        /* 2027 Elegant Design System - Navy + Gold Theme */
        :root {
            --cqc-primary: #0d1f3c;
            --cqc-primary-light: #1a3a5c;
            --cqc-accent: #f0b429;
            --cqc-accent-dark: #d4960d;
            --cqc-success: #10b981;
            --cqc-warning: #f59e0b;
            --cqc-danger: #ef4444;
            --cqc-text: #0d1f3c;
            --cqc-muted: #64748b;
            --cqc-border: #e2e8f0;
            --cqc-bg: #f8fafc;
        }
        
        .cqc-container { 
            max-width: 100%; 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        /* Header - Clean White Design */
        .cqc-header {
            background: #fff;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 16px;
            display: flex; justify-content: space-between; align-items: center;
            border: 1px solid var(--cqc-border);
            border-left: 4px solid var(--cqc-accent);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        .cqc-header h1 { 
            font-size: 18px; font-weight: 700; color: var(--cqc-primary); 
            margin: 0 0 4px; letter-spacing: -0.3px;
        }
        .cqc-header p { font-size: 12px; margin: 0; color: var(--cqc-muted); font-weight: 500; }
        .cqc-header button {
            background: var(--cqc-accent); color: var(--cqc-primary); border: none;
            padding: 8px 16px; border-radius: 8px; font-weight: 700;
            font-size: 12px; cursor: pointer; transition: all 0.2s;
            display: flex; align-items: center; gap: 6px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }
        .cqc-header button:hover { background: #ffc942; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); }

        /* Stats - Clean Cards */
        .cqc-stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 16px; }
        .cqc-stat-card {
            background: #fff; padding: 14px 16px; border-radius: 10px;
            border: 1px solid var(--cqc-border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            position: relative; overflow: hidden;
        }
        .cqc-stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: var(--cqc-border);
        }
        .cqc-stat-card.accent::before { background: var(--cqc-accent); }
        .cqc-stat-card.primary::before { background: var(--cqc-primary); }
        .cqc-stat-card.success::before { background: var(--cqc-success); }
        .cqc-stat-card.warning::before { background: var(--cqc-warning); }
        .cqc-stat-card.danger::before { background: var(--cqc-danger); }
        
        .cqc-stat-icon { 
            width: 32px; height: 32px; border-radius: 8px; 
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; margin-bottom: 10px; background: var(--cqc-bg);
        }
        .cqc-stat-label { 
            font-size: 11px; color: var(--cqc-muted); text-transform: uppercase; 
            font-weight: 600; letter-spacing: 0.5px; margin-bottom: 4px; 
        }
        .cqc-stat-value { 
            font-size: 22px; font-weight: 700; color: var(--cqc-primary); 
            letter-spacing: -0.5px; line-height: 1;
        }
        .cqc-stat-subtitle { font-size: 11px; color: var(--cqc-muted); margin-top: 4px; }

        /* Charts - Clean */
        .cqc-charts-section { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 16px; }
        .cqc-chart-card {
            background: #fff; padding: 16px; border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            border: 1px solid var(--cqc-border);
        }
        .cqc-chart-title {
            font-size: 12px; font-weight: 600; color: var(--cqc-primary);
            margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
        }
        .cqc-chart-title span {
            width: 24px; height: 24px; background: var(--cqc-bg); border-radius: 6px;
            display: flex; align-items: center; justify-content: center; font-size: 12px;
        }
        .cqc-chart-canvas { max-height: 160px; }

        /* Section title - Refined */
        .cqc-section-title {
            font-size: 13px; font-weight: 700; color: var(--cqc-primary);
            margin: 20px 0 10px; padding: 8px 12px;
            border-bottom: none;
            border-left: 4px solid var(--cqc-accent);
            background: rgba(248, 250, 252, 0.8);
            border-radius: 0 8px 8px 0;
            display: flex; align-items: center; gap: 8px;
        }

        /* Table - Modern Clean with Navy Header */
        .cqc-projects-table {
            background: #fff; border-radius: 10px; overflow: hidden;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--cqc-border);
        }
        .cqc-projects-table table { width: 100%; border-collapse: collapse; }
        .cqc-projects-table th {
            background: var(--cqc-bg); color: var(--cqc-primary);
            padding: 10px 14px; text-align: left;
            font-weight: 700; font-size: 11px; text-transform: uppercase; 
            letter-spacing: 0.4px; border-bottom: 2px solid var(--cqc-accent);
        }
        .cqc-projects-table td {
            padding: 12px 14px; border-bottom: 1px solid #f1f5f9;
            font-size: 13px; color: var(--cqc-text);
        }
        .cqc-projects-table tr:last-child td { border-bottom: none; }
        .cqc-projects-table tr:hover { background: #fafbfc; }

        .status-badge { 
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; 
        }
        .status-planning { background: #f1f5f9; color: #475569; }
        .status-procurement { background: #fef3c7; color: #92400e; }
        .status-installation { background: #d1fae5; color: #065f46; }
        .status-testing { background: #e0f2fe; color: #0369a1; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-on_hold { background: #fee2e2; color: #991b1b; }

        .cqc-progress-bar { 
            width: 100%; height: 4px; background: #e2e8f0; 
            border-radius: 2px; overflow: hidden; margin-bottom: 4px; 
        }
        .cqc-progress-fill { 
            height: 100%; background: linear-gradient(90deg, var(--cqc-accent), var(--cqc-accent-dark)); 
            border-radius: 2px; transition: width 0.4s ease;
        }
        .cqc-progress-text { font-size: 11px; color: var(--cqc-muted); font-weight: 500; }

        .cqc-action-links { display: flex; gap: 6px; }
        .cqc-action-links a {
            padding: 5px 10px; background: #fff; color: var(--cqc-text);
            border-radius: 6px; text-decoration: none; font-size: 11px; font-weight: 600;
            border: 1px solid var(--cqc-border); transition: all 0.15s;
        }
        .cqc-action-links a:hover { background: var(--cqc-bg); border-color: #cbd5e1; }
        .cqc-action-links a.btn-start { 
            background: var(--cqc-success); color: #fff; border-color: var(--cqc-success); 
        }
        .cqc-action-links a.btn-start:hover { background: #059669; }

        .cqc-empty-state { text-align: center; padding: 40px 20px; color: var(--cqc-muted); }
        .cqc-empty-state-icon { font-size: 36px; margin-bottom: 12px; opacity: 0.5; }
        .cqc-empty-state h3 { color: var(--cqc-text); margin-bottom: 6px; font-size: 14px; font-weight: 600; }
        .cqc-empty-state p { font-size: 12px; color: var(--cqc-muted); }
        .cqc-empty-state button {
            background: var(--cqc-accent); color: var(--cqc-primary); border: none;
            padding: 10px 20px; border-radius: 8px; cursor: pointer; margin-top: 14px;
            font-weight: 700; font-size: 12px; transition: all 0.2s;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }
        .cqc-empty-state button:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12); }

        /* Project Cards - Grid with Donut Charts */
        .cqc-project-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .cqc-project-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--cqc-border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 16px;
            display: flex;
            gap: 16px;
            align-items: flex-start;
            transition: all 0.2s;
        }
        .cqc-project-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            border-color: var(--cqc-accent);
        }
        
        /* Donut Chart Container */
        .cqc-card-chart {
            flex-shrink: 0;
            width: 90px;
            height: 90px;
            position: relative;
        }
        .cqc-card-chart canvas {
            width: 90px !important;
            height: 90px !important;
        }
        .cqc-card-chart-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        .cqc-card-chart-pct {
            font-size: 18px;
            font-weight: 800;
            color: var(--cqc-primary);
            line-height: 1;
        }
        .cqc-card-chart-label {
            font-size: 8px;
            color: var(--cqc-muted);
            text-transform: uppercase;
            font-weight: 600;
        }
        
        /* Card Content */
        .cqc-card-content {
            flex: 1;
            min-width: 0;
        }
        .cqc-card-header {
            margin-bottom: 10px;
        }
        .cqc-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--cqc-primary);
            margin: 0 0 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cqc-card-client {
            font-size: 11px;
            color: var(--cqc-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Budget Row */
        .cqc-card-budget {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 10px;
        }
        .cqc-card-budget-item {
            background: var(--cqc-bg);
            padding: 8px 10px;
            border-radius: 8px;
        }
        .cqc-card-budget-label {
            font-size: 9px;
            color: var(--cqc-muted);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .cqc-card-budget-value {
            font-size: 12px;
            font-weight: 700;
        }
        .cqc-card-budget-value.spent { color: var(--cqc-danger); }
        .cqc-card-budget-value.remaining { color: var(--cqc-success); }
        
        /* kWp & Actions */
        .cqc-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 8px;
            border-top: 1px solid var(--cqc-border);
        }
        .cqc-card-kwp {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 700;
            color: var(--cqc-accent-dark);
        }
        .cqc-card-kwp svg {
            width: 16px;
            height: 16px;
            color: var(--cqc-accent);
        }
        .cqc-card-actions {
            display: flex;
            gap: 4px;
        }
        .cqc-card-actions a {
            padding: 4px 8px;
            background: #fff;
            color: var(--cqc-text);
            border-radius: 5px;
            text-decoration: none;
            font-size: 10px;
            font-weight: 600;
            border: 1px solid var(--cqc-border);
            transition: all 0.15s;
        }
        .cqc-card-actions a:hover {
            background: var(--cqc-bg);
            border-color: var(--cqc-accent);
        }
        .cqc-card-actions a.btn-start {
            background: var(--cqc-success);
            color: #fff;
            border-color: var(--cqc-success);
        }

        @media (max-width: 768px) {
            .cqc-stats-grid { grid-template-columns: repeat(2,1fr); }
            .cqc-charts-section { grid-template-columns: 1fr; }
            .cqc-project-cards { grid-template-columns: 1fr; }
        }
</style>

    <div class="cqc-container">
        <div class="cqc-header">
            <div>
                <h1>Dashboard Proyek CQC</h1>
                <p>Solar Panel Installation Management</p>
            </div>
            <button onclick="location.href='add.php'"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg> Proyek Baru</button>
        </div>

        <div class="cqc-stats-grid">
            <div class="cqc-stat-card">
                <div class="cqc-stat-icon">📋</div>
                <div class="cqc-stat-label">Total Proyek</div>
                <div class="cqc-stat-value"><?php echo $stats['total']; ?></div>
            </div>
            <div class="cqc-stat-card accent">
                <div class="cqc-stat-icon">⚡</div>
                <div class="cqc-stat-label">Proyek Berjalan</div>
                <div class="cqc-stat-value"><?php echo $stats['active']; ?></div>
                <div class="cqc-stat-subtitle">Procurement, Installation, Testing</div>
            </div>
            <div class="cqc-stat-card success">
                <div class="cqc-stat-icon">✅</div>
                <div class="cqc-stat-label">Rata-rata Progress</div>
                <div class="cqc-stat-value"><?php echo $stats['avg_progress']; ?>%</div>
            </div>
            <div class="cqc-stat-card warning">
                <div class="cqc-stat-icon">💰</div>
                <div class="cqc-stat-label">Total Pengeluaran</div>
                <div class="cqc-stat-value">Rp <?php echo number_format($stats['total_spent'], 0); ?></div>
                <div class="cqc-stat-subtitle">dari Rp <?php echo number_format($stats['total_budget'], 0); ?></div>
            </div>
        </div>

        <div class="cqc-charts-section">
            <div class="cqc-chart-card">
                <div class="cqc-chart-title"><span>📊</span> Distribusi Status</div>
                <canvas id="statusChart" class="cqc-chart-canvas"></canvas>
            </div>
            <div class="cqc-chart-card">
                <div class="cqc-chart-title"><span>💵</span> Budget vs Spent</div>
                <canvas id="budgetChart" class="cqc-chart-canvas"></canvas>
            </div>
            <div class="cqc-chart-card">
                <div class="cqc-chart-title"><span>⏳</span> Progress</div>
                <canvas id="progressChart" class="cqc-chart-canvas"></canvas>
            </div>
        </div>

        <div class="cqc-section-title">Proyek Sedang Berjalan</div>
        
        <?php if (!empty($running_projects)): ?>
            <div class="cqc-project-cards">
                <?php foreach ($running_projects as $idx => $proj): 
                    $spent = floatval($proj['spent_idr'] ?? 0);
                    $budget = floatval($proj['budget_idr'] ?? 0);
                    $remaining = max(0, $budget - $spent);
                    $kwp = floatval($proj['solar_capacity_kwp'] ?? 0);
                ?>
                <div class="cqc-project-card">
                    <!-- Donut Chart -->
                    <div class="cqc-card-chart">
                        <canvas id="projChart<?php echo $idx; ?>"></canvas>
                        <div class="cqc-card-chart-center">
                            <div class="cqc-card-chart-pct"><?php echo $proj['progress_percentage']; ?>%</div>
                            <div class="cqc-card-chart-label">Progress</div>
                        </div>
                    </div>
                    
                    <!-- Content -->
                    <div class="cqc-card-content">
                        <div class="cqc-card-header">
                            <h4 class="cqc-card-title"><?php echo htmlspecialchars($proj['project_name']); ?></h4>
                            <div class="cqc-card-client">
                                <span class="status-badge status-<?php echo $proj['status']; ?>" style="font-size: 9px; padding: 2px 6px;">
                                    <?php echo ucfirst(str_replace('_', ' ', $proj['status'])); ?>
                                </span>
                                <?php if (!empty($proj['client_name'])): ?>
                                <span style="color: #999;">•</span>
                                <span><?php echo htmlspecialchars($proj['client_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Budget Info -->
                        <div class="cqc-card-budget">
                            <div class="cqc-card-budget-item">
                                <div class="cqc-card-budget-label">Terpakai</div>
                                <div class="cqc-card-budget-value spent">Rp <?php echo number_format($spent, 0, ',', '.'); ?></div>
                            </div>
                            <div class="cqc-card-budget-item">
                                <div class="cqc-card-budget-label">Sisa</div>
                                <div class="cqc-card-budget-value remaining">Rp <?php echo number_format($remaining, 0, ',', '.'); ?></div>
                            </div>
                        </div>
                        
                        <!-- kWp & Actions -->
                        <div class="cqc-card-footer">
                            <div class="cqc-card-kwp">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="5"/>
                                    <line x1="12" y1="1" x2="12" y2="3"/>
                                    <line x1="12" y1="21" x2="12" y2="23"/>
                                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                                    <line x1="1" y1="12" x2="3" y2="12"/>
                                    <line x1="21" y1="12" x2="23" y2="12"/>
                                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                                </svg>
                                <?php echo $kwp > 0 ? number_format($kwp, 1) . ' kWp' : '-'; ?>
                            </div>
                            <div class="cqc-card-actions">
                                <a href="detail.php?id=<?php echo $proj['id']; ?>">Detail</a>
                                <a href="add.php?id=<?php echo $proj['id']; ?>">Edit</a>
                                <?php 
                                $unpaidInfo = $unpaid_by_project[$proj['id']] ?? null;
                                $unpaidCount = $unpaidInfo ? (int)$unpaidInfo['unpaid_count'] : 0;
                                $unpaidTotal = $unpaidInfo ? floatval($unpaidInfo['unpaid_total']) : 0;
                                ?>
                                <a href="javascript:void(0)"
                                   style="background: #059669; color: #fff; border-color: #059669;"
                                   onclick="checkAndFinish(<?php echo $proj['id']; ?>, '<?php echo addslashes(htmlspecialchars($proj['project_name'])); ?>', <?php echo $unpaidCount; ?>, <?php echo $unpaidTotal; ?>)">✓ Finish</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
                    <div class="cqc-empty-state">
                <div class="cqc-empty-state-icon">📦</div>
                <h3>Tidak Ada Proyek Sedang Berjalan</h3>
                <p>Mulai dengan membuat proyek baru untuk instalasi panel surya.</p>
                <button onclick="location.href='add.php'">Buat Proyek Baru</button>
            </div>
        <?php endif; ?>

        <!-- ALL PROJECTS TABLE -->
        <div class="cqc-section-title" style="margin-top: 1.5rem;">Semua Proyek</div>
        
        <?php if (!empty($all_projects)): ?>
            <div class="cqc-projects-table">
                <table>
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama Proyek</th>
                            <th>Lokasi</th>
                            <th>Klien</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Budget</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_projects as $proj): ?>
                            <tr>
                                <td><code style="font-size: 10px; background: rgba(240,180,41,0.15); padding: 2px 6px; border-radius: 3px; color: #0d1f3c;"><?php echo htmlspecialchars($proj['project_code']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($proj['project_name']); ?></strong></td>
                                <td style="font-size: 11px; color: #666;"><?php echo htmlspecialchars($proj['location'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($proj['client_name'] ?? '-'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $proj['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $proj['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="cqc-progress-bar">
                                        <div class="cqc-progress-fill" style="width: <?php echo $proj['progress_percentage']; ?>%"></div>
                                    </div>
                                    <div class="cqc-progress-text"><?php echo $proj['progress_percentage']; ?>%</div>
                                </td>
                                <td>
                                    <div style="font-size: 10px; color: #888;">
                                        Rp <?php echo number_format($proj['budget_idr'] ?? 0, 0, ',', '.'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="cqc-action-links">
                                        <a href="detail.php?id=<?php echo $proj['id']; ?>">Lihat</a>
                                        <a href="add.php?id=<?php echo $proj['id']; ?>" style="color: #2563eb; font-weight: 600;">✏️ Edit</a>
                                        <?php if ($proj['status'] === 'planning' || $proj['status'] === 'on_hold'): ?>
                                        <a href="?action=start&id=<?php echo $proj['id']; ?>" class="btn-start" onclick="return confirm('Start proyek ini?')">Start</a>
                                        <?php elseif ($proj['status'] !== 'completed'): ?>
                                        <?php 
                                        $unpaidInfoT = $unpaid_by_project[$proj['id']] ?? null;
                                        $unpaidCountT = $unpaidInfoT ? (int)$unpaidInfoT['unpaid_count'] : 0;
                                        $unpaidTotalT = $unpaidInfoT ? floatval($unpaidInfoT['unpaid_total']) : 0;
                                        ?>
                                        <a href="javascript:void(0)" style="color: #059669; font-weight: 700;" onclick="checkAndFinish(<?php echo $proj['id']; ?>, '<?php echo addslashes(htmlspecialchars($proj['project_name'])); ?>', <?php echo $unpaidCountT; ?>, <?php echo $unpaidTotalT; ?>)">✓ Finish</a>
                                        <?php endif; ?>
                                        <?php if ($proj['status'] === 'completed'): ?>
                                        <a href="report.php?id=<?php echo $proj['id']; ?>" style="color: #2563eb; font-weight: 600;">📊 Laporan</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="cqc-empty-state">
                <div class="cqc-empty-state-icon">📋</div>
                <h3>Belum Ada Proyek</h3>
                <p>Mulai dengan membuat proyek baru untuk instalasi panel surya.</p>
                <button onclick="location.href='add.php'">Buat Proyek Baru</button>
            </div>
        <?php endif; ?>
        
        <!-- RECENT TRANSACTIONS TABLE -->
        <div class="cqc-section-title" style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <span>📊 Transaksi Terakhir</span>
            <a href="../cashbook/index.php" style="font-size: 11px; color: var(--cqc-accent); text-decoration: none; font-weight: 600;">Lihat Semua →</a>
        </div>
        
        <?php if (!empty($recent_transactions)): ?>
            <div class="cqc-projects-table">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 90px;">Tanggal</th>
                            <th style="width: 60px;">Waktu</th>
                            <th>Proyek</th>
                            <th>Kategori/Keterangan</th>
                            <th style="width: 80px;">Tipe</th>
                            <th style="width: 120px; text-align: right;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_transactions as $txn): 
                            // Parse project info from description for cashbook transactions
                            $projectCode = '-';
                            $projectName = '';
                            $categoryDisplay = $txn['description'] ?? '-';
                            
                            if ($txn['source_table'] === 'project_expense') {
                                $projectCode = $txn['project_code'] ?? '-';
                                $projectName = $txn['project_name'] ?? '';
                                $categoryDisplay = ($txn['category_icon'] ?? '📦') . ' ' . ($txn['category_name'] ?? 'Lainnya');
                                if (!empty($txn['description'])) {
                                    $categoryDisplay .= ' - ' . $txn['description'];
                                }
                            } else {
                                // Extract from [CQC_PROJECT:id] marker
                                if (preg_match('/\[CQC_PROJECT:(\d+)\]/', $txn['description'] ?? '', $matches)) {
                                    $projId = $matches[1];
                                    if (isset($project_map[$projId])) {
                                        $projectCode = $project_map[$projId]['project_code'];
                                        $projectName = $project_map[$projId]['project_name'];
                                    }
                                    // Clean description
                                    $categoryDisplay = preg_replace('/\[CQC_PROJECT:\d+\]\s*/', '', $txn['description']);
                                    $categoryDisplay = preg_replace('/\[[A-Z0-9\-]+\]\s*/', '', $categoryDisplay);
                                }
                            }
                            
                            $isIncome = ($txn['transaction_type'] === 'income');
                        ?>
                            <tr>
                                <td style="font-size: 11px; font-weight: 600;"><?php echo date('d/m/Y', strtotime($txn['transaction_date'])); ?></td>
                                <td style="font-size: 10px; color: #666;"><?php echo $txn['transaction_time'] ? date('H:i', strtotime($txn['transaction_time'])) : '-'; ?></td>
                                <td>
                                    <?php if ($projectCode !== '-'): ?>
                                        <code style="font-size: 9px; background: rgba(14,165,233,0.1); padding: 2px 5px; border-radius: 3px; color: #0284c7;"><?php echo htmlspecialchars($projectCode); ?></code>
                                        <span style="font-size: 10px; color: #666; margin-left: 4px;"><?php echo htmlspecialchars(mb_substr($projectName, 0, 15)); ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 11px;">
                                    <?php echo htmlspecialchars(mb_substr($categoryDisplay, 0, 40)); ?>
                                    <?php if (strlen($categoryDisplay) > 40): ?>...<?php endif; ?>
                                </td>
                                <td>
                                    <span style="display: inline-flex; align-items: center; gap: 3px; font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600; <?php echo $isIncome ? 'background: rgba(16,185,129,0.1); color: #059669;' : 'background: rgba(239,68,68,0.1); color: #dc2626;'; ?>">
                                        <?php echo $isIncome ? '↗ Masuk' : '↙ Keluar'; ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 700; font-size: 11px; <?php echo $isIncome ? 'color: #059669;' : 'color: #dc2626;'; ?>">
                                    <?php echo ($isIncome ? '+' : '-') . ' Rp ' . number_format($txn['amount'], 0, ',', '.'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 2rem; text-align: center; background: #f8fafc; border-radius: 8px; border: 1px dashed var(--cqc-border);">
                <div style="font-size: 32px; margin-bottom: 8px;">💰</div>
                <p style="margin: 0; color: var(--cqc-muted); font-size: 12px;">Belum ada transaksi</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // === Center Text Plugin (futuristic doughnut center) ===
        const centerTextPlugin = {
            id: 'centerText',
            afterDraw(chart) {
                if (!chart.config.options.plugins.centerText) return;
                const { text, subtext, color } = chart.config.options.plugins.centerText;
                const { ctx, chartArea: { left, right, top, bottom } } = chart;
                const cx = (left + right) / 2;
                const cy = (top + bottom) / 2;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                if (text) {
                    ctx.font = 'bold 16px -apple-system, BlinkMacSystemFont, sans-serif';
                    ctx.fillStyle = color || '#333';
                    ctx.fillText(text, cx, subtext ? cy - 6 : cy);
                }
                if (subtext) {
                    ctx.font = '700 7px -apple-system, BlinkMacSystemFont, sans-serif';
                    ctx.fillStyle = '#aaa';
                    ctx.fillText(subtext, cx, cy + 10);
                }
                ctx.restore();
            }
        };
        Chart.register(centerTextPlugin);

        // === Shared tooltip style ===
        const cqcTooltip = {
            backgroundColor: '#0d1f3c',
            titleColor: '#f0b429',
            bodyColor: '#fff',
            borderColor: 'rgba(240,180,41,0.4)',
            borderWidth: 1,
            cornerRadius: 4,
            padding: 6,
            titleFont: { size: 9, weight: '700' },
            bodyFont: { size: 10, weight: '600' },
            displayColors: true,
            boxPadding: 2
        };

        // === Gradient helper ===
        function cqcGrad(ctx, c1, c2) {
            const g = ctx.createLinearGradient(0, 0, 0, 250);
            g.addColorStop(0, c1);
            g.addColorStop(1, c2);
            return g;
        }

        // ── Status Chart ──
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            const sData = [
                <?php echo $stats['by_status']['planning'] ?? 0; ?>,
                <?php echo $stats['by_status']['procurement'] ?? 0; ?>,
                <?php echo $stats['by_status']['installation'] ?? 0; ?>,
                <?php echo $stats['by_status']['testing'] ?? 0; ?>,
                <?php echo $stats['by_status']['completed'] ?? 0; ?>,
                <?php echo $stats['by_status']['on_hold'] ?? 0; ?>
            ];
            const sTotal = sData.reduce((a,b) => a+b, 0);

            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Planning', 'Procurement', 'Installation', 'Testing', 'Completed', 'On Hold'],
                    datasets: [{
                        data: sData,
                        backgroundColor: ['#1a3050','#f0b429','#8899aa','#27ae60','#0d1f3c','#e74c3c'],
                        hoverBackgroundColor: ['#2a4060','#f5c842','#99aabb','#2ecc71','#1a3050','#ff6b6b'],
                        borderWidth: 0,
                        spacing: 2,
                        borderRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '70%',
                    plugins: {
                        centerText: { text: sTotal.toString(), subtext: 'PROYEK', color: '#0d1f3c' },
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, pointStyle: 'circle', padding: 6, font: { size: 8, weight: '600' }, color: '#777' }
                        },
                        tooltip: cqcTooltip
                    },
                    animation: { animateRotate: true, duration: 800, easing: 'easeOutQuart' }
                }
            });
        }

        // ── Budget Chart ──
        const budgetCtx = document.getElementById('budgetChart');
        if (budgetCtx) {
            const ctx2d = budgetCtx.getContext('2d');
            new Chart(budgetCtx, {
                type: 'bar',
                data: {
                    labels: ['Budget', 'Terpakai', 'Sisa'],
                    datasets: [{
                        label: 'Rp',
                        data: [
                            <?php echo $stats['total_budget']; ?>,
                            <?php echo $stats['total_spent']; ?>,
                            <?php echo $stats['remaining']; ?>
                        ],
                        backgroundColor: [
                            cqcGrad(ctx2d, '#0d1f3c', '#1a3050'),
                            cqcGrad(ctx2d, '#f0b429', '#f5c842'),
                            cqcGrad(ctx2d, '#27ae60', '#2ecc71')
                        ],
                        borderRadius: 4,
                        borderSkipped: false,
                        barPercentage: 0.55,
                        categoryPercentage: 0.7
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            ...cqcTooltip,
                            callbacks: { label: function(ctx) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(ctx.parsed.x); } }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false },
                            ticks: {
                                color: '#999', font: { size: 8, weight: '600' },
                                callback: function(v) { return v >= 1e9 ? 'Rp '+(v/1e9).toFixed(1)+'B' : 'Rp '+(v/1e6).toFixed(0)+'M'; }
                            }
                        },
                        y: { grid: { display: false }, ticks: { color: '#0d1f3c', font: { size: 9, weight: '600' } } }
                    },
                    animation: { duration: 800, easing: 'easeOutQuart' }
                }
            });
        }

        // ── Progress Chart ──
        const progressCtx = document.getElementById('progressChart');
        if (progressCtx) {
            const pVal = <?php echo $stats['avg_progress']; ?>;
            new Chart(progressCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Selesai', 'Tersisa'],
                    datasets: [{
                        data: [pVal, 100 - pVal],
                        backgroundColor: [
                            (function(){
                                const g = progressCtx.getContext('2d').createLinearGradient(0,0,180,180);
                                g.addColorStop(0, '#f0b429');
                                g.addColorStop(1, '#f5c842');
                                return g;
                            })(),
                            'rgba(0,0,0,0.06)'
                        ],
                        borderWidth: 0,
                        spacing: 2,
                        borderRadius: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '75%',
                    plugins: {
                        centerText: { text: pVal + '%', subtext: 'PROGRESS', color: '#d4960d' },
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, pointStyle: 'circle', padding: 6, font: { size: 8, weight: '600' }, color: '#777' }
                        },
                        tooltip: {
                            ...cqcTooltip,
                            callbacks: { label: function(ctx) { return ctx.label + ': ' + ctx.parsed + '%'; } }
                        }
                    },
                    animation: { animateRotate: true, duration: 1000, easing: 'easeOutQuart' }
                }
            });
        }

        // ── Project Card Charts ──
        <?php foreach ($running_projects as $idx => $proj): ?>
        (function() {
            const canvas = document.getElementById('projChart<?php echo $idx; ?>');
            if (canvas) {
                const progress = <?php echo $proj['progress_percentage']; ?>;
                const ctx = canvas.getContext('2d');
                const gradient = ctx.createLinearGradient(0, 0, 90, 90);
                gradient.addColorStop(0, '#f0b429');
                gradient.addColorStop(1, '#d4960d');
                
                new Chart(canvas, {
                    type: 'doughnut',
                    data: {
                        datasets: [{
                            data: [progress, 100 - progress],
                            backgroundColor: [gradient, 'rgba(0,0,0,0.06)'],
                            borderWidth: 0,
                            spacing: 1,
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: false,
                        maintainAspectRatio: false,
                        cutout: '72%',
                        plugins: {
                            legend: { display: false },
                            tooltip: { enabled: false }
                        },
                        animation: {
                            animateRotate: true,
                            duration: 600 + (<?php echo $idx; ?> * 150),
                            easing: 'easeOutQuart'
                        }
                    }
                });
            }
        })();
        <?php endforeach; ?>
    </script>

<!-- ===== FINISH PROJECT MODAL ===== -->
<div id="finishModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:14px; padding:24px 26px; max-width:480px; width:92%; box-shadow:0 20px 60px rgba(0,0,0,0.3); border-top:4px solid #f0b429; position:relative;">
        <button onclick="document.getElementById('finishModal').style.display='none'" style="position:absolute; top:12px; right:14px; background:none; border:none; font-size:18px; cursor:pointer; color:#aaa; line-height:1;">✕</button>
        
        <div style="font-size:28px; margin-bottom:10px;">🏁</div>
        <h3 id="finishModalTitle" style="color:#0d1f3c; font-size:15px; font-weight:700; margin:0 0 14px;"></h3>

        <!-- Unpaid warning box -->
        <div id="finishModalUnpaidBox" style="display:none; background:#fff8e1; border:1px solid #f0b429; border-left:4px solid #e65100; border-radius:8px; padding:14px; margin-bottom:16px;">
            <div style="font-weight:700; color:#e65100; font-size:13px; margin-bottom:6px;">⚠️ Masih ada tagihan belum lunas!</div>
            <div style="font-size:12px; color:#7c4700; line-height:1.6;">
                <strong id="finishUnpaidCount"></strong> invoice termin belum dibayar<br>
                Total tagihan: <strong id="finishUnpaidTotal" style="color:#e65100;"></strong>
            </div>
            <div style="font-size:11px; color:#9a7200; margin-top:8px; background:rgba(240,180,41,0.12); padding:8px; border-radius:6px;">
                💡 Disarankan buat <strong>Invoice Pelunasan</strong> terlebih dahulu agar tagihan tercatat lunas sebelum proyek diselesaikan.
            </div>
        </div>

        <!-- No unpaid message -->
        <div id="finishModalCleanBox" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px; margin-bottom:16px; font-size:12px; color:#166534;">
            ✅ Semua tagihan sudah lunas. Proyek siap diselesaikan.
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a id="btnBuatPelunasan" href="#" style="background:#0d1f3c; color:#f0b429; padding:10px 16px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none; display:none; align-items:center; gap:6px;">
                🧾 Buat Invoice Pelunasan
            </a>
            <a id="btnConfirmFinish" href="#" style="background:#059669; color:#fff; padding:10px 16px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                ✓ Selesaikan Proyek
            </a>
            <button onclick="document.getElementById('finishModal').style.display='none'" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; padding:10px 16px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer;">
                Batal
            </button>
        </div>
    </div>
</div>

<script>
function checkAndFinish(id, name, unpaidCount, unpaidTotal) {
    var modal = document.getElementById('finishModal');
    document.getElementById('finishModalTitle').textContent = 'Selesaikan proyek "' + name + '"?';
    document.getElementById('btnConfirmFinish').href = '?action=finish&id=' + id;

    if (unpaidCount > 0) {
        document.getElementById('finishModalUnpaidBox').style.display = 'block';
        document.getElementById('finishModalCleanBox').style.display = 'none';
        document.getElementById('finishUnpaidCount').textContent = unpaidCount;
        document.getElementById('finishUnpaidTotal').textContent = 'Rp ' + parseFloat(unpaidTotal).toLocaleString('id-ID');
        var pelBtn = document.getElementById('btnBuatPelunasan');
        pelBtn.href = '../sales/create-termin.php?project_id=' + id;
        pelBtn.style.display = 'inline-flex';
        document.getElementById('btnConfirmFinish').innerHTML = '⚡ Tetap Selesaikan';
    } else {
        document.getElementById('finishModalUnpaidBox').style.display = 'none';
        document.getElementById('finishModalCleanBox').style.display = 'block';
        document.getElementById('btnBuatPelunasan').style.display = 'none';
        document.getElementById('btnConfirmFinish').innerHTML = '✓ Selesaikan Proyek';
    }

    modal.style.display = 'flex';
    modal.addEventListener('click', function handler(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
            modal.removeEventListener('click', handler);
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
