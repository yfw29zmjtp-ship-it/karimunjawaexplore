<?php
/**
 * INVESTOR MODULE - Investor Fund Recording
 * Separate from projects, only records investor deposits
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Define base_url for API calls
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $base_url = $protocol . $_SERVER['HTTP_HOST'];
} else {
    $base_url = BASE_URL;
}

$db = Database::getInstance()->getConnection();

// Get all investors with their total deposits
try {
    $investors = $db->query("
        SELECT i.*, 
               COALESCE(SUM(CASE WHEN it.type = 'capital' OR it.transaction_type = 'capital' THEN it.amount ELSE 0 END), 0) as total_deposits,
               COALESCE(i.name, i.investor_name) as name,
               COALESCE(i.contact, i.contact_phone) as contact,
               COALESCE(i.total_capital, i.balance) as total_capital
        FROM investors i
        LEFT JOIN investor_transactions it ON i.id = it.investor_id
        GROUP BY i.id
        ORDER BY COALESCE(i.name, i.investor_name)
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback if investor_transactions doesn't have the right structure
    try {
        $investors = $db->query("
            SELECT i.*, 
                   COALESCE(i.name, i.investor_name) as name,
                   COALESCE(i.contact, i.contact_phone) as contact,
                   COALESCE(i.total_capital, i.balance) as total_capital
            FROM investors i 
            ORDER BY COALESCE(i.name, i.investor_name)
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Absolute fallback
        $investors = $db->query("SELECT * FROM investors")->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get recent deposits grouped by investor
try {
    $recentDeposits = $db->query("
        SELECT it.*, 
               COALESCE(i.name, i.investor_name) as investor_name,
               COALESCE(i.contact, i.contact_phone) as investor_contact,
               i.id as investor_id
        FROM investor_transactions it
        JOIN investors i ON it.investor_id = i.id
        WHERE it.type = 'capital' OR it.transaction_type = 'capital'
        ORDER BY i.id, it.created_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by investor
    $depositsByInvestor = [];
    foreach ($recentDeposits as $deposit) {
        $investorId = $deposit['investor_id'];
        if (!isset($depositsByInvestor[$investorId])) {
            $depositsByInvestor[$investorId] = [
                'name' => $deposit['investor_name'],
                'contact' => $deposit['investor_contact'],
                'deposits' => []
            ];
        }
        $depositsByInvestor[$investorId]['deposits'][] = $deposit;
    }
} catch (Exception $e) {
    $recentDeposits = [];
    $depositsByInvestor = [];
}

// Get all projects for project management section
try {
    // Check what columns actually exist
    $stmt = $db->query("DESCRIBE projects");
    $columnsInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = array_column($columnsInfo, 'Field');
    
    // Build flexible SELECT based on available columns
    $name_col = 'COALESCE(';
    if (in_array('project_name', $columns)) $name_col .= 'project_name, ';
    if (in_array('name', $columns)) $name_col .= 'name, ';
    $name_col .= "'Project') as project_name";
    
    $code_col = 'COALESCE(';
    if (in_array('project_code', $columns)) $code_col .= 'project_code, ';
    if (in_array('code', $columns)) $code_col .= 'code, ';
    $code_col .= "CONCAT('PROJ-', LPAD(id, 4, '0'))) as project_code";
    
    $budget_col = 'COALESCE(';
    if (in_array('budget_idr', $columns)) $budget_col .= 'budget_idr, ';
    if (in_array('budget', $columns)) $budget_col .= 'budget, ';
    $budget_col .= '0) as budget_idr';
    
    $desc_col = 'COALESCE(';
    if (in_array('description', $columns)) $desc_col .= 'description, ';
    if (in_array('desc', $columns)) $desc_col .= 'desc, ';
    $desc_col .= "'') as description";
    
    $status_col = 'COALESCE(';
    if (in_array('status', $columns)) $status_col .= 'status, ';
    $status_col .= "'ongoing') as status";
    
    $projects = $db->query("
        SELECT id,
               $name_col,
               $code_col,
               $budget_col,
               $desc_col,
               $status_col,
               created_at,
               0 as total_expenses,
               0 as expense_count
        FROM projects
        ORDER BY created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: minimal query
    try {
        $projects = $db->query("
            SELECT * FROM projects
            ORDER BY created_at DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $projects = [];
    }
}

$totalProjects = count($projects);

// Get all bills
try {
    $bills = $db->query("
        SELECT * FROM investor_bills
        ORDER BY 
            CASE 
                WHEN status = 'unpaid' THEN 1
                WHEN status = 'overdue' THEN 2
                WHEN status = 'paid' THEN 3
                ELSE 4
            END,
            due_date DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $bills = [];
}

// Calculate bill statistics
$totalBills = count($bills);
$totalUnpaid = 0;
$totalPaid = 0;
$unpaidAmount = 0;
$paidAmount = 0;

foreach ($bills as $bill) {
    if ($bill['status'] === 'unpaid' || $bill['status'] === 'overdue') {
        $totalUnpaid++;
        $unpaidAmount += $bill['amount'];
    } elseif ($bill['status'] === 'paid') {
        $totalPaid++;
        $paidAmount += $bill['amount'];
    }
}

// Calculate totals
$totalInvestors = count($investors);
$totalCapital = 0;
foreach ($investors as $inv) {
    // Use the flexible field name we calculated in the query
    $totalCapital += $inv['total_capital'] ?? 0;
}

// ====== CHART DATA ======
$chart_budget_vs_expense = [];
$chart_contractor_pie = [];
$total_all_expenses = 0;

foreach ($projects as &$proj) {
    $pid = $proj['id'];
    // Get real expense total per project
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_expenses WHERE project_id = ?");
        $stmt->execute([$pid]);
        $proj['total_expenses'] = floatval($stmt->fetchColumn());
    } catch (Exception $e) { $proj['total_expenses'] = 0; }

    // Get salary + division totals
    $proj['total_gaji'] = 0;
    $proj['total_divisi'] = 0;
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_salary),0) FROM project_salaries WHERE project_id = ?");
        $stmt->execute([$pid]);
        $proj['total_gaji'] = floatval($stmt->fetchColumn());
    } catch (Exception $e) {}
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM project_division_expenses WHERE project_id = ?");
        $stmt->execute([$pid]);
        $proj['total_divisi'] = floatval($stmt->fetchColumn());
    } catch (Exception $e) {}

    $proj['grand_expenses'] = $proj['total_expenses'] + $proj['total_gaji'] + $proj['total_divisi'];
    $total_all_expenses += $proj['grand_expenses'];

    $chart_budget_vs_expense[] = [
        'name' => $proj['project_name'] ?? 'Project',
        'budget' => floatval($proj['budget_idr'] ?? 0),
        'expense' => $proj['grand_expenses'],
    ];

    // Expenses per contractor for this project
    try {
        $expCols = [];
        try {
            $stmt = $db->query("DESCRIBE project_expenses");
            $expCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } catch (Exception $e) {}

        if (in_array('division_name', $expCols)) {
            $stmt = $db->prepare("SELECT division_name, SUM(amount) as total FROM project_expenses WHERE project_id = ? AND division_name IS NOT NULL AND division_name != '' GROUP BY division_name");
            $stmt->execute([$pid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dn = $row['division_name'];
                if (!isset($chart_contractor_pie[$dn])) $chart_contractor_pie[$dn] = 0;
                $chart_contractor_pie[$dn] += floatval($row['total']);
            }
        }
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare("SELECT division_name, SUM(amount) as total FROM project_division_expenses WHERE project_id = ? GROUP BY division_name");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dn = $row['division_name'];
            if (!isset($chart_contractor_pie[$dn])) $chart_contractor_pie[$dn] = 0;
            $chart_contractor_pie[$dn] += floatval($row['total']);
        }
    } catch (Exception $e) {}
}
unset($proj);

// Sort contractor pie by value descending
arsort($chart_contractor_pie);

// ====== RECENT PROJECT TRANSACTIONS (from cashbook sync) ======
$recentProjectExpenses = [];
$projectExpenseDebug = '';
try {
    // Check columns in project_expenses
    $peCols = [];
    try {
        $stmt = $db->query("DESCRIBE project_expenses");
        $peCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Exception $e) {
        $projectExpenseDebug = 'Table project_expenses not found';
    }
    
    if (!empty($peCols)) {
        $hasCashBookId = in_array('cash_book_id', $peCols);
        $hasDivisionName = in_array('division_name', $peCols);
        $hasDescription = in_array('description', $peCols);
        $hasExpenseDate = in_array('expense_date', $peCols);
        
        // Count total records first
        $countRow = $db->query("SELECT COUNT(*) as cnt FROM project_expenses")->fetch(PDO::FETCH_ASSOC);
        $totalPE = (int)($countRow['cnt'] ?? 0);
        
        // Detect project name column
        $projCols = [];
        try {
            $stmt = $db->query("DESCRIBE projects");
            $projCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } catch (Exception $e) {}
        $pNameCol = in_array('project_name', $projCols) ? 'project_name' : (in_array('name', $projCols) ? 'name' : null);
        $pCodeCol = in_array('project_code', $projCols) ? 'project_code' : (in_array('code', $projCols) ? 'code' : null);
        
        // Build SELECT with available columns
        $selectCols = ['pe.id', 'pe.project_id', 'pe.amount'];
        if ($hasExpenseDate) $selectCols[] = 'pe.expense_date';
        if ($hasDescription) $selectCols[] = 'pe.description';
        if ($hasDivisionName) $selectCols[] = 'pe.division_name';
        if ($hasCashBookId) $selectCols[] = 'pe.cash_book_id';
        if ($pNameCol) $selectCols[] = "p.{$pNameCol} as project_name";
        else $selectCols[] = "'Unknown' as project_name";
        if ($pCodeCol) $selectCols[] = "p.{$pCodeCol} as project_code";
        else $selectCols[] = "NULL as project_code";
        
        if ($hasCashBookId) {
            $selectCols[] = 'cat.category_name';
        }
        
        $sql = "SELECT " . implode(', ', $selectCols);
        $sql .= " FROM project_expenses pe";
        $sql .= " LEFT JOIN projects p ON pe.project_id = p.id";
        if ($hasCashBookId) {
            $sql .= " LEFT JOIN cash_book cb ON pe.cash_book_id = cb.id";
            $sql .= " LEFT JOIN categories cat ON cb.category_id = cat.id";
        }
        $orderCol = $hasExpenseDate ? 'pe.expense_date' : 'pe.id';
        $sql .= " ORDER BY {$orderCol} DESC LIMIT 15";
        
        $recentProjectExpenses = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $projectExpenseDebug = "Total records: {$totalPE}, Shown: " . count($recentProjectExpenses) . ", Cols: " . implode(',', $peCols);
    }
} catch (Exception $e) {
    error_log("Recent project expenses load error: " . $e->getMessage());
    $projectExpenseDebug = 'Error: ' . $e->getMessage();
    $recentProjectExpenses = [];
}

$pageTitle = 'Data Investor';
include $base_path . '/includes/header.php';
?>

<style>
* {
    box-sizing: border-box;
}

.investor-page {
    padding: 1.25rem;
    max-width: 1400px;
    margin: 0 auto;
    background: var(--bg-primary);
    min-height: 100vh;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid rgba(99, 102, 241, 0.1);
}

.page-header h1 {
    font-size: 1.6rem;
    font-weight: 700;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.header-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.btn {
    padding: 0.65rem 1.25rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

.btn-primary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
}

.btn-primary:hover {
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.btn-success:hover {
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
}

.btn-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.btn-danger:hover {
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
}

.btn:active {
    transform: translateY(0);
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.75rem;
}

.summary-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.summary-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.05), transparent);
    border-radius: 50%;
}

.summary-card:hover {
    border-color: rgba(99, 102, 241, 0.3);
    box-shadow: 0 8px 24px rgba(99, 102, 241, 0.1);
    transform: translateY(-4px);
}

.summary-card .label {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.summary-card .value {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
}

.summary-card.highlight {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(139, 92, 246, 0.15));
    border-color: rgba(99, 102, 241, 0.3);
}

.summary-card.highlight .value {
    color: #6366f1;
}

/* Section Header */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 1.5rem 0 1rem 0;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid rgba(99, 102, 241, 0.1);
}

