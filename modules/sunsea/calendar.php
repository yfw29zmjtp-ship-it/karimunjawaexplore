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

    $expenses = $pdo->prepare("SELECT transaction_date, category, description, amount, reference, created_by FROM cash_book WHERE booking_id=? AND type='expense' ORDER BY transaction_date, id");
    $expenses->execute([$bId]);
    $expenses = $expenses->fetchAll();

    $totalExpense = 0;
    foreach ($expenses as $ex) $totalExpense += (float)$ex['amount'];

    $totalRab = 0;
    foreach ($items as $it) $totalRab += (float)$it['total_sell'];

    $mitraItems = array_values(array_filter($items, fn($it) => $it['component_code'] !== 'paket'));

    // Riwayat pembayaran/DP: invoice booking ini bisa dibayar bertahap (DP 1, DP 2, pelunasan, dst) di tabel payments.
    $invStmt = $pdo->prepare("SELECT id, invoice_no, total_amount FROM invoices WHERE internal_notes = ? OR internal_notes = ?");
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
        cursor: grab;
        user-select: none;
    }

    .cal-timeline-scroll.is-dragging {
        cursor: grabbing;
    }

    .cal-timeline {
        width: 100%;
        background: #fff;
        touch-action: pan-y;
        will-change: transform;
    }

    .cal-day-row {
        display: grid;
        grid-template-columns: 190px repeat(var(--cal-days), minmax(18px, 1fr));
    }

    .cal-name-col {
        position: sticky;
        left: 0;
        z-index: 2;
        background: var(--ss-sky);
        border-right: 1px solid var(--ss-gray-2);
        padding: 10px 12px;
        font-size: 12px;
        font-weight: 800;
        color: var(--ss-deep);
        text-transform: uppercase;
        letter-spacing: .04em;
        display: flex;
        align-items: center;
    }

    .cal-day-col {
        text-align: center;
        padding: 8px 1px;
        font-size: 11px;
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
        grid-template-columns: 190px repeat(var(--cal-days), minmax(18px, 1fr));
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
        padding: 10px 12px;
        overflow: hidden;
    }

    .cal-guest-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--ss-ocean), var(--ss-cyan));
        color: #fff;
        font-size: 12px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-right: 8px;
    }

    .cal-guest-name {
        font-size: 14px;
        font-weight: 700;
        color: var(--ss-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .cal-guest-meta {
        font-size: 11px;
        font-weight: 500;
        color: var(--ss-muted);
        white-space: nowrap;
    }

    .cal-cell {
        height: 28px;
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
        height: 20px;
        margin: 0 1px;
        border-radius: 999px;
        background: linear-gradient(90deg, var(--ss-ocean), #E85D2C);
        box-shadow: 0 2px 6px rgba(194, 65, 12, .28);
        display: flex;
        align-items: center;
        transition: transform .12s ease, box-shadow .12s ease;
    }

    .cal-row:hover .cal-bar {
        transform: scaleY(1.15);
        box-shadow: 0 3px 10px rgba(194, 65, 12, .4);
    }

    .cal-bar-label {
        font-size: 11px;
        font-weight: 700;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
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
    <form method="GET" style="display:flex;gap:6px;align-items:center;">
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
                                <div class="cal-bar" style="background:linear-gradient(90deg,<?php echo $barColor; ?>,<?php echo $barColor; ?>cc);border-radius:<?php echo $radius; ?>;<?php echo !$isLeftEdge ? 'margin-left:-1px;' : ''; ?><?php echo !$isRightEdge ? 'margin-right:-1px;' : ''; ?>">
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
            // Selalu tampilkan minimal 5 baris tamu supaya tinggi timeline stabil (tidak "lompat"/menciut)
            // walau booking masih sedikit/kosong - baris kosong ini otomatis terisi begitu ada booking baru.
            $calPlaceholderRows = max(0, 5 - count($bookings));
            for ($pr = 0; $pr < $calPlaceholderRows; $pr++):
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
            <?php endfor; ?>

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
    #bookingDetailBody .bd-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0;
        font-size: 12px;
        margin-bottom: 14px;
        background: #fff;
        border: 1px solid var(--ss-ocean);
        border-radius: 8px;
        overflow: hidden;
    }

    #bookingDetailBody .bd-grid>div {
        padding: 8px 12px;
        border-right: 1px solid #FDE4CE;
        border-bottom: 1px solid #FDE4CE;
    }

    #bookingDetailBody .bd-grid>div:nth-child(3n) {
        border-right: none;
    }

    #bookingDetailBody .bd-grid>div:nth-last-child(-n+3) {
        border-bottom: none;
    }

    #bookingDetailBody .bd-grid strong {
        display: block;
        color: var(--ss-muted);
        font-weight: 600;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: .4px;
        margin-bottom: 2px;
    }

    #bookingDetailBody .bd-grid .bd-val {
        display: block;
        font-size: 12.5px;
        font-weight: 700;
        color: #0f172a;
    }

    #bookingDetailBody .bd-section-title {
        font-size: 13px;
        font-weight: 700;
        margin: 16px 0 8px;
        color: #0f172a;
        padding-top: 4px;
        border-top: 1px solid var(--ss-gray-1);
    }

    #bookingDetailBody .bd-cols {
        display: grid;
        grid-template-columns: 1fr 440px;
        gap: 0 18px;
        align-items: start;
    }

    #bookingDetailBody .bd-col .bd-section-title {
        margin-top: 0;
        padding-top: 0;
        border-top: none;
    }

    #bookingDetailBody .bd-col-expense {
        background: #fff;
        border: 1px solid var(--ss-ocean);
        border-radius: 8px;
        padding: 16px 18px;
        min-width: 0;
    }

    #bookingDetailBody .bd-col-expense .bd-section-title {
        margin-top: 0;
        padding-top: 0;
        border-top: none;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    #bookingDetailBody .bd-col-expense table.ss-table {
        font-size: 13px;
    }

    #bookingDetailBody .bd-col-expense table.ss-table th {
        font-size: 11px;
        padding: 7px 9px;
    }

    #bookingDetailBody .bd-col-expense table.ss-table td {
        padding: 8px 9px;
    }

    #bookingDetailBody table.ss-table {
        font-size: 12px;
        margin-bottom: 0;
    }

    #bookingDetailBody table.ss-table th {
        font-size: 10.5px;
        padding: 5px 8px;
    }

    #bookingDetailBody table.ss-table td {
        padding: 5px 8px;
    }

    #bookingDetailBody .bd-summary {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin-top: 14px;
    }

    #bookingDetailBody .bd-summary-box {
        border-radius: 8px;
        padding: 10px 12px;
    }

    #bookingDetailBody .bd-summary-box .bd-label {
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: .3px;
        opacity: .8;
    }

    #bookingDetailBody .bd-summary-box .bd-value {
        font-size: 17px;
        font-weight: 700;
        margin-top: 3px;
    }

    #bookingDetailBody .bd-chart-row {
        display: grid;
        grid-template-columns: 160px 1fr;
        gap: 18px;
        align-items: center;
        margin-top: 14px;
        padding: 12px;
        background: var(--ss-gray-1);
        border-radius: 8px;
    }

    #bookingDetailBody .bd-donut {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        position: relative;
        margin: 0 auto;
        box-shadow: 0 10px 22px -8px rgba(0, 0, 0, .28), inset 0 2px 4px rgba(255, 255, 255, .35), inset 0 -3px 6px rgba(0, 0, 0, .15);
    }

    #bookingDetailBody .bd-donut-center {
        position: absolute;
        inset: 38px;
        background: radial-gradient(circle at 35% 30%, #ffffff, #f4f6f8);
        border-radius: 50%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        box-shadow: 0 3px 10px rgba(0, 0, 0, .14), inset 0 1px 2px rgba(255, 255, 255, .8);
    }

    #bookingDetailBody .bd-donut-center .pct {
        font-size: 17px;
        font-weight: 700;
    }

    #bookingDetailBody .bd-donut-center .lbl {
        font-size: 9.5px;
        color: var(--ss-muted);
        text-transform: uppercase;
        letter-spacing: .3px;
    }

    #bookingDetailBody .bd-legend {
        font-size: 12px;
    }

    #bookingDetailBody .bd-legend-item {
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 4px 0;
    }

    #bookingDetailBody .bd-legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    #bookingDetailBody .bd-mitra-list {
        margin-top: 6px;
    }

    #bookingDetailBody .bd-mitra-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 7px 10px;
        border-radius: 6px;
        font-size: 12px;
        margin-bottom: 5px;
    }

    #bookingDetailBody .bd-mitra-item.paid {
        background: #F0FDF4;
        color: #15803d;
    }

    #bookingDetailBody .bd-mitra-item.unpaid {
        background: #FFF7ED;
        color: #C2410C;
    }

    @media (max-width: 640px) {
        #bookingDetailBody .bd-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        #bookingDetailBody .bd-grid>div {
            border-right: 1px solid #FDE4CE;
        }

        #bookingDetailBody .bd-grid>div:nth-child(3n) {
            border-right: 1px solid #FDE4CE;
        }

        #bookingDetailBody .bd-grid>div:nth-child(2n) {
            border-right: none;
        }

        #bookingDetailBody .bd-grid>div:nth-last-child(-n+3) {
            border-bottom: 1px solid #FDE4CE;
        }

        #bookingDetailBody .bd-grid>div:nth-last-child(-n+2) {
            border-bottom: none;
        }

        #bookingDetailBody .bd-summary {
            grid-template-columns: 1fr;
        }

        #bookingDetailBody .bd-cols {
            grid-template-columns: 1fr;
        }

        #bookingDetailBody .bd-chart-row {
            grid-template-columns: 1fr;
        }
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
                var statusLabels = {
                    draft: 'Draft',
                    confirmed: 'Confirmed',
                    ongoing: 'Ongoing',
                    completed: 'Completed',
                    cancelled: 'Cancelled'
                };
                var marginColor = data.margin >= 0 ? 'var(--ss-success)' : 'var(--ss-danger)';

                var html = '';
                html += '<div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">';
                html += '<div><div style="font-size:17px;font-weight:700;">' + b.customer_name + '</div>';
                html += '<div style="font-size:11.5px;color:var(--ss-muted);">' + b.booking_no + (b.customer_phone ? ' \u00b7 ' + b.customer_phone : '') + '</div></div>';
                html += '<span class="ss-badge" style="align-self:center;">' + (statusLabels[b.status] || b.status) + '</span>';
                html += '</div>';
                html += '<div style="height:1px;background:var(--ss-gray-1);margin:0 0 14px;"></div>';


                html += '<div class="bd-cols">';

                html += '<div class="bd-col">';

                html += '<div class="bd-grid">';
                html += '<div><strong>Tanggal Check-in</strong><span class="bd-val">' + b.start_date + ' s/d ' + b.end_date + '</span></div>';
                html += '<div><strong>Durasi</strong><span class="bd-val">' + data.durationLabel + '</span></div>';
                html += '<div><strong>Total Pax</strong><span class="bd-val">' + b.pax_count + ' orang</span></div>';
                html += '<div><strong>Paket</strong><span class="bd-val">' + (b.package_name || '-') + '</span></div>';
                html += '<div><strong>Penginapan</strong><span class="bd-val">' + data.accommodationInfo + '</span></div>';
                html += '<div><strong>Total RAB/Penawaran</strong><span class="bd-val" style="color:var(--ss-ocean);">' + fmt(data.totalRab) + '</span></div>';
                html += '</div>';

                html += '<div class="bd-summary">';
                html += '<div class="bd-summary-box" style="background:var(--ss-gray-1);"><div class="bd-label">Total RAB/Penawaran</div><div class="bd-value">' + fmt(data.totalRab) + '</div></div>';
                html += '<div class="bd-summary-box" style="background:#FEF2F2;"><div class="bd-label" style="color:var(--ss-danger);">Total Pengeluaran</div><div class="bd-value" style="color:var(--ss-danger);">' + fmt(data.totalExpense) + '</div></div>';
                html += '<div class="bd-summary-box" style="background:#F0FDF4;"><div class="bd-label" style="color:' + marginColor + ';">Margin</div><div class="bd-value" style="color:' + marginColor + ';">' + fmt(data.margin) + '</div></div>';
                html += '</div>';

                // Donut chart: proporsi margin (profit) vs pengeluaran, dihitung dari RAB yang sama dengan angka persen di tengah.
                var incomeVal = parseFloat(data.totalRab) || 0;
                var expenseVal = parseFloat(data.totalExpense) || 0;
                var profitPct = incomeVal > 0 ? (data.margin / incomeVal * 100) : 0;
                var profitPctColor = profitPct >= 0 ? 'var(--ss-success)' : 'var(--ss-danger)';
                var profitShare = Math.max(0, Math.min(100, profitPct));

                html += '<div class="bd-chart-row">';
                html += '<div class="bd-donut" style="background:conic-gradient(#16a34a 0% ' + profitShare + '%, #dc2626 ' + profitShare + '% 100%);">';
                html += '<div class="bd-donut-center"><div class="pct" style="color:' + profitPctColor + ';">' + profitPct.toFixed(1) + '%</div><div class="lbl">Profit</div></div>';
                html += '</div>';
                html += '<div class="bd-legend">';
                html += '<div class="bd-legend-item"><span class="bd-legend-dot" style="background:#16a34a;"></span> Pemasukan (RAB) &mdash; ' + fmt(incomeVal) + '</div>';
                html += '<div class="bd-legend-item"><span class="bd-legend-dot" style="background:#dc2626;"></span> Pengeluaran &mdash; ' + fmt(expenseVal) + '</div>';
                html += '<div style="margin-top:6px;color:var(--ss-muted);font-size:11px;">Margin bersih: <strong style="color:' + marginColor + ';">' + fmt(data.margin) + '</strong></div>';
                html += '</div>';
                html += '</div>';

                html += '<div class="bd-section-title">Item Booking (RAB)</div>';
                if (data.items.length === 0) {
                    html += '<div style="font-size:12px;color:var(--ss-muted);">Belum ada item.</div>';
                } else {
                    html += '<table class="ss-table"><thead><tr><th>Komponen</th><th style="width:80px;text-align:center;">Qty</th><th style="width:115px;white-space:nowrap;">Subtotal</th></tr></thead><tbody>';
                    data.items.forEach(function(it) {
                        var qtyNum = parseFloat(it.qty);
                        var qtyStr = (qtyNum % 1 === 0 ? qtyNum.toFixed(0) : qtyNum);
                        html += '<tr><td>' + it.component_name + (it.is_done == 1 ? ' <span style="color:var(--ss-success);">\u2713</span>' : '') + '</td>' +
                            '<td style="text-align:center;white-space:nowrap;">' + qtyStr + ' ' + (it.unit || '') + '</td>' +
                            '<td style="font-weight:600;white-space:nowrap;">' + fmt(it.total_sell) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }
                html += '</div>';

                // Riwayat DP/pembayaran: kalau tamu bayar bertahap (DP 1, DP 2, dst) semua baris tampil di sini.
                var paidColor = data.remainingPayment <= 0 && data.totalInvoiceAmount > 0 ? 'var(--ss-success)' : 'var(--ss-warning, #d97706)';
                html += '<div class="bd-section-title">Riwayat Pembayaran (DP)</div>';
                if (!data.payments || data.payments.length === 0) {
                    html += '<div style="font-size:12px;color:var(--ss-muted);">Belum ada pembayaran/DP tercatat untuk booking ini.</div>';
                } else {
                    html += '<table class="ss-table"><thead><tr><th style="white-space:nowrap;">Tanggal</th><th>Metode</th><th style="width:115px;white-space:nowrap;">Jumlah</th></tr></thead><tbody>';
                    data.payments.forEach(function(py, idx) {
                        var payLabel = (idx === 0 ? 'DP 1' : (idx === data.payments.length - 1 && data.remainingPayment <= 0 ? 'Pelunasan' : 'DP ' + (idx + 1)));
                        html += '<tr><td style="white-space:nowrap;">' + py.payment_date + '</td>' +
                            '<td>' + payLabel + (py.method ? ' <small style="color:var(--ss-muted);">(' + py.method + ')</small>' : '') + '</td>' +
                            '<td style="font-weight:600;color:var(--ss-success);white-space:nowrap;">' + fmt(py.amount) + '</td></tr>';
                    });
                    html += '<tfoot><tr><td colspan="2" style="text-align:right;font-weight:700;">Total Dibayar</td>' +
                        '<td style="font-weight:700;color:var(--ss-success);white-space:nowrap;">' + fmt(data.totalPaid) + '</td></tr>';
                    html += '<tr><td colspan="2" style="text-align:right;font-weight:700;">Sisa Tagihan</td>' +
                        '<td style="font-weight:700;color:' + paidColor + ';white-space:nowrap;">' + fmt(data.remainingPayment) + '</td></tr></tfoot>';
                    html += '</table>';
                }
                html += '</div>';

                html += '<div class="bd-col bd-col-expense">';
                html += '<div class="bd-section-title"><span>Pengeluaran Trip Ini (dari Finance)</span>';
                if (data.expenses.length > 0) {
                    html += '<button type="button" class="ss-btn ss-btn-outline ss-btn-sm" onclick="printBookingExpenses()">Cetak / Simpan PDF</button>';
                }
                html += '</div>';
                if (data.expenses.length === 0) {
                    html += '<div style="font-size:12px;color:var(--ss-muted);">Belum ada pengeluaran dicatat di Finance untuk trip ini.</div>';
                } else {
                    html += '<table class="ss-table"><thead><tr><th style="white-space:nowrap;">Tanggal</th><th>Keterangan</th><th style="width:100px;white-space:nowrap;">Jumlah</th></tr></thead><tbody>';
                    data.expenses.forEach(function(ex) {
                        html += '<tr><td style="white-space:nowrap;">' + ex.transaction_date + '</td>' +
                            '<td>' + ex.description + (ex.category ? '<br><small style="color:var(--ss-muted);">' + ex.category + '</small>' : '') + '</td>' +
                            '<td style="font-weight:600;color:var(--ss-danger);white-space:nowrap;">' + fmt(ex.amount) + '</td></tr>';
                    });
                    html += '<tfoot><tr><td colspan="2" style="text-align:right;font-weight:700;">Total Pengeluaran</td>' +
                        '<td style="font-weight:700;color:var(--ss-danger);white-space:nowrap;">' + fmt(expenseVal) + '</td></tr></tfoot>';
                    html += '</table>';
                }
                html += '</div>';

                html += '</div>';

                // Simpan konteks untuk tombol Cetak/Simpan PDF (hindari embed JSON di atribut onclick).
                window.__bookingDetailPrintCtx = {
                    booking: b,
                    expenses: data.expenses,
                    totalExpense: expenseVal
                };



                // Checklist layanan mitra: pakai data terstruktur dari detail layanan paket
                // (component_code != 'paket'), bukan tebakan kata kunci dari teks pengeluaran.
                html += '<div class="bd-section-title">Checklist Pembayaran ke Mitra</div>';
                html += '<div class="bd-mitra-list">';
                if (!data.mitraItems || data.mitraItems.length === 0) {
                    html += '<div style="font-size:12px;color:var(--ss-muted);">Belum ada detail layanan mitra. Isi "Detail Layanan dalam Paket" di menu Paket Wisata (tiket kapal, penginapan, rental, dll) agar tagihan mitra tampil di sini.</div>';
                } else {
                    data.mitraItems.forEach(function(it) {
                        var isPaid = it.is_paid_mitra == 1;
                        html += '<div class="bd-mitra-item ' + (isPaid ? 'paid' : 'unpaid') + '">';
                        html += '<span>' + (isPaid ? '\u2713' : '\u26a0') + ' ' + it.component_name + '</span>';
                        html += '<strong>' + (isPaid ? fmt(it.total_cost) + ' (sudah dibayar)' : fmt(it.total_cost) + ' (belum dibayar)') + '</strong>';
                        html += '</div>';
                    });
                    html += '<div style="margin-top:6px;color:var(--ss-muted);font-size:11px;">Tandai pembayaran mitra di halaman <a href="bookings.php?view=' + b.id + '">Detail Booking</a> &rarr; Pembayaran ke Mitra.</div>';
                }
                html += '</div>';

                html += '<div style="margin-top:16px;display:flex;gap:8px;">';
                html += '<a href="bookings.php?view=' + b.id + '" class="ss-btn ss-btn-outline ss-btn-sm">Buka Halaman Booking</a>';
                html += '<a href="finance.php?customer_id=' + b.customer_id + '" class="ss-btn ss-btn-outline ss-btn-sm">Lihat di Finance</a>';
                html += '</div>';

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

    // Geser (swipe) timeline ke kiri/kanan untuk pindah ke bulan berikutnya/sebelumnya, dengan animasi slide yang halus.
    (function() {
        var scroller = document.getElementById('calTimelineScroll');
        var timeline = document.getElementById('calTimeline');
        if (!scroller || !timeline) return;

        var prevMonthVal = scroller.dataset.prevMonth;
        var nextMonthVal = scroller.dataset.nextMonth;
        var isDown = false,
            startX = 0,
            dx = 0,
            dragged = false,
            width = 0;
        var THRESHOLD = 90;

        function setTransition(on) {
            timeline.style.transition = on ? 'transform .28s cubic-bezier(.22,.9,.36,1), opacity .28s ease' : 'none';
        }

        scroller.addEventListener('pointerdown', function(e) {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            isDown = true;
            dragged = false;
            dx = 0;
            startX = e.clientX;
            width = scroller.clientWidth;
            setTransition(false);
            scroller.classList.add('is-dragging');
        });
        window.addEventListener('pointermove', function(e) {
            if (!isDown) return;
            e.preventDefault();
            dx = e.clientX - startX;
            if (Math.abs(dx) > 4) dragged = true;
            // Beri efek "tertahan" (rubber-band) kalau ditarik ke arah tanpa bulan berikutnya/sebelumnya.
            var limited = dx;
            if (dx < 0 && !nextMonthVal) limited = dx * 0.25;
            if (dx > 0 && !prevMonthVal) limited = dx * 0.25;
            timeline.style.transform = 'translateX(' + limited + 'px)';
        });

        function endDrag() {
            if (!isDown) return;
            isDown = false;
            scroller.classList.remove('is-dragging');
            setTransition(true);

            var goNext = dx <= -THRESHOLD && nextMonthVal;
            var goPrev = dx >= THRESHOLD && prevMonthVal;

            if (goNext || goPrev) {
                timeline.style.transform = 'translateX(' + (goNext ? -width : width) + 'px)';
                timeline.style.opacity = '0';
                window.setTimeout(function() {
                    window.location.href = '?month=' + (goNext ? nextMonthVal : prevMonthVal);
                }, 240);
            } else {
                timeline.style.transform = 'translateX(0)';
            }
        }
        window.addEventListener('pointerup', endDrag);
        window.addEventListener('pointercancel', endDrag);
        // Cegah klik baris terbuka modal detail kalau baru saja dipakai untuk swipe.
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
