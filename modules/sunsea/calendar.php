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
sunseaEnsureFinanceSchema($pdo);

// ---- AJAX: reservation + finance expense detail for the modal ----
if (($_GET['ajax'] ?? '') === 'detail' && (int)($_GET['id'] ?? 0) > 0) {
    header('Content-Type: application/json');
    $bId = (int)$_GET['id'];

    $b = $pdo->prepare("
        SELECT b.*, c.name AS customer_name, c.phone AS customer_phone,
            p.name AS package_name, p.duration_days AS package_duration_days, p.duration_nights AS package_duration_nights
        FROM booking_orders b
        JOIN customers c ON c.id = b.customer_id
        LEFT JOIN trip_packages p ON p.id = b.package_id
        WHERE b.id = ?
    ");
    $b->execute([$bId]);
    $booking = $b->fetch();
    if (!$booking) {
        echo json_encode(['error' => 'Booking tidak ditemukan.']);
        exit;
    }

    $items = $pdo->prepare("SELECT component_code, component_name, qty, unit, price_sell, price_cost, total_sell, total_cost, is_done, is_paid_mitra FROM booking_order_items WHERE booking_id=? ORDER BY sort_order, id");
    $items->execute([$bId]);
    $items = $items->fetchAll();

    // Info penginapan: pakai label manual dulu (kalau diisi saat penawaran/booking),
    // lalu item Penginapan dari komponen, lalu catatan hotel manual di notes booking.
    $accommodationInfo = '-';
    if (!empty($booking['accommodation_manual'])) {
        $accommodationInfo = $booking['accommodation_manual'];
    }
    foreach ($items as $it) {
        if ($accommodationInfo !== '-') break;
        if (stripos($it['component_name'], 'penginapan') !== false) {
            $accommodationInfo = preg_replace('/^Penginapan:\s*/i', '', $it['component_name']);
            break;
        }
    }
    if ($accommodationInfo === '-' && preg_match('/Hotel:\s*([^-]+)/i', (string)($booking['notes'] ?? ''), $mHotel)) {
        $accommodationInfo = trim($mHotel[1]);
    }

    // Durasi selalu dibaca dari selisih tanggal mulai & selesai aktual (bukan durasi baku paket).
    $nights = max(0, (int)round((strtotime($booking['end_date']) - strtotime($booking['start_date'])) / 86400));
    $durationLabel = ($nights + 1) . 'H' . $nights . 'M';

    $expenses = sunseaFetchBookingExpenses($pdo, $bId);

    $totalExpense = 0;
    foreach ($expenses as $ex) $totalExpense += (float)$ex['amount'];

    $totalRab = 0;
    foreach ($items as $it) $totalRab += (float)$it['total_sell'];

    $mitraItems = array_values(array_filter($items, fn($it) => $it['component_code'] !== 'paket'));

    // Riwayat pembayaran/DP: invoice booking ini bisa dibayar bertahap (DP 1, DP 2, pelunasan, dst) di tabel payments.
    $invStmt = $pdo->prepare("SELECT id, invoice_no, total_amount FROM invoices WHERE status != 'cancelled' AND (internal_notes = ? OR internal_notes = ?)");
    $invStmt->execute(['booking_id:' . $bId, 'Generated from Reservasi: ' . $booking['booking_no']]);
    $bookingInvoices = $invStmt->fetchAll();

    $payments = [];
    $totalInvoiceAmount = 0;
    foreach ($bookingInvoices as $bi) $totalInvoiceAmount += (float)$bi['total_amount'];
    if ($bookingInvoices) {
        $invIds = array_column($bookingInvoices, 'id');
        $ph = implode(',', array_fill(0, count($invIds), '?'));
        $payStmt = $pdo->prepare("SELECT payment_date, amount, method, reference FROM payments WHERE invoice_id IN ($ph) ORDER BY payment_date, id");
        $payStmt->execute($invIds);
        $payments = $payStmt->fetchAll();
    }
    $totalPaid = 0;
    foreach ($payments as $py) $totalPaid += (float)$py['amount'];
    $remainingPayment = max(0, $totalInvoiceAmount - $totalPaid);

    echo json_encode([
        'booking'      => $booking,
        'items'        => $items,
        'mitraItems'   => $mitraItems,
        'expenses'     => $expenses,
        'totalExpense' => $totalExpense,
        'totalRab'     => $totalRab,
        'margin'       => $totalRab - $totalExpense,
        'accommodationInfo' => $accommodationInfo,
        'durationLabel'     => $durationLabel,
        'payments'           => $payments,
        'totalInvoiceAmount' => $totalInvoiceAmount,
        'totalPaid'          => $totalPaid,
        'remainingPayment'   => $remainingPayment,
    ]);
    exit;
}

$month = $_GET['month'] ?? date('Y-m');
$startMonth = date('Y-m-01', strtotime($month . '-01'));
$endMonth = date('Y-m-t', strtotime($month . '-01'));
$prevMonth = date('Y-m', strtotime($startMonth . ' -1 month'));
$nextMonth = date('Y-m', strtotime($startMonth . ' +1 month'));
$isCurrentMonth = ($month === date('Y-m'));

// Timeline ditampilkan menyambung 2 bulan sekaligus (bulan ini + bulan depan) supaya geser/drag
// tidak "lompat" ke halaman kosong saat bulan depan belum ada booking - tanggalnya tetap kelihatan,
// dengan garis batas + nama bulan di atas kolom bulan berikutnya.
$nextMonthEnd = date('Y-m-t', strtotime($nextMonth . '-01'));

$bookings = [];
$errorMsg = '';

try {
    $rows = $pdo->prepare("SELECT b.id, b.booking_no, b.start_date, b.end_date, b.pax_count, b.status,
        c.name as customer_name, p.name as package_name,
        (SELECT COUNT(*) FROM booking_order_items i WHERE i.booking_id=b.id AND i.is_done=0) as pending_count
        FROM booking_orders b
        JOIN customers c ON c.id=b.customer_id
        LEFT JOIN trip_packages p ON p.id = b.package_id
        WHERE b.end_date >= ? AND b.start_date <= ?
          AND b.status = 'confirmed'
        ORDER BY b.start_date, b.id");
    $rows->execute([$startMonth, $nextMonthEnd]);
    $bookings = $rows->fetchAll();
} catch (Exception $e) {
    $errorMsg = 'Error loading bookings: ' . htmlspecialchars($e->getMessage());
}

$daysInMonth = (int)date('t', strtotime($startMonth));
$daysInNextMonth = (int)date('t', strtotime($nextMonth . '-01'));
$calTotalDays = $daysInMonth + $daysInNextMonth;

// Daftar tanggal kontinu (bulan ini + bulan depan) beserta index kolomnya (dipakai header & balok booking).
$calDates = [];
$calDateIndex = [];
$curDate = strtotime($startMonth);
for ($i = 1; $i <= $calTotalDays; $i++) {
    $dateStr = date('Y-m-d', $curDate);
    $calDates[] = [
        'date'  => $dateStr,
        'day'   => (int)date('j', $curDate),
        'dow'   => (int)date('N', $curDate),
        'isMonthStart' => $i > 1 && (int)date('j', $curDate) === 1,
    ];
    $calDateIndex[$dateStr] = $i;
    $curDate = strtotime('+1 day', $curDate);
}

$todayIsInMonth = (date('Y-m') === $month);
$todayDay = $todayIsInMonth ? (int)date('j') : 0;
$todayIndex = $calDateIndex[date('Y-m-d')] ?? 0;

// Warna balok reservasi jadi penanda durasi/tipe trip (bukan acak per-tamu lagi).
$calDurationColors = [
    '2H1M' => '#EAB308',
    '3H2M' => '#EA580C',
    '4H1M' => '#2563EB',
    '4H3M' => '#0D9488',
    '5H4M' => '#7C3AED',
];
$calHoneymoonColor = '#DB2777';
$calDefaultColor = '#64748B';
$calCompletedColor = '#94A3B8';

function calBarColor(string $durationLabel, ?string $packageName, array $durationColors, string $honeymoonColor, string $defaultColor, bool $isCompleted = false, string $completedColor = '#94A3B8'): string
{
    if ($isCompleted) {
        return $completedColor;
    }
    if ($packageName && stripos($packageName, 'honeymoon') !== false) {
        return $honeymoonColor;
    }
    return $durationColors[$durationLabel] ?? $defaultColor;
}
$pageTitle = 'Kalender';
$activePage = 'calendar';
include 'layout-header.php';
?>

<?php if ($errorMsg): ?>
    <div style="padding:10px 12px;margin-bottom:10px;border-radius:8px;background:#fee2e2;border:1px solid #fca5a5;color:#c33;font-size:12.5px;">
        ⚠️ <?php echo $errorMsg; ?>
    </div>
<?php endif; ?>

<style>
    .cal-card {
        padding: 12px 14px !important;
    }

    .cal-table th {
        padding: 6px 10px !important;
        font-size: 10px !important;
    }

    .cal-table td {
        padding: 6px 10px !important;
        font-size: 12px !important;
    }

    /* Hotel-style timeline: sticky guest column + scrollable day grid with bar-shaped reservations */
    .cal-timeline-scroll {
        overflow-x: auto;
        border-radius: 10px;
        border: 1px solid var(--ss-gray-2);
        -webkit-overflow-scrolling: touch;
        scroll-behavior: smooth;
        cursor: grab;
        user-select: none;
    }

    .cal-timeline-scroll.is-dragging {
        cursor: grabbing;
        scroll-behavior: auto;
    }

    .cal-timeline {
        width: 100%;
        background: #fff;
    }

    .cal-day-row {
        display: grid;
        grid-template-columns: 210px repeat(var(--cal-days), minmax(30px, 1fr));
    }

    .cal-name-col {
        position: sticky;
        left: 0;
        z-index: 2;
        background: var(--ss-sky);
        border-right: 1px solid var(--ss-gray-2);
        padding: 12px 14px;
        font-size: 13px;
        font-weight: 800;
        color: var(--ss-deep);
        text-transform: uppercase;
        letter-spacing: .04em;
        display: flex;
        align-items: center;
    }

    .cal-day-col {
        text-align: center;
        padding: 10px 1px;
        font-size: 12.5px;
        font-weight: 700;
        color: var(--ss-muted);
        background: var(--ss-sky);
        border-left: 1px solid rgba(194, 65, 12, .06);
    }

    .cal-day-col.is-weekend {
        color: var(--ss-ocean);
        background: #FFECE0;
    }

    .cal-day-col.is-today {
        background: var(--ss-ocean);
        color: #fff;
        border-radius: 6px 6px 0 0;
    }

    .cal-row-placeholder {
        opacity: .6;
    }

    .cal-month-label-row {
        background: var(--ss-sky);
    }

    .cal-month-label {
        text-align: center;
        padding: 5px 4px;
        font-size: 11px;
        font-weight: 800;
        color: var(--ss-deep);
        text-transform: uppercase;
        letter-spacing: .03em;
        border-bottom: 1px solid var(--ss-gray-2);
    }

    .cal-month-boundary {
        border-left: 2px solid var(--ss-ocean) !important;
    }

    .cal-row {
        display: grid;
        grid-template-columns: 210px repeat(var(--cal-days), minmax(30px, 1fr));
        align-items: center;
        cursor: pointer;
        transition: background .15s ease;
    }

    .cal-row:hover {
        background: var(--ss-sky);
    }

    .cal-row:hover .cal-guest {
        background: var(--ss-sky);
    }

    .cal-guest {
        position: sticky;
        left: 0;
        z-index: 1;
        background: #fff;
        border-right: 1px solid var(--ss-gray-2);
        padding: 12px 14px;
        overflow: hidden;
    }

    .cal-guest-avatar {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--ss-ocean), var(--ss-cyan));
        color: #fff;
        font-size: 11px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-right: 8px;
    }

    .cal-guest-name {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--ss-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .cal-guest-meta {
        font-size: 10px;
        font-weight: 500;
        color: var(--ss-muted);
        white-space: nowrap;
    }

    .cal-cell {
        height: 36px;
        border-left: 1px solid rgba(15, 23, 42, .03);
        border-bottom: 1px solid rgba(15, 23, 42, .03);
    }

    .cal-cell.is-weekend {
        background: #FFF7F0;
    }

    .cal-cell.is-today {
        background: #FFE4D1;
        box-shadow: inset 1px 0 0 var(--ss-ocean), inset -1px 0 0 var(--ss-ocean);
    }

    .cal-bar {
        height: 26px;
        margin: 0 1px;
        border-radius: 999px;
        background: linear-gradient(90deg, var(--ss-ocean), #E85D2C);
        box-shadow: 0 2px 6px rgba(194, 65, 12, .28);
        display: flex;
        align-items: center;
        transition: transform .12s ease, box-shadow .12s ease;
        position: relative;
        z-index: 1;
    }

    /* Bar segment holding the pax-count label: needs to sit above the following
       day segments so the label can overflow visually without being covered. */
    .cal-bar-labeled {
        z-index: 3;
        overflow: visible;
    }

    .cal-row:hover .cal-bar {
        transform: scaleY(1.15);
        box-shadow: 0 3px 10px rgba(194, 65, 12, .4);
    }

    .cal-bar-label {
        font-size: 12.5px;
        font-weight: 700;
        color: #fff;
        white-space: nowrap;
        overflow: visible;
        padding-left: 8px;
        letter-spacing: .01em;
    }

    .cal-bar-continue {
        font-size: 12px;
        font-weight: 900;
        color: #fff;
        padding: 0 4px;
    }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
    <div>
        <h3 style="margin:0;font-size:15px;">📅 Kalender Booking</h3>
        <div style="color:var(--ss-muted);font-size:11px;">Menampilkan reservasi tamu dengan status confirmed</div>
    </div>
    <form method="GET" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <a href="?month=<?php echo $prevMonth; ?>" class="ss-btn ss-btn-outline ss-btn-sm" title="Bulan sebelumnya"><i data-feather="chevron-left" style="width:14px;height:14px;"></i></a>
        <a href="?month=<?php echo date('Y-m'); ?>" class="ss-btn <?php echo $isCurrentMonth ? 'ss-btn-primary' : 'ss-btn-outline'; ?> ss-btn-sm">Hari Ini</a>
        <a href="?month=<?php echo $nextMonth; ?>" class="ss-btn ss-btn-outline ss-btn-sm" title="Bulan berikutnya"><i data-feather="chevron-right" style="width:14px;height:14px;"></i></a>
        <input type="month" name="month" class="ss-input" style="width:150px;padding:6px 8px;font-size:12.5px;" value="<?php echo htmlspecialchars($month); ?>">
        <button class="ss-btn ss-btn-outline ss-btn-sm" type="submit"><i data-feather="search" style="width:14px;height:14px;"></i> Lihat</button>
    </form>
</div>

<div class="ss-card cal-card" style="margin-bottom:10px;">
    <div class="ss-card-title" style="margin-bottom:6px;font-size:13px;">Timeline Reservasi Confirmed - <?php echo date('F Y', strtotime($startMonth)); ?></div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;font-size:10.5px;color:var(--ss-muted);">
        <?php
        $calLegend = [
            'Honeymoon' => $calHoneymoonColor,
            '2H1M'      => $calDurationColors['2H1M'],
            '3H2M'      => $calDurationColors['3H2M'],
            '4H1M'      => $calDurationColors['4H1M'],
            '4H3M'      => $calDurationColors['4H3M'],
            '5H4M'      => $calDurationColors['5H4M'],
            'Lainnya'   => $calDefaultColor,
            'Selesai'   => $calCompletedColor,
        ];
        ?>
        <?php foreach ($calLegend as $label => $color): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;">
                <span style="width:9px;height:9px;border-radius:50%;background:<?php echo $color; ?>;display:inline-block;"></span>
                <?php echo htmlspecialchars($label); ?>
            </span>
        <?php endforeach; ?>
        <span style="display:inline-flex;align-items:center;gap:4px;">
            <span style="width:9px;height:9px;border-radius:2px;background:#FFE4D1;border:1px solid var(--ss-ocean);display:inline-block;"></span>
            Hari Ini
        </span>
        <span style="color:var(--ss-muted);">&middot; Geser timeline ke kiri/kanan untuk pindah bulan (tanggal bulan depan tetap tersambung)</span>
    </div>
    <div class="cal-timeline-scroll" id="calTimelineScroll" data-prev-month="<?php echo $prevMonth; ?>" data-next-month="<?php echo $nextMonth; ?>">
        <div class="cal-timeline" id="calTimeline" style="--cal-days:<?php echo $calTotalDays; ?>;">
            <div class="cal-day-row cal-month-label-row">
                <div class="cal-name-col" style="background:transparent;border-right:1px solid var(--ss-gray-2);"></div>
                <div class="cal-month-label" style="grid-column: 2 / span <?php echo $daysInMonth; ?>;"><?php echo date('F Y', strtotime($startMonth)); ?></div>
                <div class="cal-month-label cal-month-boundary" style="grid-column: <?php echo 2 + $daysInMonth; ?> / span <?php echo $daysInNextMonth; ?>;"><?php echo date('F Y', strtotime($nextMonth . '-01')); ?></div>
            </div>
            <div class="cal-day-row">
                <div class="cal-name-col">Tamu</div>
                <?php foreach ($calDates as $cd):
                    $isWeekend = $cd['dow'] >= 6;
                    $isToday = $cd['date'] === date('Y-m-d');
                ?>
                    <div class="cal-day-col<?php echo $isWeekend ? ' is-weekend' : ''; ?><?php echo $isToday ? ' is-today' : ''; ?><?php echo $cd['isMonthStart'] ? ' cal-month-boundary' : ''; ?>"><?php echo $cd['day']; ?></div>
                <?php endforeach; ?>
            </div>

            <?php foreach ($bookings as $bi => $b):
                $s = $calDateIndex[max($b['start_date'], $startMonth)] ?? 1;
                $e = $calDateIndex[min($b['end_date'], $nextMonthEnd)] ?? $calTotalDays;
                $nights = max(0, (int)round((strtotime($b['end_date']) - strtotime($b['start_date'])) / 86400));
                $durationLabel = ($nights + 1) . 'H' . $nights . 'M';
                $isCompletedTrip = strtotime($b['end_date']) < strtotime(date('Y-m-d'));
                $barColor = calBarColor($durationLabel, $b['package_name'], $calDurationColors, $calHoneymoonColor, $calDefaultColor, $isCompletedTrip, $calCompletedColor);
                $initial = mb_strtoupper(mb_substr($b['customer_name'], 0, 1));
                // Balok "terpotong" kalau tanggal aslinya nyambung ke sebelum/sesudah jendela 2 bulan yang sedang ditampilkan.
                $clippedLeft = strtotime($b['start_date']) < strtotime($startMonth);
                $clippedRight = strtotime($b['end_date']) > strtotime($nextMonthEnd);
                $barTitle = htmlspecialchars(date('d M Y', strtotime($b['start_date'])) . ' - ' . date('d M Y', strtotime($b['end_date'])) . ' (' . $durationLabel . ')');
            ?>
                <div class="cal-row" onclick="openBookingDetail(<?php echo $b['id']; ?>)">
                    <div class="cal-guest">
                        <div style="display:flex;align-items:center;">
                            <span class="cal-guest-avatar" style="background:linear-gradient(135deg,<?php echo $barColor; ?>,var(--ss-cyan));"><?php echo htmlspecialchars($initial); ?></span>
                            <div style="min-width:0;">
                                <div class="cal-guest-name">
                                    <?php if ((int)$b['pending_count'] > 0): ?>
                                        <span title="Ada layanan belum selesai" style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#dc2626;margin-right:3px;"></span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($b['customer_name']); ?>
                                </div>
                                <div class="cal-guest-meta"><?php echo htmlspecialchars($b['booking_no']); ?> · <?php echo (int)$b['pax_count']; ?> pax</div>
                            </div>
                        </div>
                    </div>
                    <?php foreach ($calDates as $ci => $cd):
                        $d = $ci + 1;
                        $isWeekend = $cd['dow'] >= 6;
                        $isTodayCol = $d === $todayIndex;
                    ?>
                        <?php if ($d >= $s && $d <= $e): ?>
                            <?php
                            $isLeftEdge = $d === $s;
                            $isRightEdge = $d === $e;
                            $roundLeft = $isLeftEdge && !$clippedLeft;
                            $roundRight = $isRightEdge && !$clippedRight;
                            $radius = ($roundLeft ? '999px' : '0') . ' ' . ($roundRight ? '999px' : '0') . ' ' . ($roundRight ? '999px' : '0') . ' ' . ($roundLeft ? '999px' : '0');
                            ?>
                            <div class="cal-cell<?php echo $isTodayCol ? ' is-today' : ''; ?><?php echo $cd['isMonthStart'] ? ' cal-month-boundary' : ''; ?>" style="padding:4px 0;" title="<?php echo $barTitle; ?>">
                                <div class="cal-bar<?php echo $isLeftEdge ? ' cal-bar-labeled' : ''; ?>" style="background:linear-gradient(90deg,<?php echo $barColor; ?>,<?php echo $barColor; ?>cc);border-radius:<?php echo $radius; ?>;<?php echo !$isLeftEdge ? 'margin-left:-1px;' : ''; ?><?php echo !$isRightEdge ? 'margin-right:-1px;' : ''; ?>">
                                    <?php if ($isLeftEdge && $clippedLeft): ?><span class="cal-bar-continue" title="Lanjutan dari bulan sebelumnya">&laquo;</span><?php endif; ?>
                                    <?php if ($isLeftEdge): ?><span class="cal-bar-label"><?php echo (int)$b['pax_count']; ?> pax</span><?php endif; ?>
                                    <?php if ($isRightEdge && $clippedRight): ?><span class="cal-bar-continue" title="Lanjut ke bulan berikutnya">&raquo;</span><?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="cal-cell<?php echo $isWeekend ? ' is-weekend' : ''; ?><?php echo $isTodayCol ? ' is-today' : ''; ?><?php echo $cd['isMonthStart'] ? ' cal-month-boundary' : ''; ?>"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <?php
            // Baris placeholder "Belum ada tamu" cuma tampil kalau memang belum ada booking sama sekali
            // di bulan ini - tidak lagi dipaksa menambah baris kosong saat tamu sudah ada, supaya daftar
            // tamu tidak tercampur dengan baris kosong yang membingungkan.
            if (empty($bookings)):
            ?>
                <div class="cal-row cal-row-placeholder">
                    <div class="cal-guest">
                        <div style="display:flex;align-items:center;">
                            <span class="cal-guest-avatar" style="background:#E2E8F0;color:#94A3B8;">-</span>
                            <div style="min-width:0;">
                                <div class="cal-guest-name" style="color:#94A3B8;">Belum ada tamu</div>
                                <div class="cal-guest-meta">&nbsp;</div>
                            </div>
                        </div>
                    </div>
                    <?php foreach ($calDates as $cd): ?>
                        <div class="cal-cell<?php echo $cd['dow'] >= 6 ? ' is-weekend' : ''; ?><?php echo $cd['date'] === date('Y-m-d') ? ' is-today' : ''; ?><?php echo $cd['isMonthStart'] ? ' cal-month-boundary' : ''; ?>"></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($bookings)): ?>
                <div style="padding:8px 14px;color:#64748b;font-size:12px;">
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
                    <th>Durasi</th>
                    <th>Pax</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                    <?php
                    // Durasi paket dibaca otomatis dari selisih tanggal mulai & selesai (mis. 3H2M, 4H3M).
                    $nights = max(0, (int)round((strtotime($b['end_date']) - strtotime($b['start_date'])) / 86400));
                    $durationLabel = ($nights + 1) . 'H' . $nights . 'M';
                    ?>
                    <tr style="cursor:pointer;" onclick="openBookingDetail(<?php echo $b['id']; ?>)">
                        <td><a href="javascript:void(0)" onclick="event.stopPropagation();openBookingDetail(<?php echo $b['id']; ?>)" style="color:var(--ss-ocean);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($b['booking_no']); ?></a></td>
                        <td>
                            <?php if ((int)$b['pending_count'] > 0): ?>
                                <span title="Ada layanan belum selesai" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#dc2626;margin-right:4px;"></span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($b['customer_name']); ?>
                        </td>
                        <td><?php echo date('d M Y', strtotime($b['start_date'])); ?> - <?php echo date('d M Y', strtotime($b['end_date'])); ?></td>
                        <td><?php echo $durationLabel; ?></td>
                        <td><?php echo (int)$b['pax_count']; ?></td>
                        <td><span class="ss-status ss-status-sent"><?php echo ucfirst($b['status']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($bookings)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:#64748b;">Belum ada data reservasi confirmed.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Detail Reservasi + Pengeluaran (Finance) -->
<div id="bookingDetailOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:1000;align-items:stretch;justify-content:stretch;">
    <div style="width:100%;height:100%;display:flex;flex-direction:column;background:#fff;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-bottom:1px solid var(--ss-gray-1);flex-shrink:0;">
            <div style="font-size:15px;font-weight:700;">Detail Reservasi</div>
            <button type="button" onclick="closeBookingDetail()" style="background:none;border:none;cursor:pointer;color:var(--ss-muted);padding:4px;">
                <i data-feather="x"></i>
            </button>
        </div>
        <div id="bookingDetailBody" style="flex:1;overflow:auto;padding:16px 24px;width:100%;">
            <div style="text-align:center;padding:30px;color:var(--ss-muted);">Memuat...</div>
        </div>
    </div>
</div>

<style>
    /* Detail Reservasi (popup kalender): kepala + 4 info, lalu 2 kolom "Uang dari Tamu" dan "Biaya & Untung" */
    #bookingDetailBody { background: var(--ss-gray-1); }
    #bookingDetailBody .rd { max-width: 1180px; margin: 0 auto; }
    #bookingDetailBody .rd-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
    #bookingDetailBody .rd-name { font-size: 20px; font-weight: 800; color: var(--ss-text); display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    #bookingDetailBody .rd-sub { font-size: 12.5px; color: var(--ss-muted); margin-top: 3px; }
    #bookingDetailBody .rd-sub a { color: var(--ss-ocean); text-decoration: none; font-weight: 600; }
    #bookingDetailBody .rd-pill { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 99px; letter-spacing: .2px; }
    #bookingDetailBody .rd-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    #bookingDetailBody .rd-info { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
    #bookingDetailBody .rd-info > div { background: #fff; border-radius: 12px; padding: 12px 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, .05); }
    #bookingDetailBody .rd-k { font-size: 11px; font-weight: 600; color: var(--ss-muted); text-transform: uppercase; letter-spacing: .5px; }
    #bookingDetailBody .rd-v { font-size: 14px; font-weight: 700; color: var(--ss-text); margin-top: 4px; line-height: 1.35; }
    #bookingDetailBody .rd-v small { display: block; font-size: 12px; font-weight: 500; color: var(--ss-muted); }
    #bookingDetailBody .rd-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: start; }
    #bookingDetailBody .rd-card { background: #fff; border-radius: 14px; padding: 16px 18px; box-shadow: 0 1px 2px rgba(15, 23, 42, .05); margin-bottom: 14px; }
    #bookingDetailBody .rd-card h4 { margin: 0 0 12px; font-size: 14px; font-weight: 800; color: var(--ss-text); display: flex; justify-content: space-between; align-items: center; gap: 8px; }
    #bookingDetailBody .rd-card h4 .ic { font-size: 16px; margin-right: 6px; }
    #bookingDetailBody .rd-big { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px; }
    #bookingDetailBody .rd-big > div { border-radius: 10px; padding: 10px 12px; background: var(--ss-gray-1); }
    #bookingDetailBody .rd-big .rd-v { font-size: 16px; }
    #bookingDetailBody .rd-bar { height: 8px; border-radius: 99px; background: var(--ss-gray-2); overflow: hidden; margin: 2px 0 6px; }
    #bookingDetailBody .rd-bar > span { display: block; height: 100%; border-radius: 99px; }
    #bookingDetailBody .rd-note { font-size: 12px; color: var(--ss-muted); }
    #bookingDetailBody .rd-list { display: flex; flex-direction: column; }
    #bookingDetailBody .rd-row { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; padding: 9px 0; border-bottom: 1px dashed var(--ss-gray-2); font-size: 13px; }
    #bookingDetailBody .rd-row:last-child { border-bottom: 0; }
    #bookingDetailBody .rd-row .l { color: var(--ss-text); }
    #bookingDetailBody .rd-row .l small { display: block; color: var(--ss-muted); font-size: 11.5px; margin-top: 1px; }
    #bookingDetailBody .rd-row .r { font-weight: 700; white-space: nowrap; }
    #bookingDetailBody .rd-row.total { border-top: 1px solid var(--ss-gray-2); border-bottom: 0; margin-top: 2px; font-weight: 800; }
    #bookingDetailBody .rd-sec { font-size: 12px; font-weight: 700; color: var(--ss-muted); text-transform: uppercase; letter-spacing: .5px; margin: 14px 0 4px; }
    #bookingDetailBody .rd-empty { font-size: 12.5px; color: var(--ss-muted); padding: 10px 12px; border-radius: 10px; background: var(--ss-gray-1); }
    #bookingDetailBody .rd-warn { font-size: 12.5px; color: #92400E; padding: 10px 12px; border-radius: 10px; background: #FFFBEB; border: 1px solid #FDE68A; margin-bottom: 10px; }
    #bookingDetailBody .rd-tag { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; white-space: nowrap; }
    #bookingDetailBody .rd-tag.ok { background: #DCFCE7; color: #166534; }
    #bookingDetailBody .rd-tag.no { background: #FEF3C7; color: #92400E; }
    @media (max-width: 1000px) {
        #bookingDetailBody .rd-info { grid-template-columns: repeat(2, 1fr); }
        #bookingDetailBody .rd-cols { grid-template-columns: 1fr; }
    }
    @media (max-width: 560px) {
        #bookingDetailBody .rd-big { grid-template-columns: 1fr; }
    }
</style>

<script>
    function openBookingDetail(id) {
        var overlay = document.getElementById('bookingDetailOverlay');
        var body = document.getElementById('bookingDetailBody');
        body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--ss-muted);">Memuat...</div>';
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        fetch('calendar.php?ajax=detail&id=' + id)
            .then(function(r) {
                return r.json();
            })
            .then(function(data) {
                if (data.error) {
                    body.innerHTML = '<div style="color:var(--ss-danger);">' + data.error + '</div>';
                    return;
                }
                var b = data.booking;
                var fmt = function(n) {
                    return 'Rp ' + Math.round(parseFloat(n) || 0).toLocaleString('id-ID');
                };
                var esc = function(s) {
                    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
                    });
                };
                var bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                var tgl = function(d) {
                    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || '');
                    return m ? (+m[3]) + ' ' + bulan[+m[2] - 1] + ' ' + m[1] : esc(d || '-');
                };
                var statusInfo = {
                    draft: ['Draft', '#F1F5F9', '#475569'],
                    confirmed: ['Terkonfirmasi', '#DBEAFE', '#1E40AF'],
                    ongoing: ['Sedang berjalan', '#FEF3C7', '#92400E'],
                    completed: ['Selesai', '#DCFCE7', '#166534'],
                    cancelled: ['Batal', '#FEE2E2', '#991B1B']
                };
                var st = statusInfo[b.status] || [b.status, '#F1F5F9', '#475569'];

                var rab = parseFloat(data.totalRab) || 0;
                var expenseVal = parseFloat(data.totalExpense) || 0;
                var margin = parseFloat(data.margin) || 0;
                var billed = parseFloat(data.totalInvoiceAmount) || rab;
                var paid = parseFloat(data.totalPaid) || 0;
                var remaining = parseFloat(data.remainingPayment) || 0;
                var paidPct = billed > 0 ? Math.max(0, Math.min(100, paid / billed * 100)) : 0;
                var lunas = billed > 0 && remaining <= 0;
                var marginPct = rab > 0 ? margin / rab * 100 : 0;
                var marginColor = margin >= 0 ? 'var(--ss-success)' : 'var(--ss-danger)';
                var phone = String(b.customer_phone || '').replace(/\D+/g, '');
                var waNo = phone.indexOf('0') === 0 ? '62' + phone.slice(1) : phone;

                var html = '<div class="rd">';

                // ── Kepala: nama, status, kode & telepon, tombol ──
                html += '<div class="rd-head"><div>';
                html += '<div class="rd-name">' + esc(b.customer_name) + ' <span class="rd-pill" style="background:' + st[1] + ';color:' + st[2] + ';">' + esc(st[0]) + '</span></div>';
                html += '<div class="rd-sub">' + esc(b.booking_no) + (phone ? ' · <a href="https://wa.me/' + waNo + '" target="_blank" rel="noopener">WhatsApp ' + esc(b.customer_phone) + '</a>' : '') + '</div>';
                html += '</div><div class="rd-actions">';
                html += '<a href="bookings.php?view=' + (+b.id) + '" class="ss-btn ss-btn-outline ss-btn-sm">Buka Pemesanan</a>';
                html += '<a href="finance.php?customer_id=' + (+b.customer_id) + '" class="ss-btn ss-btn-outline ss-btn-sm">Lihat di Kas & Biaya</a>';
                html += '</div></div>';

                // ── 4 info utama ──
                html += '<div class="rd-info">';
                html += '<div><div class="rd-k">Tanggal</div><div class="rd-v">' + tgl(b.start_date) + '<small>s/d ' + tgl(b.end_date) + '</small></div></div>';
                html += '<div><div class="rd-k">Durasi & Tamu</div><div class="rd-v">' + esc(data.durationLabel) + '<small>' + (+b.pax_count || 0) + ' orang</small></div></div>';
                html += '<div><div class="rd-k">Paket</div><div class="rd-v">' + esc(b.package_name || 'Tanpa paket') + '</div></div>';
                html += '<div><div class="rd-k">Penginapan</div><div class="rd-v">' + esc(data.accommodationInfo || '-') + '</div></div>';
                html += '</div>';

                html += '<div class="rd-cols">';

                // ── Kolom kiri: uang dari tamu ──
                html += '<div>';
                html += '<div class="rd-card"><h4><span><span class="ic">💰</span>Uang dari Tamu</span>' +
                    '<span class="rd-tag ' + (lunas ? 'ok' : 'no') + '">' + (lunas ? 'Lunas' : (paid > 0 ? 'Sudah DP' : 'Belum bayar')) + '</span></h4>';
                html += '<div class="rd-big">';
                html += '<div><div class="rd-k">Total Tagihan</div><div class="rd-v">' + fmt(billed) + '</div></div>';
                html += '<div style="background:#ECFDF5;"><div class="rd-k">Sudah Dibayar</div><div class="rd-v" style="color:var(--ss-success);">' + fmt(paid) + '</div></div>';
                html += '<div style="background:' + (remaining > 0 ? '#FFFBEB' : '#ECFDF5') + ';"><div class="rd-k">Sisa</div><div class="rd-v" style="color:' + (remaining > 0 ? '#B45309' : 'var(--ss-success)') + ';">' + fmt(remaining) + '</div></div>';
                html += '</div>';
                html += '<div class="rd-bar"><span style="width:' + paidPct.toFixed(1) + '%;background:var(--ss-success);"></span></div>';
                html += '<div class="rd-note">' + Math.round(paidPct) + '% sudah dibayar</div>';

                html += '<div class="rd-sec">Riwayat pembayaran</div>';
                if (!data.payments || data.payments.length === 0) {
                    html += '<div class="rd-empty">Belum ada pembayaran / DP yang tercatat.</div>';
                } else {
                    html += '<div class="rd-list">';
                    data.payments.forEach(function(py, idx) {
                        var payLabel = (idx === data.payments.length - 1 && remaining <= 0) ? 'Pelunasan' : 'DP ' + (idx + 1);
                        html += '<div class="rd-row"><span class="l">' + payLabel + '<small>' + tgl(py.payment_date) + (py.method ? ' · ' + esc(py.method) : '') + '</small></span>' +
                            '<span class="r" style="color:var(--ss-success);">' + fmt(py.amount) + '</span></div>';
                    });
                    html += '</div>';
                }
                html += '</div>';

                html += '<div class="rd-card"><h4><span><span class="ic">🧾</span>Rincian Harga (RAB)</span></h4>';
                if (!data.items || data.items.length === 0) {
                    html += '<div class="rd-empty">Belum ada item.</div>';
                } else {
                    html += '<div class="rd-list">';
                    data.items.forEach(function(it) {
                        var q = parseFloat(it.qty) || 0;
                        html += '<div class="rd-row"><span class="l">' + esc(it.component_name) + (it.is_done == 1 ? ' <span style="color:var(--ss-success);">✓</span>' : '') +
                            '<small>' + (q % 1 === 0 ? q.toFixed(0) : q) + ' ' + esc(it.unit || '') + '</small></span>' +
                            '<span class="r">' + fmt(it.total_sell) + '</span></div>';
                    });
                    html += '<div class="rd-row total"><span class="l">Total</span><span class="r">' + fmt(rab) + '</span></div>';
                    html += '</div>';
                }
                html += '</div>';
                html += '</div>';

                // ── Kolom kanan: biaya & untung ──
                html += '<div>';
                html += '<div class="rd-card"><h4><span><span class="ic">📊</span>Biaya & Untung</span>';
                if (data.expenses.length > 0) {
                    html += '<button type="button" class="ss-btn ss-btn-outline ss-btn-sm" onclick="printBookingExpenses()">Cetak / PDF</button>';
                }
                html += '</h4>';
                html += '<div class="rd-big">';
                html += '<div><div class="rd-k">Harga Jual</div><div class="rd-v">' + fmt(rab) + '</div></div>';
                html += '<div style="background:#FEF2F2;"><div class="rd-k">Biaya Trip</div><div class="rd-v" style="color:var(--ss-danger);">' + fmt(expenseVal) + '</div></div>';
                html += '<div style="background:' + (margin >= 0 ? '#ECFDF5' : '#FEF2F2') + ';"><div class="rd-k">Untung</div><div class="rd-v" style="color:' + marginColor + ';">' + fmt(margin) + '<small>' + marginPct.toFixed(0) + '% dari harga jual</small></div></div>';
                html += '</div>';
                if (data.expenses.length === 0) {
                    html += '<div class="rd-warn">Biaya trip ini belum dicatat di Kas & Biaya, jadi untungnya belum final. Catat biaya (tiket, penginapan, guide, dll.) supaya angkanya benar.</div>';
                } else {
                    html += '<div class="rd-sec">Pengeluaran (dari Kas & Biaya)</div><div class="rd-list">';
                    data.expenses.forEach(function(ex) {
                        html += '<div class="rd-row"><span class="l">' + esc(ex.description) + '<small>' + tgl(ex.transaction_date) + (ex.category ? ' · ' + esc(ex.category) : '') + '</small></span>' +
                            '<span class="r" style="color:var(--ss-danger);">' + fmt(ex.amount) + '</span></div>';
                    });
                    html += '<div class="rd-row total"><span class="l">Total biaya</span><span class="r" style="color:var(--ss-danger);">' + fmt(expenseVal) + '</span></div>';
                    html += '</div>';
                }
                html += '</div>';

                // Checklist layanan mitra: data terstruktur dari detail layanan paket (component_code != 'paket')
                html += '<div class="rd-card"><h4><span><span class="ic">🤝</span>Pembayaran ke Mitra</span></h4>';
                if (!data.mitraItems || data.mitraItems.length === 0) {
                    html += '<div class="rd-empty">Belum ada layanan mitra. Isi "Detail Layanan dalam Paket" di Paket Wisata (tiket kapal, penginapan, rental, dll.) supaya tagihan mitra tampil di sini.</div>';
                } else {
                    html += '<div class="rd-list">';
                    data.mitraItems.forEach(function(it) {
                        var isPaid = it.is_paid_mitra == 1;
                        html += '<div class="rd-row"><span class="l">' + esc(it.component_name) + '</span>' +
                            '<span class="r">' + fmt(it.total_cost) + ' <span class="rd-tag ' + (isPaid ? 'ok' : 'no') + '">' + (isPaid ? 'Sudah dibayar' : 'Belum dibayar') + '</span></span></div>';
                    });
                    html += '</div><div class="rd-note" style="margin-top:8px;">Tandai pembayaran mitra di <a href="bookings.php?view=' + (+b.id) + '">halaman Pemesanan</a> → Pembayaran ke Mitra.</div>';
                }
                html += '</div>';
                html += '</div>';

                html += '</div></div>';

                // Konteks untuk tombol Cetak / PDF (hindari JSON di atribut onclick)
                window.__bookingDetailPrintCtx = {
                    booking: b,
                    expenses: data.expenses,
                    totalExpense: expenseVal
                };

                body.innerHTML = html;
                if (window.feather) feather.replace();
            })
            .catch(function() {
                body.innerHTML = '<div style="color:var(--ss-danger);">Gagal memuat detail reservasi.</div>';
            });
    }

    function closeBookingDetail() {
        document.getElementById('bookingDetailOverlay').style.display = 'none';
        document.body.style.overflow = '';
    }

    // Timeline sudah menyambung 2 bulan (bulan ini + depan) dalam satu grid, jadi geser tanggal
    // cukup pakai scroll horizontal - di HP tetap swipe/touch bawaan browser (halus, tanpa reload).
    // Drag mouse pakai Pointer Events + setPointerCapture supaya gerakan mouse tetap "ditangkap"
    // oleh timeline walau kursor sempat keluar dari elemen/jendela saat digeser cepat (lebih stabil
    // dibanding mouse event + listener di window yang gampang "lepas" duluan).
    (function() {
        var scroller = document.getElementById('calTimelineScroll');
        if (!scroller || !window.PointerEvent) return;
        var isDown = false,
            startX = 0,
            startScroll = 0,
            dragged = false,
            activePointerId = null;

        function beginDrag(pageX) {
            isDown = true;
            dragged = false;
            startX = pageX - scroller.offsetLeft;
            startScroll = scroller.scrollLeft;
            scroller.classList.add('is-dragging');
        }

        function moveDrag(pageX) {
            if (!isDown) return;
            var x = pageX - scroller.offsetLeft;
            var walk = x - startX;
            if (Math.abs(walk) > 4) dragged = true;
            scroller.scrollLeft = startScroll - walk;
        }

        function endDrag() {
            if (!isDown) return;
            isDown = false;
            scroller.classList.remove('is-dragging');
            if (activePointerId !== null && scroller.hasPointerCapture && scroller.hasPointerCapture(activePointerId)) {
                scroller.releasePointerCapture(activePointerId);
            }
            activePointerId = null;
        }

        scroller.addEventListener('pointerdown', function(e) {
            if (e.pointerType !== 'mouse' || e.button !== 0) return;
            activePointerId = e.pointerId;
            scroller.setPointerCapture(e.pointerId);
            beginDrag(e.pageX);
        });
        scroller.addEventListener('pointermove', function(e) {
            if (!isDown || e.pointerType !== 'mouse') return;
            e.preventDefault();
            moveDrag(e.pageX);
        });
        scroller.addEventListener('pointerup', endDrag);
        scroller.addEventListener('pointercancel', endDrag);
        scroller.addEventListener('lostpointercapture', endDrag);

        // Cegah klik baris terbuka modal detail kalau baru saja dipakai untuk drag mouse.
        // (Swipe di HP tetap pakai scroll native browser, tidak lewat JS ini.)
        scroller.addEventListener('click', function(e) {
            if (dragged) {
                e.stopPropagation();
                e.preventDefault();
            }
        }, true);
    })();

    function printBookingExpenses() {
        var ctx = window.__bookingDetailPrintCtx;
        if (!ctx) return;
        var fmt = function(n) {
            return 'Rp ' + Math.round(parseFloat(n) || 0).toLocaleString('id-ID');
        };
        var rows = ctx.expenses.map(function(ex) {
            return '<tr><td>' + ex.transaction_date + '</td><td>' + ex.description +
                (ex.category ? ' <small style="color:#64748b;">(' + ex.category + ')</small>' : '') +
                '</td><td style="text-align:right;">' + fmt(ex.amount) + '</td></tr>';
        }).join('');
        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pengeluaran ' + ctx.booking.booking_no + '</title>' +
            '<style>' +
            'body{font-family:Arial,Helvetica,sans-serif;padding:24px;color:#0f172a;}' +
            'h1{font-size:16px;margin:0 0 2px;}' +
            'p{margin:0 0 14px;color:#475569;font-size:12.5px;}' +
            'table{width:100%;border-collapse:collapse;font-size:12.5px;}' +
            'th,td{border:1px solid #cbd5e1;padding:7px 9px;text-align:left;}' +
            'th{background:#f1f5f9;}' +
            'tfoot td{font-weight:700;}' +
            '</style></head><body onload="window.print()">' +
            '<h1>Pengeluaran Trip &mdash; ' + ctx.booking.booking_no + '</h1>' +
            '<p>' + ctx.booking.customer_name + '</p>' +
            '<table><thead><tr><th style="width:100px;">Tanggal</th><th>Keterangan</th><th style="width:140px;text-align:right;">Jumlah</th></tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
            '<tfoot><tr><td colspan="2" style="text-align:right;">Total Pengeluaran</td><td style="text-align:right;">' + fmt(ctx.totalExpense) + '</td></tr></tfoot>' +
            '</table></body></html>';
        var win = window.open('', '_blank');
        win.document.open();
        win.document.write(html);
        win.document.close();
    }
</script>

<?php include 'layout-footer.php';
