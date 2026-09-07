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
if (!$auth->isLoggedIn()) { header('Location: owner-login.php'); exit; }
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

<?php if (empty($bookings)): ?>
    <div class="ob-section"><div class="ob-empty">Belum ada data reservasi.</div></div>
<?php else: ?>
    <?php foreach ($bookings as $b): ?>
        <?php
        $badge = $statusBadge[$b['status']] ?? ['ob-badge-draft', $b['status']];
        $waLink = sunseaWaLink((string)$b['customer_phone']);
        $initial = strtoupper(mb_substr(trim((string)$b['customer_name']), 0, 1)) ?: '?';
        ?>
        <div class="ob-bcard">
            <div class="ob-bcard-top">
                <div class="ob-bcard-avatar"><?php echo htmlspecialchars($initial); ?></div>
                <div class="ob-bcard-info">
                    <div class="ob-bcard-name"><?php echo htmlspecialchars($b['customer_name']); ?></div>
                    <div class="ob-bcard-no"><?php echo htmlspecialchars($b['booking_no']); ?></div>
                </div>
                <span class="ob-badge <?php echo $badge[0]; ?>"><?php echo htmlspecialchars($badge[1]); ?></span>
            </div>
            <div class="ob-bcard-meta">
                <i data-feather="calendar"></i>
                <?php echo date('d M', strtotime($b['start_date'])); ?> - <?php echo date('d M Y', strtotime($b['end_date'])); ?>
                &nbsp;·&nbsp;<i data-feather="users"></i> <?php echo (int)$b['pax_count']; ?> pax
            </div>
            <div class="ob-bcard-actions">
                <a href="owner-booking-detail.php?id=<?php echo (int)$b['id']; ?>" class="ob-btn-detail"><i data-feather="file-text"></i> Detail</a>
                <?php if ($waLink): ?>
                    <a href="<?php echo htmlspecialchars($waLink); ?>" target="_blank" rel="noopener" class="ob-btn-wa"><i data-feather="message-circle"></i> WhatsApp</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include 'owner-mobile-footer.php'; ?>
