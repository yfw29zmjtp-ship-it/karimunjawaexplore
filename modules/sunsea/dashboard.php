<?php

/**
 * Sunsea - Beranda
 * Urutan: Perlu ditindaklanjuti → 4 angka utama bulan ini → grafik jumlah tamu + tamu yang akan datang → penawaran & invoice terbaru
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$pageTitle  = 'Beranda';
$activePage = 'dashboard';

try {
    $pdo = getSunseaConnection();

    // Stats: Quotations
    $qStats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN status='sent'  THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved
        FROM quotations
    ")->fetch();

    // Stats: Invoices
    $iStats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status='issued'   THEN 1 ELSE 0 END) as issued,
            SUM(CASE WHEN status='partial'  THEN 1 ELSE 0 END) as partial,
            SUM(CASE WHEN status='paid'     THEN 1 ELSE 0 END) as paid,
            SUM(CASE WHEN status='overdue'  THEN 1 ELSE 0 END) as overdue,
            COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)), 0) as outstanding
        FROM invoices
        WHERE status NOT IN ('cancelled')
    ")->fetch();

    // Stats: Customers
    $custCount = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE is_active=1")->fetchColumn();

    // Stats: Packages
    $pkgCount  = (int)$pdo->query("SELECT COUNT(*) FROM trip_packages WHERE is_active=1")->fetchColumn();

    // Stats: Bookings
    $bookingStats = $pdo->query("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status IN ('draft','confirmed','ongoing') THEN 1 ELSE 0 END) as active
        FROM booking_orders")->fetch();

    // Revenue this month
    $monthRevenue = (float)$pdo->query("
        SELECT COALESCE(SUM(amount),0) FROM payments 
        WHERE YEAR(payment_date)=YEAR(NOW()) AND MONTH(payment_date)=MONTH(NOW())
    ")->fetchColumn();

    // Pengeluaran bulan ini (semua tamu/trip) dari Buku Kas Operasional
    $monthExpense = (float)$pdo->query("
        SELECT COALESCE(SUM(amount),0) FROM cash_book
        WHERE type='expense' AND YEAR(transaction_date)=YEAR(NOW()) AND MONTH(transaction_date)=MONTH(NOW())
    ")->fetchColumn();
    // Profit dihitung per trip (tanggal mulai trip + RAB), bukan dari uang masuk/keluar bulan ini,
    // supaya DP yang masuk bulan lalu untuk trip bulan ini tidak bikin profit bulan ini minus.
    $tripProfit = sunseaTripProfit($pdo, date('Y-m-01'), date('Y-m-t'));
    $monthProfit = $tripProfit['profit'];

    // Recent quotations (5)
    $recentQuotations = $pdo->query("
        SELECT q.id, q.quotation_no, q.status, q.total_amount, q.trip_date, q.created_at,
               c.name as customer_name
        FROM quotations q
        JOIN customers c ON c.id = q.customer_id
        ORDER BY q.created_at DESC
        LIMIT 5
    ")->fetchAll();

    // Recent invoices (5)
    $recentInvoices = $pdo->query("
        SELECT i.id, i.invoice_no, i.status, i.total_amount, i.remaining_amount, i.due_date,
               c.name as customer_name
        FROM invoices i
        JOIN customers c ON c.id = i.customer_id
        ORDER BY i.created_at DESC
        LIMIT 5
    ")->fetchAll();


    // Total Pax: harian (30 hari terakhir), bulanan (12 bulan terakhir), tahunan (5 tahun terakhir).
    // Semua pemesanan yang tidak dibatalkan.
    $currentYear = (int)date("Y");
    $paxDailyLabels = [];
    $paxDailyTotals = [];
    for ($d = 29; $d >= 0; $d--) {
        $date = new DateTime();
        $date->modify("-{$d} days");
        $dayKey = $date->format('Y-m-d');
        $paxDailyLabels[] = $date->format('d M');

        $count = (int)$pdo->query("
            SELECT COALESCE(SUM(pax_count),0) FROM booking_orders
            WHERE status <> 'cancelled' AND start_date = '{$dayKey}'
        ")->fetchColumn();
        $paxDailyTotals[] = $count;
    }

    $paxMonthLabels = [];
    $paxMonthlyTotals = [];
    for ($m = 11; $m >= 0; $m--) {
        // Mulai dari tanggal 1: "-1 months" pada tanggal 31 melompati bulan pendek (Okt 31 → Sep 31 = 1 Okt), label bulan jadi dobel
        $date = new DateTime("first day of this month");
        $date->modify("-{$m} months");
        $monthKey = $date->format('Y-m');
        $paxMonthLabels[] = $date->format('M Y');

        $count = (int)$pdo->query("
            SELECT COALESCE(SUM(pax_count),0) FROM booking_orders
            WHERE status <> 'cancelled'
              AND DATE_FORMAT(start_date,'%Y-%m')='{$monthKey}'
        ")->fetchColumn();
        $paxMonthlyTotals[] = $count;
    }

    $paxYearLabels = [];
    $paxYearlyTotals = [];
    for ($y = 4; $y >= 0; $y--) {
        $year = $currentYear - $y;
        $paxYearLabels[] = (string)$year;

        $count = (int)$pdo->query("
            SELECT COALESCE(SUM(pax_count),0) FROM booking_orders
            WHERE status <> 'cancelled' AND YEAR(start_date) = {$year}
        ")->fetchColumn();
        $paxYearlyTotals[] = $count;
    }

    $paxDailyLabels = json_encode($paxDailyLabels);
    $paxDailyTotals = json_encode($paxDailyTotals);
    $paxMonthLabels = json_encode($paxMonthLabels);
    $paxMonthlyTotals = json_encode($paxMonthlyTotals);
    $paxYearLabels = json_encode($paxYearLabels);
    $paxYearlyTotals = json_encode($paxYearlyTotals);

    // Tamu yang datang hari ini dan yang akan datang sesudahnya
    $upcomingArrivals = $pdo->query("
        SELECT b.start_date, b.pax_count, c.name AS customer_name, tp.name AS package_name
        FROM booking_orders b
        JOIN customers c ON c.id = b.customer_id
        LEFT JOIN trip_packages tp ON tp.id = b.package_id
        WHERE b.status <> 'cancelled' AND b.start_date >= CURDATE()
        ORDER BY b.start_date ASC
        LIMIT 10
    ")->fetchAll();
    // ── Perlu ditindaklanjuti ──
    // Permintaan penawaran dari form website yang belum diproses (sama dengan titik merah di menu Penawaran)
    $todoNewQuotes = (int)$pdo->query("SELECT COUNT(*) FROM quotations WHERE created_by = 'website' AND status = 'draft'")->fetchColumn();
    // Invoice yang belum lunas dan jatuh tempo dalam 3 hari atau sudah lewat
    $due = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)), 0) amt FROM invoices
        WHERE status NOT IN ('cancelled', 'paid') AND total_amount > paid_amount AND due_date IS NOT NULL AND due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetch();
    $todoDueInvoices = (int)($due['n'] ?? 0);
    $todoDueAmount = (float)($due['amt'] ?? 0);
    // Tamu yang datang hari ini dan besok
    $todoArrivals = array_values(array_filter($upcomingArrivals, fn($a) => $a['start_date'] <= date('Y-m-d', strtotime('+1 day'))));
} catch (Exception $e) {
    $dbError = $e->getMessage();
    $qStats = ['total' => 0, 'draft' => 0, 'sent' => 0, 'approved' => 0];
    $iStats = ['total' => 0, 'issued' => 0, 'partial' => 0, 'paid' => 0, 'overdue' => 0, 'outstanding' => 0];
    $custCount = $pkgCount = 0;
    $monthRevenue = 0;
    $monthExpense = 0;
    $monthProfit = 0;
    $tripProfit = ['trip_count' => 0, 'revenue' => 0, 'trip_cost' => 0, 'ops_cost' => 0, 'profit' => 0];
    $bookingStats = ['total' => 0, 'active' => 0];
    $recentQuotations = $recentInvoices = [];
    $monthLabels = json_encode([]);
    $monthlyGuests = json_encode([]);
    $yearLabels = json_encode([]);
    $yearlyGuests = json_encode([]);
    $paxDailyLabels = json_encode([]);
    $paxDailyTotals = json_encode([]);
    $paxMonthLabels = json_encode([]);
    $paxMonthlyTotals = json_encode([]);
    $paxYearLabels = json_encode([]);
    $paxYearlyTotals = json_encode([]);
    $packageLabels = json_encode([]);
    $packagePaxDaily = json_encode([]);
    $packagePaxMonthly = json_encode([]);
    $packagePaxYearly = json_encode([]);
    $upcomingArrivals = [];
    $todoNewQuotes = $todoDueInvoices = 0;
    $todoDueAmount = 0;
    $todoArrivals = [];
    $todayArrivals = [];
    $nextArrivals = [];
}
include 'layout-header.php';

// Label status dalam bahasa Indonesia (penawaran & invoice)
$statusLabel = [
    'draft' => 'Draft', 'sent' => 'Terkirim', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'expired' => 'Kedaluwarsa',
    'converted' => 'Jadi Pemesanan', 'issued' => 'Belum Bayar', 'partial' => 'DP', 'paid' => 'Lunas', 'overdue' => 'Terlambat', 'cancelled' => 'Batal',
];
$todoCount = $todoNewQuotes + $todoDueInvoices + count($todoArrivals);
?>

<?php if (isset($dbError)): ?>
    <div class="ss-alert ss-alert-error">
        <i data-feather="alert-circle"></i>
        Database belum siap. Jalankan <code>database/sunsea-setup.sql</code> terlebih dahulu.
        <br><small style="opacity:.7"><?php echo htmlspecialchars($dbError); ?></small>
    </div>
<?php endif; ?>

<style>
    .bd-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .bd-todo { margin-bottom: 20px; }
    .bd-todo-list { display: flex; flex-direction: column; gap: 8px; }
    .bd-todo-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--ss-gray-2); text-decoration: none; color: var(--ss-text); background: var(--ss-white); transition: .15s; }
    .bd-todo-item:hover { border-color: var(--ss-ocean); background: var(--ss-sky); }
    .bd-todo-item .n { min-width: 34px; height: 34px; padding: 0 8px; border-radius: 9px; display: grid; place-items: center; font-weight: 800; font-size: 15px; color: #fff; }
    .bd-todo-item .t { flex: 1; font-weight: 600; font-size: 13.5px; }
    .bd-todo-item .t small { display: block; font-weight: 500; color: var(--ss-muted); font-size: 12px; margin-top: 1px; }
    .bd-todo-item .go { color: var(--ss-muted); font-size: 12px; font-weight: 600; white-space: nowrap; }
    .bd-ok { padding: 14px; border-radius: 10px; background: #ECFDF5; color: #065F46; font-weight: 600; font-size: 13.5px; }
    .bd-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
    .bd-stat { display: block; padding: 14px 16px; border-radius: var(--ss-radius); background: var(--ss-white); box-shadow: var(--ss-shadow); text-decoration: none; color: var(--ss-text); border-left: 4px solid var(--ss-gray-3); transition: .15s; }
    .bd-stat:hover { box-shadow: var(--ss-shadow-md); }
    .bd-stat .l { font-size: 12px; font-weight: 600; color: var(--ss-muted); }
    .bd-stat .v { font-size: 20px; font-weight: 800; margin-top: 4px; }
    .bd-stat .s { font-size: 11.5px; color: var(--ss-muted); margin-top: 3px; }
    .bd-stat.in { border-left-color: var(--ss-success); } .bd-stat.out { border-left-color: var(--ss-danger); }
    .bd-stat.profit { border-left-color: var(--ss-ocean); } .bd-stat.debt { border-left-color: var(--ss-warning); }
    .bd-tabs { display: flex; gap: 6px; }
    .bd-tabs button.on { background: var(--ss-ocean); color: #fff; border-color: var(--ss-ocean); }
    @media (max-width: 1100px) { .bd-stats { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 900px) { .bd-grid2 { grid-template-columns: 1fr; } }
</style>

<!-- 1. PERLU DITINDAKLANJUTI -->
<div class="ss-card bd-todo">
    <div class="ss-card-header">
        <div>
            <div class="ss-card-title">Perlu ditindaklanjuti</div>
            <div class="ss-card-sub">Yang perlu dikerjakan hari ini</div>
        </div>
    </div>
    <?php if ($todoCount === 0): ?>
        <div class="bd-ok">👍 Semua beres. Tidak ada penawaran baru, tagihan jatuh tempo, atau tamu yang datang hari ini / besok.</div>
    <?php else: ?>
        <div class="bd-todo-list">
            <?php if ($todoNewQuotes > 0): ?>
                <a class="bd-todo-item" href="quotations.php?web_history=1">
                    <span class="n" style="background:var(--ss-ocean)"><?php echo $todoNewQuotes; ?></span>
                    <span class="t">Permintaan penawaran baru dari website<small>Segera hubungi tamunya dan kirim penawaran</small></span>
                    <span class="go">Buka →</span>
                </a>
            <?php endif; ?>
            <?php if ($todoDueInvoices > 0): ?>
                <a class="bd-todo-item" href="invoices.php?filter=outstanding">
                    <span class="n" style="background:var(--ss-danger)"><?php echo $todoDueInvoices; ?></span>
                    <span class="t">Invoice jatuh tempo (3 hari lagi atau sudah lewat)<small>Sisa tagihan <?php echo sunseaRupiah($todoDueAmount); ?>, ingatkan tamunya untuk membayar</small></span>
                    <span class="go">Buka →</span>
                </a>
            <?php endif; ?>
            <?php foreach ($todoArrivals as $a): $isToday = $a['start_date'] === date('Y-m-d'); ?>
                <a class="bd-todo-item" href="calendar.php">
                    <span class="n" style="background:<?php echo $isToday ? 'var(--ss-success)' : 'var(--ss-cyan)'; ?>"><?php echo (int)$a['pax_count']; ?></span>
                    <span class="t"><?php echo htmlspecialchars($a['customer_name']); ?> datang <?php echo $isToday ? 'hari ini' : 'besok'; ?><small><?php echo htmlspecialchars($a['package_name'] ?? 'Tanpa paket'); ?> · <?php echo (int)$a['pax_count']; ?> pax</small></span>
                    <span class="go">Kalender →</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- 2. ANGKA UTAMA BULAN INI -->
<div class="bd-stats">
    <a class="bd-stat in" href="finance.php">
        <div class="l">Uang Masuk · <?php echo date('M Y'); ?></div>
        <div class="v"><?php echo sunseaRupiah($monthRevenue, true); ?></div>
        <div class="s">Pembayaran dari tamu bulan ini</div>
    </a>
    <a class="bd-stat out" href="finance.php">
        <div class="l">Uang Keluar · <?php echo date('M Y'); ?></div>
        <div class="v"><?php echo sunseaRupiah($monthExpense, true); ?></div>
        <div class="s">Biaya di Kas & Biaya bulan ini</div>
    </a>
    <a class="bd-stat profit" href="laporan.php">
        <div class="l">Profit Trip · <?php echo date('M Y'); ?></div>
        <div class="v" style="color:<?php echo $monthProfit >= 0 ? 'var(--ss-success)' : 'var(--ss-danger)'; ?>"><?php echo sunseaRupiah($monthProfit, true); ?></div>
        <div class="s"><?php echo (int)$tripProfit['trip_count']; ?> trip yang mulai bulan ini</div>
    </a>
    <a class="bd-stat debt" href="invoices.php?filter=outstanding">
        <div class="l">Piutang Belum Lunas</div>
        <div class="v"><?php echo sunseaRupiah((float)($iStats['outstanding'] ?? 0), true); ?></div>
        <div class="s">Sisa tagihan semua invoice</div>
    </a>
</div>

<!-- 3. GRAFIK TAMU + TAMU YANG AKAN DATANG -->
<div class="bd-grid2">
    <div class="ss-card">
        <div class="ss-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
            <div>
                <div class="ss-card-title">Jumlah Tamu</div>
                <div class="ss-card-sub" id="paxChartSub">Jumlah tamu (pax) per bulan, 12 bulan terakhir</div>
            </div>
            <div class="bd-tabs">
                <button type="button" id="paxChartBtnDaily" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchPaxChart('daily')">Harian</button>
                <button type="button" id="paxChartBtnMonthly" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchPaxChart('monthly')">Bulanan</button>
                <button type="button" id="paxChartBtnYearly" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchPaxChart('yearly')">Tahunan</button>
            </div>
        </div>
        <div style="position:relative;height:280px;padding:10px;">
            <canvas id="paxMonthlyChart"></canvas>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Tamu yang Akan Datang</div>
                <div class="ss-card-sub">Kedatangan hari ini dan sesudahnya</div>
            </div>
            <a href="calendar.php" class="ss-btn ss-btn-outline ss-btn-sm">Kalender</a>
        </div>
        <?php if (empty($upcomingArrivals)): ?>
            <div class="ss-empty">
                <div class="ss-empty-icon">🧳</div>
                <h3>Belum ada tamu terjadwal</h3>
                <p>Belum ada pemesanan dengan tanggal kedatangan hari ini atau sesudahnya</p>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Tamu</th>
                            <th>Paket</th>
                            <th>Pax</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcomingArrivals as $arrival): $isToday = $arrival['start_date'] === date('Y-m-d'); ?>
                            <tr>
                                <td style="white-space:nowrap;<?php echo $isToday ? 'font-weight:700;color:var(--ss-success);' : ''; ?>"><?php echo $isToday ? 'Hari ini' : htmlspecialchars(date('d M Y', strtotime($arrival['start_date']))); ?></td>
                                <td><?php echo htmlspecialchars($arrival['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($arrival['package_name'] ?? '-'); ?></td>
                                <td><?php echo (int)$arrival['pax_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 4. PENAWARAN & INVOICE TERBARU -->
<div class="bd-grid2">
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Penawaran Terbaru</div>
                <div class="ss-card-sub">5 penawaran terakhir</div>
            </div>
            <a href="quotations.php" class="ss-btn ss-btn-outline ss-btn-sm">Lihat Semua</a>
        </div>
        <?php if (empty($recentQuotations)): ?>
            <div class="ss-empty">
                <div class="ss-empty-icon">📋</div>
                <h3>Belum ada penawaran</h3>
                <p>Buat penawaran pertama untuk tamu Anda</p>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>No. Penawaran</th>
                            <th>Tamu</th>
                            <th>Status</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentQuotations as $q): ?>
                            <tr>
                                <td><a href="quotations.php?action=view&id=<?php echo (int)$q['id']; ?>" style="color:var(--ss-ocean);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($q['quotation_no']); ?></a></td>
                                <td><?php echo htmlspecialchars($q['customer_name']); ?></td>
                                <td><span class="ss-status ss-status-<?php echo htmlspecialchars($q['status']); ?>"><?php echo htmlspecialchars($statusLabel[$q['status']] ?? ucfirst($q['status'])); ?></span></td>
                                <td style="font-weight:600;"><?php echo sunseaRupiah((float)$q['total_amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Invoice Terbaru</div>
                <div class="ss-card-sub">5 invoice terakhir</div>
            </div>
            <a href="invoices.php" class="ss-btn ss-btn-outline ss-btn-sm">Lihat Semua</a>
        </div>
        <?php if (empty($recentInvoices)): ?>
            <div class="ss-empty">
                <div class="ss-empty-icon">🧾</div>
                <h3>Belum ada invoice</h3>
                <p>Invoice muncul setelah ada pemesanan</p>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>No. Invoice</th>
                            <th>Tamu</th>
                            <th>Status</th>
                            <th>Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentInvoices as $inv): ?>
                            <tr>
                                <td><a href="invoices.php?action=view&id=<?php echo (int)$inv['id']; ?>" style="color:var(--ss-ocean);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($inv['invoice_no']); ?></a></td>
                                <td><?php echo htmlspecialchars($inv['customer_name']); ?></td>
                                <td><span class="ss-status ss-status-<?php echo htmlspecialchars($inv['status']); ?>"><?php echo htmlspecialchars($statusLabel[$inv['status']] ?? ucfirst($inv['status'])); ?></span></td>
                                <td style="font-weight:600;color:<?php echo ($inv['remaining_amount'] > 0) ? 'var(--ss-danger)' : 'var(--ss-success)'; ?>"><?php echo sunseaRupiah((float)$inv['remaining_amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // Grafik Jumlah Tamu: Harian (30 hari) / Bulanan (12 bulan) / Tahunan (5 tahun), semua pemesanan yang tidak dibatalkan
    const paxAccent = getComputedStyle(document.documentElement).getPropertyValue('--ss-ocean').trim() || '#0891b2';
    const paxData = {
        daily: { labels: <?php echo $paxDailyLabels; ?>, values: <?php echo $paxDailyTotals; ?>, sub: 'Jumlah tamu (pax) per hari, 30 hari terakhir' },
        monthly: { labels: <?php echo $paxMonthLabels; ?>, values: <?php echo $paxMonthlyTotals; ?>, sub: 'Jumlah tamu (pax) per bulan, 12 bulan terakhir' },
        yearly: { labels: <?php echo $paxYearLabels; ?>, values: <?php echo $paxYearlyTotals; ?>, sub: 'Jumlah tamu (pax) per tahun, 5 tahun terakhir' }
    };
    let paxChartInstance = null;

    function switchPaxChart(mode) {
        const ctx = document.getElementById('paxMonthlyChart');
        if (!ctx || typeof Chart === 'undefined') return;
        const src = paxData[mode];
        if (paxChartInstance) paxChartInstance.destroy();
        paxChartInstance = new Chart(ctx, {
            type: 'bar',
            data: { labels: src.labels, datasets: [{ label: 'Tamu (pax)', data: src.values, backgroundColor: paxAccent + 'cc', borderRadius: 8, maxBarThickness: 36 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0, font: { size: 12 } }, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
        document.getElementById('paxChartSub').textContent = src.sub;
        ['daily', 'monthly', 'yearly'].forEach(m => {
            const btn = document.getElementById('paxChartBtn' + m.charAt(0).toUpperCase() + m.slice(1));
            if (btn) btn.classList.toggle('on', m === mode);
        });
    }
    switchPaxChart('monthly');
</script>

<?php include 'layout-footer.php';
