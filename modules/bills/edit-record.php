<?php
/**
 * BILLS MODULE - Edit Bill Record
 * Edit amount, due date, notes for a specific bill record
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Prevent browser caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();
require_once __DIR__ . '/auto-migrate.php';

$pageTitle = 'Edit Tagihan';
$pageSubtitle = 'Ubah detail tagihan';

$recordId = (int)getGet('id');
if (!$recordId) {
    setFlash('error', 'ID tagihan tidak valid');
    redirect(BASE_URL . '/modules/bills/');
}

// Fetch record with template info
$record = $db->fetchOne(
    "SELECT br.*, bt.bill_name, bt.bill_category, bt.vendor_name, bt.is_fixed_amount
     FROM bill_records br 
     JOIN bill_templates bt ON br.template_id = bt.id 
     WHERE br.id = ?", [$recordId]
);

if (!$record) {
    setFlash('error', 'Tagihan tidak ditemukan');
    redirect(BASE_URL . '/modules/bills/');
}

// Handle delete
if (isset($_GET['delete'])) {
    if ($record['status'] === 'paid') {
        setFlash('error', 'Tidak bisa menghapus tagihan yang sudah dibayar');
    } else {
        try {
            $db->delete('bill_records', 'id = :id', ['id' => $recordId]);
            setFlash('success', 'Tagihan berhasil dihapus');
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus: ' . $e->getMessage());
        }
    }
    redirect(BASE_URL . '/modules/bills/');
}

// Handle cancel payment (un-pay)
if (isset($_GET['unpay']) && $record['status'] === 'paid') {
    try {
        $db->beginTransaction();
        
        // Delete cashbook entry if exists
        if ($record['cashbook_id']) {
            $db->delete('cash_book', 'id = :id', ['id' => $record['cashbook_id']]);
        }
        
        // Revert bill to pending/overdue
        $newStatus = (strtotime($record['due_date']) < time()) ? 'overdue' : 'pending';
        $db->update('bill_records', [
            'status' => $newStatus,
            'paid_date' => null,
            'paid_amount' => null,
            'payment_method' => null,
            'cashbook_id' => null,
            'paid_by' => null,
        ], 'id = :id', ['id' => $recordId]);
        
        $db->commit();
        setFlash('success', 'Pembayaran tagihan "' . $record['bill_name'] . '" berhasil dibatalkan');
    } catch (Exception $e) {
        $db->rollback();
        setFlash('error', 'Gagal membatalkan: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/bills/');
}

// Handle form update
if (isPost()) {
    $data = [
        'amount' => (int)str_replace(['.', ',', ' '], '', getPost('amount')),
        'due_date' => sanitize(getPost('due_date')),
        'notes' => sanitize(getPost('notes')) ?: null,
    ];
    
    // Allow status change only for non-paid bills
    if ($record['status'] !== 'paid') {
        $newStatus = sanitize(getPost('status'));
        if (in_array($newStatus, ['pending', 'overdue', 'cancelled'])) {
            $data['status'] = $newStatus;
        }
    }
    
    if (!$data['amount'] || !$data['due_date']) {
        setFlash('error', 'Nominal dan jatuh tempo wajib diisi');
    } else {
        try {
            $db->update('bill_records', $data, 'id = :id', ['id' => $recordId]);
            setFlash('success', 'Tagihan "' . $record['bill_name'] . '" berhasil diperbarui');
            redirect(BASE_URL . '/modules/bills/');
        } catch (Exception $e) {
            setFlash('error', 'Gagal menyimpan: ' . $e->getMessage());
        }
    }
}

$categories = [
    'electricity' => ['label' => 'Listrik', 'icon' => '⚡', 'color' => '#f59e0b'],
    'tax' => ['label' => 'Pajak', 'icon' => '🏛️', 'color' => '#8b5cf6'],
    'wifi' => ['label' => 'WiFi', 'icon' => '📶', 'color' => '#3b82f6'],
    'vehicle' => ['label' => 'Kendaraan', 'icon' => '🏍️', 'color' => '#06b6d4'],
    'po' => ['label' => 'PO', 'icon' => '📦', 'color' => '#f97316'],
    'receivable' => ['label' => 'Piutang', 'icon' => '💳', 'color' => '#ec4899'],
    'other' => ['label' => 'Lainnya', 'icon' => '📋', 'color' => '#64748b'],
];

$catInfo = $categories[$record['bill_category']] ?? $categories['other'];

include '../../includes/header.php';
?>

<style>
.edit-bill-form {
    max-width: 540px;
}
.edit-bill-form .form-group { margin-bottom: 0.9rem; }
.edit-bill-form .form-label {
    display: block; font-size: 0.72rem; font-weight: 600; color: var(--text-secondary);
    margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.3px;
}
.edit-bill-form .form-control {
    width: 100%; padding: 0.55rem 0.75rem; background: var(--bg-secondary);
    border: 1px solid var(--bg-tertiary); border-radius: var(--radius-md);
    color: var(--text-primary); font-size: 0.82rem; outline: none; transition: var(--transition);
}
.edit-bill-form .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(99,102,241,0.15); }
.edit-bill-form .form-control:disabled { opacity: 0.6; cursor: not-allowed; }
.edit-bill-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.form-actions { display: flex; gap: 0.5rem; margin-top: 1.25rem; }
.form-actions .btn {
    padding: 0.55rem 1.25rem; border: none; border-radius: var(--radius-md);
    font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: var(--transition);
    text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem;
}

.bill-detail-card {
    background: var(--bg-secondary); border: 1px solid var(--bg-tertiary);
    border-radius: var(--radius-lg); padding: 1rem; margin-bottom: 1.25rem;
    display: flex; align-items: center; gap: 0.75rem;
}
.bill-detail-icon {
    width: 42px; height: 42px; border-radius: var(--radius-md);
    display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;
}
.bill-detail-name { font-size: 0.9rem; font-weight: 700; color: var(--text-heading); }
.bill-detail-meta { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.1rem; }

@media (max-width: 768px) { .edit-bill-form .form-row { grid-template-columns: 1fr; } }
</style>

<div class="card" style="margin-bottom: 1.25rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <h2 style="font-size: 1.05rem; font-weight: 700; color: var(--text-heading); margin: 0;">
                <i data-feather="edit-3" style="width: 20px; height: 20px; display: inline; vertical-align: -3px;"></i>
                Edit Tagihan
            </h2>
            <p style="font-size: 0.72rem; color: var(--text-muted); margin: 0.2rem 0 0;">Ubah detail tagihan periode <?= $record['bill_period'] ?></p>
        </div>
        <div style="display: flex; gap: 0.4rem;">
            <?php if ($record['status'] === 'paid'): ?>
            <a href="<?= BASE_URL ?>/modules/bills/edit-record.php?id=<?= $recordId ?>&unpay=1" 
               class="btn" style="background: rgba(239,68,68,0.12); color: #ef4444; padding: 0.45rem 0.8rem; border-radius: var(--radius-md); font-size: 0.72rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;"
               onclick="return confirm('Batalkan pembayaran? Entry di buku kas juga akan dihapus.');">
                <i data-feather="x-circle" style="width: 14px; height: 14px;"></i> Batalkan Bayar
            </a>
            <?php endif; ?>
            <?php if ($record['status'] !== 'paid'): ?>
            <a href="<?= BASE_URL ?>/modules/bills/edit-record.php?id=<?= $recordId ?>&delete=1" 
               class="btn" style="background: rgba(239,68,68,0.12); color: #ef4444; padding: 0.45rem 0.8rem; border-radius: var(--radius-md); font-size: 0.72rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;"
               onclick="return confirm('Hapus tagihan ini?');">
                <i data-feather="trash-2" style="width: 14px; height: 14px;"></i> Hapus
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/bills/" class="btn" style="background: var(--bg-tertiary); color: var(--text-secondary); padding: 0.45rem 0.8rem; border-radius: var(--radius-md); font-size: 0.72rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
                <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
            </a>
        </div>
    </div>
</div>

<!-- Bill Detail -->
<div class="bill-detail-card">
    <div class="bill-detail-icon" style="background: <?= $catInfo['color'] ?>20; color: <?= $catInfo['color'] ?>;">
        <?= $catInfo['icon'] ?>
    </div>
    <div>
        <div class="bill-detail-name"><?= htmlspecialchars($record['bill_name']) ?></div>
        <div class="bill-detail-meta">
            <?= $catInfo['label'] ?> · <?= htmlspecialchars($record['vendor_name'] ?? '—') ?> · Periode <?= $record['bill_period'] ?>
            <?php if ($record['status'] === 'paid'): ?>
            <span style="display: inline-block; background: rgba(16,185,129,0.15); color: #059669; padding: 0.1rem 0.35rem; border-radius: 3px; font-size: 0.6rem; font-weight: 700; margin-left: 0.25rem;">LUNAS</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <form method="POST" class="edit-bill-form">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Jumlah Tagihan (Rp) *</label>
                <input type="text" name="amount" class="form-control" required
                       value="<?= number_format($record['amount'], 0, ',', '.') ?>"
                       oninput="this.value = this.value.replace(/[^0-9]/g,'').replace(/\B(?=(\d{3})+(?!\d))/g, '.')"
                       <?= $record['status'] === 'paid' ? 'disabled' : '' ?>>
            </div>
            <div class="form-group">
                <label class="form-label">Jatuh Tempo *</label>
                <input type="date" name="due_date" class="form-control" required
                       value="<?= $record['due_date'] ?>"
                       <?= $record['status'] === 'paid' ? 'disabled' : '' ?>>
            </div>
        </div>
        
        <?php if ($record['status'] !== 'paid'): ?>
        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="pending" <?= $record['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="overdue" <?= $record['status'] === 'overdue' ? 'selected' : '' ?>>Jatuh Tempo</option>
                <option value="cancelled" <?= $record['status'] === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
            </select>
        </div>
        <?php endif; ?>
        
        <div class="form-group">
            <label class="form-label">Catatan</label>
            <input type="text" name="notes" class="form-control" placeholder="Keterangan tambahan"
                   value="<?= htmlspecialchars($record['notes'] ?? '') ?>">
        </div>
        
        <?php if ($record['status'] === 'paid'): ?>
        <div style="background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2); border-radius: var(--radius-md); padding: 0.75rem; margin-top: 0.5rem;">
            <div style="font-size: 0.72rem; font-weight: 700; color: #10b981; margin-bottom: 0.35rem;">✅ Sudah Dibayar</div>
            <div style="font-size: 0.72rem; color: var(--text-secondary); display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem;">
                <span>Tanggal: <strong><?= $record['paid_date'] ? date('d M Y', strtotime($record['paid_date'])) : '—' ?></strong></span>
                <span>Jumlah: <strong><?= $record['paid_amount'] ? formatCurrency($record['paid_amount']) : '—' ?></strong></span>
                <span>Metode: <strong><?= strtoupper($record['payment_method'] ?? '—') ?></strong></span>
                <span>Buku Kas: <strong><?= $record['cashbook_id'] ? '#' . $record['cashbook_id'] : '—' ?></strong></span>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="form-actions">
            <a href="<?= BASE_URL ?>/modules/bills/" class="btn" style="background: var(--bg-tertiary); color: var(--text-secondary);">Batal</a>
            <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); color: white;">
                💾 Simpan Perubahan
            </button>
        </div>
    </form>
</div>

<script>
if (typeof feather !== 'undefined') feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
