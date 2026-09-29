<?php

/**
 * Sunsea - Owner mobile view: Detail Invoice (read-only) + cetak
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: owner-login.php');
    exit;
}
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$pdo = getSunseaConnection();
if (!sunseaCanAccessMenu($pdo, $currentUser, 'owner_dashboard')) {
    header('Location: dashboard.php');
    exit;
}

sunseaEnsureFinanceSchema($pdo);

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT i.*, c.name AS customer_name, c.phone AS customer_phone
    FROM invoices i JOIN customers c ON c.id = i.customer_id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header('Location: owner-invoices.php');
    exit;
}

$errorMsg = '';

// Catat pembayaran dari Owner Portal - pakai logika yang sama persis dengan invoices.php
// (action=add_payment) supaya payments, invoices, dan cash_book selalu sinkron dengan Finance.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_payment') {
    $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
    $method = $_POST['method'] ?? 'transfer';
    $date   = $_POST['payment_date'] ?: date('Y-m-d');
    $ref    = trim($_POST['reference'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');
    $user   = $currentUser['username'] ?? 'owner';

    if ($amount <= 0) {
        $errorMsg = 'Jumlah pembayaran harus lebih dari 0.';
    } else {
        $pdo->prepare("
            INSERT INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_by)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$id, $date, $amount, $method, $ref, $notes, $user]);
        $paymentId = (int)$pdo->lastInsertId();

        $totalPaid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?");
        $totalPaid->execute([$id]);
        $paid      = (float)$totalPaid->fetchColumn();
        $remaining = max(0, (float)$invoice['total_amount'] - $paid);
        $newStatus = $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued');
        $paidAt    = $remaining <= 0 ? ', paid_at=NOW()' : '';
        $pdo->prepare("UPDATE invoices SET paid_amount=?, remaining_amount=?, status=? $paidAt WHERE id=?")
            ->execute([$paid, $remaining, $newStatus, $id]);

        // Cari booking terkait (kalau invoice ini berasal dari reservasi) - sama seperti invoices.php,
        // supaya Finance/Kalender tetap bisa filter transaksi ini per trip.
        $linkedBookingId = null;
        if (preg_match('/Generated from Reservasi:\s*(\S+)/', (string)($invoice['internal_notes'] ?? ''), $mBk)) {
            $bkStmt = $pdo->prepare("SELECT id FROM booking_orders WHERE booking_no=?");
            $bkStmt->execute([$mBk[1]]);
            $linkedBookingId = $bkStmt->fetchColumn() ?: null;
        } elseif (preg_match('/^booking_id:(\d+)$/', (string)($invoice['internal_notes'] ?? ''), $mBk)) {
            $linkedBookingId = (int)$mBk[1];
        }

        $pdo->prepare("
            INSERT INTO cash_book (transaction_date, transaction_time, type, category, description, amount, reference, invoice_id, payment_id, customer_id, booking_id, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $date,
            date('H:i:s'),
            'income',
            'Penerimaan Trip',
            "Pembayaran Invoice {$invoice['invoice_no']} — {$invoice['customer_name']}",
            $amount,
            $ref ?: $invoice['invoice_no'],
            $id,
            $paymentId,
            $invoice['customer_id'],
            $linkedBookingId,
            $user
        ]);

        header('Location: owner-invoice-detail.php?id=' . $id . '&paid=1');
        exit;
    }
}

$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
$items->execute([$id]);
$items = $items->fetchAll();

$payments = $pdo->prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY payment_date");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$statusBadge = [
    'issued'  => ['ob-badge-issued', 'Belum Bayar'],
    'partial' => ['ob-badge-partial', 'Sebagian'],
    'paid'    => ['ob-badge-paid', 'Lunas'],
];
$badge = $statusBadge[$invoice['status']] ?? ['ob-badge-draft', $invoice['status']];
$waLink = sunseaWaLink((string)$invoice['customer_phone'], 'Halo ' . $invoice['customer_name'] . ', mengenai invoice ' . $invoice['invoice_no'] . '...');
$canPay = in_array($invoice['status'], ['issued', 'partial'], true) && (float)$invoice['remaining_amount'] > 0;

$pageTitle = 'Detail Invoice';
$backUrl = 'owner-invoices.php';
include 'owner-mobile-header.php';
?>

<style>
    .ob-form-group { margin-bottom: 12px; }
    .ob-form-label { display: block; font-size: 11.5px; font-weight: 700; color: var(--text); margin-bottom: 5px; }
    .ob-form-input {
        width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 9px;
        font-size: 13px; background: #fff; color: var(--text); font-family: inherit;
    }
    .ob-submit-btn {
        width: 100%; padding: 13px; border: none; border-radius: 10px; background: var(--success);
        color: #fff; font-size: 14px; font-weight: 800; cursor: pointer; margin-top: 4px;
    }
    .ob-alert-error {
        background: #FEE2E2; color: var(--danger); padding: 10px 12px; border-radius: 9px;
        font-size: 12.5px; margin-bottom: 12px;
    }
    .ob-alert-success {
        background: #D1FAE5; color: var(--success); padding: 10px 12px; border-radius: 9px;
        font-size: 12.5px; margin-bottom: 12px;
    }
    .ob-pay-form { display: none; }
    .ob-pay-form.open { display: block; }
</style>

<?php if (!empty($_GET['paid'])): ?>
    <div class="ob-alert-success"><i data-feather="check-circle"></i> Pembayaran berhasil dicatat &amp; sudah masuk ke Finance.</div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="ob-alert-error"><i data-feather="alert-triangle"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>

<div class="ob-section">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
        <div>
            <div style="font-size:17px;font-weight:800;"><?php echo htmlspecialchars($invoice['invoice_no']); ?></div>
            <div style="font-size:11px;color:var(--muted);"><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
        </div>
        <span class="ob-badge <?php echo $badge[0]; ?>"><?php echo htmlspecialchars($badge[1]); ?></span>
    </div>

    <?php if ($waLink): ?>
        <a href="<?php echo htmlspecialchars($waLink); ?>" target="_blank" rel="noopener" class="ob-wa-btn" style="font-size:12px;padding:7px 14px;margin-bottom:14px;">
            <i data-feather="message-circle"></i> Chat WhatsApp
        </a>
    <?php endif; ?>

    <div class="ob-detail-label">Jatuh Tempo</div>
    <div class="ob-detail-value"><?php echo $invoice['due_date'] ? date('d M Y', strtotime($invoice['due_date'])) : '-'; ?></div>

    <div class="ob-detail-label">Total Tagihan</div>
    <div class="ob-detail-value"><?php echo sunseaRupiah((float)$invoice['total_amount']); ?></div>

    <div class="ob-detail-label">Sudah Dibayar</div>
    <div class="ob-detail-value" style="color:var(--success);"><?php echo sunseaRupiah((float)$invoice['paid_amount']); ?></div>

    <div class="ob-detail-label">Sisa Tagihan</div>
    <div class="ob-detail-value" style="color:var(--danger);"><?php echo sunseaRupiah((float)$invoice['remaining_amount']); ?></div>

    <?php if ($canPay): ?>
        <button type="button" class="ob-qbtn" style="width:100%;margin-top:12px;background:var(--success);color:#fff;border-color:var(--success);" onclick="document.getElementById('payForm').classList.toggle('open')">
            <i data-feather="dollar-sign"></i> Bayar
        </button>

        <form method="POST" class="ob-pay-form" id="payForm" style="margin-top:14px;">
            <input type="hidden" name="action" value="add_payment">
            <div class="ob-form-group">
                <label class="ob-form-label">Tanggal Bayar</label>
                <input type="date" name="payment_date" class="ob-form-input" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="ob-form-group">
                <label class="ob-form-label">Jumlah (Rp)</label>
                <input type="text" name="amount" class="ob-form-input" inputmode="numeric" value="<?php echo (int)$invoice['remaining_amount']; ?>" required>
                <div style="font-size:10.5px;color:var(--muted);margin-top:4px;">* Default sisa tagihan (pelunasan). Ubah jumlahnya kalau ini baru DP / cicilan.</div>
            </div>
            <div class="ob-form-group">
                <label class="ob-form-label">Metode</label>
                <select name="method" class="ob-form-input">
                    <option value="transfer">Transfer</option>
                    <option value="cash">Tunai</option>
                    <option value="qris">QRIS</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>
            <div class="ob-form-group">
                <label class="ob-form-label">Referensi (opsional)</label>
                <input type="text" name="reference" class="ob-form-input" placeholder="No. transfer / referensi lain">
            </div>
            <button type="submit" class="ob-submit-btn"><i data-feather="check"></i> Simpan Pembayaran</button>
        </form>
    <?php endif; ?>
</div>

<div class="ob-section">
    <div class="ob-section-head">
        <div class="ob-section-title">Rincian Item</div>
    </div>
    <?php if (empty($items)): ?>
        <div class="ob-empty">Belum ada item.</div>
    <?php else: ?>
        <?php foreach ($items as $it): ?>
            <div class="ob-item-row">
                <div>
                    <?php echo htmlspecialchars($it['description']); ?>
                    <div style="color:var(--muted);font-size:10.5px;"><?php echo (float)$it['qty']; ?> <?php echo htmlspecialchars($it['unit']); ?> × <?php echo sunseaRupiah((float)$it['unit_price']); ?></div>
                </div>
                <div style="font-weight:700;"><?php echo sunseaRupiah((float)$it['subtotal']); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!empty($payments)): ?>
    <div class="ob-section">
        <div class="ob-section-head">
            <div class="ob-section-title">Riwayat Pembayaran</div>
        </div>
        <?php foreach ($payments as $p): ?>
            <div class="ob-item-row">
                <div>
                    <?php echo date('d M Y', strtotime($p['payment_date'])); ?>
                    <div style="color:var(--muted);font-size:10.5px;"><?php echo htmlspecialchars($p['method'] ?? '-'); ?></div>
                </div>
                <div style="font-weight:700;color:var(--success);">+<?php echo sunseaRupiah((float)$p['amount']); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<a href="invoices.php?action=print&id=<?php echo (int)$invoice['id']; ?>" target="_blank" class="ob-qbtn" style="width:100%;">
    <i data-feather="printer"></i> Cetak Invoice
</a>

<?php include 'owner-mobile-footer.php'; ?>