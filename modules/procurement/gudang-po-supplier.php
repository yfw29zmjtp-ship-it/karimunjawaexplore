<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses Gudang Nasita ditolak.';
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'PO Supplier Gudang';

// Read-only: item detail for a PO, shown in the "Hapus" confirmation modal so the user
// can see exactly what they're about to delete before confirming.
if (($_GET['ajax_po_items'] ?? '') === '1') {
    header('Content-Type: application/json');
    $poId = (int)($_GET['po_id'] ?? 0);
    $poHeader = $db->fetchOne('SELECT id, po_number, status FROM purchase_orders_header WHERE id = ? LIMIT 1', [$poId]);
    if (!$poHeader) {
        echo json_encode(['success' => false, 'message' => 'PO tidak ditemukan.']);
        exit;
    }
    $items = $db->fetchAll(
        'SELECT item_name, quantity, unit_of_measure, unit, unit_price, subtotal, received_quantity
         FROM purchase_orders_detail WHERE po_header_id = ? ORDER BY line_number ASC, id ASC',
        [$poId]
    );
    echo json_encode(['success' => true, 'po_number' => $poHeader['po_number'], 'items' => $items]);
    exit;
}

// TEMP DIAGNOSTIC (auth-gated above) — read-only, shows PO detail rows matching an item
// name so we can see qty/received_quantity/status without guessing. Remove after debugging.
if (($_GET['debug_po_item'] ?? '') === '1') {
    header('Content-Type: application/json');
    $itemName = trim((string)($_GET['item_name'] ?? 'amer'));
    $rows = $db->fetchAll(
        "SELECT poh.id AS po_id, poh.po_number, poh.status AS po_status, poh.created_at,
                pod.id AS detail_id, pod.item_name, pod.quantity, pod.received_quantity, pod.unit_of_measure
         FROM purchase_orders_detail pod
         JOIN purchase_orders_header poh ON poh.id = pod.po_header_id
         WHERE pod.item_name LIKE ?
         ORDER BY poh.created_at DESC",
        ['%' . $itemName . '%']
    );
    echo json_encode(['search_term' => $itemName, 'matches' => $rows], JSON_PRETTY_PRINT);
    exit;
}

// TEMP DIAGNOSTIC (auth-gated above) — read-only: dumps gudang_nasita_stock columns and
// replays the exact UPDATE statement receivePurchaseOrderToGudang() uses (rolled back, never
// committed) so we can see the REAL PDO error instead of the silently-swallowed one from
// Database::update(). Remove after debugging the "stock not increasing" report.
if (($_GET['debug_stock_update'] ?? '') === '1') {
    header('Content-Type: application/json');
    $stockId = (int)($_GET['stock_id'] ?? 0);
    $response = [
        'columns' => $db->fetchAll('SHOW COLUMNS FROM gudang_nasita_stock'),
        'stock_row' => $stockId > 0 ? $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ?', [$stockId]) : null,
    ];
    if ($stockId > 0) {
        try {
            $conn = $db->getConnection();
            $conn->beginTransaction();
            $stmt = $conn->prepare("UPDATE gudang_nasita_stock SET quantity = :quantity, harga_beli = :harga_beli, total_harga = :total_harga, supplier_name = :supplier_name, notes = :notes WHERE id = :id");
            $stmt->execute([
                'quantity' => 2,
                'harga_beli' => 1000,
                'total_harga' => 2000,
                'supplier_name' => 'Test Supplier',
                'notes' => 'debug_stock_update test',
                'id' => $stockId,
            ]);
            $response['replay_row_count'] = $stmt->rowCount();
            $conn->rollBack();
        } catch (Throwable $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->getConnection()->rollBack();
            }
            $response['replay_error'] = $e->getMessage();
        }
    }
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

$normalizePoStatus = static function ($status): string {
    $statusText = trim((string)($status ?? ''));
    if ($statusText === '') {
        return 'draft';
    }
    return strtolower($statusText);
};

$poStatusLabelMap = [
    'draft' => 'Draft',
    'submitted' => '⏳ Menunggu Datang',
    'pending' => '⏳ Menunggu Datang',
    'waiting' => '⏳ Menunggu Gudang',
    'approved' => '✅ Disetujui',
    'partially_received' => '📦 Sebagian Diterima',
    'received' => '✓ Diterima',
    'completed' => '✓ Selesai',
    'cancelled' => '✗ Dibatalkan',
    'rejected' => '⚠️ Ditolak',
];

