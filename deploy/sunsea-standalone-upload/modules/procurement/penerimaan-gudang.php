<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

$businessConfig = getActiveBusinessConfig();
$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Penerimaan dari Gudang';

// Check if user is warehouse/gudang user
$isWarehouse = $auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse') || $auth->hasPermission('warehouse_transfers');

$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

// For non-warehouse users, require a business context
if (!$isWarehouse && $activeBusinessId <= 0) {
    http_response_code(403);
    echo 'Bisnis tidak ditemukan.';
    exit;
}

// Get all transfers with filters
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$businessIdFilter = isset($_GET['business_id']) ? (int)$_GET['business_id'] : ($isWarehouse ? 0 : $activeBusinessId);

$whereConditions = [];
$whereParams = [];

// For business users: only show their transfers
// For warehouse users: show all transfers (unless they filter by business)
if (!$isWarehouse) {
    $whereConditions[] = 'target_business_id = ?';
    $whereParams[] = $activeBusinessId;
} elseif ($businessIdFilter > 0) {
    $whereConditions[] = 'target_business_id = ?';
    $whereParams[] = $businessIdFilter;
}

if ($dateFrom) {
    $whereConditions[] = 'DATE(created_at) >= ?';
    $whereParams[] = $dateFrom;
}
if ($dateTo) {
    $whereConditions[] = 'DATE(created_at) <= ?';
    $whereParams[] = $dateTo;
}
if ($statusFilter !== '') {
    $whereConditions[] = 'status = ?';
    $whereParams[] = $statusFilter;
}

$whereClause = empty($whereConditions) ? '1=1' : implode(' AND ', $whereConditions);

// Get transfers
$transfers = $db->fetchAll("
    SELECT 
        gnt.id,
        gnt.transfer_number,
        gnt.status,
        gnt.notes,
        gnt.created_at,
        gnt.source_po_id,
        COUNT(gnti.id) as items_count,
        SUM(gnti.quantity) as total_quantity,
        u.full_name as created_by_name
    FROM gudang_nasita_transfers gnt
    LEFT JOIN gudang_nasita_transfer_items gnti ON gnti.transfer_id = gnt.id
    LEFT JOIN users u ON u.id = gnt.created_by
    WHERE {$whereClause}
    GROUP BY gnt.id
    ORDER BY gnt.created_at DESC
    LIMIT 200
", $whereParams);

// Get transfer details for modal (via AJAX or prefetch)
$viewTransferId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$transferDetail = null;
$transferItems = [];
if ($viewTransferId > 0) {
    $detailWhereClause = 'gnt.id = ?';
    $detailParams = [$viewTransferId];

    // For business users, also check they own this transfer
    if (!$isWarehouse && $activeBusinessId > 0) {
        $detailWhereClause .= ' AND gnt.target_business_id = ?';
        $detailParams[] = $activeBusinessId;
    }

    $transferDetail = $db->fetchOne("
        SELECT 
            gnt.id,
            gnt.transfer_number,
            gnt.target_business_id,
            gnt.target_business_name,
            gnt.status,
            gnt.notes,
            gnt.created_at,
            gnt.source_po_id,
            u.full_name as created_by_name
        FROM gudang_nasita_transfers gnt
        LEFT JOIN users u ON u.id = gnt.created_by
        WHERE {$detailWhereClause}
        LIMIT 1
    ", $detailParams);

    if ($transferDetail) {
        $transferItems = $db->fetchAll("
            SELECT 
                id,
                item_name,
                unit,
                quantity,
                notes
            FROM gudang_nasita_transfer_items
            WHERE transfer_id = ?
            ORDER BY id ASC
        ", [$viewTransferId]);
    }
}

// Get all businesses for warehouse user filter
$allBusinesses = [];
if ($isWarehouse) {
    $allBusinesses = $db->fetchAll("SELECT id, business_name FROM businesses WHERE is_active = 1 OR is_active IS NULL ORDER BY business_name ASC");
}

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">Penerimaan dari Gudang</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">
            <?php echo $isWarehouse ? 'Stock yang diterima semua bisnis dari Gudang Nasita' : 'Stok yang diterima dari Gudang Nasita untuk bisnis ini'; ?>
        </p>
    </div>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <?php echo htmlspecialchars($_SESSION['success']);
        unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($_SESSION['error']);
        unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<!-- Filter Section -->
<div style="background: var(--bg-secondary); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
    <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <?php if ($isWarehouse && !empty($allBusinesses)): ?>
            <div style="flex: 1; min-width: 200px;">
                <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--text-secondary);">Bisnis Tujuan</label>
                <select name="business_id" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-primary); color: var(--text-primary);">
                    <option value="">-- Semua Bisnis --</option>
                    <?php foreach ($allBusinesses as $biz): ?>
                        <option value="<?php echo (int)$biz['id']; ?>" <?php echo $businessIdFilter === (int)$biz['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($biz['business_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div style="flex: 1; min-width: 200px;">
            <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--text-secondary);">Dari Tanggal</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-primary); color: var(--text-primary);">
        </div>
        <div style="flex: 1; min-width: 200px;">
            <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--text-secondary);">Sampai Tanggal</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-primary); color: var(--text-primary);">
        </div>
        <div style="flex: 1; min-width: 200px;">
            <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--text-secondary);">Status</label>
            <select name="status" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; background: var(--bg-primary); color: var(--text-primary);">
                <option value="">-- Semua Status --</option>
                <option value="received" <?php echo $statusFilter === 'received' ? 'selected' : ''; ?>>Diterima</option>
                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Tertunda</option>
                <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1rem;">
            <i data-feather="filter" style="width: 16px; height: 16px; margin-right: 0.5rem;"></i>
            Filter
        </button>
    </form>
</div>

