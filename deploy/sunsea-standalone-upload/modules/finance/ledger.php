<?php
/**
 * MODUL FINANCE - LEDGER PENGELUARAN PROJECT
 * Catat dan lihat riwayat pengeluaran per project (Buku Kas)
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

// Get project ID from URL or POST
$projectId = $_GET['project_id'] ?? $_POST['project_id'] ?? null;

// Get all projects for selection
try {
    $projects = $db->query("SELECT id, name, budget FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $projects = [];
}

// If project selected, get details
$selectedProject = null;
$expenses = [];
$totalExpenses = 0;
$expenseCount = 0;

if ($projectId) {
    try {
        // Get project details
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $selectedProject = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($selectedProject) {
            // Get expenses for this project
            $expStmt = $db->prepare("
                SELECT pe.*, pec.category_name
                FROM project_expenses pe
                LEFT JOIN project_expense_categories pec ON pe.expense_category_id = pec.id
                WHERE pe.project_id = ?
                ORDER BY pe.expense_date DESC, pe.id DESC
            ");
            $expStmt->execute([$projectId]);
            $expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate totals
            foreach ($expenses as $exp) {
                $totalExpenses += $exp['amount_idr'] ?? $exp['amount'] ?? 0;
                $expenseCount++;
            }
        }
    } catch (Exception $e) {
        // Error loading project
    }
}

// Get expense categories
try {
    $categories = $db->query("SELECT * FROM project_expense_categories WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

$pageTitle = 'Buku Kas Project';
include '../../includes/header.php';
?>

<style>
.ledger-page {
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
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.btn-success:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* Project Selector */
.project-selector {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.selector-group {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
}

.form-group {
    flex: 1;
}

.form-group label {
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-group select {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.9rem;
    background: var(--bg-primary);
    color: var(--text-primary);
    cursor: pointer;
}

.form-group select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

/* Expense Form */
.expense-form {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.form-group input, .form-group textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.9rem;
    background: var(--bg-primary);
    color: var(--text-primary);
}

.form-group input:focus, .form-group textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
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
    display: flex;
    justify-content: space-between;
    align-items: center;
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
}

.table td {
    padding: 1rem;
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

/* Summary */
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
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
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
    border-radius: 16px;
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--text-muted);
    cursor: pointer;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.btn-secondary {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    cursor: pointer;
}
</style>

<div class="ledger-page">
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
                <path d="M9 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4m0 0h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-10m0 0V2a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2m-6 4v10m4-10v10"/>
            </svg>
            Buku Kas Project
        </h1>
        <button class="btn btn-success" onclick="openExpenseModal()">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M12 5v14M5 12h14"/>
            </svg>
            + Tambah Pengeluaran
        </button>
    </div>

    <!-- Project Selector -->
    <div class="project-selector">
        <form method="GET" action="" onsubmit="return this.project_id.value">
            <div class="selector-group">
                <div class="form-group" style="flex: 2;">
                    <label>Pilih Project</label>
                    <select name="project_id" id="projectSelect" onchange="this.form.submit()" required>
                        <option value="">-- Pilih Project untuk Melihat Buku Kas --</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?= $proj['id'] ?>" <?= $projectId == $proj['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($proj['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>

    <?php if ($selectedProject): ?>
        <!-- Project Info & Summary -->
        <div class="summary-section">
            <div class="summary-box">
                <div class="label">Budget Project</div>
                <div class="value">Rp <?= number_format($selectedProject['budget'] ?? 0, 0, ',', '.') ?></div>
            </div>
            <div class="summary-box">
                <div class="label">Total Pengeluaran</div>
                <div class="value" style="color: #f43f5e;">-Rp <?= number_format($totalExpenses, 0, ',', '.') ?></div>
            </div>
            <div class="summary-box">
                <div class="label">Sisa Budget</div>
                <div class="value" style="color: #10b981;">Rp <?= number_format(($selectedProject['budget'] ?? 0) - $totalExpenses, 0, ',', '.') ?></div>
            </div>
            <div class="summary-box">
                <div class="label">Total Transaksi</div>
                <div class="value"><?= $expenseCount ?></div>
            </div>
        </div>

        <!-- Expense Form -->
        <div class="expense-form">
            <h3 style="margin-bottom: 1.5rem; font-size: 1rem; color: var(--text-primary);">Catat Pengeluaran Baru</h3>
            <form id="expenseForm" onsubmit="saveExpense(event)">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Tanggal Pengeluaran</label>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Kategori</label>
                        <select name="expense_category_id" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Jumlah Pengeluaran (Rp)</label>
                        <input type="number" name="amount_idr" placeholder="0" min="1" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>No. Referensi / Invoice</label>
                        <input type="text" name="reference_no" placeholder="INV-001">
                    </div>
                </div>

                <div class="form-group">
                    <label>Keterangan</label>
                    <textarea name="description" rows="2" placeholder="Deskripsi pengeluaran..."></textarea>
                </div>

                <div class="form-actions">
                    <button type="reset" class="btn btn-secondary">Batal</button>
                    <button type="submit" class="btn btn-success">Simpan Pengeluaran</button>
                </div>
            </form>
        </div>

        <!-- Expense Ledger Table -->
        <div class="expense-table">
            <div class="table-header">
                <h3>Riwayat Pengeluaran</h3>
            </div>

            <?php if (empty($expenses)): ?>
                <div class="empty-state">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M9 12h6m-6 4h6m2-13H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/>
                    </svg>
                    <p>Belum ada pengeluaran yang tercatat untuk project ini</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Kategori</th>
                            <th>Keterangan</th>
                            <th>No. Referensi</th>
                            <th style="text-align: right;">Jumlah</th>
                            <th style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
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
                                <td style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($exp['reference_no'] ?? '-') ?></td>
                                <td class="amount-cell">-Rp <?= number_format($exp['amount_idr'] ?? $exp['amount'] ?? 0, 0, ',', '.') ?></td>
                                <td style="text-align: center;">
                                    <button class="btn btn-sm btn-outline" onclick="deleteExpense(<?= $exp['id'] ?>)">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6"/>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                            <line x1="10" y1="11" x2="10" y2="17"/>
                                            <line x1="14" y1="11" x2="14" y2="17"/>
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- No Project Selected -->
        <div class="empty-state" style="margin-top: 4rem;">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M5 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5z"/>
                <polyline points="10 8 10 14 14 11"/>
            </svg>
            <p>Pilih project untuk menampilkan buku kas</p>
        </div>
    <?php endif; ?>
</div>

<!-- Modal -->
<div class="modal-overlay" id="expenseModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Tambah Pengeluaran</h3>
            <button class="modal-close" onclick="closeModal('expenseModal')">&times;</button>
        </div>
        <form id="modalExpenseForm" onsubmit="saveExpense(event)">
            <div class="modal-body">
                <!-- Form content here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('expenseModal')">Batal</button>
                <button type="submit" class="btn btn-success">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openExpenseModal() {
    <?php if (!$projectId): ?>
        alert('Pilih project terlebih dahulu');
        document.getElementById('projectSelect').focus();
    <?php else: ?>
        // Form is inline, just scroll to it
        document.querySelector('.expense-form').scrollIntoView({ behavior: 'smooth' });
    <?php endif; ?>
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

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
            alert('Pengeluaran berhasil dicatat');
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
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'expense_id=' + expenseId
        });
        const result = await response.json();
        
        if (result.success) {
            alert('Pengeluaran berhasil dihapus');
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Gagal menghapus'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
