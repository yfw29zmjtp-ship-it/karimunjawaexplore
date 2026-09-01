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
            COALESCE(SUM(CASE WHEN status IN ('issued','partial') THEN remaining_amount ELSE 0 END), 0) as outstanding
        FROM invoices
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
} catch (Exception $e) {
    $dbError = $e->getMessage();
    $qStats = ['total' => 0, 'draft' => 0, 'sent' => 0, 'approved' => 0];
    $iStats = ['total' => 0, 'issued' => 0, 'partial' => 0, 'paid' => 0, 'overdue' => 0, 'outstanding' => 0];
    $custCount = $pkgCount = 0;
    $monthRevenue = 0;
    $bookingStats = ['total' => 0, 'active' => 0];
    $recentQuotations = $recentInvoices = [];
    $monthLabels = json_encode([]);
    $monthlyGuests = json_encode([]);
    $yearLabels = json_encode([]);
    $yearlyGuests = json_encode([]);
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
<div class="ss-stats-grid">
    <div class="ss-stat-card">
        <div class="ss-stat-icon ocean"><i data-feather="users"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo $custCount; ?></div>
            <div class="ss-stat-label">Total Pelanggan</div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon cyan"><i data-feather="file-text"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo $qStats['total'] ?? 0; ?></div>
            <div class="ss-stat-label">Penawaran <span style="color:var(--ss-warning);"><?php echo (int)($qStats['sent'] ?? 0); ?> terkirim</span></div>
        </div>
    </div>
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
        <div class="ss-stat-icon danger"><i data-feather="alert-triangle"></i></div>
        <div>
            <div class="ss-stat-value" style="font-size:16px;"><?php echo sunseaRupiah((float)($iStats['outstanding'] ?? 0), true); ?></div>
            <div class="ss-stat-label">Piutang Belum Lunas</div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon ocean"><i data-feather="package"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo $pkgCount; ?></div>
            <div class="ss-stat-label">Paket Wisata Aktif</div>
        </div>
    </div>
    <div class="ss-stat-card">
        <div class="ss-stat-icon cyan"><i data-feather="briefcase"></i></div>
        <div>
            <div class="ss-stat-value"><?php echo (int)($bookingStats['total'] ?? 0); ?></div>
            <div class="ss-stat-label">Pemesanan <span style="color:var(--ss-ocean);"><?php echo (int)($bookingStats['active'] ?? 0); ?> aktif</span></div>
        </div>
    </div>
</div>

<!-- ============================
     QUICK ACTIONS - ELEGANT GRID
============================= -->
<div class="ss-card" style="margin-bottom:24px;">
    <div class="ss-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <div class="ss-card-title">Aksi Cepat</div>
            <div class="ss-card-sub">Akses cepat ke fitur utama</div>
        </div>
        <button type="button" class="ss-btn ss-btn-outline" onclick="openQuickActionSettings()" style="margin:0;">
            <i data-feather="settings"></i> <span style="font-size:11px;">Setelan</span>
        </button>
    </div>
    <div class="ss-quick-actions-grid">
        <?php if ($quickActionSettings['tamu'] ?? true): ?>
            <a href="customers.php" class="ss-quick-action-btn" title="Daftar Tamu">
                <div class="ss-qa-icon"><i data-feather="users"></i></div>
                <div class="ss-qa-label">Tamu</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['booking'] ?? true): ?>
            <a href="bookings.php?action=add" class="ss-quick-action-btn ss-qa-primary" title="Input Pemesanan">
                <div class="ss-qa-icon"><i data-feather="briefcase"></i></div>
                <div class="ss-qa-label">Pemesanan</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['calendar'] ?? true): ?>
            <a href="calendar.php" class="ss-quick-action-btn" title="Kalender Booking">
                <div class="ss-qa-icon"><i data-feather="calendar"></i></div>
                <div class="ss-qa-label">Kalender</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['partner'] ?? true): ?>
            <a href="partners.php" class="ss-quick-action-btn" title="Hotel & Homestay">
                <div class="ss-qa-icon"><i data-feather="building"></i></div>
                <div class="ss-qa-label">Hotel</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['guide'] ?? true): ?>
            <a href="guides.php" class="ss-quick-action-btn" title="Guide Darat/Laut">
                <div class="ss-qa-icon"><i data-feather="compass"></i></div>
                <div class="ss-qa-label">Guide</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['coordinator'] ?? true): ?>
            <a href="coordinators.php" class="ss-quick-action-btn" title="Koordinator">
                <div class="ss-qa-icon"><i data-feather="user-check"></i></div>
                <div class="ss-qa-label">Koordinator</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['facility'] ?? true): ?>
            <a href="facilities.php" class="ss-quick-action-btn" title="Fasilitas">
                <div class="ss-qa-icon"><i data-feather="tool"></i></div>
                <div class="ss-qa-label">Fasilitas</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['customer'] ?? true): ?>
            <a href="customers.php?action=add" class="ss-quick-action-btn" title="Tambah Tamu">
                <div class="ss-qa-icon"><i data-feather="user-plus"></i></div>
                <div class="ss-qa-label">Tamu Baru</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['quotation'] ?? true): ?>
            <a href="quotations.php?action=add" class="ss-quick-action-btn ss-qa-primary" title="Buat Penawaran">
                <div class="ss-qa-icon"><i data-feather="file-plus"></i></div>
                <div class="ss-qa-label">Penawaran</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['invoice'] ?? true): ?>
            <a href="invoices.php?action=add" class="ss-quick-action-btn" title="Buat Invoice">
                <div class="ss-qa-icon"><i data-feather="plus-circle"></i></div>
                <div class="ss-qa-label">Invoice</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['calculator'] ?? true): ?>
            <a href="calculator.php" class="ss-quick-action-btn" title="Kalkulator Harga">
                <div class="ss-qa-icon"><i data-feather="calculator"></i></div>
                <div class="ss-qa-label">Kalkulator</div>
            </a>
        <?php endif; ?>

        <?php if ($quickActionSettings['package'] ?? true): ?>
            <a href="packages.php?action=add" class="ss-quick-action-btn" title="Paket Baru">
                <div class="ss-qa-icon"><i data-feather="plus"></i></div>
                <div class="ss-qa-label">Paket</div>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================
     RESERVATION CHARTS
