<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse_transfers') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses transfer Gudang Nasita ditolak.';
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Transfer Gudang Nasita';

function normalizeGudangStockName($value)
{
    $normalized = trim((string)$value);
    $normalized = mb_strtolower($normalized, 'UTF-8');
    $normalized = preg_replace('/[\p{Z}\p{P}]+/u', ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return trim($normalized);
}

function findExactGudangStockMatch($db, $poItemName)
{
    $targetName = normalizeGudangStockName($poItemName);
    if ($targetName === '') {
        return null;
    }

    $stockRows = $db->fetchAll('SELECT * FROM gudang_nasita_stock WHERE COALESCE(is_active,1) = 1 ORDER BY item_name ASC');
    foreach ($stockRows as $row) {
        $candidateName = normalizeGudangStockName($row['item_name'] ?? '');
        if ($candidateName !== '' && $candidateName === $targetName) {
            return $row;
        }
    }

    return null;
}

$message = '';
$messageType = 'success';

$prefillPoId = (int)($_GET['po_id'] ?? 0);
$prefillPoBusinessSlug = trim((string)($_GET['po_business'] ?? ''));
$prefillStockId = (int)($_GET['stock_id'] ?? 0);
$prefillQty = (float)($_GET['qty'] ?? 0);
$prefillTargetBusinessId = 0;
$prefillTargetBusinessName = '';
$prefillNotes = '';
$allowedPoBusinessSlugs = ['narayana-hotel', 'bens-cafe', 'eaat-meet', 'eat-meet'];
if (!in_array($prefillPoBusinessSlug, $allowedPoBusinessSlugs, true)) {
    $prefillPoBusinessSlug = '';
}

$resolvePoContext = function (int $poId, string $poBusinessSlug = '') use ($db, $allowedPoBusinessSlugs) {
    $originDbName = Database::getCurrentDatabase();
    $resolved = [
        'row' => null,
        'business_slug' => $poBusinessSlug,
        'business_name' => '',
    ];

    try {
        if ($poBusinessSlug !== '' && in_array($poBusinessSlug, $allowedPoBusinessSlugs, true)) {
            $cfgPath = __DIR__ . '/../../config/businesses/' . $poBusinessSlug . '.php';
            if (file_exists($cfgPath)) {
                $cfg = require $cfgPath;
                $bizDbName = (string)($cfg['database'] ?? '');
                if ($bizDbName !== '') {
                    $bizDb = Database::switchDatabase($bizDbName);
                    $resolved['row'] = $bizDb->fetchOne("\n                        SELECT poh.id, poh.po_number, poh.business_id, b.business_name, b.business_code\n                        FROM purchase_orders_header poh\n                        LEFT JOIN businesses b ON b.id = poh.business_id\n                        WHERE poh.id = ?\n                        LIMIT 1\n                    ", [$poId]);
                    $resolved['business_name'] = (string)($cfg['name'] ?? '');
                }
            }
        }

        if (!$resolved['row']) {
            $resolved['row'] = $db->fetchOne("\n                SELECT poh.id, poh.po_number, poh.business_id, b.business_name, b.business_code\n                FROM purchase_orders_header poh\n                LEFT JOIN businesses b ON b.id = poh.business_id\n                WHERE poh.id = ?\n                LIMIT 1\n            ", [$poId]);
            $resolved['business_slug'] = $resolved['business_slug'] ?: '';
        }
    } catch (Throwable $e) {
        error_log('Gudang transfer resolve PO error: ' . $e->getMessage());
    }

    if (!empty($originDbName)) {
        try {
            Database::switchDatabase($originDbName);
        } catch (Throwable $e) {
        }
    }

    return $resolved;
};

if ($prefillPoId > 0) {
    $resolvedPo = $resolvePoContext($prefillPoId, $prefillPoBusinessSlug);
    $poRow = $resolvedPo['row'];
    if ($poRow) {
        // Do NOT use business_id from cross-DB context — IDs differ between databases.
        // Only take business name from config and PO number for notes.
        if ($prefillTargetBusinessName === '') {
            $prefillTargetBusinessName = trim((string)($resolvedPo['business_name'] ?? ''));
        }
        $prefillNotes = 'Proses PO ' . $poRow['po_number'];
    }
}

if ($prefillTargetBusinessId > 0 && $prefillTargetBusinessName === '') {
    $bizById = $db->fetchOne("SELECT id, business_name FROM businesses WHERE id = ? LIMIT 1", [$prefillTargetBusinessId]);
    if ($bizById && !empty($bizById['business_name'])) {
        $prefillTargetBusinessName = trim((string)$bizById['business_name']);
    }
}

$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

// PENTING: Pastikan di master DB sebelum query businesses
// (bisa terjadi database context switch di autoload atau includes sebelumnya)
$masterDb = Database::switchDatabase(DB_NAME);
$allBusinesses = [];
$businessQueries = [
    "SELECT id, business_name, business_code FROM businesses WHERE (is_active = 1 OR is_active IS NULL) ORDER BY business_name ASC",
    "SELECT id, business_name, business_code FROM businesses ORDER BY business_name ASC",
    "SELECT id, business_name, slug AS business_code FROM businesses WHERE (is_active = 1 OR is_active IS NULL) ORDER BY business_name ASC",
    "SELECT id, business_name, slug AS business_code FROM businesses ORDER BY business_name ASC",
    "SELECT id, business_name, '' AS business_code FROM businesses WHERE (is_active = 1 OR is_active IS NULL) ORDER BY business_name ASC",
    "SELECT id, business_name, '' AS business_code FROM businesses ORDER BY business_name ASC",
];
foreach ($businessQueries as $q) {
    try {
        $allBusinesses = $masterDb->fetchAll($q);
        if (!empty($allBusinesses)) {
            break;
        }
    } catch (Throwable $e) {
        error_log('[gudang-transfer] business query failed: ' . $e->getMessage() . ' | SQL=' . $q);
    }
}

// FILTER: Hanya ambil 3 bisnis yang diizinkan
$normalizeBizToken = function ($value) {
    return strtolower(preg_replace('/[^a-z0-9]/', '', (string)$value));
};
$allowedBizTokens = ['narayanahotel', 'benscafe', 'eatmeet', 'eaatmeet'];
$allBusinesses = array_values(array_filter($allBusinesses, function ($biz) use ($allowedBizTokens, $normalizeBizToken) {
    $codeToken = $normalizeBizToken($biz['business_code'] ?? '');
    $nameToken = $normalizeBizToken($biz['business_name'] ?? '');

    if (in_array($codeToken, $allowedBizTokens, true)) {
        return true;
    }

    foreach ($allowedBizTokens as $token) {
        if ($nameToken !== '' && (strpos($nameToken, $token) !== false || strpos($token, $nameToken) !== false)) {
            return true;
        }
    }

    return false;
}));
// Re-order by business_name
usort($allBusinesses, fn($a, $b) => strcmp($a['business_name'], $b['business_name']));

// Fallback: when businesses table query/filter returns empty, build options from business configs.
if (empty($allBusinesses)) {
    $fallbackBusinesses = [];
    $fallbackId = 900000;

    foreach ($allowedPoBusinessSlugs as $slug) {
        $cfgPath = __DIR__ . '/../../config/businesses/' . $slug . '.php';
        if (!file_exists($cfgPath)) {
            continue;
        }

        $cfg = require $cfgPath;
        $bizName = trim((string)($cfg['name'] ?? $slug));
        $bizId = 0;
        if (function_exists('getNumericBusinessId')) {
            $bizId = (int)getNumericBusinessId($slug);
        }
        if ($bizId <= 0) {
            $bizId = $fallbackId;
            $fallbackId++;
        }

        $fallbackBusinesses[] = [
            'id' => $bizId,
            'business_name' => $bizName,
            'business_code' => $slug,
        ];
    }

    $allBusinesses = $fallbackBusinesses;
}

// DEBUG: Log business loading
error_log('[gudang-transfer] Loaded ' . count($allBusinesses) . ' businesses: ' . json_encode(array_map(fn($b) => ['id' => $b['id'], 'name' => $b['business_name']], $allBusinesses)));

// Gunakan bisnis yang sudah difilter untuk dropdown transfer
$allowedBusinesses = $allBusinesses;

$allowedBusinessesById = [];
foreach ($allowedBusinesses as $biz) {
    $allowedBusinessesById[(string)$biz['id']] = $biz;
}

// Update prefillTargetBusinessName jika ada prefillTargetBusinessId
foreach ($allowedBusinesses as $biz) {
    if ($prefillTargetBusinessId > 0 && (int)$biz['id'] === $prefillTargetBusinessId) {
        $prefillTargetBusinessName = (string)$biz['business_name'];
    }
}

// Resolve target business from PO slug using already-loaded businesses (avoids stale DB closure issues)
$findBusinessBySlug = function (string $slug) use ($allBusinesses) {
    $slugNorm = preg_replace('/[^a-z0-9]/', '', strtolower($slug));
    if ($slugNorm === '') {
        return [0, ''];
    }
    foreach ($allBusinesses as $biz) {
        $codeNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)($biz['business_code'] ?? '')));
        $nameNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)($biz['business_name'] ?? '')));
        if ($slugNorm === $codeNorm || strpos($nameNorm, $slugNorm) !== false || strpos($slugNorm, $nameNorm) !== false) {
            return [(int)$biz['id'], (string)$biz['business_name']];
        }
    }
    return [0, ''];
};

