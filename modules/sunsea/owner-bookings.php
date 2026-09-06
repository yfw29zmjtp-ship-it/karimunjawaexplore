<?php

/**
 * Sunsea - Owner mobile view: Reservasi Tamu (read-only list)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getSunseaConnection();
sunseaEnsureBookingSchema($pdo);

$statusFilter = $_GET['status'] ?? '';
$where = '';
$params = [];
if (in_array($statusFilter, ['draft', 'confirmed', 'cancelled'], true)) {
    $where = 'WHERE b.status = ?';
    $params[] = $statusFilter;
}

$bookings = $pdo->prepare("
    SELECT b.id, b.booking_no, b.start_date, b.end_date, b.pax_count, b.status, c.name AS customer_name, c.phone AS customer_phone
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    $where
    ORDER BY b.start_date DESC
    LIMIT 100
");
$bookings->execute($params);
$bookings = $bookings->fetchAll();

$statusBadge = [
    'draft'     => ['ob-badge-draft', 'Pending'],
    'confirmed' => ['ob-badge-confirmed', 'Confirmed'],
    'cancelled' => ['ob-badge-issued', 'Batal'],
];

$pageTitle = 'Reservasi Tamu';
include 'owner-mobile-header.php';
?>

<div class="ob-tabs">
    <a href="owner-bookings.php" class="ob-tab <?php echo $statusFilter === '' ? 'active' : ''; ?>">Semua</a>
    <a href="owner-bookings.php?status=draft" class="ob-tab <?php echo $statusFilter === 'draft' ? 'active' : ''; ?>">Pending</a>
    <a href="owner-bookings.php?status=confirmed" class="ob-tab <?php echo $statusFilter === 'confirmed' ? 'active' : ''; ?>">Confirmed</a>
    <a href="owner-bookings.php?status=cancelled" class="ob-tab <?php echo $statusFilter === 'cancelled' ? 'active' : ''; ?>">Batal</a>
</div>

<div class="ob-section">
    <?php if (empty($bookings)): ?>
        <div class="ob-empty">Belum ada data reservasi.</div>
    <?php else: ?>
        <?php foreach ($bookings as $b): ?>
            <?php $badge = $statusBadge[$b['status']] ?? ['ob-badge-draft', $b['status']]; ?>
            <?php $waLink = sunseaWaLink((string)$b['customer_phone']); ?>
            <a href="owner-booking-detail.php?id=<?php echo (int)$b['id']; ?>" class="ob-row" style="align-items:flex-start;">
                <div style="flex:1;min-width:0;">
                    <div class="ob-row-title" style="font-size:14px;"><?php echo htmlspecialchars($b['customer_name']); ?></div>
                    <div class="ob-row-sub"><?php echo htmlspecialchars($b['booking_no']); ?> · <?php echo (int)$b['pax_count']; ?> pax</div>
                    <div class="ob-row-sub"><?php echo date('d M', strtotime($b['start_date'])); ?> - <?php echo date('d M Y', strtotime($b['end_date'])); ?></div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                    <span class="ob-badge <?php echo $badge[0]; ?>"><?php echo htmlspecialchars($badge[1]); ?></span>
                    <?php if ($waLink): ?>
                        <a href="<?php echo htmlspecialchars($waLink); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation();" class="ob-wa-btn"><i data-feather="message-circle"></i> WA</a>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'owner-mobile-footer.php'; ?>
