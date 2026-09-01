<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Rekaman Stock Masuk';

$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$activeBusinessName = '';

if ($activeBusinessId > 0) {
    $businessRow = $db->fetchOne('SELECT business_name FROM businesses WHERE id = ? LIMIT 1', [$activeBusinessId]);
    $activeBusinessName = $businessRow['business_name'] ?? '';
}

$incomingTransfers = [];
$businessPurchaseOrders = [];

if ($activeBusinessId > 0) {
    $incomingTransfers = $db->fetchAll("\n        SELECT\n            gt.id,\n            gt.transfer_number,\n            gt.target_business_name,\n            gt.status,\n            gt.notes,\n            gt.created_at,\n            gt.received_by,\n            u.full_name AS created_by_name,\n            r.full_name AS received_by_name,\n            COUNT(gti.id) AS items_count,\n            COALESCE(SUM(gti.quantity), 0) AS total_qty\n        FROM gudang_nasita_transfers gt\n        LEFT JOIN users u ON gt.created_by = u.id\n        LEFT JOIN users r ON gt.received_by = r.id\n        LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id\n        WHERE gt.target_business_id = ?\n        GROUP BY gt.id\n        ORDER BY gt.created_at DESC\n        LIMIT 100\n    ", [$activeBusinessId]);

    $businessPurchaseOrders = $db->fetchAll("\n        SELECT\n            poh.id,\n            poh.po_number,\n            poh.po_date,\n            poh.status,\n            poh.notes,\n            poh.created_at,\n            s.supplier_name,\n            COUNT(pod.id) AS items_count,\n            COALESCE(SUM(pod.quantity), 0) AS ordered_qty,\n            COALESCE(SUM(pod.received_quantity), 0) AS received_qty\n        FROM purchase_orders_header poh\n        LEFT JOIN suppliers s ON s.id = poh.supplier_id\n        LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id\n        WHERE poh.business_id = ?\n        GROUP BY poh.id\n        ORDER BY poh.created_at DESC\n        LIMIT 100\n    ", [$activeBusinessId]);
}

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">Rekaman Stock Masuk</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">PO dan stock masuk untuk bisnis aktif<?php echo $activeBusinessName ? ' - ' . htmlspecialchars($activeBusinessName) : ''; ?></p>
    </div>
    <a href="purchase-orders.php" class="btn btn-secondary">
        <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
        Kembali ke PO
    </a>
</div>

<?php if ($activeBusinessId <= 0): ?>
    <div class="alert alert-warning">
        Tidak ada bisnis aktif di sesi ini.
    </div>
<?php else: ?>
    <div class="card" style="margin-bottom: 1.25rem;">
        <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 1rem;">Purchase Order Bisnis Ini</h3>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>No PO</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th>Item</th>
                        <th>Status</th>
                        <th>Qty Terima</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($businessPurchaseOrders)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">Belum ada PO untuk bisnis ini.</td>
                        </tr>
                        <?php else: foreach ($businessPurchaseOrders as $po): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                <td><?php echo !empty($po['po_date']) ? date('d M Y', strtotime($po['po_date'])) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($po['supplier_name'] ?? '-'); ?></td>
                                <td><?php echo (int)$po['items_count']; ?> item</td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $po['status']))); ?></span></td>
                                <td><?php echo number_format((float)$po['received_qty'], 0, ',', '.'); ?></td>
                                <td><a href="view-po.php?id=<?php echo (int)$po['id']; ?>" class="btn btn-sm btn-outline-primary">Lihat</a></td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 1rem;">Rekaman Stock Masuk dari Gudang</h3>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>No Transfer</th>
                        <th>Tanggal</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th>Dibuat Oleh</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($incomingTransfers)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">Belum ada transfer stock masuk.</td>
                        </tr>
                        <?php else: foreach ($incomingTransfers as $transfer): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($transfer['transfer_number']); ?></strong></td>
                                <td><?php echo !empty($transfer['created_at']) ? date('d M Y H:i', strtotime($transfer['created_at'])) : '-'; ?></td>
                                <td><?php echo (int)$transfer['items_count']; ?> item</td>
                                <td><?php echo number_format((float)$transfer['total_qty'], 0, ',', '.'); ?></td>
                                <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars(ucfirst($transfer['status'])); ?></span></td>
                                <td><?php echo htmlspecialchars($transfer['created_by_name'] ?? '-'); ?></td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>