$ensureGudangPoSchema = function () use ($db) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS purchase_orders_header (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NULL,
            po_number VARCHAR(30) UNIQUE,
            supplier_id INT,
            po_date DATE NOT NULL,
            delivery_date DATE NULL,
            status VARCHAR(30) DEFAULT 'draft',
            total_amount DECIMAL(15,2) DEFAULT 0,
            notes TEXT,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_po_number (po_number),
            INDEX idx_supplier (supplier_id),
            INDEX idx_status (status),
            INDEX idx_po_date (po_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS purchase_orders_detail (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_header_id INT NOT NULL,
            line_number INT NULL,
            item_name VARCHAR(200) NOT NULL,
            item_description TEXT NULL,
            unit_of_measure VARCHAR(20) DEFAULT 'pcs',
            unit VARCHAR(20) NULL,
            quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_price DECIMAL(15,2) NULL,
            received_quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            division_id INT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_po_header (po_header_id),
            INDEX idx_item_name (item_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Backfill missing columns for older variants.
        $hdrCols = $db->fetchAll('SHOW COLUMNS FROM purchase_orders_header');
        $hdrNames = array_column($hdrCols, 'Field');
        $hdrRequired = [
            'po_number' => "VARCHAR(30) UNIQUE",
            'supplier_id' => 'INT NULL',
            'po_date' => 'DATE NULL',
            'status' => "VARCHAR(30) DEFAULT 'draft'",
            'total_amount' => 'DECIMAL(15,2) DEFAULT 0',
            'notes' => 'TEXT NULL',
            'created_by' => 'INT NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];
        foreach ($hdrRequired as $col => $def) {
            if (!in_array($col, $hdrNames, true)) {
                $db->query("ALTER TABLE purchase_orders_header ADD COLUMN `{$col}` {$def}");
            }
        }

        $dtlCols = $db->fetchAll('SHOW COLUMNS FROM purchase_orders_detail');
        $dtlNames = array_column($dtlCols, 'Field');
        $dtlRequired = [
            'po_header_id' => 'INT NOT NULL',
            'item_name' => "VARCHAR(200) NOT NULL DEFAULT ''",
            'unit_of_measure' => "VARCHAR(20) DEFAULT 'pcs'",
            'quantity' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'unit_price' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'subtotal' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'received_quantity' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        ];
        foreach ($dtlRequired as $col => $def) {
            if (!in_array($col, $dtlNames, true)) {
                $db->query("ALTER TABLE purchase_orders_detail ADD COLUMN `{$col}` {$def}");
            }
        }

        if (!in_array('unit_of_measure', $dtlNames, true) && in_array('unit', $dtlNames, true)) {
            $db->query("UPDATE purchase_orders_detail SET unit_of_measure = unit WHERE (unit_of_measure IS NULL OR unit_of_measure = '') AND unit IS NOT NULL");
        }
        if (!in_array('subtotal', $dtlNames, true) && in_array('total_price', $dtlNames, true)) {
            $db->query('UPDATE purchase_orders_detail SET subtotal = total_price WHERE subtotal = 0 AND total_price IS NOT NULL');
        }
    } catch (Throwable $e) {
        error_log('ensureGudangPoSchema error: ' . $e->getMessage());
    }
};

$ensureGudangPoSchema();

$createdById = null;
$userInDb = $db->fetchOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$currentUser['id']]);
if ($userInDb) {
    $createdById = $currentUser['id'];
} else {
    // Current user not in this DB — use first available user as fallback
    $fallbackUser = $db->fetchOne('SELECT id FROM users ORDER BY id ASC LIMIT 1');
    $createdById = $fallbackUser ? (int)$fallbackUser['id'] : 1;
}

// ─── POST: buat PO baru ke supplier ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_po') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');
    $items      = $_POST['items'] ?? [];

    $validItems = [];
    foreach ($items as $it) {
        $nm = trim($it['item_name'] ?? '');
        $qt = (float)($it['quantity'] ?? 0);
        $un = trim($it['unit'] ?? 'pcs');
        $up = (float)($it['unit_price'] ?? 0);
        if ($nm !== '' && $qt > 0) {
            $validItems[] = ['item_name' => $nm, 'quantity' => $qt, 'unit' => $un, 'unit_price' => $up, 'expiry_date' => trim($it['expiry_date'] ?? '')];
        }
    }

    if ($supplierId <= 0 || empty($validItems)) {
        $_SESSION['error'] = 'Pilih supplier dan tambahkan minimal 1 item.';
    } else {
        try {
            $poPrefix = 'GDN-' . date('Ymd') . '-';
            $lastPo = $db->fetchOne("SELECT po_number FROM purchase_orders_header WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1", [$poPrefix . '%']);
            $poSeq  = $lastPo ? ((int)substr($lastPo['po_number'], -3) + 1) : 1;
            $poNumber = $poPrefix . str_pad($poSeq, 3, '0', STR_PAD_LEFT);

            $totalAmount = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $validItems));

            // Wrap in transaction so header rolls back if any detail insert fails
            $db->getConnection()->beginTransaction();

            $poHeaderId = $db->insert('purchase_orders_header', [
                'business_id'  => null,
                'po_number'    => $poNumber,
                'supplier_id'  => $supplierId,
                'po_date'      => date('Y-m-d'),
                'status'       => 'submitted',
                'total_amount' => $totalAmount,
                'notes'        => $notes ?: 'Restock Gudang Nasita',
                'created_by'   => $createdById,
            ]);
            if (!$poHeaderId) {
                throw new \RuntimeException('Gagal membuat header PO.');
            }

            // Probe optional columns once before the loop
            $detailCols    = $db->fetchAll("SHOW COLUMNS FROM purchase_orders_detail");
            $detailColNames = array_column($detailCols, 'Field');
            $firstDiv = in_array('division_id', $detailColNames)
                ? $db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1")
                : null;

            foreach ($validItems as $idx => $it) {
                $detailData = [
                    'po_header_id'     => $poHeaderId,
                    'item_name'        => $it['item_name'],
                    'unit_of_measure'  => $it['unit'],
                    'quantity'         => $it['quantity'],
                    'unit_price'       => $it['unit_price'],
                    'subtotal'         => $it['quantity'] * $it['unit_price'],
                    'received_quantity' => 0,
                ];
                if (!empty($it['expiry_date']) && in_array('expiry_date', $detailColNames)) {
                    $detailData['expiry_date'] = $it['expiry_date'];
                }
                if (in_array('line_number', $detailColNames)) {
                    $detailData['line_number'] = $idx + 1;
                }
                if ($firstDiv) {
                    $detailData['division_id'] = (int)$firstDiv['id'];
                }
                $insertedId = $db->insert('purchase_orders_detail', $detailData);
                if (!$insertedId) {
                    throw new \RuntimeException('Gagal menyimpan item: ' . $it['item_name']);
                }
            }

            $db->getConnection()->commit();

            // Auto-register any new item names into gudang_nasita_barang
            foreach ($validItems as $it) {
                $nm = $it['item_name'];
                $exists = $db->fetchOne('SELECT id FROM gudang_nasita_barang WHERE LOWER(nama_barang) = LOWER(?) AND COALESCE(is_active,1) = 1 LIMIT 1', [$nm]);
                if (!$exists) {
                    try {
                        $prefix = 'BRG-';
                        $last = $db->fetchOne('SELECT kode_barang FROM gudang_nasita_barang WHERE kode_barang LIKE ? ORDER BY kode_barang DESC LIMIT 1', [$prefix . '%']);
                        $seq = $last ? ((int)substr($last['kode_barang'], -4) + 1) : 1;
                        $db->insert('gudang_nasita_barang', [
                            'kode_barang' => $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT),
                            'nama_barang' => $nm,
                            'satuan'      => $it['unit'],
                            'harga_beli'  => $it['unit_price'] > 0 ? $it['unit_price'] : 0,
                            'is_active'   => 1,
                        ]);
                    } catch (Throwable $regErr) {
                        error_log('Auto-register barang failed: ' . $regErr->getMessage());
                    }
                }
            }

            $_SESSION['success'] = 'PO ' . $poNumber . ' berhasil dibuat (' . count($validItems) . ' item).';
            $poMode = $_POST['po_mode'] ?? 'save';
            if ($poMode === 'print') {
                header('Location: gudang-po-supplier.php?print=' . $poHeaderId);
            } else {
                header('Location: gudang-po-supplier.php');
            }
            exit;
        } catch (Throwable $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->getConnection()->rollBack();
            }
            $_SESSION['error'] = 'Gagal buat PO: ' . $e->getMessage();
        }
    }
    header('Location: gudang-po-supplier.php');
    exit;
}

