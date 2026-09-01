<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Suppliers';

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $db->delete('suppliers', ['id' => $id]);
        $_SESSION['success'] = 'Supplier berhasil dihapus';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal menghapus supplier: ' . $e->getMessage();
    }
    header('Location: suppliers.php');
    exit;
}

// Get filters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(supplier_name LIKE :search OR supplier_code LIKE :search OR email LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($status !== '') {
    $where_conditions[] = "is_active = :status";
    $params['status'] = $status;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Check if purchase_orders_header table exists
$tableExists = false;
try {
    $tableCheck = $db->fetchOne("SHOW TABLES LIKE 'purchase_orders_header'");
    $tableExists = !empty($tableCheck);
} catch (Exception $e) {
    $tableExists = false;
}

if ($tableExists) {
    $suppliers = $db->fetchAll("
        SELECT 
            s.*,
            u.full_name as created_by_name,
            (SELECT COUNT(*) FROM purchase_orders_header WHERE supplier_id = s.id) as po_count,
            0 as invoice_count
        FROM suppliers s
        LEFT JOIN users u ON s.created_by = u.id
        {$where_clause}
        ORDER BY s.supplier_name
    ", $params);
} else {
    $suppliers = $db->fetchAll("
        SELECT 
            s.*,
            u.full_name as created_by_name,
            0 as po_count,
            0 as invoice_count
        FROM suppliers s
        LEFT JOIN users u ON s.created_by = u.id
        {$where_clause}
        ORDER BY s.supplier_name
    ", $params);
}

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                👥 Suppliers
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Kelola data supplier</p>
        </div>
        <a href="create-supplier.php" class="btn btn-primary">
            <i data-feather="plus" style="width: 16px; height: 16px;"></i>
            Tambah Supplier
        </a>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger">
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 1.25rem;">
    <form method="GET" style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 1rem; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Cari Supplier</label>
            <input type="text" name="search" class="form-control" placeholder="Nama, kode, atau email supplier" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">Semua Status</option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>Active</option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        
        <button type="submit" class="btn btn-primary" style="height: 42px;">
            <i data-feather="search" style="width: 16px; height: 16px;"></i> Cari
        </button>
    </form>
</div>

<!-- Statistics -->
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <?php
    $total_suppliers = count($suppliers);
    $active_suppliers = count(array_filter($suppliers, function($s) { return $s['is_active'] == 1; }));
    $inactive_suppliers = $total_suppliers - $active_suppliers;
    ?>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #6366f120; display: flex; align-items: center; justify-content: center;">
                <i data-feather="users" style="width: 20px; height: 20px; color: #6366f1;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Total Suppliers</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary);"><?php echo $total_suppliers; ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #10b98120; display: flex; align-items: center; justify-content: center;">
                <i data-feather="check-circle" style="width: 20px; height: 20px; color: #10b981;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Active</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;"><?php echo $active_suppliers; ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #ef444420; display: flex; align-items: center; justify-content: center;">
                <i data-feather="x-circle" style="width: 20px; height: 20px; color: #ef4444;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Inactive</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;"><?php echo $inactive_suppliers; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Suppliers Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Supplier</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Payment Terms</th>
                    <th>PO/Invoice</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <i data-feather="inbox" style="width: 48px; height: 48px; opacity: 0.3; margin-bottom: 1rem;"></i>
                            <p>Tidak ada supplier</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $supplier): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--primary-color);">
                                <?php echo $supplier['supplier_code']; ?>
                            </td>
                            <td style="font-weight: 600;"><?php echo $supplier['supplier_name']; ?></td>
                            <td><?php echo $supplier['contact_person'] ?: '-'; ?></td>
                            <td><?php echo $supplier['phone'] ?: '-'; ?></td>
                            <td style="font-size: 0.813rem;"><?php echo $supplier['email'] ?: '-'; ?></td>
                            <td>
                                <span class="badge badge-secondary">
                                    <?php echo str_replace('_', ' ', $supplier['payment_terms']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-size: 0.813rem;">
                                    <span style="color: var(--success);"><?php echo $supplier['po_count']; ?> PO</span> /
                                    <span style="color: var(--primary-color);"><?php echo $supplier['invoice_count']; ?> INV</span>
                                </div>
                            </td>
                            <td>
                                <?php if ($supplier['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div style="display: flex; gap: 0.5rem; justify-content: center;">
                                    <a href="view-supplier.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-primary" title="View">
                                        <i data-feather="eye" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    <a href="edit-supplier.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-secondary" title="Edit">
                                        <i data-feather="edit" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    <a href="?delete=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-danger" title="Delete" 
                                       onclick="return confirm('Yakin ingin menghapus supplier ini?')">
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

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
