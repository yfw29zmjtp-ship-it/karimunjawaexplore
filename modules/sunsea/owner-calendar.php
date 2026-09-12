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
sunseaEnsureFinanceSchema($pdo);

// ---- AJAX: reservation + finance expense detail for the modal ----
if (($_GET['ajax'] ?? '') === 'detail' && (int)($_GET['id'] ?? 0) > 0) {
    header('Content-Type: application/json');
    $bId = (int)$_GET['id'];

    $b = $pdo->prepare("
        SELECT b.*, c.name AS customer_name, c.phone AS customer_phone,
            p.name AS package_name
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

    // Info penginapan: cari item Penginapan dari komponen, atau catatan hotel manual di notes booking.
    $accommodationInfo = '-';
    foreach ($items as $it) {
        if (stripos($it['component_name'], 'penginapan') !== false) {
            $accommodationInfo = preg_replace('/^Penginapan:\s*/i', '', $it['component_name']);
            break;
        }
    }
    if ($accommodationInfo === '-' && preg_match('/Hotel:\s*([^-]+)/i', (string)($booking['notes'] ?? ''), $mHotel)) {
        $accommodationInfo = trim($mHotel[1]);
    }

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

    // Info DP/pembayaran: invoice dibuat otomatis dari booking dan ditautkan lewat internal_notes
    // 'booking_id:<id>' (jalur normal) atau pola 'Generated from Reservasi: <no>' (jalur konversi invoice manual).
    $invStmt = $pdo->prepare("SELECT invoice_no, status, total_amount, paid_amount, remaining_amount FROM invoices WHERE internal_notes=? OR internal_notes=? ORDER BY id DESC LIMIT 1");
    $invStmt->execute(['booking_id:' . $bId, 'Generated from Reservasi: ' . $booking['booking_no']]);
    $linkedInvoice = $invStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($linkedInvoice) {
        // Hitung ulang sisa tagihan dari total - terbayar, jangan percaya kolom remaining_amount yang bisa basi.
        $linkedInvoice['remaining_amount'] = max(0, (float)$linkedInvoice['total_amount'] - (float)$linkedInvoice['paid_amount']);
    }

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
        'invoice'           => $linkedInvoice,
    ]);
    exit;
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$startMonth = date('Y-m-01', strtotime($month . '-01'));
$endMonth = date('Y-m-t', strtotime($month . '-01'));
$daysInMonth = (int)date('t', strtotime($startMonth));
$todayDay = (date('Y-m') === $month) ? (int)date('j') : 0;

$prevMonth = date('Y-m', strtotime($startMonth . ' -1 month'));
$nextMonth = date('Y-m', strtotime($startMonth . ' +1 month'));

// Timeline ditampilkan menyambung 2 bulan sekaligus (bulan ini + bulan depan) supaya geser/drag
// tidak "lompat" ke halaman kosong saat bulan depan belum ada booking - tanggalnya tetap kelihatan,
// dengan garis batas + nama bulan di atas kolom bulan berikutnya.
$nextMonthEnd = date('Y-m-t', strtotime($nextMonth . '-01'));
$daysInNextMonth = (int)date('t', strtotime($nextMonth . '-01'));
$calTotalDays = $daysInMonth + $daysInNextMonth;

$calDates = [];
$calDateIndex = [];
$curDate = strtotime($startMonth);
for ($i = 1; $i <= $calTotalDays; $i++) {
    $dateStr = date('Y-m-d', $curDate);
    $calDates[] = [
        'date' => $dateStr,
        'day' => (int)date('j', $curDate),
        'dow' => (int)date('N', $curDate),
        'isMonthStart' => $i > 1 && (int)date('j', $curDate) === 1,
    ];
    $calDateIndex[$dateStr] = $i;
    $curDate = strtotime('+1 day', $curDate);
}
$todayIndex = $calDateIndex[date('Y-m-d')] ?? 0;

