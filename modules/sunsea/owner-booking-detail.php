<?php

/**
 * Sunsea - Owner mobile view: Detail Reservasi (read-only)
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

/** Mirrors ensureInvoiceFromBooking() in bookings.php so status confirm behaves the same as the main system. */
function ownerEnsureInvoiceFromBooking(PDO $pdo, string $username, array $booking): int
{
    $internalRef = 'booking_id:' . (int)$booking['id'];
    $invStmt = $pdo->prepare("SELECT id FROM invoices WHERE internal_notes=? OR internal_notes=? ORDER BY id DESC LIMIT 1");
    $invStmt->execute([$internalRef, 'Generated from Reservasi: ' . $booking['booking_no']]);
    $invoiceId = (int)($invStmt->fetchColumn() ?: 0);
    if ($invoiceId > 0) {
        return $invoiceId;
    }

    $itemsStmt = $pdo->prepare("SELECT component_name, qty, unit, price_sell, total_sell FROM booking_order_items WHERE booking_id=? AND component_code != 'pkg_detail' ORDER BY sort_order");
    $itemsStmt->execute([(int)$booking['id']]);
    $bookingItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($bookingItems)) {
        throw new RuntimeException('Item reservasi belum tersedia, invoice tidak dapat dibuat.');
    }

    $subtotal = 0.0;
    foreach ($bookingItems as $bi) {
        $subtotal += (float)$bi['total_sell'];
    }
    $dueDate = date('Y-m-d', strtotime('+14 days'));

    $pdo->beginTransaction();
    try {
        $invoiceNo = sunseaNextNumber($pdo, 'invoice');
        $pdo->prepare("INSERT INTO invoices
            (invoice_no, customer_id, trip_date, trip_end_date, pax_count,
             status, subtotal, tax_pct, tax_amount, discount_amount,
             total_amount, paid_amount, remaining_amount, due_date,
             notes, internal_notes, issued_at, created_by)
            VALUES (?,?,?,?,?,'issued',?,0,0,0,?,0,?,?,?,?,NOW(),?)")
            ->execute([
                $invoiceNo,
                (int)$booking['customer_id'],
                $booking['start_date'],
                $booking['end_date'],
                (int)$booking['pax_count'],
                $subtotal,
                $subtotal,
                $subtotal,
                $dueDate,
                'Generated from Reservasi: ' . $booking['booking_no'],
                $internalRef,
                $username,
            ]);
        $invoiceId = (int)$pdo->lastInsertId();

        $insItem = $pdo->prepare("INSERT INTO invoice_items
            (invoice_id, item_type, description, qty, unit, unit_price, subtotal, sort_order)
            VALUES (?,?,?,?,?,?,?,?)");
        foreach ($bookingItems as $idx => $bi) {
            $insItem->execute([$invoiceId, 'other', (string)$bi['component_name'], (float)$bi['qty'], (string)$bi['unit'], (float)$bi['price_sell'], (float)$bi['total_sell'], $idx]);
        }
        $pdo->commit();
        return $invoiceId;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Mirrors recalcBookingTotals() in bookings.php. */
function ownerRecalcBookingTotals(PDO $pdo, int $bookingId): void
{
    $sums = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) AS c, COALESCE(SUM(total_sell),0) AS s FROM booking_order_items WHERE booking_id=?");
    $sums->execute([$bookingId]);
    $row = $sums->fetch() ?: ['c' => 0, 's' => 0];
    $costTotal = (float)$row['c'];
    $sellTotal = (float)$row['s'];
    $pdo->prepare("UPDATE booking_orders SET cost_total=?, sell_total=?, margin_amount=?, updated_at=NOW() WHERE id=?")
        ->execute([$costTotal, $sellTotal, $sellTotal - $costTotal, $bookingId]);
}

$flashMessage = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    $allowed = ['draft', 'confirmed', 'cancelled'];

    if ($bookingId > 0 && in_array($newStatus, $allowed, true)) {
        try {
            $pdo->prepare("UPDATE booking_orders SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $bookingId]);
            $flashMessage = 'Status reservasi berhasil diperbarui.';
            $flashType = 'success';

            // Saat dikonfirmasi, otomatis siapkan invoice-nya juga (sama seperti sistem utama).
            if ($newStatus === 'confirmed') {
                $bStmt = $pdo->prepare("SELECT id, booking_no, customer_id, start_date, end_date, pax_count FROM booking_orders WHERE id=?");
                $bStmt->execute([$bookingId]);
                $bRow = $bStmt->fetch(PDO::FETCH_ASSOC);
                if ($bRow) {
                    try {
                        ownerEnsureInvoiceFromBooking($pdo, $username, $bRow);
                        $flashMessage = 'Status dikonfirmasi, masuk kalender booking, dan invoice otomatis dibuat.';
                    } catch (Exception $e) {
                        $flashMessage = 'Status dikonfirmasi, tapi invoice gagal dibuat otomatis: ' . $e->getMessage();
                        $flashType = 'error';
                    }
                }
            }
        } catch (Exception $e) {
            $flashMessage = 'Gagal update status: ' . $e->getMessage();
            $flashType = 'error';
        }
    }
    header('Location: owner-booking-detail.php?id=' . $bookingId . '&msg=' . urlencode($flashMessage) . '&msgtype=' . $flashType);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_prices') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $itemIds = $_POST['item_id'] ?? [];
    $priceSells = $_POST['price_sell'] ?? [];

    if ($bookingId > 0 && is_array($itemIds)) {
        try {
            $upd = $pdo->prepare("UPDATE booking_order_items SET price_sell=?, total_sell=(qty*?) WHERE id=? AND booking_id=?");
            foreach ($itemIds as $idx => $itemId) {
                $itemId = (int)$itemId;
                if ($itemId <= 0) continue;
                $newSell = (float)str_replace(['.', ','], ['', '.'], $priceSells[$idx] ?? '0');
                $upd->execute([$newSell, $newSell, $itemId, $bookingId]);
            }
            ownerRecalcBookingTotals($pdo, $bookingId);
            $flashMessage = 'Harga jual berhasil diperbarui.';
            $flashType = 'success';
        } catch (Exception $e) {
            $flashMessage = 'Gagal update harga: ' . $e->getMessage();
            $flashType = 'error';
        }
    }
    header('Location: owner-booking-detail.php?id=' . $bookingId . '&msg=' . urlencode($flashMessage) . '&msgtype=' . $flashType);
    exit;
}

$flashMessage = $_GET['msg'] ?? '';
$flashType = $_GET['msgtype'] ?? '';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT b.*, c.name AS customer_name, c.phone AS customer_phone
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: owner-bookings.php');
    exit;
}

$items = $pdo->prepare("SELECT id, component_name, qty, unit, price_sell, total_sell FROM booking_order_items WHERE booking_id=? ORDER BY sort_order");
$items->execute([$id]);
$items = $items->fetchAll();

$totalSell = 0;
foreach ($items as $it) {
    $totalSell += (float)$it['total_sell'];
}

// Info pembayaran/DP: invoice booking ditautkan lewat internal_notes 'booking_id:<id>'
// (jalur normal) atau 'Generated from Reservasi: <no>' (jalur konversi invoice manual).
// Bisa ada LEBIH DARI SATU invoice tertaut ke booking yang sama (mis. invoice lama +
// invoice duplikat) - jumlahkan semuanya (sama seperti detail booking di system utama),
// jangan ambil satu (LIMIT 1) saja krn bisa kepilih invoice duplikat yang belum dibayar.
$invStmt = $pdo->prepare("SELECT invoice_no, status, total_amount, paid_amount FROM invoices WHERE internal_notes=? OR internal_notes=? ORDER BY id ASC");
$invStmt->execute(['booking_id:' . $id, 'Generated from Reservasi: ' . $booking['booking_no']]);
$bookingInvoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

$linkedInvoice = null;
if ($bookingInvoices) {
    $invoiceNos = array_column($bookingInvoices, 'invoice_no');
    $sumTotal = 0.0;
    $sumPaid = 0.0;
    foreach ($bookingInvoices as $bi) {
        $sumTotal += (float)$bi['total_amount'];
        $sumPaid += (float)$bi['paid_amount'];
    }
    $linkedInvoice = [
        'invoice_no' => implode(', ', $invoiceNos),
        'total_amount' => $sumTotal,
        'paid_amount' => $sumPaid,
    ];
}
if ($linkedInvoice) {
    // Hitung ulang sisa tagihan dari total - terbayar, jangan percaya kolom remaining_amount yang bisa basi.
    $linkedInvoice['remaining_amount'] = max(0, (float)$linkedInvoice['total_amount'] - (float)$linkedInvoice['paid_amount']);
    if ($linkedInvoice['remaining_amount'] <= 0.01) {
        $paymentStatusLabel = 'Lunas';
        $paymentStatusColor = 'var(--success)';
    } elseif ((float)$linkedInvoice['paid_amount'] > 0) {
        $paymentStatusLabel = 'DP';
        $paymentStatusColor = '#C2410C';
    } else {
        $paymentStatusLabel = 'Belum Bayar';
        $paymentStatusColor = 'var(--danger)';
    }
}

$statusBadge = [
    'draft'     => ['ob-badge-draft', 'Pending'],
    'confirmed' => ['ob-badge-confirmed', 'Confirmed'],
    'cancelled' => ['ob-badge-issued', 'Batal'],
];
$badge = $statusBadge[$booking['status']] ?? ['ob-badge-draft', $booking['status']];
$waLink = sunseaWaLink((string)$booking['customer_phone'], 'Halo ' . $booking['customer_name'] . ', mengenai reservasi ' . $booking['booking_no'] . '...');

$pageTitle = 'Detail Reservasi';
$backUrl = 'owner-bookings.php';
include 'owner-mobile-header.php';
?>

<style>
    .ob-status-actions {
        display: flex;
        gap: 8px;
        margin-bottom: 14px;
        flex-wrap: wrap;
    }

    .ob-status-btn {
        flex: 1;
        min-width: 100px;
        text-align: center;
        padding: 9px 10px;
        border-radius: 9px;
        border: none;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }

    .ob-form-input {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 12.5px;
        background: #fff;
        color: var(--text);
        font-family: inherit;
    }

    .ob-alert-success {
        background: #DCFCE7;
        color: #166534;
        padding: 10px 12px;
        border-radius: 9px;
        font-size: 12.5px;
        margin-bottom: 12px;
    }

    .ob-alert-error {
        background: #FEE2E2;
        color: var(--danger);
        padding: 10px 12px;
        border-radius: 9px;
        font-size: 12.5px;
        margin-bottom: 12px;
    }

    .ob-section-link {
        font-size: 11px;
        color: var(--ocean);
        text-decoration: none;
        font-weight: 600;
    }
</style>

<?php if ($flashMessage): ?>
    <div class="<?php echo $flashType === 'error' ? 'ob-alert-error' : 'ob-alert-success'; ?>">
        <?php echo htmlspecialchars($flashMessage); ?>
    </div>
<?php endif; ?>

<div class="ob-status-actions">
    <?php if ($booking['status'] !== 'confirmed'): ?>
        <form method="POST" style="flex:1;margin:0;" onsubmit="return confirm('Konfirmasi reservasi ini? Invoice otomatis akan dibuat dan masuk ke kalender booking.');">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
            <input type="hidden" name="status" value="confirmed">
            <button type="submit" class="ob-status-btn" style="background:var(--success);color:#fff;width:100%;"><i data-feather="check-circle"></i> Confirmed</button>
        </form>
    <?php endif; ?>
    <?php if ($booking['status'] !== 'draft'): ?>
        <form method="POST" style="flex:1;margin:0;" onsubmit="return confirm('Kembalikan status reservasi ke Pending?');">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
            <input type="hidden" name="status" value="draft">
            <button type="submit" class="ob-status-btn" style="background:#F1F5F9;color:var(--text);width:100%;"><i data-feather="clock"></i> Pending</button>
        </form>
    <?php endif; ?>
    <?php if ($booking['status'] !== 'cancelled'): ?>
        <form method="POST" style="flex:1;margin:0;" onsubmit="return confirm('Batalkan reservasi ini?');">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
            <input type="hidden" name="status" value="cancelled">
            <button type="submit" class="ob-status-btn" style="background:#FEE2E2;color:var(--danger);width:100%;"><i data-feather="x-circle"></i> Batal</button>
        </form>
    <?php endif; ?>
</div>

<div class="ob-section">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
        <div>
            <div style="font-size:17px;font-weight:800;"><?php echo htmlspecialchars($booking['customer_name']); ?></div>
            <div style="font-size:11px;color:var(--muted);"><?php echo htmlspecialchars($booking['booking_no']); ?></div>
        </div>
        <span class="ob-badge <?php echo $badge[0]; ?>"><?php echo htmlspecialchars($badge[1]); ?></span>
    </div>

    <?php if ($waLink): ?>
        <a href="<?php echo htmlspecialchars($waLink); ?>" target="_blank" rel="noopener" class="ob-wa-btn" style="font-size:12px;padding:7px 14px;margin-bottom:14px;">
            <i data-feather="message-circle"></i> Chat WhatsApp
        </a>
    <?php endif; ?>

    <div class="ob-detail-label">Tanggal Trip</div>
    <div class="ob-detail-value"><?php echo date('d M Y', strtotime($booking['start_date'])); ?> - <?php echo date('d M Y', strtotime($booking['end_date'])); ?></div>

    <div class="ob-detail-label">Jumlah Pax</div>
    <div class="ob-detail-value"><?php echo (int)$booking['pax_count']; ?> orang</div>

    <?php if ($linkedInvoice): ?>
        <div class="ob-detail-label">Status Pembayaran</div>
        <div class="ob-detail-value">
            <span style="color:<?php echo $paymentStatusColor; ?>;"><?php echo $paymentStatusLabel; ?></span>
            <span style="font-weight:400;color:var(--muted);font-size:11px;">
                · Terbayar <?php echo sunseaRupiah((float)$linkedInvoice['paid_amount']); ?>
                <?php if ($linkedInvoice['remaining_amount'] > 0.01): ?>
                    · Sisa <?php echo sunseaRupiah((float)$linkedInvoice['remaining_amount']); ?>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if (!empty($booking['notes'])): ?>
        <div class="ob-detail-label">Catatan</div>
        <div class="ob-detail-value" style="font-weight:400;"><?php echo nl2br(htmlspecialchars($booking['notes'])); ?></div>
    <?php endif; ?>
</div>

<div class="ob-section">
    <div class="ob-section-head">
        <div class="ob-section-title">Rincian Layanan</div>
        <?php if (!empty($items)): ?>
            <a href="javascript:void(0)" class="ob-section-link" onclick="toggleEditPrice()" id="editPriceToggle">Edit Harga</a>
        <?php endif; ?>
    </div>
    <?php if (empty($items)): ?>
        <div class="ob-empty">Belum ada item layanan.</div>
    <?php else: ?>
        <div id="itemsView">
            <?php foreach ($items as $it): ?>
                <div class="ob-item-row">
                    <div>
                        <?php echo htmlspecialchars($it['component_name']); ?>
                        <div style="color:var(--muted);font-size:10.5px;"><?php echo (float)$it['qty']; ?> <?php echo htmlspecialchars($it['unit']); ?> × <?php echo sunseaRupiah((float)$it['price_sell']); ?></div>
                    </div>
                    <div style="font-weight:700;"><?php echo sunseaRupiah((float)$it['total_sell']); ?></div>
                </div>
            <?php endforeach; ?>
            <div class="ob-item-row" style="border-top:2px solid var(--border);margin-top:4px;padding-top:10px;">
                <div style="font-weight:800;">Total</div>
                <div style="font-weight:800;color:var(--ocean);"><?php echo sunseaRupiah($totalSell); ?></div>
            </div>
        </div>

        <form method="POST" id="editPriceForm" style="display:none;">
            <input type="hidden" name="action" value="update_prices">
            <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
            <?php foreach ($items as $it): ?>
                <div style="margin-bottom:10px;">
                    <label style="display:block;font-size:11px;font-weight:700;margin-bottom:4px;"><?php echo htmlspecialchars($it['component_name']); ?> (<?php echo (float)$it['qty']; ?> <?php echo htmlspecialchars($it['unit']); ?>)</label>
                    <input type="hidden" name="item_id[]" value="<?php echo (int)$it['id']; ?>">
                    <input type="text" name="price_sell[]" class="ob-form-input" value="<?php echo (float)$it['price_sell']; ?>" placeholder="Harga jual per unit">
                </div>
            <?php endforeach; ?>
            <button type="submit" style="width:100%;padding:11px;border:none;border-radius:9px;background:var(--ocean);color:#fff;font-size:13px;font-weight:800;cursor:pointer;"><i data-feather="check"></i> Simpan Perubahan Harga</button>
        </form>
    <?php endif; ?>
</div>

<script>
    function toggleEditPrice() {
        var view = document.getElementById('itemsView');
        var form = document.getElementById('editPriceForm');
        var showingForm = form.style.display !== 'none';
        view.style.display = showingForm ? '' : 'none';
        form.style.display = showingForm ? 'none' : '';
        document.getElementById('editPriceToggle').textContent = showingForm ? 'Edit Harga' : 'Batal Edit';
        if (window.feather) feather.replace();
    }
</script>

<a href="bookings.php?action=print_invoice&id=<?php echo (int)$booking['id']; ?>" target="_blank" class="ob-qbtn" style="width:100%;">
    <i data-feather="printer"></i> Cetak Invoice
</a>

<?php include 'owner-mobile-footer.php'; ?>