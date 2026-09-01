<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

// Safety net: if a PO detail intent lands here, redirect to PO resolver.
$intentPoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$intentPoNumber = trim((string)($_GET['po_number'] ?? ''));
$intentPoBiz = trim((string)($_GET['po_business'] ?? ''));
if ($intentPoId > 0 || $intentPoNumber !== '') {
    $target = 'open-po.php';
    $q = [];
    if ($intentPoId > 0) {
        $q['id'] = $intentPoId;
    }
    if ($intentPoNumber !== '') {
        $q['po_number'] = $intentPoNumber;
    }
    if ($intentPoBiz !== '') {
        $q['po_business'] = $intentPoBiz;
    }
    if (!empty($q)) {
        $target .= '?' . http_build_query($q);
        header('Location: ' . $target);
        exit;
    }
}

// Gudang mode must follow active business context, not global role/permission.
$activeBusinessSlug = strtolower((string)($_SESSION['active_business_id'] ?? ''));
$isGudang = ($activeBusinessSlug === 'gudang-nasita');

// Map of business slugs to database names
$businessDatabases = [
    'narayana-hotel' => 'adf_narayana_hotel',
    'bens-cafe' => 'adf_benscafe',
    'eaat-meet' => 'adf_eat_meet',
    'eat-meet' => 'adf_eat_meet'
];

// Get active business config
$businessConfig = getActiveBusinessConfig();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Purchase Orders';

// For gudang users: will fetch from all DBs below
// For regular users: fetch from active business DB only
if (!$isGudang) {
    $resolvedBusinessDb = $businessDatabases[$activeBusinessSlug] ?? ($businessConfig['database'] ?? null);
    if (!empty($resolvedBusinessDb)) {
        Database::switchDatabase($resolvedBusinessDb);
    }
}

$db = Database::getInstance();
$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

// Inline delete action from PO list (for editable statuses)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_po') {
    $poIdToDelete = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;

    try {
        if ($poIdToDelete <= 0) {
            throw new Exception('PO tidak valid');
        }

        $poRow = $db->fetchOne('SELECT id, po_number, status FROM purchase_orders_header WHERE id = ? LIMIT 1', [$poIdToDelete]);
        if (!$poRow) {
            throw new Exception('PO tidak ditemukan');
        }

        $allowedDeleteStatuses = ['draft', 'submitted', 'approved', 'partially_received', 'cancelled', 'rejected', 'completed'];
        if (!in_array(strtolower((string)$poRow['status']), $allowedDeleteStatuses, true)) {
            throw new Exception('PO dengan status ini tidak boleh dihapus');
        }

        $conn = $db->getConnection();
        $conn->beginTransaction();
        $db->query('DELETE FROM purchase_orders_detail WHERE po_header_id = ?', [$poIdToDelete]);
        $db->query('DELETE FROM purchase_orders_header WHERE id = ?', [$poIdToDelete]);
        $conn->commit();

        $_SESSION['success'] = 'PO ' . $poRow['po_number'] . ' berhasil dihapus.';
    } catch (Throwable $e) {
        try {
            if ($db->getConnection()->inTransaction()) {
                $db->getConnection()->rollBack();
            }
        } catch (Throwable $rollbackError) {
        }
        $_SESSION['error'] = 'Gagal hapus PO: ' . $e->getMessage();
    }

    header('Location: purchase-orders.php');
    exit;
}

// Get filters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

// Get suppliers for filter
$suppliers = $db->fetchAll("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");

// Build filters
$filters = [];
if ($status) $filters['status'] = $status;
if ($supplier_id > 0) $filters['supplier_id'] = $supplier_id;
if ($date_from) $filters['date_from'] = $date_from;
if ($date_to) $filters['date_to'] = $date_to;
$filters['exclude_gdn_prefix'] = true;

// For non-Gudang (business context): strip supplier + date filters to ensure data always shows
// This prevents empty lists due to filter strictness in legacy schemas
if (!$isGudang) {
    unset($filters['supplier_id']);
    unset($filters['date_from']);
    unset($filters['date_to']);
}

