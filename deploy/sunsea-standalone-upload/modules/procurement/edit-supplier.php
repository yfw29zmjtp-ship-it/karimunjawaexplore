<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get supplier data
$supplier = $db->fetchOne("
    SELECT s.*, u.full_name as created_by_name
    FROM suppliers s
    LEFT JOIN users u ON s.created_by = u.user_id
    WHERE s.id = ?
", [$supplier_id]);

if (!$supplier) {
    header('Location: suppliers.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_code = trim($_POST['supplier_code']);
    $supplier_name = trim($_POST['supplier_name']);
    $contact_person = trim($_POST['contact_person']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $tax_number = trim($_POST['tax_number']);
    $payment_terms = $_POST['payment_terms'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate
    if (empty($supplier_code) || empty($supplier_name)) {
        $_SESSION['error'] = 'Kode dan nama supplier wajib diisi';
    } else {
        // Check if code already exists (except current)
        $existing = $db->fetchOne("SELECT id FROM suppliers WHERE supplier_code = ? AND id != ?", [$supplier_code, $supplier_id]);
        if ($existing) {
            $_SESSION['error'] = 'Kode supplier sudah digunakan';
        } else {
            try {
                $data = [
                    'supplier_code' => $supplier_code,
                    'supplier_name' => $supplier_name,
                    'contact_person' => $contact_person ?: null,
                    'phone' => $phone ?: null,
                    'email' => $email ?: null,
                    'address' => $address ?: null,
                    'tax_number' => $tax_number ?: null,
                    'payment_terms' => $payment_terms,
                    'is_active' => $is_active
                ];
                
                $result = $db->update('suppliers', $data, 'id = :id', ['id' => $supplier_id]);
                
                if ($result !== false) {
                    $_SESSION['success'] = 'Supplier berhasil diupdate';
                    header('Location: view-supplier.php?id=' . $supplier_id);
                    exit;
                } else {
                    $_SESSION['error'] = 'Gagal mengupdate supplier';
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Edit: ' . $supplier['supplier_name'];

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <a href="view-supplier.php?id=<?php echo $supplier_id; ?>" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i>
        </a>
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
                ✏️ Edit Supplier: <?php echo $supplier['supplier_name']; ?>
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Update informasi supplier</p>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger">
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<form method="POST">
    <div class="card" style="margin-bottom: 1.25rem;">
        <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem; color: var(--text-primary);">
            Informasi Supplier
        </h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label class="form-label">Kode Supplier *</label>
                <input type="text" name="supplier_code" class="form-control" required 
                       value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Nama Supplier *</label>
                <input type="text" name="supplier_name" class="form-control" required
                       value="<?php echo htmlspecialchars($supplier['supplier_name']); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Contact Person</label>
                <input type="text" name="contact_person" class="form-control"
                       value="<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control"
                       value="<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Tax Number (NPWP)</label>
                <input type="text" name="tax_number" class="form-control"
                       value="<?php echo htmlspecialchars($supplier['tax_number'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Payment Terms</label>
                <select name="payment_terms" class="form-control">
                    <option value="cash" <?php echo $supplier['payment_terms'] === 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="net_7" <?php echo $supplier['payment_terms'] === 'net_7' ? 'selected' : ''; ?>>Net 7 Days</option>
                    <option value="net_14" <?php echo $supplier['payment_terms'] === 'net_14' ? 'selected' : ''; ?>>Net 14 Days</option>
                    <option value="net_30" <?php echo $supplier['payment_terms'] === 'net_30' ? 'selected' : ''; ?>>Net 30 Days</option>
                    <option value="net_45" <?php echo $supplier['payment_terms'] === 'net_45' ? 'selected' : ''; ?>>Net 45 Days</option>
                    <option value="net_60" <?php echo $supplier['payment_terms'] === 'net_60' ? 'selected' : ''; ?>>Net 60 Days</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Status</label>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
                    <input type="checkbox" name="is_active" id="is_active" value="1" 
                           <?php echo $supplier['is_active'] ? 'checked' : ''; ?>
                           style="width: 18px; height: 18px;">
                    <label for="is_active" style="margin: 0; cursor: pointer;">Active</label>
                </div>
            </div>
        </div>
        
        <div class="form-group" style="margin-top: 1rem;">
            <label class="form-label">Alamat</label>
            <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($supplier['address'] ?? ''); ?></textarea>
        </div>
    </div>
    
    <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
        <a href="view-supplier.php?id=<?php echo $supplier_id; ?>" class="btn btn-secondary">Batal</a>
        <button type="submit" class="btn btn-primary">
            <i data-feather="save" style="width: 16px; height: 16px;"></i> Update Supplier
        </button>
    </div>
</form>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
