<?php

/**
 * Sunsea - Owner mobile view: Kalender Booking (timeline balok reservasi, read-only)
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

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$startMonth = date('Y-m-01', strtotime($month . '-01'));
$endMonth = date('Y-m-t', strtotime($month . '-01'));
$daysInMonth = (int)date('t', strtotime($startMonth));
$todayDay = (date('Y-m') === $month) ? (int)date('j') : 0;

$bookings = $pdo->prepare("
    SELECT b.id, b.booking_no, b.start_date, b.end_date, b.pax_count, c.name AS customer_name
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    WHERE b.end_date >= ? AND b.start_date <= ? AND b.status = 'confirmed'
    ORDER BY b.start_date, b.id
");
$bookings->execute([$startMonth, $endMonth]);
$bookings = $bookings->fetchAll();

$prevMonth = date('Y-m', strtotime($startMonth . ' -1 month'));
$nextMonth = date('Y-m', strtotime($startMonth . ' +1 month'));
$colWidth = 22;

$pageTitle = 'Kalender Booking';
include 'owner-mobile-header.php';
?>

<style>
    .ob-cal-nav { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:12px; }
    .ob-cal-nav a {
        width:32px; height:32px; border-radius:8px; background:#fff; border:1px solid var(--border);
        display:flex; align-items:center; justify-content:center; text-decoration:none; color:var(--ocean); flex-shrink:0;
    }
    .ob-cal-nav a svg { width:16px; height:16px; }
    .ob-cal-month { font-size:14px; font-weight:800; text-align:center; }
    .ob-cal-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .ob-cal-grid { display:grid; grid-auto-rows:22px; align-items:center; }
    .ob-cal-head-name { position:sticky; left:0; background:#fff; z-index:2; font-size:9px; font-weight:700; color:var(--muted); padding-left:2px; }
    .ob-cal-daynum { font-size:8.5px; text-align:center; color:var(--muted); }
    .ob-cal-daynum.today { color:var(--ocean); font-weight:800; }
    .ob-cal-name {
        position:sticky; left:0; background:#fff; z-index:1; padding-right:6px;
        font-size:10.5px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        border-right:2px solid var(--border);
    }
    .ob-cal-name .sub { font-size:8.5px; font-weight:400; color:var(--muted); }
    .ob-cal-cell { height:14px; border-radius:2px; background:#F1F5F9; margin:0 1px; }
    .ob-cal-cell.on { background:var(--ocean); }
    .ob-cal-legend { display:flex; align-items:center; gap:6px; font-size:10.5px; color:var(--muted); margin-top:10px; }
    .ob-cal-legend span.dot { width:10px; height:10px; border-radius:2px; background:var(--ocean); display:inline-block; }
</style>

<div class="ob-cal-nav">
    <a href="?month=<?php echo $prevMonth; ?>"><i data-feather="chevron-left"></i></a>
    <div class="ob-cal-month"><?php echo date('F Y', strtotime($startMonth)); ?></div>
    <a href="?month=<?php echo $nextMonth; ?>"><i data-feather="chevron-right"></i></a>
</div>

<div class="ob-section">
    <?php if (empty($bookings)): ?>
        <div class="ob-empty">Tidak ada reservasi confirmed pada bulan ini.</div>
    <?php else: ?>
        <div class="ob-cal-scroll">
            <div class="ob-cal-grid" style="grid-template-columns:96px repeat(<?php echo $daysInMonth; ?>, <?php echo $colWidth; ?>px);">
                <div class="ob-cal-head-name">Tamu</div>
                <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                    <div class="ob-cal-daynum <?php echo $d === $todayDay ? 'today' : ''; ?>"><?php echo $d; ?></div>
                <?php endfor; ?>

                <?php foreach ($bookings as $b):
                    $s = max(1, (int)date('j', strtotime(max($b['start_date'], $startMonth))));
                    $e = min($daysInMonth, (int)date('j', strtotime(min($b['end_date'], $endMonth))));
                ?>
                    <div class="ob-cal-name">
                        <?php echo htmlspecialchars($b['customer_name']); ?>
                        <div class="sub"><?php echo htmlspecialchars($b['booking_no']); ?> · <?php echo (int)$b['pax_count']; ?> pax</div>
                    </div>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <div class="ob-cal-cell <?php echo ($d >= $s && $d <= $e) ? 'on' : ''; ?>"></div>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ob-cal-legend"><span class="dot"></span> Reservasi confirmed · geser ke samping untuk lihat tanggal lain</div>
    <?php endif; ?>
</div>

<?php include 'owner-mobile-footer.php'; ?>