// ─── POST: terima barang dari supplier → tambah ke gudang ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_goods') {
    $poId         = (int)($_POST['po_id'] ?? 0);
    $receivedItems = isset($_POST['received_qty']) && is_array($_POST['received_qty']) ? $_POST['received_qty'] : [];
    $notes        = trim($_POST['notes'] ?? '');

    $result = receivePurchaseOrderToGudang($poId, $receivedItems, $createdById ?? 1, $notes);
    if ($result['success']) {
        $_SESSION['success'] = 'Barang berhasil diterima ke Gudang Nasita.';
    } else {
        $_SESSION['error'] = $result['message'];
    }
    header('Location: gudang-po-supplier.php');
    exit;
}

// ─── POST: batalkan PO ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_po') {
    $poId = (int)($_POST['po_id'] ?? 0);
    if ($poId > 0) {
        $db->update('purchase_orders_header', ['status' => 'cancelled'], 'id = :id', ['id' => $poId]);
        $_SESSION['success'] = 'PO dibatalkan.';
    }
    header('Location: gudang-po-supplier.php');
    exit;
}
// ─── POST: hapus PO permanen ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_po') {
    $poId = (int)($_POST['po_id'] ?? 0);
    if ($poId > 0) {
        try {
            $poRow = $db->fetchOne('SELECT id, po_number, status FROM purchase_orders_header WHERE id = ? LIMIT 1', [$poId]);
            if (!$poRow) {
                throw new RuntimeException('PO tidak ditemukan.');
            }

            $poStatusKey = $normalizePoStatus($poRow['status'] ?? '');
            $allowedStatuses = ['draft', 'submitted', 'approved', 'partially_received', 'cancelled', 'rejected', 'pending', 'waiting', 'completed', 'received'];
            if (!in_array($poStatusKey, $allowedStatuses, true)) {
                throw new RuntimeException('Status PO ini tidak boleh dihapus.');
            }

            $conn = $db->getConnection();
            $conn->beginTransaction();
            $db->query('DELETE FROM purchase_orders_detail WHERE po_header_id = ?', [$poId]);
            $db->query('DELETE FROM purchase_orders_header WHERE id = ?', [$poId]);
            // Also remove the "Histori Barang Datang" trail on the Gudang dashboard tied to this PO
            $db->query("DELETE FROM gudang_nasita_movements WHERE reference_type = 'purchase_order' AND reference_id = ?", [$poId]);
            $conn->commit();

            $_SESSION['success'] = 'PO ' . (string)($poRow['po_number'] ?? '') . ' berhasil dihapus dan hilang dari daftar Gudang Nasita.';
        } catch (Throwable $e) {
            try {
                if ($db->getConnection()->inTransaction()) {
                    $db->getConnection()->rollBack();
                }
            } catch (Throwable $rollbackError) {
            }
            $_SESSION['error'] = 'Gagal hapus PO: ' . $e->getMessage();
        }
    }
    header('Location: gudang-po-supplier.php');
    exit;
}
// ─── Data ──────────────────────────────────────────────────────────────────
$viewPoId = (int)($_GET['view'] ?? 0);
$printPoId = (int)($_GET['print'] ?? 0);

