<?php

/**
 * Sunsea - Kalender Booking (Balok Reservasi Confirmed)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();
sunseaEnsureBookingSchema($pdo);

$month = $_GET['month'] ?? date('Y-m');
$startMonth = date('Y-m-01', strtotime($month . '-01'));
$endMonth = date('Y-m-t', strtotime($month . '-01'));

$bookings = [];
$errorMsg = '';

try {
    $rows = $pdo->prepare("SELECT b.id, b.booking_no, b.start_date, b.end_date, b.pax_count, b.status,
        c.name as customer_name,
        (SELECT COUNT(*) FROM booking_order_items i WHERE i.booking_id=b.id AND i.is_done=0) as pending_count
        FROM booking_orders b
        JOIN customers c ON c.id=b.customer_id
        WHERE b.end_date >= ? AND b.start_date <= ?
          AND b.status = 'confirmed'
        ORDER BY b.start_date, b.id");
    $rows->execute([$startMonth, $endMonth]);
    $bookings = $rows->fetchAll();
} catch (Exception $e) {
    $errorMsg = 'Error loading bookings: ' . htmlspecialchars($e->getMessage());
}

$daysInMonth = (int)date('t', strtotime($startMonth));
$pageTitle = 'Kalender Booking';
$activePage = 'calendar';
include 'layout-header.php';
?>

<?php if ($errorMsg): ?>
    <div style="padding:10px 12px;margin-bottom:10px;border-radius:8px;background:#fee2e2;border:1px solid #fca5a5;color:#c33;font-size:12.5px;">
        ⚠️ <?php echo $errorMsg; ?>
    </div>
<?php endif; ?>

<style>
    .cal-card { padding: 12px 14px !important; }
    .cal-table th { padding: 6px 10px !important; font-size: 10px !important; }
    .cal-table td { padding: 6px 10px !important; font-size: 12px !important; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
    <div>
        <h3 style="margin:0;font-size:15px;">📅 Kalender Booking</h3>
        <div style="color:var(--ss-muted);font-size:11px;">Menampilkan reservasi tamu dengan status confirmed</div>
    </div>
    <form method="GET" style="display:flex;gap:6px;align-items:center;">
        <input type="month" name="month" class="ss-input" style="width:150px;padding:6px 8px;font-size:12.5px;" value="<?php echo htmlspecialchars($month); ?>">
        <button class="ss-btn ss-btn-outline ss-btn-sm" type="submit"><i data-feather="search" style="width:14px;height:14px;"></i> Lihat</button>
    </form>
</div>

<div class="ss-card cal-card" style="margin-bottom:10px;">
    <div class="ss-card-title" style="margin-bottom:8px;font-size:13px;">Timeline Reservasi Confirmed - <?php echo date('F Y', strtotime($startMonth)); ?></div>
    <div style="overflow:auto;">
        <div style="min-width:<?php echo 160 + $daysInMonth * 18; ?>px;">
            <div style="display:grid;grid-template-columns:160px repeat(<?php echo $daysInMonth; ?>, 18px);gap:1px;align-items:center;margin-bottom:6px;">
                <div style="font-size:10px;color:var(--ss-muted);font-weight:700;">Booking</div>
                <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                    <div style="font-size:9px;color:var(--ss-muted);text-align:center;"><?php echo $d; ?></div>
                <?php endfor; ?>
            </div>

            <?php foreach ($bookings as $b):
                $s = max(1, (int)date('j', strtotime(max($b['start_date'], $startMonth))));
                $e = min($daysInMonth, (int)date('j', strtotime(min($b['end_date'], $endMonth))));
                $span = max(1, $e - $s + 1);
                $statusColor = '#3b82f6';
            ?>
                <div style="display:grid;grid-template-columns:160px repeat(<?php echo $daysInMonth; ?>, 18px);gap:1px;align-items:center;margin-bottom:3px;">
                    <div style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php if ((int)$b['pending_count'] > 0): ?>
                            <span title="Ada layanan belum selesai" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#dc2626;margin-right:3px;"></span>
                        <?php endif; ?>
                        <a href="bookings.php?view=<?php echo $b['id']; ?>" style="color:var(--ss-text);text-decoration:none;"><?php echo htmlspecialchars($b['customer_name']); ?></a>
                        <div style="font-size:9px;font-weight:400;color:var(--ss-muted);">
                            <a href="bookings.php?view=<?php echo $b['id']; ?>" style="color:var(--ss-ocean);text-decoration:none;"><?php echo htmlspecialchars($b['booking_no']); ?></a>
                            · <?php echo (int)$b['pax_count']; ?> pax
                        </div>
                    </div>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <?php if ($d >= $s && $d <= $e): ?>
                            <div style="height:12px;background:<?php echo $statusColor; ?>;border-radius:2px;"></div>
                        <?php else: ?>
                            <div style="height:12px;background:#F1F5F9;border-radius:2px;"></div>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endforeach; ?>

            <?php if (empty($bookings)): ?>
                <div style="padding:10px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:12px;">
                    Tidak ada reservasi confirmed pada bulan ini.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="ss-card cal-card">
    <div class="ss-card-title" style="margin-bottom:6px;font-size:13px;">Daftar Reservasi Confirmed Bulan Ini</div>
    <div class="ss-table-wrap">
        <table class="ss-table cal-table">
            <thead>
                <tr>
                    <th>Booking</th>
                    <th>Customer</th>
                    <th>Tanggal</th>
                    <th>Pax</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td><a href="bookings.php?view=<?php echo $b['id']; ?>" style="color:var(--ss-ocean);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($b['booking_no']); ?></a></td>
                        <td>
                            <?php if ((int)$b['pending_count'] > 0): ?>
                                <span title="Ada layanan belum selesai" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#dc2626;margin-right:4px;"></span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($b['customer_name']); ?>
                        </td>
                        <td><?php echo date('d M Y', strtotime($b['start_date'])); ?> - <?php echo date('d M Y', strtotime($b['end_date'])); ?></td>
                        <td><?php echo (int)$b['pax_count']; ?></td>
                        <td><span class="ss-status ss-status-sent"><?php echo ucfirst($b['status']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($bookings)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;color:#64748b;">Belum ada data reservasi confirmed.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'layout-footer.php';
