<?php

/**
 * MODUL PROJEK - Daftar Projek
 * Menampilkan daftar projek, klik untuk masuk ke detail keuangan
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

// Get all projects with expense totals
try {
    $projects = $db->query("
        SELECT p.*, 
               COALESCE(SUM(pe.amount_idr), 0) as total_spent
        FROM projects p
        LEFT JOIN project_expenses pe ON p.id = pe.project_id
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $projects = $db->query("SELECT * FROM projects ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$totalProjects = count($projects);
$totalBudget = 0;
$totalSpent = 0;
$activeProjects = 0;

foreach ($projects as $proj) {
    $totalBudget += $proj['budget'] ?? 0;
    $totalSpent += $proj['total_spent'] ?? $proj['total_expenses'] ?? 0;
    if (($proj['status'] ?? 'planning') === 'active') {
        $activeProjects++;
    }
}

$pageTitle = 'Daftar Projek';
include $base_path . '/includes/header.php';
?>

<style>
    .project-page {
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
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--secondary-color);
        display: flex;
        align-items: center;
        gap: 0.65rem;
    }

    .page-header h1 .title-icon {
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

    .page-header h1 .title-icon svg {
        width: 18px;
        height: 18px;
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

    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
    }

    /* Summary Cards */
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    .summary-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 0.65rem 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        box-shadow: var(--shadow-sm);
        transition: all 0.2s;
    }

    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .summary-card .icon {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .summary-card .icon svg {
        width: 14px;
        height: 14px;
    }

    .summary-card.total .icon {
        background: rgba(139, 92, 246, 0.15);
        color: #8b5cf6;
    }

    .summary-card.active .icon {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    .summary-card.budget .icon {
        background: rgba(30, 64, 175, 0.15);
        color: var(--secondary-color);
    }

    .summary-card.spent .icon {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .summary-card.remaining .icon {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    .summary-card .label {
        font-size: 0.62rem;
        color: var(--text-muted);
        margin-bottom: 0.15rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .summary-card .value {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .summary-card.budget .value {
        color: var(--secondary-color);
    }

    .summary-card.spent .value {
        color: #ef4444;
    }

    .summary-card.remaining .value {
        color: #10b981;
    }

    /* Project Grid */
    .project-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 0.9rem;
    }

    .project-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-top: 3px solid var(--border-color);
        border-radius: 8px;
        padding: 0.75rem 0.85rem;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: block;
        box-shadow: var(--shadow-sm);
    }

    .project-card:hover {
        border-color: var(--primary-color);
        border-top-color: var(--primary-color);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }

    .project-card.status-active {
        border-top-color: #10b981;
    }

    .project-card.status-planning {
        border-top-color: #f59e0b;
    }

    .project-card.status-completed {
        border-top-color: var(--secondary-color);
    }

    .project-card.status-cancelled {
        border-top-color: #ef4444;
    }

    .project-card .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.5rem;
    }

    .project-card .name {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--secondary-color);
        margin-bottom: 0.15rem;
    }

    .project-card .description {
        font-size: 0.72rem;
        color: var(--text-muted);
        line-height: 1.35;
    }

    .status-badge {
        padding: 0.22rem 0.55rem;
        border-radius: 20px;
        font-size: 0.62rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-badge.active {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    .status-badge.planning {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
    }

    .status-badge.completed {
        background: rgba(30, 64, 175, 0.15);
        color: var(--secondary-color);
    }

    .status-badge.cancelled {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .project-card .stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
        margin-top: 0.6rem;
        padding-top: 0.6rem;
        border-top: 1px solid var(--border-color);
    }

    .project-card .stat {
        text-align: center;
    }

    .project-card .stat-value {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .project-card .stat-label {
        font-size: 0.58rem;
        color: var(--text-muted);
        text-transform: uppercase;
        margin-top: 0.15rem;
    }

    .project-card .stat.budget .stat-value {
        color: var(--secondary-color);
    }

    .project-card .stat.spent .stat-value {
        color: #ef4444;
    }

    .project-card .stat.remaining .stat-value {
        color: #10b981;
    }

    /* Progress Bar */
    .progress-container {
        margin-top: 0.6rem;
    }

    .progress-bar {
        height: 5px;
        background: var(--border-color);
        border-radius: 3px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #10b981, #059669);
        border-radius: 3px;
        transition: width 0.3s ease;
    }

    .progress-fill.warning {
        background: linear-gradient(90deg, #f59e0b, #d97706);
    }

    .progress-fill.danger {
        background: linear-gradient(90deg, #ef4444, #dc2626);
    }

    .progress-label {
        display: flex;
        justify-content: space-between;
        font-size: 0.68rem;
        color: var(--text-muted);
        margin-top: 0.4rem;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background: var(--bg-secondary);
        border: 2px dashed var(--border-color);
        border-radius: 12px;
    }

    .empty-state svg {
        width: 64px;
        height: 64px;
        color: var(--text-muted);
        margin-bottom: 1rem;
    }

    .empty-state h3 {
        font-size: 1.1rem;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .empty-state p {
        color: var(--text-muted);
        margin-bottom: 1.5rem;
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
        border-radius: 16px;
        width: 100%;
        max-width: 550px;
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
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

    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-group label {
        display: block;
        font-size: 0.85rem;
        font-weight: 500;
        color: var(--text-secondary);
        margin-bottom: 0.5rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 0.9rem;
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
        gap: 1rem;
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
    }
</style>

<div class="project-page">
    <!-- Header -->
    <div class="page-header">
        <h1>
            <span class="title-icon">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                </svg>
            </span>
            Daftar Projek
        </h1>
        <button class="btn btn-primary" onclick="openAddProjectModal()">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M12 5v14M5 12h14" />
            </svg>
            Projek Baru
        </button>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card total">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                </svg>
            </div>
            <div>
                <div class="label">Total Projek</div>
                <div class="value"><?= $totalProjects ?></div>
            </div>
        </div>
        <div class="summary-card active">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                    <polyline points="22,4 12,14.01 9,11.01" />
                </svg>
            </div>
            <div>
                <div class="label">Projek Aktif</div>
                <div class="value"><?= $activeProjects ?></div>
            </div>
        </div>
        <div class="summary-card budget">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2" />
                    <path d="M16 3v4M8 3v4" />
                </svg>
            </div>
            <div>
                <div class="label">Total Budget</div>
                <div class="value">Rp <?= number_format($totalBudget / 1000000, 1) ?>jt</div>
            </div>
        </div>
        <div class="summary-card spent">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                </svg>
            </div>
            <div>
                <div class="label">Total Terpakai</div>
                <div class="value">Rp <?= number_format($totalSpent / 1000000, 1) ?>jt</div>
            </div>
        </div>
        <div class="summary-card remaining">
            <div class="icon">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
                </svg>
            </div>
            <div>
                <div class="label">Sisa Budget</div>
                <div class="value">Rp <?= number_format(($totalBudget - $totalSpent) / 1000000, 1) ?>jt</div>
            </div>
        </div>
    </div>

    <!-- Project Grid -->
    <?php if (empty($projects)): ?>
        <div class="empty-state">
            <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
            </svg>
            <h3>Belum Ada Projek</h3>
            <p>Mulai dengan membuat projek pertama Anda</p>
            <button class="btn btn-primary" onclick="openAddProjectModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Buat Projek Baru
            </button>
        </div>
    <?php else: ?>
        <div class="project-grid">
            <?php foreach ($projects as $project):
                $budget = $project['budget'] ?? 0;
                $spent = $project['total_spent'] ?? $project['total_expenses'] ?? 0;
                $remaining = $budget - $spent;
                $percentage = $budget > 0 ? min(100, ($spent / $budget) * 100) : 0;
                $progressClass = $percentage > 90 ? 'danger' : ($percentage > 70 ? 'warning' : '');
                $status = $project['status'] ?? 'planning';
            ?>
                <a href="detail.php?id=<?= $project['id'] ?>" class="project-card status-<?= htmlspecialchars($status) ?>">
                    <div class="header">
                        <div>
                            <div class="name"><?= htmlspecialchars($project['name']) ?></div>
                            <div class="description"><?= htmlspecialchars($project['description'] ?? 'Tidak ada deskripsi') ?></div>
                        </div>
                        <span class="status-badge <?= $status ?>"><?= ucfirst($status) ?></span>
                    </div>

                    <div class="stats">
                        <div class="stat budget">
                            <div class="stat-value">Rp <?= number_format($budget / 1000000, 1) ?>jt</div>
                            <div class="stat-label">Budget</div>
                        </div>
                        <div class="stat spent">
                            <div class="stat-value">Rp <?= number_format($spent / 1000000, 1) ?>jt</div>
                            <div class="stat-label">Terpakai</div>
                        </div>
                        <div class="stat remaining">
                            <div class="stat-value">Rp <?= number_format($remaining / 1000000, 1) ?>jt</div>
                            <div class="stat-label">Sisa</div>
                        </div>
                    </div>

                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill <?= $progressClass ?>" style="width: <?= $percentage ?>%"></div>
                        </div>
                        <div class="progress-label">
                            <span><?= number_format($percentage, 1) ?>% terpakai</span>
                            <span><?= $project['start_date'] ? date('d M Y', strtotime($project['start_date'])) : '-' ?></span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Add Project -->
<div class="modal-overlay" id="addProjectModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Buat Projek Baru</h3>
            <button class="modal-close" onclick="closeModal('addProjectModal')">&times;</button>
        </div>
        <form id="addProjectForm" onsubmit="saveProject(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Projek *</label>
                    <input type="text" name="name" required placeholder="Nama projek">
                </div>
                <div class="form-group">
                    <label>Deskripsi</label>
                    <textarea name="description" rows="2" placeholder="Deskripsi singkat projek..."></textarea>
                </div>
                <div class="form-group">
                    <label>Budget (Rp) *</label>
                    <input type="number" name="budget" required placeholder="0" min="0">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Tanggal Mulai</label>
                        <input type="date" name="start_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Target Selesai</label>
                        <input type="date" name="end_date">
                    </div>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="planning">Planning</option>
                        <option value="active" selected>Active</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addProjectModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Projek</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddProjectModal() {
        document.getElementById('addProjectForm').reset();
        document.getElementById('addProjectModal').classList.add('active');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }

    async function saveProject(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);

        try {
            const response = await fetch('<?= $base_url ?>/api/project-save.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                alert('Projek berhasil dibuat');
                location.reload();
            } else {
                alert('Error: ' + (result.message || 'Gagal menyimpan'));
            }
        } catch (error) {
            alert('Error: ' + error.message);
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