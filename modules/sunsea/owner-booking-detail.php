<?php

/**
 * Sunsea - Owner mobile view: Detail Reservasi (read-only)
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
sunseaEnsureBookingSchema($pdo);

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT b.*, c.name AS customer_name, c.phone AS customer_phone
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: owner-bookings.php');
    exit;
}

$items = $pdo->prepare("SELECT component_name, qty, unit, price_sell, total_sell FROM booking_order_items WHERE booking_id=? ORDER BY sort_order");
$items->execute([$id]);
$items = $items->fetchAll();

$totalSell = 0;
foreach ($items as $it) {
    $totalSell += (float)$it['total_sell'];
}

$statusBadge = [
    'draft'     => ['ob-badge-draft', 'Pending'],
    'confirmed' => ['ob-badge-confirmed', 'Confirmed'],
    'cancelled' => ['ob-badge-issued', 'Batal'],
];
$badge = $statusBadge[$booking['status']] ?? ['ob-badge-draft', $booking['status']];
$waLink = sunseaWaLink((string)$booking['customer_phone'], 'Halo ' . $booking['customer_name'] . ', mengenai reservasi ' . $booking['booking_no'] . '...');

$pageTitle = 'Detail Reservasi';
$backUrl = 'owner-bookings.php';
include 'owner-mobile-header.php';
?>

<div class="ob-section">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
        <div>
            <div style="font-size:17px;font-weight:800;"><?php echo htmlspecialchars($booking['customer_name']); ?></div>
            <div style="font-size:11px;color:var(--muted);"><?php echo htmlspecialchars($booking['booking_no']); ?></div>
        </div>
        <span class="ob-badge <?php echo $badge[0]; ?>"><?php echo htmlspecialchars($badge[1]); ?></span>
    </div>

    <?php if ($waLink): ?>
        <a href="<?php echo htmlspecialchars($waLink); ?>" target="_blank" rel="noopener" class="ob-wa-btn" style="font-size:12px;padding:7px 14px;margin-bottom:14px;">
            <i data-feather="message-circle"></i> Chat WhatsApp
        </a>
    <?php endif; ?>

    <div class="ob-detail-label">Tanggal Trip</div>
    <div class="ob-detail-value"><?php echo date('d M Y', strtotime($booking['start_date'])); ?> - <?php echo date('d M Y', strtotime($booking['end_date'])); ?></div>

    <div class="ob-detail-label">Jumlah Pax</div>
    <div class="ob-detail-value"><?php echo (int)$booking['pax_count']; ?> orang</div>

    <?php if (!empty($booking['notes'])): ?>
        <div class="ob-detail-label">Catatan</div>
        <div class="ob-detail-value" style="font-weight:400;"><?php echo nl2br(htmlspecialchars($booking['notes'])); ?></div>
    <?php endif; ?>
</div>

<div class="ob-section">
    <div class="ob-section-head">
        <div class="ob-section-title">Rincian Layanan</div>
    </div>
    <?php if (empty($items)): ?>
        <div class="ob-empty">Belum ada item layanan.</div>
    <?php else: ?>
        <?php foreach ($items as $it): ?>
            <div class="ob-item-row">
                <div>
                    <?php echo htmlspecialchars($it['component_name']); ?>
                    <div style="color:var(--muted);font-size:10.5px;"><?php echo (float)$it['qty']; ?> <?php echo htmlspecialchars($it['unit']); ?> × <?php echo sunseaRupiah((float)$it['price_sell']); ?></div>
                </div>
                <div style="font-weight:700;"><?php echo sunseaRupiah((float)$it['total_sell']); ?></div>
            </div>
        <?php endforeach; ?>
        <div class="ob-item-row" style="border-top:2px solid var(--border);margin-top:4px;padding-top:10px;">
            <div style="font-weight:800;">Total</div>
            <div style="font-weight:800;color:var(--ocean);"><?php echo sunseaRupiah($totalSell); ?></div>
        </div>
    <?php endif; ?>
</div>

<a href="bookings.php?action=print_invoice&id=<?php echo (int)$booking['id']; ?>" target="_blank" class="ob-qbtn" style="width:100%;">
    <i data-feather="printer"></i> Cetak Invoice
</a>

<?php include 'owner-mobile-footer.php'; ?>