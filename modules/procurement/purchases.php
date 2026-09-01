<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Purchase Invoices';

// Get filters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

// Get suppliers for filter
$suppliers = $db->fetchAll("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");

// Build query
$where_conditions = ['poh.status = \'completed\''];
$params = [];

if ($supplier_id > 0) {
    $where_conditions[] = "poh.supplier_id = :supplier_id";
    $params['supplier_id'] = $supplier_id;
}

if ($date_from) {
    $where_conditions[] = "poh.approved_at >= :date_from";
    $params['date_from'] = $date_from . ' 00:00:00';
}

if ($date_to) {
    $where_conditions[] = "poh.approved_at <= :date_to";
    $params['date_to'] = $date_to . ' 23:59:59';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

$purchases = $db->fetchAll("
    SELECT 
        poh.*,
        s.supplier_name,
        s.supplier_code,
        u.full_name as created_by_name,
        a.full_name as approved_by_name,
        COUNT(pod.id) as items_count,
        COALESCE(ta.file_path, NULL) as ta_attachment_path
    FROM purchase_orders_header poh
    LEFT JOIN suppliers s ON poh.supplier_id = s.id
    LEFT JOIN users u ON poh.created_by = u.id
    LEFT JOIN users a ON poh.approved_by = a.id
    LEFT JOIN purchase_orders_detail pod ON poh.id = pod.po_header_id
    LEFT JOIN transaction_attachments ta ON ta.transaction_type = 'purchase_order' AND ta.transaction_id = poh.id
    {$where_clause}
    GROUP BY poh.id
    ORDER BY poh.approved_at DESC
", $params);

// Calculate summary
$total_amount = 0;
foreach ($purchases as $purchase) {
    $total_amount += $purchase['total_amount'];
}

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                📄 Purchase Invoices
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Daftar PO yang sudah dibayar</p>
        </div>
        <a href="purchase-orders.php" class="btn btn-secondary">
            <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
            Kembali ke PO
        </a>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <!-- Success Popup Modal -->
    <div id="successPopup" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(4px); animation: fadeIn 0.3s ease-out;">
        <div style="background: white; border-radius: 1rem; padding: 2rem; max-width: 420px; width: 90%; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.3); animation: scaleIn 0.3s ease-out;">
            <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                <i data-feather="check" style="width: 40px; height: 40px; color: white; stroke-width: 3;"></i>
            </div>
            <h3 style="font-size: 1.5rem; font-weight: 700; color: #065f46; margin-bottom: 0.75rem;">Pembayaran Berhasil!</h3>
            <div style="color: #047857; font-size: 0.95rem; margin-bottom: 1.5rem; line-height: 1.6;"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <button onclick="document.getElementById('successPopup').style.display='none'" style="background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 0.75rem 2rem; border-radius: 0.5rem; font-weight: 600; font-size: 1rem; cursor: pointer; transition: transform 0.2s;">
                <i data-feather="check-circle" style="width: 18px; height: 18px; display: inline; vertical-align: middle; margin-right: 0.5rem;"></i>
                OK, Mengerti
            </button>
        </div>
    </div>
    <style>
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.8); } to { opacity: 1; transform: scale(1); } }
    </style>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #ef4444; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(239,68,68,0.15); animation: slideInDown 0.5s ease-out;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-feather="x-circle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; color: #991b1b; font-size: 1.125rem; margin-bottom: 0.25rem;">❌ Error!</div>
                <div style="color: #b91c1c; font-size: 0.95rem;"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            </div>
            <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; color: #dc2626; font-size: 1.5rem; cursor: pointer; padding: 0; width: 32px; height: 32px;">&times;</button>
        </div>
    </div>
<?php endif; ?>

<style>
@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem;">Total Invoices</div>
                <div style="font-size: 1.75rem; font-weight: 700; color: var(--text-primary);">
                    <?php echo count($purchases); ?>
                </div>
            </div>
            <div style="width: 3rem; height: 3rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 0.75rem; display: flex; align-items: center; justify-content: center;">
                <i data-feather="file-text" style="width: 1.5rem; height: 1.5rem; color: white;"></i>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem;">Total Pengeluaran</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #e53e3e;">
                    Rp <?php echo number_format($total_amount, 0, ',', '.'); ?>
                </div>
            </div>
            <div style="width: 3rem; height: 3rem; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 0.75rem; display: flex; align-items: center; justify-content: center;">
                <i data-feather="trending-down" style="width: 1.5rem; height: 1.5rem; color: white;"></i>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem;">Rata-rata</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary);">
                    Rp <?php echo count($purchases) > 0 ? number_format($total_amount / count($purchases), 0, ',', '.') : '0'; ?>
                </div>
            </div>
            <div style="width: 3rem; height: 3rem; background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); border-radius: 0.75rem; display: flex; align-items: center; justify-content: center;">
                <i data-feather="bar-chart-2" style="width: 1.5rem; height: 1.5rem; color: white;"></i>
            </div>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 1.25rem;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(3, 1fr) auto; gap: 1rem; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-control">
                <option value="">Semua Supplier</option>
                <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_id == $supplier['id'] ? 'selected' : ''; ?>>
                        <?php echo $supplier['supplier_name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Dari Tanggal</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Sampai Tanggal</label>
            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
        </div>
        
        <button type="submit" class="btn btn-primary">
            <i data-feather="filter" style="width: 16px; height: 16px;"></i>
            Filter
        </button>
    </form>
</div>

<!-- Purchases List -->
<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>PO Number</th>
                <th>Supplier</th>
                <th>Tanggal Bayar</th>
                <th>Items</th>
                <th>Total</th>
                <th>Dibayar Oleh</th>
                <th>Nota</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($purchases)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                        <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
                        <p>Belum ada purchase invoice</p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($purchases as $purchase): ?>
                    <tr>
                        <td>
                            <strong><?php echo $purchase['po_number']; ?></strong>
                        </td>
                        <td>
                            <div style="font-weight: 500;"><?php echo $purchase['supplier_name']; ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $purchase['supplier_code']; ?></div>
                        </td>
                        <td>
                            <?php echo date('d M Y', strtotime($purchase['approved_at'])); ?>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                <?php echo date('H:i', strtotime($purchase['approved_at'])); ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-secondary"><?php echo $purchase['items_count']; ?> items</span>
                        </td>
                        <td>
                            <strong style="color: var(--primary-color);">
                                Rp <?php echo number_format($purchase['total_amount'], 0, ',', '.'); ?>
                            </strong>
                        </td>
                        <td><?php echo $purchase['approved_by_name']; ?></td>
                        <td>
                            <?php 
                            // Determine attachment path safely
                            $attPath = isset($purchase['attachment_path']) ? $purchase['attachment_path'] : '';
                            if (empty($attPath) && isset($purchase['ta_attachment_path'])) $attPath = $purchase['ta_attachment_path'];
                            if (empty($attPath) && isset($purchase['file_path'])) $attPath = $purchase['file_path'];
                            ?>

                            <?php if (!empty($attPath)): ?>
                                <a href="../../<?php echo $attPath; ?>" target="_blank" class="btn btn-sm btn-success">
                                    <i data-feather="image" style="width: 14px; height: 14px;"></i>
                                    Lihat
                                </a>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 0.875rem;">Tidak ada</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="view-po.php?id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-secondary">
                                <i data-feather="eye" style="width: 14px; height: 14px;"></i>
                                Detail
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
