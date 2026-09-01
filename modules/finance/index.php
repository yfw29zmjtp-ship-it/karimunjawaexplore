<?php
/**
 * MODUL FINANCE - Manajemen Pengeluaran Project
 * Dashboard untuk memilih project dan mengelola pengeluaran
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

// Get all projects with expense summary
try {
    $projects = $db->query("
        SELECT p.*,
               COALESCE(SUM(pe.amount_idr), 0) as total_expenses,
               COALESCE(COUNT(pe.id), 0) as expense_count
        FROM projects p
        LEFT JOIN project_expenses pe ON p.id = pe.project_id
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $projects = [];
}

// Calculate totals
$totalBudget = 0;
$totalExpense = 0;
foreach ($projects as $proj) {
    $totalBudget += $proj['budget'] ?? 0;
    $totalExpense += $proj['total_expenses'] ?? 0;
}

// Get top 5 recent expenses
try {
    $recentExpenses = $db->query("
        SELECT pe.*, p.name as project_name
        FROM project_expenses pe
        LEFT JOIN projects p ON pe.project_id = p.id
        ORDER BY pe.expense_date DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentExpenses = [];
}

$pageTitle = 'Manajemen Keuangan';
include '../../includes/header.php';
?>

<style>
.finance-page {
    padding: 1.5rem;
    max-width: 1400px;
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

.header-actions {
    display: flex;
    gap: 0.75rem;
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
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.btn-success:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
}

.summary-card .label {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.summary-card .value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
}

.summary-card.highlight {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
    border-color: rgba(99, 102, 241, 0.3);
}

.summary-card.highlight .value {
    color: #6366f1;
}

.summary-card.warning {
    background: linear-gradient(135deg, rgba(244, 63, 94, 0.1), rgba(248, 113, 113, 0.1));
    border-color: rgba(244, 63, 94, 0.3);
}

.summary-card.warning .value {
    color: #f43f5e;
}

/* Section Title */
.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1.5rem;
    margin-top: 2rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

/* Project Grid */
.project-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
    margin-bottom: 3rem;
}

.project-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    transition: all 0.2s;
    display: flex;
    flex-direction: column;
}

.project-card:hover {
    border-color: #6366f1;
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
}

.project-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.project-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.project-status {
    font-size: 0.65rem;
    padding: 0.25rem 0.75rem;
    background: rgba(52, 211, 153, 0.2);
    color: #10b981;
    border-radius: 20px;
    text-transform: uppercase;
    font-weight: 500;
}

.project-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1.25rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid var(--border-color);
}

.stat {
    display: flex;
    flex-direction: column;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.stat-desc {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
}

/* Progress Bar */
.progress-bar {
    height: 8px;
    background: var(--bg-tertiary);
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
    width: 0%;
    transition: width 0.3s;
}

.progress-text {
    font-size: 0.7rem;
    color: var(--text-muted);
    text-align: right;
}

/* Project Actions */
.project-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: auto;
}

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.8rem;
    border-radius: 6px;
    flex: 1;
    text-align: center;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
}

.btn-outline:hover {
    background: var(--bg-tertiary);
    border-color: #6366f1;
    color: #6366f1;
}

/* Recent Activity */
.activity-list {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
}

