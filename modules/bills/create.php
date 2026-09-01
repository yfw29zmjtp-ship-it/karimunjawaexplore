<?php
/**
 * BILLS MODULE - Create Manual Bill Record
 * Add a one-off or manual bill that doesn't come from templates
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();
require_once __DIR__ . '/auto-migrate.php';

$pageTitle = 'Tambah Tagihan';
$pageSubtitle = 'Input tagihan baru secara manual';

// Get templates for quick-select
$templates = $db->fetchAll("SELECT id, bill_name, bill_category, default_amount, due_day, payment_method, division_id, category_id FROM bill_templates WHERE is_active = 1 ORDER BY bill_name");

// Handle form submit
if (isPost()) {
    $templateId = (int)getPost('template_id');
    $amount = (int)str_replace(['.', ',', ' '], '', getPost('amount'));
    $dueDate = sanitize(getPost('due_date'));
    $billPeriod = sanitize(getPost('bill_period'));
    $notes = sanitize(getPost('notes'));
    
    if (!$templateId || !$amount || !$dueDate || !$billPeriod) {
        setFlash('error', 'Lengkapi semua field yang wajib!');
    } else {
        try {
            // Check for duplicate
            $exists = $db->fetchOne("SELECT id FROM bill_records WHERE template_id = ? AND bill_period = ?", [$templateId, $billPeriod]);
            if ($exists) {
                setFlash('error', 'Tagihan untuk template dan periode ini sudah ada!');
            } else {
                $db->insert('bill_records', [
                    'template_id' => $templateId,
                    'bill_period' => $billPeriod,
                    'amount' => $amount,
                    'due_date' => $dueDate,
                    'status' => 'pending',
                    'notes' => $notes
                ]);
                setFlash('success', 'Tagihan berhasil ditambahkan');
                redirect(BASE_URL . '/modules/bills/');
            }
        } catch (Exception $e) {
            setFlash('error', 'Gagal menyimpan: ' . $e->getMessage());
        }
    }
}

$categories = [
    'electricity' => ['label' => 'Listrik', 'icon' => '⚡'],
    'tax' => ['label' => 'Pajak', 'icon' => '🏛️'],
    'wifi' => ['label' => 'WiFi', 'icon' => '📶'],
    'vehicle' => ['label' => 'Kendaraan', 'icon' => '🏍️'],
    'po' => ['label' => 'PO', 'icon' => '📦'],
    'receivable' => ['label' => 'Piutang', 'icon' => '💳'],
    'other' => ['label' => 'Lainnya', 'icon' => '📋'],
];

include '../../includes/header.php';
?>

<style>
.create-bill-form {
    max-width: 540px;
}

.create-bill-form .form-group {
    margin-bottom: 0.9rem;
}

.create-bill-form .form-label {
    display: block;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 0.3rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.create-bill-form .form-control {
    width: 100%;
    padding: 0.55rem 0.75rem;
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 0.82rem;
    outline: none;
    transition: var(--transition);
}

.create-bill-form .form-control:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
}

.create-bill-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.form-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1.25rem;
}

.form-actions .btn {
    padding: 0.55rem 1.25rem;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

/* Template quick-select */
.tpl-select-info {
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    border-radius: var(--radius-md);
    padding: 0.65rem 0.85rem;
    margin-top: 0.5rem;
    font-size: 0.75rem;
    color: var(--text-secondary);
    display: none;
}

.tpl-select-info.visible { display: block; }

@media (max-width: 768px) {
    .create-bill-form .form-row { grid-template-columns: 1fr; }
}
</style>

<div class="card" style="margin-bottom: 1.25rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <h2 style="font-size: 1.05rem; font-weight: 700; color: var(--text-heading); margin: 0;">
                <i data-feather="plus-circle" style="width: 20px; height: 20px; display: inline; vertical-align: -3px;"></i>
                Tambah Tagihan Manual
            </h2>
            <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0.2rem 0 0;">Buat tagihan baru berdasarkan template yang sudah ada</p>
        </div>
        <a href="<?= BASE_URL ?>/modules/bills/" class="btn" style="background: var(--bg-tertiary); color: var(--text-secondary); padding: 0.45rem 0.8rem; border-radius: var(--radius-md); font-size: 0.75rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
    </div>
