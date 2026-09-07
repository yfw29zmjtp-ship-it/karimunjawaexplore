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

    .ob-cal-scroll {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .ob-cal-grid {
        display: grid;
        grid-auto-rows: 22px;
        align-items: center;
    }

    .ob-cal-head-name {
        position: sticky;
        left: 0;
        background: #fff;
        z-index: 2;
        font-size: 9px;
        font-weight: 700;
        color: var(--muted);
        padding-left: 2px;
    }

    .ob-cal-daynum {
        font-size: 8.5px;
        text-align: center;
        color: var(--muted);
    }

    .ob-cal-daynum.today {
        color: var(--ocean);
        font-weight: 800;
    }

    .ob-cal-name {
        position: sticky;
        left: 0;
        background: #fff;
        z-index: 1;
        padding-right: 6px;
        font-size: 10.5px;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border-right: 2px solid var(--border);
    }

    .ob-cal-name .sub {
        font-size: 8.5px;
        font-weight: 400;
        color: var(--muted);
    }

    .ob-cal-cell {
        height: 14px;
        border-radius: 2px;
        background: #F1F5F9;
        margin: 0 1px;
    }

    .ob-cal-cell.on {
        background: var(--ocean);
    }

    .ob-cal-name,
    .ob-cal-cell {
        cursor: pointer;
    }

    .ob-cal-legend {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 10.5px;
        color: var(--muted);
        margin-top: 10px;
    }

    .ob-cal-legend span.dot {
        width: 10px;
        height: 10px;
        border-radius: 2px;
        background: var(--ocean);
        display: inline-block;
    }
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
                    <div class="ob-cal-name" onclick="openBookingDetail(<?php echo (int)$b['id']; ?>)">
                        <?php echo htmlspecialchars($b['customer_name']); ?>
                        <div class="sub"><?php echo htmlspecialchars($b['booking_no']); ?> · <?php echo (int)$b['pax_count']; ?> pax</div>
                    </div>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <div class="ob-cal-cell <?php echo ($d >= $s && $d <= $e) ? 'on' : ''; ?>" onclick="openBookingDetail(<?php echo (int)$b['id']; ?>)"></div>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ob-cal-legend"><span class="dot"></span> Reservasi confirmed · geser ke samping untuk lihat tanggal lain</div>
    <?php endif; ?>
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
</script>

<?php include 'owner-mobile-footer.php'; ?>