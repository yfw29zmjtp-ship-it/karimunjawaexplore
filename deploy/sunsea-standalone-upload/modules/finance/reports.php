<?php
/**
 * MODUL FINANCE - PROJECT REPORTS
 * Laporan pengeluaran project per minggu/bulan/custom
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
    header('Location: ../../index.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// Get project ID and report type
$projectId = $_GET['project_id'] ?? null;
$reportType = $_GET['report_type'] ?? 'monthly'; // monthly, weekly, daily, custom
$selectedMonth = $_GET['month'] ?? date('Y-m');
$selectedWeek = $_GET['week'] ?? null;

// Get all projects
try {
    $projects = $db->query("SELECT id, name, budget FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $projects = [];
}

$selectedProject = null;
$reportData = [];
$totalExpenses = 0;
$categoryBreakdown = [];

if ($projectId) {
    try {
        // Get project details
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $selectedProject = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($selectedProject) {
            // Prepare date range based on report type
            $whereClause = "WHERE pe.project_id = ?";
            $params = [$projectId];

            if ($reportType === 'monthly' && !empty($selectedMonth)) {
                $whereClause .= " AND DATE_FORMAT(pe.expense_date, '%Y-%m') = ?";
                $params[] = $selectedMonth;
            } elseif ($reportType === 'weekly' && !empty($selectedWeek)) {
                // Week calculation (Year-Week format)
                $whereClause .= " AND DATE_FORMAT(pe.expense_date, '%x-%v') = ?";
                $params[] = $selectedWeek;
            } elseif ($reportType === 'daily') {
                $day = $_GET['day'] ?? date('Y-m-d');
                $whereClause .= " AND DATE(pe.expense_date) = ?";
                $params[] = $day;
            }

            // Get expenses with category info
            $query = "
                SELECT pe.*, pec.category_name,
                       DATE_FORMAT(pe.expense_date, '%d') as day_of_month,
                       DATE_FORMAT(pe.expense_date, '%Y-%m') as month_year,
                       DATE_FORMAT(pe.expense_date, '%x-%v') as week_year
                FROM project_expenses pe
                LEFT JOIN project_expense_categories pec ON pe.expense_category_id = pec.id
                $whereClause
                ORDER BY pe.expense_date DESC, pe.id DESC
            ";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate totals and breakdown by category
            foreach ($reportData as $exp) {
                $amount = $exp['amount_idr'] ?? $exp['amount'] ?? 0;
                $totalExpenses += $amount;

                $category = $exp['category_name'] ?? 'Lainnya';
                if (!isset($categoryBreakdown[$category])) {
                    $categoryBreakdown[$category] = ['total' => 0, 'count' => 0];
                }
                $categoryBreakdown[$category]['total'] += $amount;
                $categoryBreakdown[$category]['count']++;
            }

            // Sort by total
            uasort($categoryBreakdown, function($a, $b) {
                return $b['total'] <=> $a['total'];
            });
        }
    } catch (Exception $e) {
        // Error loading project
    }
}

$pageTitle = 'Laporan Project';
include '../../includes/header.php';
?>

<style>
.report-page {
    padding: 1.5rem;
    max-width: 1200px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.page-header h1 {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.back-link {
    color: var(--text-secondary);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    transition: color 0.2s;
}

.back-link:hover {
    color: #6366f1;
}

.btn {
    padding: 0.6rem 1.2rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
    background: #6366f1;
    color: white;
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

/* Filters */
.report-filters {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-group select,
.form-group input {
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.9rem;
    background: var(--bg-primary);
    color: var(--text-primary);
    cursor: pointer;
}

.form-group select:focus,
.form-group input:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

/* Summary Cards */
.summary-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.summary-box {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
}

.summary-box .label {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.summary-box .value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
}

.summary-box .desc {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 0.5rem;
}

/* Two Column Layout */
.report-container {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 1.5rem;
}

/* Expense Table */
.expense-table {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
}

.table-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.table-header h3 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th {
    padding: 1rem;
    background: var(--bg-tertiary);
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-muted);
    text-align: left;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border-color);
}

.table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.9rem;
    color: var(--text-primary);
}

.table tr:hover {
    background: var(--bg-tertiary);
}

.amount-cell {
    font-weight: 700;
    color: #f43f5e;
    text-align: right;
}

.date-cell {
    color: var(--text-muted);
    font-size: 0.85rem;
}

.category-badge {
    display: inline-block;
    padding: 0.3rem 0.75rem;
    background: rgba(99, 102, 241, 0.1);
    color: #6366f1;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 500;
}

/* Category Breakdown */
.category-breakdown {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    height: fit-content;
    position: sticky;
    top: 20px;
}

.category-breakdown h3 {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border-color);
}

.category-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.85rem;
}

.category-item:last-child {
    border-bottom: none;
}

.category-name {
    flex: 1;
    color: var(--text-primary);
}

.category-amount {
    font-weight: 600;
    color: #f43f5e;
    text-align: right;
    min-width: 100px;
}

.category-count {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 0.2rem;
}

.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: var(--text-muted);
}

.empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 1rem;
    opacity: 0.3;
}

@media (max-width: 768px) {
    .report-container {
        grid-template-columns: 1fr;
    }
    
    .category-breakdown {
        position: static;
    }
}

.print-btn {
    background: #059669;
}