============================= -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

    <!-- Monthly Guests Chart -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Tamu Reservasi Bulanan</div>
                <div class="ss-card-sub">Total tamu per bulan (12 bulan terakhir)</div>
            </div>
        </div>
        <div style="position:relative;height:300px;padding:10px;">
            <canvas id="monthlyGuestsChart"></canvas>
        </div>
    </div>

    <!-- Yearly Guests Chart -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Tamu Reservasi Tahunan</div>
                <div class="ss-card-sub">Total tamu per tahun (5 tahun terakhir)</div>
            </div>
        </div>
        <div style="position:relative;height:300px;padding:10px;">
            <canvas id="yearlyGuestsChart"></canvas>
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

    // Monthly Guests Chart
    const monthlyCtx = document.getElementById('monthlyGuestsChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo $monthLabels; ?>,
                datasets: [{
                    label: 'Tamu Reservasi',
                    data: <?php echo $monthlyGuests; ?>,
                    borderColor: oceanColors.primary,
                    backgroundColor: oceanColors.primary + '15',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointBackgroundColor: oceanColors.primary,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7
                }],
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
                                stepSize: 5,
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
            }
        });
    }

    // Yearly Guests Chart
    const yearlyCtx = document.getElementById('yearlyGuestsChart');
    if (yearlyCtx) {
        new Chart(yearlyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo $yearLabels; ?>,
                datasets: [{
                    label: 'Total Tamu',
                    data: <?php echo $yearlyGuests; ?>,
                    backgroundColor: [
                        oceanColors.primary,
                        oceanColors.secondary,
                        oceanColors.success,
                        oceanColors.warning,
                        oceanColors.danger
                    ],
                    borderRadius: 8,
                    borderWidth: 0
                }],
                options: {
                    indexAxis: 'x',
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
                                padding: 15
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 20,
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
            }
        });
    }

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