<!-- Transfers Table -->
<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Transfer #</th>
                <?php if ($isWarehouse): ?>
                    <th>Bisnis Tujuan</th>
                <?php endif; ?>
                <th>Tanggal</th>
                <th>Items</th>
                <th>Total Qty</th>
                <th>Status</th>
                <th>Diterima Oleh</th>
                <th class="text-center">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($transfers)): ?>
                <tr>
                    <td colspan="<?php echo $isWarehouse ? 8 : 7; ?>" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                        <i data-feather="box" style="width: 48px; height: 48px; opacity: 0.3; margin-bottom: 1rem; display: block;"></i>
                        <p>Belum ada penerimaan dari gudang</p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($transfers as $transfer): ?>
                    <tr>
                        <td style="font-weight: 600; color: var(--primary-color);">
                            <?php echo htmlspecialchars($transfer['transfer_number']); ?>
                        </td>
                        <?php if ($isWarehouse): ?>
                            <td style="font-size: 0.9rem; color: var(--text-primary);">
                                <strong><?php echo htmlspecialchars($transfer['target_business_name'] ?? 'Unknown'); ?></strong>
                            </td>
                        <?php endif; ?>
                        <td><?php echo date('d M Y H:i', strtotime($transfer['created_at'])); ?></td>
                        <td>
                            <span class="badge" style="background: var(--primary-color); color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.75rem;">
                                <?php echo (int)$transfer['items_count']; ?> item(s)
                            </span>
                        </td>
                        <td><?php echo number_format($transfer['total_quantity'], 2); ?></td>
                        <td>
                            <?php
                            $statusLabels = [
                                'received' => ['label' => 'Diterima', 'color' => '#10b981'],
                                'pending' => ['label' => 'Tertunda', 'color' => '#f59e0b'],
                                'cancelled' => ['label' => 'Dibatalkan', 'color' => '#ef4444'],
                            ];
                            $status = $transfer['status'] ?? 'received';
                            $statusInfo = $statusLabels[$status] ?? ['label' => $status, 'color' => '#6b7280'];
                            ?>
                            <span style="background: <?php echo $statusInfo['color']; ?>; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.75rem; font-weight: 500;">
                                ✓ <?php echo htmlspecialchars($statusInfo['label']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($transfer['created_by_name'] ?? 'System'); ?></td>
                        <td class="text-center">
                            <a href="?view=<?php echo (int)$transfer['id']; ?><?php echo $isWarehouse && $businessIdFilter > 0 ? '&business_id=' . (int)$businessIdFilter : ''; ?>" class="btn btn-sm btn-info" style="padding: 0.375rem 0.75rem; font-size: 0.75rem;">
                                <i data-feather="eye" style="width: 14px; height: 14px;"></i> Lihat
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Detail Modal -->
<?php if ($transferDetail && !empty($transferItems)): ?>
    <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem;">
        <div style="background: var(--bg-primary); border-radius: 8px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
            <div style="padding: 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    Detail Penerimaan: <?php echo htmlspecialchars($transferDetail['transfer_number']); ?>
                </h3>
                <a href="penerimaan-gudang.php" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">×</a>
            </div>

            <div style="padding: 1.5rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div>
                        <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0 0 0.25rem 0;">Tanggal Penerimaan</p>
                        <p style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin: 0;">
                            <?php echo date('d M Y H:i', strtotime($transferDetail['created_at'])); ?>
                        </p>
                    </div>
                    <div>
                        <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0 0 0.25rem 0;">Status</p>
                        <p style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin: 0;">
                            <?php echo $statusLabels[$transferDetail['status'] ?? 'received']['label'] ?? $transferDetail['status']; ?>
                        </p>
                    </div>
                    <div>
                        <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0 0 0.25rem 0;">Diterima Oleh</p>
                        <p style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin: 0;">
                            <?php echo htmlspecialchars($transferDetail['created_by_name'] ?? 'System'); ?>
                        </p>
                    </div>
                    <div>
                        <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0 0 0.25rem 0;">Asal Gudang</p>
                        <p style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin: 0;">
                            Gudang Nasita
                        </p>
                    </div>
                </div>

                <?php if (!empty($transferDetail['notes'])): ?>
                    <div style="margin-bottom: 1.5rem; padding: 1rem; background: var(--bg-secondary); border-radius: 4px;">
                        <p style="font-size: 0.875rem; color: var(--text-muted); margin: 0 0 0.5rem 0;">Catatan</p>
                        <p style="font-size: 1rem; color: var(--text-primary); margin: 0; word-break: break-word;">
                            <?php echo htmlspecialchars($transferDetail['notes']); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div>
                    <h4 style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin: 0 0 1rem 0;">Detail Item</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <th style="text-align: left; padding: 0.75rem; font-size: 0.875rem; font-weight: 600; color: var(--text-muted);">Item</th>
                                <th style="text-align: center; padding: 0.75rem; font-size: 0.875rem; font-weight: 600; color: var(--text-muted);">Qty</th>
                                <th style="text-align: center; padding: 0.75rem; font-size: 0.875rem; font-weight: 600; color: var(--text-muted);">Satuan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transferItems as $item): ?>
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <td style="text-align: left; padding: 0.75rem; font-size: 0.875rem; color: var(--text-primary);">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                        <?php if (!empty($item['notes'])): ?>
                                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($item['notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center; padding: 0.75rem; font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">
                                        <?php echo number_format($item['quantity'], 2); ?>
                                    </td>
                                    <td style="text-align: center; padding: 0.75rem; font-size: 0.875rem; color: var(--text-muted);">
                                        <?php echo htmlspecialchars($item['unit']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="padding: 1.5rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 1rem;">
                <a href="penerimaan-gudang.php" class="btn btn-secondary">Tutup</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>