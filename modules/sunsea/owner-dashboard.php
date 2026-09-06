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

// Invoice outstanding
$invoiceStats = $pdo->query("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(remaining_amount),0) AS total_outstanding
    FROM invoices WHERE status IN ('issued','partial')
")->fetch();
$recentInvoices = $pdo->query("
    SELECT i.invoice_no, i.status, i.remaining_amount, i.due_date, c.name AS customer_name
    FROM invoices i JOIN customers c ON c.id = i.customer_id
    WHERE i.status IN ('issued','partial')
    ORDER BY i.due_date ASC
    LIMIT 5
")->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Owner Dashboard - Explore Karimunjawa</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<script src="https://unpkg.com/feather-icons"></script>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    :root {
        --ocean:#0369A1; --success:#059669; --danger:#DC2626; --muted:#64748B;
        --text:#1E293B; --sky:#F0F9FF; --border:#E2E8F0;
    }
    body {
        font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
        background:#F8FAFC; color:var(--text); font-size:14px; padding-bottom:24px;
    }
    .ob-header {
        background:linear-gradient(135deg,#0369A1 0%,#0EA5E9 100%);
        color:#fff; padding:18px 16px 22px;
    }
    .ob-header-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .ob-brand { display:flex; align-items:center; gap:8px; font-weight:700; font-size:15px; }
    .ob-avatar {
        width:30px; height:30px; border-radius:50%; background:rgba(255,255,255,.25);
        display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px;
    }
    .ob-user { display:flex; align-items:center; gap:8px; font-size:12.5px; }
    .ob-greeting { font-size:12px; opacity:.85; }
    .ob-title { font-size:19px; font-weight:800; margin-top:2px; }
    .ob-container { padding:14px; max-width:520px; margin:0 auto; }
    .ob-cards { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:14px; }
    .ob-card {
        background:#fff; border-radius:12px; padding:14px; text-decoration:none; color:inherit;
        box-shadow:0 1px 3px rgba(0,0,0,.06); border:1px solid var(--border);
        display:block;
    }
    .ob-card-label { font-size:10px; color:var(--muted); text-transform:uppercase; font-weight:600; }
    .ob-card-value { font-size:20px; font-weight:800; margin:4px 0 2px; }
    .ob-card-sub { font-size:11px; color:var(--muted); }
    .ob-section {
        background:#fff; border-radius:12px; padding:14px; margin-bottom:14px;
        box-shadow:0 1px 3px rgba(0,0,0,.06); border:1px solid var(--border);
    }
    .ob-section-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
    .ob-section-title { font-size:13px; font-weight:700; }
    .ob-section-link { font-size:11px; color:var(--ocean); text-decoration:none; font-weight:600; }
    .ob-row {
        display:flex; justify-content:space-between; align-items:center; padding:9px 10px;
        background:var(--sky); border-radius:8px; text-decoration:none; color:inherit; margin-bottom:8px;
    }
    .ob-row:last-child { margin-bottom:0; }
    .ob-row-title { font-size:12.5px; font-weight:700; color:var(--text); }
    .ob-row-sub { font-size:11px; color:var(--muted); margin-top:1px; }
    .ob-row-meta { text-align:right; font-size:11px; color:var(--muted); }
    .ob-empty { font-size:12px; color:var(--muted); text-align:center; padding:12px 0; }
    .ob-quicklinks { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
    .ob-qbtn {
        display:flex; align-items:center; justify-content:center; gap:6px;
        background:#fff; border:1.5px solid var(--ocean); color:var(--ocean);
        border-radius:10px; padding:12px 8px; text-decoration:none; font-size:12.5px; font-weight:700;
    }
    .ob-qbtn svg { width:16px; height:16px; }
</style>
</head>
<body>

<div class="ob-header">
    <div class="ob-header-top">
        <div class="ob-brand">🌊 Explore Karimunjawa</div>
        <div class="ob-user">
            <div class="ob-avatar"><?php echo strtoupper(substr($userName, 0, 1)); ?></div>
            <?php echo htmlspecialchars($userName); ?>
        </div>
    </div>
    <div class="ob-greeting">Welcome back,</div>
    <div class="ob-title">Owner Dashboard</div>
</div>

<div class="ob-container">

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
            <div class="ob-row">
                <div>
                    <div class="ob-row-title"><?php echo htmlspecialchars($inv['invoice_no']); ?></div>
                    <div class="ob-row-sub"><?php echo htmlspecialchars($inv['customer_name']); ?> · JT <?php echo $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '-'; ?></div>
                </div>
                <div style="font-size:12px;font-weight:700;color:var(--danger);"><?php echo sunseaRupiah((float)$inv['remaining_amount']); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

<script>if (window.feather) feather.replace();</script>
</body>
</html>