.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.section-title svg {
    stroke: #6366f1;
    stroke-width: 2.5;
}

/* Investor List */
.investor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.2rem;
    margin-bottom: 3rem;
}

.investor-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.8rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    position: relative;
    overflow: hidden;
}

.investor-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
}

.investor-card:hover {
    border-color: rgba(99, 102, 241, 0.4);
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.12);
    transform: translateY(-3px);
}

.investor-card .investor-info {
    padding: 0.2rem 0;
}

.investor-card .investor-name {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 0.3rem 0;
}

.investor-card .investor-meta {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.3rem;
    word-break: break-word;
}

.investor-card .investor-details {
    padding: 0.6rem 0;
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
}

.investor-card .detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
}

.investor-card .detail-item .label {
    font-size: 0.65rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.investor-card .detail-item .value {
    font-size: 0.85rem;
    font-weight: 700;
    color: #10b981;
}

.investor-card .investor-card-divider {
    display: none;
}

.investor-card .investor-card-content {
    display: none;
}

.investor-card .actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.4rem;
}

.investor-card .btn-sm {
    padding: 0.4rem 0.6rem;
    font-size: 0.65rem;
    border-radius: 5px;
    text-align: center;
    justify-content: center;
}

.btn-setoran {
    background: linear-gradient(135deg, #10b981, #059669) !important;
    color: white !important;
    border: none !important;
    font-weight: 600;
}
.btn-setoran:hover {
    background: linear-gradient(135deg, #059669, #047857) !important;
    box-shadow: 0 4px 12px rgba(16,185,129,0.4);
}

.btn-history {
    background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
    color: white !important;
    border: none !important;
    font-weight: 600;
}
.btn-history:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
    box-shadow: 0 4px 12px rgba(59,130,246,0.4);
}

.btn-inv-edit {
    background: linear-gradient(135deg, #f59e0b, #d97706) !important;
    color: white !important;
    border: none !important;
    font-weight: 600;
}
.btn-inv-edit:hover {
    background: linear-gradient(135deg, #d97706, #b45309) !important;
    box-shadow: 0 4px 12px rgba(245,158,11,0.4);
}

.btn-kas {
    background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
    color: white !important;
    border: none !important;
    font-weight: 600;
}
.btn-kas:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(37,99,235,0.4);
}

.btn-edit {
    background: linear-gradient(135deg, #f59e0b, #d97706) !important;
    color: white !important;
    border: none !important;
    font-weight: 600;
}
.btn-edit:hover {
    background: linear-gradient(135deg, #d97706, #b45309) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(217,119,6,0.4);
}

.btn-hapus {
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    border: none !important;
    font-weight: 600;
}
.btn-hapus:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(239,68,68,0.4);
}

/* Charts Section */
.charts-section {
    margin-bottom: 1.75rem;
}

.charts-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

.chart-card {
    background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 16px;
    padding: 1.75rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.08);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.chart-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 48px rgba(0,0,0,0.12);
    border-color: rgba(255,255,255,0.2);
}

.chart-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    z-index: 1;
}

.chart-card.chart-pie::before {
    background: linear-gradient(90deg, #6366f1 0%, #ec4899 50%, #f59e0b 100%);
}

.chart-card.chart-bar::before {
    background: linear-gradient(90deg, #10b981 0%, #3b82f6 50%, #8b5cf6 100%);
}

.chart-card::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(99,102,241,0.03) 0%, transparent 70%);
    pointer-events: none;
}

.chart-card h3 {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 0.3rem 0;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    position: relative;
    z-index: 2;
    letter-spacing: -0.02em;
}

.chart-card .chart-sub {
    font-size: 0.78rem;
    color: var(--text-muted);
    margin-bottom: 1.25rem;
    position: relative;
    z-index: 2;
    line-height: 1.4;
    font-weight: 500;
    opacity: 0.85;
}

.chart-card canvas {
    max-height: 320px;
    position: relative;
    z-index: 2;
}

.chart-empty {
    text-align: center;
    padding: 3rem 1.5rem;
    color: var(--text-muted);
    font-size: 0.85rem;
    position: relative;
    z-index: 2;
    background: rgba(0,0,0,0.02);
    border-radius: 12px;
    border: 2px dashed rgba(0,0,0,0.08);
}

@media (max-width: 900px) {
    .charts-grid { 
        grid-template-columns: 1fr; 
        gap: 1.25rem;
    }
    .chart-card {
        padding: 1.5rem;
    }
}

/* Projects Section */
.projects-section {
    margin-bottom: 1.75rem;
}

.projects-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1rem;
}

.project-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1.15rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    position: relative;
    cursor: pointer;
    min-height: 230px;
    overflow: visible;
}

.project-card::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #f59e0b, #ec4899);
}

.project-card:hover {
    border-color: rgba(245, 158, 11, 0.4);
    box-shadow: 0 12px 32px rgba(245, 158, 11, 0.15);
    transform: translateY(-6px);
}

.project-card .project-name {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text-primary);
}

.project-card .project-code {
    font-size: 0.7rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.project-card .project-amount {
    font-size: 1.1rem;
    font-weight: 700;
    color: #f59e0b;
}

.project-card .project-meta {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-top: 1px solid var(--border-color);
    font-size: 0.75rem;
    color: var(--text-muted);
}

.project-card .meta-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.project-card .meta-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.project-card .project-actions {
    display: flex;
    gap: 0.3rem;
    margin-top: 0.5rem;
    flex-wrap: wrap;
    width: 100%;
    z-index: 10;
    position: relative;
}

.project-card .btn-sm {
    padding: 0.4rem 0.65rem;
    font-size: 0.68rem;
    border-radius: 6px;
    flex: 1;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.2rem;
    white-space: nowrap;
    line-height: 1;
    height: 28px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.add-project-card {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.05), rgba(236, 72, 153, 0.05));
    border: 2px dashed rgba(245, 158, 11, 0.3);
    border-radius: 10px;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
}

.add-project-card:hover {
    border-color: rgba(245, 158, 11, 0.6);
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(236, 72, 153, 0.1));
    box-shadow: 0 8px 20px rgba(245, 158, 11, 0.15);
}

.add-project-card svg {
    width: 40px;
    height: 40px;
    stroke: #f59e0b;
    stroke-width: 2;
}

.add-project-card .text {
    font-weight: 600;
    color: var(--text-secondary);
}

/* Project Monitoring Table */
.project-monitoring-table {
    margin-top: 2rem;
    background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 16px;
    padding: 0;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.08);
}

.project-monitoring-table .table-header {
    padding: 1.5rem 1.75rem;
    background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(236,72,153,0.05));
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.project-monitoring-table .table-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    letter-spacing: -0.02em;
}

.project-monitoring-table .table-title svg {
    stroke: #6366f1;
}

.monitoring-table-wrapper {
    overflow-x: auto;
    max-width: 100%;
}

.monitoring-table {
    width: 100%;
    border-collapse: collapse;
    background: transparent;
}

