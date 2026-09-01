<?php
/**
 * INVESTOR LEDGER - Buku Kas + Gaji Pekerja + Pengeluaran Divisi
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

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $base_url = $protocol . $_SERVER['HTTP_HOST'];
} else {
    $base_url = BASE_URL;
}

$db = Database::getInstance()->getConnection();

// ====== AUTO-CREATE TABLES ======
$tables_sql = [
    "CREATE TABLE IF NOT EXISTS project_workers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        role VARCHAR(100) DEFAULT 'Tukang',
        daily_rate DECIMAL(15,2) DEFAULT 0,
        phone VARCHAR(20),
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS project_salaries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        worker_id INT NOT NULL,
        period_type ENUM('weekly','monthly') DEFAULT 'weekly',
        period_label VARCHAR(50),
        daily_rate DECIMAL(15,2) DEFAULT 0,
        overtime_per_day DECIMAL(15,2) DEFAULT 0,
        other_per_day DECIMAL(15,2) DEFAULT 0,
        total_days INT DEFAULT 0,
        total_salary DECIMAL(15,2) DEFAULT 0,
        status ENUM('draft','submitted','approved','paid') DEFAULT 'draft',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT
    )",
    "CREATE TABLE IF NOT EXISTS project_division_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        division_name VARCHAR(100) NOT NULL,
        contractor_name VARCHAR(100),
        description TEXT,
        amount DECIMAL(15,2) NOT NULL,
        expense_date DATE,
        receipt_file VARCHAR(255),
        status ENUM('pending','approved','paid') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT
    )",
    "CREATE TABLE IF NOT EXISTS project_contractors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        bidang VARCHAR(100) DEFAULT '',
        pic_name VARCHAR(100) DEFAULT '',
        phone VARCHAR(20) DEFAULT '',
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];
foreach ($tables_sql as $sql) {
    try { $db->exec($sql); } catch (Exception $e) { /* table exists */ }
}

// Add division_name column to project_expenses if not exists
try {
    $cols = array_column($db->query("DESCRIBE project_expenses")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('division_name', $cols)) {
        $db->exec("ALTER TABLE project_expenses ADD COLUMN division_name VARCHAR(100) DEFAULT NULL AFTER description");
    }
} catch (Exception $e) { /* table may not exist yet */ }

// ====== HELPER: Detect columns ======
function getTableColumns($db, $table) {
    try {
        $stmt = $db->query("DESCRIBE `$table`");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Exception $e) {
        return [];
    }
}

function buildProjectNameCol($columns) {
    $col = 'COALESCE(';
    if (in_array('project_name', $columns)) $col .= 'project_name, ';
    if (in_array('name', $columns)) $col .= 'name, ';
    return $col . "'Project') as project_name";
}

function buildBudgetCol($columns) {
    $col = 'COALESCE(';
    if (in_array('budget_idr', $columns)) $col .= 'budget_idr, ';
    if (in_array('budget', $columns)) $col .= 'budget, ';
    return $col . '0) as budget_idr';
}

// ====== LOAD DATA ======
$project_id = intval($_GET['project_id'] ?? 0);
$tab = $_GET['tab'] ?? 'expenses';
$project = null;
$expenses = [];
$workers = [];
$salaries = [];
$division_expenses = [];
$contractors = [];
$division_list = [];
$laporan_gaji = [];
$laporan_jasa = [];
$projCols = getTableColumns($db, 'projects');

