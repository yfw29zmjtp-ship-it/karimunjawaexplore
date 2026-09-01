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

// Get expenses for this project
try {
    $stmt = $db->prepare("
        SELECT * FROM project_expenses 
        WHERE project_id = ? 
        ORDER BY expense_date DESC, created_at DESC
    ");
    $stmt->execute([$projectId]);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $expenses = [];
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

// Calculate totals
$totalExpenses = 0;
$expenseByCategory = [];

foreach ($expenses as $exp) {
    $amount = $exp['amount_idr'] ?? $exp['amount'] ?? 0;
    $totalExpenses += $amount;
    
    $cat = $exp['category'] ?? 'other';
    if (!isset($expenseByCategory[$cat])) {
        $expenseByCategory[$cat] = 0;
    }
    $expenseByCategory[$cat] += $amount;
}

$budget = $project['budget'] ?? 0;
$remaining = $budget - $totalExpenses;
$percentage = $budget > 0 ? min(100, ($totalExpenses / $budget) * 100) : 0;

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
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.page-header .title-section h1 {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}

.page-header .breadcrumb {
    font-size: 0.85rem;
    color: var(--text-muted);
}

.page-header .breadcrumb a {
    color: #6366f1;
    text-decoration: none;
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
    border-radius: 12px;
    padding: 1.25rem;
}

.dash-card .icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
}

.dash-card .icon svg {
    width: 20px;
    height: 20px;
}

.dash-card.budget .icon {
    background: rgba(99, 102, 241, 0.15);
    color: #6366f1;
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
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
}

.dash-card .value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}

.dash-card.budget .value { color: #6366f1; }
.dash-card.spent .value { color: #ef4444; }
.dash-card.remaining .value { color: #10b981; }

/* Progress Section */
.progress-section {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.progress-header h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
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
    border-radius: 12px;
    padding: 1.5rem;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.section-header h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.expense-table {
    width: 100%;
    border-collapse: collapse;
}

.expense-table th,
.expense-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.expense-table th {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.expense-table td {
    font-size: 0.85rem;
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
    font-size: 0.7rem;
    font-weight: 500;
    background: rgba(99, 102, 241, 0.1);
    color: #6366f1;
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

/* Quick Add Form */
.quick-add-section {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
}

.quick-add-section h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
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
    padding: 0.65rem 0.9rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.85rem;
    background: var(--bg-primary);
    color: var(--text-primary);
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
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
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.75rem;
}

.category-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    font-size: 0.8rem;
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
</style>

<div class="detail-page">
    <!-- Header -->
    <div class="page-header">
        <div class="title-section">
            <div class="breadcrumb">
                <a href="index.php">Projek</a> / Keuangan
            </div>
            <h1>
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                </svg>
                <?= htmlspecialchars($project['name']) ?>
            </h1>
        </div>
        <div class="header-actions">
            <a href="index.php" class="btn btn-outline">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
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
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                    <path d="M16 3v4M8 3v4"/>
                </svg>
            </div>
            <div class="label">Budget Projek</div>
            <div class="value">Rp <?= number_format($budget, 0, ',', '.') ?></div>
        </div>
        
        <div class="dash-card spent">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </div>
            <div class="label">Total Pengeluaran</div>
            <div class="value">Rp <?= number_format($totalExpenses, 0, ',', '.') ?></div>
        </div>
        
        <div class="dash-card remaining">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
                </svg>
            </div>
            <div class="label">Sisa Budget</div>
            <div class="value">Rp <?= number_format($remaining, 0, ',', '.') ?></div>
        </div>
        
        <div class="dash-card transactions">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14,2 14,8 20,8"/>
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
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14,2 14,8 20,8"/>
                    </svg>
                    Riwayat Pengeluaran
                </h3>
            </div>
            
            <?php if (empty($expenses)): ?>
                <div class="empty-state">
                    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    </svg>
                    <p>Belum ada pengeluaran tercatat</p>
                </div>
            <?php else: ?>
                <table class="expense-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Kategori</th>
                            <th>Keterangan</th>
                            <th>Jumlah</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                        <tr>
                            <td class="date-col"><?= date('d M Y', strtotime($exp['expense_date'] ?? $exp['created_at'])) ?></td>
                            <td>
                                <span class="category-badge"><?= $categories[$exp['category'] ?? 'other'] ?? $exp['category'] ?></span>
                            </td>
                            <td><?= htmlspecialchars($exp['description'] ?? '-') ?></td>
                            <td class="amount-col">Rp <?= number_format($exp['amount_idr'] ?? $exp['amount'] ?? 0, 0, ',', '.') ?></td>
                            <td>
                                <button class="action-btn-sm" onclick="deleteExpense(<?= $exp['id'] ?>)" title="Hapus">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <polyline points="3,6 5,6 21,6"/>
                                        <path d="M19,6v14a2,2,0,0,1-2,2H7a2,2,0,0,1-2-2V6m3,0V4a2,2,0,0,1,2-2h4a2,2,0,0,1,2,2v2"/>
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
                    <path d="M12 5v14M5 12h14"/>
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
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Simpan Pengeluaran
                </button>
            </form>
            
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
        </div>
    </div>
</div>

<script>
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
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ expense_id: expenseId })
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