$gudangPOs = $db->fetchAll("
    SELECT poh.*, s.supplier_name,
           COUNT(pod.id) AS items_count,
           COALESCE(SUM(pod.quantity), 0) AS total_ordered,
           COALESCE(SUM(pod.received_quantity), 0) AS total_received
    FROM purchase_orders_header poh
    LEFT JOIN suppliers s ON poh.supplier_id = s.id
    LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
    WHERE poh.business_id IS NULL AND poh.po_number LIKE 'GDN-%'
    GROUP BY poh.id
    ORDER BY poh.created_at DESC
    LIMIT 100
");

$suppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 OR is_active IS NULL ORDER BY supplier_name ASC");
if (empty($suppliers)) {
    $suppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC");
}

$viewPo = null;
if ($viewPoId > 0) {
    $viewPo = getPurchaseOrder($viewPoId);
}

$printPo = null;
if ($printPoId > 0) {
    $printPo = getPurchaseOrder($printPoId);
}

// ─── Print mode ────────────────────────────────────────────────────────────
if ($printPo) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>PO ' . htmlspecialchars($printPo['po_number']) . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;font-size:12px;margin:20px;}h2{margin:0 0 4px;}table{width:100%;border-collapse:collapse;margin-top:12px;}th,td{border:1px solid #999;padding:6px 8px;text-align:left;}th{background:#f0f0f0;}.text-right{text-align:right;}.footer{margin-top:24px;display:flex;justify-content:space-between;}@media print{button{display:none}}</style>';
    echo '</head><body>';
    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;"><div>';
    echo '<h2>PURCHASE ORDER</h2>';
    echo '<strong>Gudang Nasita</strong><br>Narayana Hotel Karimunjawa';
    echo '</div><div style="text-align:right;">';
    echo '<strong>No PO:</strong> ' . htmlspecialchars($printPo['po_number']) . '<br>';
    echo '<strong>Tanggal:</strong> ' . date('d M Y', strtotime($printPo['po_date'])) . '<br>';
    echo '<strong>Status:</strong> ' . ucfirst($printPo['status']);
    echo '</div></div>';
    echo '<hr style="margin:12px 0;">';
    echo '<strong>Kepada Yth:</strong><br>';
    echo htmlspecialchars($printPo['supplier_name'] ?? '-');
    echo '<table><thead><tr><th>#</th><th>Item</th><th class="text-right">Qty</th><th>Unit</th><th class="text-right">Harga Satuan</th><th class="text-right">Total</th></tr></thead><tbody>';
    $grandTotal = 0;
    foreach ($printPo['items'] as $idx => $item) {
        $sub = (float)$item['quantity'] * (float)$item['unit_price'];
        $grandTotal += $sub;
        echo '<tr><td>' . ($idx + 1) . '</td><td>' . htmlspecialchars($item['item_name']) . '</td>';
        echo '<td class="text-right">' . number_format((float)$item['quantity'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($item['unit_of_measure'] ?: ($item['unit'] ?? '')) . '</td>';
        echo '<td class="text-right">Rp ' . number_format((float)$item['unit_price'], 0, ',', '.') . '</td>';
        echo '<td class="text-right">Rp ' . number_format($sub, 0, ',', '.') . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td colspan="5" class="text-right"><strong>Total</strong></td><td class="text-right"><strong>Rp ' . number_format($grandTotal, 0, ',', '.') . '</strong></td></tr></tfoot></table>';
    if (!empty($printPo['notes'])) {
        echo '<p style="margin-top:12px;"><strong>Catatan:</strong> ' . htmlspecialchars($printPo['notes']) . '</p>';
    }
    echo '<div class="footer"><div><strong>Dibuat oleh:</strong><br><br><br>___________________________<br>' . htmlspecialchars($printPo['created_by_name'] ?? 'Gudang Nasita') . '</div>';
    echo '<div><strong>Disetujui:</strong><br><br><br>___________________________<br>&nbsp;</div>';
    echo '<div><strong>Diterima:</strong><br><br><br>___________________________<br>Supplier</div></div>';
    echo '<br><button onclick="window.print()">🖨️ Cetak</button>';
    echo '</body></html>';
    exit;
}

// Load all barang for client-side autocomplete — no column filters that might not exist
$allBarang = [];
try {
    $allBarang = $db->fetchAll(
        "SELECT gb.id, COALESCE(gb.kode_barang,'') AS kode_barang, gb.nama_barang,
                COALESCE(gb.satuan,'pcs') AS satuan, COALESCE(gb.harga_beli,0) AS harga_beli,
                COALESCE(gb.min_stock,0) AS min_stock,
                COALESCE((
                    SELECT SUM(COALESCE(gs.quantity,0))
                    FROM gudang_nasita_stock gs
                    WHERE LOWER(gs.item_name) = LOWER(gb.nama_barang)
                      AND COALESCE(gs.is_active, 1) = 1
                ), 0) AS current_stock
         FROM gudang_nasita_barang gb
         WHERE COALESCE(gb.is_active,1) = 1
         ORDER BY gb.nama_barang ASC"
    ) ?: [];
} catch (Throwable $e) {
    error_log('gudang-po-supplier allBarang load error: ' . $e->getMessage());
    try {
        $allBarang = $db->fetchAll(
            "SELECT id, COALESCE(kode_barang,'') AS kode_barang, nama_barang,
                    COALESCE(satuan,'pcs') AS satuan, COALESCE(harga_beli,0) AS harga_beli,
                    0 AS min_stock, 0 AS current_stock
             FROM gudang_nasita_barang ORDER BY nama_barang ASC"
        ) ?: [];
    } catch (Throwable $e2) {
        error_log('gudang-po-supplier allBarang fallback error: ' . $e2->getMessage());
    }
}

$forceTheme = 'light';
include '../../includes/header.php';
?>

<div style="margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size:1.5rem; font-weight:700; color:var(--text-primary); margin-bottom:0.2rem;">PO Supplier Gudang</h2>
        <p style="color:var(--text-muted); font-size:0.875rem;">Centang barang di kiri → isi qty di kanan → Buat PO</p>
    </div>
    <a href="gudang-nasita.php" class="btn btn-secondary">
        <i data-feather="arrow-left" style="width:16px;height:16px;"></i> Kembali ke Gudang
    </a>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['success']);
                                                                    unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['error']);
                                                                unset($_SESSION['error']); ?></div>
<?php endif; ?>

<?php if (empty($suppliers)): ?>
    <div class="alert alert-warning" style="margin-bottom:1rem;">
        ⚠️ Belum ada supplier. Tambahkan di <a href="suppliers.php">menu Pemasok</a>.
    </div>
<?php endif; ?>

<!-- Split-panel: kiri=daftar barang, kanan=form PO -->
<div style="display:<?php echo $viewPo ? 'none' : 'grid'; ?>; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.25rem; height:calc(100vh - 210px); min-height:420px;" id="poCreatePanel">

    <!-- KIRI: Daftar barang dengan checkbox -->
    <div class="card" style="display:flex; flex-direction:column; overflow:hidden; padding:0;">
        <div style="padding:0.85rem 1rem; border-bottom:1px solid var(--border,#e2e8f0); flex-shrink:0;">
            <div style="font-weight:700; font-size:0.95rem; margin-bottom:0.5rem;">Pilih Barang</div>
            <input type="text" id="poLeftSearch" class="form-control" placeholder="Cari nama barang..." autocomplete="off">
        </div>
        <div id="poLeftList" style="flex:1; overflow-y:auto; min-height:0;"></div>
        <!-- Manual input for items not yet in database -->
        <div style="padding:0.65rem 1rem; border-top:1px solid #fde68a; background:#fffbeb; flex-shrink:0;">
            <div style="font-size:0.78rem; font-weight:700; color:#92400e; margin-bottom:0.4rem;">+ Tambah item baru (belum di database)</div>
            <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
                <input type="text" id="manualItemName" class="form-control" placeholder="Nama barang..." style="flex:1; min-width:140px; height:32px; font-size:0.82rem;">
                <input type="text" id="manualItemSatuan" class="form-control" placeholder="Satuan" style="width:72px; height:32px; font-size:0.82rem;" value="pcs">
                <input type="number" id="manualItemHarga" class="form-control" placeholder="Harga" style="width:90px; height:32px; font-size:0.82rem;" min="0" step="1">
                <button type="button" class="btn btn-sm" style="background:#f59e0b;color:#fff;height:32px;padding:0 0.7rem;font-size:0.82rem;" onclick="addManualItem()">Tambah</button>
            </div>
        </div>
        <div style="padding:0.5rem 1rem; border-top:1px solid var(--border,#e2e8f0); font-size:0.78rem; color:var(--text-muted); flex-shrink:0;">
            <span id="poLeftCount">0 barang</span> &nbsp;·&nbsp; <span id="poSelectedCount" style="color:#0f9d6a; font-weight:700;">0 dipilih</span>
        </div>
    </div>

    <!-- KANAN: Form PO + item terpilih -->
    <div class="card" style="display:flex; flex-direction:column; overflow:hidden; padding:0;">
        <div style="padding:0.85rem 1rem; border-bottom:1px solid var(--border,#e2e8f0); flex-shrink:0;">
            <div style="font-weight:700; font-size:0.95rem; margin-bottom:0.75rem;">Buat PO Baru</div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.6rem;">
                <div>
                    <label class="form-label" style="font-size:0.82rem;">Supplier *</label>
                    <select id="poSupplier" class="form-control" required>
                        <option value="">-- Pilih Supplier --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo (int)$sup['id']; ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size:0.82rem;">Catatan</label>
                    <input type="text" id="poNotes" class="form-control" placeholder="Kebutuhan minggu ini">
                </div>
            </div>
        </div>

        <!-- Selected items list -->
        <div id="poRightList" style="flex:1; overflow-y:auto; min-height:0; padding:0;">
            <div style="padding:2rem; text-align:center; color:#94a3b8; font-size:0.875rem;" id="poEmptyMsg">
                ← Centang barang di sebelah kiri
            </div>
        </div>

        <!-- Footer: submit -->
        <div style="padding:0.85rem 1rem; border-top:1px solid var(--border,#e2e8f0); flex-shrink:0; display:flex; justify-content:space-between; align-items:center; gap:0.5rem; flex-wrap:wrap;">
            <span style="font-size:0.82rem; color:var(--text-muted);">
                <strong id="poRightCount">0</strong> item · Total: <strong id="poRightTotal">Rp 0</strong>
            </span>
            <div style="display:flex; gap:0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="submitPo('save')" style="font-weight:700;">
                    <i data-feather="save" style="width:15px;height:15px;"></i> Simpan PO
                </button>
                <button type="button" class="btn btn-primary" onclick="submitPo('print')" style="font-weight:700;">
                    <i data-feather="printer" style="width:15px;height:15px;"></i> Cetak PO
                </button>
            </div>
        </div>

        <!-- Hidden form for submission -->
        <form id="poHiddenForm" method="POST" style="display:none;">
            <input type="hidden" name="action" value="create_po">
            <input type="hidden" id="phSupplier" name="supplier_id">
            <input type="hidden" id="phNotes" name="notes">
            <input type="hidden" id="phMode" name="po_mode" value="save">
        </form>
    </div>
</div>

<!-- Terima Barang section (jika ada PO yang dibuka) -->
<?php if ($viewPo): ?>
    <div class="card" style="margin-bottom:1.25rem; border:2px solid #0f9d6a;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem;">
            <div>
                <h3 style="font-size:1rem; font-weight:700; margin:0; color:#0f9d6a;">
                    📦 Terima Barang — <?php echo htmlspecialchars($viewPo['po_number']); ?>
                </h3>
                <div style="font-size:0.812rem; color:var(--text-muted); margin-top:0.2rem;">
                    Supplier: <strong><?php echo htmlspecialchars($viewPo['supplier_name'] ?? '-'); ?></strong> &nbsp;|&nbsp;
                    Tanggal PO: <?php echo date('d M Y', strtotime($viewPo['po_date'])); ?>
                </div>
            </div>
            <div style="display:flex; gap:0.5rem;">
                <a href="gudang-po-supplier.php?print=<?php echo (int)$viewPo['id']; ?>" target="_blank" class="btn btn-sm btn-primary" style="font-weight:700;">
                    <i data-feather="printer" style="width:14px;height:14px;"></i> Cetak PO
                </a>
                <a href="gudang-po-supplier.php" class="btn btn-sm btn-secondary">Tutup</a>
            </div>
        </div>

        <?php if (in_array($viewPo['status'], ['submitted', 'approved', 'partially_received'])): ?>
            <?php if (empty($viewPo['items'])): ?>
                <div class="alert alert-danger" style="margin-bottom:1rem;">
                    ⚠️ PO ini tidak memiliki detail item — kemungkinan dibuat saat terjadi error database sebelumnya.<br>
                    <strong>Batalkan PO ini dan buat PO baru.</strong>
                </div>
                <form method="POST" onsubmit="return confirm('Batalkan PO ini?')">
                    <input type="hidden" name="action" value="cancel_po">
                    <input type="hidden" name="po_id" value="<?php echo (int)$viewPo['id']; ?>">
                    <button type="submit" class="btn btn-danger">✕ Batalkan PO Ini</button>
                    <a href="gudang-po-supplier.php" class="btn btn-secondary" style="margin-left:0.5rem;">Kembali</a>
                </form>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="receive_goods">
                    <input type="hidden" name="po_id" value="<?php echo (int)$viewPo['id']; ?>">
                    <div class="table-responsive">
                        <table class="table" style="font-size:0.875rem;">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-right">Qty PO</th>
                                    <th>Unit</th>
                                    <th class="text-right">Sudah Diterima</th>
                                    <th class="text-right">Sisa</th>
                                    <th class="text-right" style="min-width:120px;">Qty Datang Sekarang</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viewPo['items'] as $item):
                                    $remainQty = max(0, (float)$item['quantity'] - (float)($item['received_quantity'] ?? 0));
                                ?>
                                    <tr style="<?php echo $remainQty <= 0 ? 'opacity:0.5;' : ''; ?>">
                                        <td style="font-weight:600;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td class="text-right"><?php echo number_format((float)$item['quantity'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($item['unit_of_measure'] ?: ($item['unit'] ?? '')); ?></td>
                                        <td class="text-right" style="color:#0f9d6a;"><?php echo number_format((float)($item['received_quantity'] ?? 0), 2); ?></td>
                                        <td class="text-right" style="font-weight:600; color:<?php echo $remainQty > 0 ? '#d97706' : '#6b7280'; ?>;"><?php echo number_format($remainQty, 2); ?></td>
                                        <td class="text-right">
                                            <?php if ($remainQty > 0): ?>
                                                <input type="number" name="received_qty[<?php echo (int)$item['id']; ?>]"
                                                    class="form-control" style="width:110px; text-align:right;"
                                                    step="0.01" min="0" max="<?php echo $remainQty; ?>"
                                                    value="<?php echo $remainQty; ?>">
                                            <?php else: ?>
                                                <span style="color:#6b7280;">✓ Lunas</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-group" style="margin-top:0.75rem;">
                        <label class="form-label">Catatan Penerimaan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Catatan kondisi barang, dll."></textarea>
                    </div>
                    <div style="display:flex; justify-content:flex-end; margin-top:1rem;">
                        <button type="submit" class="btn btn-success" style="font-size:0.95rem; padding:0.6rem 1.5rem;">
                            <i data-feather="check-circle" style="width:16px;height:16px;"></i>
                            Tambahkan ke Gudang
                        </button>
                    </div>
                </form>
            <?php endif; // end empty/non-empty items check 
            ?>
        <?php else: ?>
            <div class="alert alert-info">PO ini sudah berstatus <strong><?php echo ucfirst(str_replace('_', ' ', $viewPo['status'])); ?></strong> — semua barang sudah diterima.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Daftar PO Gudang -->