// When po_business is given, ALWAYS resolve from narayana's businesses table (slug is reliable, cross-DB IDs are not)
if ($prefillPoId > 0 && $prefillPoBusinessSlug !== '') {
    [$prefillTargetBusinessId, $resolvedName] = $findBusinessBySlug($prefillPoBusinessSlug);
    if ($prefillTargetBusinessName === '' && $resolvedName !== '') {
        $prefillTargetBusinessName = $resolvedName;
    }
} elseif ($prefillPoId > 0 && $prefillTargetBusinessId <= 0 && $prefillPoBusinessSlug !== '') {
    [$prefillTargetBusinessId, $resolvedName] = $findBusinessBySlug($prefillPoBusinessSlug);
    if ($prefillTargetBusinessName === '' && $resolvedName !== '') {
        $prefillTargetBusinessName = $resolvedName;
    }
}
// Load display name from config file as final fallback
if ($prefillPoId > 0 && $prefillTargetBusinessId > 0 && $prefillTargetBusinessName === '') {
    $bizCfgPath = __DIR__ . '/../../config/businesses/' . $prefillPoBusinessSlug . '.php';
    if ($prefillPoBusinessSlug !== '' && file_exists($bizCfgPath)) {
        $bizCfg = require $bizCfgPath;
        $prefillTargetBusinessName = (string)($bizCfg['name'] ?? '');
    }
}

