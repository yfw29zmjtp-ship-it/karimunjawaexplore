<?php

/**
 * Sunsea - Owner mobile view: Kalender Booking (agenda list, read-only)
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

$today = date('Y-m-d');

$bookings = $pdo->prepare("
    SELECT b.id, b.booking_no, b.start_date, b.end_date, b.pax_count, b.status, c.name AS customer_name
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    WHERE b.status = 'confirmed' AND b.end_date >= ?
    ORDER BY b.start_date ASC
    LIMIT 100
");
$bookings->execute([$today]);
$bookings = $bookings->fetchAll();

$grouped = [];
foreach ($bookings as $b) {
    $key = date('Y-m-d', strtotime($b['start_date']));
    $grouped[$key][] = $b;
}

$pageTitle = 'Kalender Booking';
include 'owner-mobile-header.php';
?>

<div class="ob-section">
    <?php if (empty($grouped)): ?>
        <div class="ob-empty">Belum ada reservasi confirmed yang akan datang.</div>
    <?php else: ?>
        <?php foreach ($grouped as $dateKey => $items): ?>
            <div class="ob-agenda-date"><?php echo date('l, d M Y', strtotime($dateKey)); ?></div>
            <?php foreach ($items as $b): ?>
                <a href="owner-bookings.php?status=confirmed" class="ob-row">
                    <div>
                        <div class="ob-row-title"><?php echo htmlspecialchars($b['booking_no']); ?></div>
                        <div class="ob-row-sub"><?php echo htmlspecialchars($b['customer_name']); ?> · <?php echo (int)$b['pax_count']; ?> pax</div>
                    </div>
                    <div class="ob-row-meta">s/d <?php echo date('d M', strtotime($b['end_date'])); ?></div>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'owner-mobile-footer.php'; ?>
