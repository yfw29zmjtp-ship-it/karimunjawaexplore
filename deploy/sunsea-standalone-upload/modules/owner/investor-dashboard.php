<?php
/**
 * Investor Dashboard
 * Shows investment overview and detailed project expenses
 */
session_start();
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ../../login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'], ['owner', 'admin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

// Connect to Narayana database
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_narayana_hotel') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Get List of Projects for the Selection Menu
    $projects = $pdo->query("SELECT * FROM projects ORDER BY budget DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate grand_expenses for each project
    foreach ($projects as &$proj) {
        $pid = $proj['id'];
        
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
    }
    unset($proj);

    // 2. Handle Selected Project Logic
    $selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
    $selectedProject = null;
    $projectExpenses = [];
    $projectStats = [];
    
    // Also fetch totals for overview if no project selected
    $totalCapital = 0;
    $totalBudget = 0;
    $totalExpenses = 0;

    if ($selectedProjectId) {
        // Get Project Details
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$selectedProjectId]);
        $selectedProject = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($selectedProject) {
            // Get Specific Project Expenses
            // Try to join with categories if table exists, otherwise just fetch expenses
            try {
                $stmtExp = $pdo->prepare("
                    SELECT pe.*, pec.category_name 
                    FROM project_expenses pe 
                    LEFT JOIN project_expense_categories pec ON pe.expense_category_id = pec.id
                    WHERE pe.project_id = ? 
                    ORDER BY pe.expense_date DESC
                ");
                $stmtExp->execute([$selectedProjectId]);
                $projectExpenses = $stmtExp->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Fallback if category table join fails
                $stmtExp = $pdo->prepare("SELECT * FROM project_expenses WHERE project_id = ? ORDER BY expense_date DESC");
                $stmtExp->execute([$selectedProjectId]);
                $projectExpenses = $stmtExp->fetchAll(PDO::FETCH_ASSOC);
            }

            // Calculate Stats for this project (using grand_expenses)
            $totalBudget = $selectedProject['budget'] ?? 0;
            
            // Find the pre-calculated grand_expenses from projects array
            $currentExpenses = 0;
            foreach ($projects as $p) {
                if ($p['id'] == $selectedProjectId) {
                    $currentExpenses = $p['grand_expenses'];
                    break;
                }
            }

            $projectStats = [
                'budget' => $totalBudget,
                'expenses' => $currentExpenses,
                'remaining' => $totalBudget - $currentExpenses,
                'usage_percent' => $totalBudget > 0 ? ($currentExpenses / $totalBudget * 100) : 0
            ];
        }
    } else {
        // Overview Data
        try {
            $totalCapital = $pdo->query("SELECT COALESCE(SUM(total_capital), 0) FROM investors")->fetchColumn();
            $totalBudget = $pdo->query("SELECT COALESCE(SUM(budget), 0) FROM projects")->fetchColumn();
            
            // Calculate total expenses from grand_expenses
            $totalExpenses = 0;
            foreach ($projects as $p) {
                $totalExpenses += $p['grand_expenses'];
            }
        } catch (Exception $e) {
            // Ignore if tables missing
        }
    }
    
} catch (Exception $e) {
    echo "Database Error: " . $e->getMessage();
    exit;
}

function formatMoney($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Project Management - Investor View</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(180deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
            min-height: 100vh;
            color: white;
            padding-bottom: 80px;
        }
        .container { padding: 1rem; max-width: 800px; margin: 0 auto; }
        
        .header {
            padding: 1.5rem 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: rgba(30, 27, 75, 0.5);
            backdrop-filter: blur(5px);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header h1 { font-size: 1.25rem; font-weight: 700; }
        .header p { font-size: 0.8rem; opacity: 0.7; }

        /* Project Selection Cards */
        .project-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }
        .project-card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 1.2rem;
            border: 1px solid rgba(255,255,255,0.1);
            transition: transform 0.2s, background 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: white;
            display: block;
        }
        .project-card:hover { transform: translateY(-2px); background: rgba(255,255,255,0.15); }
        
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .card-title { font-weight: 700; font-size: 1.1rem; }
        .status-badge {
            font-size: 0.65rem; padding: 0.2rem 0.5rem; border-radius: 20px;
            background: rgba(52, 211, 153, 0.2); color: #34d399; text-transform: uppercase;
        }

        /* Detail View */
        .detail-view { margin-top: 1rem; animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .stats-summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.8rem;
            margin-bottom: 1.5rem;
        }
        .stat-box { background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 10px; text-align: center; }
        .stat-box.full { grid-column: span 2; background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .stat-label { font-size: 0.7rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 1.2rem; font-weight: 700; margin-top: 0.3rem; }

        .expenses-list { display: flex; flex-direction: column; gap: 0.8rem; }
        .expense-item {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-left: 3px solid #f87171; 
        }
        .expense-info h4 { font-size: 0.95rem; margin-bottom: 0.3rem; font-weight: 600; }
        .expense-info p { font-size: 0.75rem; opacity: 0.6; }
        .expense-amount { font-weight: 700; font-size: 0.95rem; color: #f87171; white-space: nowrap; margin-left: 1rem; }
        .expense-date { font-size: 0.7rem; opacity: 0.5; display: block; margin-top: 0.3rem; text-align: right;}

        /* Bottom Nav */
        .bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
            display: flex; justify-content: space-around; padding: 0.8rem 0;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.25); z-index: 1000;
        }
        .nav-item {
            display: flex; flex-direction: column; align-items: center;
            gap: 0.2rem; color: rgba(255,255,255,0.6); font-size: 0.65rem;
            text-decoration: none; padding: 0.35rem; border-radius: 8px;
        }
        .nav-item.active { color: white; }
    </style>