// Get purchase orders
if ($isGudang) {
    // Gudang user: aggregate POs from ALL business databases
    $purchase_orders = [];
    $businessNames = [
        'adf_narayana_hotel' => 'Narayana Hotel',
        'adf_benscafe' => 'Bens Cafe',
        'adf_eat_meet' => 'Eat Meet'
    ];

    foreach ($businessDatabases as $bizSlug => $bizDb) {
        try {
            Database::switchDatabase($bizDb);
            $bizDbInstance = Database::getInstance();
            $bizName = $businessNames[$bizDb] ?? $bizSlug;
            $bizPOs = $bizDbInstance->fetchAll("
                SELECT 
                    poh.*,
                    s.supplier_name,
                    s.supplier_code,
                    u.full_name as created_by_name,
                    COUNT(pod.id) as items_count,
                    '{$bizSlug}' as source_business_slug,
                    '{$bizName}' as source_business_name
                FROM purchase_orders_header poh
                LEFT JOIN suppliers s ON poh.supplier_id = s.id
                LEFT JOIN users u ON poh.created_by = u.id
                LEFT JOIN purchase_orders_detail pod ON poh.id = pod.po_header_id
                WHERE poh.po_number NOT LIKE 'GDN-%'
                GROUP BY poh.id
                ORDER BY poh.id DESC
                LIMIT 1000
            ");
            $purchase_orders = array_merge($purchase_orders, $bizPOs ?? []);
        } catch (Throwable $e) {
            error_log("Error fetching POs from {$bizDb}: " . $e->getMessage());
        }
    }

    // Sort all combined POs by date DESC
    usort($purchase_orders, function ($a, $b) {
        return strtotime($b['po_date'] ?? '0') - strtotime($a['po_date'] ?? '0');
    });
    $purchase_orders = array_slice($purchase_orders, 0, 50);
} else {
    // Business context: use absolutely minimal query, no filters except GDN-prefix
    // This ensures data always shows regardless of schema variations
    try {
        $purchase_orders = $db->fetchAll("
            SELECT 
                poh.*,
                s.supplier_name,
                s.supplier_code,
                u.full_name as created_by_name,
                (SELECT COUNT(*) FROM purchase_orders_detail pod WHERE pod.po_header_id = poh.id) as items_count
            FROM purchase_orders_header poh
            LEFT JOIN suppliers s ON poh.supplier_id = s.id
            LEFT JOIN users u ON poh.created_by = u.id
            WHERE poh.po_number NOT LIKE 'GDN-%'
            ORDER BY poh.id DESC
            LIMIT 100
        ");

        // Hard fallback for legacy schemas: no joins, no subqueries.
        // If joins fail or return empty due schema mismatch, still show PO headers.
        if (empty($purchase_orders)) {
            $purchase_orders = $db->fetchAll("
                SELECT
                    poh.*,
                    '' as supplier_name,
                    '' as supplier_code,
                    CAST(COALESCE(poh.created_by, 0) AS CHAR) as created_by_name,
                    0 as items_count
                FROM purchase_orders_header poh
                WHERE poh.po_number NOT LIKE 'GDN-%'
                ORDER BY poh.id DESC
                LIMIT 100
            ");
        }

        // If still empty, force-fetch using slug-mapped DB to avoid any wrong active DB context.
        if (empty($purchase_orders)) {
            $forceDb = $businessDatabases[$activeBusinessSlug] ?? null;
            if (!empty($forceDb)) {
                Database::switchDatabase($forceDb);
                $forcedDb = Database::getInstance();
                $purchase_orders = $forcedDb->fetchAll("
                    SELECT
                        poh.*,
                        '' as supplier_name,
                        '' as supplier_code,
                        CAST(COALESCE(poh.created_by, 0) AS CHAR) as created_by_name,
                        0 as items_count
                    FROM purchase_orders_header poh
                    WHERE poh.po_number NOT LIKE 'GDN-%'
                    ORDER BY poh.id DESC
                    LIMIT 100
                ");
            }
        }

        if (!is_array($purchase_orders)) {
            $purchase_orders = [];
        }
    } catch (Throwable $e) {
        error_log("Business PO query error: " . $e->getMessage());
        $purchase_orders = [];
    }
}

$gudangTransferHistory = [];
$gudangTransferStats = [
    'narayana' => ['label' => 'Narayana Hotel', 'count' => 0, 'qty' => 0],
    'bens' => ['label' => 'Bens Cafe', 'count' => 0, 'qty' => 0],
    'eatmeet' => ['label' => 'Eat Meet', 'count' => 0, 'qty' => 0],
];

if ($isGudang) {
    try {
        $gudangCfgPath = __DIR__ . '/../../config/businesses/gudang-nasita.php';
        if (file_exists($gudangCfgPath)) {
            $gudangCfg = require $gudangCfgPath;
            $gudangDbName = (string)($gudangCfg['database'] ?? '');
            if ($gudangDbName !== '') {
                Database::switchDatabase($gudangDbName);
            }
        }

        $gudangTransferHistory = getGudangNasitaTransfers(120);

        foreach ($gudangTransferHistory as $t) {
            $bizNameNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)($t['target_business_name'] ?? '')));
            $qty = (float)($t['total_qty'] ?? 0);

            if (strpos($bizNameNorm, 'narayana') !== false || strpos($bizNameNorm, 'hotel') !== false) {
                $bucket = 'narayana';
            } elseif (strpos($bizNameNorm, 'bens') !== false || strpos($bizNameNorm, 'cafe') !== false) {
                $bucket = 'bens';
            } elseif (strpos($bizNameNorm, 'eatmeet') !== false || strpos($bizNameNorm, 'eaatmeet') !== false || strpos($bizNameNorm, 'eat') !== false) {
                $bucket = 'eatmeet';
            } else {
                $bucket = null;
            }

            if ($bucket !== null) {
                $gudangTransferStats[$bucket]['count']++;
                $gudangTransferStats[$bucket]['qty'] += $qty;
            }
        }
    } catch (Throwable $e) {
        error_log('purchase-orders gudang transfer history error: ' . $e->getMessage());
        $gudangTransferHistory = [];
    }
}

$forceTheme = 'light';
include '../../includes/header.php';
?>

<?php if (isset($_SESSION['success'])): ?>
    <!-- Success Popup Modal -->
    <div id="successPopup" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(4px); animation: fadeIn 0.3s ease-out;">
        <div style="background: white; border-radius: 1rem; padding: 2rem; max-width: 420px; width: 90%; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.3); animation: scaleIn 0.3s ease-out;">
            <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                <i data-feather="check" style="width: 40px; height: 40px; color: white; stroke-width: 3;"></i>
            </div>
            <h3 style="font-size: 1.5rem; font-weight: 700; color: #065f46; margin-bottom: 0.75rem;">Berhasil!</h3>
            <div style="color: #047857; font-size: 0.95rem; margin-bottom: 1.5rem; line-height: 1.6;"><?php echo $_SESSION['success'];
                                                                                                        unset($_SESSION['success']); ?></div>
            <button onclick="document.getElementById('successPopup').style.display='none'" style="background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 0.75rem 2rem; border-radius: 0.5rem; font-weight: 600; font-size: 1rem; cursor: pointer;">
                OK, Mengerti
            </button>
        </div>
    </div>
    <style>
        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }
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
                <div style="color: #b91c1c; font-size: 0.95rem;"><?php echo $_SESSION['error'];
                                                                    unset($_SESSION['error']); ?></div>
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

    /* Action Button Styles */
    .po-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }

    .po-action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .po-action-btn.view {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white !important;
    }

    .po-action-btn.submit {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white !important;
    }

    .po-action-btn.nota {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: white !important;
    }

    .po-action-btn.reject {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white !important;
    }

    .po-action-btn.update {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        color: white !important;
    }

    .po-action-btn svg {
        width: 16px;
        height: 16px;
        stroke: white !important;
    }

    .po-action-group {
        display: flex;
        gap: 0.35rem;
        justify-content: center;
        align-items: center;
    }

    .po-action-wide {
        width: auto;
        padding: 0.4rem 0.75rem;
        gap: 0.35rem;
        font-size: 0.72rem;
        font-weight: 600;
    }
