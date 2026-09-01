<?php
/**
 * Database Supplier - List & Manage Suppliers
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Database Supplier';

// Ensure table exists
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_code VARCHAR(30) UNIQUE,
            supplier_name VARCHAR(100) NOT NULL,
            contact_person VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            address TEXT,
            city VARCHAR(50),
            province VARCHAR(50),
            bank_name VARCHAR(100),
            bank_account VARCHAR(50),
            payment_terms VARCHAR(50) DEFAULT 'COD',
            notes TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    // Table might already exist
}

// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    
    $data = [
        'supplier_name' => trim($_POST['supplier_name'] ?? ''),
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'province' => trim($_POST['province'] ?? ''),
        'bank_name' => trim($_POST['bank_name'] ?? ''),
        'bank_account' => trim($_POST['bank_account'] ?? ''),
        'payment_terms' => $_POST['payment_terms'] ?? 'COD',
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
    
    try {
        if ($action === 'edit' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $db->update('suppliers', $data, 'id = :where_id', ['where_id' => $id]);
            $_SESSION['success'] = 'Supplier berhasil diupdate';
        } else {
            // Generate supplier code
            $lastCode = $db->fetchOne("SELECT supplier_code FROM suppliers ORDER BY id DESC LIMIT 1");
            if ($lastCode) {
                $num = (int)substr($lastCode['supplier_code'], 4) + 1;
            } else {
                $num = 1;
            }
            $data['supplier_code'] = 'SUP-' . str_pad($num, 4, '0', STR_PAD_LEFT);
            $data['created_by'] = $currentUser['id'];
            
            $db->insert('suppliers', $data);
            $_SESSION['success'] = 'Supplier berhasil ditambahkan';
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal menyimpan: ' . $e->getMessage();
    }
    
    header('Location: suppliers.php');
    exit;
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $db->delete('suppliers', 'id = ?', [$id]);
        $_SESSION['success'] = 'Supplier berhasil dihapus';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal menghapus: ' . $e->getMessage();
    }
    header('Location: suppliers.php');
    exit;
}

// Handle toggle status
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    try {
        $current = $db->fetchOne("SELECT is_active FROM suppliers WHERE id = ?", [$id]);
        $newStatus = $current['is_active'] ? 0 : 1;
        $db->update('suppliers', ['is_active' => $newStatus], 'id = :where_id', ['where_id' => $id]);
        $_SESSION['success'] = 'Status supplier berhasil diubah';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal mengubah status: ' . $e->getMessage();
    }
    header('Location: suppliers.php');
    exit;
}

// Get edit data if editing
$editData = null;
if (isset($_GET['edit'])) {
    $editData = $db->fetchOne("SELECT * FROM suppliers WHERE id = ?", [(int)$_GET['edit']]);
}

// Get filters
$search = $_GET['search'] ?? '';

// Build query
$params = [];
$where = '';
if ($search) {
    $where = "WHERE supplier_name LIKE :search OR supplier_code LIKE :search OR contact_person LIKE :search";
    $params['search'] = '%' . $search . '%';
}

$suppliers = $db->fetchAll("SELECT * FROM suppliers {$where} ORDER BY supplier_name", $params);

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <nav style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                <a href="index.php" style="color: var(--primary-color);">Database</a> / Supplier
            </nav>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
                📦 Database Supplier
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Kelola data supplier/vendor</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="showModal('addModal')">
            <i data-feather="plus" style="width: 16px; height: 16px;"></i>
            Tambah Supplier
        </button>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Search -->
<div class="card" style="margin-bottom: 1.25rem; padding: 1rem;">
    <form method="GET" style="display: flex; gap: 1rem;">
        <input type="text" name="search" class="form-control" placeholder="Cari nama, kode, atau contact person..." 
               value="<?php echo htmlspecialchars($search); ?>" style="flex: 1;">
        <button type="submit" class="btn btn-primary">
            <i data-feather="search" style="width: 16px; height: 16px;"></i> Cari
        </button>
        <?php if ($search): ?>
            <a href="suppliers.php" class="btn btn-secondary">Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- Statistics -->
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <?php
    $total = count($suppliers);
    $active = count(array_filter($suppliers, fn($s) => $s['is_active'] == 1));
    $inactive = $total - $active;
    ?>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #6366f120; display: flex; align-items: center; justify-content: center;">
                <i data-feather="truck" style="width: 20px; height: 20px; color: #6366f1;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Total</div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?php echo $total; ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #10b98120; display: flex; align-items: center; justify-content: center;">
                <i data-feather="check-circle" style="width: 20px; height: 20px; color: #10b981;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Aktif</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;"><?php echo $active; ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #ef444420; display: flex; align-items: center; justify-content: center;">
                <i data-feather="x-circle" style="width: 20px; height: 20px; color: #ef4444;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Nonaktif</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;"><?php echo $inactive; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Supplier</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Kota</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <i data-feather="inbox" style="width: 48px; height: 48px; opacity: 0.3;"></i>
                            <p style="margin-top: 1rem;">Belum ada data supplier</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $sup): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--primary-color);"><?php echo $sup['supplier_code']; ?></td>
                            <td style="font-weight: 600;"><?php echo htmlspecialchars($sup['supplier_name']); ?></td>
                            <td><?php echo htmlspecialchars($sup['contact_person'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($sup['phone'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($sup['city'] ?: '-'); ?></td>
                            <td><span class="badge badge-secondary"><?php echo $sup['payment_terms']; ?></span></td>
                            <td>
                                <?php if ($sup['is_active']): ?>
                                    <span class="badge badge-success">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div style="display: flex; gap: 0.25rem; justify-content: center;">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="editSupplier(<?php echo htmlspecialchars(json_encode($sup)); ?>)" title="Edit">
                                        <i data-feather="edit" style="width: 14px; height: 14px;"></i>
                                    </button>
                                    <a href="?toggle=<?php echo $sup['id']; ?>" class="btn btn-sm <?php echo $sup['is_active'] ? 'btn-warning' : 'btn-success'; ?>" title="Toggle Status">
                                        <i data-feather="<?php echo $sup['is_active'] ? 'eye-off' : 'eye'; ?>" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    <a href="?delete=<?php echo $sup['id']; ?>" class="btn btn-sm btn-danger" title="Hapus" onclick="return confirm('Hapus supplier ini?')">
                                        <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="addModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 id="modalTitle">Tambah Supplier</h3>
            <button type="button" onclick="hideModal('addModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <form method="POST" id="supplierForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="supplierId">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: 1.5rem;">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Nama Supplier <span style="color: red;">*</span></label>
                    <input type="text" name="supplier_name" id="supplier_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" id="contact_person" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" id="phone" class="form-control">
                </div>
                
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control">
                </div>
                
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Alamat</label>
                    <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Kota</label>
                    <input type="text" name="city" id="city" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Provinsi</label>
                    <input type="text" name="province" id="province" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Bank</label>
                    <input type="text" name="bank_name" id="bank_name" class="form-control" placeholder="Nama Bank">
                </div>
                
                <div class="form-group">
                    <label class="form-label">No. Rekening</label>
                    <input type="text" name="bank_account" id="bank_account" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Terms</label>
                    <select name="payment_terms" id="payment_terms" class="form-control">
                        <option value="COD">COD (Cash on Delivery)</option>
                        <option value="NET7">NET 7 (7 Hari)</option>
                        <option value="NET14">NET 14 (14 Hari)</option>
                        <option value="NET30">NET 30 (30 Hari)</option>
                        <option value="NET60">NET 60 (60 Hari)</option>
                    </select>
                </div>
                
                <div class="form-group" style="display: flex; align-items: center; padding-top: 1.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="is_active" checked>
                        <span>Aktif</span>
                    </label>
                </div>
                
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 0.5rem; padding: 1rem 1.5rem; border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary" onclick="hideModal('addModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.modal-content {
    background: var(--bg-primary);
    border-radius: 12px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
}
.modal-header h3 {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
}
</style>

<script>
feather.replace();

function showModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function hideModal(id) {
    document.getElementById(id).style.display = 'none';
    // Reset form
    document.getElementById('supplierForm').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('supplierId').value = '';
    document.getElementById('modalTitle').textContent = 'Tambah Supplier';
    document.getElementById('is_active').checked = true;
}

function editSupplier(data) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('supplierId').value = data.id;
    document.getElementById('modalTitle').textContent = 'Edit Supplier';
    
    document.getElementById('supplier_name').value = data.supplier_name || '';
    document.getElementById('contact_person').value = data.contact_person || '';
    document.getElementById('phone').value = data.phone || '';
    document.getElementById('email').value = data.email || '';
    document.getElementById('address').value = data.address || '';
    document.getElementById('city').value = data.city || '';
    document.getElementById('province').value = data.province || '';
    document.getElementById('bank_name').value = data.bank_name || '';
    document.getElementById('bank_account').value = data.bank_account || '';
    document.getElementById('payment_terms').value = data.payment_terms || 'COD';
    document.getElementById('notes').value = data.notes || '';
    document.getElementById('is_active').checked = data.is_active == 1;
    
    showModal('addModal');
}

// Close modal on outside click
document.getElementById('addModal').addEventListener('click', function(e) {
    if (e.target === this) hideModal('addModal');
});

<?php if ($editData): ?>
editSupplier(<?php echo json_encode($editData); ?>);
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