.activity-item {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-info {
    flex: 1;
}

.activity-project {
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}

.activity-desc {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.activity-amount {
    font-size: 0.95rem;
    font-weight: 600;
    color: #f43f5e;
    text-align: right;
    min-width: 120px;
}

.activity-date {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-align: right;
    min-width: 80px;
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
</style>

<div class="finance-page">
    <!-- Header -->
    <div class="page-header">
        <h1>
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M12 8v8m-4-4h8M1 10h6m8 0h6M4 3h16a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
            </svg>
            Manajemen Keuangan
        </h1>
        <div class="header-actions">
            <button class="btn btn-success" onclick="location.href='ledger.php'">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                + Catat Pengeluaran
            </button>
            <button class="btn btn-primary" onclick="location.href='reports.php'">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zM9 7h6m-6 4h6m-6 4h6M7 7v10"/>
                </svg>
                Laporan
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card highlight">
            <div class="label">Total Budget Project</div>
            <div class="value">Rp <?= number_format($totalBudget, 0, ',', '.') ?></div>
        </div>
        <div class="summary-card warning">
            <div class="label">Total Pengeluaran</div>
            <div class="value">Rp <?= number_format($totalExpense, 0, ',', '.') ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Sisa Budget</div>
            <div class="value">Rp <?= number_format($totalBudget - $totalExpense, 0, ',', '.') ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Project</div>
            <div class="value"><?= count($projects) ?></div>
        </div>
    </div>

    <!-- Project List -->
    <h2 class="section-title">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M5 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5z"/>
            <polyline points="10 8 10 14 14 11"/>
        </svg>
        Project Aktif
    </h2>

    <?php if (empty($projects)): ?>
        <div class="empty-state">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 13h6m-3-3v6m-7-9h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
            </svg>
            <p>Belum ada project yang tersedia</p>
        </div>
    <?php else: ?>
        <div class="project-grid">
            <?php foreach ($projects as $proj): 
                $budget = $proj['budget'] ?? 0;
                $expenses = $proj['total_expenses'] ?? 0;
                $percentage = $budget > 0 ? ($expenses / $budget * 100) : 0;
                $remaining = $budget - $expenses;
            ?>
            <div class="project-card">
                <div class="project-header">
                    <div class="project-name"><?= htmlspecialchars($proj['name']) ?></div>
                    <div class="project-status"><?= htmlspecialchars($proj['status'] ?? 'active') ?></div>
                </div>

                <div class="project-stats">
                    <div class="stat">
                        <div class="stat-label">Budget</div>
                        <div class="stat-value">Rp <?= number_format($budget, 0, ',', '.') ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Pengeluaran</div>
                        <div class="stat-value">Rp <?= number_format($expenses, 0, ',', '.') ?></div>
                    </div>
                </div>

                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= min($percentage, 100) ?>%"></div>
                </div>
                <div class="progress-text"><?= number_format($percentage, 1) ?>% terpakai</div>

                <div style="margin: 1rem 0; padding: 0.75rem; background: var(--bg-tertiary); border-radius: 8px;">
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Sisa Budget</div>
                    <div style="font-size: 1.2rem; font-weight: 700; color: #10b981;">Rp <?= number_format($remaining, 0, ',', '.') ?></div>
                </div>

                <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                    <?= $proj['expense_count'] ?> pengeluaran tercatat
                </div>

                <div class="project-actions">
                    <a href="ledger.php?project_id=<?= $proj['id'] ?>" class="btn btn-sm btn-outline">
                        Buku Kas
                    </a>
                    <a href="reports.php?project_id=<?= $proj['id'] ?>" class="btn btn-sm btn-outline">
                        Laporan
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Recent Activity -->
    <h2 class="section-title">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
        </svg>
        Aktivitas Terbaru
    </h2>

    <div class="activity-list">
        <?php if (empty($recentExpenses)): ?>
            <div class="empty-state">
                <p>Belum ada pengeluaran yang tercatat</p>
            </div>
        <?php else: ?>
            <?php foreach ($recentExpenses as $exp): ?>
            <div class="activity-item">
                <div class="activity-info">
                    <div class="activity-project"><?= htmlspecialchars($exp['project_name']) ?></div>
                    <div class="activity-desc"><?= htmlspecialchars($exp['description'] ?? 'Pengeluaran') ?></div>
                </div>
                <div class="activity-amount">-Rp <?= number_format($exp['amount_idr'] ?? $exp['amount'] ?? 0, 0, ',', '.') ?></div>
                <div class="activity-date"><?= date('d M Y', strtotime($exp['expense_date'])) ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