.monitoring-table thead tr {
    background: linear-gradient(135deg, rgba(99,102,241,0.1), rgba(245,158,11,0.05));
}

.monitoring-table th {
    padding: 1rem 1.25rem;
    text-align: left;
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text-primary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid rgba(99,102,241,0.2);
    white-space: nowrap;
}

.monitoring-table tbody tr {
    transition: all 0.25s ease;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.monitoring-table tbody tr:hover {
    background: linear-gradient(90deg, rgba(99,102,241,0.08), rgba(245,158,11,0.04));
    transform: scale(1.005);
}

.monitoring-table td {
    padding: 1.15rem 1.25rem;
    font-size: 0.88rem;
    color: var(--text-primary);
    vertical-align: middle;
}

.monitoring-table .text-center {
    text-align: center;
}

.monitoring-table .project-details {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.monitoring-table .project-name-table {
    font-weight: 700;
    font-size: 0.92rem;
    color: var(--text-primary);
    line-height: 1.3;
}

.monitoring-table .project-code-table {
    font-size: 0.72rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    font-weight: 600;
}

.monitoring-table .amount-cell {
    font-weight: 700;
    font-size: 0.9rem;
    font-family: 'Segoe UI', system-ui, sans-serif;
    white-space: nowrap;
}

.monitoring-table .text-warning {
    color: #f59e0b;
}

.monitoring-table .text-success {
    color: #10b981;
}

.monitoring-table .text-danger {
    color: #ef4444;
}

.monitoring-table .badge-danger {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    font-size: 0.65rem;
    font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    border-radius: 12px;
    margin-left: 0.4rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.monitoring-table .progress-container {
    width: 100%;
    height: 8px;
    background: rgba(0,0,0,0.1);
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 0.3rem;
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.2);
}

.monitoring-table .progress-bar {
    height: 100%;
    border-radius: 10px;
    transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    box-shadow: 0 0 10px rgba(255,255,255,0.3);
}

.monitoring-table .progress-bar.success {
    background: linear-gradient(90deg, #10b981, #059669);
}

.monitoring-table .progress-bar.warning {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}

.monitoring-table .progress-bar.danger {
    background: linear-gradient(90deg, #ef4444, #dc2626);
}

.monitoring-table .progress-text {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    text-align: center;
}

.monitoring-table .btn-icon-sm {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, rgba(99,102,241,0.15), rgba(236,72,153,0.1));
    border: 1px solid rgba(99,102,241,0.2);
    border-radius: 8px;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all 0.25s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.monitoring-table .btn-icon-sm:hover {
    background: linear-gradient(135deg, rgba(99,102,241,0.25), rgba(236,72,153,0.15));
    border-color: rgba(99,102,241,0.4);
    transform: translateY(-2px) scale(1.1);
    box-shadow: 0 4px 12px rgba(99,102,241,0.3);
}

@media (max-width: 1024px) {
    .monitoring-table {
        font-size: 0.8rem;
    }
    .monitoring-table th,
    .monitoring-table td {
        padding: 0.85rem 1rem;
    }
}

/* History Table */
.history-section {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1.5rem;
    margin-top: 1.75rem;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
}

.history-table th,
.history-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.history-table th {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    background: rgba(99, 102, 241, 0.05);
}

.history-table td {
    font-size: 0.85rem;
    color: var(--text-primary);
}

.history-table tr:hover {
    background: rgba(99, 102, 241, 0.02);
}

.history-table .amount-cell {
    font-weight: 700;
    color: #10b981;
}

.history-table .date-cell {
    color: var(--text-muted);
    font-size: 0.85rem;
}

.empty-state {
    text-align: center;
    padding: 2.5rem 1.5rem;
    color: var(--text-muted);
}

.empty-state svg {
    width: 56px;
    height: 56px;
    margin-bottom: 0.75rem;
    opacity: 0.3;
}

.empty-state p {
    font-size: 0.95rem;
    margin: 0;
}

/* Investor Fund Inflow Section */
.inflow-section {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.75rem;
}

.inflow-container {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.inflow-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
}

.inflow-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.inflow-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
}

.inflow-card:hover {
    border-color: rgba(99, 102, 241, 0.3);
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.1);
    transform: translateY(-2px);
}

.inflow-card.highlight {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
    border-color: rgba(99, 102, 241, 0.3);
}

.inflow-label {
    font-size: 0.65rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
    margin-bottom: 0.4rem;
}

.inflow-value {
    font-size: 1.3rem;
    font-weight: 700;
    color: #6366f1;
    margin-bottom: 0.3rem;
    word-break: break-word;
}

.inflow-value.color-success {
    color: #10b981;
}

.inflow-description {
    font-size: 0.7rem;
    color: var(--text-muted);
}

.inflow-table-wrapper {
    overflow-x: auto;
    border: 1px solid var(--border-color);
    border-radius: 12px;
}

.inflow-table {
    width: 100%;
    border-collapse: collapse;
}

.inflow-table thead {
    background: linear-gradient(90deg, rgba(99, 102, 241, 0.08), rgba(139, 92, 246, 0.08));
    border-bottom: 2px solid var(--border-color);
}

.inflow-table th {
    padding: 0.8rem 0.75rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.inflow-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}

.inflow-table tbody tr:hover {
    background: rgba(99, 102, 241, 0.04);
}

.inflow-table td {
    padding: 0.75rem;
    font-size: 0.85rem;
    color: var(--text-primary);
}

.investor-name-cell {
    font-weight: 600;
    color: #6366f1;
}

.contact-cell {
    color: var(--text-muted);
    font-size: 0.85rem;
}

.amount-cell {
    font-weight: 700;
    color: #10b981;
}

.percentage-cell {
    padding: 1rem;
}

.percentage-bar {
    position: relative;
    height: 24px;
    background: var(--bg-primary);
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
}

.percentage-fill {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
    transition: width 0.3s ease;
    border-radius: 8px;
}

.percentage-text {
    position: relative;
    z-index: 1;
    font-weight: 700;
    font-size: 0.75rem;
    color: var(--text-primary);
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

@media (max-width: 768px) {
    .inflow-summary {
        grid-template-columns: 1fr;
    }

    .inflow-table {
        font-size: 0.8rem;
    }

    .inflow-table td,
    .inflow-table th {
        padding: 0.75rem 0.5rem;
    }

    .percentage-bar {
        height: 24px;
    }
}

/* Modal Styling */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-overlay.active {
    display: flex;
}

.modal-content {
    background: var(--bg-secondary);
    border-radius: 12px;
    width: 100%;
    max-width: 480px;
    max-height: 90vh;
    overflow-y: auto;
    animation: slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    padding: 1.25rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--text-muted);
    cursor: pointer;
    transition: color 0.2s ease;
    padding: 0;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}

.modal-close:hover {
    color: var(--text-primary);
    background: rgba(99, 102, 241, 0.1);
}

.modal-body {
    padding: 1.5rem;
}

.form-group {
    margin-bottom: 1.2rem;
}

.form-group label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.75rem 0.9rem;
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.9rem;
    background: var(--bg-primary);
    color: var(--text-primary);
    transition: all 0.2s ease;
    font-family: inherit;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.modal-footer {
    padding: 1.25rem;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.btn-secondary {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

.btn-secondary:hover {
    background: rgba(99, 102, 241, 0.05);
    border-color: #6366f1;
    color: #6366f1;
}

@media (max-width: 768px) {
    .investor-page {
        padding: 1rem;
    }
    
    .page-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
    }
    
    .header-actions .btn {
        flex: 1;
    }
    
    .investor-grid {
        grid-template-columns: 1fr;
    }
    
    .projects-grid {
        grid-template-columns: 1fr;
    }
}

/* Deposits Grouped by Investor */
.deposits-grouped {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.investor-deposit-group {
    border: 1px solid var(--border-color);
    border-radius: 10px;
    overflow: hidden;
}

.group-header {
    width: 100%;
    padding: 1rem;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(139, 92, 246, 0.08));
    border: none;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    transition: all 0.2s ease;
}

.group-header:hover {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.12), rgba(139, 92, 246, 0.12));
}

.toggle-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    transition: transform 0.2s ease;
    color: #6366f1;
    font-size: 0.7rem;
}

.investor-deposit-group.collapsed .toggle-icon {
    transform: rotate(-90deg);
}

.investor-badge {
    font-size: 0.95rem;
    font-weight: 700;
    color: #6366f1;
    min-width: 100px;
}

.deposit-count {
    font-size: 0.8rem;
    color: var(--text-muted);
    background: rgba(99, 102, 241, 0.1);
    padding: 0.3rem 0.6rem;
    border-radius: 4px;
    font-weight: 600;
}

.total-amount {
    margin-left: auto;
    font-size: 1rem;
    font-weight: 700;
    color: #10b981;
}

.deposit-items {
    padding: 1rem;
    background: var(--bg-primary);
    display: none;
}

.investor-deposit-group.expanded .deposit-items {
    display: block;
}

.investor-contact {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
}

.deposit-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.deposit-table thead {
    background: rgba(99, 102, 241, 0.05);
}

.deposit-table th {
    padding: 0.6rem 0.8rem;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border-bottom: 1px solid var(--border-color);
}

.deposit-table td {
    padding: 0.65rem 0.8rem;
    border-bottom: 1px solid rgba(0, 0, 0, 0.03);
}

.deposit-table tbody tr:hover {
    background: rgba(99, 102, 241, 0.02);
}

.deposit-date {
    color: var(--text-muted);
    font-size: 0.8rem;
    white-space: nowrap;
}

.deposit-desc {
    color: var(--text-primary);
}

