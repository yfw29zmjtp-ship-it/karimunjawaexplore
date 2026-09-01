<?php
/**
 * Production Module - Production Orders (Daftar Order Produksi)
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

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$error  = '';
$success = '';

// ============================
// HANDLE ACTIONS
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'add' || $formAction === 'edit') {
        $productName  = trim($_POST['product_name'] ?? '');
        $productType  = trim($_POST['product_type'] ?? '');
        $quantity     = (float)($_POST['quantity'] ?? 1);
        $unit         = trim($_POST['unit'] ?? 'pcs');
        $status       = $_POST['status'] ?? 'pending';
        $priority     = $_POST['priority'] ?? 'normal';
        $dueDate      = $_POST['due_date'] ?? null;
        $notes        = trim($_POST['notes'] ?? '');
        $salesOrderId = (int)($_POST['sales_order_id'] ?? 0) ?: null;

        if (empty($productName)) {
            $error = 'Nama produk wajib diisi.';
        } else {
            try {
                if ($formAction === 'add') {
                    $orderNumber = genOrderNumber($pdo, 'PO');
                    $pdo->prepare("INSERT INTO production_orders (order_number, sales_order_id, product_name, product_type, quantity, unit, status, priority, due_date, notes, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$orderNumber, $salesOrderId, $productName, $productType, $quantity, $unit, $status, $priority, $dueDate ?: null, $notes, $_SESSION['user_id'] ?? null]);
                    $success = "Order produksi {$orderNumber} berhasil dibuat.";
                    $action = 'list';
                } else {
                    $pdo->prepare("UPDATE production_orders SET product_name=?, product_type=?, quantity=?, unit=?, status=?, priority=?, due_date=?, notes=?, sales_order_id=?, updated_at=NOW() WHERE id=?")
                        ->execute([$productName, $productType, $quantity, $unit, $status, $priority, $dueDate ?: null, $notes, $salesOrderId, $id]);
                    // Set timestamps
                    if ($status === 'in_production') {
                        $pdo->prepare("UPDATE production_orders SET started_at = COALESCE(started_at, NOW()) WHERE id=?")->execute([$id]);
                    }
                    if ($status === 'completed') {
                        $pdo->prepare("UPDATE production_orders SET completed_at = COALESCE(completed_at, NOW()) WHERE id=?")->execute([$id]);
                    }
                    $success = 'Order produksi berhasil diperbarui.';
                    $action = 'list';
                }
            } catch (Exception $e) {
                $error = 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    }

    if ($formAction === 'delete') {
        try {
            $pdo->prepare("DELETE FROM production_orders WHERE id=?")->execute([$id]);
            $success = 'Order produksi dihapus.';
            $action = 'list';
        } catch (Exception $e) {
            $error = 'Gagal menghapus: ' . $e->getMessage();
        }
    }
}

// ============================
// DATA FETCHING
// ============================
$orders = [];
$filterStatus = $_GET['status'] ?? '';
if ($action === 'list') {
    $where = $filterStatus ? "WHERE status = ?" : "";
    $params = $filterStatus ? [$filterStatus] : [];
    $stmt = $pdo->prepare("SELECT po.*, so.customer_name FROM production_orders po LEFT JOIN sales_orders so ON po.sales_order_id=so.id $where ORDER BY
        FIELD(priority,'urgent','high','normal','low'), created_at DESC");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
}

$editOrder = null;
if ($action === 'edit' && $id) {
    $editOrder = $pdo->prepare("SELECT * FROM production_orders WHERE id=?")->execute([$id]) ? $pdo->query("SELECT * FROM production_orders WHERE id=$id")->fetch() : null;
    $stmt = $pdo->prepare("SELECT * FROM production_orders WHERE id=?");
    $stmt->execute([$id]);
    $editOrder = $stmt->fetch();
}

$salesOrders = $pdo->query("SELECT id, order_number, customer_name FROM sales_orders WHERE status NOT IN ('cancelled','delivered') ORDER BY order_date DESC")->fetchAll();

$statusList  = ['pending'=>'Pending','in_production'=>'Sedang Diproduksi','quality_check'=>'Quality Check','completed'=>'Selesai','cancelled'=>'Dibatalkan'];
$priorityList = ['low'=>'Rendah','normal'=>'Normal','high'=>'Tinggi','urgent'=>'Urgent'];

include '../../includes/header.php';
?>

<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-1"><i class="bi bi-hammer me-2 text-primary"></i>Order Produksi</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="dashboard.php">Produksi</a></li>
                <li class="breadcrumb-item active">Order Produksi</li>
            </ol></nav>
        </div>
        <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Order Baru</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<!-- FORM -->
<div class="content-card" style="max-width:700px">
    <h5 class="mb-4"><?= $action === 'add' ? 'Order Produksi Baru' : 'Edit Order Produksi' ?></h5>
    <form method="POST">
        <input type="hidden" name="form_action" value="<?= $action ?>">
        <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label fw-semibold">Nama Produk <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="product_name" required
                       placeholder="misal: Meja Makan Kayu Jati 6 Kursi"
                       value="<?= htmlspecialchars($editOrder['product_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jenis Produk</label>
                <input type="text" class="form-control" name="product_type"
                       placeholder="meja / kursi / lemari"
                       value="<?= htmlspecialchars($editOrder['product_type'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jumlah</label>
                <div class="input-group">
                    <input type="number" class="form-control" name="quantity" step="0.5" min="0.5" value="<?= $editOrder['quantity'] ?? 1 ?>">
                    <input type="text" class="form-control" name="unit" style="max-width:80px" placeholder="pcs" value="<?= htmlspecialchars($editOrder['unit'] ?? 'pcs') ?>">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Status</label>
                <select class="form-select" name="status">
                    <?php foreach ($statusList as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($editOrder['status'] ?? 'pending') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Prioritas</label>
                <select class="form-select" name="priority">
                    <?php foreach ($priorityList as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($editOrder['priority'] ?? 'normal') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Sales Order (opsional)</label>
                <select class="form-select" name="sales_order_id">
                    <option value="">- Tidak terhubung -</option>
                    <?php foreach ($salesOrders as $so): ?>
                    <option value="<?= $so['id'] ?>" <?= ($editOrder['sales_order_id'] ?? '') == $so['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($so['order_number'] . ' - ' . $so['customer_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Deadline</label>
                <input type="date" class="form-control" name="due_date" value="<?= $editOrder['due_date'] ?? '' ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars($editOrder['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Simpan</button>
            <a href="orders.php" class="btn btn-outline-secondary">Batal</a>
        </div>
    </form>
</div>

<?php else: ?>
<!-- LIST -->
<!-- Filter -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <?php foreach (array_merge([''=>'Semua'], $statusList) as $val => $label): ?>
    <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filterStatus === $val ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="content-card">
    <?php if (empty($orders)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-hammer fs-1 d-block mb-2"></i>Belum ada order produksi.
        <br><a href="?action=add">Buat order pertama</a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>No. Order</th><th>Produk</th><th>Qty</th><th>Sales Order</th><th>Prioritas</th><th>Deadline</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
            <?php
            $priorityBadge = ['low'=>'secondary','normal'=>'info','high'=>'warning','urgent'=>'danger'];
            $statusBadge = ['pending'=>'secondary','in_production'=>'primary','quality_check'=>'warning','completed'=>'success','cancelled'=>'danger'];
            $overdue = $o['due_date'] && $o['due_date'] < date('Y-m-d') && !in_array($o['status'],['completed','cancelled']);
            ?>
            <tr class="<?= $overdue ? 'table-warning' : '' ?>">
                <td><a href="?action=edit&id=<?= $o['id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($o['order_number']) ?></a></td>
                <td>
                    <div><?= htmlspecialchars($o['product_name']) ?></div>
                    <?php if ($o['product_type']): ?><small class="text-muted"><?= htmlspecialchars($o['product_type']) ?></small><?php endif; ?>
                </td>
                <td><?= $o['quantity'] + 0 ?> <?= htmlspecialchars($o['unit']) ?></td>
                <td><?= $o['customer_name'] ? htmlspecialchars($o['customer_name']) : '<span class="text-muted">-</span>' ?></td>
                <td><span class="badge bg-<?= $priorityBadge[$o['priority']]??'secondary' ?>"><?= $priorityList[$o['priority']]??$o['priority'] ?></span></td>
                <td><?= $o['due_date'] ? date('d/m/Y', strtotime($o['due_date'])) : '-' ?><?= $overdue ? ' <span class="badge bg-danger">Terlambat</span>' : '' ?></td>
                <td><span class="badge bg-<?= $statusBadge[$o['status']]??'secondary' ?>"><?= $statusList[$o['status']]??$o['status'] ?></span></td>
                <td>
                    <a href="?action=edit&id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Hapus order ini?')">
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
