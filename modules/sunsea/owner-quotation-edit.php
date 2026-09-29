<?php

/**
 * Sunsea - Owner mobile view: Edit Penawaran (harga jual, fasilitas/item, penginapan, itinerary)
 * Menulis langsung ke tabel quotations/quotation_items yang sama dipakai system utama
 * (quotations.php), jadi otomatis sinkron - tidak ada proses sync terpisah.
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

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT q.*, c.name AS customer_name FROM quotations q JOIN customers c ON c.id = q.customer_id WHERE q.id = ?");
$stmt->execute([$id]);
$quotation = $stmt->fetch();

if (!$quotation) {
    header('Location: owner-dashboard.php');
    exit;
}

if ($quotation['status'] === 'converted') {
    header('Location: owner-quotation-detail.php?id=' . $id);
    exit;
}

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itinerary           = trim($_POST['itinerary'] ?? '');
    $accommodationManual = trim($_POST['accommodation_manual'] ?? '');
    $descriptions = $_POST['item_description'] ?? [];
    $qtys         = $_POST['item_qty']         ?? [];
    $units        = $_POST['item_unit']        ?? [];
    $prices       = $_POST['item_price']       ?? [];
    $types        = $_POST['item_type']        ?? [];
    $allowedTypes = ['accommodation', 'transport', 'meal', 'activity', 'guide', 'equipment', 'other'];

    $subtotal = 0;
    $items    = [];
    foreach ($descriptions as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '') continue;
        $qty   = max(0, (float)str_replace(',', '.', $qtys[$i] ?? '0'));
        $price = (float)str_replace(['.', ','], ['', '.'], $prices[$i] ?? '0');
        $sub   = $qty * $price;
        $subtotal += $sub;
        $type  = $types[$i] ?? 'other';
        $items[] = [
            'item_type'   => in_array($type, $allowedTypes, true) ? $type : 'other',
            'description' => $desc,
            'qty'         => $qty,
            'unit'        => trim($units[$i] ?? 'pax') ?: 'pax',
            'unit_price'  => $price,
            'subtotal'    => $sub,
        ];
    }

    if (empty($items)) {
        $errorMsg = 'Minimal harus ada 1 item fasilitas dengan keterangan.';
    } else {
        $taxPct    = (float)$quotation['tax_pct'];
        $discount  = (float)$quotation['discount_amount'];
        $taxAmount = round($subtotal * $taxPct / 100, 2);
        $total     = $subtotal + $taxAmount - $discount;

        $pdo->prepare("
            UPDATE quotations SET itinerary=?, accommodation_manual=?, subtotal=?, tax_amount=?, total_amount=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$itinerary, $accommodationManual, $subtotal, $taxAmount, $total, $id]);

        // Sinkron ke booking yang sudah terbentuk (kalau Penawaran ini sudah pernah di-approve).
        $pdo->prepare("UPDATE booking_orders SET accommodation_manual=? WHERE quotation_id=?")
            ->execute([$accommodationManual, $id]);

        $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id=?")->execute([$id]);
        $insItem = $pdo->prepare("
            INSERT INTO quotation_items (quotation_id, item_type, description, qty, unit, unit_price, subtotal, sort_order)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        foreach ($items as $idx => $item) {
            $insItem->execute([
                $id,
                $item['item_type'],
                $item['description'],
                $item['qty'],
                $item['unit'],
                $item['unit_price'],
                $item['subtotal'],
                $idx,
            ]);
        }

        header('Location: owner-quotation-detail.php?id=' . $id);
        exit;
    }
}

$qItems = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY sort_order, id");
$qItems->execute([$id]);
$qItems = $qItems->fetchAll();
if (empty($qItems)) {
    $qItems = [['item_type' => 'other', 'description' => '', 'qty' => 1, 'unit' => 'pax', 'unit_price' => 0]];
}

function obSafeAll(PDO $pdo, string $sql): array
{
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

sunseaEnsureMasterDataSchema($pdo);
sunseaEnsureAccommodationSchema($pdo);
$mdTickets    = obSafeAll($pdo, "SELECT id, ticket_name, ticket_type, price_sell, unit FROM tickets WHERE is_active=1 ORDER BY ticket_type, ticket_name");
$mdTransport  = obSafeAll($pdo, "SELECT id, name, transport_type, price_sell, unit FROM transport_items WHERE is_active=1 ORDER BY transport_type, name");
$mdRooms      = obSafeAll($pdo, "SELECT r.id, r.room_type, r.price_sell, p.name as partner_name FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id WHERE r.is_active=1 AND p.is_active=1 ORDER BY p.name, r.room_type");
$mdCaterings  = obSafeAll($pdo, "SELECT id, menu_name, vendor_name, price_sell, portion_unit FROM caterings WHERE is_active=1 ORDER BY vendor_name, menu_name");
$mdFacilities = obSafeAll($pdo, "SELECT id, name, price_sell, unit FROM facilities WHERE is_active=1 ORDER BY name");
$quickAddGroups = [
    ['label' => 'Tiket & Retribusi (PP kapal/ferry/tiket masuk)', 'type' => 'other', 'options' => $mdTickets, 'nameField' => 'ticket_name', 'extra' => 'ticket_type', 'unitField' => 'unit'],
    ['label' => 'Transportasi', 'type' => 'transport', 'options' => $mdTransport, 'nameField' => 'name', 'extra' => 'transport_type', 'unitField' => 'unit'],
    ['label' => 'Penginapan', 'type' => 'accommodation', 'options' => $mdRooms, 'nameField' => 'room_type', 'extra' => 'partner_name', 'unitField' => null],
    ['label' => 'Makanan / Catering', 'type' => 'meal', 'options' => $mdCaterings, 'nameField' => 'menu_name', 'extra' => 'vendor_name', 'unitField' => 'portion_unit'],
    ['label' => 'Fasilitas Tambahan', 'type' => 'equipment', 'options' => $mdFacilities, 'nameField' => 'name', 'extra' => null, 'unitField' => 'unit'],
];
$hasAnyMasterData = false;
foreach ($quickAddGroups as $g) {
    if (!empty($g['options'])) {
        $hasAnyMasterData = true;
        break;
    }
}

$pageTitle = 'Edit Penawaran';
$backUrl   = 'owner-quotation-detail.php?id=' . $id;
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

    .ob-form-input,
    .ob-form-textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 9px;
        font-size: 13px;
        background: #fff;
        color: var(--text);
        font-family: inherit;
    }

    .ob-form-textarea {
        resize: vertical;
        min-height: 70px;
    }

    .ob-item-card {
        background: var(--sky);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px;
        margin-bottom: 8px;
        position: relative;
    }

    .ob-item-card-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-top: 8px;
    }

    .ob-item-remove {
        position: absolute;
        top: 8px;
        right: 8px;
        background: none;
        border: none;
        color: var(--danger);
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
    }

    .ob-add-item-btn {
        width: 100%;
        padding: 10px;
        border-radius: 9px;
        border: 1.5px dashed var(--ocean);
        background: #fff;
        color: var(--ocean);
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
        margin-bottom: 10px;
    }

    .ob-quickadd-box {
        background: var(--sky);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px;
        margin-bottom: 12px;
    }

    .ob-quickadd-title {
        font-size: 10px;
        font-weight: 700;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .3px;
        margin-bottom: 8px;
    }

    .ob-quickadd-row {
        display: flex;
        gap: 6px;
    }

    .ob-quickadd-row select {
        flex: 1;
        min-width: 0;
    }

    .ob-quickadd-row input {
        width: 56px;
        flex-shrink: 0;
    }

    .ob-quickadd-btn {
        flex-shrink: 0;
        padding: 0 12px;
        border: none;
        border-radius: 9px;
        background: var(--ocean);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }

    .ob-total-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 13px;
        font-weight: 800;
        padding: 10px 12px;
        background: var(--sky);
        border-radius: 9px;
        margin-top: 10px;
    }

    .ob-save-btn {
        width: 100%;
        padding: 13px;
        border: none;
        border-radius: 10px;
        background: var(--ocean);
        color: #fff;
        font-size: 13.5px;
        font-weight: 800;
        cursor: pointer;
        margin-top: 14px;
    }
</style>

<form method="POST" id="obQuotationEditForm">
    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

    <?php if ($errorMsg): ?>
        <div style="background:#FEE2E2;color:var(--danger);padding:10px 12px;border-radius:9px;font-size:12px;margin-bottom:12px;font-weight:600;">
            <?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <div class="ob-section">
        <div class="ob-section-head">
            <div class="ob-section-title"><i data-feather="tag"></i> Harga Jual &amp; Fasilitas</div>
        </div>

        <?php if ($hasAnyMasterData): ?>
            <div class="ob-quickadd-box">
                <div class="ob-quickadd-title">Tambah Fasilitas dari Database</div>
                <div class="ob-quickadd-row">
                    <select class="ob-form-input" id="obQaSelect">
                        <option value="">-- Pilih item (tiket, transport, penginapan, dll) --</option>
                        <?php foreach ($quickAddGroups as $g):
                            if (empty($g['options'])) continue;
                        ?>
                            <optgroup label="<?php echo htmlspecialchars($g['label']); ?>">
                                <?php foreach ($g['options'] as $o):
                                    $name  = $o[$g['nameField']];
                                    $extra = $g['extra'] ? ' - ' . $o[$g['extra']] : '';
                                    $unit  = $g['unitField'] ? ($o[$g['unitField']] ?: 'pax') : 'pax';
                                ?>
                                    <option value="<?php echo $o['id']; ?>"
                                        data-type="<?php echo $g['type']; ?>"
                                        data-name="<?php echo htmlspecialchars($name . $extra); ?>"
                                        data-price="<?php echo (float)$o['price_sell']; ?>"
                                        data-unit="<?php echo htmlspecialchars($unit); ?>">
                                        <?php echo htmlspecialchars($name . $extra); ?> (<?php echo sunseaRupiah((float)$o['price_sell']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ob-quickadd-row" style="margin-top:6px;">
                    <input type="number" class="ob-form-input" id="obQaQty" value="1" min="0" step="1" placeholder="Qty">
                    <button type="button" class="ob-quickadd-btn" style="flex:1;" onclick="obQuickAddItem()">+ Tambahkan ke Daftar</button>
                </div>
            </div>
        <?php else: ?>
            <div style="font-size:11px;color:var(--muted);margin-bottom:10px;">Belum ada data master (Tiket/Transport/Penginapan/Catering/Fasilitas). Isi dulu di menu Database pada sistem utama.</div>
        <?php endif; ?>

        <div id="obItemsWrap">
            <?php foreach ($qItems as $it): ?>
                <div class="ob-item-card">
                    <button type="button" class="ob-item-remove" onclick="obRemoveItem(this)">Hapus</button>
                    <input type="hidden" name="item_type[]" value="<?php echo htmlspecialchars($it['item_type'] ?? 'other'); ?>">
                    <input type="text" class="ob-form-input" name="item_description[]" placeholder="Nama fasilitas / item"
                        value="<?php echo htmlspecialchars($it['description']); ?>" required>
                    <div class="ob-item-card-grid">
                        <input type="number" step="0.01" min="0" class="ob-form-input obq-qty" name="item_qty[]" placeholder="Qty"
                            value="<?php echo (float)$it['qty'] == (int)$it['qty'] ? (int)$it['qty'] : (float)$it['qty']; ?>">
                        <input type="text" class="ob-form-input" name="item_unit[]" placeholder="Satuan (pax/paket/hari)"
                            value="<?php echo htmlspecialchars($it['unit']); ?>">
                    </div>
                    <div class="ob-item-card-grid" style="grid-template-columns:1fr;margin-top:8px;">
                        <input type="text" class="ob-form-input obq-price" name="item_price[]" placeholder="Harga Jual per satuan (Rp)"
                            value="<?php echo (float)$it['unit_price']; ?>" inputmode="decimal">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="ob-add-item-btn" onclick="obAddItem()">+ Tambah Baris Manual</button>
        <div class="ob-total-bar">
            <span>Total Penawaran</span>
            <span id="obTotalPreview">-</span>
        </div>
    </div>

    <div class="ob-section">
        <div class="ob-section-head">
            <div class="ob-section-title"><i data-feather="home"></i> Penginapan</div>
        </div>
        <div class="ob-form-group">
            <label class="ob-form-label">Penginapan yang Dipilih</label>
            <input type="text" class="ob-form-input" name="accommodation_manual" placeholder="Contoh: Homestay Pak Budi"
                value="<?php echo htmlspecialchars($quotation['accommodation_manual'] ?? ''); ?>">
        </div>
    </div>

    <div class="ob-section">
        <div class="ob-section-head">
            <div class="ob-section-title"><i data-feather="map"></i> Itinerary</div>
        </div>
        <div class="ob-form-group">
            <textarea class="ob-form-textarea" name="itinerary" placeholder="Rincian perjalanan hari per hari..."><?php echo htmlspecialchars($quotation['itinerary'] ?? ''); ?></textarea>
        </div>
    </div>

    <button type="submit" class="ob-save-btn">Simpan Perubahan</button>
</form>

<script>
    var obTaxPct = <?php echo (float)$quotation['tax_pct']; ?>;
    var obDiscount = <?php echo (float)$quotation['discount_amount']; ?>;

    function obFmtRupiah(n) {
        return 'Rp ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function obRecalcTotal() {
        var qtys = document.querySelectorAll('.obq-qty');
        var prices = document.querySelectorAll('.obq-price');
        var subtotal = 0;
        for (var i = 0; i < qtys.length; i++) {
            var q = parseFloat(qtys[i].value) || 0;
            var p = parseFloat((prices[i].value || '0').replace(/[^0-9.-]/g, '')) || 0;
            subtotal += q * p;
        }
        var tax = subtotal * obTaxPct / 100;
        var total = subtotal + tax - obDiscount;
        document.getElementById('obTotalPreview').textContent = obFmtRupiah(total);
    }

    function obRemoveItem(btn) {
        var wrap = document.getElementById('obItemsWrap');
        if (wrap.children.length <= 1) return;
        btn.closest('.ob-item-card').remove();
        obRecalcTotal();
    }

    function obMakeItemCard(desc, qty, unit, price, type) {
        var card = document.createElement('div');
        card.className = 'ob-item-card';
        card.innerHTML = '<button type="button" class="ob-item-remove" onclick="obRemoveItem(this)">Hapus</button>' +
            '<input type="hidden" name="item_type[]" value="' + type + '">' +
            '<input type="text" class="ob-form-input" name="item_description[]" placeholder="Nama fasilitas / item" value="' + desc.replace(/"/g, '&quot;') + '">' +
            '<div class="ob-item-card-grid">' +
            '<input type="number" step="0.01" min="0" class="ob-form-input obq-qty" name="item_qty[]" placeholder="Qty" value="' + qty + '">' +
            '<input type="text" class="ob-form-input" name="item_unit[]" placeholder="Satuan (pax/paket/hari)" value="' + unit + '">' +
            '</div>' +
            '<div class="ob-item-card-grid" style="grid-template-columns:1fr;margin-top:8px;">' +
            '<input type="text" class="ob-form-input obq-price" name="item_price[]" placeholder="Harga Jual per satuan (Rp)" value="' + price + '">' +
            '</div>';
        return card;
    }

    function obAddItem() {
        var wrap = document.getElementById('obItemsWrap');
        var card = obMakeItemCard('', 1, 'pax', 0, 'other');
        wrap.appendChild(card);
        card.querySelectorAll('.obq-qty, .obq-price').forEach(function(el) {
            el.addEventListener('input', obRecalcTotal);
        });
        obRecalcTotal();
    }

    function obQuickAddItem() {
        var sel = document.getElementById('obQaSelect');
        var opt = sel.options[sel.selectedIndex];
        if (!sel.value) {
            alert('Pilih item dari database terlebih dahulu.');
            return;
        }
        var qty = parseFloat(document.getElementById('obQaQty').value) || 1;
        var name = opt.getAttribute('data-name') || '';
        var price = parseFloat(opt.getAttribute('data-price')) || 0;
        var unit = opt.getAttribute('data-unit') || 'pax';
        var type = opt.getAttribute('data-type') || 'other';

        var wrap = document.getElementById('obItemsWrap');
        var card = obMakeItemCard(name, qty, unit, price, type);
        wrap.appendChild(card);
        card.querySelectorAll('.obq-qty, .obq-price').forEach(function(el) {
            el.addEventListener('input', obRecalcTotal);
        });
        sel.value = '';
        obRecalcTotal();
    }

    document.querySelectorAll('.obq-qty, .obq-price').forEach(function(el) {
        el.addEventListener('input', obRecalcTotal);
    });
    obRecalcTotal();
</script>

<?php include 'owner-mobile-footer.php'; ?>