.print-btn:hover {
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}

@media print {
    .page-header,
    .report-filters,
    .form-group,
    .print-btn {
        display: none;
    }
    
    .report-page {
        padding: 0;
    }
}
</style>

<div class="report-page">
    <a href="index.php" class="back-link">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        Kembali ke Dashboard Keuangan
    </a>

    <!-- Page Header -->
    <div class="page-header">
        <h1>
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M19 21H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h1V6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1h1a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2zM9 6h6v1H9V6z"/>
            </svg>
            Laporan Project
        </h1>
        <button class="btn print-btn" onclick="window.print()">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect x="6" y="14" width="12" height="8"/>
            </svg>
            Print
        </button>
    </div>

    <!-- Filters -->
    <div class="report-filters">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="form-group">
                    <label>Pilih Project</label>
                    <select name="project_id" onchange="this.form.submit()" required>
                        <option value="">-- Pilih Project --</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?= $proj['id'] ?>" <?= $projectId == $proj['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($proj['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Jenis Laporan</label>
                    <select name="report_type" onchange="this.form.submit()">
                        <option value="monthly" <?= $reportType === 'monthly' ? 'selected' : '' ?>>Bulanan</option>
                        <option value="weekly" <?= $reportType === 'weekly' ? 'selected' : '' ?>>Mingguan</option>
                        <option value="daily" <?= $reportType === 'daily' ? 'selected' : '' ?>>Harian</option>
                    </select>
                </div>

                <?php if ($reportType === 'monthly'): ?>
                    <div class="form-group">
                        <label>Bulan</label>
                        <input type="month" name="month" value="<?= $selectedMonth ?>" onchange="this.form.submit()">
                    </div>
                <?php elseif ($reportType === 'weekly'): ?>
                    <div class="form-group">
                        <label>Minggu</label>
                        <input type="week" name="week" value="<?= $selectedWeek ?>" onchange="this.form.submit()">
                    </div>
                <?php elseif ($reportType === 'daily'): ?>
                    <div class="form-group">
                        <label>Tanggal</label>
                        <input type="date" name="day" value="<?= $_GET['day'] ?? date('Y-m-d') ?>" onchange="this.form.submit()">
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($selectedProject): ?>
        <!-- Summary -->
        <div class="summary-section">
            <div class="summary-box">
                <div class="label">Total Pengeluaran</div>
                <div class="value">Rp <?= number_format($totalExpenses, 0, ',', '.') ?></div>
                <div class="desc"><?= count($reportData) ?> transaksi</div>
            </div>
            <div class="summary-box">
                <div class="label">Budget Project</div>
                <div class="value">Rp <?= number_format($selectedProject['budget'] ?? 0, 0, ',', '.') ?></div>
            </div>
            <div class="summary-box">
                <div class="label">Sisa Budget</div>
                <div class="value" style="color: #10b981;">Rp <?= number_format(($selectedProject['budget'] ?? 0) - $totalExpenses, 0, ',', '.') ?></div>
            </div>
        </div>

        <!-- Report Data -->
        <div class="report-container">
            <!-- Main Table -->
            <div class="expense-table">
                <div class="table-header">
                    <h3><?= htmlspecialchars($selectedProject['name']) ?> - Laporan <?= ucfirst($reportType) ?></h3>
                </div>

                <?php if (empty($reportData)): ?>
                    <div class="empty-state">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M9 12h6m-6 4h6m2-13H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/>
                        </svg>
                        <p>Tidak ada pengeluaran untuk periode ini</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Kategori</th>
                                <th>Keterangan</th>
                                <th style="text-align: right;">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $exp): ?>
                                <tr>
                                    <td class="date-cell"><?= date('d M Y', strtotime($exp['expense_date'])) ?></td>
                                    <td>
                                        <?php if (!empty($exp['category_name'])): ?>
                                            <span class="category-badge"><?= htmlspecialchars($exp['category_name']) ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($exp['description'] ?? '-') ?></td>
                                    <td class="amount-cell">Rp <?= number_format($exp['amount_idr'] ?? $exp['amount'] ?? 0, 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background: var(--bg-tertiary); font-weight: 700;">
                                <td colspan="3">TOTAL</td>
                                <td class="amount-cell" style="color: #f43f5e;">Rp <?= number_format($totalExpenses, 0, ',', '.') ?></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Category Breakdown -->
            <?php if (!empty($categoryBreakdown)): ?>
                <div class="category-breakdown">
                    <h3>Breakdown by Kategori</h3>
                    <?php foreach ($categoryBreakdown as $category => $data): 
                        $percentage = $totalExpenses > 0 ? ($data['total'] / $totalExpenses * 100) : 0;
                    ?>
                        <div class="category-item">
                            <div style="flex: 1;">
                                <div class="category-name"><?= htmlspecialchars($category) ?></div>
                                <div class="category-count"><?= $data['count'] ?> item</div>
                            </div>
                            <div style="text-align: right;">
                                <div class="category-amount">Rp <?= number_format($data['total'], 0, ',', '.') ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-muted);"><?= number_format($percentage, 1) ?>%</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- No Project Selected -->
        <div class="empty-state" style="margin-top: 4rem;">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M5 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5z"/>
                <polyline points="10 8 10 14 14 11"/>
            </svg>
            <p>Pilih project untuk menampilkan laporan</p>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
