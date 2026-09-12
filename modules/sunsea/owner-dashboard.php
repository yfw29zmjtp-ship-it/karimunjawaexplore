<?php

/**
 * Sunsea - Owner Dashboard
 * Ringkasan mobile-friendly untuk role Developer/Owner: reservasi tamu,
 * kalender booking, invoice, dan finance dalam satu layar.
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

$today = date('Y-m-d');

// Reservasi tamu mendatang (confirmed, belum lewat)
$upcomingBookings = $pdo->prepare("
    SELECT b.id, b.booking_no, b.start_date, b.end_date, b.pax_count, b.status,
           c.name AS customer_name
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    WHERE b.status = 'confirmed' AND b.end_date >= ?
    ORDER BY b.start_date ASC
    LIMIT 6
");
$upcomingBookings->execute([$today]);
$upcomingBookings = $upcomingBookings->fetchAll();

// Booking pending (belum di-confirm)
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM booking_orders WHERE status='draft'")->fetchColumn();
$confirmedCount = (int)$pdo->query("SELECT COUNT(*) FROM booking_orders WHERE status='confirmed'")->fetchColumn();

// Invoice outstanding (dihitung dari total - terbayar, bukan kolom remaining_amount yang bisa basi)
$invoiceStats = $pdo->query("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)),0) AS total_outstanding
    FROM invoices WHERE status IN ('issued','partial')
")->fetch();
$recentInvoices = $pdo->query("
    SELECT i.invoice_no, i.status, i.total_amount, i.paid_amount, i.remaining_amount, i.due_date, c.name AS customer_name
    FROM invoices i JOIN customers c ON c.id = i.customer_id
    WHERE i.status IN ('issued','partial')
    ORDER BY i.due_date ASC
    LIMIT 5
")->fetchAll();
foreach ($recentInvoices as &$_inv) {
    // Hitung ulang sisa tagihan dari total - terbayar, jangan percaya kolom remaining_amount yang bisa basi.
    $_inv['remaining_amount'] = max(0, (float)$_inv['total_amount'] - (float)$_inv['paid_amount']);
}
unset($_inv);

// Finance bulan berjalan
$financeRow = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS total_income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS total_expense
    FROM cash_book
    WHERE transaction_date BETWEEN ? AND ?
");
$financeRow->execute([date('Y-m-01'), date('Y-m-t')]);
$financeRow = $financeRow->fetch();
$monthIncome  = (float)$financeRow['total_income'];
$monthExpense = (float)$financeRow['total_expense'];
$monthBalance = $monthIncome - $monthExpense;

$userName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'Owner';

$companyLogoPath = sunseaSetting($pdo, 'company_logo', '');
$companyLogoSrc  = $companyLogoPath ? sunseaAssetUrl($companyLogoPath) : '';

// Pie 1: status booking (confirmed vs pending) - sudah tidak dipakai, diganti grafik Total Pax Bulan Ini
$bookingPieTotal = $pendingCount + $confirmedCount;
$confirmedPct = $bookingPieTotal > 0 ? round($confirmedCount / $bookingPieTotal * 100) : 0;

// Pie 2: keuangan bulan ini (masuk vs keluar)
$financePieTotal = $monthIncome + $monthExpense;
$monthIncomePct = $financePieTotal > 0 ? round($monthIncome / $financePieTotal * 100) : 0;
$monthProfit = max(0, $monthIncome - $monthExpense);

// Total Pax dalam 1 bulan ini (per hari), pengganti donut Status Booking
$paxDaysInMonth = (int)date('t');
$paxDayLabels = [];
$paxDayTotals = [];
for ($pd = 1; $pd <= $paxDaysInMonth; $pd++) {
    $paxDayKey = date('Y-m-') . str_pad((string)$pd, 2, '0', STR_PAD_LEFT);
    $paxDayLabels[] = (string)$pd;
    $paxDayTotals[] = (int)$pdo->query("
        SELECT COALESCE(SUM(pax_count),0) FROM booking_orders
        WHERE status <> 'cancelled' AND start_date = '{$paxDayKey}'
    ")->fetchColumn();
}
$paxDayLabelsJson = json_encode($paxDayLabels);
$paxDayTotalsJson = json_encode($paxDayTotals);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Owner Dashboard - Karimunjawa Explore</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0369A1">
    <link rel="manifest" href="owner-manifest.php">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($companyLogoSrc ?: (BASE_URL . '/img/favicon.png')); ?>">
    <script src="https://unpkg.com/feather-icons"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --ocean: #0369A1;
            --success: #059669;
            --danger: #DC2626;
            --muted: #64748B;
            --text: #1E293B;
            --sky: #F0F9FF;
            --border: #E2E8F0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #F8FAFC;
            color: var(--text);
            font-size: 14px;
            padding-bottom: 90px;
        }

        .ob-header {
            background: linear-gradient(135deg, #0369A1 0%, #0EA5E9 100%);
            color: #fff;
            padding: 18px 16px 22px;
        }

        .ob-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .ob-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 15px;
        }

        .ob-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
        }

        .ob-user {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
        }

        .ob-logout-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .18);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            text-decoration: none;
            flex-shrink: 0;
        }

        .ob-logout-btn svg {
            width: 15px;
            height: 15px;
        }

        .ob-greeting {
            font-size: 12px;
            opacity: .85;
        }

        .ob-title {
            font-size: 19px;
            font-weight: 800;
            margin-top: 2px;
        }

        .ob-container {
            padding: 14px;
            max-width: 520px;
            margin: 0 auto;
        }

        .ob-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }

        .ob-card {
            background: #fff;
            border-radius: 11px;
            padding: 10px 11px;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            border: 1px solid var(--border);
            display: block;
        }

        .ob-card-label {
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase;
            font-weight: 600;
        }

        .ob-card-value {
            font-size: 16px;
            font-weight: 700;
            margin: 3px 0 1px;
        }

        .ob-card-sub {
            font-size: 9.5px;
            color: var(--muted);
        }

        .ob-section {
            background: #fff;
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            border: 1px solid var(--border);
        }

        .ob-section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .ob-section-title {
            font-size: 13px;
            font-weight: 700;
        }

        .ob-section-link {
            font-size: 11px;
            color: var(--ocean);
            text-decoration: none;
            font-weight: 600;
        }

        .ob-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 9px 10px;
            background: var(--sky);
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            margin-bottom: 8px;
        }

        .ob-row:last-child {
            margin-bottom: 0;
        }

        .ob-row-title {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text);
        }

        .ob-row-sub {
            font-size: 11px;
            color: var(--muted);
            margin-top: 1px;
        }

        .ob-row-meta {
            text-align: right;
            font-size: 11px;
            color: var(--muted);
        }

        .ob-empty {
            font-size: 12px;
            color: var(--muted);
            text-align: center;
            padding: 12px 0;
        }

        .ob-quicklinks {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .ob-qbtn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: #fff;
            border: 1.5px solid var(--ocean);
            color: var(--ocean);
            border-radius: 10px;
            padding: 12px 8px;
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 700;
        }

        .ob-qbtn svg {
            width: 16px;
            height: 16px;
        }

        .ob-brand-logo {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            background: #fff;
            padding: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .15);
        }

        .ob-brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .ob-pies-panel {
            background: linear-gradient(135deg, #e0f2fe 0%, #ede9fe 50%, #fce7f3 100%);
            border-radius: 20px;
            padding: 12px;
            margin-bottom: 14px;
        }

        .ob-pies-row {
            display: flex;
            gap: 10px;
        }

        .ob-pie-card {
            flex: 1;
            background: rgba(255, 255, 255, .55);
            backdrop-filter: blur(12px) saturate(160%);
            -webkit-backdrop-filter: blur(12px) saturate(160%);
            border: 1px solid rgba(255, 255, 255, .7);
            border-radius: 16px;
            padding: 12px 10px;
            box-shadow: 0 8px 20px rgba(31, 41, 55, .08), inset 0 1px 0 rgba(255, 255, 255, .6);
            text-align: center;
        }

        .ob-pie-title {
            font-size: 9.5px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .03em;
            margin-bottom: 8px;
        }

        .ob-donut {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            margin: 0 auto 8px;
            position: relative;
        }

        .ob-donut::before {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: inherit;
            filter: blur(6px);
            opacity: .35;
            z-index: -1;
        }

        .ob-donut::after {
            content: '';
            position: absolute;
            inset: 13px;
            background: rgba(255, 255, 255, .85);
            backdrop-filter: blur(4px);
            border-radius: 50%;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, .06);
        }

        .ob-donut-label {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text);
            z-index: 1;
        }

        .ob-pie-legend {
            display: flex;
            justify-content: center;
            gap: 10px;
            font-size: 9px;
            color: var(--muted);
            font-weight: 500;
        }

        .ob-pie-legend span {
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .ob-pie-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, .5);
        }

        .ob-install-banner {
            position: fixed;
            left: 12px;
            right: 12px;
            bottom: 12px;
            z-index: 900;
            background: linear-gradient(135deg, #0369A1 0%, #0EA5E9 100%);
            color: #fff;
            border-radius: 16px;
            padding: 12px 14px;
            display: none;
            align-items: center;
            gap: 10px;
            box-shadow: 0 10px 26px rgba(3, 105, 161, .35);
            cursor: pointer;
        }

        .ob-install-banner.show {
            display: flex;
            animation: obIbUp .4s cubic-bezier(.16, 1, .3, 1);
        }

        @keyframes obIbUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .ob-ib-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: rgba(255, 255, 255, .2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }

        .ob-ib-text {
            flex: 1;
            min-width: 0;
        }

        .ob-ib-title {
            font-size: 12.5px;
            font-weight: 700;
        }

        .ob-ib-sub {
            font-size: 10.5px;
            opacity: .85;
            margin-top: 1px;
        }

        .ob-ib-action {
            background: #fff;
            color: var(--ocean);
            border: none;
            padding: 8px 14px;
            border-radius: 9px;
            font-size: 11.5px;
            font-weight: 700;
            white-space: nowrap;
        }

        .ob-ib-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, .7);
            font-size: 14px;
            padding: 2px 4px;
            flex-shrink: 0;
        }
    </style>
</head>

<body>

    <div class="ob-header">
        <div class="ob-header-top">
            <div class="ob-brand">
                <div class="ob-brand-logo">
                    <?php if ($companyLogoSrc): ?>
                        <img src="<?php echo htmlspecialchars($companyLogoSrc); ?>" alt="Logo">
                    <?php else: ?>
                        🌊
                    <?php endif; ?>
                </div>
                Karimunjawa Explore
            </div>
            <div class="ob-user">
                <div class="ob-avatar"><?php echo strtoupper(substr($userName, 0, 1)); ?></div>
                <?php echo htmlspecialchars($userName); ?>
                <a href="<?php echo BASE_URL; ?>/logout.php?redirect=owner" class="ob-logout-btn" title="Logout">
                    <i data-feather="log-out"></i>
                </a>
            </div>
        </div>
        <div class="ob-greeting">Welcome back,</div>
        <div class="ob-title">Owner Dashboard</div>
    </div>

    <div class="ob-container">

        <div class="ob-pies-panel">
            <div class="ob-pies-row">
                <div class="ob-pie-card">
                    <div class="ob-pie-title">Total Pax Bulan Ini</div>
                    <div style="position:relative;height:150px;">
                        <canvas id="obPaxMonthChart"></canvas>
                    </div>
                </div>
                <div class="ob-pie-card">
                    <div class="ob-pie-title">Keuangan Bulan Ini</div>
                    <div style="position:relative;height:150px;">
                        <canvas id="obFinancePieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="ob-quicklinks" style="margin-bottom:14px;">
            <a href="owner-bookings.php" class="ob-qbtn"><i data-feather="briefcase"></i> Reservasi Tamu</a>
            <a href="owner-calendar.php" class="ob-qbtn"><i data-feather="calendar"></i> Kalender Booking</a>
            <a href="owner-invoices.php" class="ob-qbtn"><i data-feather="credit-card"></i> Invoice</a>
            <a href="owner-finance.php" class="ob-qbtn"><i data-feather="dollar-sign"></i> Finance</a>
        </div>

        <div class="ob-cards">
            <a href="owner-bookings.php?status=draft" class="ob-card">
                <div class="ob-card-label">Pending</div>
                <div class="ob-card-value" style="color:var(--ocean);"><?php echo $pendingCount; ?></div>
                <div class="ob-card-sub">Booking belum confirm</div>
            </a>
            <a href="owner-bookings.php?status=confirmed" class="ob-card">
                <div class="ob-card-label">Confirmed</div>
                <div class="ob-card-value" style="color:var(--success);"><?php echo $confirmedCount; ?></div>
                <div class="ob-card-sub">Masuk kalender</div>
            </a>
            <a href="owner-invoices.php?status=issued" class="ob-card">
                <div class="ob-card-label">Invoice Belum Lunas</div>
                <div class="ob-card-value" style="color:var(--danger);"><?php echo (int)$invoiceStats['cnt']; ?></div>
                <div class="ob-card-sub"><?php echo sunseaRupiah((float)$invoiceStats['total_outstanding']); ?></div>
            </a>
            <a href="owner-finance.php" class="ob-card">
                <div class="ob-card-label">Saldo Bulan Ini</div>
                <div class="ob-card-value" style="color:<?php echo $monthBalance >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;"><?php echo sunseaRupiah($monthBalance); ?></div>
                <div class="ob-card-sub">Masuk <?php echo sunseaRupiah($monthIncome, true); ?> · Keluar <?php echo sunseaRupiah($monthExpense, true); ?></div>
            </a>
        </div>

        <div class="ob-section">
            <div class="ob-section-head">
                <div class="ob-section-title">Reservasi Tamu Mendatang</div>
                <a href="owner-calendar.php" class="ob-section-link">Lihat Kalender →</a>
            </div>
            <?php if (empty($upcomingBookings)): ?>
                <div class="ob-empty">Belum ada reservasi confirmed yang akan datang.</div>
            <?php else: ?>
                <?php foreach ($upcomingBookings as $b): ?>
                    <a href="owner-bookings.php?status=confirmed" class="ob-row">
                        <div>
                            <div class="ob-row-title"><?php echo htmlspecialchars($b['booking_no']); ?></div>
                            <div class="ob-row-sub"><?php echo htmlspecialchars($b['customer_name']); ?> · <?php echo (int)$b['pax_count']; ?> pax</div>
                        </div>
                        <div class="ob-row-meta">
                            <?php echo date('d M', strtotime($b['start_date'])); ?> - <?php echo date('d M Y', strtotime($b['end_date'])); ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="ob-section">
            <div class="ob-section-head">
                <div class="ob-section-title">Invoice Perlu Ditagih</div>
                <a href="owner-invoices.php" class="ob-section-link">Lihat Semua →</a>
            </div>
            <?php if (empty($recentInvoices)): ?>
                <div class="ob-empty">Semua invoice sudah lunas. 🎉</div>
            <?php else: ?>
                <?php foreach ($recentInvoices as $inv): ?>
                    <div class="ob-row" style="flex-direction:column;align-items:stretch;gap:6px;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                            <div>
                                <div class="ob-row-title"><?php echo htmlspecialchars($inv['invoice_no']); ?></div>
                                <div class="ob-row-sub"><?php echo htmlspecialchars($inv['customer_name']); ?> · JT <?php echo $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '-'; ?></div>
                            </div>
                            <div style="font-size:12px;font-weight:700;color:var(--danger);text-align:right;">
                                Sisa Tagihan<br><?php echo sunseaRupiah((float)$inv['remaining_amount']); ?>
                            </div>
                        </div>
                        <?php if ((float)$inv['paid_amount'] > 0): ?>
                            <div style="display:flex;gap:12px;font-size:10.5px;color:var(--muted);border-top:1px dashed var(--border);padding-top:6px;">
                                <span>Total Paket: <strong style="color:var(--text);"><?php echo sunseaRupiah((float)$inv['total_amount']); ?></strong></span>
                                <span>DP Terbayar: <strong style="color:var(--success);"><?php echo sunseaRupiah((float)$inv['paid_amount']); ?></strong></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <div class="ob-install-banner" id="obInstallBanner">
        <div class="ob-ib-icon">📲</div>
        <div class="ob-ib-text">
            <div class="ob-ib-title" id="obIbTitle">Install Owner Portal</div>
            <div class="ob-ib-sub" id="obIbSub">Akses lebih cepat dari home screen</div>
        </div>
        <button class="ob-ib-action" id="obIbAction">Install</button>
        <button class="ob-ib-close" onclick="event.stopPropagation();this.parentElement.classList.remove('show');localStorage.setItem('ob_ib_dismissed','1');">✕</button>
    </div>

    <script>
        if (window.feather) feather.replace();
    </script>
    <script>
        // Total Pax Bulan Ini (per hari), pengganti donut Status Booking
        const obPaxCtx = document.getElementById('obPaxMonthChart');
        if (obPaxCtx) {
            new Chart(obPaxCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo $paxDayLabelsJson; ?>,
                    datasets: [{
                        label: 'Total Pax',
                        data: <?php echo $paxDayTotalsJson; ?>,
                        backgroundColor: '#0891b2cc',
                        borderRadius: 6,
                        maxBarThickness: 14
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
                                    size: 9
                                }
                            },
                            grid: {
                                color: 'rgba(0,0,0,.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: 8
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Keuangan Bulan Ini: Pemasukan vs Pengeluaran vs Profit Margin (sama seperti dashboard system)
        const obFinanceCtx = document.getElementById('obFinancePieChart');
        if (obFinanceCtx) {
            new Chart(obFinanceCtx, {
                type: 'pie',
                data: {
                    labels: ['Pemasukan', 'Pengeluaran', 'Profit Margin'],
                    datasets: [{
                        data: [<?php echo (float)$monthIncome; ?>, <?php echo (float)$monthExpense; ?>, <?php echo (float)$monthProfit; ?>],
                        backgroundColor: ['#10b981', '#ef4444', '#0369A1'],
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
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 9.5
                                },
                                padding: 8,
                                usePointStyle: true,
                                boxWidth: 8
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
    </script>
    <script>
        (function() {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('owner-sw.js', {
                    scope: './'
                }).catch(function() {});
            }

            var deferredPrompt = null;
            var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
            var wasDismissed = localStorage.getItem('ob_ib_dismissed') === '1';
            var banner = document.getElementById('obInstallBanner');
            var ibBtn = document.getElementById('obIbAction');

            if (isStandalone || wasDismissed || !banner) return;

            function showBanner(mode) {
                if (banner.classList.contains('show')) return;
                if (mode === 'manual') {
                    document.getElementById('obIbSub').textContent = 'Tap ⋮ menu Chrome → "Install app"';
                    ibBtn.textContent = 'Cara Install';
                    ibBtn.dataset.mode = 'manual';
                } else {
                    document.getElementById('obIbSub').textContent = 'Buka langsung dari home screen';
                    ibBtn.textContent = 'Install';
                    ibBtn.dataset.mode = 'native';
                }
                banner.classList.add('show');
            }

            window.addEventListener('beforeinstallprompt', function(e) {
                e.preventDefault();
                deferredPrompt = e;
                showBanner('native');
            });

            if (!isIOS) {
                [4000, 10000].forEach(function(ms) {
                    setTimeout(function() {
                        if (!deferredPrompt && !isStandalone) showBanner('manual');
                    }, ms);
                });
            } else {
                setTimeout(function() {
                    showBanner('manual');
                }, 3000);
            }

            ibBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!deferredPrompt || ibBtn.dataset.mode === 'manual') {
                    showManualGuide();
                    return;
                }
                deferredPrompt.prompt();
                deferredPrompt.userChoice.finally(function() {
                    banner.classList.remove('show');
                    deferredPrompt = null;
                });
            });

            banner.addEventListener('click', function(e) {
                if (e.target.closest('.ob-ib-close') || e.target.closest('.ob-ib-action')) return;
                ibBtn.click();
            });

            window.addEventListener('appinstalled', function() {
                banner.classList.remove('show');
                localStorage.removeItem('ob_ib_dismissed');
                deferredPrompt = null;
            });

            function showManualGuide() {
                var ov = document.createElement('div');
                ov.style.cssText = 'position:fixed;inset:0;z-index:2000;background:rgba(5,10,24,.94);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;';
                ov.innerHTML = '<div style="text-align:center;max-width:300px;">' +
                    '<div style="font-size:48px;margin-bottom:14px;">📲</div>' +
                    '<h3 style="color:#fff;font-size:16px;font-weight:700;margin:0 0 6px;">Install Owner Portal</h3>' +
                    '<p style="color:rgba(255,255,255,.5);font-size:11.5px;margin:0 0 22px;">' + (isIOS ? 'Di Safari: tap ikon Share (kotak+panah) → "Add to Home Screen".' : 'Tap menu ⋮ Chrome → "Install app" atau "Add to Home screen".') + '</p>' +
                    '<button style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:10px 28px;border-radius:11px;font-size:12.5px;font-weight:600;width:100%;">Mengerti</button>' +
                    '</div>';
                ov.querySelector('button').addEventListener('click', function() {
                    ov.remove();
                });
                ov.addEventListener('click', function(e) {
                    if (e.target === ov) ov.remove();
                });
                document.body.appendChild(ov);
            }
        })();
    </script>
</body>

</html>