// Get selected project
if ($project_id) {
    try {
        $name_col = buildProjectNameCol($projCols);
        $budget_col = buildBudgetCol($projCols);
        $stmt = $db->prepare("SELECT id, $name_col, $budget_col, created_at FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($project) {
            // Expenses
            try {
                $expCols = getTableColumns($db, 'project_expenses');
                $sel = ['id','project_id','amount'];
                if (in_array('description', $expCols)) $sel[] = 'description';
                if (in_array('division_name', $expCols)) $sel[] = 'division_name';
                if (in_array('expense_date', $expCols)) $sel[] = 'expense_date';
                if (in_array('created_at', $expCols)) $sel[] = 'created_at';
                $stmt = $db->prepare("SELECT " . implode(',', $sel) . " FROM project_expenses WHERE project_id = ? ORDER BY id DESC");
                $stmt->execute([$project_id]);
                $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $expenses = []; }

            // ── Expense filters ──────────────────────────────────────────
            $expFilterDate   = trim($_GET['exp_date'] ?? '');
            $expFilterMonth  = trim($_GET['exp_month'] ?? '');
            $expFilterDiv    = trim($_GET['exp_div'] ?? '');
            $expFilterSearch = trim($_GET['exp_search'] ?? '');

            // Smart: if date within selected month, use month
            if (!empty($expFilterDate) && !empty($expFilterMonth)) {
                if (substr($expFilterDate, 0, 7) === $expFilterMonth) $expFilterDate = '';
            }

            // Collect unique divisions from expenses for filter dropdown
            $expDivisions = [];
            foreach ($expenses as $ex) {
                if (!empty($ex['division_name']) && !in_array($ex['division_name'], $expDivisions)) {
                    $expDivisions[] = $ex['division_name'];
                }
            }
            sort($expDivisions);

            // Apply filters
            // When search is used: search is PRIMARY - date/month/division are optional refinements
            // When search is empty: date/month/division work as strict AND filters
            $filteredExpenses = array_filter($expenses, function($e) use ($expFilterDate, $expFilterMonth, $expFilterDiv, $expFilterSearch) {
                $d = $e['expense_date'] ?? ($e['created_at'] ?? '');
                $desc = mb_strtolower($e['description'] ?? '');
                $div  = mb_strtolower($e['division_name'] ?? '');

                if (!empty($expFilterSearch)) {
                    // Search mode: keyword must match description or division
                    $needle = mb_strtolower($expFilterSearch);
                    if (strpos($desc . ' ' . $div, $needle) === false) return false;
                    // If month is also set, still respect it (broad time scope)
                    if (!empty($expFilterMonth) && substr($d, 0, 7) !== $expFilterMonth) return false;
                    // If date is also set, respect it
                    if (!empty($expFilterDate) && substr($d, 0, 10) !== $expFilterDate) return false;
                    // Division filter is IGNORED when search is active (search already covers it)
                    return true;
                }

                // No search: strict AND filters
                if (!empty($expFilterDate) && substr($d, 0, 10) !== $expFilterDate) return false;
                if (!empty($expFilterMonth) && substr($d, 0, 7) !== $expFilterMonth) return false;
                if (!empty($expFilterDiv) && mb_strtolower($e['division_name'] ?? '') !== mb_strtolower($expFilterDiv)) return false;
                return true;
            });
            $filteredExpenses = array_values($filteredExpenses);
            $filteredTotal = array_sum(array_column($filteredExpenses, 'amount'));
            $hasExpFilter = !empty($expFilterDate) || !empty($expFilterMonth) || !empty($expFilterDiv) || !empty($expFilterSearch);

            $total_expenses = array_sum(array_column($expenses, 'amount'));
            $project['total_expenses'] = $total_expenses;

            // Workers
            try {
                $stmt = $db->prepare("SELECT * FROM project_workers WHERE project_id = ? ORDER BY name");
                $stmt->execute([$project_id]);
                $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $workers = []; }

            // Salaries
            try {
                $stmt = $db->prepare("
                    SELECT s.*, w.name as worker_name, w.role as worker_role
                    FROM project_salaries s
                    LEFT JOIN project_workers w ON s.worker_id = w.id
                    WHERE s.project_id = ?
                    ORDER BY s.created_at DESC
                ");
                $stmt->execute([$project_id]);
                $salaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $salaries = []; }

            // Division Expenses
            try {
                $stmt = $db->prepare("SELECT * FROM project_division_expenses WHERE project_id = ? ORDER BY created_at DESC");
                $stmt->execute([$project_id]);
                $division_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $division_expenses = []; }

            // Load contractors
            try {
                $stmt = $db->prepare("SELECT * FROM project_contractors WHERE project_id = ? ORDER BY name");
                $stmt->execute([$project_id]);
                $contractors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $contractors = []; }

            // Build division_list from contractors for dropdown
            $division_list = array_map(function($c) { return $c['name']; }, $contractors);

            // Build Laporan Gaji - group salaries by worker
            foreach ($salaries as $s) {
                $wname = $s['worker_name'] ?? 'Unknown';
                $wrole = $s['worker_role'] ?? '';
                $key = $wname;
                if (!isset($laporan_gaji[$key])) $laporan_gaji[$key] = ['role' => $wrole, 'items' => [], 'total' => 0];
                $laporan_gaji[$key]['items'][] = [
                    'period' => $s['period_label'] ?: ($s['period_type']=='weekly'?'Mingguan':'Bulanan'),
                    'daily_rate' => $s['daily_rate'] ?? 0,
                    'overtime' => $s['overtime_per_day'] ?? 0,
                    'other' => $s['other_per_day'] ?? 0,
                    'days' => $s['total_days'] ?? 0,
                    'total' => $s['total_salary'] ?? 0,
                    'status' => $s['status'] ?? 'draft',
                ];
                $laporan_gaji[$key]['total'] += ($s['total_salary'] ?? 0);
            }
            uasort($laporan_gaji, function($a, $b) { return $b['total'] - $a['total']; });

            // Build Laporan Jasa - group expenses by division/kontraktor
            foreach ($expenses as $ex) {
                if (empty($ex['division_name'])) continue;
                $div = $ex['division_name'];
                if (!isset($laporan_jasa[$div])) $laporan_jasa[$div] = ['items' => [], 'total' => 0];
                $laporan_jasa[$div]['items'][] = [
                    'source' => 'Pengeluaran',
                    'description' => $ex['description'] ?? '-',
                    'amount' => $ex['amount'] ?? 0,
                    'date' => $ex['expense_date'] ?? $ex['created_at'] ?? '-',
                ];
                $laporan_jasa[$div]['total'] += ($ex['amount'] ?? 0);
            }
            foreach ($division_expenses as $dx) {
                $div = $dx['division_name'] ?? 'Lainnya';
                if (!isset($laporan_jasa[$div])) $laporan_jasa[$div] = ['items' => [], 'total' => 0];
                $laporan_jasa[$div]['items'][] = [
                    'source' => 'Divisi/Kontraktor',
                    'description' => $dx['description'] ?? '-',
                    'amount' => $dx['amount'] ?? 0,
                    'date' => $dx['expense_date'] ?? '-',
                ];
                $laporan_jasa[$div]['total'] += ($dx['amount'] ?? 0);
            }
            uasort($laporan_jasa, function($a, $b) { return $b['total'] - $a['total']; });
        }
    } catch (Exception $e) {
        error_log('Ledger error: ' . $e->getMessage());
    }
}

// Get all projects
try {
    $name_col = buildProjectNameCol($projCols);
    $budget_col = buildBudgetCol($projCols);
    $stmt = $db->query("SELECT id, $name_col, $budget_col FROM projects ORDER BY id DESC LIMIT 100");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $projects = []; }

$total_gaji = array_sum(array_column($salaries, 'total_salary'));
$total_divisi = array_sum(array_column($division_expenses, 'amount'));
$grand_total_gaji = array_sum(array_map(function($d) { return $d['total']; }, $laporan_gaji));
$grand_total_jasa = array_sum(array_map(function($d) { return $d['total']; }, $laporan_jasa));

$pageTitle = 'Buku Kas - Investor';
include $base_path . '/includes/header.php';
?>

<style>
* { box-sizing: border-box; }
.lp { padding: 1.5rem 2rem; max-width: 1400px; margin: 0 auto; }

/* ── Top Bar ── */
.top-bar { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
.top-bar .back-link { display: inline-flex; align-items: center; gap: .4rem; color: var(--text-muted,#888); font-size: .92rem; font-weight: 600; text-decoration: none; padding: .5rem 1rem; border-radius: 6px; border: 1px solid var(--border-color,#e5e7eb); transition: all .2s; background: var(--bg-secondary,#fff); }
.top-bar .back-link:hover { border-color: #6366f1; color: #6366f1; }
.proj-select { position: relative; }
.proj-select select { appearance: none; -webkit-appearance: none; padding: .55rem 2.2rem .55rem 1rem; border: 1.5px solid var(--border-color,#e5e7eb); border-radius: 8px; background: var(--bg-secondary,#fff); color: var(--text-primary,#111); font-size: .95rem; font-weight: 600; cursor: pointer; min-width: 240px; transition: all .2s; }
.proj-select select:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.proj-select::after { content: '▾'; position: absolute; right: .8rem; top: 50%; transform: translateY(-50%); font-size: .82rem; color: var(--text-muted,#888); pointer-events: none; }
.top-bar .proj-badge { font-size: .82rem; padding: .25rem .65rem; border-radius: 20px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; font-weight: 700; letter-spacing: .3px; }

/* ── Tabs ── */
.nav-tabs { display: flex; gap: .4rem; margin-bottom: 1.2rem; padding: .35rem; background: var(--bg-secondary,#f8f9fa); border-radius: 10px; border: 1px solid var(--border-color,#e5e7eb); }
.nav-tab { flex: 1; padding: .6rem .5rem; text-align: center; font-size: .88rem; font-weight: 600; color: var(--text-muted,#888); border-radius: 7px; text-decoration: none; transition: all .2s; white-space: nowrap; }
.nav-tab:hover { color: #6366f1; background: rgba(99,102,241,.06); }
.nav-tab.active { background: #fff; color: #6366f1; box-shadow: 0 1px 4px rgba(0,0,0,.08); }

/* ── Summary Strip ── */
.summary-strip { display: grid; grid-template-columns: repeat(4,1fr); gap: .75rem; margin-bottom: 1.2rem; }
.sc { padding: .8rem 1rem; border-radius: 8px; background: var(--bg-secondary,#fff); border: 1px solid var(--border-color,#e5e7eb); position: relative; overflow: hidden; }
.sc::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3.5px; }
.sc.c-blue::before { background: linear-gradient(180deg,#3b82f6,#6366f1); }
.sc.c-amber::before { background: linear-gradient(180deg,#f59e0b,#ef4444); }
.sc.c-red::before { background: linear-gradient(180deg,#ef4444,#dc2626); }
.sc.c-green::before { background: linear-gradient(180deg,#10b981,#059669); }
.sc .sc-label { font-size: .75rem; text-transform: uppercase; font-weight: 700; letter-spacing: .5px; color: var(--text-muted,#999); margin-bottom: .2rem; }
.sc .sc-val { font-size: 1.15rem; font-weight: 800; color: var(--text-primary,#111); }

/* ── Card / Panel ── */
.panel { background: var(--bg-secondary,#fff); border: 1px solid var(--border-color,#e5e7eb); border-radius: 10px; padding: 1.2rem 1.5rem; margin-bottom: 1rem; }
.panel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .85rem; }
.panel-head h3 { font-size: 1.05rem; font-weight: 700; color: var(--text-primary,#111); margin: 0; display: flex; align-items: center; gap: .4rem; }
.panel-head .sub { font-size: .82rem; color: var(--text-muted,#888); font-weight: 500; }

/* ── Forms ── */
.inline-form { display: flex; gap: .6rem; flex-wrap: wrap; align-items: flex-end; }
.fg { display: flex; flex-direction: column; flex: 1; min-width: 130px; }
.fg.w2 { flex: 2; min-width: 200px; }
.fg.w-auto { flex: 0 0 auto; min-width: auto; }
.fg label { font-size: .8rem; font-weight: 600; margin-bottom: .25rem; color: var(--text-muted,#888); text-transform: uppercase; letter-spacing: .3px; }
.fg input, .fg select { padding: .5rem .75rem; border: 1.5px solid var(--border-color,#e5e7eb); border-radius: 6px; background: var(--bg-primary,#fff); color: var(--text-primary,#111); font-size: .95rem; transition: border .2s; }
.fg input:focus, .fg select:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,.1); }
.fg .total-display { background: linear-gradient(135deg,#f0fdf4,#dcfce7) !important; font-weight: 800; color: #059669 !important; font-size: 1.05rem; border: 1.5px solid #86efac !important; }

/* ── Buttons ── */
.btn { padding: .5rem 1rem; border-radius: 6px; font-size: .88rem; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: .35rem; transition: all .15s; text-decoration: none; white-space: nowrap; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,.12); }
.btn-emerald { background: linear-gradient(135deg,#10b981,#059669); color: #fff; }
.btn-indigo { background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff; }
.btn-sky { background: linear-gradient(135deg,#0ea5e9,#0284c7); color: #fff; }
.btn-amber { background: linear-gradient(135deg,#f59e0b,#d97706); color: #fff; }
.btn-rose { background: linear-gradient(135deg,#f43f5e,#e11d48); color: #fff; }
.btn-ghost { background: transparent; border: 1px solid var(--border-color,#e5e7eb); color: var(--text-muted,#888); }
.btn-ghost:hover { border-color: #6366f1; color: #6366f1; }
.btn-xs { padding: .35rem .65rem; font-size: .8rem; border-radius: 4px; }
.action-row { display: flex; gap: .5rem; flex-wrap: wrap; }

/* ── Table ── */
.tbl { width: 100%; border-collapse: separate; border-spacing: 0; }
.tbl th { padding: .6rem .75rem; font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted,#999); background: rgba(99,102,241,.03); border-bottom: 1.5px solid var(--border-color,#e5e7eb); text-align: left; }
.tbl td { padding: .6rem .75rem; font-size: .92rem; color: var(--text-primary,#111); border-bottom: 1px solid var(--border-color,#f0f0f0); vertical-align: middle; }
.tbl tbody tr { transition: background .15s; }
.tbl tbody tr:hover { background: rgba(99,102,241,.02); }
.tbl .money { font-weight: 700; color: #d97706; font-variant-numeric: tabular-nums; }
.tbl .foot td { background: rgba(99,102,241,.04); font-weight: 700; border-top: 2px solid #6366f1; }
.tbl .empty td { text-align: center; padding: 1.5rem; color: var(--text-muted,#999); font-size: .92rem; }

/* ── Badge ── */
.badge { display: inline-block; padding: .2rem .55rem; border-radius: 20px; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.badge-draft { background: #fef3c7; color: #92400e; }
.badge-submitted { background: #dbeafe; color: #1e40af; }
.badge-approved { background: #d1fae5; color: #065f46; }
.badge-paid { background: #e0e7ff; color: #3730a3; }
.badge-pending { background: #fef3c7; color: #92400e; }
.badge-active { background: #d1fae5; color: #065f46; }
.badge-inactive { background: #fee2e2; color: #991b1b; }

.empty-state { text-align: center; padding: 2rem; color: var(--text-muted,#999); font-size: .95rem; }
.hint { font-size: .82rem; color: var(--text-muted,#888); font-weight: 500; }
.hint a { color: #6366f1; text-decoration: none; font-weight: 600; }

/* ── Laporan Cards ── */
.laporan-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .75rem; margin-bottom: 1.2rem; }
.lap-card { padding: .85rem 1rem; border-radius: 8px; background: var(--bg-secondary,#fff); border: 1px solid var(--border-color,#e5e7eb); position: relative; overflow: hidden; cursor: pointer; transition: all .2s; }
.lap-card:hover { border-color: #6366f1; box-shadow: 0 2px 8px rgba(99,102,241,.1); }
.lap-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3.5px; background: linear-gradient(180deg,#6366f1,#8b5cf6); }
.lap-card .lap-name { font-size: .88rem; font-weight: 700; color: var(--text-primary,#111); margin-bottom: .2rem; }
.lap-card .lap-total { font-size: 1.1rem; font-weight: 800; color: #d97706; }
.lap-card .lap-count { font-size: .75rem; color: var(--text-muted,#888); }
.lap-detail { margin-bottom: 1rem; }

@media print {
    .top-bar, .nav-tabs, .no-print, .btn, .action-row { display: none !important; }
    .panel { border: 1px solid #ddd; box-shadow: none; }
    .tbl th, .tbl td { padding: .4rem .5rem; font-size: .8rem; }
}
@media (max-width: 768px) {
    .summary-strip { grid-template-columns: repeat(2,1fr); }
    .laporan-grid { grid-template-columns: 1fr; }
    .inline-form { flex-direction: column; }
    .fg { min-width: 100% !important; }
}
</style>

<div class="lp">
    <!-- TOP BAR -->
    <div class="top-bar no-print">
        <a href="<?= BASE_URL ?>/modules/investor/" class="back-link">← Kembali</a>
        <div class="proj-select">
            <select onchange="if(this.value) location.href='?project_id='+this.value+'&tab=<?= $tab ?>'">
                <option value="">— Pilih Projek —</option>
                <?php foreach ($projects as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $project_id == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['project_name']) ?> — Rp <?= number_format($p['budget_idr']??0,0,',','.') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($project): ?>
        <span class="proj-badge">BUKU KAS</span>
        <?php endif; ?>
    </div>

    <?php if (empty($projects)): ?>
        <div class="empty-state"><p>Belum ada projek. <a href="<?= BASE_URL ?>/modules/investor/" style="color:#6366f1">Buat projek dulu →</a></p></div>
    <?php elseif (!$project): ?>
        <div class="empty-state">Pilih projek dari dropdown di atas untuk melihat buku kas</div>
    <?php else: ?>

        <!-- TABS -->
        <div class="nav-tabs no-print">
            <a class="nav-tab <?= $tab=='expenses'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=expenses">💸 Pengeluaran</a>
            <a class="nav-tab <?= $tab=='workers'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=workers">👷 Pekerja</a>
            <a class="nav-tab <?= $tab=='salary'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=salary">💰 Gaji</a>
            <a class="nav-tab <?= $tab=='division'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=division">🏗️ Divisi</a>
            <a class="nav-tab <?= $tab=='laporan'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=laporan">📊 Laporan</a>
        </div>

        <!-- SUMMARY -->
        <div class="summary-strip">
            <div class="sc c-blue"><div class="sc-label">Budget</div><div class="sc-val">Rp <?= number_format($project['budget_idr']??0,0,',','.') ?></div></div>
            <div class="sc c-amber"><div class="sc-label">Pengeluaran</div><div class="sc-val">Rp <?= number_format($project['total_expenses']??0,0,',','.') ?></div></div>
            <div class="sc c-red"><div class="sc-label">Gaji + Divisi</div><div class="sc-val">Rp <?= number_format($total_gaji+$total_divisi,0,',','.') ?></div></div>
            <div class="sc c-green"><div class="sc-label">Sisa</div><div class="sc-val">Rp <?= number_format(($project['budget_idr']??0)-($project['total_expenses']??0)-$total_gaji-$total_divisi,0,',','.') ?></div></div>
        </div>

                <!-- ═══ TAB: PENGELUARAN ═══ -->
                <?php if ($tab == 'expenses'): ?>
                <div class="panel no-print">
                    <div class="panel-head"><h3>📝 Catat Pengeluaran</h3></div>
                    <form id="expenseForm" onsubmit="saveExpense(event)">
                        <div class="inline-form">
                            <div class="fg w2"><label>Deskripsi</label><input type="text" name="description" required placeholder="Nama item pengeluaran"></div>
                            <div class="fg"><label>Divisi / Kontraktor</label>
                                <input type="text" name="division_name" list="divisionList" placeholder="Pilih atau ketik divisi">
                                <datalist id="divisionList">
                                    <?php foreach ($division_list as $dn): ?>
                                    <option value="<?= htmlspecialchars($dn) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="fg"><label>Jumlah (Rp)</label><input type="number" name="amount" required min="1" placeholder="0"></div>
                            <div class="fg"><label>Tanggal</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
                            <div class="fg w-auto"><label>&nbsp;</label><button type="submit" class="btn btn-emerald">+ Catat</button></div>
                        </div>
                    </form>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Riwayat Pengeluaran <?php if ($hasExpFilter): ?><span style="font-size:0.75rem;color:#6366f1;font-weight:400">(Filtered: <?= count($filteredExpenses) ?> dari <?= count($expenses) ?> data)</span><?php endif; ?></h3>
                        <div class="action-row no-print"><button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button></div>
                    </div>

                    <!-- Filter Bar -->
                    <form method="GET" action="" class="no-print" style="display:grid;grid-template-columns:repeat(5,1fr);gap:0.6rem;margin-bottom:1rem;padding:0.85rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                        <input type="hidden" name="project_id" value="<?= $project_id ?>">
                        <input type="hidden" name="tab" value="expenses">
                        <div>
                            <label style="font-size:0.7rem;font-weight:600;color:#64748b;display:block;margin-bottom:0.2rem">📅 Tanggal <span style="font-weight:400;color:#94a3b8">(opsional)</span></label>
                            <input type="date" id="expFilterDate" name="exp_date" value="<?= htmlspecialchars($expFilterDate) ?>" style="width:100%;height:34px;border:1px solid #e2e8f0;border-radius:6px;padding:0 0.5rem;font-size:0.82rem;">
                        </div>
                        <div>
                            <label style="font-size:0.7rem;font-weight:600;color:#64748b;display:block;margin-bottom:0.2rem">📆 Bulan <span style="font-weight:400;color:#94a3b8">(opsional)</span></label>
                            <input type="month" name="exp_month" value="<?= htmlspecialchars($expFilterMonth) ?>" style="width:100%;height:34px;border:1px solid #e2e8f0;border-radius:6px;padding:0 0.5rem;font-size:0.82rem;">
                        </div>
                        <div>
                            <label style="font-size:0.7rem;font-weight:600;color:#64748b;display:block;margin-bottom:0.2rem">🏗️ Divisi <span style="font-weight:400;color:#94a3b8">(opsional)</span></label>
                            <select id="expFilterDiv" name="exp_div" style="width:100%;height:34px;border:1px solid #e2e8f0;border-radius:6px;padding:0 0.5rem;font-size:0.82rem;background:#fff;">
                                <option value="">Semua Divisi</option>
                                <?php foreach ($expDivisions as $ed): ?>
                                <option value="<?= htmlspecialchars($ed) ?>" <?= $expFilterDiv === $ed ? 'selected' : '' ?>><?= htmlspecialchars($ed) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.7rem;font-weight:600;color:#6366f1;display:block;margin-bottom:0.2rem">🔍 Cari Nama/Ket</label>
                            <input type="text" id="expFilterSearch" name="exp_search" value="<?= htmlspecialchars($expFilterSearch) ?>" placeholder="Ketik nama: Pak Ipin, semen..." style="width:100%;height:34px;border:1px solid #e2e8f0;border-radius:6px;padding:0 0.5rem;font-size:0.82rem;" oninput="if(this.value.length>0){document.getElementById('expFilterDate').value='';document.getElementById('expFilterDiv').value='';}">
                        </div>
                        <div style="display:flex;align-items:flex-end;gap:0.4rem">
                            <button type="submit" style="flex:1;height:34px;background:#6366f1;color:#fff;border:none;border-radius:6px;font-size:0.8rem;font-weight:600;cursor:pointer">🔍 Filter</button>
                            <a href="?project_id=<?= $project_id ?>&tab=expenses" style="height:34px;display:flex;align-items:center;padding:0 0.7rem;background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;border-radius:6px;font-size:0.78rem;text-decoration:none;font-weight:600">✕</a>
                        </div>
                    </form>

                    <?php if ($hasExpFilter): ?>
                    <div style="margin-bottom:0.75rem;padding:0.5rem 0.75rem;background:linear-gradient(135deg,rgba(99,102,241,0.06),rgba(99,102,241,0.02));border:1px solid rgba(99,102,241,0.15);border-radius:6px;font-size:0.78rem;color:#4338ca;">
                        📊 Menampilkan <strong><?= count($filteredExpenses) ?></strong> dari <?= count($expenses) ?> transaksi
                        | Total: <strong>Rp <?= number_format($filteredTotal,0,',','.') ?></strong>
                        <?php if (!empty($expFilterSearch)): ?> | Pencarian: "<em><?= htmlspecialchars($expFilterSearch) ?></em>"<?php endif; ?>
                        <?php if (!empty($expFilterSearch) && (empty($expFilterDate) && empty($expFilterDiv))): ?>
                        <span style="color:#64748b"> — 💡 Pencarian mencari di semua tanggal & divisi</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <table class="tbl">
                        <thead><tr><th>#</th><th>Tanggal</th><th>Deskripsi</th><th>Divisi</th><th style="text-align:right">Jumlah</th><th class="no-print" style="width:50px">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($filteredExpenses)): ?>
                            <tr class="empty"><td colspan="6"><?= $hasExpFilter ? 'Tidak ada data yang cocok dengan filter' : 'Belum ada data pengeluaran' ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($filteredExpenses as $i => $e): ?>
                            <tr>
                                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                                <td><?= date('d/m/Y', strtotime($e['expense_date'] ?? $e['created_at'] ?? 'now')) ?></td>
                                <td><?= htmlspecialchars($e['description'] ?? '-') ?></td>
                                <td><?php if (!empty($e['division_name'])): ?><span class="badge badge-submitted"><?= htmlspecialchars($e['division_name']) ?></span><?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?></td>
                                <td class="money" style="text-align:right">Rp <?= number_format($e['amount']??0,0,',','.') ?></td>
                                <td class="no-print"><button class="btn btn-rose btn-xs" onclick="deleteExpense(<?= $e['id'] ?>)">✕</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="foot"><td colspan="4">TOTAL<?= $hasExpFilter ? ' (FILTERED)' : '' ?></td><td class="money" style="text-align:right">Rp <?= number_format($hasExpFilter ? $filteredTotal : $project['total_expenses'],0,',','.') ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ═══ TAB: DATA PEKERJA ═══ -->
                <?php elseif ($tab == 'workers'): ?>
                <div class="panel no-print">
                    <div class="panel-head"><h3>👷 Tambah Pekerja</h3></div>
                    <form id="workerForm" onsubmit="saveWorker(event)">
                        <div class="inline-form">
                            <div class="fg w2"><label>Nama</label><input type="text" name="name" required placeholder="Nama lengkap"></div>
                            <div class="fg"><label>Jabatan</label>
                                <select name="role">
                                    <option>Tukang</option><option>Kepala Tukang</option><option>Kuli</option><option>Mandor</option>
                                    <option>Tukang Listrik</option><option>Tukang Cat</option><option>Tukang Las</option><option>Helper</option>
                                </select>
                            </div>
                            <div class="fg"><label>Upah/Hari</label><input type="number" name="daily_rate" placeholder="150000" min="0"></div>
                            <div class="fg"><label>HP</label><input type="text" name="phone" placeholder="08xx"></div>
                            <div class="fg w-auto"><label>&nbsp;</label><button type="submit" class="btn btn-emerald">+ Tambah</button></div>
                        </div>
                    </form>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Daftar Pekerja <span class="badge badge-active" style="margin-left:.4rem"><?= count($workers) ?> orang</span></h3>
                        <div class="action-row no-print"><button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button></div>
                    </div>
                    <table class="tbl">
                        <thead><tr><th>#</th><th>Nama</th><th>Jabatan</th><th style="text-align:right">Upah/Hari</th><th>HP</th><th>Status</th><th class="no-print" style="width:50px">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($workers)): ?>
                            <tr class="empty"><td colspan="7">Belum ada data pekerja</td></tr>
                        <?php else: ?>
                            <?php foreach ($workers as $i => $w): ?>
                            <tr>
                                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                                <td><strong><?= htmlspecialchars($w['name']) ?></strong></td>
                                <td><?= htmlspecialchars($w['role'] ?? 'Tukang') ?></td>
                                <td class="money" style="text-align:right">Rp <?= number_format($w['daily_rate']??0,0,',','.') ?></td>
                                <td style="color:var(--text-muted)"><?= htmlspecialchars($w['phone'] ?? '-') ?></td>
                                <td><span class="badge badge-<?= $w['status']??'active' ?>"><?= $w['status']??'active' ?></span></td>
                                <td class="no-print"><button class="btn btn-rose btn-xs" onclick="deleteWorker(<?= $w['id'] ?>)">✕</button></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ═══ TAB: GAJI TUKANG ═══ -->
                <?php elseif ($tab == 'salary'): ?>
                <div class="panel no-print">
                    <div class="panel-head">
                        <h3>💰 Hitung Gaji</h3>
                        <span class="hint">( Upah + Lembur + Lain² ) × Hari = <strong>Total</strong></span>
                    </div>
                    <?php if (empty($workers)): ?>
                        <div class="empty-state">⚠️ Tambahkan pekerja dulu di tab <a class="hint" href="?project_id=<?= $project_id ?>&tab=workers">Pekerja</a></div>
                    <?php else: ?>
                    <form id="salaryForm" onsubmit="saveSalary(event)">
                        <div class="inline-form" style="margin-bottom:.5rem">
                            <div class="fg w2"><label>Pekerja</label>
                                <select name="worker_id" id="workerSelect" required onchange="fillRate(this)">
                                    <option value="">— Pilih —</option>
                                    <?php foreach ($workers as $w): ?>
                                    <option value="<?= $w['id'] ?>" data-rate="<?= $w['daily_rate']??0 ?>"><?= htmlspecialchars($w['name']) ?> (<?= $w['role'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fg"><label>Upah/Hari</label><input type="number" name="daily_rate" id="dailyRate" required min="0" placeholder="150000"></div>
                            <div class="fg"><label>Lembur</label><input type="number" name="overtime_per_day" value="0" min="0"></div>
                            <div class="fg"><label>Lain²</label><input type="number" name="other_per_day" value="0" min="0"></div>
                            <div class="fg"><label>Hari</label><input type="number" name="total_days" required min="1" placeholder="7"></div>
                        </div>
                        <div class="inline-form">
                            <div class="fg"><label>Periode</label>
                                <select name="period_type"><option value="weekly">Mingguan</option><option value="monthly">Bulanan</option></select>
                            </div>
                            <div class="fg"><label>Label</label><input type="text" name="period_label" placeholder="Minggu 1 Feb 2026"></div>
                            <div class="fg"><label>💵 Total Gaji</label><input type="text" id="totalSalaryDisplay" class="total-display" readonly placeholder="Rp 0"></div>
                            <div class="fg"><label>Catatan</label><input type="text" name="notes" placeholder="Opsional"></div>
                            <div class="fg w-auto"><label>&nbsp;</label><button type="submit" class="btn btn-emerald">💾 Simpan</button></div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Riwayat Gaji</h3>
                        <div class="action-row no-print">
                            <button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button>
                            <button onclick="submitToOwner()" class="btn btn-amber btn-xs">📤 Ajukan ke Owner</button>
                        </div>
                    </div>
                    <table class="tbl">
                        <thead><tr><th>#</th><th>Pekerja</th><th>Periode</th><th style="text-align:right">Upah</th><th style="text-align:right">Lembur</th><th style="text-align:right">Lain²</th><th>Hari</th><th style="text-align:right">Total</th><th>Status</th><th class="no-print" style="width:50px"></th></tr></thead>
                        <tbody>
                        <?php if (empty($salaries)): ?>
                            <tr class="empty"><td colspan="10">Belum ada data gaji</td></tr>
                        <?php else: ?>
                            <?php foreach ($salaries as $i => $s): ?>
                            <tr>
                                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                                <td><strong><?= htmlspecialchars($s['worker_name']??'?') ?></strong><br><span class="hint"><?= htmlspecialchars($s['worker_role']??'') ?></span></td>
                                <td><?= htmlspecialchars($s['period_label'] ?: ($s['period_type']=='weekly'?'Mingguan':'Bulanan')) ?></td>
                                <td style="text-align:right">Rp <?= number_format($s['daily_rate']??0,0,',','.') ?></td>
                                <td style="text-align:right">Rp <?= number_format($s['overtime_per_day']??0,0,',','.') ?></td>
                                <td style="text-align:right">Rp <?= number_format($s['other_per_day']??0,0,',','.') ?></td>
                                <td style="text-align:center"><?= $s['total_days']??0 ?></td>
                                <td class="money" style="text-align:right">Rp <?= number_format($s['total_salary']??0,0,',','.') ?></td>
                                <td><span class="badge badge-<?= $s['status']??'draft' ?>"><?= $s['status']??'draft' ?></span></td>
                                <td class="no-print"><button class="btn btn-rose btn-xs" onclick="deleteSalary(<?= $s['id'] ?>)">✕</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="foot"><td colspan="7">TOTAL GAJI</td><td class="money" style="text-align:right" colspan="2">Rp <?= number_format($total_gaji,0,',','.') ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ═══ TAB: DIVISI/KONTRAKTOR (Master Data) ═══ -->
                <?php elseif ($tab == 'division'): ?>
                <div class="panel no-print">
                    <div class="panel-head"><h3>🏗️ Tambah Data Kontraktor</h3></div>
                    <form id="contractorForm" onsubmit="saveContractor(event)">
                        <div class="inline-form">
                            <div class="fg w2"><label>Nama Kontraktor</label><input type="text" name="name" required placeholder="CV. ABC / Kontraktor Moyong"></div>
                            <div class="fg"><label>Bidang</label>
                                <select name="bidang">
                                    <option value="">— Pilih —</option>
                                    <option>Sipil</option><option>Listrik</option><option>Plumbing</option>
                                    <option>Interior</option><option>Atap</option><option>Besi/Las</option>
                                    <option>Cat</option><option>Keramik</option><option>Taman</option><option>Lainnya</option>
                                </select>
                            </div>
                            <div class="fg"><label>PIC</label><input type="text" name="pic_name" placeholder="Nama penanggung jawab"></div>
                            <div class="fg"><label>HP</label><input type="text" name="phone" placeholder="08xx"></div>
                            <div class="fg w-auto"><label>&nbsp;</label><button type="submit" class="btn btn-emerald">+ Tambah</button></div>
                        </div>
                    </form>
                </div>
                <div class="panel">
                    <div class="panel-head">
                        <h3>Daftar Kontraktor <span class="badge badge-active" style="margin-left:.4rem"><?= count($contractors) ?></span></h3>
                        <div class="action-row no-print"><button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button></div>
                    </div>
                    <table class="tbl">
                        <thead><tr><th>#</th><th>Nama Kontraktor</th><th>Bidang</th><th>PIC</th><th>HP</th><th>Status</th><th class="no-print" style="width:50px">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (empty($contractors)): ?>
                            <tr class="empty"><td colspan="7">Belum ada data kontraktor</td></tr>
                        <?php else: ?>
                            <?php foreach ($contractors as $i => $c): ?>
                            <tr>
                                <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                                <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                <td><?= htmlspecialchars($c['bidang'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($c['pic_name'] ?? '-') ?></td>
                                <td style="color:var(--text-muted)"><?= htmlspecialchars($c['phone'] ?? '-') ?></td>
                                <td><span class="badge badge-<?= $c['status']??'active' ?>"><?= $c['status']??'active' ?></span></td>
                                <td class="no-print"><button class="btn btn-rose btn-xs" onclick="deleteContractor(<?= $c['id'] ?>)">✕</button></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ═══ TAB: LAPORAN ═══ -->
                <?php elseif ($tab == 'laporan'): ?>

                <!-- Sub-tab toggle -->
                <?php $sub = $_GET['sub'] ?? 'jasa'; ?>
                <div class="nav-tabs no-print" style="margin-bottom:.8rem">
                    <a class="nav-tab <?= $sub=='jasa'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=laporan&sub=jasa">🏗️ Laporan Jasa / Kontraktor</a>
                    <a class="nav-tab <?= $sub=='gaji'?'active':'' ?>" href="?project_id=<?= $project_id ?>&tab=laporan&sub=gaji">💰 Laporan Gaji</a>
                </div>

                <?php if ($sub == 'jasa'): ?>
                <!-- ── LAPORAN JASA / KONTRAKTOR ── -->
                <div class="panel">
                    <div class="panel-head">
                        <h3>🏗️ Laporan Pengeluaran per Kontraktor</h3>
                        <div class="action-row no-print"><button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button></div>
                    </div>
                    <?php if (empty($laporan_jasa)): ?>
                        <div class="empty-state">Belum ada pengeluaran yang dikaitkan ke kontraktor.<br><span class="hint">Tambah kontraktor di tab Divisi, lalu pilih kontraktor saat catat pengeluaran.</span></div>
                    <?php else: ?>
                        <div class="laporan-grid">
                            <?php foreach ($laporan_jasa as $divName => $divData): ?>
                            <div class="lap-card" onclick="document.getElementById('jasa-<?= md5($divName) ?>').scrollIntoView({behavior:'smooth'})">
                                <div class="lap-name"><?= htmlspecialchars($divName) ?></div>
                                <div class="lap-total">Rp <?= number_format($divData['total'],0,',','.') ?></div>
                                <div class="lap-count"><?= count($divData['items']) ?> transaksi</div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="summary-strip" style="grid-template-columns:1fr">
                            <div class="sc c-amber">
                                <div class="sc-label">Total Semua Kontraktor</div>
                                <div class="sc-val">Rp <?= number_format($grand_total_jasa,0,',','.') ?></div>
                            </div>
                        </div>

                        <?php foreach ($laporan_jasa as $divName => $divData): ?>
                        <div class="panel lap-detail" id="jasa-<?= md5($divName) ?>">
                            <div class="panel-head">
                                <h3>🏗️ <?= htmlspecialchars($divName) ?></h3>
                                <span class="badge badge-submitted">Rp <?= number_format($divData['total'],0,',','.') ?></span>
                            </div>
                            <table class="tbl">
                                <thead><tr><th>#</th><th>Sumber</th><th>Deskripsi</th><th>Tanggal</th><th style="text-align:right">Jumlah</th></tr></thead>
                                <tbody>
                                <?php foreach ($divData['items'] as $idx => $item): ?>
                                <tr>
                                    <td style="color:var(--text-muted)"><?= $idx+1 ?></td>
                                    <td><span class="badge <?= $item['source']=='Pengeluaran'?'badge-pending':'badge-approved' ?>"><?= $item['source'] ?></span></td>
                                    <td><?= htmlspecialchars($item['description']) ?></td>
                                    <td><?= $item['date'] != '-' ? date('d/m/Y', strtotime($item['date'])) : '-' ?></td>
                                    <td class="money" style="text-align:right">Rp <?= number_format($item['amount'],0,',','.') ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="foot"><td colspan="4">SUBTOTAL</td><td class="money" style="text-align:right">Rp <?= number_format($divData['total'],0,',','.') ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php else: ?>
                <!-- ── LAPORAN GAJI ── -->
                <div class="panel">
                    <div class="panel-head">
                        <h3>💰 Laporan Gaji per Pekerja</h3>
                        <div class="action-row no-print"><button onclick="window.print()" class="btn btn-sky btn-xs">🖨️ Print</button></div>
                    </div>
                    <?php if (empty($laporan_gaji)): ?>
                        <div class="empty-state">Belum ada data gaji.<br><span class="hint">Tambah pekerja di tab Pekerja, lalu input gaji di tab Gaji.</span></div>
                    <?php else: ?>
                        <div class="laporan-grid">
                            <?php foreach ($laporan_gaji as $wName => $wData): ?>
                            <div class="lap-card" onclick="document.getElementById('gaji-<?= md5($wName) ?>').scrollIntoView({behavior:'smooth'})">
                                <div class="lap-name"><?= htmlspecialchars($wName) ?></div>
                                <div class="lap-total">Rp <?= number_format($wData['total'],0,',','.') ?></div>
                                <div class="lap-count"><?= count($wData['items']) ?> pembayaran · <?= htmlspecialchars($wData['role']) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="summary-strip" style="grid-template-columns:1fr">
                            <div class="sc c-red">
                                <div class="sc-label">Total Gaji Semua Pekerja</div>
                                <div class="sc-val">Rp <?= number_format($grand_total_gaji,0,',','.') ?></div>
                            </div>
                        </div>

                        <?php foreach ($laporan_gaji as $wName => $wData): ?>
                        <div class="panel lap-detail" id="gaji-<?= md5($wName) ?>">
                            <div class="panel-head">
                                <h3>👷 <?= htmlspecialchars($wName) ?> <span class="hint" style="margin-left:.5rem"><?= htmlspecialchars($wData['role']) ?></span></h3>
                                <span class="badge badge-submitted">Rp <?= number_format($wData['total'],0,',','.') ?></span>
                            </div>
                            <table class="tbl">
                                <thead><tr><th>#</th><th>Periode</th><th style="text-align:right">Upah/Hari</th><th style="text-align:right">Lembur</th><th style="text-align:right">Lain²</th><th>Hari</th><th style="text-align:right">Total</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($wData['items'] as $idx => $item): ?>
                                <tr>
                                    <td style="color:var(--text-muted)"><?= $idx+1 ?></td>
                                    <td><?= htmlspecialchars($item['period']) ?></td>
                                    <td style="text-align:right">Rp <?= number_format($item['daily_rate'],0,',','.') ?></td>
                                    <td style="text-align:right">Rp <?= number_format($item['overtime'],0,',','.') ?></td>
                                    <td style="text-align:right">Rp <?= number_format($item['other'],0,',','.') ?></td>
                                    <td style="text-align:center"><?= $item['days'] ?></td>
                                    <td class="money" style="text-align:right">Rp <?= number_format($item['total'],0,',','.') ?></td>
                                    <td><span class="badge badge-<?= $item['status'] ?>"><?= $item['status'] ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="foot"><td colspan="6">SUBTOTAL</td><td class="money" style="text-align:right">Rp <?= number_format($wData['total'],0,',','.') ?></td><td></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

    <?php endif; ?>
</div>

<script>
const BASE = '<?= BASE_URL ?>';
const PID = <?= $project_id ?: 0 ?>;

function fillRate(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('dailyRate').value = opt.getAttribute('data-rate') || 0;
    calcTotal();
}

function calcTotal() {
    const f = document.getElementById('salaryForm');
    if (!f) return;
    const daily = parseFloat(f.daily_rate.value) || 0;
    const ot = parseFloat(f.overtime_per_day.value) || 0;
    const other = parseFloat(f.other_per_day.value) || 0;
    const days = parseInt(f.total_days.value) || 0;
    const total = (daily + ot + other) * days;
    document.getElementById('totalSalaryDisplay').value = 'Rp ' + total.toLocaleString('id-ID');
}

document.addEventListener('DOMContentLoaded', () => {
    const sf = document.getElementById('salaryForm');
    if (sf) sf.querySelectorAll('input[type=number]').forEach(el => el.addEventListener('input', calcTotal));
});

async function apiPost(url, data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    fd.append('project_id', PID);
    const r = await fetch(BASE + url, { method: 'POST', body: fd });
    return await r.json();
}

async function saveExpense(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('project_id', PID);
    const r = await fetch(BASE + '/api/investor-expense-save.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function deleteExpense(id) {
    if (!confirm('Hapus pengeluaran ini?')) return;
    const res = await apiPost('/api/investor-expense-delete.php', { expense_id: id });
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function saveWorker(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('project_id', PID);
    const r = await fetch(BASE + '/api/investor-worker-save.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function deleteWorker(id) {
    if (!confirm('Hapus data pekerja ini?')) return;
    const res = await apiPost('/api/investor-worker-delete.php', { worker_id: id });
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function saveSalary(e) {
    e.preventDefault();
    const f = e.target;
    const daily = parseFloat(f.daily_rate.value) || 0;
    const ot = parseFloat(f.overtime_per_day.value) || 0;
    const other = parseFloat(f.other_per_day.value) || 0;
    const days = parseInt(f.total_days.value) || 0;
    const total = (daily + ot + other) * days;
    const fd = new FormData(f);
    fd.append('project_id', PID);
    fd.append('total_salary', total);
    const r = await fetch(BASE + '/api/investor-salary-save.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function deleteSalary(id) {
    if (!confirm('Hapus data gaji ini?')) return;
    const res = await apiPost('/api/investor-salary-delete.php', { salary_id: id });
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function saveDivision(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('project_id', PID);
    const r = await fetch(BASE + '/api/investor-division-save.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function deleteDivision(id) {
    if (!confirm('Hapus pengeluaran divisi ini?')) return;
    const res = await apiPost('/api/investor-division-delete.php', { division_id: id });
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function saveContractor(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('project_id', PID);
    const r = await fetch(BASE + '/api/investor-contractor-save.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

async function deleteContractor(id) {
    if (!confirm('Hapus data kontraktor ini?')) return;
    const res = await apiPost('/api/investor-contractor-delete.php', { contractor_id: id });
    if (res.success) location.reload(); else alert('Error: ' + res.message);
}

function submitToOwner() {
    if (!confirm('Ajukan semua data gaji ke Owner untuk approval?')) return;
    apiPost('/api/investor-salary-submit.php', {}).then(res => {
        if (res.success) { alert('✅ Berhasil diajukan ke Owner!'); location.reload(); }
        else alert('Error: ' + res.message);
    });
}

function submitDivToOwner() {
    if (!confirm('Ajukan semua pengeluaran divisi ke Owner untuk approval?')) return;
    apiPost('/api/investor-division-submit.php', {}).then(res => {
        if (res.success) { alert('✅ Berhasil diajukan ke Owner!'); location.reload(); }
        else alert('Error: ' + res.message);
    });
}
</script>

<?php include $base_path . '/includes/footer.php'; ?>
