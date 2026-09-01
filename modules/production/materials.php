<?php
/**
 * Production Module - Materials (Stok Bahan Baku)
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
$filter  = $_GET['filter'] ?? '';
$error   = '';
$success = '';

$categories = ['kayu'=>'Kayu','kain'=>'Kain','besi'=>'Besi','kaca'=>'Kaca','finishing'=>'Finishing','hardware'=>'Hardware','lainnya'=>'Lainnya'];

// ============================
// HANDLE POST
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa = $_POST['form_action'] ?? '';

    if ($fa === 'add' || $fa === 'edit') {
        $code     = strtoupper(trim($_POST['material_code'] ?? ''));
        $name     = trim($_POST['material_name'] ?? '');
        $cat      = $_POST['category'] ?? 'lainnya';
        $unit     = trim($_POST['unit'] ?? 'pcs');
        $qty      = (float)($_POST['stock_quantity'] ?? 0);
        $min      = (float)($_POST['min_stock'] ?? 0);
        $price    = (float)str_replace(['.','Rp',' '],'',$_POST['unit_price'] ?? 0);
        $supplier = trim($_POST['supplier'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');

        if (empty($code) || empty($name)) {
            $error = 'Kode dan nama bahan wajib diisi.';
        } else {
            try {
                if ($fa === 'add') {
                    $pdo->prepare("INSERT INTO production_materials (material_code,material_name,category,unit,stock_quantity,min_stock,unit_price,supplier,location,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$code,$name,$cat,$unit,$qty,$min,$price,$supplier,$location,$notes]);
                    $success = "Bahan baku {$name} berhasil ditambahkan.";
                } else {
                    $pdo->prepare("UPDATE production_materials SET material_code=?,material_name=?,category=?,unit=?,stock_quantity=?,min_stock=?,unit_price=?,supplier=?,location=?,notes=?,updated_at=NOW() WHERE id=?")
                        ->execute([$code,$name,$cat,$unit,$qty,$min,$price,$supplier,$location,$notes,$id]);
                    $success = "Bahan baku berhasil diperbarui.";
                }
                $action = 'list';
            } catch (Exception $e) {
                $error = 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    }

    if ($fa === 'adjust') {
        $matId     = (int)($_POST['material_id'] ?? 0);
        $adjType   = $_POST['adj_type'] ?? 'add';
        $adjQty    = (float)($_POST['adj_qty'] ?? 0);
        if ($adjQty > 0) {
            try {
                $op = ($adjType === 'add') ? '+' : '-';
                $pdo->prepare("UPDATE production_materials SET stock_quantity = GREATEST(0, stock_quantity {$op} ?), updated_at=NOW() WHERE id=?")->execute([$adjQty, $matId]);
                $success = "Stok berhasil disesuaikan.";
            } catch (Exception $e) {
                $error = 'Gagal: ' . $e->getMessage();
            }
        }
        $action = 'list';
    }

    if ($fa === 'delete') {
        try {
            $pdo->prepare("DELETE FROM production_materials WHERE id=?")->execute([$id]);
            $success = 'Bahan baku dihapus.';
        } catch (Exception $e) {
            $error = 'Gagal menghapus: ' . $e->getMessage();
        }
        $action = 'list';
    }
}

// ============================
// DATA
// ============================
$materials = [];
if ($action === 'list') {
    $where = '';
    $params = [];
    if ($filter === 'low_stock') {
        $where = 'WHERE stock_quantity <= min_stock AND min_stock > 0';
    }
    $materials = $pdo->query("SELECT * FROM production_materials $where ORDER BY category, material_name")->fetchAll();
}

$editMaterial = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM production_materials WHERE id=?");
    $stmt->execute([$id]);
    $editMaterial = $stmt->fetch();
}

$totalValue = 0;
if ($action === 'list') {
    foreach ($materials as $m) $totalValue += $m['stock_quantity'] * $m['unit_price'];
}

include '../../includes/header.php';
?>

<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-1"><i class="bi bi-box-seam me-2 text-warning"></i>Stok Bahan Baku</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="dashboard.php">Produksi</a></li>
                <li class="breadcrumb-item active">Bahan Baku</li>
            </ol></nav>
        </div>
        <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-warning btn-sm text-dark"><i class="bi bi-plus-lg me-1"></i>Tambah Bahan</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="content-card" style="max-width:650px">
    <h5 class="mb-4"><?= $action === 'add' ? 'Tambah Bahan Baku' : 'Edit Bahan Baku' ?></h5>
    <form method="POST">
        <input type="hidden" name="form_action" value="<?= $action ?>">
        <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kode Bahan <span class="text-danger">*</span></label>
                <input type="text" class="form-control text-uppercase" name="material_code" required
                       placeholder="KYJ-001" value="<?= htmlspecialchars($editMaterial['material_code'] ?? '') ?>">
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold">Nama Bahan <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="material_name" required
                       placeholder="Kayu Jati Grade A" value="<?= htmlspecialchars($editMaterial['material_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kategori</label>
                <select class="form-select" name="category">
                    <?php foreach ($categories as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($editMaterial['category'] ?? 'lainnya') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Satuan</label>
                <input type="text" class="form-control" name="unit" placeholder="pcs / meter / kg / lembar" value="<?= htmlspecialchars($editMaterial['unit'] ?? 'pcs') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Harga Satuan (Rp)</label>
                <input type="number" class="form-control" name="unit_price" min="0" value="<?= $editMaterial['unit_price'] ?? 0 ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Stok Awal</label>
                <input type="number" class="form-control" name="stock_quantity" step="0.001" min="0" value="<?= $editMaterial['stock_quantity'] ?? 0 ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Stok Minimum</label>
                <input type="number" class="form-control" name="min_stock" step="0.001" min="0" value="<?= $editMaterial['min_stock'] ?? 0 ?>">
                <small class="text-muted">Notifikasi jika stok di bawah nilai ini</small>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Lokasi Penyimpanan</label>
                <input type="text" class="form-control" name="location" placeholder="Gudang A / Rak 3" value="<?= htmlspecialchars($editMaterial['location'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Supplier</label>
                <input type="text" class="form-control" name="supplier" placeholder="Nama toko/supplier" value="<?= htmlspecialchars($editMaterial['supplier'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars($editMaterial['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-warning text-dark"><i class="bi bi-check-lg me-1"></i>Simpan</button>
            <a href="materials.php" class="btn btn-outline-secondary">Batal</a>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Summary -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="content-card text-center">
            <div class="fs-4 fw-bold"><?= count($materials) ?></div>
            <div class="text-muted small">Total Item</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="content-card text-center">
            <div class="fs-4 fw-bold text-danger"><?= count(array_filter($materials, fn($m) => $m['stock_quantity'] <= $m['min_stock'] && $m['min_stock'] > 0)) ?></div>
            <div class="text-muted small">Stok Menipis</div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="content-card text-center">
            <div class="fs-4 fw-bold text-success">Rp <?= number_format($totalValue,0,',','.') ?></div>
            <div class="text-muted small">Total Nilai Stok</div>
        </div>
    </div>
</div>

<!-- Filter tabs -->
<div class="d-flex gap-2 mb-3">
    <a href="materials.php" class="btn btn-sm <?= !$filter ? 'btn-primary' : 'btn-outline-secondary' ?>">Semua</a>
    <a href="?filter=low_stock" class="btn btn-sm <?= $filter==='low_stock' ? 'btn-danger' : 'btn-outline-danger' ?>"><i class="bi bi-exclamation-triangle me-1"></i>Stok Menipis</a>
</div>

<div class="content-card">
    <?php if (empty($materials)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-box-seam fs-1 d-block mb-2"></i>Belum ada data bahan baku.
        <br><a href="?action=add">Tambah bahan pertama</a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Kode</th><th>Nama Bahan</th><th>Kategori</th><th class="text-end">Stok</th><th class="text-end">Min. Stok</th><th class="text-end">Harga</th><th>Lokasi</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($materials as $m): ?>
            <?php $isLow = $m['min_stock'] > 0 && $m['stock_quantity'] <= $m['min_stock']; ?>
            <tr class="<?= $isLow ? 'table-warning' : '' ?>">
                <td><code><?= htmlspecialchars($m['material_code']) ?></code></td>
                <td>
                    <div class="fw-semibold"><?= htmlspecialchars($m['material_name']) ?></div>
                    <?php if ($m['supplier']): ?><small class="text-muted"><?= htmlspecialchars($m['supplier']) ?></small><?php endif; ?>
                </td>
                <td><span class="badge bg-secondary"><?= $categories[$m['category']]??$m['category'] ?></span></td>
                <td class="text-end fw-semibold <?= $isLow ? 'text-danger' : '' ?>">
                    <?= ($m['stock_quantity']+0) ?> <?= htmlspecialchars($m['unit']) ?>
                    <?= $isLow ? '<i class="bi bi-exclamation-triangle text-danger ms-1"></i>' : '' ?>
                </td>
                <td class="text-end text-muted"><?= ($m['min_stock']+0) ?> <?= htmlspecialchars($m['unit']) ?></td>
                <td class="text-end">Rp <?= number_format($m['unit_price'],0,',','.') ?></td>
                <td><?= htmlspecialchars($m['location'] ?? '-') ?></td>
                <td>
                    <!-- Quick adjust -->
                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#adjustModal" data-id="<?= $m['id'] ?>" data-name="<?= htmlspecialchars($m['material_name']) ?>" data-unit="<?= htmlspecialchars($m['unit']) ?>" title="Sesuaikan Stok"><i class="bi bi-arrows-collapse"></i></button>
                    <a href="?action=edit&id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Hapus bahan ini?')">
                        <input type="hidden" name="form_action" value="delete">
                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
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

<!-- Adjust Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="form_action" value="adjust">
                <input type="hidden" name="material_id" id="adjMatId">
                <div class="modal-header"><h5 class="modal-title">Sesuaikan Stok</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="mb-2">Bahan: <strong id="adjMatName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Jenis</label>
                        <select class="form-select" name="adj_type">
                            <option value="add">+ Tambah Stok (Pembelian/Masuk)</option>
                            <option value="sub">- Kurangi Stok (Terpakai/Keluar)</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Jumlah <span id="adjUnit"></span></label>
                        <input type="number" class="form-control" name="adj_qty" step="0.001" min="0.001" required placeholder="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('adjustModal').addEventListener('show.bs.modal', e => {
    const btn = e.relatedTarget;
    document.getElementById('adjMatId').value = btn.dataset.id;
    document.getElementById('adjMatName').textContent = btn.dataset.name;
    document.getElementById('adjUnit').textContent = '(' + btn.dataset.unit + ')';
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
