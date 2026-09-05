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
        SELECT b.*, c.name AS customer_name, c.phone AS customer_phone, p.name AS package_name
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

    $items = $pdo->prepare("SELECT component_name, qty, unit, price_sell, total_sell, is_done FROM booking_order_items WHERE booking_id=? ORDER BY sort_order, id");
    $items->execute([$bId]);
    $items = $items->fetchAll();

    $expenses = $pdo->prepare("SELECT transaction_date, category, description, amount, reference, created_by FROM cash_book WHERE booking_id=? AND type='expense' ORDER BY transaction_date, id");
    $expenses->execute([$bId]);
    $expenses = $expenses->fetchAll();

    $totalExpense = 0;
    foreach ($expenses as $ex) $totalExpense += (float)$ex['amount'];

    $totalRab = 0;
    foreach ($items as $it) $totalRab += (float)$it['total_sell'];

    echo json_encode([
        'booking'      => $booking,
        'items'        => $items,
        'expenses'     => $expenses,
        'totalExpense' => $totalExpense,
        'totalRab'     => $totalRab,
        'margin'       => $totalRab - $totalExpense,
    ]);
    exit;
}

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
                <div style="display:grid;grid-template-columns:160px repeat(<?php echo $daysInMonth; ?>, 18px);gap:1px;align-items:center;margin-bottom:3px;cursor:pointer;" onclick="openBookingDetail(<?php echo $b['id']; ?>)">
                    <div style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php if ((int)$b['pending_count'] > 0): ?>
                            <span title="Ada layanan belum selesai" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#dc2626;margin-right:3px;"></span>
                        <?php endif; ?>
                        <a href="javascript:void(0)" onclick="event.stopPropagation();openBookingDetail(<?php echo $b['id']; ?>)" style="color:var(--ss-text);text-decoration:none;"><?php echo htmlspecialchars($b['customer_name']); ?></a>
                        <div style="font-size:9px;font-weight:400;color:var(--ss-muted);">
                            <a href="javascript:void(0)" onclick="event.stopPropagation();openBookingDetail(<?php echo $b['id']; ?>)" style="color:var(--ss-ocean);text-decoration:none;"><?php echo htmlspecialchars($b['booking_no']); ?></a>
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
                    <tr style="cursor:pointer;" onclick="openBookingDetail(<?php echo $b['id']; ?>)">
                        <td><a href="javascript:void(0)" onclick="event.stopPropagation();openBookingDetail(<?php echo $b['id']; ?>)" style="color:var(--ss-ocean);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($b['booking_no']); ?></a></td>
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
    #bookingDetailBody .bd-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px 14px; font-size: 12px; margin-bottom: 12px; background: var(--ss-gray-1); border-radius: 8px; padding: 10px 12px; }
    #bookingDetailBody .bd-grid strong { display: block; color: var(--ss-muted); font-weight: 600; font-size: 10.5px; text-transform: uppercase; letter-spacing: .3px; }
    #bookingDetailBody .bd-section-title { font-size: 12.5px; font-weight: 700; margin: 14px 0 6px; color: #0f172a; }
    #bookingDetailBody table.ss-table { font-size: 12px; margin-bottom: 0; }
    #bookingDetailBody table.ss-table th { font-size: 10.5px; padding: 5px 8px; }
    #bookingDetailBody table.ss-table td { padding: 5px 8px; }
    #bookingDetailBody .bd-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 14px; }
    #bookingDetailBody .bd-summary-box { border-radius: 8px; padding: 10px 12px; }
    #bookingDetailBody .bd-summary-box .bd-label { font-size: 10.5px; text-transform: uppercase; letter-spacing: .3px; opacity: .8; }
    #bookingDetailBody .bd-summary-box .bd-value { font-size: 15px; font-weight: 700; margin-top: 2px; }
    @media (max-width: 640px) {
        #bookingDetailBody .bd-grid { grid-template-columns: repeat(2, 1fr); }
        #bookingDetailBody .bd-summary { grid-template-columns: 1fr; }
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
                    draft: 'Draft', confirmed: 'Confirmed', ongoing: 'Ongoing', completed: 'Completed', cancelled: 'Cancelled'
                };
                var marginColor = data.margin >= 0 ? 'var(--ss-success)' : 'var(--ss-danger)';

                var html = '';
                html += '<div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">';
                html += '<div><div style="font-size:15px;font-weight:700;">' + b.customer_name + '</div>';
                html += '<div style="font-size:11.5px;color:var(--ss-muted);">' + b.booking_no + (b.customer_phone ? ' \u00b7 ' + b.customer_phone : '') + '</div></div>';
                html += '<span class="ss-badge" style="align-self:center;">' + (statusLabels[b.status] || b.status) + '</span>';
                html += '</div>';

                html += '<div class="bd-grid">';
                html += '<div><strong>Tanggal</strong>' + b.start_date + ' s/d ' + b.end_date + '</div>';
                html += '<div><strong>Pax</strong>' + b.pax_count + ' orang</div>';
                html += '<div><strong>Paket</strong>' + (b.package_name || '-') + '</div>';
                html += '<div><strong>Total RAB/Penawaran</strong>' + fmt(data.totalRab) + '</div>';
                html += '</div>';

                html += '<div class="bd-section-title">Item Booking (RAB)</div>';
                if (data.items.length === 0) {
                    html += '<div style="font-size:12px;color:var(--ss-muted);">Belum ada item.</div>';
                } else {
                    html += '<table class="ss-table"><thead><tr><th>Komponen</th><th style="width:70px;">Qty</th><th style="width:100px;">Subtotal</th></tr></thead><tbody>';
                    data.items.forEach(function(it) {
                        html += '<tr><td>' + it.component_name + (it.is_done == 1 ? ' <span style="color:var(--ss-success);">\u2713</span>' : '') + '</td>' +
                            '<td>' + it.qty + ' ' + (it.unit || '') + '</td>' +
                            '<td style="font-weight:600;">' + fmt(it.total_sell) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                html += '<div class="bd-section-title">Pengeluaran Trip Ini (dari Finance)</div>';
                if (data.expenses.length === 0) {
                    html += '<div style="font-size:12px;color:var(--ss-muted);">Belum ada pengeluaran dicatat di Finance untuk trip ini.</div>';
                } else {
                    html += '<table class="ss-table"><thead><tr><th style="width:75px;">Tanggal</th><th>Keterangan</th><th style="width:100px;">Jumlah</th></tr></thead><tbody>';
                    data.expenses.forEach(function(ex) {
                        html += '<tr><td>' + ex.transaction_date + '</td>' +
                            '<td>' + ex.description + (ex.category ? '<br><small style="color:var(--ss-muted);">' + ex.category + '</small>' : '') + '</td>' +
                            '<td style="font-weight:600;color:var(--ss-danger);">' + fmt(ex.amount) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                html += '<div class="bd-summary">';
                html += '<div class="bd-summary-box" style="background:var(--ss-gray-1);"><div class="bd-label">Total RAB/Penawaran</div><div class="bd-value">' + fmt(data.totalRab) + '</div></div>';
                html += '<div class="bd-summary-box" style="background:#FEF2F2;"><div class="bd-label" style="color:var(--ss-danger);">Total Pengeluaran</div><div class="bd-value" style="color:var(--ss-danger);">' + fmt(data.totalExpense) + '</div></div>';
                html += '<div class="bd-summary-box" style="background:#F0FDF4;"><div class="bd-label" style="color:' + marginColor + ';">Margin</div><div class="bd-value" style="color:' + marginColor + ';">' + fmt(data.margin) + '</div></div>';
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
</script>

<?php include 'layout-footer.php';