// Load PO items from source business DB and match with gudang stock
$poData = null;
$poItemsWithStock = [];
if ($prefillPoId > 0 && $prefillPoBusinessSlug !== '') {
    $bizCfgPath2 = __DIR__ . '/../../config/businesses/' . $prefillPoBusinessSlug . '.php';
    if (file_exists($bizCfgPath2)) {
        $bizCfgData2 = require $bizCfgPath2;
        $bizDbName2 = (string)($bizCfgData2['database'] ?? '');
        if ($bizDbName2 !== '') {
            try {
                $originDbName2 = Database::getCurrentDatabase();
                $bizDb2 = Database::switchDatabase($bizDbName2);
                $poHeader2 = $bizDb2->fetchOne('SELECT * FROM purchase_orders_header WHERE id = ? LIMIT 1', [$prefillPoId]);
                if ($poHeader2) {
                    $poDetails2 = $bizDb2->fetchAll('SELECT pod.* FROM purchase_orders_detail pod WHERE pod.po_header_id = ? ORDER BY pod.id', [$prefillPoId]);
                    $poHeader2['items'] = $poDetails2;
                    $poData = $poHeader2;
                }
                if (!empty($originDbName2)) {
                    Database::switchDatabase($originDbName2);
                    $db = Database::getInstance();
                }
            } catch (Throwable $e) {
                error_log('gudang-transfer load PO items error: ' . $e->getMessage());
            }
        }
    }
    if ($poData && !empty($poData['items'])) {
        foreach ($poData['items'] as $poItem) {
            $pItemName = trim((string)($poItem['item_name'] ?? ''));
            $pUnit = trim((string)($poItem['unit'] ?? 'pcs'));
            $pOrdered = (float)($poItem['quantity'] ?? 0);
            $pReceived = (float)($poItem['received_quantity'] ?? 0);
            $pRemaining = max(0, $pOrdered - $pReceived);

            // IMPORTANT: only match on the exact normalized item name. Fuzzy/LIKE matching can
            // silently pick a different item such as "Vibe Rum" when the PO requested "Vibe Ram".
            $gStock = findExactGudangStockMatch($db, $pItemName);

            $poItemsWithStock[] = [
                'po_detail_id' => (int)($poItem['id'] ?? 0),
                'item_name'    => $pItemName,
                'unit'         => $pUnit,
                'ordered_qty'  => $pOrdered,
                'received_qty' => $pReceived,
                'remaining_qty' => $pRemaining,
                'gudang_stock' => $gStock ?: null,
                'gudang_stock_id' => $gStock ? (int)($gStock['id'] ?? 0) : 0,
                'gudang_stock_code' => $gStock['stock_code'] ?? null,
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetBusinessId = (int)($_POST['target_business_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $sourcePoId = (int)($_POST['source_po_id'] ?? 0);
    $sourcePoBusinessSlug = trim((string)($_POST['source_po_business'] ?? ''));
    if (!in_array($sourcePoBusinessSlug, $allowedPoBusinessSlugs, true)) {
        $sourcePoBusinessSlug = '';
    }
    $redirectBase = 'gudang-transfer.php' . ($sourcePoId > 0 ? '?po_id=' . $sourcePoId . '&po_business=' . urlencode($sourcePoBusinessSlug) : '');

    // Build items array — support both multi-item (PO mode) and single-item (manual mode)
    $transferItems = [];
    if (!empty($_POST['transfer_items']) && is_array($_POST['transfer_items'])) {
        foreach ($_POST['transfer_items'] as $tItem) {
            $tStockId = (int)($tItem['stock_id'] ?? 0);
            $tQty = (float)($tItem['qty'] ?? 0);
            $tPoDetailId = (int)($tItem['po_detail_id'] ?? 0);
            if ($tStockId > 0 && $tQty > 0) {
                $transferItems[] = ['stock_id' => $tStockId, 'quantity' => $tQty, 'po_detail_id' => $tPoDetailId, 'notes' => $notes];
            }
        }
    } else {
        $stockId = (int)($_POST['stock_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);
        if ($stockId > 0 && $quantity > 0) {
            $transferItems[] = ['stock_id' => $stockId, 'quantity' => $quantity, 'notes' => $notes];
        }
    }
    if (empty($transferItems)) {
        $sep = strpos($redirectBase, '?') !== false ? '&' : '?';
        header('Location: ' . $redirectBase . $sep . 'transfer_err=' . urlencode('Tidak ada item transfer yang valid. Isi qty minimal 1 item.'));
        exit;
    }

    // Resolve target business name + ID from current gudang/master businesses table.
    // Never trust cross-DB IDs because each business database can have different numeric IDs.
    $resolvedBizName = '';
    $resolvedBizId = $targetBusinessId;

    // Manual mode can rely on dropdown value directly; keep a readable target name.
    if ($targetBusinessId > 0) {
        $selectedBiz = $allowedBusinessesById[(string)$targetBusinessId] ?? null;
        if ($selectedBiz) {
            $resolvedBizName = (string)($selectedBiz['business_name'] ?? '');
        }
    }

    if ($sourcePoBusinessSlug !== '') {
        $bizCfgPath = __DIR__ . '/../../config/businesses/' . $sourcePoBusinessSlug . '.php';
        if (file_exists($bizCfgPath)) {
            $bizCfgData = require $bizCfgPath;
            $resolvedBizName = (string)($bizCfgData['name'] ?? '');
        }
    }

    // Fallback: resolve from already-loaded businesses list
    if ($resolvedBizId <= 0 && $sourcePoBusinessSlug !== '') {
        [$resolvedBizId, $resolvedBizNameFallback] = $findBusinessBySlug($sourcePoBusinessSlug);
        if ($resolvedBizName === '') {
            $resolvedBizName = $resolvedBizNameFallback;
        }
    }

    // Final safety: resolve by business name in current DB if ID still missing/invalid.
    if ($resolvedBizName !== '') {
        $bizByName = $db->fetchOne(
            'SELECT id, business_name FROM businesses WHERE LOWER(TRIM(business_name)) = LOWER(TRIM(?)) LIMIT 1',
            [$resolvedBizName]
        );
        if ($bizByName) {
            $resolvedBizId = (int)($bizByName['id'] ?? 0);
            $resolvedBizName = (string)($bizByName['business_name'] ?? $resolvedBizName);
        }
    }

    if ($sourcePoId <= 0) {
        $sourcePoId = null;
    } else if ($resolvedBizId <= 0) {
        header('Location: ' . $redirectBase . (strpos($redirectBase, '?') !== false ? '&' : '?') . 'transfer_err=' . urlencode('Bisnis tujuan tidak ditemukan. Hubungi admin.'));
        exit;
    }

    $result = transferGudangNasitaStock($resolvedBizId ?: $targetBusinessId, $transferItems, $currentUser['id'], $notes, $sourcePoId, $resolvedBizName ?: null);

    // After successful transfer, increment PO received quantities and set status partial/completed.
    if ($result['success'] && $sourcePoId !== null && $sourcePoBusinessSlug !== '') {
        $poStatusCfgPath = __DIR__ . '/../../config/businesses/' . $sourcePoBusinessSlug . '.php';
        if (file_exists($poStatusCfgPath)) {
            $poStatusCfg = require $poStatusCfgPath;
            $poStatusDbName = (string)($poStatusCfg['database'] ?? '');
            if ($poStatusDbName !== '') {
                try {
                    $originDbForPo = Database::getCurrentDatabase();
                    $poStatusDb = Database::switchDatabase($poStatusDbName);

                    // Ensure received_quantity column exists in older schemas.
                    $detailCols = $poStatusDb->fetchAll('SHOW COLUMNS FROM purchase_orders_detail');
                    $detailColNames = array_map(function ($r) {
                        return strtolower((string)($r['Field'] ?? ''));
                    }, $detailCols ?: []);
                    if (!in_array('received_quantity', $detailColNames, true)) {
                        $poStatusDb->query('ALTER TABLE purchase_orders_detail ADD COLUMN received_quantity DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER quantity');
                    }

                    // Aggregate transferred qty by PO detail row.
                    $byDetail = [];
                    foreach ($transferItems as $ti) {
                        $detailId = (int)($ti['po_detail_id'] ?? 0);
                        $qty = (float)($ti['quantity'] ?? 0);
                        if ($detailId > 0 && $qty > 0) {
                            if (!isset($byDetail[$detailId])) {
                                $byDetail[$detailId] = 0;
                            }
                            $byDetail[$detailId] += $qty;
                        }
                    }

                    foreach ($byDetail as $detailId => $qtyToAdd) {
                        $row = $poStatusDb->fetchOne('SELECT id, quantity, COALESCE(received_quantity,0) AS received_quantity FROM purchase_orders_detail WHERE id = ? AND po_header_id = ? LIMIT 1', [(int)$detailId, (int)$sourcePoId]);
                        if (!$row) {
                            continue;
                        }
                        $ordered = (float)($row['quantity'] ?? 0);
                        $receivedNow = (float)($row['received_quantity'] ?? 0);
                        $newReceived = min($ordered, $receivedNow + (float)$qtyToAdd);
                        $poStatusDb->update('purchase_orders_detail', ['received_quantity' => $newReceived], 'id = :id', ['id' => (int)$detailId]);
                    }

                    // Recompute header status.
                    $agg = $poStatusDb->fetchOne('SELECT COALESCE(SUM(quantity),0) AS ordered_total, COALESCE(SUM(received_quantity),0) AS received_total FROM purchase_orders_detail WHERE po_header_id = ?', [(int)$sourcePoId]);
                    $orderedTotal = (float)($agg['ordered_total'] ?? 0);
                    $receivedTotal = (float)($agg['received_total'] ?? 0);
                    $newStatus = 'submitted';
                    if ($orderedTotal > 0 && $receivedTotal >= $orderedTotal) {
                        $newStatus = 'completed';
                    } elseif ($receivedTotal > 0) {
                        $newStatus = 'partially_received';
                    }
                    $poStatusDb->update('purchase_orders_header', ['status' => $newStatus], 'id = :id', ['id' => (int)$sourcePoId]);

                    if (!empty($originDbForPo)) {
                        Database::switchDatabase($originDbForPo);
                    }
                } catch (Throwable $e) {
                    error_log('gudang-transfer PO status update error: ' . $e->getMessage());
                }
            }
        }
    }

    $sep = strpos($redirectBase, '?') !== false ? '&' : '?';
    if ($result['success']) {
        $redirectParams = [
            'transfer_ok' => '1',
            'biz' => (string)($result['business_name'] ?? $resolvedBizName),
            'tn' => (string)($result['transfer_number'] ?? ''),
            'tq' => (string)number_format((float)($result['total_qty'] ?? 0), 2, '.', ''),
            'ic' => (string)count($transferItems),
        ];
        header('Location: ' . $redirectBase . $sep . http_build_query($redirectParams));
    } else {
        header('Location: ' . $redirectBase . $sep . 'transfer_err=' . urlencode($result['message']));
    }
    exit;
}

$stockItems = getGudangNasitaStock(300);
$transfers = getGudangNasitaTransfers(50);

$forceTheme = 'light';
include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">Transfer Gudang Nasita</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Kirim stok dari gudang pusat ke bisnis tujuan</p>
    </div>
    <a href="gudang-nasita.php" class="btn btn-secondary">
        <i data-feather="archive" style="width: 16px; height: 16px;"></i>
        Kembali ke Gudang
    </a>
</div>

<?php if (!empty($_GET['transfer_ok'])): ?>
    <div class="alert alert-success" id="transferResult" style="border-left:4px solid #16a34a; background:linear-gradient(135deg,#ecfdf3,#f0fdf4);">
        <div style="display:flex; gap:0.8rem; align-items:flex-start;">
            <div style="font-size:1.2rem; line-height:1;">✅</div>
            <div>
                <div style="font-weight:700; color:#166534; margin-bottom:0.25rem;">Transfer Berhasil Dikirim</div>
                <div style="color:#166534; font-size:0.92rem;">
                    Barang berhasil ditransfer ke <strong><?php echo htmlspecialchars((string)($_GET['biz'] ?? '')); ?></strong>
                    <?php if (!empty($_GET['tn'])): ?>
                        dengan nomor <strong><?php echo htmlspecialchars((string)$_GET['tn']); ?></strong>
                        <?php endif; ?>.
                </div>
                <div style="margin-top:0.35rem; color:#065f46; font-size:0.82rem;">
                    Total Qty: <strong><?php echo htmlspecialchars((string)($_GET['tq'] ?? '0')); ?></strong>
                    | Item Terkirim: <strong><?php echo htmlspecialchars((string)($_GET['ic'] ?? '0')); ?></strong>
                    | Riwayat transfer di samping sudah otomatis ter-update.
                </div>
            </div>
        </div>
        <script>
            document.getElementById('transferResult').scrollIntoView({
                behavior: 'smooth'
            });
        </script>
    </div>
<?php elseif (!empty($_GET['transfer_err'])): ?>
    <div class="alert alert-danger" id="transferResult">
        ❌ <?php echo htmlspecialchars((string)$_GET['transfer_err']); ?>
        <script>
            document.getElementById('transferResult').scrollIntoView({
                behavior: 'smooth'
            });
        </script>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']);
                                        unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']);
                                    unset($_SESSION['error']); ?></div>
<?php endif; ?>

<?php if ($prefillPoId > 0 && $poData): ?>
    <div class="alert alert-info" style="margin-bottom:1rem;">
        Transfer untuk PO <strong><?php echo htmlspecialchars($poData['po_number'] ?? ''); ?></strong>
        dari <strong><?php echo htmlspecialchars($prefillTargetBusinessName ?: $prefillPoBusinessSlug); ?></strong>.
        Isi qty yang akan dikirim untuk setiap item.
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1.1fr 1fr; gap: 1.25rem; align-items:start;">
    <div class="card">
        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Form Transfer</h3>
        <form method="POST">
            <input type="hidden" name="source_po_id" value="<?php echo (int)$prefillPoId; ?>">
            <input type="hidden" name="source_po_business" value="<?php echo htmlspecialchars($prefillPoBusinessSlug); ?>">
            <input type="hidden" name="target_business_id" value="<?php echo (int)$prefillTargetBusinessId; ?>">

            <div class="form-group" style="margin-bottom:1rem;">
                <label class="form-label">Tujuan Bisnis</label>
                <?php if ($prefillPoId > 0): ?>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars(($prefillTargetBusinessName !== '' ? $prefillTargetBusinessName : 'Business')); ?>" readonly style="font-weight:700; background:#f8fafc; cursor:not-allowed;">
                <?php else: ?>
                    <select name="target_business_id" class="form-control" required>
                        <option value="">-- Pilih bisnis --</option>
                        <?php foreach ($allowedBusinesses as $biz): ?>
                            <option value="<?php echo (int)$biz['id']; ?>" <?php echo ($activeBusinessId > 0 && (int)$biz['id'] === $activeBusinessId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($biz['business_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <?php if ($prefillPoId > 0 && !empty($poItemsWithStock)): ?>
                <!-- PO mode: table of PO items with gudang stock check -->
                <div style="overflow-x:auto; margin-bottom:1rem;">
                    <table class="table" style="font-size:0.875rem;">
                        <thead>
                            <tr>
                                <th>Item PO</th>
                                <th>ID Produk</th>
                                <th class="text-right">Diminta</th>
                                <th>Stok Gudang</th>
                                <th class="text-right" style="min-width:110px;">Kirim (qty)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($poItemsWithStock as $idx => $pItem): ?>
                                <?php $gStock = $pItem['gudang_stock']; ?>
                                <tr style="<?php echo ($pItem['remaining_qty'] <= 0) ? 'opacity:0.5;' : ''; ?>">
                                    <td style="font-weight:600;">
                                        <?php echo htmlspecialchars($pItem['item_name']); ?>
                                    </td>
                                    <td>
                                        <?php if ($gStock): ?>
                                            <span style="font-weight:700; color:#1e3a8a;">
                                                <?php echo htmlspecialchars((string)($pItem['gudang_stock_code'] ?: ('GN-' . $pItem['gudang_stock_id']))); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#b91c1c; font-weight:600;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right"><?php echo number_format($pItem['remaining_qty'], 2); ?> <?php echo htmlspecialchars($pItem['unit']); ?></td>
                                    <td>
                                        <?php if ($gStock): ?>
                                            <span style="color:<?php echo (float)$gStock['quantity'] > 0 ? '#0f9d6a' : '#d97706'; ?>; font-weight:600;">
                                                <?php echo number_format((float)$gStock['quantity'], 2); ?> <?php echo htmlspecialchars($gStock['unit']); ?>
                                            </span>
                                            <?php if ((float)$gStock['quantity'] <= 0): ?>
                                                <div style="color:#b45309; font-size:0.75rem; margin-top:0.2rem; font-weight:600;">⚠️ Stok habis / tidak tersedia untuk dikirim</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#b91c1c; font-weight:700;">⚠️ Tidak ada di gudang</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($gStock && (float)$gStock['quantity'] > 0 && $pItem['remaining_qty'] > 0): ?>
                                            <input type="hidden" name="transfer_items[<?php echo $idx; ?>][stock_id]" value="<?php echo (int)$gStock['id']; ?>">
                                            <input type="hidden" name="transfer_items[<?php echo $idx; ?>][po_detail_id]" value="<?php echo (int)$pItem['po_detail_id']; ?>">
                                            <input type="number" name="transfer_items[<?php echo $idx; ?>][qty]" step="0.01" min="0" max="<?php echo min($pItem['remaining_qty'], (float)$gStock['quantity']); ?>" value="<?php echo min($pItem['remaining_qty'], (float)$gStock['quantity']); ?>" class="form-control" style="width:100px; text-align:right;">
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <!-- Simple mode: single item dropdown -->
                <div class="form-group">
                    <label class="form-label">Item Gudang</label>
                    <select name="stock_id" class="form-control" required>
                        <option value="">-- Pilih item --</option>
                        <?php foreach ($stockItems as $item): ?>
                            <option value="<?php echo (int)$item['id']; ?>" <?php echo ((int)$item['id'] === $prefillStockId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($item['item_name']); ?> (<?php echo number_format((float)$item['quantity'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Qty Transfer</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" value="<?php echo $prefillQty > 0 ? htmlspecialchars((string)$prefillQty) : ''; ?>" required>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">Catatan</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Catatan pengiriman..."><?php echo htmlspecialchars($prefillNotes); ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i data-feather="send" style="width: 16px; height: 16px;"></i>
                Transfer Sekarang
            </button>
        </form>
    </div>

    <div class="card">
        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Riwayat Transfer</h3>
        <div style="display:grid; gap:0.75rem; max-height: 620px; overflow-y:auto; padding-right:0.25rem;">
            <?php if (empty($transfers)): ?>
                <div style="color:var(--text-muted); font-size:0.875rem;">Belum ada transfer</div>
            <?php else: ?>
                <?php foreach ($transfers as $transfer): ?>
                    <div style="padding:0.85rem; border:1px solid var(--border); border-radius:0.85rem; background: var(--bg-secondary);">
                        <div style="display:flex; justify-content:space-between; gap:1rem; align-items:flex-start;">
                            <div>
                                <div style="font-weight:700; color: var(--text-primary);"><?php echo htmlspecialchars($transfer['transfer_number']); ?></div>
                                <div style="font-size:0.813rem; color:var(--text-muted);"><?php echo htmlspecialchars($transfer['target_business_name']); ?></div>
                                <div style="font-size:0.813rem; color:var(--text-muted);"><?php echo (int)$transfer['items_count']; ?> item | <?php echo number_format((float)$transfer['total_qty'], 2); ?> qty</div>
                            </div>
                            <span class="badge badge-<?php echo $transfer['status'] === 'sent' ? 'warning' : 'secondary'; ?>"><?php echo ucfirst($transfer['status']); ?></span>
                        </div>
                        <?php if (!empty($transfer['notes'])): ?>
                            <div style="margin-top:0.5rem; font-size:0.813rem; color:var(--text-muted);"><?php echo htmlspecialchars($transfer['notes']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>