</style>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                📋 Purchase Orders
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Kelola PO internal bisnis untuk proses gudang dan transfer</p>
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <a href="business-stock-incoming.php" class="btn btn-outline-secondary">
                <i data-feather="inbox" style="width: 16px; height: 16px;"></i>
                Rekaman Stock Masuk
            </a>
            <a href="business-stock-incoming.php" class="btn btn-secondary">
                <i data-feather="repeat" style="width: 16px; height: 16px;"></i>
                Transfer Stock
            </a>
            <a href="create-po.php" class="btn btn-primary">
                <i data-feather="plus" style="width: 16px; height: 16px;"></i>
                Buat PO Baru
            </a>
        </div>
    </div>
</div>

<?php if ($isGudang): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:0.9rem;">
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Riwayat Transfer Gudang (3 Bisnis)</h3>
            <span style="font-size:0.8rem; color:var(--text-muted);">History langsung ter-update setelah transfer sukses</span>
        </div>

        <div style="display:flex; gap:0.6rem; align-items:center; margin-bottom:0.95rem; flex-wrap:wrap;">
            <div style="position:relative; min-width:320px; flex:1;">
                <i data-feather="search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); width:14px; height:14px; color:#64748b;"></i>
                <input type="text" id="gudangHistorySearch" class="form-control" placeholder="Cari no transfer / bisnis tujuan / status" style="padding-left:2rem;">
            </div>
            <button type="button" class="btn btn-secondary" onclick="clearGudangHistorySearch()" style="height:38px;">Reset</button>
            <span id="gudangHistoryCounter" style="font-size:0.8rem; color:var(--text-muted);">Menampilkan <?php echo count(array_slice($gudangTransferHistory, 0, 20)); ?> data</span>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No Transfer</th>
                        <th>Bisnis Tujuan</th>
                        <th>Tanggal</th>
                        <th class="text-right">Qty</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($gudangTransferHistory)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; color:var(--text-muted); padding:1.5rem;">Belum ada riwayat transfer gudang.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach (array_slice($gudangTransferHistory, 0, 20) as $t): ?>
                            <?php
                            $transferNo = (string)($t['transfer_number'] ?? $t['no_transfer'] ?? '-');
                            $targetBiz = (string)($t['target_business_name'] ?? '-');
                            $statusText = strtoupper((string)($t['status'] ?? '-'));
                            $dateText = !empty($t['created_at']) ? date('d M Y H:i', strtotime((string)$t['created_at'])) : '-';
                            $searchText = strtolower(trim($transferNo . ' ' . $targetBiz . ' ' . $statusText . ' ' . $dateText));
                            $transferItems = $db->fetchAll(
                                'SELECT item_name, unit, quantity, unit_price, subtotal FROM gudang_nasita_transfer_items WHERE transfer_id = ? ORDER BY id ASC',
                                [(int)($t['id'] ?? 0)]
                            ) ?: [];
                            ?>
                            <tr class="gudang-history-row" data-search="<?php echo htmlspecialchars($searchText); ?>" style="cursor:pointer;"
                                data-transfer-no="<?php echo htmlspecialchars($transferNo); ?>"
                                data-biz="<?php echo htmlspecialchars($targetBiz); ?>"
                                data-date="<?php echo htmlspecialchars($dateText); ?>"
                                data-status="<?php echo htmlspecialchars($statusText); ?>"
                                data-items="<?php echo htmlspecialchars(json_encode($transferItems), ENT_QUOTES); ?>"
                                onclick="openGudangTransferDetail(this)">
                                <td style="font-weight:700;"><?php echo htmlspecialchars((string)($t['transfer_number'] ?? $t['no_transfer'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($t['target_business_name'] ?? '-')); ?></td>
                                <td><?php echo !empty($t['created_at']) ? date('d M Y H:i', strtotime((string)$t['created_at'])) : '-'; ?></td>
                                <td class="text-right" style="font-weight:700;"><?php echo number_format((float)($t['total_qty'] ?? 0), 2); ?></td>
                                <td class="text-center"><span class="badge badge-secondary"><?php echo strtoupper((string)($t['status'] ?? '-')); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Detail transfer gudang modal -->
<div id="gudangTransferDetailModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,0.55); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:0.9rem; width:92%; max-width:520px; max-height:82vh; overflow-y:auto; box-shadow:0 20px 50px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid #e2e8f0;">
            <div>
                <div style="font-weight:800; font-size:1rem;" id="gtdTransferNo">-</div>
                <div style="font-size:0.8rem; color:var(--text-muted);" id="gtdMeta">-</div>
            </div>
            <button type="button" onclick="closeGudangTransferDetail()" style="background:none; border:none; font-size:1.3rem; line-height:1; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="padding:1rem 1.25rem;">
            <table class="table" style="width:100%; font-size:0.85rem;">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Harga</th>
                        <th class="text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody id="gtdItemsBody">
                    <tr>
                        <td colspan="4" style="text-align:center; color:var(--text-muted); padding:1rem;">Memuat...</td>
                    </tr>
                </tbody>
            </table>
            <div style="display:flex; justify-content:flex-end; gap:0.75rem; align-items:center; padding-top:0.75rem; border-top:1px solid #e2e8f0; margin-top:0.5rem;">
                <span style="font-size:0.85rem; font-weight:700; color:var(--text-muted);">Total Tagihan</span>
                <span id="gtdTotal" style="font-size:1.05rem; font-weight:800;">Rp 0</span>
            </div>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:0.6rem; padding:0.9rem 1.25rem; border-top:1px solid #e2e8f0;">
            <button type="button" onclick="closeGudangTransferDetail()" class="btn btn-secondary">Tutup</button>
            <button type="button" onclick="printGudangTransferDetail()" class="btn btn-primary">
                <i data-feather="printer" style="width:16px; height:16px;"></i> Print
            </button>
        </div>
    </div>
</div>

<?php if (!$isGudang): ?>
    <!-- Filter Section -->
    <div class="card" style="margin-bottom: 1.25rem;">
        <form method="GET" style="display: grid; grid-template-columns: repeat(3, 1fr) auto; gap: 1rem; align-items: end;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">Semua Status</option>
                    <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="submitted" <?php echo $status === 'submitted' ? 'selected' : ''; ?>>Menunggu Proses Gudang</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Disiapkan Gudang</option>
                    <option value="partially_received" <?php echo $status === 'partially_received' ? 'selected' : ''; ?>>Partially Received</option>
                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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

            <button type="submit" class="btn btn-primary" style="height: 42px;">
                <i data-feather="filter" style="width: 16px; height: 16px;"></i> Filter
            </button>
        </form>
    </div>

    <!-- Statistics Cards -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
        <?php
        $stats = [
            'draft' => ['label' => 'Draft', 'color' => '#6366f1', 'icon' => 'edit-3'],
            'submitted' => ['label' => 'Menunggu Proses Gudang', 'color' => '#f59e0b', 'icon' => 'clock'],
            'completed' => ['label' => 'Selesai', 'color' => '#10b981', 'icon' => 'check-circle']
        ];

        foreach ($stats as $stat_status => $stat_data) {
            $count = count(array_filter($purchase_orders, function ($po) use ($stat_status) {
                return $po['status'] === $stat_status;
            }));
            $total = array_sum(array_map(function ($po) use ($stat_status) {
                return $po['status'] === $stat_status ? ($po['total_amount'] ?? 0) : 0;
            }, $purchase_orders));
        ?>
            <div class="card" style="padding: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div style="width: 40px; height: 40px; border-radius: 8px; background: <?php echo $stat_data['color']; ?>20; display: flex; align-items: center; justify-content: center;">
                        <i data-feather="<?php echo $stat_data['icon']; ?>" style="width: 20px; height: 20px; color: <?php echo $stat_data['color']; ?>;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $stat_data['label']; ?></div>
                        <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);"><?php echo $count; ?></div>
                        <div style="font-size: 0.688rem; color: var(--text-muted);">Rp <?php echo number_format($total, 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>

    <!-- Purchase Orders Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <?php if ($isGudang): ?>
                            <th>Bisnis</th>
                        <?php endif; ?>
                        <th>Tanggal</th>
                        <th>Tujuan</th>
                        <th>Status</th>
                        <th>Items</th>
                        <th class="text-right">Total</th>
                        <th>Dibuat Oleh</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchase_orders)): ?>
                        <tr>
                            <td colspan="<?php echo $isGudang ? 9 : 8; ?>" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                                <i data-feather="inbox" style="width: 48px; height: 48px; opacity: 0.3; margin-bottom: 1rem;"></i>
                                <p>Tidak ada purchase order</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchase_orders as $po): ?>
                            <tr>
                                <td style="font-weight: 600; color: var(--primary-color);">
                                    <?php echo $po['po_number']; ?>
                                </td>
                                <?php if ($isGudang): ?>
                                    <td style="font-weight: 600; font-size: 0.9rem; color: #6b7280;">
                                        <?php echo htmlspecialchars($po['source_business_name'] ?? $po['source_business_slug'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                <?php endif; ?>
                                <td><?php echo date('d M Y', strtotime($po['po_date'])); ?></td>
                                <td>
                                    <div style="font-weight: 600;">Gudang Nasita (Internal)</div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Tanpa supplier eksternal</div>
                                </td>
                                <td>
                                    <?php
                                    $status_colors = [
                                        'draft' => 'secondary',
                                        'submitted' => 'warning',
                                        'approved' => 'success',
                                        'rejected' => 'danger',
                                        'partially_received' => 'info',
                                        'completed' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                    $status_labels = [
                                        'draft' => 'Draft',
                                        'submitted' => '🕐 Menunggu Proses Gudang',
                                        'approved' => 'Disiapkan Gudang',
                                        'completed' => '✓ Selesai',
                                        'rejected' => 'Rejected',
                                        'cancelled' => 'Cancelled'
                                    ];
                                    $badge_color = $status_colors[$po['status']] ?? 'secondary';
                                    $badge_label = $status_labels[$po['status']] ?? ucfirst($po['status']);
                                    ?>
                                    <span class="badge badge-<?php echo $badge_color; ?>" style="font-size: 0.875rem;">
                                        <?php echo $badge_label; ?>
                                    </span>
                                </td>
                                <td><?php echo $po['items_count']; ?> items</td>
                                <td class="text-right" style="font-weight: 700; color: var(--text-primary);">
                                    Rp <?php echo number_format($po['total_amount'] ?? 0, 0, ',', '.'); ?>
                                </td>
                                <td style="font-size: 0.813rem;"><?php echo $po['created_by_name']; ?></td>
                                <td>
                                    <div class="po-action-group">
                                        <a href="<?php
                                                    $viewTarget = 'view-po.php?id=' . (int)$po['id'];
                                                    if (!empty($po['source_business_slug'])) {
                                                        $viewTarget .= '&po_business=' . urlencode((string)$po['source_business_slug']);
                                                    }
                                                    echo $viewTarget;
                                                    ?>" class="po-action-btn view" title="Lihat PO">
                                            <i data-feather="eye"></i>
                                        </a>

                                        <?php if (in_array(strtolower((string)$po['status']), ['draft', 'submitted'], true)): ?>
                                            <a href="view-po.php?id=<?php echo (int)$po['id']; ?><?php echo !empty($po['source_business_slug']) ? '&po_business=' . urlencode((string)$po['source_business_slug']) : ''; ?>&edit=1" class="po-action-btn submit" title="Edit PO">
                                                <i data-feather="edit-3"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($po['status'] === 'draft'): ?>
                                            <form method="POST" action="submit-po.php" style="display: inline;">
                                                <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
                                                <button type="submit" class="po-action-btn submit" title="Submit PO" onclick="return confirm('Submit PO ini?')">
                                                    <i data-feather="send"></i>
                                                </button>
                                            </form>
                                        <?php elseif ($po['status'] === 'submitted' && $isGudang): ?>
                                            <a href="gudang-transfer.php?po_id=<?php echo (int)$po['id']; ?><?php echo !empty($po['source_business_slug']) ? '&po_business=' . urlencode((string)$po['source_business_slug']) : ''; ?>" class="po-action-btn po-action-wide submit" title="Siapkan Transfer Gudang">
                                                <i data-feather="send"></i> Siapkan Transfer
                                            </a>
                                        <?php elseif ($po['status'] === 'submitted'): ?>
                                            <span class="badge badge-warning" style="font-size:0.75rem;">Menunggu Gudang</span>
                                        <?php elseif ($po['status'] === 'completed'): ?>
                                            <span class="badge badge-success">Transfer Selesai</span>
                                        <?php endif; ?>

                                        <?php if (in_array(strtolower((string)$po['status']), ['draft', 'submitted', 'approved', 'partially_received', 'cancelled', 'rejected', 'completed'], true)): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus PO ini permanen?')">
                                                <input type="hidden" name="action" value="delete_po">
                                                <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                                                <button type="submit" class="po-action-btn reject" title="Hapus PO">
                                                    <i data-feather="trash-2"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Approve Modal -->
<div id="approveModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; backdrop-filter: blur(8px);">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 600px; width: 90%; overflow: hidden;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 1.75rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.5rem; font-weight: 700;">
                    <i data-feather="check-circle" style="width: 24px; height: 24px; vertical-align: middle; margin-right: 0.5rem;"></i>
                    Approve & Bayar PO
                </h3>
                <p style="margin: 0.5rem 0 0 0; font-size: 0.875rem; opacity: 0.95;" id="modalSubtitle"></p>
            </div>
            <button type="button" onclick="closeApproveModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 1.75rem; width: 36px; height: 36px; border-radius: 0.5rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="approve-purchase.php" enctype="multipart/form-data" id="approveForm">
            <div style="padding: 2rem;">
                <input type="hidden" name="approve" value="1">
                <input type="hidden" name="po_id" id="modalPoId">

                <!-- Info PO -->
                <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-left: 4px solid #10b981; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                        <div style="background: white; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(16,185,129,0.15);">
                            <i data-feather="dollar-sign" style="width: 24px; height: 24px; color: #10b981;"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.25rem;">Total Pembayaran</div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;" id="modalAmount"></div>
                        </div>
                    </div>
                </div>

                <!-- Warning -->
                <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 4px solid #f59e0b; border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1.5rem;">
                    <div style="display: flex; gap: 1rem;">
                        <div style="flex-shrink: 0;">
                            <div style="width: 32px; height: 32px; background: #f59e0b; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.25rem;">!</div>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 700; color: #92400e; margin-bottom: 0.75rem; font-size: 1rem;">⚠️ Upload Nota Wajib</div>
                            <p style="margin: 0; color: #92400e; line-height: 1.6; font-size: 0.875rem;">
                                Silakan pilih file nota/invoice dari supplier. Setelah upload, PO akan otomatis di-approve dan pembayaran dicatat ke Kas Besar.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- File Upload -->
                <div style="background: #f9fafb; border: 2px dashed #d1d5db; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem; text-align: center; cursor: pointer;" onclick="document.getElementById('notaFile').click();">
                    <div style="margin-bottom: 1rem;">
                        <i data-feather="upload-cloud" style="width: 48px; height: 48px; color: #6b7280;"></i>
                    </div>
                    <div style="font-weight: 600; color: #374151; margin-bottom: 0.5rem;">
                        Klik untuk Upload Nota/Invoice
                    </div>
                    <div style="font-size: 0.875rem; color: #6b7280;">
                        JPG, PNG, PDF (Max 5MB)
                    </div>
                    <input type="file" name="nota_image" id="notaFile" accept="image/jpeg,image/png,image/jpg,application/pdf" required style="display: none;" onchange="updateFileName(this)">
                    <div id="fileName" style="margin-top: 1rem; font-size: 0.875rem; color: #10b981; font-weight: 600;"></div>
                </div>

                <!-- Buttons -->
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" onclick="closeApproveModal()" class="btn btn-secondary" style="padding: 0.875rem 1.75rem; font-size: 1rem; font-weight: 600;">
                        <i data-feather="x" style="width: 18px; height: 18px; vertical-align: middle; margin-right: 0.5rem;"></i>
                        Batal
                    </button>
                    <button type="submit" class="btn btn-success" style="padding: 0.875rem 2rem; font-size: 1rem; font-weight: 600; background: linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16,185,129,0.3);">
                        <i data-feather="check" style="width: 18px; height: 18px; vertical-align: middle; margin-right: 0.5rem;"></i>
                        Ya, Approve & Bayar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; backdrop-filter: blur(8px);">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
        <div style="margin-bottom: 2rem;">
            <div class="spinner-border" style="width: 80px; height: 80px; border: 6px solid rgba(16,185,129,0.2); border-top-color: #10b981; animation: spin 1s linear infinite;"></div>
        </div>

        <div style="background: white; padding: 2.5rem 3rem; border-radius: 1.5rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); min-width: 400px;">
            <div style="font-size: 1.25rem; font-weight: 700; color: #1f2937; margin-bottom: 1rem;">⏳ Processing...</div>
            <div style="font-size: 0.875rem; color: #6b7280;">Mengupload nota dan approve PO</div>
            <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #f3f4f6; text-align: center;">
                <div style="font-size: 0.875rem; color: #10b981; font-weight: 600;">
                    Mohon tunggu, jangan tutup halaman ini
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

<script>
    feather.replace();

    function normalizeGudangHistoryTerm(value) {
        return String(value || '').toLowerCase().trim().replace(/\s+/g, ' ');
    }

    function filterGudangHistoryRows() {
        const input = document.getElementById('gudangHistorySearch');
        const rows = document.querySelectorAll('.gudang-history-row');
        const counter = document.getElementById('gudangHistoryCounter');
        if (!input || !rows.length) return;

        const term = normalizeGudangHistoryTerm(input.value);
        let shown = 0;

        rows.forEach((row) => {
            const hay = String(row.getAttribute('data-search') || '').toLowerCase();
            const match = term === '' || hay.includes(term);
            row.style.display = match ? '' : 'none';
            if (match) shown++;
        });

        if (counter) {
            counter.textContent = 'Menampilkan ' + shown + ' data';
        }
    }

    function clearGudangHistorySearch() {
        const input = document.getElementById('gudangHistorySearch');
        if (!input) return;
        input.value = '';
        filterGudangHistoryRows();
        input.focus();
    }

    (function bindGudangHistorySearch() {
        const input = document.getElementById('gudangHistorySearch');
        if (!input) return;
        input.addEventListener('input', filterGudangHistoryRows);
        filterGudangHistoryRows();
    })();

    function openGudangTransferDetail(rowEl) {
        const escapeHtml = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        } [c]));

        const transferNo = rowEl.getAttribute('data-transfer-no') || '-';
        const biz = rowEl.getAttribute('data-biz') || '-';
        const date = rowEl.getAttribute('data-date') || '-';
        const status = rowEl.getAttribute('data-status') || '-';
        let items = [];
        try {
            items = JSON.parse(rowEl.getAttribute('data-items') || '[]');
        } catch (e) {
            items = [];
        }

        document.getElementById('gtdTransferNo').textContent = transferNo;
        document.getElementById('gtdMeta').textContent = biz + ' • ' + date + ' • ' + status;

        const body = document.getElementById('gtdItemsBody');
        let grandTotal = 0;
        if (!items.length) {
            body.innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:1rem;">Tidak ada detail item untuk transfer ini.</td></tr>';
        } else {
            body.innerHTML = items.map(it => {
                const qty = parseFloat(it.quantity || 0).toLocaleString('id-ID', {
                    minimumFractionDigits: 2
                });
                const price = parseFloat(it.unit_price || 0);
                const subtotal = parseFloat(it.subtotal || (price * parseFloat(it.quantity || 0)));
                grandTotal += subtotal;
                return '<tr>' +
                    '<td>' + escapeHtml(it.item_name || '-') + '</td>' +
                    '<td class="text-right">' + qty + ' ' + escapeHtml(it.unit || '') + '</td>' +
                    '<td class="text-right">Rp ' + price.toLocaleString('id-ID') + '</td>' +
                    '<td class="text-right">Rp ' + subtotal.toLocaleString('id-ID') + '</td>' +
                    '</tr>';
            }).join('');
        }

        document.getElementById('gtdTotal').textContent = 'Rp ' + grandTotal.toLocaleString('id-ID');
        document.getElementById('gudangTransferDetailModal').style.display = 'flex';
    }

    function closeGudangTransferDetail() {
        document.getElementById('gudangTransferDetailModal').style.display = 'none';
    }

    function printGudangTransferDetail() {
        const transferNo = document.getElementById('gtdTransferNo').textContent;
        const meta = document.getElementById('gtdMeta').textContent;
        const total = document.getElementById('gtdTotal').textContent;
        const itemsHtml = document.getElementById('gtdItemsBody').innerHTML;
        const printedAt = new Date().toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

        const printWindow = window.open('', '_blank', 'width=700,height=900');
        printWindow.document.write(
            '<html><head><title>Transfer ' + transferNo + '</title>' +
            '<style>' +
            '*{box-sizing:border-box;}' +
            'body{font-family:Arial,Helvetica,sans-serif; padding:16px 20px; color:#0f172a; font-size:11px;}' +
            'h2{margin:0 0 2px; font-size:14px;} p{margin:0 0 10px; color:#475569; font-size:10.5px;}' +
            'table{width:100%; border-collapse:collapse; font-size:10.5px;}' +
            'th,td{padding:4px 6px; border-bottom:1px solid #e2e8f0; text-align:left;}' +
            'th{background:#f1f5f9; font-size:10px; text-transform:uppercase; letter-spacing:0.3px;}' +
            '.text-right{text-align:right;}' +
            '.total-row td{font-weight:800; border-top:2px solid #0f172a; border-bottom:none; padding-top:8px; font-size:11px;}' +
            'footer{margin-top:18px; padding-top:8px; border-top:1px solid #e2e8f0; font-size:9px; color:#94a3b8; text-align:center;}' +
            '</style></head><body>' +
            '<h2>Riwayat Transfer Gudang</h2>' +
            '<p>' + transferNo + ' &middot; ' + meta + '</p>' +
            '<table><thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th></tr></thead>' +
            '<tbody>' + itemsHtml + '</tbody>' +
            '<tfoot><tr class="total-row"><td colspan="3" class="text-right">Total Tagihan</td><td class="text-right">' + total + '</td></tr></tfoot>' +
            '</table>' +
            '<footer>Dokumen ini dicetak otomatis dari ADF System pada ' + printedAt + '. Sah tanpa tanda tangan basah.</footer>' +
            '</body></html>'
        );
        printWindow.document.close();
        printWindow.focus();
        printWindow.onload = function () {
            printWindow.print();
        };
    }

    function openApproveDialog(poId, poNumber, amount) {
        document.getElementById('modalPoId').value = poId;
        document.getElementById('modalSubtitle').textContent = poNumber;
        document.getElementById('modalAmount').textContent = 'Rp ' + amount.toLocaleString('id-ID');
        document.getElementById('fileName').textContent = '';
        document.getElementById('notaFile').value = '';
        document.getElementById('approveModal').style.display = 'block';

        // Auto-open file picker
        setTimeout(() => {
            document.getElementById('notaFile').click();
        }, 300);

        feather.replace();
    }

    function closeApproveModal() {
        document.getElementById('approveModal').style.display = 'none';
    }

    function updateFileName(input) {
        if (input.files && input.files[0]) {
            const fileName = input.files[0].name;
            const fileSize = (input.files[0].size / 1024 / 1024).toFixed(2);
            document.getElementById('fileName').textContent = '✓ ' + fileName + ' (' + fileSize + ' MB)';
        }
    }

    document.getElementById('approveForm').addEventListener('submit', function(e) {
        const fileInput = document.getElementById('notaFile');
        if (!fileInput.files || fileInput.files.length === 0) {
            e.preventDefault();
            alert('❌ Harap upload nota terlebih dahulu!');
            return false;
        }

        // Show loading
        document.getElementById('loadingOverlay').style.display = 'block';
    });

    function rejectPO(poId, poNumber) {
        console.log('rejectPO called with:', poId, poNumber);

        if (confirm('⚠️ PERHATIAN!\n\nApakah Anda yakin ingin REJECT dan HAPUS PO ' + poNumber + '?\n\nData PO ini akan dihapus permanen!')) {
            console.log('User confirmed, redirecting...');

            // Redirect dengan GET parameter untuk lebih reliable
            window.location.href = 'reject-po.php?po_id=' + poId + '&confirm=1';
        } else {
            console.log('User cancelled');
        }
    }
</script>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>