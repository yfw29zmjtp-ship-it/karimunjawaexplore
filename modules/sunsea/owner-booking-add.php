<?php

/**
 * Sunsea - Owner mobile view: Buat Reservasi Baru
 * Menulis langsung ke booking_orders/booking_order_items/booking_schedule
 * (tabel yang sama dipakai bookings.php di sistem utama) supaya tetap sinkron.
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
$pdo = getSunseaConnection();
if (!sunseaCanAccessMenu($pdo, $currentUser, 'owner_dashboard')) {
    header('Location: dashboard.php');
    exit;
}

sunseaEnsureBookingSchema($pdo);
$username = $currentUser['username'] ?? 'system';

function ownerBookingCreateCustomer(PDO $pdo, string $name, string $phone, string $email): int
{
    $lastCode = $pdo->query("SELECT code FROM customers ORDER BY id DESC LIMIT 1")->fetchColumn();
    $nextNum = 1;
    if ($lastCode && preg_match('/(\d+)$/', $lastCode, $m)) {
        $nextNum = (int)$m[1] + 1;
    }
    $newCode = 'SS-CUST-' . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO customers (code, name, type, email, phone, whatsapp, country) VALUES (?,?,?,?,?,?,?)")
        ->execute([$newCode, $name, 'individual', $email, $phone, $phone, 'Indonesia']);
    return (int)$pdo->lastInsertId();
}

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $newName = trim($_POST['new_customer_name'] ?? '');
        if ($customerId <= 0 && $newName !== '') {
            $customerId = ownerBookingCreateCustomer(
                $pdo,
                $newName,
                trim($_POST['new_customer_phone'] ?? ''),
                trim($_POST['new_customer_email'] ?? '')
            );
        }
        if ($customerId <= 0) {
            throw new RuntimeException('Pilih customer atau isi data customer baru.');
        }

        $packageId = (int)($_POST['package_id'] ?? 0) ?: null;
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $pax = max(1, (int)($_POST['pax_count'] ?? 1));
        $notes = trim($_POST['notes'] ?? '');

        if ($startDate === '' || $endDate === '') {
            throw new RuntimeException('Tanggal mulai dan tanggal selesai wajib diisi.');
        }
        if (strtotime($endDate) < strtotime($startDate)) {
            throw new RuntimeException('Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        $bookingMode = $packageId ? 'paket' : 'ecer';
        $costTotal = 0.0;
        $sellTotal = 0.0;
        $pkgName = null;

        if ($packageId) {
            $pkgStmt = $pdo->prepare("SELECT name, base_price FROM trip_packages WHERE id=?");
            $pkgStmt->execute([$packageId]);
            $pkg = $pkgStmt->fetch();
            if ($pkg) {
                $pkgName = $pkg['name'];
                $sellTotal = $pax * (float)$pkg['base_price'];
                $costTotal = $sellTotal * 0.8; // estimasi modal awal, bisa disesuaikan di sistem utama
            }
        }
        $margin = $sellTotal - $costTotal;
        $bookingNo = sunseaNextNumber($pdo, 'booking');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO booking_orders
                (booking_no, customer_id, booking_mode, package_id, start_date, end_date, pax_count,
                 status, cost_total, sell_total, margin_amount, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $bookingNo,
                    $customerId,
                    $bookingMode,
                    $packageId,
                    $startDate,
                    $endDate,
                    $pax,
                    'draft',
                    $costTotal,
                    $sellTotal,
                    $margin,
                    $notes,
                    $username,
                ]);
            $bookingId = (int)$pdo->lastInsertId();

            if ($packageId && $pkgName !== null) {
                $pdo->prepare("INSERT INTO booking_order_items
                    (booking_id, component_code, component_name, qty, unit, price_cost, price_sell, total_cost, total_sell, details_json, sort_order)
                    VALUES (?,?,?,?,?,?,?,?,?,?,0)")
                    ->execute([
                        $bookingId,
                        'paket',
                        'Paket: ' . $pkgName,
                        $pax,
                        'pax',
                        $costTotal / max(1, $pax),
                        (float)($pkg['base_price'] ?? 0),
                        $costTotal,
                        $sellTotal,
                        json_encode(['package_id' => $packageId], JSON_UNESCAPED_UNICODE),
                    ]);
            }

            $cur = strtotime($startDate);
            $end = strtotime($endDate);
            $sched = $pdo->prepare("INSERT INTO booking_schedule (booking_id, activity_date, activity_type, title, notes) VALUES (?,?,?,?,?)");
            while ($cur <= $end) {
                $sched->execute([$bookingId, date('Y-m-d', $cur), 'other', 'Operasional ' . $bookingNo, 'Auto-generated schedule']);
                $cur = strtotime('+1 day', $cur);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        header('Location: owner-booking-detail.php?id=' . $bookingId);
        exit;
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

$customers = $pdo->query("SELECT id, name, phone FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
$packages = $pdo->query("SELECT id, name, base_price FROM trip_packages WHERE is_active=1 ORDER BY name")->fetchAll();

$pageTitle = 'Buat Reservasi Baru';
$backUrl = 'owner-bookings.php';
include 'owner-mobile-header.php';
?>

<style>
    .ob-form-group {
        margin-bottom: 12px;
    }

    .ob-form-label {
        display: block;
        font-size: 11.5px;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 5px;
    }

    .ob-form-input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 9px;
        font-size: 13px;
        background: #fff;
        color: var(--text);
        font-family: inherit;
    }

    textarea.ob-form-input {
        resize: vertical;
        min-height: 60px;
    }

    .ob-toggle-link {
        font-size: 11.5px;
        color: var(--ocean);
        font-weight: 700;
        text-decoration: none;
    }

    .ob-item-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .ob-submit-btn {
        width: 100%;
        padding: 13px;
        border: none;
        border-radius: 10px;
        background: var(--ocean);
        color: #fff;
        font-size: 14px;
        font-weight: 800;
        cursor: pointer;
        margin-top: 8px;
    }

    .ob-alert-error {
        background: #FEE2E2;
        color: var(--danger);
        padding: 10px 12px;
        border-radius: 9px;
        font-size: 12.5px;
        margin-bottom: 12px;
    }
</style>

<?php if ($errorMsg): ?>
    <div class="ob-alert-error"><i data-feather="alert-triangle"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>

<form method="POST" id="bookingForm" class="ob-section">
    <div class="ob-form-group">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <label class="ob-form-label" style="margin-bottom:0;">Customer</label>
            <a href="javascript:void(0)" class="ob-toggle-link" onclick="toggleNewCustomer()">+ Tambah Customer Baru</a>
        </div>
        <select name="customer_id" id="customerSelect" class="ob-form-input">
            <option value="">-- Pilih Customer --</option>
            <?php foreach ($customers as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="newCustomerBlock" style="display:none;">
        <div class="ob-form-group">
            <label class="ob-form-label">Nama Customer Baru</label>
            <input type="text" name="new_customer_name" class="ob-form-input" placeholder="Nama lengkap">
        </div>
        <div class="ob-item-grid">
            <div class="ob-form-group">
                <label class="ob-form-label">No. HP / WA</label>
                <input type="text" name="new_customer_phone" class="ob-form-input" placeholder="08xxxx">
            </div>
            <div class="ob-form-group">
                <label class="ob-form-label">Email (opsional)</label>
                <input type="email" name="new_customer_email" class="ob-form-input" placeholder="email@...">
            </div>
        </div>
    </div>

    <div class="ob-form-group">
        <label class="ob-form-label">Paket Wisata (opsional)</label>
        <select name="package_id" class="ob-form-input">
            <option value="">-- Tanpa Paket (Ecer) --</option>
            <?php foreach ($packages as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="ob-item-grid">
        <div class="ob-form-group">
            <label class="ob-form-label">Tanggal Mulai</label>
            <input type="date" name="start_date" class="ob-form-input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="ob-form-group">
            <label class="ob-form-label">Tanggal Selesai</label>
            <input type="date" name="end_date" class="ob-form-input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
    </div>
    <div class="ob-form-group">
        <label class="ob-form-label">Jumlah Pax</label>
        <input type="number" name="pax_count" class="ob-form-input" value="1" min="1">
    </div>
    <div class="ob-form-group">
        <label class="ob-form-label">Catatan</label>
        <textarea name="notes" class="ob-form-input"></textarea>
    </div>

    <div style="font-size:11px;color:var(--muted);margin-bottom:8px;">
        Reservasi akan dibuat dengan status <strong>Pending</strong> dan langsung tersinkron dengan sistem utama — item &amp; harga detail bisa dilengkapi lagi lewat menu Reservasi di sistem utama.
    </div>

    <button type="submit" class="ob-submit-btn"><i data-feather="check"></i> Simpan Reservasi</button>
</form>

<script>
    function toggleNewCustomer() {
        var block = document.getElementById('newCustomerBlock');
        var showing = block.style.display !== 'none';
        block.style.display = showing ? 'none' : '';
        document.getElementById('customerSelect').required = showing;
    }

    if (window.feather) feather.replace();
</script>

<?php include 'owner-mobile-footer.php'; ?>