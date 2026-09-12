<?php

/**
 * Sunsea - Dashboard
 * Ocean-themed travel bureau dashboard
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$pageTitle  = 'Dashboard';
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
    $monthProfit = $monthRevenue - $monthExpense;

    // Recent quotations (5)
    $recentQuotations = $pdo->query("
        SELECT q.quotation_no, q.status, q.total_amount, q.trip_date, q.created_at,
               c.name as customer_name
        FROM quotations q
        JOIN customers c ON c.id = q.customer_id
        ORDER BY q.created_at DESC
        LIMIT 5
    ")->fetchAll();

    // Recent invoices (5)
    $recentInvoices = $pdo->query("
        SELECT i.invoice_no, i.status, i.total_amount, i.remaining_amount, i.due_date,
               c.name as customer_name
        FROM invoices i
        JOIN customers c ON c.id = i.customer_id
        ORDER BY i.created_at DESC
        LIMIT 5
    ")->fetchAll();

    // Monthly guest reservations (last 12 months)
    $monthlyGuests = [];
    $monthLabels = [];
    $currentYear = (int)date('Y');

    for ($m = 11; $m >= 0; $m--) {
        $date = new DateTime();
        $date->modify("-{$m} months");
        $monthKey = $date->format('Y-m');
        $monthLabels[] = $date->format('M');

        $count = (int)$pdo->query("
            SELECT COALESCE(SUM(pax_count),0) FROM booking_orders
            WHERE status='confirmed' 
              AND YEAR(start_date)={$currentYear}
              AND DATE_FORMAT(start_date,'%Y-%m')='{$monthKey}'
        ")->fetchColumn();
        $monthlyGuests[] = $count;
    }
    $monthLabels = json_encode($monthLabels);
    $monthlyGuests = json_encode($monthlyGuests);

    // Yearly guest reservations (last 5 years)
    $yearlyGuests = [];
    $yearLabels = [];

    for ($y = 4; $y >= 0; $y--) {
        $year = $currentYear - $y;
        $yearLabels[] = $year;

        $count = (int)$pdo->query("
            SELECT COALESCE(SUM(pax_count),0) FROM booking_orders
            WHERE status='confirmed' AND YEAR(start_date)={$year}
        ")->fetchColumn();
        $yearlyGuests[] = $count;
    }
    $yearLabels = json_encode($yearLabels);
    $yearlyGuests = json_encode($yearlyGuests);

    // Total Pax: harian (30 hari terakhir), bulanan (12 bulan terakhir), tahunan (5 tahun terakhir).
    // Semua booking yang tidak dibatalkan, beda dengan chart "Tamu Reservasi" di atas yang hanya status confirmed.
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
        $date = new DateTime();
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

    // Pax per paket wisata aktif, dengan periode harian (hari ini) / bulanan (bulan ini) / tahunan (tahun ini)
    $activePackages = $pdo->query("SELECT id, name FROM trip_packages WHERE is_active = 1 ORDER BY display_order, name")->fetchAll();
    $packageLabels = json_encode(array_column($activePackages, 'name'));

    $paxPkgPeriodSql = function (string $dateCondition) use ($pdo, $activePackages) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(b.pax_count),0) AS total_pax
            FROM booking_orders b
            WHERE b.package_id = ? AND b.status <> 'cancelled' AND $dateCondition
        ");
        $values = [];
        foreach ($activePackages as $pkg) {
            $stmt->execute([$pkg['id']]);
            $values[] = (int)$stmt->fetchColumn();
        }
        return $values;
    };
    $packagePaxDaily = json_encode($paxPkgPeriodSql('b.start_date = CURDATE()'));
    $packagePaxMonthly = json_encode($paxPkgPeriodSql("DATE_FORMAT(b.start_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')"));
    $packagePaxYearly = json_encode($paxPkgPeriodSql('YEAR(b.start_date) = YEAR(CURDATE())'));
} catch (Exception $e) {
    $dbError = $e->getMessage();
    $qStats = ['total' => 0, 'draft' => 0, 'sent' => 0, 'approved' => 0];
    $iStats = ['total' => 0, 'issued' => 0, 'partial' => 0, 'paid' => 0, 'overdue' => 0, 'outstanding' => 0];
    $custCount = $pkgCount = 0;
    $monthRevenue = 0;
    $monthExpense = 0;
    $monthProfit = 0;
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
}

include 'layout-header.php';
?>

<?php
// Load quick action settings
$quickActionSettings = [];
try {
    $qaSetting = sunseaSetting($pdo, 'quick_actions_visible', json_encode([
        'tamu' => true,
        'booking' => true,
        'calendar' => true,
        'partner' => true,
        'guide' => true,
        'coordinator' => true,
        'facility' => true,
        'customer' => true,
        'quotation' => true,
        'invoice' => true,
        'calculator' => true,
        'package' => true
    ]));
    $quickActionSettings = json_decode($qaSetting, true) ?? [];
} catch (Exception $e) {
    $quickActionSettings = [];
}


if (isset($dbError)): ?>
    <div class="ss-alert ss-alert-error">
        <i data-feather="alert-circle"></i>
        Database belum siap. Jalankan <code>database/sunsea-setup.sql</code> terlebih dahulu.
        <br><small style="opacity:.7"><?php echo htmlspecialchars($dbError); ?></small>
    </div>
<?php endif; ?>

<!-- ============================
     STAT CARDS
============================= -->
<style>
    .ss-stats-grid.compact {
        grid-template-columns: repeat(6, 1fr);
        gap: 10px;
        margin-bottom: 20px;
    }

    .ss-stats-grid.compact .ss-stat-card {
        padding: 12px 10px;
        gap: 8px;
        align-items: center;
    }

    .ss-stats-grid.compact .ss-stat-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
    }

    .ss-stats-grid.compact .ss-stat-icon svg {
        width: 15px;
        height: 15px;
    }

    .ss-stats-grid.compact .ss-stat-value {
        font-size: 15px !important;
    }

    .ss-stats-grid.compact .ss-stat-label {
        font-size: 10.5px;
        margin-top: 2px;
        white-space: nowrap;
    }

    @media (max-width: 1200px) {
        .ss-stats-grid.compact {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 700px) {
        .ss-stats-grid.compact {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>
<div class="ss-stats-grid compact">
    <div class="ss-stat-card">
        <div class="ss-stat-icon success"><i data-feather="credit-card"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo $iStats['total'] ?? 0; ?></div>
            <div class="ss-stat-label">Invoice <span style="color:var(--ss-danger);"><?php echo (int)($iStats['overdue'] ?? 0); ?> overdue</span></div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon warning"><i data-feather="trending-up"></i></div>
        <div>
            <div class="ss-stat-value" style="font-size:16px;"><?php echo sunseaRupiah($monthRevenue, true); ?></div>
            <div class="ss-stat-label">Pendapatan Bulan Ini</div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon danger"><i data-feather="trending-down"></i></div>
        <div>
            <div class="ss-stat-value" style="font-size:16px;"><?php echo sunseaRupiah($monthExpense, true); ?></div>
            <div class="ss-stat-label">Pengeluaran Bulan Ini</div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon <?php echo $monthProfit >= 0 ? 'success' : 'danger'; ?>"><i data-feather="pie-chart"></i></div>
        <div>
            <div class="ss-stat-value" style="font-size:16px;color:<?php echo $monthProfit >= 0 ? 'var(--ss-success)' : 'var(--ss-danger)'; ?>;"><?php echo sunseaRupiah($monthProfit, true); ?></div>
            <div class="ss-stat-label">Profit Margin Bulan Ini</div>
        </div>
    </div>
    <a href="invoices.php?filter=outstanding" class="ss-stat-card" style="text-decoration:none;color:inherit;">
        <div class="ss-stat-icon danger"><i data-feather="alert-triangle"></i></div>
        <div>
            <div class="ss-stat-value" style="font-size:16px;"><?php echo sunseaRupiah((float)($iStats['outstanding'] ?? 0), true); ?></div>
            <div class="ss-stat-label">Piutang Belum Lunas</div>
        </div>
    </a>
    <div class="ss-stat-card">
        <div class="ss-stat-icon cyan"><i data-feather="briefcase"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo (int)($bookingStats['total'] ?? 0); ?></div>
            <div class="ss-stat-label">Pemesanan <span style="color:var(--ss-ocean);"><?php echo (int)($bookingStats['active'] ?? 0); ?> aktif</span></div>
        </div>
    </div>
</div>

<!-- ============================
     RESERVATION CHARTS
============================= -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

    <!-- Guest Chart (toggle Bulanan/Tahunan) -->
    <div class="ss-card">
        <div class="ss-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
            <div>
                <div class="ss-card-title">Tamu Reservasi</div>
                <div class="ss-card-sub" id="guestChartSub">Total tamu per bulan (12 bulan terakhir)</div>
            </div>
            <div style="display:flex;gap:6px;">
                <button type="button" id="guestChartBtnMonthly" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchGuestChart('monthly')">Bulanan</button>
                <button type="button" id="guestChartBtnYearly" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchGuestChart('yearly')">Tahunan</button>
            </div>
        </div>
        <div style="position:relative;height:300px;padding:10px;">
            <canvas id="guestChart"></canvas>
        </div>
    </div>

    <!-- Finance Pie Chart: Pemasukan vs Pengeluaran vs Profit Margin bulan ini -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Pemasukan, Pengeluaran &amp; Profit Margin</div>
                <div class="ss-card-sub">Ringkasan Finance bulan ini (<?php echo date('F Y'); ?>)</div>
            </div>
        </div>
        <div style="position:relative;height:300px;padding:10px;">
            <canvas id="financePieChart"></canvas>
        </div>
    </div>

</div>

<!-- ============================
     PAX CHARTS: Total Pax Bulanan & Pax per Paket
============================= -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

    <!-- Total Pax Bulanan -->
    <div class="ss-card">
        <div class="ss-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
            <div>
                <div class="ss-card-title">Total Pax</div>
                <div class="ss-card-sub" id="paxChartSub">Jumlah tamu (pax) per bulan, 12 bulan terakhir</div>
            </div>
            <div style="display:flex;gap:6px;">
                <button type="button" id="paxChartBtnDaily" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchPaxChart('daily')">Harian</button>
                <button type="button" id="paxChartBtnMonthly" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchPaxChart('monthly')">Bulanan</button>
                <button type="button" id="paxChartBtnYearly" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchPaxChart('yearly')">Tahunan</button>
            </div>
        </div>
        <div style="position:relative;height:300px;padding:10px;">
            <canvas id="paxMonthlyChart"></canvas>
        </div>
    </div>

    <!-- Pax per Paket -->
    <div class="ss-card">
        <div class="ss-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
            <div>
                <div class="ss-card-title">Pax per Paket Wisata</div>
                <div class="ss-card-sub" id="paxPkgChartSub">Jumlah tamu yang booking di tiap paket, bulan ini</div>
            </div>
            <div style="display:flex;gap:6px;">
                <button type="button" id="paxPkgChartBtnDaily" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchPaxPkgChart('daily')">Harian</button>
                <button type="button" id="paxPkgChartBtnMonthly" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchPaxPkgChart('monthly')">Bulanan</button>
                <button type="button" id="paxPkgChartBtnYearly" class="ss-btn ss-btn-sm ss-btn-outline" onclick="switchPaxPkgChart('yearly')">Tahunan</button>
            </div>
        </div>
        <div style="position:relative;height:300px;padding:10px;">
            <canvas id="paxByPackageChart"></canvas>
        </div>
    </div>

</div>

<!-- ============================
     TWO COLUMN: Recent Quotations & Invoices
============================= -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

    <!-- Recent Quotations -->
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
                <p>Buat penawaran pertama untuk customer Anda</p>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>No. Penawaran</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentQuotations as $q): ?>
                            <tr>
                                <td><a href="quotations.php?view=<?php echo urlencode($q['quotation_no']); ?>"
                                        style="color:var(--ss-ocean);font-weight:600;text-decoration:none;">
                                        <?php echo htmlspecialchars($q['quotation_no']); ?>
                                    </a></td>
                                <td><?php echo htmlspecialchars($q['customer_name']); ?></td>
                                <td><span class="ss-status ss-status-<?php echo $q['status']; ?>"><?php echo ucfirst($q['status']); ?></span></td>
                                <td style="font-weight:600;"><?php echo sunseaRupiah((float)$q['total_amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Invoices -->
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
                <p>Invoice akan muncul setelah Anda membuat penawaran</p>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>No. Invoice</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentInvoices as $inv): ?>
                            <tr>
                                <td><a href="invoices.php?view=<?php echo urlencode($inv['invoice_no']); ?>"
                                        style="color:var(--ss-ocean);font-weight:600;text-decoration:none;">
                                        <?php echo htmlspecialchars($inv['invoice_no']); ?>
                                    </a></td>
                                <td><?php echo htmlspecialchars($inv['customer_name']); ?></td>
                                <td><span class="ss-status ss-status-<?php echo $inv['status']; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                                <td style="font-weight:600;color:<?php echo ($inv['remaining_amount'] > 0) ? 'var(--ss-danger)' : 'var(--ss-success)'; ?>">
                                    <?php echo sunseaRupiah((float)$inv['remaining_amount']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
    // Ocean theme colors
    const oceanColors = {
        primary: '#0369a1', // Ocean blue
        secondary: '#F59E0B', // Cyan
        success: '#10b981', // Green
        warning: '#f59e0b', // Amber
        danger: '#ef4444' // Red
    };

    // Guest Chart: toggle between monthly (line) and yearly (bar) datasets
    const guestData = {
        monthly: {
            labels: <?php echo $monthLabels; ?>,
            values: <?php echo $monthlyGuests; ?>,
            sub: 'Total tamu per bulan (12 bulan terakhir)'
        },
        yearly: {
            labels: <?php echo $yearLabels; ?>,
            values: <?php echo $yearlyGuests; ?>,
            sub: 'Total tamu per tahun (5 tahun terakhir)'
        }
    };
    let guestChartInstance = null;

    function switchGuestChart(mode) {
        const ctx = document.getElementById('guestChart');
        if (!ctx) return;
        const isMonthly = mode === 'monthly';
        const src = guestData[mode];

        if (guestChartInstance) guestChartInstance.destroy();
        guestChartInstance = new Chart(ctx, {
            type: isMonthly ? 'line' : 'bar',
            data: {
                labels: src.labels,
                datasets: [{
                    label: isMonthly ? 'Tamu Reservasi' : 'Total Tamu',
                    data: src.values,
                    borderColor: oceanColors.primary,
                    backgroundColor: isMonthly ? (oceanColors.primary + '15') : [oceanColors.primary, oceanColors.secondary, oceanColors.success, oceanColors.warning, oceanColors.danger],
                    borderWidth: isMonthly ? 3 : 0,
                    fill: isMonthly,
                    tension: 0.4,
                    pointRadius: isMonthly ? 5 : 0,
                    pointBackgroundColor: oceanColors.primary,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: isMonthly ? 7 : 0,
                    borderRadius: isMonthly ? 0 : 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: {
                                size: 13
                            },
                            padding: 15,
                            usePointStyle: true
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: isMonthly ? 5 : 20,
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        document.getElementById('guestChartSub').textContent = src.sub;
        const mBtn = document.getElementById('guestChartBtnMonthly');
        const yBtn = document.getElementById('guestChartBtnYearly');
        mBtn.style.background = isMonthly ? oceanColors.primary : '';
        mBtn.style.color = isMonthly ? '#fff' : '';
        yBtn.style.background = !isMonthly ? oceanColors.primary : '';
        yBtn.style.color = !isMonthly ? '#fff' : '';
    }
    switchGuestChart('monthly');

    // Finance Pie Chart: Pemasukan vs Pengeluaran vs Profit Margin bulan ini
    const financePieCtx = document.getElementById('financePieChart');
    if (financePieCtx) {
        new Chart(financePieCtx, {
            type: 'pie',
            data: {
                labels: ['Pemasukan', 'Pengeluaran', 'Profit Margin'],
                datasets: [{
                    data: [<?php echo (float)$monthRevenue; ?>, <?php echo (float)$monthExpense; ?>, <?php echo max(0, (float)$monthProfit); ?>],
                    backgroundColor: [oceanColors.success, oceanColors.danger, oceanColors.primary],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: {
                                size: 13
                            },
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.parsed || 0;
                                return ctx.label + ': Rp ' + val.toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        });
    }

    // Total Pax Chart: toggle Harian / Bulanan / Tahunan (same elegant design as Tamu Reservasi, beda warna)
    const paxAccent = '#0891b2'; // teal/cyan accent, membedakan dari chart Tamu Reservasi (ocean blue)
    const paxData = {
        daily: {
            labels: <?php echo $paxDailyLabels; ?>,
            values: <?php echo $paxDailyTotals; ?>,
            sub: 'Jumlah tamu (pax) per hari, 30 hari terakhir'
        },
        monthly: {
            labels: <?php echo $paxMonthLabels; ?>,
            values: <?php echo $paxMonthlyTotals; ?>,
            sub: 'Jumlah tamu (pax) per bulan, 12 bulan terakhir'
        },
        yearly: {
            labels: <?php echo $paxYearLabels; ?>,
            values: <?php echo $paxYearlyTotals; ?>,
            sub: 'Jumlah tamu (pax) per tahun, 5 tahun terakhir'
        }
    };
    let paxChartInstance = null;

    function switchPaxChart(mode) {
        const ctx = document.getElementById('paxMonthlyChart');
        if (!ctx) return;
        const src = paxData[mode];

        if (paxChartInstance) paxChartInstance.destroy();
        paxChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: src.labels,
                datasets: [{
                    label: 'Total Pax',
                    data: src.values,
                    backgroundColor: paxAccent + 'cc',
                    borderRadius: 8,
                    maxBarThickness: 36
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        document.getElementById('paxChartSub').textContent = src.sub;
        ['daily', 'monthly', 'yearly'].forEach(m => {
            const btn = document.getElementById('paxChartBtn' + m.charAt(0).toUpperCase() + m.slice(1));
            const active = m === mode;
            btn.style.background = active ? paxAccent : '';
            btn.style.color = active ? '#fff' : '';
            btn.style.borderColor = active ? paxAccent : '';
        });
    }
    switchPaxChart('monthly');

    // Pax per Paket Wisata: toggle Harian / Bulanan / Tahunan
    const paxPkgPalette = ['#0891b2', '#F59E0B', '#10b981', '#8b5cf6', '#ef4444', '#0369a1', '#db2777', '#65a30d'];
    const paxPkgLabels = <?php echo $packageLabels; ?>;
    const paxPkgData = {
        daily: {
            values: <?php echo $packagePaxDaily; ?>,
            sub: 'Jumlah tamu yang booking di tiap paket, hari ini'
        },
        monthly: {
            values: <?php echo $packagePaxMonthly; ?>,
            sub: 'Jumlah tamu yang booking di tiap paket, bulan ini'
        },
        yearly: {
            values: <?php echo $packagePaxYearly; ?>,
            sub: 'Jumlah tamu yang booking di tiap paket, tahun ini'
        }
    };
    let paxPkgChartInstance = null;

    function switchPaxPkgChart(mode) {
        const ctx = document.getElementById('paxByPackageChart');
        if (!ctx) return;
        const src = paxPkgData[mode];

        if (paxPkgLabels.length === 0) {
            ctx.parentElement.insertAdjacentHTML('beforeend', '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px;">Belum ada paket wisata aktif.</div>');
            return;
        }

        if (paxPkgChartInstance) paxPkgChartInstance.destroy();
        paxPkgChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: paxPkgLabels,
                datasets: [{
                    label: 'Total Pax',
                    data: src.values,
                    backgroundColor: paxPkgLabels.map((_, i) => paxPkgPalette[i % paxPkgPalette.length]),
                    borderRadius: 8,
                    maxBarThickness: 28
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });

        document.getElementById('paxPkgChartSub').textContent = src.sub;
        ['daily', 'monthly', 'yearly'].forEach(m => {
            const btn = document.getElementById('paxPkgChartBtn' + m.charAt(0).toUpperCase() + m.slice(1));
            const active = m === mode;
            btn.style.background = active ? paxAccent : '';
            btn.style.color = active ? '#fff' : '';
            btn.style.borderColor = active ? paxAccent : '';
        });
    }
    switchPaxPkgChart('monthly');

    // Quick Action Settings
    function openQuickActionSettings() {
        const modal = document.getElementById('qaSettingsModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeQuickActionSettings() {
        const modal = document.getElementById('qaSettingsModal');
        if (modal) modal.style.display = 'none';
    }

    function saveQuickActionSettings() {
        const settings = {};
        const keys = ['tamu', 'booking', 'calendar', 'partner', 'guide', 'coordinator', 'facility', 'customer', 'quotation', 'invoice', 'calculator', 'package'];

        keys.forEach(key => {
            const checkbox = document.getElementById('qa_' + key);
            if (checkbox) settings[key] = checkbox.checked;
        });

        fetch('api/save-quick-actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(settings)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Gagal menyimpan pengaturan');
                }
            })
            .catch(err => console.error(err));
    }

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.getElementById('qaSettingsModal')?.style.display === 'flex') {
            closeQuickActionSettings();
        }
    });
</script>

<!-- Quick Actions Settings Modal -->
<div id="qaSettingsModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:12px;width:90%;max-width:400px;padding:24px;box-shadow:0 20px 40px rgba(0,0,0,.2);animation:slideUp .3s ease;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:1.5px solid #E2E8F0;padding-bottom:16px;">
            <h2 style="margin:0;font-size:18px;font-weight:700;color:#0F172A;">Atur Tombol Cepat</h2>
            <button type="button" onclick="closeQuickActionSettings()" style="background:none;border:none;cursor:pointer;font-size:24px;color:#64748B;">&times;</button>
        </div>

        <div style="max-height:400px;overflow-y:auto;margin-bottom:20px;">
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_tamu" <?php echo ($quickActionSettings['tamu'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Tamu</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_booking" <?php echo ($quickActionSettings['booking'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;\">Pemesanan</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_calendar" <?php echo ($quickActionSettings['calendar'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Kalender Booking</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_partner" <?php echo ($quickActionSettings['partner'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Hotel & Homestay</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_guide" <?php echo ($quickActionSettings['guide'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Guide Darat/Laut</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_coordinator" <?php echo ($quickActionSettings['coordinator'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Koordinator</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_facility" <?php echo ($quickActionSettings['facility'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Fasilitas</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_customer" <?php echo ($quickActionSettings['customer'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Tamu Baru</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_quotation" <?php echo ($quickActionSettings['quotation'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Penawaran</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_invoice" <?php echo ($quickActionSettings['invoice'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Invoice</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_calculator" <?php echo ($quickActionSettings['calculator'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Kalkulator Harga</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:0;cursor:pointer;border-radius:8px;transition:.2s;">
                <input type="checkbox" id="qa_package" <?php echo ($quickActionSettings['package'] ?? true) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <span style="flex:1;">Paket Baru</span>
            </label>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1.5px solid #E2E8F0;padding-top:16px;">
            <button type="button" onclick="closeQuickActionSettings()" class="ss-btn ss-btn-outline">Batal</button>
            <button type="button" onclick="saveQuickActionSettings()" class="ss-btn ss-btn-primary">Simpan Perubahan</button>
        </div>
    </div>
</div>

<style>
    @keyframes slideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    #qaSettingsModal label:hover {
        background: #F8FAFC;
    }

    #qaSettingsModal input[type="checkbox"] {
        accent-color: #C2410C;
    }
</style>

<?php include 'layout-footer.php'; ?>