<div class="card">
    <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Daftar PO Supplier Gudang</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No PO</th>
                    <th>Tanggal</th>
                    <th>Supplier</th>
                    <th>Item</th>
                    <th>Status</th>
                    <th class="text-right">Total</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($gudangPOs)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:2.5rem; color:var(--text-muted);">Belum ada PO supplier. Klik "Buat PO Baru" untuk mulai.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($gudangPOs as $po):
                        $statusKey = $normalizePoStatus($po['status'] ?? '');
                        $statusColor = ['submitted' => 'warning', 'pending' => 'warning', 'waiting' => 'warning', 'completed' => 'success', 'received' => 'success', 'cancelled' => 'danger', 'rejected' => 'danger', 'approved' => 'info', 'partially_received' => 'info'][$statusKey] ?? 'secondary';
                        $statusLabel = $poStatusLabelMap[$statusKey] ?? ucfirst(str_replace('_', ' ', $statusKey));
                        $canCancel = in_array($statusKey, ['submitted', 'partially_received', 'approved', 'draft', 'pending', 'waiting'], true);
                        $canDelete = in_array($statusKey, ['draft', 'submitted', 'approved', 'partially_received', 'cancelled', 'rejected', 'pending', 'waiting', 'completed', 'received'], true);
                    ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($po['po_number']); ?></td>
                            <td><?php echo date('d M Y', strtotime($po['po_date'])); ?></td>
                            <td><?php echo htmlspecialchars($po['supplier_name'] ?? '-'); ?></td>
                            <td><?php echo (int)$po['items_count']; ?> item</td>
                            <td><span class="badge badge-<?php echo $statusColor; ?>"><?php echo $statusLabel; ?></span></td>
                            <td class="text-right">Rp <?php echo number_format((float)($po['total_amount'] ?? 0), 0, ',', '.'); ?></td>
                            <td>
                                <div style="display:flex; gap:0.35rem; justify-content:center; flex-wrap:wrap;">
                                    <?php if (in_array($statusKey, ['submitted', 'partially_received', 'approved', 'pending', 'waiting'], true)): ?>
                                        <a href="gudang-po-supplier.php?view=<?php echo (int)$po['id']; ?>" class="btn btn-sm btn-success">
                                            <i data-feather="package" style="width:13px;height:13px;"></i> Terima Barang
                                        </a>
                                    <?php endif; ?>
                                    <a href="gudang-po-supplier.php?print=<?php echo (int)$po['id']; ?>" target="_blank" class="btn btn-sm btn-primary" style="font-weight:700;">
                                        <i data-feather="printer" style="width:13px;height:13px;"></i> Cetak PO
                                    </a>
                                    <?php if ($canCancel): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Batalkan PO ini?')">
                                            <input type="hidden" name="action" value="cancel_po">
                                            <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" title="Batalkan">
                                                <i data-feather="x" style="width:13px;height:13px;"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <form method="POST" id="deletePoForm<?php echo (int)$po['id']; ?>" style="display:inline;" onsubmit="return false;">
                                            <input type="hidden" name="action" value="delete_po">
                                            <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                                        </form>
                                        <button type="button" class="btn btn-sm btn-danger" title="Hapus Permanen"
                                            onclick="showDeletePoModal(<?php echo (int)$po['id']; ?>, '<?php echo htmlspecialchars($po['po_number'], ENT_QUOTES); ?>')">
                                            <i data-feather="trash-2" style="width:13px;height:13px;"></i>
                                        </button>
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

