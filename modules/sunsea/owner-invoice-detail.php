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
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getSunseaConnection();
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

$pageTitle = 'Detail Invoice';
$backUrl = 'owner-invoices.php';
include 'owner-mobile-header.php';
?>

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