.deposit-amount {
    text-align: right;
    font-weight: 700;
    color: #10b981;
}

/* Bills Section */
.bills-section {
    margin-bottom: 2rem;
}

.bills-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.bill-stat-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.3s;
}

.bill-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-info {
    flex: 1;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.3rem;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1;
}

.stat-sub {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 0.2rem;
}

.bills-table-wrapper {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    overflow: hidden;
}

.bills-table {
    width: 100%;
    border-collapse: collapse;
}

.bills-table thead tr {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(139, 92, 246, 0.05));
}

.bills-table th {
    text-align: left;
    padding: 0.8rem 1rem;
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border-color);
}

.bills-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s;
}

.bills-table tbody tr:hover {
    background: rgba(99, 102, 241, 0.03);
}

.bills-table tbody tr.overdue {
    background: rgba(239, 68, 68, 0.03);
}

.bills-table td {
    padding: 1rem;
    font-size: 0.85rem;
}

.bill-title {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.2rem;
}

.bill-desc {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.category-badge {
    display: inline-block;
    padding: 0.3rem 0.75rem;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: capitalize;
}

.category-land { background: #dbeafe; color: #1e40af; }
.category-property { background: #e0e7ff; color: #4338ca; }
.category-utility { background: #fef3c7; color: #92400e; }
.category-tax { background: #fee2e2; color: #991b1b; }
.category-service { background: #d1fae5; color: #065f46; }
.category-legal { background: #e9d5ff; color: #6b21a8; }
.category-other { background: #f3f4f6; color: #374151; }

.status-badge {
    display: inline-block;
    padding: 0.3rem 0.75rem;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: capitalize;
}

.status-success { background: #d1fae5; color: #065f46; }
.status-warning { background: #fef3c7; color: #92400e; }
.status-danger { background: #fee2e2; color: #991b1b; }
.status-secondary { background: #f3f4f6; color: #6b7280; }

.bill-actions {
    display: flex;
    gap: 0.4rem;
    justify-content: center;
}

.btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    background: white;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.85rem;
}

.btn-icon:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.btn-icon.btn-success {
    background: #d1fae5;
    border-color: #10b981;
    color: #065f46;
}

.btn-icon.btn-success:hover {
    background: #10b981;
    color: white;
}

.btn-icon.btn-edit {
    background: #fef3c7;
    border-color: #f59e0b;
}

.btn-icon.btn-edit:hover {
    background: #f59e0b;
    color: white;
}

.btn-icon.btn-delete {
    background: #fee2e2;
    border-color: #ef4444;
}

.btn-icon.btn-delete:hover {
    background: #ef4444;
    color: white;
}

/* Tab Navigation */
.tab-nav {
    display: flex;
    gap: 0.25rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.tab-btn {
    padding: 0.75rem 1.25rem;
    border: none;
    background: none;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.2s ease;
    white-space: nowrap;
    margin-bottom: -2px;
}

.tab-btn:hover {
    color: #6366f1;
    background: rgba(99, 102, 241, 0.05);
    border-radius: 6px 6px 0 0;
}

.tab-btn.active {
    color: #6366f1;
    border-bottom-color: #6366f1;
}

.tab-panel {
    display: none;
}

.tab-panel.active {
    display: block;
    animation: tabFadeIn 0.25s ease;
}

@keyframes tabFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 768px) {
    .tab-btn {
        padding: 0.6rem 0.9rem;
        font-size: 0.8rem;
    }
}
</style>

<div class="investor-page">
    <!-- Header -->
    <div class="page-header">
        <h1>
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Investor & Projek
        </h1>
        <div class="header-actions">
            <button class="btn btn-success" onclick="openDepositModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Catat Setoran
            </button>
            <button class="btn btn-primary" onclick="openAddInvestorModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="8.5" cy="7" r="4"/>
                    <line x1="20" y1="8" x2="20" y2="14"/>
                    <line x1="23" y1="11" x2="17" y2="11"/>
                </svg>
                Tambah Investor
            </button>
            <button class="btn btn-danger" onclick="openResetModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M3 6h18"/>
                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                    <line x1="10" y1="11" x2="10" y2="17"/>
                    <line x1="14" y1="11" x2="14" y2="17"/>
                </svg>
                Reset Data
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="label">📊 Jumlah Investor</div>
            <div class="value"><?= $totalInvestors ?></div>
        </div>
        <div class="summary-card highlight">
            <div class="label">💰 Total Modal</div>
            <div class="value">Rp <?= number_format($totalCapital, 0, ',', '.') ?></div>
        </div>
        <div class="summary-card">
            <div class="label">🏗️ Active Projects</div>
            <div class="value"><?= $totalProjects ?></div>
        </div>
        <div class="summary-card">
            <div class="label">💸 Total Expenses</div>
            <div class="value" style="color:#d97706">Rp <?= number_format($total_all_expenses, 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-nav">
        <button class="tab-btn active" onclick="switchTab('ringkasan')" data-tab="ringkasan">📊 Ringkasan</button>
        <button class="tab-btn" onclick="switchTab('proyek')" data-tab="proyek">🏗️ Proyek</button>
        <button class="tab-btn" onclick="switchTab('tagihan')" data-tab="tagihan">📋 Tagihan</button>
        <button class="tab-btn" onclick="switchTab('investor')" data-tab="investor">👥 Investor</button>
    </div>

    <!-- Tab: Ringkasan -->
    <div id="tab-ringkasan" class="tab-panel active">

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="section-header">
            <h2 class="section-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M18 20V10M12 20V4M6 20v-6"/>
                </svg>
                Expense Charts
            </h2>
        </div>
        <div class="charts-grid">
            <div class="chart-card chart-pie">
                <h3>🍩 Expenses by Contractor</h3>
                <div class="chart-sub">Cost distribution by contractor / division</div>
                <?php if (empty($chart_contractor_pie)): ?>
                    <div class="chart-empty">No contractor expense data yet.<br>Select contractor when recording expenses in Ledger.</div>
                <?php else: ?>
                    <canvas id="pieChart"></canvas>
                <?php endif; ?>
            </div>
            <div class="chart-card chart-bar">
                <h3>📊 Budget vs Expenses</h3>
                <div class="chart-sub">Comparison of budget and actual expenses per project</div>
                <?php if (empty($chart_budget_vs_expense)): ?>
                    <div class="chart-empty">No project data available.</div>
                <?php else: ?>
                    <canvas id="barChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Project Transactions (from Cashbook) -->
    <div class="recent-project-section" style="margin-top: 1.5rem;">
        <div class="section-header">
            <h2 class="section-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
                Transaksi Proyek Terbaru
            </h2>
            <span style="font-size: 0.75rem; color: var(--text-muted);">Dari Buku Kas Besar</span>
        </div>
        
        <!-- Debug info (temporary) -->
        <div style="font-size: 0.7rem; color: #6b7280; background: #f3f4f6; padding: 0.5rem 0.75rem; border-radius: 6px; margin-bottom: 0.75rem; font-family: monospace;">
            📊 <?= htmlspecialchars($projectExpenseDebug) ?>
        </div>
        
        <?php if (empty($recentProjectExpenses)): ?>
            <div style="text-align: center; padding: 2rem; color: var(--text-muted); background: var(--bg-secondary); border-radius: 12px; border: 1px dashed var(--border-color);">
                <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom: 0.75rem; opacity: 0.4;">
                    <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <div style="font-size: 0.85rem;">Belum ada transaksi proyek dari buku kas.</div>
                <div style="font-size: 0.75rem; margin-top: 0.25rem;">Tandai pengeluaran sebagai "Pengeluaran Proyek" di <strong>Buku Kas</strong> untuk sinkronisasi otomatis.</div>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto; border-radius: 12px; border: 1px solid var(--border-color);">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
                    <thead>
                        <tr style="background: linear-gradient(135deg, rgba(245,158,11,0.15), rgba(217,119,6,0.1));">
                            <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #92400e; border-bottom: 2px solid rgba(245,158,11,0.2);">Tanggal</th>
                            <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #92400e; border-bottom: 2px solid rgba(245,158,11,0.2);">Proyek</th>
                            <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #92400e; border-bottom: 2px solid rgba(245,158,11,0.2);">Deskripsi</th>
                            <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #92400e; border-bottom: 2px solid rgba(245,158,11,0.2);">Divisi</th>
                            <th style="padding: 0.75rem 1rem; text-align: right; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #92400e; border-bottom: 2px solid rgba(245,158,11,0.2);">Jumlah</th>
                            <th style="padding: 0.75rem 1rem; text-align: center; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #92400e; border-bottom: 2px solid rgba(245,158,11,0.2);">Sumber</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentProjectExpenses as $idx => $pe): ?>
                        <tr style="border-bottom: 1px solid var(--border-color); background: <?= $idx % 2 === 0 ? 'var(--bg-secondary)' : 'var(--bg-primary)' ?>; transition: background 0.2s;" onmouseover="this.style.background='rgba(245,158,11,0.08)'" onmouseout="this.style.background='<?= $idx % 2 === 0 ? 'var(--bg-secondary)' : 'var(--bg-primary)' ?>'">
                            <td style="padding: 0.6rem 1rem; white-space: nowrap; color: var(--text-primary);">
                                <?= date('d M Y', strtotime($pe['expense_date'] ?? $pe['created_at'])) ?>
                            </td>
                            <td style="padding: 0.6rem 1rem;">
                                <div style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($pe['project_name'] ?? '-') ?></div>
                                <?php if (!empty($pe['project_code'])): ?>
                                    <div style="font-size: 0.7rem; color: var(--text-muted);"><?= htmlspecialchars($pe['project_code']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.6rem 1rem; color: var(--text-primary); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= htmlspecialchars($pe['description'] ?? ($pe['category_name'] ?? '-')) ?>
                            </td>
                            <td style="padding: 0.6rem 1rem;">
                                <?php if (!empty($pe['division_name'])): ?>
                                    <span style="display: inline-block; padding: 0.2rem 0.5rem; background: rgba(99,102,241,0.1); color: #6366f1; border-radius: 4px; font-size: 0.7rem; font-weight: 600;">
                                        <?= htmlspecialchars($pe['division_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.6rem 1rem; text-align: right; font-weight: 700; color: #d97706; white-space: nowrap;">
                                Rp <?= number_format($pe['amount'], 0, ',', '.') ?>
                            </td>
                            <td style="padding: 0.6rem 1rem; text-align: center;">
                                <?php if (!empty($pe['cash_book_id'])): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.5rem; background: rgba(16,185,129,0.1); color: #059669; border-radius: 4px; font-size: 0.68rem; font-weight: 600;">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                                        Kas Besar
                                    </span>
                                <?php else: ?>
                                    <span style="display: inline-block; padding: 0.2rem 0.5rem; background: rgba(107,114,128,0.1); color: #6b7280; border-radius: 4px; font-size: 0.68rem;">Manual</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="text-align: right; margin-top: 0.5rem; font-size: 0.72rem; color: var(--text-muted);">
                Menampilkan <?= count($recentProjectExpenses) ?> transaksi terbaru
            </div>
        <?php endif; ?>
    </div>

    <!-- Investor Fund Inflow Details -->
    <div class="inflow-section">
        <div class="section-header">
            <h2 class="section-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"/>
                </svg>
                Investor Fund Inflow Details
            </h2>
        </div>
        <div class="inflow-container">
            <div class="inflow-summary">
                <div class="inflow-card">
                    <div class="inflow-label">Total Investors</div>
                    <div class="inflow-value"><?= $totalInvestors ?></div>
                    <div class="inflow-description">Active contributors</div>
                </div>
                <div class="inflow-card highlight">
                    <div class="inflow-label">Total Capital Received</div>
                    <div class="inflow-value color-success">Rp <?= number_format($totalCapital, 0, ',', '.') ?></div>
                    <div class="inflow-description">From all investor deposits</div>
                </div>
                <div class="inflow-card">
                    <div class="inflow-label">Average per Investor</div>
                    <div class="inflow-value">Rp <?= number_format($totalInvestors > 0 ? $totalCapital / $totalInvestors : 0, 0, ',', '.') ?></div>
                    <div class="inflow-description">Capital distribution</div>
                </div>
            </div>

            <div class="inflow-table-wrapper">
                <table class="inflow-table">
                    <thead>
                        <tr>
                            <th>Investor Name</th>
                            <th>Contact</th>
                            <th>Capital Contributed</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($investors as $investor): ?>
                        <tr>
                            <td class="investor-name-cell">
                                <strong><?= htmlspecialchars($investor['name'] ?? $investor['investor_name'] ?? '-') ?></strong>
                            </td>
                            <td class="contact-cell"><?= htmlspecialchars($investor['contact'] ?? $investor['contact_phone'] ?? '-') ?></td>
                            <td class="amount-cell">
                                Rp <?= number_format($investor['total_capital'] ?? $investor['balance'] ?? 0, 0, ',', '.') ?>
                            </td>
                            <td class="percentage-cell">
                                <div class="percentage-bar">
                                    <div class="percentage-fill" style="width: <?= $totalCapital > 0 ? (($investor['total_capital'] ?? $investor['balance'] ?? 0) / $totalCapital * 100) : 0 ?>%"></div>
                                    <span class="percentage-text"><?= $totalCapital > 0 ? number_format((($investor['total_capital'] ?? $investor['balance'] ?? 0) / $totalCapital * 100), 1) : 0 ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    </div><!-- /tab: ringkasan -->

    <!-- Tab: Proyek -->
    <div id="tab-proyek" class="tab-panel">

    <!-- Projects Section -->
    <div class="projects-section">
        <div class="section-header">
            <h2 class="section-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M9 11l3 3L22 4"/>
                    <path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Project Management
            </h2>
            <button class="btn btn-primary" onclick="openAddProjectModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Add Project
            </button>
        </div>

        <div class="projects-grid">
            <?php if (empty($projects)): ?>
                <div class="add-project-card" onclick="openAddProjectModal()">
                    <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="9" y1="9" x2="15" y2="9"/>
                        <line x1="9" y1="15" x2="15" y2="15"/>
                    </svg>
                    <div class="text">Create First Project</div>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">Manage project budget and expenses with ledger</p>
                </div>
            <?php else: ?>
                <?php foreach ($projects as $project): ?>
                <div class="project-card" onclick="goToProjectLedger(<?= $project['id'] ?>)">
                    <div>
                        <div class="project-name"><?= htmlspecialchars($project['project_name'] ?? 'N/A') ?></div>
                        <div class="project-code">
                            <?php
                            $code = $project['project_code'] ?? 'PROJ-' . str_pad($project['id'], 4, '0', STR_PAD_LEFT);
                            echo htmlspecialchars($code);
                            ?>
                        </div>
                    </div>
                    <div class="project-amount">
                        Rp <?= number_format($project['budget_idr'] ?? 0, 0, ',', '.') ?>
                    </div>
                    <div class="project-meta">
                        <div class="meta-item">
                            <span>Pengeluaran</span>
                            <div class="meta-value" style="color:#d97706">Rp <?= number_format($project['grand_expenses'] ?? $project['total_expenses'] ?? 0, 0, ',', '.') ?></div>
                        </div>
                        <div class="meta-item">
                            <span>Sisa</span>
                            <?php $sisa = ($project['budget_idr'] ?? 0) - ($project['grand_expenses'] ?? 0); ?>
                            <div class="meta-value" style="color:<?= $sisa >= 0 ? '#059669' : '#dc2626' ?>">Rp <?= number_format($sisa, 0, ',', '.') ?></div>
                        </div>
                    </div>
                    <div class="project-actions">
                        <button class="btn btn-sm btn-kas" onclick="event.stopPropagation(); goToProjectLedger(<?= $project['id'] ?>)">
                            📊 Ledger
                        </button>
                        <button class="btn btn-sm btn-edit" onclick="event.stopPropagation(); editProject(<?= $project['id'] ?>)">
                            ✏️ Edit
                        </button>
                        <button class="btn btn-sm btn-hapus" onclick="event.stopPropagation(); deleteProject(<?= $project['id'] ?>, '<?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?>')">
                            🗑️ Delete
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="add-project-card" onclick="openAddProjectModal()">
                    <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <div class="text">Add New Project</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Project Monitoring Table -->
        <?php if (!empty($projects)): ?>
        <div class="project-monitoring-table">
            <div class="table-header">
                <h3 class="table-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        <path d="M9 12l2 2 4-4"/>
                    </svg>
                    Project Monitoring Overview
                </h3>
            </div>
            <div class="monitoring-table-wrapper">
                <table class="monitoring-table">
                    <thead>
                        <tr>
                            <th width="5%">No</th>
                            <th width="25%">Project Details</th>
                            <th width="15%">Budget</th>
                            <th width="15%">Expenses</th>
                            <th width="15%">Remaining</th>
                            <th width="15%">Progress</th>
                            <th width="10%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach ($projects as $project): 
                            $budget = $project['budget_idr'] ?? 0;
                            $expenses = $project['grand_expenses'] ?? $project['total_expenses'] ?? 0;
                            $remaining = $budget - $expenses;
                            $progress = $budget > 0 ? ($expenses / $budget * 100) : 0;
                            $progress = min($progress, 100);
                            $status = $project['status'] ?? 'ongoing';
                        ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td>
                                <div class="project-details">
                                    <div class="project-name-table"><?= htmlspecialchars($project['project_name'] ?? 'N/A') ?></div>
                                    <div class="project-code-table"><?= htmlspecialchars($project['project_code'] ?? 'PROJ-' . str_pad($project['id'], 4, '0', STR_PAD_LEFT)) ?></div>
                                </div>
                            </td>
                            <td class="amount-cell">Rp <?= number_format($budget, 0, ',', '.') ?></td>
                            <td class="amount-cell text-warning">Rp <?= number_format($expenses, 0, ',', '.') ?></td>
                            <td class="amount-cell <?= $remaining >= 0 ? 'text-success' : 'text-danger' ?>">
                                Rp <?= number_format(abs($remaining), 0, ',', '.') ?>
                                <?php if ($remaining < 0): ?>
                                    <span class="badge-danger">Over Budget</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="progress-container">
                                    <div class="progress-bar <?= $progress >= 90 ? 'danger' : ($progress >= 70 ? 'warning' : 'success') ?>" style="width: <?= $progress ?>%"></div>
                                </div>
                                <div class="progress-text"><?= number_format($progress, 1) ?>%</div>
                            </td>
                            <td class="text-center">
                                <button class="btn-icon-sm" onclick="goToProjectLedger(<?= $project['id'] ?>)" title="Open Ledger">
                                    📊
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    </div><!-- /tab: proyek -->

    <!-- Tab: Tagihan -->
    <div id="tab-tagihan" class="tab-panel">

    <!-- Bills Section -->
    <div class="bills-section">
        <div class="section-header">
            <h2 class="section-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M9 2a1 1 0 0 0-1 1v1H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V3a1 1 0 1 0-2 0v1H10V3a1 1 0 0 0-1-1z"/>
                    <path d="M6 8h12"/>
                    <path d="M8 12h2"/>
                    <path d="M8 16h5"/>
                </svg>
                Bills & Payments
            </h2>
            <button class="btn btn-primary" onclick="openAddBillModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Add Bill
            </button>
        </div>

        <div class="bills-summary">
            <div class="bill-stat-card">
                <div class="stat-icon" style="background: #fef3c7;">💰</div>
                <div class="stat-info">
                    <div class="stat-label">Total Bills</div>
                    <div class="stat-value"><?= $totalBills ?></div>
                </div>
            </div>
            <div class="bill-stat-card">
                <div class="stat-icon" style="background: #fecaca;">⏰</div>
                <div class="stat-info">
                    <div class="stat-label">Unpaid</div>
                    <div class="stat-value" style="color: #dc2626;"><?= $totalUnpaid ?></div>
                    <div class="stat-sub">Rp <?= number_format($unpaidAmount, 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="bill-stat-card">
                <div class="stat-icon" style="background: #d1fae5;">✅</div>
                <div class="stat-info">
                    <div class="stat-label">Paid</div>
                    <div class="stat-value" style="color: #059669;"><?= $totalPaid ?></div>
                    <div class="stat-sub">Rp <?= number_format($paidAmount, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>

        <div class="bills-table-wrapper">
            <?php if (empty($bills)): ?>
                <div class="empty-state">
                    <svg width="64" height="64" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M9 2a1 1 0 0 0-1 1v1H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V3a1 1 0 1 0-2 0v1H10V3a1 1 0 0 0-1-1z"/>
                    </svg>
                    <p>No bills recorded yet</p>
                    <button class="btn btn-primary" onclick="openAddBillModal()">Add First Bill</button>
                </div>
            <?php else: ?>
                <table class="bills-table">
                    <thead>
                        <tr>
                            <th>Title & Description</th>
                            <th>Category</th>
                            <th style="text-align: right;">Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bills as $bill): ?>
                        <tr class="bill-row <?= $bill['status'] === 'overdue' ? 'overdue' : '' ?>">
                            <td>
                                <div class="bill-title"><?= htmlspecialchars($bill['title']) ?></div>
                                <?php if ($bill['description']): ?>
                                <div class="bill-desc"><?= htmlspecialchars(substr($bill['description'], 0, 60)) ?><?= strlen($bill['description']) > 60 ? '...' : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="category-badge category-<?= $bill['category'] ?>">
                                    <?= ucfirst($bill['category']) ?>
                                </span>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #333;">
                                Rp <?= number_format($bill['amount'], 0, ',', '.') ?>
                            </td>
                            <td>
                                <?= $bill['due_date'] ? date('d M Y', strtotime($bill['due_date'])) : '-' ?>
                            </td>
                            <td>
                                <?php
                                $statusColors = [
                                    'unpaid' => 'warning',
                                    'paid' => 'success',
                                    'overdue' => 'danger',
                                    'cancelled' => 'secondary'
                                ];
                                $statusColor = $statusColors[$bill['status']] ?? 'secondary';
                                ?>
                                <span class="status-badge status-<?= $statusColor ?>">
                                    <?= ucfirst($bill['status']) ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <div class="bill-actions">
                                    <?php if ($bill['status'] === 'unpaid' || $bill['status'] === 'overdue'): ?>
                                    <button class="btn-icon btn-success" onclick="markAsPaid(<?= $bill['id'] ?>)" title="Mark as Paid">
                                        ✓
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn-icon btn-edit" onclick="editBill(<?= $bill['id'] ?>)" title="Edit">
                                        ✏️
                                    </button>
                                    <button class="btn-icon btn-delete" onclick="deleteBill(<?= $bill['id'] ?>, '<?= htmlspecialchars($bill['title'], ENT_QUOTES) ?>')" title="Delete">
                                        🗑️
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    </div><!-- /tab: tagihan -->

    <!-- Tab: Investor -->
    <div id="tab-investor" class="tab-panel">

    <!-- Investor List -->
    <div class="section-header">
        <h2 class="section-title">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Investor List
        </h2>
    </div>
    
    <div class="investor-grid">
        <?php if (empty($investors)): ?>
            <div class="empty-state">
                <svg width="64" height="64" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                </svg>
                <p>No investor data yet</p>
            </div>
        <?php else: ?>
            <?php foreach ($investors as $investor): ?>
            <div class="investor-card">
                <div class="investor-info">
                    <h3 class="investor-name"><?= htmlspecialchars($investor['name'] ?? $investor['investor_name'] ?? '') ?></h3>
                    <p class="investor-meta">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                        </svg>
                        <?= htmlspecialchars($investor['contact'] ?? $investor['contact_phone'] ?? '-') ?>
                    </p>
                </div>
                <div class="investor-details">
                    <div class="detail-item">
                        <span class="label">Total Capital</span>
                        <span class="value">Rp <?= number_format($investor['total_capital'] ?? $investor['balance'] ?? 0, 0, ',', '.') ?></span>
                    </div>
                </div>
                <div class="investor-card-divider"></div>
                <div class="investor-card-content">
                    <div class="investor-info-box">
                        <span class="info-label">Added</span>
                        <span class="info-value"><?= date('d M Y', strtotime($investor['created_at'] ?? now())) ?></span>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn btn-sm btn-setoran" onclick="openDepositModal(<?= $investor['id'] ?>, '<?= htmlspecialchars($investor['name'] ?? $investor['investor_name'] ?? '') ?>')">
                        ➕ Deposit
                    </button>
                    <button class="btn btn-sm btn-history" onclick="viewHistory(<?= $investor['id'] ?>)">
                        🕐 History
                    </button>
                    <button class="btn btn-sm btn-inv-edit" onclick="editInvestor(<?= $investor['id'] ?>)">
                        ✏️ Edit
                    </button>
                    <button class="btn btn-sm btn-hapus" onclick="deleteInvestor(<?= $investor['id'] ?>, '<?= htmlspecialchars($investor['name'] ?? $investor['investor_name'] ?? '') ?>')">
                        🗑️ Delete
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Recent Deposits History -->
    <div class="history-section">
        <h2 class="section-title">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12,6 12,12 16,14"/>
            </svg>
            Recent Deposit History
        </h2>
        
        <?php include "deposits-history.php"; ?>
    </div>

    </div><!-- /tab: investor -->
</div>

<!-- Modal: Investor Transaction History -->
<div class="modal-overlay" id="investorHistoryModal" onclick="if(event.target===this) closeModal('investorHistoryModal')">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
            <div>
                <h3 style="color: white; margin: 0 0 0.3rem 0;">📊 Transaction History</h3>
                <p style="color: rgba(255,255,255,0.8); margin: 0; font-size: 0.85rem;" id="historyInvestorName">-</p>
                <p style="color: rgba(255,255,255,0.7); margin: 0.2rem 0 0 0; font-size: 0.75rem;" id="historyInvestorPhone">-</p>
            </div>
            <button class="modal-close" onclick="closeModal('investorHistoryModal')" style="color: white;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 0;">
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);">
                            <th style="text-align: left; padding: 0.8rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Date & Time</th>
                            <th style="text-align: right; padding: 0.8rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Amount</th>
                            <th style="text-align: center; padding: 0.8rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Type</th>
                            <th style="text-align: left; padding: 0.8rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Description</th>
                        </tr>
                    </thead>
                    <tbody id="historyTransactionTable">
                        <tr><td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Reset Data Investor -->
<div class="modal-overlay" id="resetDataModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
            <h3 style="color: white;">⚠️ Reset Investor Data</h3>
            <button class="modal-close" onclick="closeModal('resetDataModal')" style="color: white;">&times;</button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; padding: 1rem;">
                <svg width="64" height="64" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24" style="margin-bottom: 1rem;">
                    <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <h4 style="color: #dc2626; margin-bottom: 1rem;">Warning!</h4>
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                    This action will <strong>DELETE ALL investor data</strong> including:
                </p>
                <ul style="text-align: left; color: var(--text-secondary); padding-left: 2rem; margin-bottom: 1.5rem;">
                    <li>All investor records</li>
                    <li>All deposit history</li>
                    <li>All investor transactions</li>
                </ul>
                <p style="color: #dc2626; font-weight: 600;">
                    Deleted data CANNOT be recovered!
                </p>
            </div>
            <div class="form-group" style="margin-top: 1rem;">
                <label>Type <strong style="color: #dc2626;">RESET</strong> to confirm:</label>
                <input type="text" id="resetConfirmInput" placeholder="Type RESET" style="text-transform: uppercase;">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('resetDataModal')">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="executeResetData()" id="resetExecuteBtn" disabled>
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M3 6h18"/>
                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                </svg>
                Reset All Data
            </button>
        </div>
    </div>
</div>

<!-- Modal: Add Investor -->
<div class="modal-overlay" id="addInvestorModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Investor</h3>
            <button class="modal-close" onclick="closeModal('addInvestorModal')">&times;</button>
        </div>
        <form id="addInvestorForm" onsubmit="saveInvestor(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label>Investor Name *</label>
                    <input type="text" name="name" required placeholder="Full investor name">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" placeholder="+62xxxxx">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="email@example.com">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addInvestorModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Investor -->
<div class="modal-overlay" id="editInvestorModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Investor</h3>
            <button class="modal-close" onclick="closeModal('editInvestorModal')">&times;</button>
        </div>
        <form id="editInvestorForm" onsubmit="saveInvestorEdit(event)">
            <input type="hidden" name="investor_id" id="editInvestorId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Investor Name *</label>
                    <input type="text" name="name" id="editInvestorName" required placeholder="Full investor name">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" id="editInvestorPhone" placeholder="+62xxxxx">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="editInvestorEmail" placeholder="email@example.com">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="editInvestorNotes" rows="2" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editInvestorModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Project -->
<div class="modal-overlay" id="addProjectModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Project</h3>
            <button class="modal-close" onclick="closeModal('addProjectModal')">&times;</button>
        </div>
        <form id="addProjectForm" onsubmit="saveProject(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label>Project Name *</label>
                    <input type="text" name="project_name" required placeholder="Project name">
                </div>
                <div class="form-group">
                    <label>Project Code</label>
                    <input type="text" name="project_code" placeholder="PROJ-001">
                </div>
                <div class="form-group">
                    <label>Budget (IDR) *</label>
                    <input type="number" name="budget_idr" required placeholder="0" min="1">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2" placeholder="Project description..."></textarea>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" required>
                        <option value="planning">Planning</option>
                        <option value="ongoing" selected>Ongoing</option>
                        <option value="on_hold">On Hold</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addProjectModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Project</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Deposit -->
<div class="modal-overlay" id="depositModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Record Investor Deposit</h3>
            <button class="modal-close" onclick="closeModal('depositModal')">&times;</button>
        </div>
        <form id="depositForm" onsubmit="saveDeposit(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label>Select Investor *</label>
                    <select name="investor_id" id="depositInvestorSelect" required>
                        <option value="">-- Select Investor --</option>
                        <?php foreach ($investors as $inv): ?>
                        <option value="<?= $inv['id'] ?>"><?= htmlspecialchars($inv['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Deposit Amount (IDR) *</label>
                    <input type="text" id="depositAmount" name="amount" required placeholder="0" data-currency>
                </div>
                <div class="form-group">
                    <label>Deposit Date</label>
                    <input type="date" name="deposit_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2" placeholder="Deposit description..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('depositModal')">Cancel</button>
                <button type="submit" class="btn btn-success">Save Deposit</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Bill -->
<div class="modal-overlay" id="addBillModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Bill</h3>
            <button class="modal-close" onclick="closeModal('addBillModal')">&times;</button>
        </div>
        <form id="addBillForm" onsubmit="saveBill(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label>Bill Title *</label>
                    <input type="text" name="title" required placeholder="e.g., Land Payment Kavling A">
                </div>
                <div class="form-group">
                    <label>Bill Number</label>
                    <input type="text" name="bill_number" placeholder="e.g., INV-2025-001">
                </div>
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category" required>
                        <option value="land">Land</option>
                        <option value="property">Property</option>
                        <option value="utility">Utility</option>
                        <option value="tax">Tax</option>
                        <option value="service">Service</option>
                        <option value="legal">Legal</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount (IDR) *</label>
                    <input type="text" id="billAmount" name="amount" required placeholder="0" data-currency>
                </div>
                <div class="form-group">
                    <label>Due Date</label>
                    <input type="date" name="due_date">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2" placeholder="Bill details..."></textarea>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addBillModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Bill</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Bill -->
<div class="modal-overlay" id="editBillModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Bill</h3>
            <button class="modal-close" onclick="closeModal('editBillModal')">&times;</button>
        </div>
        <form id="editBillForm" onsubmit="updateBill(event)">
            <input type="hidden" name="id" id="editBillId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Bill Title *</label>
                    <input type="text" name="title" id="editBillTitle" required placeholder="e.g., Land Payment Kavling A">
                </div>
                <div class="form-group">
                    <label>Bill Number</label>
                    <input type="text" name="bill_number" id="editBillNumber" placeholder="e.g., INV-2025-001">
                </div>
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category" id="editBillCategory" required>
                        <option value="land">Land</option>
                        <option value="property">Property</option>
                        <option value="utility">Utility</option>
                        <option value="tax">Tax</option>
                        <option value="service">Service</option>
                        <option value="legal">Legal</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount (IDR) *</label>
                    <input type="text" id="editBillAmount" name="amount" required placeholder="0" data-currency>
                </div>
                <div class="form-group">
                    <label>Due Date</label>
                    <input type="date" name="due_date" id="editBillDueDate">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="editBillDescription" rows="2" placeholder="Bill details..."></textarea>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="editBillNotes" rows="2" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editBillModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Bill</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="deposits-script.js"></script>
<script>
// ====== TAB NAVIGATION ======
function switchTab(tabId) {
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById('tab-' + tabId).classList.add('active');
    document.querySelector('.tab-btn[data-tab="' + tabId + '"]').classList.add('active');
}

// ====== GLOBAL FUNCTIONS ======
// Currency Formatter for IDR
function formatCurrency(value) {
    if (!value) return '';
    const numericValue = parseInt(value.toString().replace(/\D/g, '')) || 0;
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(numericValue).replace('IDR', '').trim();
}

// ====== CHART RENDERING ======
document.addEventListener('DOMContentLoaded', function() {
    const darkMode = document.documentElement.classList.contains('dark') || document.body.classList.contains('dark-mode');
    const textColor = darkMode ? '#ccc' : '#666';
    const gridColor = darkMode ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';

    // Add currency formatting to deposit amount input
    const depositAmountInput = document.getElementById('depositAmount');
    if (depositAmountInput) {
        depositAmountInput.addEventListener('input', function(e) {
            const value = e.target.value;
            const numericValue = value.replace(/\D/g, '');
            if (numericValue) {
                e.target.value = 'IDR ' + formatCurrency(numericValue);
            }
        });
    }

    // Add currency formatting to bill amount inputs
    const billAmountInput = document.getElementById('billAmount');
    if (billAmountInput) {
        billAmountInput.addEventListener('input', function(e) {
            const value = e.target.value;
            const numericValue = value.replace(/\D/g, '');
            if (numericValue) {
                e.target.value = 'IDR ' + formatCurrency(numericValue);
            }
        });
    }

    const editBillAmountInput = document.getElementById('editBillAmount');
    if (editBillAmountInput) {
        editBillAmountInput.addEventListener('input', function(e) {
            const value = e.target.value;
            const numericValue = value.replace(/\D/g, '');
            if (numericValue) {
                e.target.value = 'IDR ' + formatCurrency(numericValue);
            }
        });
    }

    // Pie Chart - Pengeluaran per Kontraktor
    <?php if (!empty($chart_contractor_pie)): ?>
    const pieCtx = document.getElementById('pieChart');
    if (pieCtx) {
        const pieColors = ['#6366f1','#ec4899','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ef4444','#14b8a6','#f97316','#06b6d4','#84cc16','#e879f9'];
        new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_keys($chart_contractor_pie)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($chart_contractor_pie)) ?>,
                    backgroundColor: pieColors.slice(0, <?= count($chart_contractor_pie) ?>),
                    borderWidth: 2,
                    borderColor: darkMode ? '#1e1e2e' : '#fff',
                    hoverOffset: 8,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: textColor, padding: 12, font: { size: 12, weight: 600 }, usePointStyle: true, pointStyle: 'circle' }
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

    // Bar Chart - Budget vs Pengeluaran
    <?php if (!empty($chart_budget_vs_expense)): ?>
    const barCtx = document.getElementById('barChart');
    if (barCtx) {
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($chart_budget_vs_expense, 'name')) ?>,
                datasets: [
                    {
                        label: 'Budget',
                        data: <?= json_encode(array_column($chart_budget_vs_expense, 'budget')) ?>,
                        backgroundColor: 'rgba(59,130,246,0.7)',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.6,
                    },
                    {
                        label: 'Pengeluaran',
                        data: <?= json_encode(array_column($chart_budget_vs_expense, 'expense')) ?>,
                        backgroundColor: 'rgba(245,158,11,0.7)',
                        borderColor: '#f59e0b',
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { color: textColor, font: { size: 12, weight: 600 }, usePointStyle: true, pointStyle: 'circle', padding: 16 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': Rp ' + ctx.parsed.y.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: textColor, font: { size: 11 } },
                        grid: { display: false }
                    },
                    y: {
                        ticks: {
                            color: textColor,
                            font: { size: 11 },
                            callback: function(val) {
                                if (val >= 1e9) return 'Rp ' + (val/1e9).toFixed(1) + 'M';
                                if (val >= 1e6) return 'Rp ' + (val/1e6).toFixed(0) + 'Jt';
                                if (val >= 1e3) return 'Rp ' + (val/1e3).toFixed(0) + 'Rb';
                                return 'Rp ' + val;
                            }
                        },
                        grid: { color: gridColor }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});

// ====== EXISTING FUNCTIONS ======
function openAddInvestorModal() {
    document.getElementById('addInvestorForm').reset();
    document.getElementById('addInvestorModal').classList.add('active');
}

function openDepositModal(investorId = null, investorName = null) {
    document.getElementById('depositForm').reset();
    if (investorId) {
        document.getElementById('depositInvestorSelect').value = investorId;
    }
    document.getElementById('depositModal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

async function saveInvestor(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-save.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('✅ Investor saved successfully');
            location.reload();
        } else {
            alert('❌ Error: ' + (result.message || 'Failed to save'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function saveDeposit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    
    // Extract numeric value from currency-formatted input
    const amountInput = document.getElementById('depositAmount')?.value || formData.get('amount');
    const numericAmount = parseInt(amountInput.replace(/\D/g, '')) || 0;
    formData.set('amount', numericAmount);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-deposit.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('✅ Deposit recorded successfully');
            location.reload();
        } else {
            alert('❌ Error: ' + (result.message || 'Failed to save'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

async function viewHistory(investorId) {
    try {
        // Fetch investor details
        const investorRes = await fetch('<?= BASE_URL ?>/api/investor-get.php?id=' + encodeURIComponent(investorId));
        
        if (!investorRes.ok) {
            console.error('Investor fetch failed:', investorRes.status, investorRes.statusText);
            alert('Failed to load investor data (HTTP ' + investorRes.status + ')');
            return;
        }
        
        const investorData = await investorRes.json();
        
        if (!investorData.success || !investorData.investor) {
            console.error('Investor data error:', investorData);
            alert('Failed to load investor data: ' + (investorData.message || 'Unknown error'));
            return;
        }
        
        const investor = investorData.investor;
        const investorName = investor.name || investor.investor_name || 'Unknown Investor';
        
        // Fetch investor transactions
        console.log('Fetching transactions for investor:', investorId);
        const transRes = await fetch('<?= BASE_URL ?>/api/investor-transactions.php?investor_id=' + encodeURIComponent(investorId));
        
        if (!transRes.ok) {
            console.error('Transaction fetch failed:', transRes.status, transRes.statusText);
            alert('Failed to load transactions (HTTP ' + transRes.status + ')');
            return;
        }
        
        const transData = await transRes.json();
        console.log('Transaction data received:', transData);
        
        if (!transData.success) {
            console.error('Transaction API error:', transData);
            alert('Failed to load transactions: ' + (transData.message || 'Unknown error'));
            return;
        }
        
        const transactions = transData.transactions || [];
        console.log('Number of transactions:', transactions.length);
        
        // Populate modal
        document.getElementById('historyInvestorName').textContent = investorName;
        document.getElementById('historyInvestorPhone').textContent = investor.contact || investor.contact_phone || '-';
        
        const historyTable = document.getElementById('historyTransactionTable');
        historyTable.innerHTML = '';
        
        if (transactions.length === 0) {
            historyTable.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 1.5rem; color: var(--text-muted);">No transactions found</td></tr>';
        } else {
            transactions.forEach(trans => {
                const row = document.createElement('tr');
                const date = new Date(trans.created_at);
                const dateStr = date.toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' });
                const timeStr = date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
                
                const type = trans.type || trans.transaction_type || 'capital';
                const typeDisplay = type === 'capital' ? 'Capital' : type === 'expense' ? 'Expense' : 'Return';
                const typeColor = type === 'capital' ? '#10b981' : type === 'expense' ? '#ef4444' : '#f59e0b';
                
                const amount = trans.amount || 0;
                
                row.innerHTML = `
                    <td style="font-size: 0.75rem; padding: 0.6rem;">${dateStr} ${timeStr}</td>
                    <td style="font-size: 0.75rem; padding: 0.6rem;">${formatCurrency(amount)}</td>
                    <td style="font-size: 0.75rem; padding: 0.6rem;"><span style="background: ${typeColor}15; color: ${typeColor}; padding: 0.2rem 0.5rem; border-radius: 4px; font-weight: 600;">${typeDisplay}</span></td>
                    <td style="font-size: 0.75rem; padding: 0.6rem; color: var(--text-muted);">${trans.description || 'No description'}</td>
                `;
                historyTable.appendChild(row);
            });
        }
        
        // Show modal
        document.getElementById('investorHistoryModal').classList.add('active');
    } catch (error) {
        console.error('Error in viewHistory:', error);
        alert('Error loading history: ' + error.message);
    }
}

async function editInvestor(investorId) {
    try {
        // Fetch investor data
        const response = await fetch('<?= BASE_URL ?>/api/investor-get.php?id=' + encodeURIComponent(investorId));
        const result = await response.json();
        
        if (result.success && result.investor) {
            const investor = result.investor;
            
            // Populate form fields
            document.getElementById('editInvestorId').value = investor.id;
            document.getElementById('editInvestorName').value = investor.name || investor.investor_name || '';
            document.getElementById('editInvestorPhone').value = investor.phone || investor.contact_phone || '';
            document.getElementById('editInvestorEmail').value = investor.email || '';
            document.getElementById('editInvestorNotes').value = investor.notes || '';
            
            // Open modal
            document.getElementById('editInvestorModal').classList.add('active');
        } else {
            alert('❌ Failed to load investor data: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function saveInvestorEdit(event) {
    event.preventDefault();
    
    const investorId = document.getElementById('editInvestorId').value;
    if (!investorId) {
        alert('ID investor tidak ditemukan');
        return;
    }
    
    const form = event.target;
    const formData = new FormData(form);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-update.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('✅ Investor berhasil diperbarui');
            closeModal('editInvestorModal');
            location.reload();
        } else {
            alert('❌ Error: ' + (result.message || 'Gagal menyimpan'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

async function deleteInvestor(investorId, investorName) {
    if (!confirm(`Are you sure you want to delete investor "${investorName}"?\n\nAll deposit funds and investor transactions will be DELETED!\n\nThis action CANNOT be undone.`)) {
        return;
    }
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'investor_id=' + encodeURIComponent(investorId)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ ' + (result.message || 'Investor deleted successfully'));
            location.reload();
        } else {
            alert('❌ Error: ' + (result.message || 'Gagal menghapus investor'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

function openAddProjectModal() {
    document.getElementById('addProjectForm').reset();
    document.getElementById('addProjectModal').classList.add('active');
}

async function saveProject(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-project-save.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('✅ Project saved successfully');
            location.reload();
        } else {
            alert('❌ Error: ' + (result.message || 'Failed to save'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

function goToProjectLedger(projectId) {
    // Go to investor ledger page with project selected
    window.location.href = '<?= BASE_URL ?>/modules/investor/ledger.php?project_id=' + projectId;
}

function editProject(projectId) {
    // TODO: Implement edit project modal
    alert('Edit project feature coming soon');
}

async function deleteProject(projectId, projectName) {
    if (!confirm(`Are you sure you want to delete the project "${projectName}"?\n\nAll project expense data will be deleted!`)) {
        return;
    }
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-project-delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'project_id=' + encodeURIComponent(projectId)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ ' + (result.message || 'Project deleted successfully'));
            location.reload(); // Refresh page to update project list
        } else {
            alert('❌ Error: ' + result.message);
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

// ====== BILLS FUNCTIONS ======
function openAddBillModal() {
    document.getElementById('addBillForm').reset();
    document.getElementById('addBillModal').classList.add('active');
}

async function saveBill(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/bill-add.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('✅ Bill added successfully');
            closeModal('addBillModal');
            location.reload();
        } else {
            alert('❌ Error: ' + (result.message || 'Failed to save bill'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

async function editBill(billId) {
    try {
        const response = await fetch('<?= BASE_URL ?>/api/bill-get.php?id=' + encodeURIComponent(billId));
        const result = await response.json();
        
        if (result.success && result.bill) {
            const bill = result.bill;
            
            // Populate form fields
            document.getElementById('editBillId').value = bill.id;
            document.getElementById('editBillTitle').value = bill.title || '';
            document.getElementById('editBillNumber').value = bill.bill_number || '';
            document.getElementById('editBillCategory').value = bill.category || 'other';
            document.getElementById('editBillAmount').value = formatCurrency(bill.amount);
            document.getElementById('editBillDueDate').value = bill.due_date || '';
            document.getElementById('editBillDescription').value = bill.description || '';
            document.getElementById('editBillNotes').value = bill.notes || '';
            
            // Open modal
            document.getElementById('editBillModal').classList.add('active');
        } else {
            alert('❌ Failed to load bill data: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

async function updateBill(event) {
    event.preventDefault();
    
    const billId = document.getElementById('editBillId').value;
    if (!billId) {
        alert('❌ Bill ID not found');
        return;
    }
    
    const form = event.target;
    const formData = new FormData(form);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/bill-update.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('✅ Bill updated successfully');
            closeModal('editBillModal');
            location.reload();
        } else {
            alert('❌ Error: ' + (result.message || 'Failed to update bill'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

async function deleteBill(billId, billTitle) {
    if (!confirm(`Are you sure you want to delete the bill "${billTitle}"?\n\nThis action CANNOT be undone.`)) {
        return;
    }
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/bill-delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'id=' + encodeURIComponent(billId)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ ' + (result.message || 'Bill deleted successfully'));
            location.reload();
        } else {
            alert('❌ Error: ' + (result.message || 'Failed to delete bill'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

async function markAsPaid(billId) {
    if (!confirm('Mark this bill as paid?')) {
        return;
    }
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/bill-mark-paid.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'id=' + encodeURIComponent(billId)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ ' + (result.message || 'Bill marked as paid'));
            location.reload();
        } else {
            alert('❌ Error: ' + (result.message || 'Failed to mark as paid'));
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

// Reset Data Functions
function openResetModal() {
    document.getElementById('resetConfirmInput').value = '';
    document.getElementById('resetExecuteBtn').disabled = true;
    document.getElementById('resetDataModal').classList.add('active');
}

// Enable/disable reset button based on confirmation input
if (document.getElementById('resetConfirmInput')) {
    document.getElementById('resetConfirmInput').addEventListener('input', function() {
        const btn = document.getElementById('resetExecuteBtn');
        btn.disabled = this.value.toUpperCase() !== 'RESET';
    });
}

async function executeResetData() {
    const confirmInput = document.getElementById('resetConfirmInput');
    if (confirmInput.value.toUpperCase() !== 'RESET') {
        alert('Please type RESET to confirm');
        return;
    }
    
    const btn = document.getElementById('resetExecuteBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Deleting...';
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-reset.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'confirm=RESET'
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ ' + result.message);
            location.reload();
        } else {
            alert('❌ Error: ' + result.message);
            btn.disabled = false;
            btn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg> Reset All Data';
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg> Reset All Data';
    }
}

// Close modal when clicking outside
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    });
});
</script>

<?php include $base_path . '/includes/footer.php'; ?>