</div>

<div class="card">
    <form method="POST" class="create-bill-form">
        <div class="form-group">
            <label class="form-label">Template Tagihan *</label>
            <select name="template_id" id="templateSelect" class="form-control" required onchange="onTemplateChange()">
                <option value="">— Pilih Template —</option>
                <?php foreach ($templates as $tpl): 
                    $catI = $categories[$tpl['bill_category']] ?? $categories['other'];
                ?>
                <option value="<?= $tpl['id'] ?>" 
                        data-amount="<?= $tpl['default_amount'] ?>" 
                        data-due="<?= $tpl['due_day'] ?>"
                        data-payment="<?= $tpl['payment_method'] ?>">
                    <?= $catI['icon'] ?> <?= htmlspecialchars($tpl['bill_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="tpl-select-info" id="tplInfo">
                <span id="tplInfoText"></span>
            </div>
            <?php if (empty($templates)): ?>
            <p style="font-size: 0.72rem; color: var(--warning); margin-top: 0.4rem;">
                ⚠️ Belum ada template. <a href="<?= BASE_URL ?>/modules/bills/templates.php" style="color: var(--primary-color);">Buat template dulu</a>
            </p>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Periode (Bulan) *</label>
                <input type="month" name="bill_period" id="billPeriod" class="form-control" value="<?= date('Y-m') ?>" required onchange="updateDueDate()">
            </div>
            <div class="form-group">
                <label class="form-label">Jatuh Tempo *</label>
                <input type="date" name="due_date" id="dueDate" class="form-control" required>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Jumlah Tagihan (Rp) *</label>
            <input type="text" name="amount" id="billAmount" class="form-control" placeholder="0" required
                   oninput="this.value = this.value.replace(/[^0-9]/g,'').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
        </div>
        
        <div class="form-group">
            <label class="form-label">Catatan (opsional)</label>
            <input type="text" name="notes" class="form-control" placeholder="Keterangan tambahan">
        </div>
        
        <div class="form-actions">
            <a href="<?= BASE_URL ?>/modules/bills/" class="btn" style="background: var(--bg-tertiary); color: var(--text-secondary);">Batal</a>
            <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); color: white;">
                <i data-feather="save" style="width: 15px; height: 15px;"></i> Simpan Tagihan
            </button>
        </div>
    </form>
</div>

<script>
function onTemplateChange() {
    const sel = document.getElementById('templateSelect');
    const opt = sel.options[sel.selectedIndex];
    const info = document.getElementById('tplInfo');
    
    if (sel.value) {
        const amount = parseInt(opt.dataset.amount);
        const due = parseInt(opt.dataset.due);
        
        if (amount > 0) {
            document.getElementById('billAmount').value = amount.toLocaleString('id-ID').replace(/,/g, '.');
        }
        
        info.innerHTML = '<span>Jatuh tempo tanggal <strong>' + due + '</strong> setiap bulan · Metode: <strong>' + opt.dataset.payment + '</strong></span>';
        info.classList.add('visible');
        
        // Auto-set due date
        updateDueDate(due);
    } else {
        info.classList.remove('visible');
    }
}

function updateDueDate(forcedDay) {
    const period = document.getElementById('billPeriod').value;
    if (!period) return;
    
    const sel = document.getElementById('templateSelect');
    const opt = sel.options[sel.selectedIndex];
    const day = forcedDay || (opt && opt.dataset.due ? parseInt(opt.dataset.due) : 20);
    
    const [year, month] = period.split('-');
    const maxDay = new Date(year, month, 0).getDate();
    const actualDay = Math.min(day, maxDay);
    
    document.getElementById('dueDate').value = year + '-' + month + '-' + String(actualDay).padStart(2, '0');
}

// Init
updateDueDate(20);
if (typeof feather !== 'undefined') feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
