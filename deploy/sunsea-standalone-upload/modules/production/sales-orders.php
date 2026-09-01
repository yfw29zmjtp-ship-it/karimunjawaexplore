<?php
/**
 * Production Module - Sales Orders
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

try {
    $pdo = getProductionPdo();
} catch (Exception $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

$action  = $_GET['action'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);
$error   = '';
$success = '';

$statusList = ['quotation'=>'Penawaran','confirmed'=>'Dikonfirmasi','in_production'=>'Produksi','ready'=>'Siap Kirim','delivered'=>'Terkirim','cancelled'=>'Dibatalkan'];
$statusBadge = ['quotation'=>'secondary','confirmed'=>'info','in_production'=>'primary','ready'=>'warning','delivered'=>'success','cancelled'=>'danger'];

// ============================
// HANDLE POST
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa = $_POST['form_action'] ?? '';

    if ($fa === 'add' || $fa === 'edit') {
        $customer  = trim($_POST['customer_name'] ?? '');
        $contact   = trim($_POST['customer_contact'] ?? '');
        $address   = trim($_POST['customer_address'] ?? '');
        $status    = $_POST['status'] ?? 'quotation';
        $notes     = trim($_POST['notes'] ?? '');
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $delivDate = $_POST['delivery_date'] ?? null;
        $dpAmount  = (float)str_replace('.','',($_POST['dp_amount'] ?? 0));
        $dpPaid    = isset($_POST['dp_paid']) ? 1 : 0;

        // Items
        $itemNames  = $_POST['item_name'] ?? [];
        $itemDescs  = $_POST['item_desc'] ?? [];
        $itemQtys   = $_POST['item_qty'] ?? [];
        $itemUnits  = $_POST['item_unit'] ?? [];
        $itemPrices = $_POST['item_price'] ?? [];

        if (empty($customer)) {
            $error = 'Nama customer wajib diisi.';
        } else {
            try {
                $pdo->beginTransaction();
                $total = 0;
                $items = [];
                foreach ($itemNames as $i => $n) {
                    $n = trim($n);
                    if (empty($n)) continue;
                    $qty = (float)($itemQtys[$i] ?? 1);
                    $price = (float)str_replace('.','',($itemPrices[$i] ?? 0));
                    $sub = $qty * $price;
                    $total += $sub;
                    $items[] = [trim($n), trim($itemDescs[$i]??''), $qty, trim($itemUnits[$i]??'pcs'), $price, $sub];
                }

                if ($fa === 'add') {
                    $orderNumber = genOrderNumber($pdo, 'SO');
                    $pdo->prepare("INSERT INTO sales_orders (order_number,customer_name,customer_contact,customer_address,total_amount,dp_amount,dp_paid,status,notes,order_date,delivery_date,created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$orderNumber,$customer,$contact,$address,$total,$dpAmount,$dpPaid,$status,$notes,$orderDate,$delivDate?:null,$_SESSION['user_id']??null]);
                    $soId = $pdo->lastInsertId();
                    $success = "Sales Order {$orderNumber} berhasil dibuat.";
                } else {
                    $soId = $id;
                    $pdo->prepare("UPDATE sales_orders SET customer_name=?,customer_contact=?,customer_address=?,total_amount=?,dp_amount=?,dp_paid=?,status=?,notes=?,order_date=?,delivery_date=?,updated_at=NOW() WHERE id=?")
                        ->execute([$customer,$contact,$address,$total,$dpAmount,$dpPaid,$status,$notes,$orderDate,$delivDate?:null,$soId]);
                    $pdo->prepare("DELETE FROM sales_order_items WHERE order_id=?")->execute([$soId]);
                    $success = 'Sales Order berhasil diperbarui.';
                }

                $itemStmt = $pdo->prepare("INSERT INTO sales_order_items (order_id,product_name,description,quantity,unit,unit_price,subtotal) VALUES (?,?,?,?,?,?,?)");
                foreach ($items as $item) {
                    $itemStmt->execute([$soId, ...$item]);
                }

                $pdo->commit();
                $action = 'list';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    }

    if ($fa === 'delete') {
        try {
            $pdo->prepare("DELETE FROM sales_orders WHERE id=?")->execute([$id]);
            $success = 'Sales Order dihapus.';
        } catch (Exception $e) {
            $error = 'Gagal menghapus.';
        }
        $action = 'list';
    }
}

// ============================
// DATA
// ============================
$orders = [];
$filterStatus = $_GET['status'] ?? '';
if ($action === 'list') {
    $where = $filterStatus ? "WHERE so.status = ?" : "";
    $params = $filterStatus ? [$filterStatus] : [];
    $stmt = $pdo->prepare("SELECT so.*, COUNT(soi.id) as item_count FROM sales_orders so LEFT JOIN sales_order_items soi ON so.id=soi.order_id $where GROUP BY so.id ORDER BY so.created_at DESC");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
}

$viewOrder = null;
$viewItems = [];
if ($action === 'view' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $stmt->execute([$id]);
    $viewOrder = $stmt->fetch();
    $stmtI = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id=? ORDER BY id");
    $stmtI->execute([$id]);
    $viewItems = $stmtI->fetchAll();
}

$editOrder = null;
$editItems = [];
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?");
    $stmt->execute([$id]);
    $editOrder = $stmt->fetch();
    $stmtI = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id=? ORDER BY id");
    $stmtI->execute([$id]);
    $editItems = $stmtI->fetchAll();
}

include '../../includes/header.php';
?>

<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-1"><i class="bi bi-bag-check me-2 text-success"></i>Sales Order</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="dashboard.php">Produksi</a></li>
                <li class="breadcrumb-item active">Sales Order</li>
            </ol></nav>
        </div>
        <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Order Baru</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<?php if ($action === 'view' && $viewOrder): ?>
<!-- VIEW DETAIL -->
<div class="content-card" style="max-width:750px">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h5 class="mb-1"><?= htmlspecialchars($viewOrder['order_number']) ?></h5>
            <span class="badge bg-<?= $statusBadge[$viewOrder['status']]??'secondary' ?> fs-6"><?= $statusList[$viewOrder['status']]??$viewOrder['status'] ?></span>
        </div>
        <div class="d-flex gap-2">
            <a href="?action=edit&id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</a>
            <a href="sales-orders.php" class="btn btn-sm btn-outline-secondary">Kembali</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="p-3 bg-light rounded"><strong>Customer</strong><br><?= htmlspecialchars($viewOrder['customer_name']) ?><?php if ($viewOrder['customer_contact']): ?><br><small class="text-muted"><?= htmlspecialchars($viewOrder['customer_contact']) ?></small><?php endif; ?></div></div>
        <div class="col-md-3"><div class="p-3 bg-light rounded"><strong>Tanggal Order</strong><br><?= date('d/m/Y', strtotime($viewOrder['order_date'])) ?></div></div>
        <div class="col-md-3"><div class="p-3 bg-light rounded"><strong>Target Kirim</strong><br><?= $viewOrder['delivery_date'] ? date('d/m/Y', strtotime($viewOrder['delivery_date'])) : '-' ?></div></div>
    </div>

    <table class="table table-bordered mb-3">
        <thead class="table-light"><tr><th>Produk</th><th class="text-center">Qty</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th></tr></thead>
        <tbody>
        <?php foreach ($viewItems as $item): ?>
        <tr>
            <td><div class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></div><?php if ($item['description']): ?><small class="text-muted"><?= htmlspecialchars($item['description']) ?></small><?php endif; ?></td>
            <td class="text-center"><?= ($item['quantity']+0) ?> <?= htmlspecialchars($item['unit']) ?></td>
            <td class="text-end">Rp <?= number_format($item['unit_price'],0,',','.') ?></td>
            <td class="text-end">Rp <?= number_format($item['subtotal'],0,',','.') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
            <tr><td colspan="3" class="text-end fw-semibold">Total</td><td class="text-end fw-bold fs-5">Rp <?= number_format($viewOrder['total_amount'],0,',','.') ?></td></tr>
            <tr><td colspan="3" class="text-end">DP <?= $viewOrder['dp_paid'] ? '<span class="badge bg-success">Lunas</span>' : '<span class="badge bg-warning text-dark">Belum Bayar</span>' ?></td>
                <td class="text-end">Rp <?= number_format($viewOrder['dp_amount'],0,',','.') ?></td></tr>
        </tfoot>
    </table>
    <?php if ($viewOrder['notes']): ?><div class="alert alert-light"><strong>Catatan:</strong> <?= nl2br(htmlspecialchars($viewOrder['notes'])) ?></div><?php endif; ?>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<!-- FORM -->
<div class="content-card">
    <h5 class="mb-4"><?= $action === 'add' ? 'Sales Order Baru' : 'Edit Sales Order' ?></h5>
    <form method="POST" id="soForm">
        <input type="hidden" name="form_action" value="<?= $action ?>">
        <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-5">
                <label class="form-label fw-semibold">Nama Customer <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="customer_name" required placeholder="Nama customer / pembeli" value="<?= htmlspecialchars($editOrder['customer_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kontak</label>
                <input type="text" class="form-control" name="customer_contact" placeholder="No. HP / WA" value="<?= htmlspecialchars($editOrder['customer_contact'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select class="form-select" name="status">
                    <?php foreach ($statusList as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($editOrder['status'] ?? 'quotation') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Alamat Pengiriman</label>
                <textarea class="form-control" name="customer_address" rows="2"><?= htmlspecialchars($editOrder['customer_address'] ?? '') ?></textarea>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Tanggal Order</label>
                <input type="date" class="form-control" name="order_date" value="<?= $editOrder['order_date'] ?? date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Target Kirim</label>
                <input type="date" class="form-control" name="delivery_date" value="<?= $editOrder['delivery_date'] ?? '' ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">DP (Rp)</label>
                <input type="number" class="form-control" name="dp_amount" min="0" value="<?= $editOrder['dp_amount'] ?? 0 ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="dp_paid" id="dp_paid" <?= ($editOrder['dp_paid'] ?? 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="dp_paid">DP Sudah Dibayar</label>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <h6 class="fw-semibold mb-3">Item Pesanan</h6>
        <div class="table-responsive mb-3">
            <table class="table table-bordered" id="itemsTable">
                <thead class="table-light"><tr><th>Nama Produk</th><th>Keterangan</th><th style="width:80px">Qty</th><th style="width:70px">Satuan</th><th style="width:130px">Harga/Unit (Rp)</th><th style="width:130px">Subtotal</th><th style="width:40px"></th></tr></thead>
                <tbody id="itemsBody">
                <?php
                $existingItems = !empty($editItems) ? $editItems : [['product_name'=>'','description'=>'','quantity'=>1,'unit'=>'pcs','unit_price'=>0,'subtotal'=>0]];
                foreach ($existingItems as $item): ?>
                <tr class="item-row">
                    <td><input type="text" class="form-control form-control-sm" name="item_name[]" required placeholder="Nama produk" value="<?= htmlspecialchars($item['product_name']) ?>"></td>
                    <td><input type="text" class="form-control form-control-sm" name="item_desc[]" placeholder="Opsional" value="<?= htmlspecialchars($item['description'] ?? '') ?>"></td>
                    <td><input type="number" class="form-control form-control-sm item-qty" name="item_qty[]" step="0.5" min="0.5" value="<?= $item['quantity']+0 ?>"></td>
                    <td><input type="text" class="form-control form-control-sm" name="item_unit[]" value="<?= htmlspecialchars($item['unit']) ?>"></td>
                    <td><input type="number" class="form-control form-control-sm item-price" name="item_price[]" min="0" value="<?= $item['unit_price']+0 ?>"></td>
                    <td class="text-end fw-semibold item-subtotal align-middle">Rp <?= number_format($item['subtotal'],0,',','.') ?></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-x"></i></button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5" class="text-end fw-bold">Total</td><td class="text-end fw-bold" id="grandTotal">Rp 0</td><td></td></tr></tfoot>
            </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="addRow"><i class="bi bi-plus-lg me-1"></i>Tambah Item</button>

        <div class="mb-3">
            <label class="form-label fw-semibold">Catatan</label>
            <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars($editOrder['notes'] ?? '') ?></textarea>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Simpan</button>
            <a href="sales-orders.php" class="btn btn-outline-secondary">Batal</a>
        </div>
    </form>
</div>
<script>
function recalc() {
    let total = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.item-qty').value)||0;
        const price = parseFloat(row.querySelector('.item-price').value)||0;
        const sub = qty * price;
        total += sub;
        row.querySelector('.item-subtotal').textContent = 'Rp ' + sub.toLocaleString('id-ID');
    });
    document.getElementById('grandTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
}
document.addEventListener('input', e => { if (e.target.classList.contains('item-qty')||e.target.classList.contains('item-price')) recalc(); });
document.getElementById('addRow').addEventListener('click', () => {
    const tbody = document.getElementById('itemsBody');
    const tr = document.createElement('tr'); tr.className = 'item-row';
    tr.innerHTML = `<td><input type="text" class="form-control form-control-sm" name="item_name[]" required placeholder="Nama produk"></td><td><input type="text" class="form-control form-control-sm" name="item_desc[]"></td><td><input type="number" class="form-control form-control-sm item-qty" name="item_qty[]" step="0.5" min="0.5" value="1"></td><td><input type="text" class="form-control form-control-sm" name="item_unit[]" value="pcs"></td><td><input type="number" class="form-control form-control-sm item-price" name="item_price[]" min="0" value="0"></td><td class="text-end fw-semibold item-subtotal align-middle">Rp 0</td><td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-x"></i></button></td>`;
    tbody.appendChild(tr);
});
document.addEventListener('click', e => { if (e.target.closest('.remove-row')) { if (document.querySelectorAll('.item-row').length > 1) { e.target.closest('tr').remove(); recalc(); } } });
recalc();
</script>

<?php else: ?>
<!-- LIST -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="?" class="btn btn-sm <?= !$filterStatus ? 'btn-primary' : 'btn-outline-secondary' ?>">Semua</a>
    <?php foreach ($statusList as $val => $lbl): ?>
    <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filterStatus === $val ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
</div>

<div class="content-card">
    <?php if (empty($orders)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-bag fs-1 d-block mb-2"></i>Belum ada sales order.
        <br><a href="?action=add">Buat order pertama</a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>No. Order</th><th>Customer</th><th>Items</th><th class="text-end">Total</th><th>Tgl Order</th><th>Target Kirim</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td><a href="?action=view&id=<?= $o['id'] ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars($o['order_number']) ?></a></td>
                <td>
                    <div><?= htmlspecialchars($o['customer_name']) ?></div>
                    <?php if ($o['customer_contact']): ?><small class="text-muted"><?= htmlspecialchars($o['customer_contact']) ?></small><?php endif; ?>
                </td>
                <td><span class="badge bg-light text-dark border"><?= $o['item_count'] ?> item</span></td>
                <td class="text-end fw-semibold">Rp <?= number_format($o['total_amount'],0,',','.') ?></td>
                <td><?= date('d/m/Y', strtotime($o['order_date'])) ?></td>
                <td><?= $o['delivery_date'] ? date('d/m/Y', strtotime($o['delivery_date'])) : '-' ?></td>
                <td><span class="badge bg-<?= $statusBadge[$o['status']]??'secondary' ?>"><?= $statusList[$o['status']]??$o['status'] ?></span></td>
                <td>
                    <a href="?action=view&id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                    <a href="?action=edit&id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Hapus sales order ini?')">
                        <input type="hidden" name="form_action" value="delete">
                        <input type="hidden" name="id" value="<?= $o['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
