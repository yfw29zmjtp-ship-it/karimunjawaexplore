<?php

/**
 * Sunsea - Owner mobile view: Buat Invoice Baru (dari reservasi, atau manual + customer baru)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) { header('Location: owner-login.php'); exit; }
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getSunseaConnection();
sunseaEnsureBookingSchema($pdo);
sunseaEnsureFinanceSchema($pdo);
$username = $currentUser['username'] ?? 'system';

function ownerCreateCustomer(PDO $pdo, string $name, string $phone, string $email): int
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

/** Reuse or create an invoice from a booking's items (mirrors bookings.php ensureInvoiceFromBooking). */
function ownerEnsureInvoiceFromBooking(PDO $pdo, string $username, array $booking): int
{
    $internalRef = 'booking_id:' . (int)$booking['id'];
    $invStmt = $pdo->prepare("SELECT id FROM invoices WHERE internal_notes=? ORDER BY id DESC LIMIT 1");
    $invStmt->execute([$internalRef]);
    $invoiceId = (int)($invStmt->fetchColumn() ?: 0);
    if ($invoiceId > 0) {
        return $invoiceId;
    }

    $itemsStmt = $pdo->prepare("SELECT component_name, qty, unit, price_sell, total_sell FROM booking_order_items WHERE booking_id=? ORDER BY sort_order");
    $itemsStmt->execute([(int)$booking['id']]);
    $bookingItems = $itemsStmt->fetchAll();
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

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'manual';

    try {
        if ($mode === 'from_booking') {
            $bookingId = (int)($_POST['booking_id'] ?? 0);
            if ($bookingId <= 0) {
                throw new RuntimeException('Pilih reservasi tamu terlebih dahulu.');
            }
            $bStmt = $pdo->prepare("SELECT id, booking_no, customer_id, start_date, end_date, pax_count FROM booking_orders WHERE id=?");
            $bStmt->execute([$bookingId]);
            $booking = $bStmt->fetch();
            if (!$booking) {
                throw new RuntimeException('Reservasi tidak ditemukan.');
            }
            $invoiceId = ownerEnsureInvoiceFromBooking($pdo, $username, $booking);
            header('Location: owner-invoice-detail.php?id=' . $invoiceId);
            exit;
        }

        // Manual mode
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $newName = trim($_POST['new_customer_name'] ?? '');
        if ($customerId <= 0 && $newName !== '') {
            $customerId = ownerCreateCustomer(
                $pdo,
                $newName,
                trim($_POST['new_customer_phone'] ?? ''),
                trim($_POST['new_customer_email'] ?? '')
            );
        }
        if ($customerId <= 0) {
            throw new RuntimeException('Pilih customer atau isi data customer baru.');
        }

        $descriptions = $_POST['item_description'] ?? [];
        $qtys   = $_POST['item_qty'] ?? [];
        $units  = $_POST['item_unit'] ?? [];
        $prices = $_POST['item_price'] ?? [];

        $subtotal = 0.0;
        $items = [];
        foreach ($descriptions as $i => $desc) {
            $desc = trim($desc);
            if ($desc === '') {
                continue;
            }
            $qty = max(0, (float)($qtys[$i] ?? 0));
            $price = (float)str_replace(['.', ','], ['', '.'], $prices[$i] ?? '0');
            $sub = $qty * $price;
            $subtotal += $sub;
            $items[] = ['description' => $desc, 'qty' => $qty, 'unit' => trim($units[$i] ?? 'pax') ?: 'pax', 'unit_price' => $price, 'subtotal' => $sub];
        }
        if (empty($items)) {
            throw new RuntimeException('Isi minimal satu item tagihan.');
        }

        $taxPct = (float)($_POST['tax_pct'] ?? 0);
        $discount = (float)str_replace(['.', ','], ['', '.'], $_POST['discount_amount'] ?? '0');
        $tax = round(($subtotal - $discount) * $taxPct / 100, 2);
        $total = $subtotal + $tax - $discount;
        $dueDate = $_POST['due_date'] ?: date('Y-m-d', strtotime('+14 days'));
        $invoiceDate = $_POST['invoice_date'] ?: date('Y-m-d');
        $tripDate = $_POST['trip_date'] ?: null;
        $tripEnd = $_POST['trip_end_date'] ?: null;
        $paxCount = max(1, (int)($_POST['pax_count'] ?? 1));
        $notes = trim($_POST['notes'] ?? '');

        $invoiceNo = sunseaNextNumber($pdo, 'invoice');
        $pdo->prepare("INSERT INTO invoices
            (invoice_no, customer_id, trip_date, trip_end_date, pax_count,
             subtotal, tax_pct, tax_amount, discount_amount, total_amount,
             remaining_amount, due_date, notes, status, issued_at, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'issued',?,?)")
            ->execute([$invoiceNo, $customerId, $tripDate, $tripEnd, $paxCount, $subtotal, $taxPct, $tax, $discount, $total, $total, $dueDate, $notes, $invoiceDate, $username]);
        $invoiceId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO invoice_items (invoice_id,item_type,description,qty,unit,unit_price,subtotal,sort_order) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($items as $idx => $item) {
            $ins->execute([$invoiceId, 'other', $item['description'], $item['qty'], $item['unit'], $item['unit_price'], $item['subtotal'], $idx]);
        }

        header('Location: owner-invoice-detail.php?id=' . $invoiceId);
        exit;
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

$customers = $pdo->query("SELECT id, name, phone FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
$bookings = $pdo->query("
    SELECT b.id, b.booking_no, b.status, c.name AS customer_name
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    ORDER BY b.created_at DESC
    LIMIT 100
")->fetchAll();

$pageTitle = 'Buat Invoice Baru';
$backUrl = 'owner-invoices.php';
include 'owner-mobile-header.php';
?>

<style>
    .ob-form-group { margin-bottom:12px; }
    .ob-form-label { display:block; font-size:11.5px; font-weight:700; color:var(--text); margin-bottom:5px; }
    .ob-form-input {
        width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:9px;
        font-size:13px; background:#fff; color:var(--text); font-family:inherit;
    }
    textarea.ob-form-input { resize:vertical; min-height:60px; }
    .ob-mode-switch { display:flex; gap:8px; margin-bottom:14px; }
    .ob-mode-btn {
        flex:1; text-align:center; padding:10px; border-radius:10px; border:1.5px solid var(--border);
        background:#fff; color:var(--muted); font-size:12.5px; font-weight:700; cursor:pointer;
    }
    .ob-mode-btn.active { background:var(--ocean); border-color:var(--ocean); color:#fff; }
    .ob-toggle-link { font-size:11.5px; color:var(--ocean); font-weight:700; text-decoration:none; }
    .ob-item-block { border:1px solid var(--border); border-radius:10px; padding:10px; margin-bottom:8px; position:relative; }
    .ob-item-remove {
        position:absolute; top:8px; right:8px; background:none; border:none; color:var(--danger); cursor:pointer;
    }
    .ob-item-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; }
    .ob-add-item-btn {
        width:100%; padding:10px; border:1.5px dashed var(--ocean); border-radius:9px; background:none;
        color:var(--ocean); font-size:12.5px; font-weight:700; cursor:pointer;
    }
    .ob-submit-btn {
        width:100%; padding:13px; border:none; border-radius:10px; background:var(--ocean); color:#fff;
        font-size:14px; font-weight:800; cursor:pointer; margin-top:8px;
    }
    .ob-alert-error { background:#FEE2E2; color:var(--danger); padding:10px 12px; border-radius:9px; font-size:12.5px; margin-bottom:12px; }
</style>

<?php if ($errorMsg): ?>
    <div class="ob-alert-error"><i data-feather="alert-triangle"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>

<div class="ob-mode-switch">
    <div class="ob-mode-btn active" id="btnModeManual" onclick="setMode('manual')">Manual / Customer Baru</div>
    <div class="ob-mode-btn" id="btnModeBooking" onclick="setMode('from_booking')">Dari Reservasi</div>
</div>

<form method="POST" id="invoiceForm">
    <input type="hidden" name="mode" id="modeInput" value="manual">

    <div id="bookingSection" style="display:none;" class="ob-section">
        <div class="ob-form-group">
            <label class="ob-form-label">Pilih Reservasi Tamu</label>
            <select name="booking_id" class="ob-form-input">
                <option value="">-- Pilih Reservasi --</option>
                <?php foreach ($bookings as $b): ?>
                    <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['booking_no'] . ' - ' . $b['customer_name'] . ' (' . ucfirst($b['status']) . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="font-size:11px;color:var(--muted);">Invoice akan dibuat otomatis dari daftar layanan/item reservasi yang dipilih.</div>
    </div>

    <div id="manualSection" class="ob-section">
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

        <div class="ob-item-grid">
            <div class="ob-form-group">
                <label class="ob-form-label">Tanggal Invoice</label>
                <input type="date" name="invoice_date" class="ob-form-input" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="ob-form-group">
                <label class="ob-form-label">Jatuh Tempo</label>
                <input type="date" name="due_date" class="ob-form-input" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>">
            </div>
            <div class="ob-form-group">
                <label class="ob-form-label">Tanggal Trip</label>
                <input type="date" name="trip_date" class="ob-form-input">
            </div>
            <div class="ob-form-group">
                <label class="ob-form-label">Trip Selesai</label>
                <input type="date" name="trip_end_date" class="ob-form-input">
            </div>
            <div class="ob-form-group">
                <label class="ob-form-label">Peserta</label>
                <input type="number" name="pax_count" class="ob-form-input" value="1" min="1">
            </div>
            <div class="ob-form-group">
                <label class="ob-form-label">PPN (%)</label>
                <input type="number" name="tax_pct" class="ob-form-input" value="0" min="0" step="0.1">
            </div>
        </div>
        <div class="ob-form-group">
            <label class="ob-form-label">Diskon (Rp)</label>
            <input type="text" name="discount_amount" class="ob-form-input" value="0">
        </div>
        <div class="ob-form-group">
            <label class="ob-form-label">Catatan</label>
            <textarea name="notes" class="ob-form-input"></textarea>
        </div>

        <div class="ob-form-label">Item Tagihan</div>
        <div id="itemsWrap"></div>
        <button type="button" class="ob-add-item-btn" onclick="addItemRow()"><i data-feather="plus"></i> Tambah Item</button>
    </div>

    <button type="submit" class="ob-submit-btn"><i data-feather="check"></i> Simpan Invoice</button>
</form>

<script>
function setMode(mode) {
    document.getElementById('modeInput').value = mode;
    document.getElementById('bookingSection').style.display = mode === 'from_booking' ? '' : 'none';
    document.getElementById('manualSection').style.display = mode === 'from_booking' ? 'none' : '';
    document.getElementById('btnModeManual').classList.toggle('active', mode !== 'from_booking');
    document.getElementById('btnModeBooking').classList.toggle('active', mode === 'from_booking');
}
function toggleNewCustomer() {
    var block = document.getElementById('newCustomerBlock');
    var showing = block.style.display !== 'none';
    block.style.display = showing ? 'none' : '';
    document.getElementById('customerSelect').required = showing;
}
function addItemRow() {
    var wrap = document.getElementById('itemsWrap');
    var div = document.createElement('div');
    div.className = 'ob-item-block';
    div.innerHTML = '<button type="button" class="ob-item-remove" onclick="this.parentElement.remove()"><i data-feather="x"></i></button>' +
        '<input type="text" name="item_description[]" class="ob-form-input" placeholder="Keterangan item...">' +
        '<div class="ob-item-grid">' +
        '<input type="number" name="item_qty[]" class="ob-form-input" placeholder="Qty" value="1" min="0" step="0.5">' +
        '<input type="text" name="item_unit[]" class="ob-form-input" placeholder="Satuan" value="pax">' +
        '<input type="text" name="item_price[]" class="ob-form-input" placeholder="Harga satuan" style="grid-column:1/3;">' +
        '</div>';
    wrap.appendChild(div);
    if (window.feather) feather.replace();
}
addItemRow();
</script>

<?php include 'owner-mobile-footer.php'; ?>
