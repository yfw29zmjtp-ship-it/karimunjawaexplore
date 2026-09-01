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

// Get statistics
$po_count = $db->fetchOne("SELECT COUNT(*) as count FROM purchase_orders_header WHERE supplier_id = ?", [$supplier_id])['count'];
$invoice_count = $db->fetchOne("SELECT COUNT(*) as count FROM purchases_header WHERE supplier_id = ?", [$supplier_id])['count'];

// Get recent POs
$recent_pos = $db->fetchAll("
    SELECT po_number, po_date, status, grand_total
    FROM purchase_orders_header
    WHERE supplier_id = ?
    ORDER BY po_date DESC
    LIMIT 5
", [$supplier_id]);

// Get recent invoices
$recent_invoices = $db->fetchAll("
    SELECT invoice_number, invoice_date, payment_status, grand_total
    FROM purchases_header
    WHERE supplier_id = ?
    ORDER BY invoice_date DESC
    LIMIT 5
", [$supplier_id]);

$pageTitle = $supplier['supplier_name'];

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <a href="suppliers.php" class="btn btn-secondary btn-sm">
                <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i>
            </a>
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
                    👤 <?php echo $supplier['supplier_name']; ?>
                </h2>
                <p style="color: var(--text-muted); font-size: 0.875rem;"><?php echo $supplier['supplier_code']; ?></p>
            </div>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <a href="edit-supplier.php?id=<?php echo $supplier_id; ?>" class="btn btn-primary btn-sm">
                <i data-feather="edit" style="width: 14px; height: 14px;"></i> Edit
            </a>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
    <!-- Supplier Info -->
    <div class="card">
        <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Informasi Supplier</h3>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Kode Supplier</div>
                <div style="font-weight: 600;"><?php echo $supplier['supplier_code']; ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Status</div>
                <?php if ($supplier['is_active']): ?>
                    <span class="badge badge-success">Active</span>
                <?php else: ?>
                    <span class="badge badge-danger">Inactive</span>
                <?php endif; ?>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Contact Person</div>
                <div><?php echo $supplier['contact_person'] ?: '-'; ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Phone</div>
                <div><?php echo $supplier['phone'] ?: '-'; ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Email</div>
                <div><?php echo $supplier['email'] ?: '-'; ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Payment Terms</div>
                <div><span class="badge badge-secondary"><?php echo str_replace('_', ' ', $supplier['payment_terms']); ?></span></div>
            </div>
            <div style="grid-column: 1 / -1;">
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Alamat</div>
                <div><?php echo $supplier['address'] ? nl2br(htmlspecialchars($supplier['address'])) : '-'; ?></div>
            </div>
            <?php if ($supplier['tax_number']): ?>
            <div style="grid-column: 1 / -1;">
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Tax Number (NPWP)</div>
                <div><?php echo $supplier['tax_number']; ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Statistics -->
    <div>
        <div class="card" style="margin-bottom: 1rem;">
            <div style="text-align: center;">
                <i data-feather="file-text" style="width: 40px; height: 40px; color: var(--primary-color); margin-bottom: 0.5rem;"></i>
                <div style="font-size: 2rem; font-weight: 800; color: var(--text-primary);"><?php echo $po_count; ?></div>
                <div style="font-size: 0.813rem; color: var(--text-muted);">Total Purchase Orders</div>
            </div>
        </div>
        <div class="card">
            <div style="text-align: center;">
                <i data-feather="shopping-bag" style="width: 40px; height: 40px; color: var(--success); margin-bottom: 0.5rem;"></i>
                <div style="font-size: 2rem; font-weight: 800; color: var(--text-primary);"><?php echo $invoice_count; ?></div>
                <div style="font-size: 0.813rem; color: var(--text-muted);">Total Invoices</div>
            </div>
        </div>
    </div>
</div>

<!-- Recent POs -->
<?php if (!empty($recent_pos)): ?>
<div class="card" style="margin-bottom: 1.25rem;">
    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Recent Purchase Orders</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>PO Number</th>
                    <th>Tanggal</th>
                    <th>Status</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_pos as $po): ?>
                    <tr>
                        <td style="font-weight: 600; color: var(--primary-color);"><?php echo $po['po_number']; ?></td>
                        <td><?php echo date('d M Y', strtotime($po['po_date'])); ?></td>
                        <td><span class="badge badge-secondary"><?php echo $po['status']; ?></span></td>
                        <td class="text-right" style="font-weight: 600;">Rp <?php echo number_format($po['grand_total'], 0, ',', '.'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Recent Invoices -->
<?php if (!empty($recent_invoices)): ?>
<div class="card">
    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Recent Invoices</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Invoice Number</th>
                    <th>Tanggal</th>
                    <th>Payment Status</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_invoices as $inv): ?>
                    <tr>
                        <td style="font-weight: 600; color: var(--primary-color);"><?php echo $inv['invoice_number']; ?></td>
                        <td><?php echo date('d M Y', strtotime($inv['invoice_date'])); ?></td>
                        <td><span class="badge badge-secondary"><?php echo $inv['payment_status']; ?></span></td>
                        <td class="text-right" style="font-weight: 600;">Rp <?php echo number_format($inv['grand_total'], 0, ',', '.'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
