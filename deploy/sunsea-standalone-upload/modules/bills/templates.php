<?php
/**
 * BILLS MODULE - Manage Bill Templates
 * Create/edit recurring bill templates
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

$pageTitle = 'Template Tagihan';
$pageSubtitle = 'Kelola template tagihan rutin';

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    try {
        // Check if template has paid records
        $hasPaid = $db->fetchOne("SELECT COUNT(*) as cnt FROM bill_records WHERE template_id = ? AND status = 'paid'", [$delId]);
        if ($hasPaid && $hasPaid['cnt'] > 0) {
            setFlash('error', 'Template tidak bisa dihapus karena sudah ada pembayaran yang tercatat');
        } else {
            $db->delete('bill_records', 'template_id = ?', [$delId]);
            $db->delete('bill_templates', 'id = ?', [$delId]);
            setFlash('success', 'Template tagihan berhasil dihapus');
        }
    } catch (Exception $e) {
        setFlash('error', 'Gagal menghapus: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/bills/templates.php');
}

// Handle toggle active/inactive
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $togId = (int)$_GET['toggle'];
    try {
        $tpl = $db->fetchOne("SELECT is_active FROM bill_templates WHERE id = ?", [$togId]);
        if ($tpl) {
            $newStatus = $tpl['is_active'] ? 0 : 1;
            $db->update('bill_templates', ['is_active' => $newStatus], 'id = :id', ['id' => $togId]);
            setFlash('success', 'Template ' . ($newStatus ? 'diaktifkan' : 'dinonaktifkan'));
        }
    } catch (Exception $e) {
        setFlash('error', 'Gagal: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/bills/templates.php');
}

// Handle form submission (create/edit)
if (isPost()) {
    $editId = (int)getPost('edit_id');
    $data = [
        'bill_name' => sanitize(getPost('bill_name')),
        'bill_category' => sanitize(getPost('bill_category')),
        'vendor_name' => sanitize(getPost('vendor_name')) ?: null,
        'vendor_contact' => sanitize(getPost('vendor_contact')) ?: null,
        'account_number' => sanitize(getPost('account_number')) ?: null,
        'default_amount' => (int)str_replace(['.', ',', ' '], '', getPost('default_amount')),
        'is_fixed_amount' => getPost('is_fixed_amount') ? 1 : 0,
        'recurrence' => sanitize(getPost('recurrence')),
        'due_day' => max(1, min(28, (int)getPost('due_day'))),
        'reminder_days' => max(1, min(30, (int)getPost('reminder_days'))),
        'division_id' => getPost('division_id') ?: null,
        'category_id' => getPost('category_id') ?: null,
        'payment_method' => sanitize(getPost('payment_method')),
        'notes' => sanitize(getPost('notes')) ?: null,
    ];
    
    if (empty($data['bill_name'])) {
        setFlash('error', 'Nama tagihan wajib diisi!');
    } else {
        try {
            if ($editId) {
                $db->update('bill_templates', $data, 'id = :id', ['id' => $editId]);
                setFlash('success', 'Template "' . $data['bill_name'] . '" berhasil diperbarui');
            } else {
                $data['created_by'] = $currentUser['id'] ?? 1;
                $db->insert('bill_templates', $data);
                setFlash('success', 'Template "' . $data['bill_name'] . '" berhasil ditambahkan');
            }
        } catch (Exception $e) {
            setFlash('error', 'Gagal menyimpan: ' . $e->getMessage());
        }
    }
    redirect(BASE_URL . '/modules/bills/templates.php');
}

// Edit mode?
$editData = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = $db->fetchOne("SELECT * FROM bill_templates WHERE id = ?", [(int)$_GET['edit']]);
}

// Fetch all templates
$templates = $db->fetchAll("SELECT bt.*, 
    (SELECT COUNT(*) FROM bill_records WHERE template_id = bt.id) as total_records,
    (SELECT COUNT(*) FROM bill_records WHERE template_id = bt.id AND status = 'paid') as paid_records
    FROM bill_templates bt ORDER BY bt.is_active DESC, bt.bill_name ASC");

// Get divisions & categories for form
$divisions = $db->fetchAll("SELECT id, division_name FROM divisions WHERE is_active = 1 ORDER BY division_name");
$expenseCategories = $db->fetchAll("SELECT id, category_name, division_id FROM categories WHERE category_type = 'expense' ORDER BY category_name");

$categories = [
    'electricity' => ['label' => 'Listrik', 'icon' => '⚡', 'color' => '#f59e0b'],
    'tax' => ['label' => 'Pajak', 'icon' => '🏛️', 'color' => '#8b5cf6'],
    'wifi' => ['label' => 'WiFi/Internet', 'icon' => '📶', 'color' => '#3b82f6'],
    'vehicle' => ['label' => 'Kendaraan', 'icon' => '🏍️', 'color' => '#06b6d4'],
    'po' => ['label' => 'Tagihan PO', 'icon' => '📦', 'color' => '#f97316'],
    'receivable' => ['label' => 'Piutang', 'icon' => '💳', 'color' => '#ec4899'],
    'other' => ['label' => 'Lainnya', 'icon' => '📋', 'color' => '#64748b'],
];

include '../../includes/header.php';
?>

<style>
/* Template styles - reuses common patterns */
.tpl-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 0.85rem;
    margin-bottom: 1.5rem;
}