$bookings = $pdo->prepare("
    SELECT b.id, b.booking_no, b.start_date, b.end_date, b.pax_count, c.name AS customer_name, p.name AS package_name,
        (SELECT COUNT(*) FROM booking_order_items i WHERE i.booking_id=b.id AND i.is_done=0) as pending_count
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    LEFT JOIN trip_packages p ON p.id = b.package_id
    WHERE b.end_date >= ? AND b.start_date <= ? AND b.status = 'confirmed'
    ORDER BY b.start_date, b.id
");
$bookings->execute([$startMonth, $nextMonthEnd]);
$bookings = $bookings->fetchAll();

// Warna balok reservasi jadi penanda durasi/tipe trip (samakan dengan kalender di system).
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

function obCalBarColor(string $durationLabel, ?string $packageName, array $durationColors, string $honeymoonColor, string $defaultColor, bool $isCompleted = false, string $completedColor = '#94A3B8'): string
{
    if ($isCompleted) {
        return $completedColor;
    }
    if ($packageName && stripos($packageName, 'honeymoon') !== false) {
        return $honeymoonColor;
    }
    return $durationColors[$durationLabel] ?? $defaultColor;
}

// Tamu yang akan check-in dalam 7 hari ke depan (untuk widget di bawah kalender)
$todayDate = date('Y-m-d');
$weekAhead = date('Y-m-d', strtotime('+7 days'));
$checkinsThisWeek = $pdo->prepare("
    SELECT b.id, b.booking_no, b.start_date, b.end_date, b.pax_count, c.name AS customer_name
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    WHERE b.status = 'confirmed' AND b.start_date BETWEEN ? AND ?
    ORDER BY b.start_date, b.id
");
$checkinsThisWeek->execute([$todayDate, $weekAhead]);
$checkinsThisWeek = $checkinsThisWeek->fetchAll();

$pageTitle = 'Kalender Booking';
include 'owner-mobile-header.php';
?>

<style>
    .ob-cal-nav {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 12px;
    }

    .ob-cal-nav a {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: #fff;
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        color: var(--ocean);
        flex-shrink: 0;
    }

    .ob-cal-nav a svg {
        width: 16px;
        height: 16px;
    }

    .ob-cal-month {
        font-size: 14px;
        font-weight: 800;
        text-align: center;
    }

    /* Timeline balok reservasi, disamakan persis dengan tampilan Kalender Booking di system (calendar.php) */
    .cal-timeline-scroll {
        overflow-x: auto;
        border-radius: 10px;
        border: 1px solid var(--border);
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
        grid-template-columns: 118px repeat(var(--cal-days), minmax(16px, 1fr));
    }

    .cal-name-col {
        position: sticky;
        left: 0;
        z-index: 2;
        background: var(--sky);
        border-right: 1px solid var(--border);
        padding: 8px 10px;
        font-size: 10.5px;
        font-weight: 800;
        color: var(--text);
        text-transform: uppercase;
        letter-spacing: .04em;
        display: flex;
        align-items: center;
    }

    .cal-day-col {
        text-align: center;
        padding: 6px 1px;
        font-size: 9.5px;
        font-weight: 700;
        color: var(--muted);
        background: var(--sky);
        border-left: 1px solid rgba(3, 105, 161, .06);
    }

    .cal-day-col.is-weekend {
        color: var(--ocean);
        background: #E0F2FE;
    }

    .cal-day-col.is-today {
        background: var(--ocean);
        color: #fff;
        border-radius: 6px 6px 0 0;
    }

    .cal-month-label-row {
        background: var(--sky);
    }

    .cal-month-label {
        text-align: center;
        padding: 4px;
        font-size: 9.5px;
        font-weight: 800;
        color: var(--text);
        text-transform: uppercase;
        letter-spacing: .03em;
        border-bottom: 1px solid var(--border);
    }

    .cal-month-boundary {
        border-left: 2px solid var(--ocean) !important;
    }

    .cal-row {
        display: grid;
        grid-template-columns: 118px repeat(var(--cal-days), minmax(16px, 1fr));
        align-items: center;
        cursor: pointer;
        transition: background .15s ease;
    }

    .cal-row:active {
        background: var(--sky);
    }

    .cal-guest {
        position: sticky;
        left: 0;
        z-index: 1;
        background: #fff;
        border-right: 1px solid var(--border);
        padding: 8px 6px;
        overflow: hidden;
    }

    .cal-guest-avatar {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        color: #fff;
        font-size: 8.5px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-right: 5px;
    }

    .cal-guest-name {
        font-size: 10px;
        font-weight: 700;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .cal-guest-meta {
        font-size: 8px;
        font-weight: 500;
        color: var(--muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .cal-cell {
        height: 24px;
        border-left: 1px solid rgba(15, 23, 42, .03);
        border-bottom: 1px solid rgba(15, 23, 42, .03);
    }

    .cal-cell.is-weekend {
        background: #F8FAFC;
    }

    .cal-cell.is-today {
        background: #E0F2FE;
        box-shadow: inset 1px 0 0 var(--ocean), inset -1px 0 0 var(--ocean);
    }

    .cal-bar {
        height: 17px;
        margin: 0 1px;
        border-radius: 999px;
        box-shadow: 0 2px 6px rgba(3, 105, 161, .28);
        display: flex;
        align-items: center;
    }

    .cal-bar-label {
        font-size: 9px;
        font-weight: 700;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        padding-left: 6px;
        letter-spacing: .01em;
    }

    .cal-bar-continue {
        font-size: 10px;
        font-weight: 900;
        color: #fff;
        padding: 0 3px;
    }

    .ob-cal-legend {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        font-size: 9.5px;
        color: var(--muted);
        margin-top: 10px;
    }

    .ob-cal-legend span.dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
    }
</style>

<div class="ob-cal-nav">
    <a href="?month=<?php echo $prevMonth; ?>"><i data-feather="chevron-left"></i></a>
    <div class="ob-cal-month"><?php echo date('F Y', strtotime($startMonth)); ?></div>
    <a href="?month=<?php echo $nextMonth; ?>"><i data-feather="chevron-right"></i></a>
</div>

<div class="ob-section">
    <?php
    $obCalLegend = [
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
    <?php if (empty($bookings)): ?>
        <div class="ob-empty" style="margin-bottom:6px;">Tidak ada reservasi confirmed pada bulan ini.</div>
    <?php endif; ?>
    <div class="cal-timeline-scroll" id="calTimelineScroll" data-prev-month="<?php echo $prevMonth; ?>" data-next-month="<?php echo $nextMonth; ?>">
        <div class="cal-timeline" id="calTimeline" style="--cal-days:<?php echo $calTotalDays; ?>;">
            <div class="cal-day-row cal-month-label-row">
                <div class="cal-name-col" style="background:transparent;border-right:1px solid var(--border);"></div>
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

            <?php foreach ($bookings as $b):
                $s = $calDateIndex[max($b['start_date'], $startMonth)] ?? 1;
                $e = $calDateIndex[min($b['end_date'], $nextMonthEnd)] ?? $calTotalDays;
                $nights = max(0, (int)round((strtotime($b['end_date']) - strtotime($b['start_date'])) / 86400));
                $durationLabel = ($nights + 1) . 'H' . $nights . 'M';
                $isCompletedTrip = strtotime($b['end_date']) < strtotime(date('Y-m-d'));
                $barColor = obCalBarColor($durationLabel, $b['package_name'], $calDurationColors, $calHoneymoonColor, $calDefaultColor, $isCompletedTrip, $calCompletedColor);
                $initial = mb_strtoupper(mb_substr($b['customer_name'], 0, 1));
                $clippedLeft = strtotime($b['start_date']) < strtotime($startMonth);
                $clippedRight = strtotime($b['end_date']) > strtotime($nextMonthEnd);
                $barTitle = htmlspecialchars(date('d M Y', strtotime($b['start_date'])) . ' - ' . date('d M Y', strtotime($b['end_date'])) . ' (' . $durationLabel . ')');
            ?>
                <div class="cal-row" onclick="openBookingDetail(<?php echo (int)$b['id']; ?>)">
                    <div class="cal-guest">
                        <div style="display:flex;align-items:center;">
                            <span class="cal-guest-avatar" style="background:linear-gradient(135deg,<?php echo $barColor; ?>,#0EA5E9);"><?php echo htmlspecialchars($initial); ?></span>
                            <div style="min-width:0;">
                                <div class="cal-guest-name">
                                    <?php if ((int)$b['pending_count'] > 0): ?>
                                        <span title="Ada layanan belum selesai" style="display:inline-block;width:5px;height:5px;border-radius:50%;background:#dc2626;margin-right:3px;"></span>
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
                            <div class="cal-cell<?php echo $isTodayCol ? ' is-today' : ''; ?><?php echo $cd['isMonthStart'] ? ' cal-month-boundary' : ''; ?>" style="padding:3px 0;" title="<?php echo $barTitle; ?>">
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
        </div>
    </div>
    <div class="ob-cal-legend">
        <?php foreach ($obCalLegend as $label => $color): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;">
                <span class="dot" style="background:<?php echo $color; ?>;"></span>
                <?php echo htmlspecialchars($label); ?>
            </span>
        <?php endforeach; ?>
        <span style="color:var(--muted);">&middot; Geser ke samping untuk lihat tanggal lain (bulan depan tetap tersambung)</span>
    </div>
</div>


<div class="ob-section">
    <div class="ob-section-head">
        <div class="ob-section-title">Tamu Check-in Minggu Ini</div>
    </div>
    <?php if (empty($checkinsThisWeek)): ?>
        <div class="ob-empty">Tidak ada tamu check-in dalam 7 hari ke depan.</div>
    <?php else: ?>
        <?php foreach ($checkinsThisWeek as $ci): ?>
            <a href="javascript:void(0)" onclick="openBookingDetail(<?php echo (int)$ci['id']; ?>)" class="ob-row">
                <div>
                    <div class="ob-row-title"><?php echo htmlspecialchars($ci['customer_name']); ?></div>
                    <div class="ob-row-sub"><?php echo htmlspecialchars($ci['booking_no']); ?> · <?php echo (int)$ci['pax_count']; ?> pax</div>
                </div>
                <div class="ob-row-meta">
                    <?php echo date('d M', strtotime($ci['start_date'])); ?> - <?php echo date('d M Y', strtotime($ci['end_date'])); ?>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal Detail Reservasi + Finance (read-only, owner) -->
<div id="bookingDetailOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:1000;align-items:stretch;justify-content:stretch;">
    <div style="width:100%;height:100%;display:flex;flex-direction:column;background:#F8FAFC;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border);flex-shrink:0;background:#fff;">
            <div style="font-size:14px;font-weight:800;">Detail Reservasi</div>
            <button type="button" onclick="closeBookingDetail()" style="background:none;border:none;cursor:pointer;color:var(--muted);padding:4px;">
                <i data-feather="x"></i>
            </button>
        </div>
        <div id="bookingDetailBody" style="flex:1;overflow:auto;padding:14px;width:100%;">
            <div style="text-align:center;padding:30px;color:var(--muted);">Memuat...</div>
        </div>
    </div>
</div>

<style>
    #bookingDetailBody .bd-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0;
        font-size: 11.5px;
        margin-bottom: 12px;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 10px;
        overflow: hidden;
    }

    #bookingDetailBody .bd-grid>div {
        padding: 10px 12px;
        border-right: 1px solid var(--border);
        border-bottom: 1px solid var(--border);
    }

    #bookingDetailBody .bd-grid>div:nth-child(2n) {
        border-right: none;
    }

    #bookingDetailBody .bd-grid>div:nth-last-child(-n+2) {
        border-bottom: none;
    }

    #bookingDetailBody .bd-grid strong {
        display: block;
        color: var(--muted);
        font-weight: 600;
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: .3px;
        margin-bottom: 3px;
    }

    #bookingDetailBody .bd-grid .bd-val {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: var(--text);
    }

    #bookingDetailBody .bd-section-title {
        font-size: 12.5px;
        font-weight: 800;
        margin: 14px 0 8px;
        color: var(--text);
        padding-top: 4px;
        border-top: 1px solid var(--border);
    }

    #bookingDetailBody table.bd-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
        background: #fff;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid var(--border);
    }

    #bookingDetailBody table.bd-table th {
        text-align: left;
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: .2px;
        color: var(--muted);
        padding: 6px 8px;
        background: var(--sky);
        border-bottom: 1px solid var(--border);
    }

    #bookingDetailBody table.bd-table td {
        padding: 6px 8px;
        border-bottom: 1px solid var(--border);
    }

    #bookingDetailBody table.bd-table tr:last-child td {
        border-bottom: none;
    }

    #bookingDetailBody .bd-summary {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        margin-top: 12px;
    }

    #bookingDetailBody .bd-summary-box {
        border-radius: 8px;
        padding: 9px 10px;
    }

    #bookingDetailBody .bd-summary-box .bd-label {
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: .2px;
        opacity: .85;
    }

    #bookingDetailBody .bd-summary-box .bd-value {
        font-size: 13.5px;
        font-weight: 800;
        margin-top: 2px;
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
        font-size: 11.5px;
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

    @media (max-width: 400px) {
        #bookingDetailBody .bd-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
    function openBookingDetail(id) {
        var overlay = document.getElementById('bookingDetailOverlay');
        var body = document.getElementById('bookingDetailBody');
        body.innerHTML = '<div style="text-align:center;padding:30px;color:var(--muted);">Memuat...</div>';
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        fetch('owner-calendar.php?ajax=detail&id=' + id)
            .then(function(r) {
                return r.json();
            })
            .then(function(data) {
                if (data.error) {
                    body.innerHTML = '<div style="color:var(--danger);">' + data.error + '</div>';
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
                var marginColor = data.margin >= 0 ? 'var(--success)' : 'var(--danger)';

                var html = '';
                html += '<div style="margin-bottom:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">';
                html += '<div><div style="font-size:15px;font-weight:800;">' + b.customer_name + '</div>';
                html += '<div style="font-size:10.5px;color:var(--muted);">' + b.booking_no + (b.customer_phone ? ' \u00b7 ' + b.customer_phone : '') + '</div></div>';
                html += '<span class="ob-badge ob-badge-confirmed">' + (statusLabels[b.status] || b.status) + '</span>';
                html += '</div>';

                html += '<div class="bd-grid">';
                html += '<div><strong>Tanggal Check-in</strong><span class="bd-val">' + b.start_date + ' s/d ' + b.end_date + '</span></div>';
                html += '<div><strong>Durasi</strong><span class="bd-val">' + data.durationLabel + '</span></div>';
                html += '<div><strong>Total Pax</strong><span class="bd-val">' + b.pax_count + ' orang</span></div>';
                html += '<div><strong>Paket</strong><span class="bd-val">' + (b.package_name || '-') + '</span></div>';
                html += '<div><strong>Penginapan</strong><span class="bd-val">' + data.accommodationInfo + '</span></div>';
                html += '<div><strong>Total RAB/Penawaran</strong><span class="bd-val" style="color:var(--ocean);">' + fmt(data.totalRab) + '</span></div>';
                html += '</div>';

                html += '<div class="bd-section-title">Info Pembayaran (DP)</div>';
                if (!data.invoice) {
                    html += '<div style="font-size:11.5px;color:var(--muted);">Belum ada invoice/DP tercatat untuk reservasi ini.</div>';
                } else {
                    var inv = data.invoice;
                    var invStatusLabels = {
                        issued: 'Belum Dibayar',
                        partial: 'DP Diterima',
                        paid: 'Lunas',
                        cancelled: 'Batal'
                    };
                    var dpBadgeClass = inv.status === 'paid' ? 'ob-badge-paid' : (inv.status === 'partial' ? 'ob-badge-partial' : 'ob-badge-issued');
                    html += '<div class="bd-grid" style="grid-template-columns:repeat(3,1fr);">';
                    html += '<div><strong>No. Invoice</strong><span class="bd-val" style="font-size:11.5px;">' + inv.invoice_no + '</span></div>';
                    html += '<div><strong>Sudah Dibayar (DP)</strong><span class="bd-val" style="color:var(--success);">' + fmt(inv.paid_amount) + '</span></div>';
                    html += '<div><strong>Sisa Tagihan</strong><span class="bd-val" style="color:' + (parseFloat(inv.remaining_amount) > 0 ? 'var(--danger)' : 'var(--success)') + ';">' + fmt(inv.remaining_amount) + '</span></div>';
                    html += '</div>';
                    html += '<div style="margin:-6px 0 12px;"><span class="ob-badge ' + dpBadgeClass + '">' + (invStatusLabels[inv.status] || inv.status) + '</span></div>';
                }

                html += '<div class="bd-section-title">Item Booking (RAB)</div>';
                if (data.items.length === 0) {
                    html += '<div style="font-size:11.5px;color:var(--muted);">Belum ada item.</div>';
                } else {
                    html += '<table class="bd-table"><thead><tr><th>Komponen</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Subtotal</th></tr></thead><tbody>';
                    data.items.forEach(function(it) {
                        var qtyNum = parseFloat(it.qty);
                        var qtyStr = (qtyNum % 1 === 0 ? qtyNum.toFixed(0) : qtyNum);
                        html += '<tr><td>' + it.component_name + (it.is_done == 1 ? ' <span style="color:var(--success);">\u2713</span>' : '') + '</td>' +
                            '<td style="text-align:center;white-space:nowrap;">' + qtyStr + ' ' + (it.unit || '') + '</td>' +
                            '<td style="text-align:right;font-weight:700;white-space:nowrap;">' + fmt(it.total_sell) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                html += '<div class="bd-section-title">Pengeluaran Trip Ini (dari Finance)</div>';
                if (data.expenses.length === 0) {
                    html += '<div style="font-size:11.5px;color:var(--muted);">Belum ada pengeluaran dicatat di Finance untuk trip ini.</div>';
                } else {
                    html += '<table class="bd-table"><thead><tr><th>Tanggal</th><th>Keterangan</th><th style="text-align:right;">Jumlah</th></tr></thead><tbody>';
                    data.expenses.forEach(function(ex) {
                        html += '<tr><td style="white-space:nowrap;">' + ex.transaction_date + '</td>' +
                            '<td>' + ex.description + (ex.category ? '<br><small style="color:var(--muted);">' + ex.category + '</small>' : '') + '</td>' +
                            '<td style="text-align:right;font-weight:700;color:var(--danger);white-space:nowrap;">' + fmt(ex.amount) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                html += '<div class="bd-summary">';
                html += '<div class="bd-summary-box" style="background:var(--sky);"><div class="bd-label">Total RAB</div><div class="bd-value">' + fmt(data.totalRab) + '</div></div>';
                html += '<div class="bd-summary-box" style="background:#FEF2F2;"><div class="bd-label" style="color:var(--danger);">Pengeluaran</div><div class="bd-value" style="color:var(--danger);">' + fmt(data.totalExpense) + '</div></div>';
                html += '<div class="bd-summary-box" style="background:#F0FDF4;"><div class="bd-label" style="color:' + marginColor + ';">Margin</div><div class="bd-value" style="color:' + marginColor + ';">' + fmt(data.margin) + '</div></div>';
                html += '</div>';

                html += '<div class="bd-section-title">Checklist Pembayaran ke Mitra</div>';
                html += '<div class="bd-mitra-list">';
                if (!data.mitraItems || data.mitraItems.length === 0) {
                    html += '<div style="font-size:11.5px;color:var(--muted);">Belum ada detail layanan mitra.</div>';
                } else {
                    data.mitraItems.forEach(function(it) {
                        var isPaid = it.is_paid_mitra == 1;
                        html += '<div class="bd-mitra-item ' + (isPaid ? 'paid' : 'unpaid') + '">';
                        html += '<span>' + (isPaid ? '\u2713' : '\u26a0') + ' ' + it.component_name + '</span>';
                        html += '<strong>' + (isPaid ? fmt(it.total_cost) + ' (lunas)' : fmt(it.total_cost) + ' (belum)') + '</strong>';
                        html += '</div>';
                    });
                }
                html += '</div>';

                html += '<div style="margin-top:14px;display:flex;gap:8px;">';
                html += '<a href="owner-booking-detail.php?id=' + b.id + '" class="ob-qbtn" style="flex:1;">Detail Booking</a>';
                html += '<a href="owner-finance.php" class="ob-qbtn" style="flex:1;">Lihat Finance</a>';
                html += '</div>';

                body.innerHTML = html;
                if (window.feather) feather.replace();
            })
            .catch(function() {
                body.innerHTML = '<div style="color:var(--danger);">Gagal memuat detail reservasi.</div>';
            });
    }

    function closeBookingDetail() {
        document.getElementById('bookingDetailOverlay').style.display = 'none';
        document.body.style.overflow = '';
    }

    // Geser (swipe) timeline ke kiri/kanan untuk pindah bulan, sama seperti kalender di system.
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
        scroller.addEventListener('click', function(e) {
            if (dragged) {
                e.stopPropagation();
                e.preventDefault();
            }
        }, true);
    })();
</script>

<?php include 'owner-mobile-footer.php'; ?>