<!-- Detail Produk & Konfirmasi Hapus PO -->
<div id="deletePoModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:2100; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(560px,100%); max-height:85vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Detail Produk</div>
                <h3 id="dpTitle" style="font-size:1.05rem; margin:0.15rem 0 0; font-weight:700;"></h3>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('deletePoModal').style.display='none'">✕</button>
        </div>
        <div id="dpItemsWrap" style="margin-bottom:1rem;">
            <div style="text-align:center; color:var(--text-muted); padding:1.5rem 0;">Memuat detail item...</div>
        </div>
        <div class="alert alert-danger" style="font-size:0.85rem; margin-bottom:1rem;">
            ⚠️ PO beserta seluruh item di atas akan dihapus permanen dan tidak bisa dikembalikan.
        </div>
        <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('deletePoModal').style.display='none'">Batal</button>
            <button type="button" id="dpConfirmBtn" class="btn btn-danger" style="font-weight:700;" onclick="confirmDeletePo()">Ya, Hapus Permanen</button>
        </div>
    </div>
</div>

<style>
    .po-chk-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.55rem 0.85rem;
        border-bottom: 1px solid var(--border, #e2e8f0);
        cursor: pointer;
        transition: background .1s;
    }

    .po-chk-row:hover {
        background: #f8fafc;
    }

    .po-chk-row.selected {
        background: #f0fdf4;
    }

    .po-chk-row input[type=checkbox] {
        width: 17px;
        height: 17px;
        flex-shrink: 0;
        accent-color: #0f9d6a;
    }

    .po-chk-row .item-info {
        flex: 1;
        min-width: 0;
    }

    .po-chk-row .item-info strong {
        display: block;
        font-size: .84rem;
    }

    .po-chk-row .item-meta {
        font-size: .73rem;
        color: #64748b;
    }

    .po-chk-row .item-price {
        font-size: .8rem;
        font-weight: 700;
        color: #0f9d6a;
        white-space: nowrap;
    }

    .po-right-row {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.55rem 1rem;
        border-bottom: 1px solid var(--border, #e2e8f0);
    }

    .po-right-row .item-name {
        flex: 1;
        min-width: 0;
        font-size: .84rem;
        font-weight: 600;
    }

    .po-right-row .item-unit {
        font-size: .75rem;
        color: #64748b;
        white-space: nowrap;
    }
</style>

<script>
    feather.replace();

    var BARANG_LIST = <?php echo json_encode(array_values($allBarang), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    // selected: {id → {nama, satuan, harga, qty}}
    var selected = {};

    function renderLeft(q) {
        var list = document.getElementById('poLeftList');
        var filtered = q ? BARANG_LIST.filter(function(p) {
            return p.nama_barang.toLowerCase().includes(q.toLowerCase());
        }) : BARANG_LIST;

        document.getElementById('poLeftCount').textContent = filtered.length + ' barang';

        if (!filtered.length) {
            list.innerHTML = '<div style="padding:1.5rem;text-align:center;color:#94a3b8;font-size:.875rem;">Tidak ada yang cocok</div>';
            return;
        }

        list.innerHTML = filtered.map(function(p) {
            var harga = parseFloat(p.harga_beli) || 0;
            var stok = parseFloat(p.current_stock) || 0;
            var minStok = parseFloat(p.min_stock) || 0;
            var stokLow = minStok > 0 && stok <= minStok;
            var stokColor = stokLow ? '#dc2626' : '#64748b';
            var stokWeight = stokLow ? '700' : 'normal';
            var stokStr = stok % 1 === 0 ? stok.toLocaleString('id-ID') : stok.toLocaleString('id-ID', {
                maximumFractionDigits: 2
            });
            var stokHtml = '<span style="font-size:.72rem;color:' + stokColor + ';font-weight:' + stokWeight + ';">' +
                (stokLow ? '⚠ ' : '') + 'Stok: ' + stokStr + (minStok > 0 ? ' / min ' + (minStok % 1 === 0 ? minStok.toLocaleString('id-ID') : minStok.toLocaleString('id-ID', {
                    maximumFractionDigits: 2
                })) : '') +
                '</span>';
            var isSelected = !!selected[p.id];
            return '<div class="po-chk-row' + (isSelected ? ' selected' : '') + '" data-id="' + p.id + '" onclick="toggleItem(' + p.id + ')">' +
                '<input type="checkbox"' + (isSelected ? ' checked' : '') + ' onclick="event.stopPropagation();toggleItem(' + p.id + ')">' +
                '<div class="item-info">' +
                '<strong>' + p.nama_barang + '</strong>' +
                '<div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">' +
                '<span class="item-meta">' + (p.satuan || 'pcs') + (p.kode_barang ? ' · ' + p.kode_barang : '') + '</span>' +
                stokHtml +
                '</div>' +
                '</div>' +
                '<span class="item-price">' + (harga > 0 ? 'Rp ' + Math.round(harga).toLocaleString('id-ID') : '—') + '</span>' +
                '</div>';
        }).join('');
    }

    function toggleItem(id) {
        var p = BARANG_LIST.find(function(x) {
            return x.id == id;
        });
        if (!p) return;
        if (selected[id]) {
            delete selected[id];
        } else {
            selected[id] = {
                nama: p.nama_barang,
                satuan: p.satuan || 'pcs',
                harga: parseFloat(p.harga_beli) || 0,
                qty: ''
            };
        }
        renderRight();
        // Update checkbox + row in left panel without full re-render (preserve scroll)
        var row = document.querySelector('#poLeftList [data-id="' + id + '"]');
        if (row) {
            var chk = row.querySelector('input[type=checkbox]');
            if (selected[id]) {
                row.classList.add('selected');
                if (chk) chk.checked = true;
            } else {
                row.classList.remove('selected');
                if (chk) chk.checked = false;
            }
        }
        document.getElementById('poSelectedCount').textContent = Object.keys(selected).length + ' dipilih';
    }

    function renderRight() {
        // Preserve qty currently typed in DOM before re-render
        document.querySelectorAll('#poRightList .po-right-row').forEach(function(row) {
            var key = row.dataset.id;
            var inp = row.querySelector('input[type=number]');
            var expInp = row.querySelector('.expiry-input');
            if (key && selected[key] !== undefined) {
                if (inp) selected[key].qty = inp.value;
                if (expInp) selected[key].expiry = expInp.value;
            }
        });

        var ids = Object.keys(selected);
        var emptyMsg = document.getElementById('poEmptyMsg');
        var rightList = document.getElementById('poRightList');
        document.getElementById('poSelectedCount').textContent = ids.length + ' dipilih';
        document.getElementById('poRightCount').textContent = ids.length;

        if (!ids.length) {
            rightList.innerHTML = '<div style="padding:2rem;text-align:center;color:#94a3b8;font-size:.875rem;" id="poEmptyMsg">← Centang barang di sebelah kiri</div>';
            document.getElementById('poRightTotal').textContent = 'Rp 0';
            return;
        }

        var html = '';
        var total = 0;
        ids.forEach(function(id) {
            var s = selected[id];
            var subtotal = (parseFloat(s.qty) || 0) * s.harga;
            total += subtotal;
            // Label manual items differently so they're visually distinct
            var rowStyle = s.isManual ? 'background:#fffbeb;' : '';
            html += '<div class="po-right-row" data-id=\'' + id + '\' style="' + rowStyle + 'flex-wrap:wrap;gap:0.3rem;">' +
                '<div class="item-name" style="flex:1;min-width:120px;">' + s.nama +
                (s.isManual ? '<span style="font-size:0.68rem;background:#f59e0b;color:#fff;padding:1px 5px;border-radius:4px;margin-left:4px;">Baru</span>' : '') +
                '<br><span class="item-unit">' + s.satuan + (s.harga > 0 ? ' · Rp ' + Math.round(s.harga).toLocaleString('id-ID') : '') + '</span></div>' +
                '<input type="number" class="form-control" style="width:80px;text-align:right;" placeholder="Qty" min="0.01" step="0.01" value="' + (s.qty || '') + '"' +
                ' oninput="selected[\'' + id + '\'].qty=this.value;updateTotal()">' +
                '<span class="item-unit">' + s.satuan + '</span>' +
                '<input type="date" class="form-control expiry-input" style="width:130px;font-size:0.78rem;" title="Tanggal Kadaluarsa (opsional)" value="' + (s.expiry || '') + '"' +
                ' oninput="selected[\'' + id + '\'].expiry=this.value">' +
                '<button type="button" onclick="removePoItem(\'' + id + '\')" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1rem;padding:0 4px;">✕</button>' +
                '</div>';
        });
        rightList.innerHTML = html;
        document.getElementById('poRightTotal').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
    }

    function removePoItem(key) {
        // For DB items, uncheck in left panel; for manual items just delete
        var numKey = parseInt(key, 10);
        if (!isNaN(numKey) && !String(key).startsWith('manual_')) {
            toggleItem(numKey);
        } else {
            delete selected[key];
            renderRight();
            document.getElementById('poSelectedCount').textContent = Object.keys(selected).length + ' dipilih';
        }
    }

    function updateTotal() {
        var total = 0;
        Object.values(selected).forEach(function(s) {
            total += (parseFloat(s.qty) || 0) * s.harga;
        });
        document.getElementById('poRightTotal').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
    }

    function submitPo(mode) {
        var supplierId = document.getElementById('poSupplier').value;
        if (!supplierId) {
            alert('Pilih supplier terlebih dahulu.');
            document.getElementById('poSupplier').focus();
            return;
        }
        var ids = Object.keys(selected);
        if (!ids.length) {
            alert('Centang minimal 1 barang.');
            return;
        }

        var valid = 0;
        ids.forEach(function(id) {
            if (parseFloat(selected[id].qty) > 0) valid++;
        });
        if (!valid) {
            alert('Isi qty untuk barang yang dipilih.');
            return;
        }

        var form = document.getElementById('poHiddenForm');
        form.target = (mode === 'print') ? '_blank' : '_self';
        document.getElementById('phMode').value = mode;
        // Remove old items
        form.querySelectorAll('.ph-item').forEach(function(el) {
            el.remove();
        });
        document.getElementById('phSupplier').value = supplierId;
        document.getElementById('phNotes').value = document.getElementById('poNotes').value;

        var idx = 0;
        ids.forEach(function(id) {
            var s = selected[id];
            var qty = parseFloat(s.qty);
            if (!qty || qty <= 0) return;

            function h(name, val) {
                var el = document.createElement('input');
                el.type = 'hidden';
                el.name = name;
                el.value = val;
                el.className = 'ph-item';
                form.appendChild(el);
            }
            h('items[' + idx + '][item_name]', s.nama);
            h('items[' + idx + '][quantity]', qty);
            h('items[' + idx + '][unit]', s.satuan);
            h('items[' + idx + '][unit_price]', s.harga);
            if (s.expiry) h('items[' + idx + '][expiry_date]', s.expiry);
            idx++;
        });

        form.submit();
    }

    // Init on load
    document.addEventListener('DOMContentLoaded', function() {
        renderLeft('');
        document.getElementById('poLeftSearch').addEventListener('input', function() {
            renderLeft(this.value.trim());
        });
    });

    function addManualItem() {
        var nama = document.getElementById('manualItemName').value.trim();
        var satuan = document.getElementById('manualItemSatuan').value.trim() || 'pcs';
        var harga = parseFloat(document.getElementById('manualItemHarga').value) || 0;
        if (!nama) {
            alert('Tulis nama barang terlebih dahulu.');
            document.getElementById('manualItemName').focus();
            return;
        }
        // Deduplicate by lowercased name
        var key = 'manual_' + nama.toLowerCase().replace(/\s+/g, '_');
        if (selected[key]) {
            alert('"' + nama + '" sudah ada di daftar PO.');
            return;
        }
        selected[key] = {
            nama: nama,
            satuan: satuan,
            harga: harga,
            qty: '',
            isManual: true
        };
        renderRight();
        document.getElementById('manualItemName').value = '';
        document.getElementById('manualItemHarga').value = '';
        document.getElementById('poSelectedCount').textContent = Object.keys(selected).length + ' dipilih';
    }

    var deletePoTargetId = null;

    function showDeletePoModal(poId, poNumber) {
        deletePoTargetId = poId;
        document.getElementById('dpTitle').textContent = poNumber;
        var wrap = document.getElementById('dpItemsWrap');
        wrap.innerHTML = '<div style="text-align:center; color:var(--text-muted); padding:1.5rem 0;">Memuat detail item...</div>';
        document.getElementById('deletePoModal').style.display = 'flex';

        fetch('gudang-po-supplier.php?ajax_po_items=1&po_id=' + encodeURIComponent(poId))
            .then(function(res) {
                return res.json();
            })
            .then(function(data) {
                if (!data.success) {
                    wrap.innerHTML = '<div class="alert alert-danger">' + (data.message || 'Gagal memuat detail.') + '</div>';
                    return;
                }
                if (!data.items || !data.items.length) {
                    wrap.innerHTML = '<div style="color:var(--text-muted);">Tidak ada item pada PO ini.</div>';
                    return;
                }
                var rows = data.items.map(function(it) {
                    var qty = parseFloat(it.quantity) || 0;
                    var received = parseFloat(it.received_quantity) || 0;
                    var unit = it.unit_of_measure || it.unit || '';
                    return '<tr>' +
                        '<td style="font-weight:600;">' + escapeHtml(it.item_name) + '</td>' +
                        '<td class="text-right">' + qty.toLocaleString('id-ID', {
                            minimumFractionDigits: 2
                        }) + '</td>' +
                        '<td>' + escapeHtml(unit) + '</td>' +
                        '<td class="text-right">' + received.toLocaleString('id-ID', {
                            minimumFractionDigits: 2
                        }) + '</td>' +
                        '</tr>';
                }).join('');
                wrap.innerHTML = '<div class="table-responsive"><table class="table" style="font-size:0.875rem;">' +
                    '<thead><tr><th>Item</th><th class="text-right">Qty PO</th><th>Unit</th><th class="text-right">Sudah Diterima</th></tr></thead>' +
                    '<tbody>' + rows + '</tbody></table></div>';
            })
            .catch(function() {
                wrap.innerHTML = '<div class="alert alert-danger">Gagal memuat detail item.</div>';
            });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function confirmDeletePo() {
        if (!deletePoTargetId) return;
        var form = document.getElementById('deletePoForm' + deletePoTargetId);
        if (form) form.submit();
    }
</script>

<?php include '../../includes/footer.php'; ?>