.tpl-card {
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    border-radius: var(--radius-lg);
    padding: 1rem 1.15rem;
    position: relative;
    transition: var(--transition);
}

.tpl-card:hover {
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
}

.tpl-card.inactive {
    opacity: 0.5;
}

.tpl-card-header {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    margin-bottom: 0.75rem;
}

.tpl-card-icon {
    width: 38px;
    height: 38px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.tpl-card-title {
    flex: 1;
    min-width: 0;
}

.tpl-card-name {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--text-heading);
    margin-bottom: 0.1rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.tpl-card-vendor {
    font-size: 0.68rem;
    color: var(--text-muted);
}

.tpl-card-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
    font-size: 0.72rem;
}

.tpl-detail {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.tpl-detail-label {
    font-size: 0.62rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: var(--text-muted);
    font-weight: 600;
}

.tpl-detail-value {
    font-weight: 600;
    color: var(--text-primary);
}

.tpl-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 0.85rem;
    padding-top: 0.65rem;
    border-top: 1px solid var(--bg-tertiary);
}

.tpl-stats {
    font-size: 0.65rem;
    color: var(--text-muted);
}

.tpl-actions {
    display: flex;
    gap: 0.3rem;
}

.tpl-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 5px;
    border: none;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    font-size: 0;
}

.tpl-action-btn svg { width: 13px; height: 13px; }

.tpl-action-btn.edit { background: var(--bg-tertiary); color: var(--text-muted); }
.tpl-action-btn.edit:hover { background: var(--primary-color); color: white; }

