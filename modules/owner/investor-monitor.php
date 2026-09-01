<?php
/**
 * INVESTOR & PROJECT MONITOR
 * Mobile-optimized project monitoring for owner
 * Clean, Compact, Modern - Light Theme
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/business_helper.php';

// Auth check
$role = $_SESSION['role'] ?? null;
if (!$role && isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    try {
        $authDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $roleStmt = $authDb->prepare("SELECT r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $roleStmt->execute([$_SESSION['user_id'] ?? 0]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($roleRow) {
            $role = $roleRow['role_code'];
            $_SESSION['role'] = $role;
        }
    } catch (Exception $e) {}
}

if (!$role || !in_array($role, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ../../login.php');
    exit;
}

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
$basePath = $isProduction ? '' : '/adf_system';

// Multi-business: get active business config
require_once __DIR__ . '/../../includes/business_access.php';
$allBusinesses = getUserAvailableBusinesses();
$activeBusinessId = getActiveBusinessId();

// Auto-switch if current business not in user's allowed list
if (!empty($allBusinesses) && !isset($allBusinesses[$activeBusinessId])) {
    $firstAllowed = array_key_first($allBusinesses);
    setActiveBusinessId($firstAllowed);
    $activeBusinessId = $firstAllowed;
}

$activeConfig = getActiveBusinessConfig();

// Database config - connect to BUSINESS database for investors/projects
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$businessDbName = getDbName($activeConfig['database'] ?? 'adf_narayana_hotel');
$businessName = $activeConfig['name'] ?? 'Unknown Business';

// Initialize variables
$investors = [];
$projects = [];
$totalCapital = 0;
$totalBudget = 0;
$totalExpenses = 0;
$projectExpenses = [];
$selectedProject = null;
$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$error = null;

try {
    // Connect to business database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$businessDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if investors table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'investors'");
    $hasInvestorTables = $tableCheck->rowCount() > 0;
    
    if ($hasInvestorTables) {
        // Get all investors
        $stmt = $pdo->query("SELECT * FROM investors ORDER BY total_capital DESC");
        $investors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($investors as $inv) {
            $totalCapital += $inv['total_capital'] ?? 0;
        }
        
        // Get all projects
        $stmt = $pdo->query("SELECT * FROM projects ORDER BY budget DESC");
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate real expenses for each project (expenses + salaries + division costs)
        foreach ($projects as &$proj) {
            $pid = $proj['id'];
            $totalBudget += $proj['budget'] ?? 0;
            
            // Get project_expenses
            $proj['total_expenses'] = 0;
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_expenses WHERE project_id = ?");
                $stmt->execute([$pid]);
                $proj['total_expenses'] = floatval($stmt->fetchColumn());
            } catch (Exception $e) {}
            
            // Get project_salaries
            $proj['total_gaji'] = 0;
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_salary),0) as total FROM project_salaries WHERE project_id = ?");
                $stmt->execute([$pid]);
                $proj['total_gaji'] = floatval($stmt->fetchColumn());
            } catch (Exception $e) {}
            
            // Get project_division_expenses
            $proj['total_divisi'] = 0;
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_division_expenses WHERE project_id = ?");
                $stmt->execute([$pid]);
                $proj['total_divisi'] = floatval($stmt->fetchColumn());
            } catch (Exception $e) {}
            
            // Calculate grand total
            $proj['grand_expenses'] = $proj['total_expenses'] + $proj['total_gaji'] + $proj['total_divisi'];
            $totalExpenses += $proj['grand_expenses'];
        }
        unset($proj);
        
        // If a project is selected, get its details and expenses
        if ($selectedProjectId) {
            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$selectedProjectId]);
            $selectedProject = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($selectedProject) {
                // Calculate grand expenses for selected project
                $selectedProject['total_expenses'] = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_expenses WHERE project_id = ?");
                    $stmt->execute([$selectedProjectId]);
                    $selectedProject['total_expenses'] = floatval($stmt->fetchColumn());
                } catch (Exception $e) {}
                
                $selectedProject['total_gaji'] = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_salary),0) as total FROM project_salaries WHERE project_id = ?");
                    $stmt->execute([$selectedProjectId]);
                    $selectedProject['total_gaji'] = floatval($stmt->fetchColumn());
                } catch (Exception $e) {}
                
                $selectedProject['total_divisi'] = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_division_expenses WHERE project_id = ?");
                    $stmt->execute([$selectedProjectId]);
                    $selectedProject['total_divisi'] = floatval($stmt->fetchColumn());
                } catch (Exception $e) {}
                
                $selectedProject['grand_expenses'] = $selectedProject['total_expenses'] + $selectedProject['total_gaji'] + $selectedProject['total_divisi'];
                
                // Get division breakdown for pie chart with detailed expenses
                $divisionBreakdown = [];
                $divisionDetails = [];
                
                // From project_expenses (if has division_name column)
                try {
                    $stmt = $pdo->query("DESCRIBE project_expenses");
                    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (in_array('division_name', $columns)) {
                        // Try with category join first
                        try {
                            $stmt = $pdo->prepare("
                                SELECT pe.division_name, pe.description, pe.amount, pe.expense_date, pec.category_name
                                FROM project_expenses pe
                                LEFT JOIN project_expense_categories pec ON pe.expense_category_id = pec.id
                                WHERE pe.project_id = ? 
                                  AND pe.division_name IS NOT NULL 
                                  AND pe.division_name != '' 
                                ORDER BY pe.expense_date DESC
                            ");
                            $stmt->execute([$selectedProjectId]);
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $dn = $row['division_name'];
                                if (!isset($divisionBreakdown[$dn])) {
                                    $divisionBreakdown[$dn] = 0;
                                    $divisionDetails[$dn] = [];
                                }
                                $divisionBreakdown[$dn] += floatval($row['amount']);
                                $divisionDetails[$dn][] = [
                                    'description' => $row['description'] ?? 'No description',
                                    'amount' => floatval($row['amount']),
                                    'date' => $row['expense_date'] ?? date('Y-m-d'),
                                    'category' => $row['category_name'] ?? 'Uncategorized',
                                    'type' => 'expense'
                                ];
                            }
                        } catch (Exception $e) {
                            // Fallback without category join
                            $stmt = $pdo->prepare("
                                SELECT division_name, description, amount, expense_date
                                FROM project_expenses
                                WHERE project_id = ? 
                                  AND division_name IS NOT NULL 
                                  AND division_name != '' 
                                ORDER BY expense_date DESC
                            ");
                            $stmt->execute([$selectedProjectId]);
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $dn = $row['division_name'];
                                if (!isset($divisionBreakdown[$dn])) {
                                    $divisionBreakdown[$dn] = 0;
                                    $divisionDetails[$dn] = [];
                                }
                                $divisionBreakdown[$dn] += floatval($row['amount']);
                                $divisionDetails[$dn][] = [
                                    'description' => $row['description'] ?? 'No description',
                                    'amount' => floatval($row['amount']),
                                    'date' => $row['expense_date'] ?? date('Y-m-d'),
                                    'category' => 'Expense',
                                    'type' => 'expense'
                                ];
                            }
                        }
                    }
                } catch (Exception $e) {}
                
                // From project_division_expenses
                try {
                    $stmt = $pdo->prepare("
                        SELECT division_name, description, amount, expense_date
                        FROM project_division_expenses 
                        WHERE project_id = ? 
                          AND division_name IS NOT NULL
                          AND division_name != ''
                        ORDER BY expense_date DESC
                    ");
                    $stmt->execute([$selectedProjectId]);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $dn = $row['division_name'];
                        if (!isset($divisionBreakdown[$dn])) {
                            $divisionBreakdown[$dn] = 0;
                            $divisionDetails[$dn] = [];
                        }
                        $divisionBreakdown[$dn] += floatval($row['amount']);
                        $divisionDetails[$dn][] = [
                            'description' => $row['description'] ?? 'Division expense',
                            'amount' => floatval($row['amount']),
                            'date' => $row['expense_date'] ?? date('Y-m-d'),
                            'category' => 'Division Cost',
                            'type' => 'division'
                        ];
                    }
                } catch (Exception $e) {}
                
                // Get project expenses list
                try {
                    $stmt = $pdo->prepare("
                        SELECT pe.*, pec.category_name 
                        FROM project_expenses pe 
                        LEFT JOIN project_expense_categories pec ON pe.expense_category_id = pec.id
                        WHERE pe.project_id = ? 
                        ORDER BY pe.expense_date DESC
                        LIMIT 20
                    ");
                    $stmt->execute([$selectedProjectId]);
                    $projectExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    // Fallback without category join
                    $stmt = $pdo->prepare("SELECT * FROM project_expenses WHERE project_id = ? ORDER BY expense_date DESC LIMIT 20");
                    $stmt->execute([$selectedProjectId]);
                    $projectExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

function rp($num) {
    if ($num >= 1000000000) {
        return 'Rp ' . number_format($num / 1000000000, 1, ',', '.') . 'B';
    } elseif ($num >= 1000000) {
        return 'Rp ' . number_format($num / 1000000, 1, ',', '.') . 'M';
    } else {
        return 'Rp ' . number_format($num, 0, ',', '.');
    }
}

function rpFull($num) {
    return 'Rp ' . number_format($num, 0, ',', '.');
}

$usagePercent = $totalBudget > 0 ? round(($totalExpenses / $totalBudget) * 100) : 0;

// Prepare chart data - Expenses by Project (using grand_expenses)
$chartExpensesByProject = [];
foreach ($projects as $proj) {
    $projExpenses = $proj['grand_expenses'] ?? 0;
    if ($projExpenses > 0) {
        $chartExpensesByProject[$proj['name'] ?? 'Project'] = $projExpenses;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Projects & Investors - Owner</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 80px;
        }
        
        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 16px;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 16px;
            border-radius: 20px;
            margin-bottom: 16px;
            text-align: center;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3);
        }
        
        .header-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .header-logo {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            margin: 0 auto 10px;
            background: white;
            padding: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .header-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .header-subtitle {
            font-size: 12px;
            opacity: 0.9;
        }
        
        /* Overview Cards */
        .overview-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .overview-card {
            background: var(--card);
            border-radius: 14px;
            padding: 14px;
            text-align: center;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        
        .overview-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }
        
        .overview-card.capital::before { background: var(--success); }
        .overview-card.budget::before { background: var(--info); }
        .overview-card.used::before { background: var(--warning); }
        .overview-card.remaining::before { background: var(--primary); }
        
        .overview-icon {
            font-size: 24px;
            margin-bottom: 6px;
        }
        
        .overview-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .overview-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
        }
        
        .overview-hint {
            font-size: 9px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        /* Progress Bar */
        .progress-section {
            background: var(--card);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .progress-content {
            display: flex;
            gap: 16px;
            align-items: center;
        }
        
        .progress-left {
            flex: 1;
        }
        
        .progress-chart {
            width: 120px;
            height: 120px;
            flex-shrink: 0;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .progress-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text);
        }
        
        .progress-percent {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .progress-bar {
            height: 10px;
            background: var(--border);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--warning));
            border-radius: 5px;
            transition: width 0.5s ease;
        }
        
        .progress-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 10px;
            color: var(--text-muted);
        }
        
        @media (max-width: 480px) {
            .progress-content {
                flex-direction: column;
            }
            .progress-chart {
                width: 100%;
                max-width: 200px;
                height: 180px;
                margin: 0 auto;
            }
        }
        
        /* Section Title */
        .section-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 16px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.02em;
            padding-left: 4px;
        }
        
        .section-title .badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        /* List Card */
        .list-card {
            background: var(--card);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .list-item {
            display: flex;
            align-items: center;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            color: inherit;
            transition: background 0.2s;
        }
        
        .list-item:last-child { border-bottom: none; }
        .list-item:hover { background: var(--bg); }
        
        .list-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-right: 12px;
        }
        
        .list-icon.investor {
            background: linear-gradient(135deg, #10b981, #34d399);
        }
        
        .list-icon.project {
            background: linear-gradient(135deg, #6366f1, #818cf8);
        }
        
        .list-info { flex: 1; }
        
        .list-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 2px;
        }
        
        .list-detail {
            font-size: 10px;
            color: var(--text-muted);
        }
        
        .list-amount {
            text-align: right;
        }
        
        .list-value {
            font-size: 12px;
            font-weight: 700;
            color: var(--success);
        }
        
        .list-label {
            font-size: 9px;
            color: var(--text-muted);
        }
        
        /* Project Detail */
        .project-detail {
            background: var(--card);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        
        .project-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
        }
        
        .project-status {
            font-size: 10px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
        }
        
        .project-status.active {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .project-status.completed {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .project-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        
        .project-stat {
            background: var(--bg);
            padding: 10px;
            border-radius: 10px;
            text-align: center;
        }
        
        .project-stat-label {
            font-size: 9px;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        
        .project-stat-value {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            margin-top: 2px;
        }
        
        /* Expense List */
        .expense-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
        }
        
        .expense-item:last-child { border-bottom: none; }
        
        .expense-info {
            flex: 1;
        }
        
        .expense-desc {
            font-size: 12px;
            color: var(--text);
            margin-bottom: 2px;
        }
        
        .expense-date {
            font-size: 10px;
            color: var(--text-muted);
        }
        
        .expense-category {
            font-size: 9px;
            background: var(--bg);
            padding: 2px 6px;
            border-radius: 4px;
            color: var(--text-muted);
        }
        
        .expense-amount {
            font-size: 12px;
            font-weight: 600;
            color: var(--danger);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: var(--text-muted);
        }
        
        .empty-icon {
            font-size: 40px;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        .empty-text {
            font-size: 13px;
        }
        
        /* Error State */
        .error-card {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            text-align: center;
        }
        
        .error-title {
            color: var(--danger);
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .error-text {
            font-size: 12px;
            color: #991b1b;
        }
        
        
        /* Footer Nav */
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 10px 0 14px;
            border-top: 1px solid var(--border);
            box-shadow: 0 -4px 16px rgba(0,0,0,0.06);
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            font-size: 10px;
            color: var(--text-muted);
            transition: color 0.2s;
            padding: 4px 12px;
        }
        
        .nav-item.active { color: var(--primary); }
        
        .nav-icon {
            font-size: 20px;
            margin-bottom: 2px;
        }
        
        /* Chart Card - Premium Modern Design */
        .chart-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06), 0 1px 4px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
        }
        
        .chart-layout {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }
        
        .chart-wrapper {
            width: 180px;
            height: 180px;
            flex-shrink: 0;
            position: relative;
        }
        
        .chart-center-label {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            pointer-events: none;
            z-index: 2;
        }
        .chart-center-label .center-title {
            font-size: 9px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .chart-center-label .center-value {
            font-size: 13px;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: -0.03em;
            margin-top: 1px;
        }
        
        .chart-legend {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.15s ease;
            user-select: none;
        }
        
        .legend-item:hover {
            background: #f8fafc;
        }
        
        .legend-item:active {
            background: #f1f5f9;
        }
        
        .legend-rank {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            font-weight: 700;
            color: #94a3b8;
            flex-shrink: 0;
        }
        
        .legend-color {
            width: 8px;
            height: 8px;
            border-radius: 2px;
            flex-shrink: 0;
        }
        
        .legend-info {
            flex: 1;
            min-width: 0;
        }
        
        .legend-top-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 3px;
        }
        
        .legend-name {
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-transform: capitalize;
        }
        
        .legend-amount {
            font-size: 11px;
            font-weight: 700;
            color: #1e293b;
            white-space: nowrap;
            margin-left: 6px;
        }
        
        .legend-bar-track {
            width: 100%;
            height: 3px;
            background: #f1f5f9;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .legend-bar-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 1s cubic-bezier(0.22, 1, 0.36, 1);
        }
        
        .legend-percent {
            font-size: 10px;
            font-weight: 600;
            color: #94a3b8;
            white-space: nowrap;
            min-width: 32px;
            text-align: right;
            flex-shrink: 0;
        }
        
        /* Division Detail Modal */
        .division-detail-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(12px);
            z-index: 9999;
            padding: 20px;
            overflow-y: auto;
            animation: fadeInModal 0.3s cubic-bezier(0.36, 0, 0.66, 1);
        }
        
        @keyframes fadeInModal {
            from {
                opacity: 0;
                backdrop-filter: blur(0);
            }
            to {
                opacity: 1;
                backdrop-filter: blur(12px);
            }
        }
        

        
        .division-detail-content {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 24px;
            max-width: 480px;
            margin: 40px auto;
            box-shadow: 
                0 24px 64px rgba(0, 0, 0, 0.2),
                0 8px 24px rgba(102, 126, 234, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.8);
            animation: slideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .division-detail-header {
            padding: 24px 24px 16px;
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
            position: relative;
        }
        
        .division-detail-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 24px 24px 0 0;
        }
        
        .division-detail-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .division-detail-total {
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.03em;
        }
        
        .division-detail-close {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .division-detail-close:hover {
            background: #ef4444;
            color: white;
            transform: rotate(90deg) scale(1.1);
        }
        
        .division-detail-body {
            padding: 20px 24px;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .division-detail-item {
            padding: 14px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(248, 250, 252, 0.8) 100%);
            border-radius: 12px;
            border: 1px solid rgba(102, 126, 234, 0.08);
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }
        
        .division-detail-item:hover {
            border-color: rgba(102, 126, 234, 0.2);
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
        }
        
        .division-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        
        .division-detail-desc {
            flex: 1;
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 6px;
        }
        
        .division-detail-amount {
            font-size: 14px;
            font-weight: 700;
            color: #ef4444;
            white-space: nowrap;
        }
        
        .division-detail-meta {
            display: flex;
            gap: 12px;
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 6px;
        }
        
        .division-detail-date {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .division-detail-category {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            background: rgba(102, 126, 234, 0.1);
            border-radius: 6px;
            font-weight: 500;
        }
        
        .division-detail-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }
        
        .division-detail-empty-icon {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.3;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-logo">
                <img src="<?= $basePath ?>/uploads/logos/<?= htmlspecialchars($activeBusinessId) ?>_logo.png" alt="Logo" onerror="this.parentElement.style.display='none'">
            </div>
            <div class="header-title">Projects & Investors</div>
            <div class="header-subtitle"><?= htmlspecialchars($businessName) ?></div>
        </div>
        
        <?php if ($error): ?>
        <div class="error-card">
            <div class="error-title">⚠️ Connection Error</div>
            <div class="error-text"><?= htmlspecialchars($error) ?></div>
        </div>
        <?php elseif ($selectedProject): ?>
        
        <!-- Project Detail View -->
        <div class="project-detail">
            <div class="project-header">
                <div class="project-name"><?= htmlspecialchars($selectedProject['name'] ?? 'Project') ?></div>
                <span class="project-status <?= ($selectedProject['status'] ?? '') === 'active' ? 'active' : 'completed' ?>">
                    <?= ucfirst($selectedProject['status'] ?? 'active') ?>
                </span>
            </div>
            <div class="project-stats">
                <div class="project-stat">
                    <div class="project-stat-label">Budget</div>
                    <div class="project-stat-value"><?= rp($selectedProject['budget'] ?? 0) ?></div>
                </div>
                <div class="project-stat">
                    <div class="project-stat-label">Total Used</div>
                    <div class="project-stat-value"><?= rp($selectedProject['grand_expenses'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($divisionBreakdown)): ?>
        <!-- Division Breakdown Chart -->
        <div class="section-title">
            📊 Expense by Division
        </div>
        
        <div class="chart-card">
            <div class="chart-layout">
                <div class="chart-wrapper">
                    <canvas id="divisionBreakdownChart"></canvas>
                    <div class="chart-center-label">
                        <div class="center-title">Total</div>
                        <div class="center-value"><?php
                            arsort($divisionBreakdown);
                            $totalDivision = array_sum($divisionBreakdown);
                            echo rpFull($totalDivision);
                        ?></div>
                    </div>
                </div>
                <div class="chart-legend">
                    <?php 
                    $colors = ['#4f46e5', '#7c3aed', '#db2777', '#ea580c', '#059669', '#0891b2', '#d97706', '#6366f1', '#0d9488', '#be123c', '#4338ca', '#15803d'];
                    $index = 0;
                    foreach ($divisionBreakdown as $divName => $divAmount): 
                        $percentage = $totalDivision > 0 ? round(($divAmount / $totalDivision) * 100, 1) : 0;
                        $color = $colors[$index % count($colors)];
                        $rank = $index + 1;
                        $index++;
                    ?>
                    <div class="legend-item" onclick="showDivisionDetail('<?= htmlspecialchars($divName, ENT_QUOTES) ?>', '<?= $color ?>')">
                        <div class="legend-rank"><?= $rank ?></div>
                        <div class="legend-color" style="background: <?= $color ?>"></div>
                        <div class="legend-info">
                            <div class="legend-top-row">
                                <span class="legend-name"><?= htmlspecialchars($divName) ?></span>
                                <span class="legend-amount"><?= rpFull($divAmount) ?></span>
                            </div>
                            <div class="legend-bar-track">
                                <div class="legend-bar-fill" style="width: <?= $percentage ?>%; background: <?= $color ?>"></div>
                            </div>
                        </div>
                        <div class="legend-percent"><?= $percentage ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Division Detail Modal -->
        <div id="divisionDetailModal" class="division-detail-modal" onclick="if(event.target === this) closeDivisionDetail()">
            <div class="division-detail-content">
                <div class="division-detail-header">
                    <div class="division-detail-close" onclick="closeDivisionDetail()">&times;</div>
                    <div class="division-detail-title">
                        <span id="divisionDetailIcon" style="font-size: 20px;">📊</span>
                        <span id="divisionDetailName">Division</span>
                    </div>
                    <div class="division-detail-total" id="divisionDetailTotal">Rp 0</div>
                </div>
                <div class="division-detail-body" id="divisionDetailBody">
                    <div class="division-detail-empty">
                        <div class="division-detail-empty-icon">📋</div>
                        <div>No expense data</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="section-title">
            📋 Recent Expenses
            <span class="badge"><?= count($projectExpenses) ?></span>
        </div>
        
        <div class="list-card">
            <?php if (empty($projectExpenses)): ?>
            <div class="empty-state">
                <div class="empty-icon">📝</div>
                <div class="empty-text">No expenses recorded yet</div>
            </div>
            <?php else: ?>
                <?php foreach ($projectExpenses as $exp): ?>
                <div class="expense-item">
                    <div class="expense-info">
                        <div class="expense-desc"><?= htmlspecialchars($exp['description'] ?? '-') ?></div>
                        <div class="expense-date">
                            <?= date('d M Y', strtotime($exp['expense_date'] ?? 'now')) ?>
                            <?php if (!empty($exp['category_name'])): ?>
                                <span class="expense-category"><?= htmlspecialchars($exp['category_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="expense-amount">-<?= rp($exp['amount'] ?? 0) ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        
        <!-- Overview -->
        <div class="overview-grid">
            <div class="overview-card capital">
                <div class="overview-icon">💰</div>
                <div class="overview-label">Total Capital</div>
                <div class="overview-value"><?= rp($totalCapital) ?></div>
                <div class="overview-hint"><?= count($investors) ?> investors</div>
            </div>
            <div class="overview-card budget">
                <div class="overview-icon">📊</div>
                <div class="overview-label">Total Budget</div>
                <div class="overview-value"><?= rp($totalBudget) ?></div>
                <div class="overview-hint"><?= count($projects) ?> projects</div>
            </div>
        </div>
        
        <!-- Budget Usage Progress -->
        <div class="progress-section">
            <div class="progress-content">
                <div class="progress-left">
                    <div class="progress-header">
                        <span class="progress-title">Budget Usage</span>
                        <span class="progress-percent"><?= $usagePercent ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= min($usagePercent, 100) ?>%"></div>
                    </div>
                    <div class="progress-labels">
                        <span>Used: <?= rp($totalExpenses) ?></span>
                        <span>Remaining: <?= rp($totalBudget - $totalExpenses) ?></span>
                    </div>
                </div>
                <?php if (!empty($chartExpensesByProject)): ?>
                <div class="progress-chart">
                    <canvas id="expensePieChart"></canvas>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Investors List -->
        <div class="section-title">
            👤 Investors
            <span class="badge"><?= count($investors) ?></span>
        </div>
        
        <div class="list-card">
            <?php if (empty($investors)): ?>
            <div class="empty-state">
                <div class="empty-icon">👥</div>
                <div class="empty-text">No investors found</div>
            </div>
            <?php else: ?>
                <?php foreach ($investors as $inv): ?>
                <div class="list-item">
                    <div class="list-icon investor">👤</div>
                    <div class="list-info">
                        <div class="list-name"><?= htmlspecialchars($inv['name'] ?? 'Investor') ?></div>
                        <div class="list-detail"><?= htmlspecialchars($inv['contact'] ?? '-') ?></div>
                    </div>
                    <div class="list-amount">
                        <div class="list-value"><?= rp($inv['total_capital'] ?? 0) ?></div>
                        <div class="list-label">Capital</div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Projects List -->
        <div class="section-title">
            📁 Projects
            <span class="badge"><?= count($projects) ?></span>
        </div>
        
        <div class="list-card">
            <?php if (empty($projects)): ?>
            <div class="empty-state">
                <div class="empty-icon">📂</div>
                <div class="empty-text">No projects found</div>
            </div>
            <?php else: ?>
                <?php foreach ($projects as $proj): ?>
                <a href="?project_id=<?= $proj['id'] ?>" class="list-item">
                    <div class="list-icon project">📁</div>
                    <div class="list-info">
                        <div class="list-name"><?= htmlspecialchars($proj['name'] ?? 'Project') ?></div>
                        <div class="list-detail">
                            Budget: <?= rp($proj['budget'] ?? 0) ?>
                        </div>
                    </div>
                    <div class="list-amount">
                        <div class="list-value" style="color: var(--warning);"><?= rp($proj['grand_expenses'] ?? 0) ?></div>
                        <div class="list-label">Used</div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
        
    </div>
    
    <!-- Footer Nav -->
    <?php
    require_once __DIR__ . '/../../includes/owner_footer_nav.php';
    $activeConfig = getActiveBusinessConfig();
    renderOwnerFooterNav('projects', $basePath, $activeConfig['enabled_modules'] ?? []);
    ?>

    <script>
        // Expense Pie Chart
        <?php if (!empty($chartExpensesByProject)): ?>
        const pieCtx = document.getElementById('expensePieChart');
        if (pieCtx) {
            const pieColors = ['#6366f1','#ec4899','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ef4444','#14b8a6'];
            new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_keys($chartExpensesByProject)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_values($chartExpensesByProject)) ?>,
                        backgroundColor: pieColors.slice(0, <?= count($chartExpensesByProject) ?>),
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    const val = ctx.parsed;
                                    const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                                    const pct = ((val/total)*100).toFixed(1);
                                    return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Division Breakdown Pie Chart (for selected project) - 2028 Elegant Style
        <?php if (!empty($divisionBreakdown)): ?>
        // Store division details data
        const divisionDetailsData = <?= json_encode($divisionDetails ?? [], JSON_UNESCAPED_UNICODE) ?>;
        
        const divisionCtx = document.getElementById('divisionBreakdownChart');
        if (divisionCtx) {
            const divisionColors = [
                '#4f46e5', '#7c3aed', '#db2777', '#ea580c',
                '#059669', '#0891b2', '#d97706', '#6366f1',
                '#0d9488', '#be123c', '#4338ca', '#15803d'
            ];
            const divisionLabels = <?= json_encode(array_keys($divisionBreakdown), JSON_UNESCAPED_UNICODE) ?>;
            const divisionData = <?= json_encode(array_values($divisionBreakdown)) ?>;
            
            try {
                new Chart(divisionCtx, {
                    type: 'doughnut',
                    data: {
                        labels: divisionLabels,
                        datasets: [{
                            data: divisionData,
                            backgroundColor: divisionColors.slice(0, divisionLabels.length),
                            borderWidth: 3,
                            borderColor: '#ffffff',
                            hoverOffset: 8,
                            hoverBorderWidth: 3,
                            hoverBorderColor: '#e2e8f0',
                            spacing: 2,
                            borderRadius: 4,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                enabled: true,
                                backgroundColor: '#1e293b',
                                padding: 14,
                                cornerRadius: 10,
                                titleFont: { size: 13, weight: '600', family: 'system-ui, -apple-system, sans-serif' },
                                bodyFont: { size: 12, family: 'system-ui, -apple-system, sans-serif' },
                                titleMarginBottom: 8,
                                displayColors: true,
                                boxWidth: 10,
                                boxHeight: 10,
                                boxPadding: 6,
                                callbacks: {
                                    label: function(ctx) {
                                        const val = ctx.parsed;
                                        const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                                        const pct = ((val/total)*100).toFixed(1);
                                        return 'Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                    },
                                    afterLabel: function(ctx) {
                                        const division = divisionLabels[ctx.dataIndex];
                                        const items = divisionDetailsData[division] || [];
                                        return items.length + ' transaksi — tap untuk detail';
                                    }
                                }
                            }
                        },
                        cutout: '52%',
                        animation: { animateRotate: true, duration: 1200, easing: 'easeOutQuart' },
                        onClick: (event, activeElements) => {
                            if (activeElements.length > 0) {
                                const index = activeElements[0].index;
                                showDivisionDetail(divisionLabels[index], divisionColors[index]);
                            }
                        },
                        onHover: (event, activeElements) => {
                            event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
                        }
                    }
                });
            } catch (error) {
                console.error('Division chart error:', error);
            }
        }
        
        // Division Detail Functions
        function showDivisionDetail(divisionName, color) {
            const modal = document.getElementById('divisionDetailModal');
            const nameEl = document.getElementById('divisionDetailName');
            const totalEl = document.getElementById('divisionDetailTotal');
            const bodyEl = document.getElementById('divisionDetailBody');
            const iconEl = document.getElementById('divisionDetailIcon');
            
            // Set division name
            nameEl.textContent = divisionName;
            iconEl.style.color = color;
            
            // Get division details
            const details = divisionDetailsData[divisionName] || [];
            
            if (details.length === 0) {
                bodyEl.innerHTML = `
                    <div class="division-detail-empty">
                        <div class="division-detail-empty-icon">📋</div>
                        <div>No transaction data</div>
                    </div>
                `;
                totalEl.textContent = 'Rp 0';
            } else {
                // Calculate total
                const total = details.reduce((sum, item) => sum + item.amount, 0);
                totalEl.textContent = 'Rp ' + total.toLocaleString('id-ID');
                
                // Sort by date (newest first)
                details.sort((a, b) => new Date(b.date) - new Date(a.date));
                
                // Render items
                let html = '';
                details.forEach(item => {
                    const formattedDate = new Date(item.date).toLocaleDateString('id-ID', {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric'
                    });
                    
                    html += `
                        <div class="division-detail-item">
                            <div class="division-detail-row">
                                <div class="division-detail-desc">${escapeHtml(item.description)}</div>
                                <div class="division-detail-amount">-Rp ${item.amount.toLocaleString('id-ID')}</div>
                            </div>
                            <div class="division-detail-meta">
                                <span class="division-detail-date">📅 ${formattedDate}</span>
                                <span class="division-detail-category">🏷️ ${escapeHtml(item.category)}</span>
                            </div>
                        </div>
                    `;
                });
                bodyEl.innerHTML = html;
            }
            
            // Show modal
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function closeDivisionDetail() {
            const modal = document.getElementById('divisionDetailModal');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDivisionDetail();
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
