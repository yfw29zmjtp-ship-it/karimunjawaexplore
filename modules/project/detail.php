<?php

/**
 * DETAIL KEUANGAN PROJEK
 * Menampilkan dashboard keuangan dan transaksi per projek
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

$db = Database::getInstance()->getConnection();

// Get project ID
$projectId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$projectId) {
    header('Location: index.php');
    exit;
}

// Get project info
try {
    $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        header('Location: index.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: index.php');
    exit;
}

// Auto-migrate: sumber belanja & status pembayaran (dibuat idempotent, aman dipanggil berulang)
$expenseCols = $db->query("DESCRIBE project_expenses")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('purchase_source', $expenseCols)) {
    $db->exec("ALTER TABLE project_expenses ADD COLUMN purchase_source ENUM('jepara','karimunjawa') DEFAULT NULL AFTER category");
}
if (!in_array('payment_status', $expenseCols)) {
    $db->exec("ALTER TABLE project_expenses ADD COLUMN payment_status ENUM('belum_lunas','lunas') NOT NULL DEFAULT 'belum_lunas'");
}
if (!in_array('contractor_name', $expenseCols)) {
    $db->exec("ALTER TABLE project_expenses ADD COLUMN contractor_name VARCHAR(150) DEFAULT NULL AFTER purchase_source");
}

$purchaseSources = ['jepara' => 'Jepara', 'karimunjawa' => 'Karimunjawa'];
$paymentStatuses = ['belum_lunas' => 'Belum Lunas', 'lunas' => 'Lunas'];

// Filter by payment status (untuk daftar & laporan cetak)
$statusFilter = $_GET['payment_status'] ?? 'all';
if (!isset($paymentStatuses[$statusFilter])) {
    $statusFilter = 'all';
}

// Get expenses for this project (filtered list, for the table)
try {
    $sql = "SELECT * FROM project_expenses WHERE project_id = ?";
    $params = [$projectId];
    if ($statusFilter !== 'all') {
        $sql .= " AND payment_status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY expense_date DESC, created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $expenses = [];
}

// Get ALL expenses (unfiltered) for the dashboard totals - these must reflect the
// whole project regardless of which payment_status filter is currently selected.
try {
    $stmt = $db->prepare("SELECT * FROM project_expenses WHERE project_id = ? ORDER BY expense_date DESC, created_at DESC");
    $stmt->execute([$projectId]);
    $allExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allExpenses = [];
}

// Get expense categories (predefined)
$categories = [
    'material' => 'Material/Bahan',
    'upah' => 'Upah Kerja',
    'transport' => 'Transport',
    'equipment' => 'Peralatan',
    'consumable' => 'Bahan Habis Pakai',
    'other' => 'Lainnya'
];

// Calculate totals (dari SELURUH pengeluaran, bukan hasil filter)
$totalExpenses = 0;
$expenseByCategory = [];

foreach ($allExpenses as $exp) {
    $amount = $exp['amount_idr'] ?? $exp['amount'] ?? 0;
    $totalExpenses += $amount;

    $cat = $exp['category'] ?? 'other';
    if (!isset($expenseByCategory[$cat])) {
        $expenseByCategory[$cat] = 0;
    }
    $expenseByCategory[$cat] += $amount;
}

// Total tagihan per pemborong (hanya baris yang punya nama pemborong diisi)
$expenseByContractor = [];
$contractorNames = [];
foreach ($allExpenses as $exp) {
    $contractor = trim($exp['contractor_name'] ?? '');
    if ($contractor === '') {
        continue;
    }
    $contractorNames[$contractor] = true;
    $amount = $exp['amount_idr'] ?? $exp['amount'] ?? 0;
    if (!isset($expenseByContractor[$contractor])) {
        $expenseByContractor[$contractor] = ['total' => 0, 'unpaid' => 0];
    }
    $expenseByContractor[$contractor]['total'] += $amount;
    if (($exp['payment_status'] ?? 'belum_lunas') !== 'lunas') {
        $expenseByContractor[$contractor]['unpaid'] += $amount;
    }
}
ksort($expenseByContractor);
$contractorNames = array_keys($contractorNames);
sort($contractorNames);

$budget = $project['budget'] ?? 0;
$remaining = $budget - $totalExpenses;
$percentage = $budget > 0 ? min(100, ($totalExpenses / $budget) * 100) : 0;

$expenseMap = [];
foreach ($expenses as $exp) {
    $expenseMap[$exp['id']] = [
        'expense_date' => $exp['expense_date'] ?? date('Y-m-d', strtotime($exp['created_at'] ?? 'now')),
        'category' => $exp['category'] ?? 'other',
        'purchase_source' => $exp['purchase_source'] ?? '',
        'payment_status' => $exp['payment_status'] ?? 'belum_lunas',
        'contractor_name' => $exp['contractor_name'] ?? '',
        'amount' => (float) ($exp['amount_idr'] ?? $exp['amount'] ?? 0),
        'description' => $exp['description'] ?? '',
        'receipt_number' => $exp['receipt_number'] ?? $exp['reference_no'] ?? ''
    ];
}

$pageTitle = 'Keuangan: ' . $project['name'];
include $base_path . '/includes/header.php';
?>

<style>
    .detail-page {
        padding: 1.5rem;
        max-width: 1400px;
        margin: 0 auto;
    }

    .page-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.75rem;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    .page-header .title-section {
        min-width: 0;
        flex: 1 1 auto;
    }

    .page-header .title-section h1 {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--secondary-color);
        display: flex;
        align-items: center;
        gap: 0.65rem;
        margin-bottom: 0.35rem;
        white-space: normal;
        overflow-wrap: break-word;
    }

    .page-header .title-section h1 .title-icon {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        box-shadow: 0 4px 10px rgba(30, 64, 175, 0.3);
    }

    .page-header .title-section h1 .title-icon svg {
        width: 18px;
        height: 18px;
    }

    .page-header .breadcrumb {
        font-size: 0.78rem;
        color: var(--text-muted);
    }

    .page-header .breadcrumb a {
        color: var(--primary-color);
        text-decoration: none;
    }

    .header-actions {
        display: flex;
        flex-shrink: 0;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .btn {
        padding: 0.5rem 0.9rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 500;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        transition: all 0.2s;
        text-decoration: none;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
    }

    .btn-success {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
    }

    .btn:hover {
        transform: translateY(-1px);
    }

    /* Dashboard Cards */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        margin-bottom: 2rem;
    }

    @media (max-width: 1024px) {
        .dashboard-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    .dash-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 0.9rem 1rem;
        box-shadow: var(--shadow-sm);
        transition: all 0.2s;
    }

    .dash-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .dash-card.budget {
        border-color: rgba(30, 64, 175, 0.25);
    }

    .dash-card.spent {
        border-color: rgba(239, 68, 68, 0.25);
    }

    .dash-card.remaining {
        border-color: rgba(16, 185, 129, 0.25);
    }

    .dash-card.transactions {
        border-color: rgba(245, 158, 11, 0.25);
    }

    .dash-card .icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 0.75rem;
    }

    .dash-card .icon svg {
        width: 16px;
        height: 16px;
    }

    .dash-card.budget .icon {
        background: rgba(30, 64, 175, 0.15);
        color: var(--secondary-color);
    }

    .dash-card.spent .icon {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .dash-card.remaining .icon {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    .dash-card.transactions .icon {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
    }

    .dash-card .label {
        font-size: 0.7rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.2rem;
    }

    .dash-card .value {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .dash-card.budget .value {
        color: var(--secondary-color);
    }

    .dash-card.spent .value {
        color: #ef4444;
    }

    .dash-card.remaining .value {
        color: #10b981;
    }

    /* Progress Section */
    .progress-section {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 1rem 1.1rem;
        margin-bottom: 1.5rem;
        box-shadow: var(--shadow-sm);
    }

    .progress-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
    }

    .progress-header h3 {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--secondary-color);
    }

    .progress-bar-large {
        height: 12px;
        background: var(--border-color);
        border-radius: 6px;
        overflow: hidden;
    }

    .progress-fill-large {
        height: 100%;
        background: linear-gradient(90deg, #10b981, #059669);
        border-radius: 6px;
        transition: width 0.5s ease;
    }

    .progress-fill-large.warning {
        background: linear-gradient(90deg, #f59e0b, #d97706);
    }

    .progress-fill-large.danger {
        background: linear-gradient(90deg, #ef4444, #dc2626);
    }

    /* Main Content Layout */
    .main-content-grid {
        display: grid;
        grid-template-columns: 1fr 400px;
        gap: 1.5rem;
    }

    @media (max-width: 1024px) {
        .main-content-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Expense Table */
    .expense-section {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 1.1rem;
        box-shadow: var(--shadow-sm);
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.9rem;
    }

    .section-header h3 {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--secondary-color);
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }

    .expense-table {
        width: 100%;
        border-collapse: collapse;
    }

    .expense-table th,
    .expense-table td {
        padding: 0.6rem 0.65rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
    }

    .expense-table th {
        font-size: 0.65rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .expense-table td {
        font-size: 0.8rem;
        color: var(--text-primary);
    }

    .expense-table .amount-col {
        font-weight: 600;
        color: #ef4444;
    }

    .expense-table .date-col {
        color: var(--text-muted);
        font-size: 0.8rem;
    }

    .category-badge {
        padding: 0.25rem 0.6rem;
        border-radius: 12px;
        font-size: 0.68rem;
        font-weight: 500;
        background: rgba(30, 64, 175, 0.1);
        color: var(--secondary-color);
    }

    .action-btn-sm {
        padding: 0.35rem 0.6rem;
        border: none;
        border-radius: 4px;
        font-size: 0.7rem;
        cursor: pointer;
        background: var(--bg-tertiary);
        color: var(--text-muted);
    }

    .action-btn-sm:hover {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
    }

    .action-btn-sm.edit-btn:hover {
        background: rgba(37, 99, 235, 0.1);
        color: var(--primary-color);
    }

    .action-col {
        display: flex;
        gap: 0.35rem;
    }

    /* Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
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
    }

    .modal-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.4rem;
        color: var(--text-muted);
        cursor: pointer;
    }

    .modal-body {
        padding: 1.25rem;
    }

    .modal-footer {
        padding: 0.9rem 1.25rem;
        border-top: 1px solid var(--border-color);
        display: flex;
        justify-content: flex-end;
        gap: 0.6rem;
    }

    .btn-secondary {
        background: var(--bg-tertiary);
        color: var(--text-secondary);
        border: 1px solid var(--border-color);
    }

    /* Quick Add Form */
    .quick-add-section {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-top: 3px solid var(--secondary-color);
        border-radius: 10px;
        padding: 1.1rem;
        box-shadow: var(--shadow-sm);
    }

    .quick-add-section h3 {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--secondary-color);
        margin-bottom: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-group label {
        display: block;
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--text-secondary);
        margin-bottom: 0.4rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 0.55rem 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 0.8rem;
        background: var(--bg-primary);
        color: var(--text-primary);
    }

    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }

    /* Category Summary */
    .category-summary {
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border-color);
    }

    .category-summary h4 {
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--secondary-color);
        margin-bottom: 0.75rem;
    }

    .category-item {
        display: flex;
        justify-content: space-between;
        padding: 0.4rem 0;
        font-size: 0.78rem;
    }

    .category-item .name {
        color: var(--text-secondary);
    }

    .category-item .amount {
        font-weight: 600;
        color: var(--text-primary);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 3rem 2rem;
        color: var(--text-muted);
    }

    .empty-state svg {
        width: 48px;
        height: 48px;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .source-badge {
        padding: 0.25rem 0.6rem;
        border-radius: 12px;
        font-size: 0.68rem;
        font-weight: 500;
        background: rgba(107, 114, 128, 0.12);
        color: var(--text-secondary);
    }

    .paystatus-badge {
        padding: 0.25rem 0.6rem;
        border-radius: 12px;
        font-size: 0.68rem;
        font-weight: 600;
    }

    .paystatus-badge.lunas {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    .paystatus-badge.belum_lunas {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .filter-tabs {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .filter-tabs .filter-tabs-left {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .filter-tabs .btn-print-filtered {
        padding: 0.4rem 0.85rem;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 500;
        text-decoration: none;
        color: #fff;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .filter-tabs a {
        padding: 0.4rem 0.85rem;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 500;
        text-decoration: none;
        color: var(--text-secondary);
        background: var(--bg-tertiary);
        border: 1px solid var(--border-color);
    }

    .filter-tabs a.active {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: #fff;
        border-color: transparent;
    }
</style>

<div class="detail-page">
    <!-- Header -->
    <div class="page-header">
        <div class="title-section">
            <div class="breadcrumb">
                <a href="index.php">Projek</a> / Keuangan
            </div>
            <h1>
                <span class="title-icon">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                    </svg>
                </span>
                <?= htmlspecialchars($project['name']) ?>
            </h1>
        </div>
        <div class="header-actions">
            <a href="print-report.php?id=<?= $projectId ?>&payment_status=<?= $statusFilter ?>" target="_blank" class="btn btn-outline">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="6,9 6,2 18,2 18,9" />
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                    <rect x="6" y="14" width="12" height="8" />
                </svg>
                Cetak Laporan
            </a>
            <a href="index.php" class="btn btn-outline">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 12H5M12 19l-7-7 7-7" />
                </svg>
                Kembali
            </a>
        </div>
    </div>

    <!-- Dashboard Cards -->
    <div class="dashboard-grid">
        <div class="dash-card budget">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2" />
                    <path d="M16 3v4M8 3v4" />
                </svg>
            </div>
            <div class="label">Budget Projek</div>
            <div class="value">Rp <?= number_format($budget, 0, ',', '.') ?></div>
        </div>

        <div class="dash-card spent">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                </svg>
            </div>
            <div class="label">Total Pengeluaran</div>
            <div class="value">Rp <?= number_format($totalExpenses, 0, ',', '.') ?></div>
        </div>

        <div class="dash-card remaining">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
                </svg>
            </div>
            <div class="label">Sisa Budget</div>
            <div class="value">Rp <?= number_format($remaining, 0, ',', '.') ?></div>
        </div>

        <div class="dash-card transactions">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <polyline points="14,2 14,8 20,8" />
                </svg>
            </div>
            <div class="label">Total Transaksi</div>
            <div class="value"><?= count($expenses) ?></div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="progress-section">
        <div class="progress-header">
            <h3>Penggunaan Budget</h3>
            <span><?= number_format($percentage, 1) ?>%</span>
        </div>
        <div class="progress-bar-large">
            <div class="progress-fill-large <?= $percentage > 90 ? 'danger' : ($percentage > 70 ? 'warning' : '') ?>"
                style="width: <?= $percentage ?>%"></div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content-grid">
        <!-- Expense Table -->
        <div class="expense-section">
            <div class="section-header">
                <h3>
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14,2 14,8 20,8" />
                    </svg>
                    Riwayat Pengeluaran
                </h3>
            </div>

            <div class="filter-tabs">
                <div class="filter-tabs-left">
                    <a href="?id=<?= $projectId ?>&payment_status=all" class="<?= $statusFilter === 'all' ? 'active' : '' ?>">Semua</a>
                    <a href="?id=<?= $projectId ?>&payment_status=belum_lunas" class="<?= $statusFilter === 'belum_lunas' ? 'active' : '' ?>">Belum Lunas</a>
                    <a href="?id=<?= $projectId ?>&payment_status=lunas" class="<?= $statusFilter === 'lunas' ? 'active' : '' ?>">Lunas</a>
                </div>
                <a href="print-report.php?id=<?= $projectId ?>&payment_status=<?= $statusFilter ?>" target="_blank" class="btn-print-filtered">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="6,9 6,2 18,2 18,9" />
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                        <rect x="6" y="14" width="12" height="8" />
                    </svg>
                    Cetak <?= $statusFilter === 'all' ? 'Semua' : ($statusFilter === 'belum_lunas' ? 'Belum Lunas' : 'Lunas') ?>
                </a>
            </div>

            <?php if (empty($expenses)): ?>
                <div class="empty-state">
                    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    </svg>
                    <p>Belum ada pengeluaran tercatat</p>
                </div>
            <?php else: ?>
                <table class="expense-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Kategori</th>
                            <th>Sumber</th>
                            <th>Pemborong</th>
                            <th>Keterangan</th>
                            <th>Jumlah</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp):
                            $paySt = $exp['payment_status'] ?? 'belum_lunas';
                            $src = $exp['purchase_source'] ?? '';
                            $contractor = trim($exp['contractor_name'] ?? '');
                        ?>
                            <tr>
                                <td class="date-col"><?= date('d M Y', strtotime($exp['expense_date'] ?? $exp['created_at'])) ?></td>
                                <td>
                                    <span class="category-badge"><?= $categories[$exp['category'] ?? 'other'] ?? $exp['category'] ?></span>
                                </td>
                                <td><?php if ($src): ?><span class="source-badge"><?= $purchaseSources[$src] ?? $src ?></span><?php else: ?>-<?php endif; ?></td>
                                <td><?= $contractor ? htmlspecialchars($contractor) : '-' ?></td>
                                <td><?= htmlspecialchars($exp['description'] ?? '-') ?></td>
                                <td class="amount-col">Rp <?= number_format($exp['amount_idr'] ?? $exp['amount'] ?? 0, 0, ',', '.') ?></td>
                                <td><span class="paystatus-badge <?= $paySt ?>"><?= $paymentStatuses[$paySt] ?? $paySt ?></span></td>
                                <td class="action-col">
                                    <button class="action-btn-sm edit-btn" onclick="openEditExpenseModal(<?= $exp['id'] ?>)" title="Edit">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z" />
                                        </svg>
                                    </button>
                                    <button class="action-btn-sm" onclick="deleteExpense(<?= $exp['id'] ?>)" title="Hapus">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <polyline points="3,6 5,6 21,6" />
                                            <path d="M19,6v14a2,2,0,0,1-2,2H7a2,2,0,0,1-2-2V6m3,0V4a2,2,0,0,1,2-2h4a2,2,0,0,1,2,2v2" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Quick Add Form -->
        <div class="quick-add-section">
            <h3>
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Tambah Pengeluaran
            </h3>

            <form id="addExpenseForm" onsubmit="saveExpense(event)">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>Tanggal</label>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Kategori</label>
                        <select name="category" required>
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Sumber Belanja</label>
                        <select name="purchase_source">
                            <?php foreach ($purchaseSources as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status Pembayaran</label>
                        <select name="payment_status" required>
                            <?php foreach ($paymentStatuses as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Pemborong</label>
                    <input type="text" name="contractor_name" list="contractorList" placeholder="Nama pemborong, cth: Moyong">
                </div>

                <div class="form-group">
                    <label>Jumlah (Rp) *</label>
                    <input type="number" name="amount" required placeholder="0" min="1">
                </div>

                <div class="form-group">
                    <label>Keterangan</label>
                    <textarea name="description" rows="2" placeholder="Deskripsi pengeluaran..."></textarea>
                </div>

                <div class="form-group">
                    <label>No. Kwitansi/Nota</label>
                    <input type="text" name="receipt_number" placeholder="Opsional">
                </div>

                <button type="submit" class="btn btn-success" style="width: 100%;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    Simpan Pengeluaran
                </button>
            </form>

            <datalist id="contractorList">
                <?php foreach ($contractorNames as $name): ?>
                    <option value="<?= htmlspecialchars($name) ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <!-- Category Summary -->
            <?php if (!empty($expenseByCategory)): ?>
                <div class="category-summary">
                    <h4>Ringkasan per Kategori</h4>
                    <?php foreach ($expenseByCategory as $cat => $amount): ?>
                        <div class="category-item">
                            <span class="name"><?= $categories[$cat] ?? $cat ?></span>
                            <span class="amount">Rp <?= number_format($amount, 0, ',', '.') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($expenseByContractor)): ?>
                <div class="category-summary">
                    <h4>Total Tagihan per Pemborong</h4>
                    <?php foreach ($expenseByContractor as $contractor => $data): ?>
                        <div class="category-item">
                            <span class="name"><?= htmlspecialchars($contractor) ?></span>
                            <span class="amount">
                                Rp <?= number_format($data['total'], 0, ',', '.') ?>
                                <?php if ($data['unpaid'] > 0): ?>
                                    <small style="color:#ef4444; font-weight:600;">(Belum Lunas Rp <?= number_format($data['unpaid'], 0, ',', '.') ?>)</small>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Edit Pengeluaran -->
<div class="modal-overlay" id="editExpenseModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Pengeluaran</h3>
            <button class="modal-close" onclick="closeModal('editExpenseModal')">&times;</button>
        </div>
        <form id="editExpenseForm" onsubmit="saveEditExpense(event)">
            <input type="hidden" name="expense_id" id="editExpenseId">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Tanggal</label>
                        <input type="date" name="expense_date" id="editExpenseDate" required>
                    </div>
                    <div class="form-group">
                        <label>Kategori</label>
                        <select name="category" id="editExpenseCategory" required>
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Sumber Belanja</label>
                        <select name="purchase_source" id="editExpenseSource">
                            <?php foreach ($purchaseSources as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status Pembayaran</label>
                        <select name="payment_status" id="editExpensePayStatus" required>
                            <?php foreach ($paymentStatuses as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Pemborong</label>
                    <input type="text" name="contractor_name" id="editExpenseContractor" list="contractorList" placeholder="Nama pemborong, cth: Moyong">
                </div>
                <div class="form-group">
                    <label>Jumlah (Rp) *</label>
                    <input type="number" name="amount" id="editExpenseAmount" required placeholder="0" min="1">
                </div>
                <div class="form-group">
                    <label>Keterangan</label>
                    <textarea name="description" id="editExpenseDescription" rows="2" placeholder="Deskripsi pengeluaran..."></textarea>
                </div>
                <div class="form-group">
                    <label>No. Kwitansi/Nota</label>
                    <input type="text" name="receipt_number" id="editExpenseReceipt" placeholder="Opsional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editExpenseModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
    const expensesData = <?= json_encode($expenseMap) ?>;

    function openEditExpenseModal(expenseId) {
        const data = expensesData[expenseId];
        if (!data) return;

        document.getElementById('editExpenseId').value = expenseId;
        document.getElementById('editExpenseDate').value = data.expense_date;
        document.getElementById('editExpenseCategory').value = data.category;
        document.getElementById('editExpenseSource').value = data.purchase_source || 'karimunjawa';
        document.getElementById('editExpensePayStatus').value = data.payment_status || 'belum_lunas';
        document.getElementById('editExpenseContractor').value = data.contractor_name || '';
        document.getElementById('editExpenseAmount').value = data.amount;
        document.getElementById('editExpenseDescription').value = data.description;
        document.getElementById('editExpenseReceipt').value = data.receipt_number;
        document.getElementById('editExpenseModal').classList.add('active');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }

    async function saveEditExpense(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);

        try {
            const response = await fetch('<?= $base_url ?>/api/project-expense-update.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                location.reload();
            } else {
                alert('Error: ' + (result.message || 'Gagal menyimpan perubahan'));
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    }

    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });

    async function saveExpense(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);

        try {
            const response = await fetch('<?= $base_url ?>/api/project-expense-save.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                location.reload();
            } else {
                alert('Error: ' + (result.message || 'Gagal menyimpan'));
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    }

    async function deleteExpense(expenseId) {
        if (!confirm('Yakin ingin menghapus pengeluaran ini?')) return;

        try {
            const response = await fetch('<?= $base_url ?>/api/project-expense-delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    expense_id: expenseId
                })
            });
            const result = await response.json();

            if (result.success) {
                location.reload();
            } else {
                alert('Error: ' + (result.message || 'Gagal menghapus'));
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    }
</script>

<?php include $base_path . '/includes/footer.php'; ?>