.tpl-action-btn.toggle { background: rgba(245, 158, 11, 0.12); color: #f59e0b; }
.tpl-action-btn.toggle:hover { background: #f59e0b; color: white; }

.tpl-action-btn.delete { background: rgba(239, 68, 68, 0.12); color: #ef4444; }
.tpl-action-btn.delete:hover { background: #ef4444; color: white; }

/* Form */
.tpl-form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.85rem;
}

.tpl-form .full-width { grid-column: 1 / -1; }

.tpl-form .form-group { margin-bottom: 0; }

.tpl-form .form-label {
    display: block;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 0.3rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.tpl-form .form-control {
    width: 100%;
    padding: 0.5rem 0.7rem;
    background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 0.8rem;
    outline: none;
    transition: var(--transition);
}

.tpl-form .form-control:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
}

.tpl-form-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.tpl-form-actions .btn {
    padding: 0.5rem 1.2rem;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
}

@media (max-width: 768px) {
    .tpl-grid { grid-template-columns: 1fr; }
    .tpl-form { grid-template-columns: 1fr; }
}
</style>

<!-- Page Header -->
<div class="card" style="margin-bottom: 1.25rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <h2 style="font-size: 1.05rem; font-weight: 700; color: var(--text-heading); margin: 0;">
                <i data-feather="layers" style="width: 20px; height: 20px; display: inline; vertical-align: -3px;"></i>
                Template Tagihan Rutin
            </h2>
            <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0.2rem 0 0;">Template digunakan untuk generate tagihan otomatis setiap bulan</p>
        </div>
        <a href="<?= BASE_URL ?>/modules/bills/" class="btn" style="background: var(--bg-tertiary); color: var(--text-secondary); padding: 0.45rem 0.8rem; border-radius: var(--radius-md); font-size: 0.75rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
    </div>
</div>

<!-- Add/Edit Form -->
<div class="card" style="margin-bottom: 1.25rem;">
    <h3 style="font-size: 0.85rem; font-weight: 700; color: var(--text-heading); margin: 0 0 1rem;">
        <?= $editData ? '✏️ Edit Template' : '➕ Tambah Template Baru' ?>
    </h3>
    <form method="POST" class="tpl-form">
        <?php if ($editData): ?>
        <input type="hidden" name="edit_id" value="<?= $editData['id'] ?>">
        <?php endif; ?>
        
        <div class="form-group full-width">
            <label class="form-label">Nama Tagihan *</label>
            <input type="text" name="bill_name" class="form-control" placeholder="cth: Listrik PLN, WiFi IndiHome" 
                   value="<?= htmlspecialchars($editData['bill_name'] ?? '') ?>" required>
        </div>
        
        <div class="form-group">
            <label class="form-label">Kategori</label>
            <select name="bill_category" class="form-control">
                <?php foreach ($categories as $catKey => $catInfo): ?>
                <option value="<?= $catKey ?>" <?= ($editData['bill_category'] ?? '') === $catKey ? 'selected' : '' ?>><?= $catInfo['icon'] ?> <?= $catInfo['label'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">Vendor / Penyedia</label>
            <input type="text" name="vendor_name" class="form-control" placeholder="cth: PLN, Telkom" 
                   value="<?= htmlspecialchars($editData['vendor_name'] ?? '') ?>">
        </div>
        
        <div class="form-group">
            <label class="form-label">Kontak Vendor</label>
            <input type="text" name="vendor_contact" class="form-control" placeholder="No HP / Email" 
                   value="<?= htmlspecialchars($editData['vendor_contact'] ?? '') ?>">
        </div>
        
        <div class="form-group">
            <label class="form-label">No. Pelanggan / Meter</label>
            <input type="text" name="account_number" class="form-control" placeholder="No. meter / akun" 
                   value="<?= htmlspecialchars($editData['account_number'] ?? '') ?>">
        </div>
        
        <div class="form-group">
            <label class="form-label">Nominal Default (Rp)</label>
            <input type="text" name="default_amount" class="form-control" placeholder="0"
                   value="<?= $editData ? number_format($editData['default_amount'], 0, ',', '.') : '' ?>"
                   oninput="this.value = this.value.replace(/[^0-9]/g,'').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
        </div>
        
        <div class="form-group">
            <label class="form-label">Nominal Tetap?</label>
            <select name="is_fixed_amount" class="form-control">
                <option value="0" <?= ($editData['is_fixed_amount'] ?? 0) == 0 ? 'selected' : '' ?>>Tidak (berubah)</option>
                <option value="1" <?= ($editData['is_fixed_amount'] ?? 0) == 1 ? 'selected' : '' ?>>Ya (tetap)</option>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">Periode</label>
            <select name="recurrence" class="form-control">
                <option value="monthly" <?= ($editData['recurrence'] ?? '') === 'monthly' ? 'selected' : '' ?>>Bulanan</option>
                <option value="quarterly" <?= ($editData['recurrence'] ?? '') === 'quarterly' ? 'selected' : '' ?>>Triwulan</option>
                <option value="yearly" <?= ($editData['recurrence'] ?? '') === 'yearly' ? 'selected' : '' ?>>Tahunan</option>
                <option value="one-time" <?= ($editData['recurrence'] ?? '') === 'one-time' ? 'selected' : '' ?>>Sekali</option>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">Jatuh Tempo (Tanggal 1-28)</label>
            <input type="number" name="due_day" class="form-control" min="1" max="28"
                   value="<?= $editData['due_day'] ?? 20 ?>">
        </div>

        <div class="form-group">
            <label class="form-label">Pengingat (hari sebelum)</label>
            <input type="number" name="reminder_days" class="form-control" min="1" max="30"
                   value="<?= $editData['reminder_days'] ?? 3 ?>">
        </div>
        
        <div class="form-group">
            <label class="form-label">Divisi</label>
            <select name="division_id" class="form-control">
                <option value="">— Pilih Divisi —</option>
                <?php foreach ($divisions as $div): ?>
                <option value="<?= $div['id'] ?>" <?= ($editData['division_id'] ?? '') == $div['id'] ? 'selected' : '' ?>><?= htmlspecialchars($div['division_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">Kategori Kas</label>
            <select name="category_id" class="form-control">
                <option value="">— Auto (dari jenis tagihan) —</option>
                <?php foreach ($expenseCategories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($editData['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">Metode Bayar Default</label>
            <select name="payment_method" class="form-control">
                <option value="transfer" <?= ($editData['payment_method'] ?? '') === 'transfer' ? 'selected' : '' ?>>Transfer</option>
                <option value="cash" <?= ($editData['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="qr" <?= ($editData['payment_method'] ?? '') === 'qr' ? 'selected' : '' ?>>QR</option>
                <option value="debit" <?= ($editData['payment_method'] ?? '') === 'debit' ? 'selected' : '' ?>>Debit</option>
                <option value="other" <?= ($editData['payment_method'] ?? '') === 'other' ? 'selected' : '' ?>>Lainnya</option>
            </select>
        </div>
        
        <div class="form-group full-width">
            <label class="form-label">Catatan</label>
            <input type="text" name="notes" class="form-control" placeholder="Info tambahan" 
                   value="<?= htmlspecialchars($editData['notes'] ?? '') ?>">
        </div>
        
        <div class="full-width tpl-form-actions">
            <?php if ($editData): ?>
            <a href="<?= BASE_URL ?>/modules/bills/templates.php" class="btn" style="background: var(--bg-tertiary); color: var(--text-secondary);">Batal</a>
            <?php endif; ?>
            <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); color: white;">
                <?= $editData ? '💾 Update Template' : '➕ Simpan Template' ?>
            </button>
        </div>
    </form>
</div>

<!-- Templates Grid -->
<?php if (empty($templates)): ?>
<div class="card">
    <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
        <div style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;">📋</div>
        <div style="font-size: 0.85rem;">Belum ada template tagihan</div>
        <div style="font-size: 0.72rem;">Buat template di atas untuk mulai generate tagihan otomatis</div>
    </div>
</div>
<?php else: ?>
<div class="tpl-grid">
    <?php foreach ($templates as $tpl): 
        $catInfo = $categories[$tpl['bill_category']] ?? $categories['other'];
        $recurrenceMap = ['monthly' => 'Bulanan', 'quarterly' => 'Triwulan', 'yearly' => 'Tahunan', 'one-time' => 'Sekali'];
    ?>
    <div class="tpl-card <?= $tpl['is_active'] ? '' : 'inactive' ?>">
        <div class="tpl-card-header">
            <div class="tpl-card-icon" style="background: <?= $catInfo['color'] ?>20; color: <?= $catInfo['color'] ?>;">
                <?= $catInfo['icon'] ?>
            </div>
            <div class="tpl-card-title">
                <div class="tpl-card-name"><?= htmlspecialchars($tpl['bill_name']) ?></div>
                <div class="tpl-card-vendor"><?= htmlspecialchars($tpl['vendor_name'] ?? '—') ?></div>
            </div>
        </div>
        <div class="tpl-card-body">
            <div class="tpl-detail">
                <span class="tpl-detail-label">Nominal</span>
                <span class="tpl-detail-value"><?= $tpl['default_amount'] > 0 ? formatCurrency($tpl['default_amount']) : 'Variabel' ?></span>
            </div>
            <div class="tpl-detail">
                <span class="tpl-detail-label">Periode</span>
                <span class="tpl-detail-value"><?= $recurrenceMap[$tpl['recurrence']] ?? $tpl['recurrence'] ?></span>
            </div>
            <div class="tpl-detail">
                <span class="tpl-detail-label">Jatuh Tempo</span>
                <span class="tpl-detail-value">Tanggal <?= $tpl['due_day'] ?></span>
            </div>
            <div class="tpl-detail">
                <span class="tpl-detail-label">Pengingat</span>
                <span class="tpl-detail-value"><?= $tpl['reminder_days'] ?> hari sebelum</span>
            </div>
        </div>
        <div class="tpl-card-footer">
            <span class="tpl-stats"><?= $tpl['paid_records'] ?>/<?= $tpl['total_records'] ?> dibayar</span>
            <div class="tpl-actions">
                <a href="<?= BASE_URL ?>/modules/bills/templates.php?edit=<?= $tpl['id'] ?>" class="tpl-action-btn edit" title="Edit">
                    <i data-feather="edit-2"></i>
                </a>
                <a href="<?= BASE_URL ?>/modules/bills/templates.php?toggle=<?= $tpl['id'] ?>" class="tpl-action-btn toggle" title="<?= $tpl['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                    <i data-feather="<?= $tpl['is_active'] ? 'pause' : 'play' ?>"></i>
                </a>
                <a href="<?= BASE_URL ?>/modules/bills/templates.php?delete=<?= $tpl['id'] ?>" class="tpl-action-btn delete" title="Hapus" onclick="return confirm('Hapus template ini?');">
                    <i data-feather="trash-2"></i>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
if (typeof feather !== 'undefined') feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
