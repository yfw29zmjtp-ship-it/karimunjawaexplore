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

$pageTitle  = 'Owner Dashboard';
$activePage = 'owner_dashboard';
include 'layout-header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
    <div>
        <h3 style="margin:0;font-size:16px;">Owner Dashboard</h3>
        <div style="color:var(--ss-muted);font-size:11.5px;">Ringkasan cepat untuk dilihat dari HP</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px;">
    <a href="bookings.php" class="ss-card" style="text-decoration:none;padding:14px;">
        <div style="font-size:10px;color:var(--ss-muted);text-transform:uppercase;">Pending</div>
        <div style="font-size:20px;font-weight:800;color:var(--ss-ocean);"><?php echo $pendingCount; ?></div>
        <div style="font-size:11px;color:var(--ss-muted);">Booking belum confirm</div>
    </a>
    <a href="calendar.php" class="ss-card" style="text-decoration:none;padding:14px;">
        <div style="font-size:10px;color:var(--ss-muted);text-transform:uppercase;">Confirmed</div>
        <div style="font-size:20px;font-weight:800;color:var(--ss-success);"><?php echo $confirmedCount; ?></div>
        <div style="font-size:11px;color:var(--ss-muted);">Masuk kalender</div>
    </a>
    <a href="invoices.php?status=issued" class="ss-card" style="text-decoration:none;padding:14px;">
        <div style="font-size:10px;color:var(--ss-muted);text-transform:uppercase;">Invoice Belum Lunas</div>
        <div style="font-size:20px;font-weight:800;color:var(--ss-danger);"><?php echo (int)$invoiceStats['cnt']; ?></div>
        <div style="font-size:11px;color:var(--ss-muted);"><?php echo sunseaRupiah((float)$invoiceStats['total_outstanding']); ?></div>
    </a>
    <a href="finance.php" class="ss-card" style="text-decoration:none;padding:14px;">
        <div style="font-size:10px;color:var(--ss-muted);text-transform:uppercase;">Saldo Bulan Ini</div>
        <div style="font-size:20px;font-weight:800;color:<?php echo $monthBalance >= 0 ? 'var(--ss-success)' : 'var(--ss-danger)'; ?>;"><?php echo sunseaRupiah($monthBalance); ?></div>
        <div style="font-size:11px;color:var(--ss-muted);">Masuk <?php echo sunseaRupiah($monthIncome, true); ?> · Keluar <?php echo sunseaRupiah($monthExpense, true); ?></div>
    </a>
</div>

<div class="ss-card" style="margin-bottom:14px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <div class="ss-card-title" style="margin:0;font-size:13px;">Reservasi Tamu Mendatang</div>
        <a href="calendar.php" style="font-size:11px;color:var(--ss-ocean);text-decoration:none;">Lihat Kalender →</a>
    </div>
    <?php if (empty($upcomingBookings)): ?>
        <div style="font-size:12px;color:var(--ss-muted);">Belum ada reservasi confirmed yang akan datang.</div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($upcomingBookings as $b): ?>
                <a href="bookings.php?view=<?php echo (int)$b['id']; ?>" style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:var(--ss-sky);border-radius:8px;text-decoration:none;color:inherit;">
                    <div>
                        <div style="font-size:12.5px;font-weight:700;color:var(--ss-text);"><?php echo htmlspecialchars($b['booking_no']); ?></div>
                        <div style="font-size:11px;color:var(--ss-muted);"><?php echo htmlspecialchars($b['customer_name']); ?> · <?php echo (int)$b['pax_count']; ?> pax</div>
                    </div>
                    <div style="text-align:right;font-size:11px;color:var(--ss-muted);">
                        <?php echo date('d M', strtotime($b['start_date'])); ?> - <?php echo date('d M Y', strtotime($b['end_date'])); ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="ss-card" style="margin-bottom:14px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <div class="ss-card-title" style="margin:0;font-size:13px;">Invoice Perlu Ditagih</div>
        <a href="invoices.php" style="font-size:11px;color:var(--ss-ocean);text-decoration:none;">Lihat Semua →</a>
    </div>
    <?php if (empty($recentInvoices)): ?>
        <div style="font-size:12px;color:var(--ss-muted);">Semua invoice sudah lunas. 🎉</div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($recentInvoices as $inv): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:var(--ss-sky);border-radius:8px;">
                    <div>
                        <div style="font-size:12.5px;font-weight:700;color:var(--ss-text);"><?php echo htmlspecialchars($inv['invoice_no']); ?></div>
                        <div style="font-size:11px;color:var(--ss-muted);"><?php echo htmlspecialchars($inv['customer_name']); ?> · JT <?php echo $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '-'; ?></div>
                    </div>
                    <div style="font-size:12px;font-weight:700;color:var(--ss-danger);"><?php echo sunseaRupiah((float)$inv['remaining_amount']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;">
    <a href="bookings.php" class="ss-btn ss-btn-outline ss-btn-sm" style="justify-content:center;"><i data-feather="briefcase"></i> Reservasi Tamu</a>
    <a href="calendar.php" class="ss-btn ss-btn-outline ss-btn-sm" style="justify-content:center;"><i data-feather="calendar"></i> Kalender Booking</a>
    <a href="invoices.php" class="ss-btn ss-btn-outline ss-btn-sm" style="justify-content:center;"><i data-feather="credit-card"></i> Invoice</a>
    <a href="finance.php" class="ss-btn ss-btn-outline ss-btn-sm" style="justify-content:center;"><i data-feather="dollar-sign"></i> Finance</a>
</div>

<?php include 'layout-footer.php'; ?>