</head>
<body>

    <?php if (!$selectedProject): ?>
        <!-- VIEW 1: PROJECT SELECTION (Front Display) -->
        <div class="header">
            <h1> Project Manager</h1>
            <p>Select a project to view expense details</p>
        </div>

        <div class="container">
            <div class="section-title" style="margin-bottom: 1rem; opacity: 0.8; font-weight: 600;">Active Projects</div>
            
            <div class="project-grid">
                <?php foreach ($projects as $proj): ?>
                <a href="?project_id=<?= $proj['id'] ?>" class="project-card">
                    <div class="card-header">
                        <span class="card-title"><?= htmlspecialchars($proj['name']) ?></span>
                        <span class="status-badge"><?= htmlspecialchars($proj['status'] ?? 'Active') ?></span>
                    </div>
                    <?php 
                        $pBudget = $proj['budget'] ?? 0;
                        $pUsed = $proj['grand_expenses'] ?? 0;
                        $pPercent = $pBudget > 0 ? ($pUsed / $pBudget * 100) : 0;
                    ?>
                    <div style="font-size: 0.8rem; margin-bottom: 0.5rem; display: flex; justify-content: space-between; opacity: 0.8;">
                        <span>Budget Consumed</span>
                        <span><?= number_format($pPercent, 1) ?>%</span>
                    </div>
                    <div style="background: rgba(255,255,255,0.1); height: 6px; border-radius: 3px; overflow: hidden;">
                        <div style="background: #6366f1; width: <?= min($pPercent, 100) ?>%; height: 100%;"></div>
                    </div>
                    <div style="margin-top: 0.8rem; font-size: 0.9rem; font-weight: 500; display: flex; justify-content: space-between;">
                       <span>Used: <?= formatMoney($pUsed) ?></span>
                       <span style="opacity: 0.7;">Budget: <?= formatMoney($pBudget) ?></span>
                    </div>
                </a>
                <?php endforeach; ?>

                <?php if (empty($projects)): ?>
                <div style="text-align: center; padding: 3rem; opacity: 0.5;">
                    <i data-feather="folder" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
                    <p>No projects available.</p>
                </div>
                <?php endif; ?>
            </div>
            
             <div class="section-title" style="margin: 2rem 0 1rem; opacity: 0.8; font-weight: 600;">Overview</div>
             <div class="stats-summary" style="margin-bottom: 4rem;">
                <div class="stat-box">
                    <div class="stat-label">Total Capital</div>
                    <div class="stat-value"><?= formatMoney($totalCapital) ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Budget</div>
                    <div class="stat-value"><?= formatMoney($totalBudget) ?></div>
                </div>
             </div>
        </div>

    <?php else: ?>
        <!-- VIEW 2: PROJECT DETAILS (Expense Log) -->
        <div class="header">
            <div style="display: flex; align-items: center; justify-content: center; position: relative;">
                <a href="investor-dashboard.php" style="position: absolute; left: 0; color: white; padding: 0.5rem;"><i data-feather="arrow-left"></i></a>
                <div>
                    <h1><?= htmlspecialchars($selectedProject['name']) ?></h1>
                    <p>Project Expense Details</p>
                </div>
            </div>
        </div>

        <div class="container detail-view">
            
            <!-- Financial Overview -->
            <div class="stats-summary">
                <div class="stat-box full">
                    <div class="stat-label">Remaining Budget</div>
                    <div class="stat-value"><?= formatMoney($projectStats['remaining']) ?></div>
                    <div style="font-size: 0.7rem; opacity: 0.7; margin-top: 5px;">
                        of Budget <?= formatMoney($projectStats['budget']) ?>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Spent</div>
                    <div class="stat-value" style="color: #f87171;"><?= formatMoney($projectStats['expenses']) ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Progress</div>
                    <div class="stat-value" style="color: #34d399;"><?= number_format($projectStats['usage_percent'], 1) ?>%</div>
                </div>
            </div>
            
            <!-- Expense List -->
            <h3 style="margin-bottom: 1rem; font-size: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="list" style="width: 16px;"></i> Expense History
            </h3>

            <div class="expenses-list">
                <?php foreach ($projectExpenses as $exp): ?>
                <div class="expense-item">
                    <div class="expense-info">
                        <h4><?= htmlspecialchars($exp['description'] ?: 'Expense') ?></h4>
                        <?php if (!empty($exp['category_name'])): ?>
                        <span style="font-size: 0.6rem; background: rgba(255,255,255,0.2); padding: 2px 6px; border-radius: 4px;">
                            <?= htmlspecialchars($exp['category_name']) ?>
                        </span>
                        <?php endif; ?>
                        <p><?= htmlspecialchars($exp['reference_no'] ?? '-') ?></p>
                    </div>
                    <div style="text-align: right;">
                        <div class="expense-amount">- <?= formatMoney($exp['amount_idr'] ?? $exp['amount'] ?? 0) ?></div>
                        <span class="expense-date"><?= date('d M Y', strtotime($exp['expense_date'])) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($projectExpenses)): ?>
                <div style="text-align: center; padding: 2rem; background: rgba(255,255,255,0.05); border-radius: 12px;">
                    <p style="opacity: 0.6;">No expense data available for this project.</p>
                </div>
                <?php endif; ?>
            </div>

        </div>
    <?php endif; ?>

    <!-- Navigation -->
    <div class="bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i data-feather="home"></i>
            <span>Home</span>
        </a>
        <a href="investor-dashboard.php" class="nav-item active">
            <i data-feather="briefcase"></i>
            <span>Projects</span>
        </a>
        <a href="../../logout.php" class="nav-item">
            <i data-feather="log-out"></i>
            <span>Logout</span>
        </a>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>
