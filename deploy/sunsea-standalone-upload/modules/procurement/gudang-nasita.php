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
$pageTitle = 'Gudang Nasita';

// TEMP DIAGNOSTIC (auth-gated by the requireLogin()+hasPermission() checks above) — remove after debugging.
if (($_GET['debug_bintang'] ?? '') === '1') {
    header('Content-Type: application/json');
    $fixResult = null;
    if (($_GET['fix'] ?? '') === '1') {
        try {
            $db->getConnection()->beginTransaction();
            // Row id=4 ("Bir Bintang", qty~1471) was wrongly linked to the "Bir Bintang Large"
            // catalog entry (id=3) instead of the correct "Bir Bintang" catalog entry (id=60).
            // Force-correct the link and restore both rows to active (they got deactivated somewhere along the way).
            $db->query("UPDATE gudang_nasita_stock SET barang_id = 60, is_active = 1 WHERE id = 4");
            $db->query("UPDATE gudang_nasita_barang SET is_active = 1 WHERE id = 60");
            // Deactivate duplicate/confusing "large"/"small" catalog entries so only BRG-0028/0029 remain selectable.
            $db->query("UPDATE gudang_nasita_barang SET is_active = 0 WHERE id IN (3, 24, 25)");
            $db->getConnection()->commit();
            $fixResult = 'applied';
        } catch (Throwable $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->getConnection()->rollBack();
            }
            $fixResult = 'error: ' . $e->getMessage();
        }
    }
    $stockRows = $db->fetchAll("SELECT id, stock_code, item_name, category, barang_id, quantity, is_active FROM gudang_nasita_stock WHERE item_name LIKE '%bintang%' ORDER BY id");
    $barangRows = [];
    try {
        $barangRows = $db->fetchAll("SELECT id, kode_barang, nama_barang, is_active FROM gudang_nasita_barang WHERE nama_barang LIKE '%bintang%' ORDER BY id");
    } catch (Throwable $e) {
        $barangRows = ['error' => $e->getMessage()];
    }
    echo json_encode(['fix' => $fixResult, 'stock' => $stockRows, 'barang' => $barangRows], JSON_PRETTY_PRINT);
    exit;
}

function gudangImportNormalizeHeader(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value ?? '');
    $value = strtolower((string)$value);
    return str_replace([' ', '_', '-'], '', $value);
}

function gudangImportParseNumber($value): float
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0.0;
    }

    $value = str_replace(["\xc2\xa0", ' '], '', $value);
    $hasComma = strpos($value, ',') !== false;
    $hasDot = strpos($value, '.') !== false;

    if ($hasComma && $hasDot) {
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma > $lastDot) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($hasComma) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function gudangImportParseTable(string $content): array
{
    $content = trim($content);
    if ($content === '') {
        throw new Exception('File / data import kosong.');
    }

    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $content)), static function ($line) {
        return $line !== '';
    }));

    if (empty($lines)) {
        throw new Exception('Tidak ada baris data yang terbaca dari import.');
    }

    $firstLine = $lines[0];
    $delimiter = "\t";
    if (substr_count($firstLine, "\t") === 0) {
        $semicolonCount = substr_count($firstLine, ';');
        $commaCount = substr_count($firstLine, ',');
        $delimiter = $semicolonCount > $commaCount ? ';' : ',';
    }

    $rows = [];
    foreach ($lines as $line) {
        $rows[] = str_getcsv($line, $delimiter);
    }

    return gudangImportParseRows($rows);
}

function gudangImportColumnLettersToIndex(string $letters): int
{
    $letters = strtoupper(trim($letters));
    $index = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function gudangImportParseRows(array $rows): array
{
    if (empty($rows)) {
        throw new Exception('Tidak ada baris data yang terbaca dari import.');
    }

    $header = array_map('gudangImportNormalizeHeader', $rows[0]);
    $headerMap = array_flip($header);

    $nameIndex = $headerMap['namabarang'] ?? $headerMap['item'] ?? null;
    $unitIndex = $headerMap['satuan'] ?? $headerMap['unit'] ?? null;
    $qtyIndex = $headerMap['saldoawal'] ?? $headerMap['qty'] ?? $headerMap['quantity'] ?? $headerMap['stokawal'] ?? null;
    $categoryIndex = $headerMap['kategori'] ?? null;
    $supplierIndex = $headerMap['supplier'] ?? $headerMap['namasupplier'] ?? null;
    $reorderIndex = $headerMap['reorder'] ?? $headerMap['reorderlevel'] ?? $headerMap['minimumstok'] ?? null;
    $notesIndex = $headerMap['catatan'] ?? $headerMap['keterangan'] ?? $headerMap['notes'] ?? null;

    if ($nameIndex === null || $qtyIndex === null) {
        throw new Exception('Header wajib minimal: NAMA BARANG dan SALDO AWAL/QTY.');
    }

    $parsedRows = [];
    foreach (array_slice($rows, 1) as $rowNumber => $row) {
        $itemName = trim((string)($row[$nameIndex] ?? ''));
        $quantity = gudangImportParseNumber($row[$qtyIndex] ?? '');

        if ($itemName === '' && $quantity <= 0) {
            continue;
        }

        $parsedRows[] = [
            'excel_row' => $rowNumber + 2,
            'item_name' => $itemName,
            'unit' => trim((string)($row[$unitIndex] ?? '')),
            'quantity' => $quantity,
            'category' => trim((string)($row[$categoryIndex] ?? '')),
            'supplier_name' => trim((string)($row[$supplierIndex] ?? '')),
            'reorder_level' => $reorderIndex !== null ? gudangImportParseNumber($row[$reorderIndex] ?? '') : null,
            'notes' => trim((string)($row[$notesIndex] ?? '')),
        ];
    }

    return $parsedRows;
}

function gudangImportParseXlsxFile(string $tmpPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new Exception('Server belum mendukung pembacaan file Excel .xlsx karena ekstensi ZIP PHP tidak aktif. Gunakan CSV atau copy-paste data Excel.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        throw new Exception('File Excel .xlsx tidak bisa dibuka.');
    }

    $sharedStrings = [];
    $sharedXmlRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXmlRaw !== false) {
        $sharedXml = @simplexml_load_string($sharedXmlRaw);
        if ($sharedXml && isset($sharedXml->si)) {
            foreach ($sharedXml->si as $si) {
                $parts = [];
                if (isset($si->t)) {
                    $parts[] = (string)$si->t;
                }
                if (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $parts[] = (string)$run->t;
                    }
                }
                $sharedStrings[] = implode('', $parts);
            }
        }
    }

    $sheetPath = 'xl/worksheets/sheet1.xml';
    $sheetXmlRaw = $zip->getFromName($sheetPath);
    if ($sheetXmlRaw === false) {
        $zip->close();
        throw new Exception('Worksheet pertama pada file Excel tidak ditemukan.');
    }

    $sheetXml = @simplexml_load_string($sheetXmlRaw);
    if (!$sheetXml || !isset($sheetXml->sheetData)) {
        $zip->close();
        throw new Exception('Format worksheet Excel tidak valid.');
    }

    $rows = [];
    foreach ($sheetXml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $ref = (string)($cell['r'] ?? '');
            $letters = preg_replace('/[^A-Z]/i', '', $ref);
            $colIndex = gudangImportColumnLettersToIndex($letters);
            $type = (string)($cell['t'] ?? '');
            $value = '';

            if ($type === 's') {
                $sharedIndex = (int)($cell->v ?? 0);
                $value = (string)($sharedStrings[$sharedIndex] ?? '');
            } elseif ($type === 'inlineStr') {
                $value = (string)($cell->is->t ?? '');
            } else {
                $value = (string)($cell->v ?? '');
            }

            $cells[$colIndex] = $value;
        }

        if (!empty($cells)) {
            ksort($cells);
            $maxIndex = max(array_keys($cells));
            $normalized = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $normalized[] = isset($cells[$i]) ? (string)$cells[$i] : '';
            }
            $rows[] = $normalized;
        }
    }

    $zip->close();
    return gudangImportParseRows($rows);
}

function gudangImportReadUpload(array $file): string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new Exception('Upload file import gagal.');
    }

    $name = strtolower((string)($file['name'] ?? ''));

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new Exception('File import temporary tidak valid.');
    }

    if (preg_match('/\.xlsx$/', $name)) {
        return json_encode(gudangImportParseXlsxFile($tmpPath), JSON_UNESCAPED_UNICODE);
    }

    if (preg_match('/\.xls$/', $name)) {
        throw new Exception('Format Excel .xls lama belum didukung. Silakan Save As .xlsx atau CSV, lalu upload lagi.');
    }

    $content = file_get_contents($tmpPath);
    if ($content === false) {
        throw new Exception('File import tidak bisa dibaca.');
    }

    return mb_convert_encoding($content, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_stock_in') {
    $itemName = trim($_POST['item_name'] ?? '');
    $category = trim($_POST['category'] ?? 'lainnya');
    $unit = trim($_POST['unit'] ?? 'pcs');
    $quantity = (float)($_POST['quantity'] ?? 0);
    $unitPrice = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : 0;
    $supplierName = trim($_POST['supplier_name'] ?? '');
    $reorderLevel = isset($_POST['reorder_level']) ? (float)$_POST['reorder_level'] : null;
    $notes = trim($_POST['notes'] ?? '');

    $result = addGudangNasitaManualStock($itemName, $unit, $quantity, $currentUser['id'], [
        'category' => $category,
        'unit_price' => $unitPrice,
        'supplier_name' => $supplierName,
        'reorder_level' => $reorderLevel,
        'notes' => $notes,
    ]);

    if ($result['success']) {
        $_SESSION['success'] = $result['message'];
    } else {
        $_SESSION['error'] = $result['message'];
    }

    header('Location: gudang-nasita.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'stock_out_daily') {
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $quantity = (float)($_POST['quantity'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($itemName === '' || $quantity <= 0) {
        $_SESSION['error'] = 'Nama item dan qty stock keluar wajib diisi.';
    } else {
        $result = recordGudangNasitaDailyStockOut($itemName, $quantity, (int)($currentUser['id'] ?? 0), [
            'notes' => $notes,
        ]);

        if ($result['success']) {
            $_SESSION['success'] = $result['message'] . ' untuk ' . htmlspecialchars($itemName, ENT_QUOTES) . '.';
        } else {
            $_SESSION['error'] = $result['message'];
        }
    }

    header('Location: gudang-nasita.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_stock_sheet') {
    try {
        $defaultCategory = trim((string)($_POST['default_category'] ?? 'lainnya'));
        if ($defaultCategory === '') {
            $defaultCategory = 'lainnya';
        }
        $defaultSupplier = trim((string)($_POST['default_supplier_name'] ?? ''));
        $defaultNotes = trim((string)($_POST['default_notes'] ?? 'Import stok awal gudang'));
        $pastedData = trim((string)($_POST['import_paste_data'] ?? ''));
        $uploadContent = !empty($_FILES['import_stock_file']) ? gudangImportReadUpload((array)$_FILES['import_stock_file']) : '';
        $rawContent = $pastedData !== '' ? $pastedData : $uploadContent;

        if ($rawContent === '') {
            throw new Exception('Pilih file import atau paste data Excel terlebih dulu.');
        }

        if ($pastedData !== '') {
            $rows = gudangImportParseTable($pastedData);
        } elseif (!empty($_FILES['import_stock_file']['name']) && preg_match('/\.xlsx$/i', (string)$_FILES['import_stock_file']['name'])) {
            $decodedRows = json_decode($uploadContent, true);
            if (!is_array($decodedRows)) {
                throw new Exception('Data Excel .xlsx tidak bisa diproses.');
            }
            $rows = $decodedRows;
        } else {
            $rows = gudangImportParseTable($uploadContent);
        }

        if (empty($rows)) {
            throw new Exception('Tidak ada data stok yang valid untuk diimport.');
        }

        $successCount = 0;
        $skippedCount = 0;
        $failReasons = [];

        foreach ($rows as $row) {
            $itemName = trim((string)$row['item_name']);
            $quantity = (float)$row['quantity'];
            if ($itemName === '') {
                $skippedCount++;
                $failReasons[] = 'Baris ' . $row['excel_row'] . ': nama barang kosong.';
                continue;
            }
            if ($quantity <= 0) {
                $skippedCount++;
                $failReasons[] = 'Baris ' . $row['excel_row'] . ' (' . $itemName . '): saldo awal harus lebih dari 0.';
                continue;
            }

            $result = addGudangNasitaManualStock(
                $itemName,
                trim((string)$row['unit']) !== '' ? trim((string)$row['unit']) : 'pcs',
                $quantity,
                (int)($currentUser['id'] ?? 0),
                [
                    'category' => trim((string)$row['category']) !== '' ? trim((string)$row['category']) : $defaultCategory,
                    'supplier_name' => trim((string)$row['supplier_name']) !== '' ? trim((string)$row['supplier_name']) : $defaultSupplier,
                    'reorder_level' => $row['reorder_level'] !== null ? (float)$row['reorder_level'] : 0,
                    'notes' => trim((string)$row['notes']) !== '' ? trim((string)$row['notes']) : $defaultNotes,
                ]
            );

            if (!($result['success'] ?? false)) {
                $skippedCount++;
                $failReasons[] = 'Baris ' . $row['excel_row'] . ' (' . $itemName . '): ' . (string)($result['message'] ?? 'gagal import');
                continue;
            }

            $successCount++;
        }

        if ($successCount <= 0) {
            $detail = empty($failReasons) ? '' : ' Detail: ' . implode(' | ', array_slice($failReasons, 0, 4));
            throw new Exception('Import stok gagal.' . $detail);
        }

        $_SESSION['success'] = $successCount . ' item berhasil diimport ke stok gudang.' . ($skippedCount > 0 ? ' ' . $skippedCount . ' baris dilewati.' : '');
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: gudang-nasita.php');
    exit;
}

// Handle hapus stock item (soft delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_stock') {
    $stockId = (int)($_POST['stock_id'] ?? 0);
    if ($stockId > 0) {
        $db->update('gudang_nasita_stock', ['is_active' => 0], 'id = :id', ['id' => $stockId]);
        $_SESSION['success'] = 'Item stok berhasil dihapus.';
    }
    header('Location: gudang-nasita.php');
    exit;
}

// Reset all active Gudang stock quantities to zero.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_stock_zero') {
    try {
        $hasJumlahStok = function_exists('gudangNasitaStockHasColumn') ? gudangNasitaStockHasColumn('jumlah_stok') : false;
        $hasBusinessId = function_exists('gudangNasitaStockHasColumn') ? gudangNasitaStockHasColumn('business_id') : false;
        $activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

        if ($hasBusinessId && $activeBusinessId > 0) {
            if ($hasJumlahStok) {
                $db->query('UPDATE gudang_nasita_stock SET quantity = 0, jumlah_stok = 0 WHERE COALESCE(is_active,1) = 1 AND business_id = ?', [$activeBusinessId]);
            } else {
                $db->query('UPDATE gudang_nasita_stock SET quantity = 0 WHERE COALESCE(is_active,1) = 1 AND business_id = ?', [$activeBusinessId]);
            }
        } else {
            if ($hasJumlahStok) {
                $db->query('UPDATE gudang_nasita_stock SET quantity = 0, jumlah_stok = 0 WHERE COALESCE(is_active,1) = 1');
            } else {
                $db->query('UPDATE gudang_nasita_stock SET quantity = 0 WHERE COALESCE(is_active,1) = 1');
            }
        }

        $_SESSION['success'] = 'Stok Gudang berhasil di-reset ke 0.';
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Gagal reset stok: ' . $e->getMessage();
    }

    header('Location: gudang-nasita.php');
    exit;
}

// Handle hapus/batalkan permintaan PO dari bisnis (cross-DB)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_pending_po') {
    $poId   = (int)($_POST['po_id'] ?? 0);
    $poSlug = trim($_POST['po_slug'] ?? '');
    $allowedSlugs = ['narayana-hotel', 'bens-cafe', 'eaat-meet', 'eat-meet'];
    if ($poId > 0 && in_array($poSlug, $allowedSlugs, true)) {
        $cfgPath = __DIR__ . '/../../config/businesses/' . $poSlug . '.php';
        if (file_exists($cfgPath)) {
            $cfg = require $cfgPath;
            $bizDbName = (string)($cfg['database'] ?? '');
            if ($bizDbName !== '') {
                try {
                    $originDb = Database::getCurrentDatabase();
                    $bizDb = Database::switchDatabase($bizDbName);
                    $bizDb->update('purchase_orders_header', ['status' => 'cancelled'], 'id = :id', ['id' => $poId]);
                    if (!empty($originDb)) {
                        Database::switchDatabase($originDb);
                        $db = Database::getInstance();
                    }
                    $_SESSION['success'] = 'Permintaan PO berhasil dibatalkan.';
                } catch (Throwable $e) {
                    $_SESSION['error'] = 'Gagal batalkan PO: ' . $e->getMessage();
                }
            }
        }
    }
    header('Location: gudang-nasita.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'order_supplier') {
    $poItemName = trim($_POST['item_name'] ?? '');
    $poUnit     = trim($_POST['unit'] ?? 'pcs');
    $poQty      = (float)($_POST['quantity'] ?? 0);
    $poSupplierId = (int)($_POST['supplier_id'] ?? 0);
    $poNotes    = trim($_POST['notes'] ?? '');

    if ($poItemName === '' || $poQty <= 0 || $poSupplierId <= 0) {
        $_SESSION['error'] = 'Nama item, qty, dan supplier wajib diisi.';
    } else {
        try {
            // Generate PO number for gudang restocking
            $poPrefix = 'GDN-' . date('Ymd') . '-';
            $lastPo = $db->fetchOne("SELECT po_number FROM purchase_orders_header WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1", [$poPrefix . '%']);
            $poSeq = $lastPo ? ((int)substr($lastPo['po_number'], -3) + 1) : 1;
            $poNumber = $poPrefix . str_pad($poSeq, 3, '0', STR_PAD_LEFT);

            $poHeaderId = $db->insert('purchase_orders_header', [
                'business_id'   => null,
                'po_number'     => $poNumber,
                'supplier_id'   => $poSupplierId,
                'po_date'       => date('Y-m-d'),
                'status'        => 'submitted',
                'total_amount'  => 0,
                'notes'         => $poNotes ?: 'Restock Gudang Nasita',
                // Verify user exists in this DB; fallback to first available user to avoid FK/NOT NULL violation
                'created_by'    => ($db->fetchOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$currentUser['id']]) ? $currentUser['id'] : ($db->fetchOne('SELECT id FROM users ORDER BY id ASC LIMIT 1')['id'] ?? 1)),
            ]);
            $db->insert('purchase_orders_detail', [
                'po_header_id'  => $poHeaderId,
                'item_name'     => $poItemName,
                'quantity'      => $poQty,
                'unit'          => $poUnit,
                'unit_price'    => 0,
                'total_price'   => 0,
            ]);
            $_SESSION['success'] = 'PO ' . $poNumber . ' ke supplier berhasil dibuat.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Gagal buat PO supplier: ' . $e->getMessage();
        }
    }
    header('Location: gudang-nasita.php');
    exit;
}

$stockItemsAll = [];
try {
    $stockItemsAll = getGudangNasitaStock(1000);
} catch (Throwable $e) {
    error_log('gudang-nasita stock load failed: ' . $e->getMessage());
    $stockItemsAll = [];
}
$searchItemName = trim((string)($_GET['q_item'] ?? ''));
$filterLowStockOnly = (string)($_GET['low_stock'] ?? '') === '1';

$stockItems = array_values(array_filter($stockItemsAll, function ($item) use ($searchItemName, $filterLowStockOnly) {
    $itemName = (string)($item['item_name'] ?? '');
    $isLow = (float)($item['reorder_level'] ?? 0) > 0 && (float)$item['quantity'] <= (float)$item['reorder_level'];

    if ($searchItemName !== '' && stripos($itemName, $searchItemName) === false) {
        return false;
    }
    if ($filterLowStockOnly && !$isLow) {
        return false;
    }

    return true;
}));

if (isset($_GET['print_stock']) && (string)$_GET['print_stock'] === '1') {
    $printItems = $stockItemsAll;
    $totalQty = 0;
    $totalValue = 0;
    foreach ($printItems as $pi) {
        $totalQty += (float)($pi['quantity'] ?? 0);
        $totalValue += (float)($pi['total_harga'] ?? ((float)($pi['quantity'] ?? 0) * (float)($pi['harga_beli'] ?? 0)));
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Cetak Semua Stok Gudang Nasita</title>';
    echo '<style>body{font-family:Arial,sans-serif;font-size:12px;margin:20px;}h2{margin:0 0 4px;}table{width:100%;border-collapse:collapse;margin-top:12px;}th,td{border:1px solid #999;padding:6px 8px;text-align:left;}th{background:#f0f0f0;}.text-right{text-align:right;}@media print{button{display:none}}</style>';
    echo '</head><body>';
    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">';
    echo '<div><h2>STOK GUDANG NASITA</h2><strong>ADF System</strong><br>Dicetak: ' . date('d M Y H:i') . '</div>';
    echo '<div style="text-align:right;"><strong>Total Item:</strong> ' . count($printItems) . '<br><strong>Total Qty:</strong> ' . number_format($totalQty, 2) . '<br><strong>Total Nilai:</strong> Rp ' . number_format($totalValue, 0, ',', '.') . '</div>';
    echo '</div>';
    echo '<table><thead><tr><th>No</th><th>Kode</th><th>Kategori</th><th>Item</th><th class="text-right">Qty</th><th class="text-right">Harga/pcs</th><th class="text-right">Nilai</th><th>Unit</th><th>Supplier</th><th>Reorder</th></tr></thead><tbody>';
    if (empty($printItems)) {
        echo '<tr><td colspan="10" style="text-align:center;">Belum ada stok gudang</td></tr>';
    } else {
        foreach ($printItems as $idx => $item) {
            $code = (string)($item['stock_code'] ?? ('GN-LEGACY-' . str_pad((string)($item['id'] ?? 0), 4, '0', STR_PAD_LEFT)));
            $itemQty = (float)($item['quantity'] ?? 0);
            $itemUnitCost = (float)($item['harga_beli'] ?? 0);
            $itemValue = (float)($item['total_harga'] ?? ($itemQty * $itemUnitCost));
            echo '<tr>';
            echo '<td>' . ($idx + 1) . '</td>';
            echo '<td>' . htmlspecialchars($code) . '</td>';
            echo '<td>' . htmlspecialchars((string)($item['category'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string)($item['item_name'] ?? '-')) . '</td>';
            echo '<td class="text-right">' . number_format($itemQty, 2) . '</td>';
            echo '<td class="text-right">Rp ' . number_format($itemUnitCost, 0, ',', '.') . '</td>';
            echo '<td class="text-right">Rp ' . number_format($itemValue, 0, ',', '.') . '</td>';
            echo '<td>' . htmlspecialchars((string)($item['unit'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string)($item['supplier_name'] ?? '-')) . '</td>';
            echo '<td>' . number_format((float)($item['reorder_level'] ?? 0), 2) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '<br><button onclick="window.print()">Cetak</button>';
    echo '</body></html>';
    exit;
}

if (isset($_GET['print_stock_out']) && (string)$_GET['print_stock_out'] === '1') {
    $dailyOutRows = $db->fetchAll("SELECT gm.*, gs.item_name, gs.unit, u.full_name AS created_by_name FROM gudang_nasita_movements gm LEFT JOIN gudang_nasita_stock gs ON gs.id = gm.stock_id LEFT JOIN users u ON u.id = gm.created_by WHERE gm.movement_date = CURDATE() AND gm.movement_type IN ('out_transfer', 'adjustment') ORDER BY gm.created_at DESC");
    $dailyOutTotalQty = 0;
    $dailyOutTotalValue = 0;
    foreach ($dailyOutRows as $row) {
        $dailyOutTotalQty += (float)($row['quantity'] ?? 0);
        $dailyOutTotalValue += (float)($row['subtotal'] ?? 0);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Cetak Pengeluaran Stok Harian Gudang</title>';
    echo '<style>body{font-family:Arial,sans-serif;font-size:12px;margin:20px;}h2{margin:0 0 4px;}table{width:100%;border-collapse:collapse;margin-top:12px;}th,td{border:1px solid #999;padding:6px 8px;text-align:left;}th{background:#f0f0f0;}.text-right{text-align:right;}@media print{button{display:none}}</style>';
    echo '</head><body>';
    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">';
    echo '<div><h2>PENGELUARAN STOK HARIAN</h2><strong>Gudang Nasita</strong><br>Dicetak: ' . date('d M Y H:i') . '</div>';
    echo '<div style="text-align:right;"><strong>Total Item Keluar:</strong> ' . count($dailyOutRows) . '<br><strong>Total Qty:</strong> ' . number_format($dailyOutTotalQty, 2) . '<br><strong>Total Nilai:</strong> Rp ' . number_format($dailyOutTotalValue, 0, ',', '.') . '</div>';
    echo '</div>';
    echo '<table><thead><tr><th>No</th><th>Item</th><th>Unit</th><th>Qty</th><th>Nilai</th><th>Catatan</th><th>Petugas</th></tr></thead><tbody>';
    if (empty($dailyOutRows)) {
        echo '<tr><td colspan="7" style="text-align:center;">Belum ada pengeluaran stok hari ini</td></tr>';
    } else {
        foreach ($dailyOutRows as $idx => $row) {
            echo '<tr>';
            echo '<td>' . ($idx + 1) . '</td>';
            echo '<td>' . htmlspecialchars((string)($row['item_name'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string)($row['unit'] ?? '-')) . '</td>';
            echo '<td class="text-right">' . number_format((float)($row['quantity'] ?? 0), 2) . '</td>';
            echo '<td class="text-right">Rp ' . number_format((float)($row['subtotal'] ?? 0), 0, ',', '.') . '</td>';
            echo '<td>' . htmlspecialchars((string)($row['notes'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string)($row['created_by_name'] ?? '-')) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '<br><button onclick="window.print()">Cetak</button>';
    echo '</body></html>';
    exit;
}

if (isset($_GET['export_excel']) && (string)$_GET['export_excel'] === '1') {
    $exportItems = $stockItems;
    $filename = 'stok-gudang-nasita-' . date('Ymd-His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    echo "<table border='1'>";
    echo "<tr><th>No</th><th>Kode</th><th>Kategori</th><th>Item</th><th>Qty</th><th>Unit</th><th>Supplier</th><th>Reorder Level</th><th>Status</th></tr>";
    foreach ($exportItems as $idx => $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $reorder = (float)($item['reorder_level'] ?? 0);
        $isLow = $reorder > 0 && $qty <= $reorder;
        $code = (string)($item['stock_code'] ?? ('GN-LEGACY-' . str_pad((string)($item['id'] ?? 0), 4, '0', STR_PAD_LEFT)));
        echo '<tr>';
        echo '<td>' . ($idx + 1) . '</td>';
        echo '<td>' . htmlspecialchars($code) . '</td>';
        echo '<td>' . htmlspecialchars((string)($item['category'] ?? '-')) . '</td>';
        echo '<td>' . htmlspecialchars((string)($item['item_name'] ?? '-')) . '</td>';
        echo '<td style="text-align:right;">' . number_format($qty, 2, '.', '') . '</td>';
        echo '<td>' . htmlspecialchars((string)($item['unit'] ?? 'pcs')) . '</td>';
        echo '<td>' . htmlspecialchars((string)($item['supplier_name'] ?? '-')) . '</td>';
        echo '<td style="text-align:right;">' . number_format($reorder, 2, '.', '') . '</td>';
        echo '<td>' . ($isLow ? 'Stok Menipis' : 'Aman') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

if (isset($_GET['export_pdf']) && (string)$_GET['export_pdf'] === '1') {
    $exportItems = $stockItems;
    $pdfLib = __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';

    if (file_exists($pdfLib)) {
        require_once $pdfLib;

        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('ADF System');
        $pdf->SetAuthor('Gudang Nasita');
        $pdf->SetTitle('Laporan Stok Gudang Nasita');
        $pdf->SetMargins(8, 8, 8);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $html = '<h2 style="margin:0;">Laporan Stok Gudang Nasita</h2>';
        $html .= '<p style="font-size:11px; margin:4px 0 10px;">Tanggal cetak: ' . date('d M Y H:i') . '</p>';
        $html .= '<table border="1" cellpadding="4">';
        $html .= '<tr style="font-weight:bold;background-color:#f1f5f9;">'
            . '<th width="26">No</th>'
            . '<th width="88">Kode</th>'
            . '<th width="78">Kategori</th>'
            . '<th width="180">Item</th>'
            . '<th width="56" align="right">Qty</th>'
            . '<th width="45">Unit</th>'
            . '<th width="130">Supplier</th>'
            . '<th width="72" align="right">Reorder</th>'
            . '<th width="84">Status</th>'
            . '</tr>';

        foreach ($exportItems as $idx => $item) {
            $qty = (float)($item['quantity'] ?? 0);
            $reorder = (float)($item['reorder_level'] ?? 0);
            $isLow = $reorder > 0 && $qty <= $reorder;
            $code = (string)($item['stock_code'] ?? ('GN-LEGACY-' . str_pad((string)($item['id'] ?? 0), 4, '0', STR_PAD_LEFT)));
            $html .= '<tr>'
                . '<td>' . ($idx + 1) . '</td>'
                . '<td>' . htmlspecialchars($code) . '</td>'
                . '<td>' . htmlspecialchars((string)($item['category'] ?? '-')) . '</td>'
                . '<td>' . htmlspecialchars((string)($item['item_name'] ?? '-')) . '</td>'
                . '<td align="right">' . number_format($qty, 2) . '</td>'
                . '<td>' . htmlspecialchars((string)($item['unit'] ?? 'pcs')) . '</td>'
                . '<td>' . htmlspecialchars((string)($item['supplier_name'] ?? '-')) . '</td>'
                . '<td align="right">' . number_format($reorder, 2) . '</td>'
                . '<td>' . ($isLow ? 'Stok Menipis' : 'Aman') . '</td>'
                . '</tr>';
        }

        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('stok-gudang-nasita-' . date('Ymd-His') . '.pdf', 'D');
        exit;
    }

    // Fallback if TCPDF is not available.
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Export PDF Stok Gudang Nasita</title></head><body>';
    echo '<script>window.print();</script>';
    echo '<h3>TCPDF tidak tersedia. Gunakan Save as PDF dari dialog print.</h3>';
    echo '</body></html>';
    exit;
}

$recentTransfers = [];
try {
    $recentTransfers = getGudangNasitaTransfers(15);
} catch (Throwable $e) {
    error_log('gudang-nasita transfer load failed: ' . $e->getMessage());
    $recentTransfers = [];
}

// Collect low-stock items for prominent alert
$lowStockItems = array_values(array_filter($stockItems, function ($item) {
    return (float)($item['reorder_level'] ?? 0) > 0 && (float)$item['quantity'] <= (float)$item['reorder_level'];
}));

// Load suppliers for the order-to-supplier modal
$gudangSuppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers WHERE (is_active = 1 OR is_active IS NULL) ORDER BY supplier_name ASC");
if (empty($gudangSuppliers)) {
    $gudangSuppliers = $db->fetchAll("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC");
}

$summary = [
    'items' => count($stockItems),
    'qty' => 0,
    'low' => 0,
    'incoming_today' => 0,
    'outgoing_today' => 0,
    'value' => 0,
];

foreach ($stockItems as $item) {
    $summary['qty'] += (float)$item['quantity'];
    $summary['value'] += (float)($item['total_harga'] ?? ((float)$item['quantity'] * (float)($item['harga_beli'] ?? 0)));
    if ((float)$item['quantity'] <= (float)($item['reorder_level'] ?? 0) && (float)($item['reorder_level'] ?? 0) > 0) {
        $summary['low']++;
    }
}

$movementSummary = $db->fetchAll("\n    SELECT movement_type, COALESCE(SUM(quantity), 0) AS total_qty\n    FROM gudang_nasita_movements\n    WHERE movement_date = CURDATE()\n    GROUP BY movement_type\n");
foreach ($movementSummary as $row) {
    if ($row['movement_type'] === 'in_supplier') {
        $summary['incoming_today'] = (float)$row['total_qty'];
    }
    if ($row['movement_type'] === 'out_transfer' || $row['movement_type'] === 'adjustment') {
        $summary['outgoing_today'] += (float)$row['total_qty'];
    }
}

$pendingReceipts = [];
function gudangResolveBusinessDbName(string $dbName): string
{
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

    if (!$isProduction) {
        return $dbName;
    }

    $dbMapping = [
        'adf_system' => 'adfb2574_adf',
        'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
        'adf_benscafe' => 'adfb2574_Adf_Bens',
        'adf_eat_meet' => 'adfb2574_eat_meet',
        'adf_demo' => 'adfb2574_demo',
        'adf_cqc' => 'adfb2574_cqc',
    ];

    if (isset($dbMapping[$dbName])) {
        return $dbMapping[$dbName];
    }

    if (strpos($dbName, 'adf_') === 0) {
        $hostingPrefix = 'adfb2574_';
        if (defined('DB_USER')) {
            $parts = explode('_', DB_USER);
            if (count($parts) >= 2) {
                $hostingPrefix = $parts[0] . '_';
            }
        }
        return $hostingPrefix . substr($dbName, 4);
    }

    return $dbName;
}

function gudangFetchPendingPoFromBusinessDb(string $dbName): array
{
    $resolvedDbName = gudangResolveBusinessDbName($dbName);
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $resolvedDbName . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->query("\n        SELECT poh.id, poh.po_number, poh.po_date, poh.status,\n               poh.business_id AS source_business_id,\n               COUNT(pod.id) AS items_count,\n               poh.created_at\n        FROM purchase_orders_header poh\n        LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id\n        WHERE poh.status IN ('submitted', 'approved', 'partially_received')\n        GROUP BY poh.id\n        ORDER BY poh.created_at DESC\n        LIMIT 20\n    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$targetBusinessConfigs = [
    'narayana-hotel',
    'bens-cafe',
    'eaat-meet',
    'eat-meet',
];

foreach ($targetBusinessConfigs as $bizSlug) {
    $cfgPath = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
    if (!file_exists($cfgPath)) {
        continue;
    }

    $bizCfg = require $cfgPath;
    $bizDbName = (string)($bizCfg['database'] ?? '');
    if ($bizDbName === '') {
        continue;
    }

    try {
        $rows = gudangFetchPendingPoFromBusinessDb($bizDbName);

        foreach ($rows as $row) {
            $row['source_business_name'] = (string)($bizCfg['name'] ?? $bizSlug);
            $row['source_business_slug'] = $bizSlug;
            $pendingReceipts[] = $row;
        }
    } catch (Throwable $e) {
        error_log('Gudang pending PO cross-db error [' . $bizSlug . ']: ' . $e->getMessage());
    }
}

usort($pendingReceipts, function ($a, $b) {
    $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    return $tb <=> $ta;
});
$pendingReceipts = array_slice($pendingReceipts, 0, 12);
$pendingPoCount = count($pendingReceipts);

$forceTheme = 'light';
include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem; display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
            Gudang Nasita
            <?php if ($pendingPoCount > 0): ?>
                <span style="background:#ef4444; color:#fff; border-radius:999px; padding:0.2rem 0.55rem; font-size:0.75rem; font-weight:800;">PO Masuk: <?php echo (int)$pendingPoCount; ?></span>
            <?php endif; ?>
        </h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Stok pusat, penerimaan supplier, dan kontrol barang keluar</p>
    </div>
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
        <button type="button" class="btn btn-warning" onclick="document.getElementById('dailyOutModal').style.display='flex'">
            <i data-feather="minus-square" style="width: 16px; height: 16px;"></i>
            Stock Keluar
        </button>
        <button type="button" class="btn btn-success" onclick="document.getElementById('manualStockModal').style.display='flex'">
            <i data-feather="plus-square" style="width: 16px; height: 16px;"></i>
            Input Stock Manual
        </button>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('importStockModal').style.display='flex'">
            <i data-feather="download" style="width: 16px; height: 16px;"></i>
            Import Stock
        </button>
        <a href="gudang-nasita.php?export_excel=1&q_item=<?php echo urlencode($searchItemName); ?>&low_stock=<?php echo $filterLowStockOnly ? '1' : '0'; ?>" class="btn btn-success" style="font-weight:700;">
            <i data-feather="upload" style="width: 16px; height: 16px;"></i>
            Export Excel
        </a>
        <a href="gudang-nasita.php?export_pdf=1&q_item=<?php echo urlencode($searchItemName); ?>&low_stock=<?php echo $filterLowStockOnly ? '1' : '0'; ?>" class="btn btn-danger" style="font-weight:700;">
            <i data-feather="file-text" style="width: 16px; height: 16px;"></i>
            Export PDF
        </a>
        <a href="gudang-nasita.php?print_stock=1" target="_blank" class="btn btn-primary" style="font-weight:700;">
            <i data-feather="printer" style="width: 16px; height: 16px;"></i>
            Print Semua Stock
        </a>
        <a href="gudang-nasita.php?print_stock_out=1" target="_blank" class="btn btn-secondary" style="font-weight:700;">
            <i data-feather="printer" style="width: 16px; height: 16px;"></i>
            Print Pengeluaran Hari Ini
        </a>
        <a href="gudang-po-supplier.php" class="btn btn-primary" style="display:none;">
            <i data-feather="file-plus" style="width: 16px; height: 16px;"></i>
            PO Supplier
        </a>
        <a href="gudang-transfer.php" class="btn btn-secondary">
            <i data-feather="shuffle" style="width: 16px; height: 16px;"></i>
            Transfer ke Bisnis
        </a>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Reset stok Gudang ke 0? Data item tetap ada, hanya qty di-nolkan.')">
            <input type="hidden" name="action" value="reset_stock_zero">
            <button type="submit" class="btn btn-danger">
                <i data-feather="rotate-ccw" style="width: 16px; height: 16px;"></i>
                Reset Stok 0
            </button>
        </form>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['success']);
                                                                    unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['error']);
                                                                unset($_SESSION['error']); ?></div>
<?php endif; ?>

<?php if (!empty($lowStockItems)): ?>
    <div style="background:#fef2f2; border:1.5px solid #fca5a5; border-radius:0.85rem; padding:1rem 1.25rem; margin-bottom:1.25rem;">
        <div style="display:flex; align-items:center; gap:0.6rem; margin-bottom:0.75rem;">
            <i data-feather="alert-triangle" style="width:18px; height:18px; color:#dc2626;"></i>
            <strong style="color:#dc2626; font-size:0.95rem;">⚠️ <?php echo count($lowStockItems); ?> item stok menipis &mdash; perlu segera dipesan ke supplier</strong>
        </div>
        <div style="display:grid; gap:0.5rem;">
            <?php foreach ($lowStockItems as $lwItem): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; background:#fff; border:1px solid #fca5a5; border-radius:0.6rem; padding:0.6rem 0.85rem;">
                    <div>
                        <span style="font-weight:700; color:#991b1b;"><?php echo htmlspecialchars($lwItem['item_name']); ?></span>
                        <span style="font-size:0.8rem; color:#b91c1c; margin-left:0.5rem;">Sisa: <?php echo number_format((float)$lwItem['quantity'], 2); ?> <?php echo htmlspecialchars($lwItem['unit']); ?> &mdash; Reorder: <?php echo number_format((float)$lwItem['reorder_level'], 2); ?></span>
                        <?php if (!empty($lwItem['supplier_name'])): ?>
                            <span style="font-size:0.78rem; color:#6b7280; margin-left:0.4rem;">(Supplier: <?php echo htmlspecialchars($lwItem['supplier_name']); ?>)</span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-sm" style="background:#dc2626; color:#fff; font-size:0.78rem; padding:0.3rem 0.75rem;"
                        onclick="window.location.href='gudang-po-supplier.php'">
                        <i data-feather="shopping-cart" style="width:13px;height:13px;"></i> Pesan ke Supplier
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <div class="card" style="padding:1rem;">
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">Total Item</div>
        <div style="font-size:1.75rem; font-weight:800; color:var(--text-primary);"><?php echo $summary['items']; ?></div>
    </div>
    <div class="card" style="padding:1rem;">
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">Total Qty</div>
        <div style="font-size:1.75rem; font-weight:800; color:var(--text-primary);"><?php echo number_format($summary['qty'], 2); ?></div>
    </div>
    <div class="card" style="padding:1rem;">
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">Masuk Hari Ini</div>
        <div style="font-size:1.75rem; font-weight:800; color:#0f9d6a;"><?php echo number_format($summary['incoming_today'], 2); ?></div>
    </div>
    <div class="card" style="padding:1rem;">
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">Keluar Hari Ini</div>
        <div style="font-size:1.75rem; font-weight:800; color:#d83a5b;"><?php echo number_format($summary['outgoing_today'], 2); ?></div>
    </div>
    <div class="card" style="padding:1rem;">
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:0.35rem;">Nilai Persediaan</div>
        <div style="font-size:1.75rem; font-weight:800; color:#0f9d6a;">Rp <?php echo number_format($summary['value'], 0, ',', '.'); ?></div>
    </div>
</div>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; align-items:start;">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; gap:1rem; flex-wrap:wrap;">
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Stok Gudang</h3>
            <form method="GET" id="stockFilterForm" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                <input type="hidden" id="stockCategoryHidden" name="category" value="<?php echo htmlspecialchars($selectedCategory); ?>">
                <input type="text" name="q_item" id="stockSearchInput" class="form-control" placeholder="Cari nama item..." value="<?php echo htmlspecialchars($searchItemName); ?>" style="min-width:220px;" autocomplete="off">
                <label style="display:flex; align-items:center; gap:0.35rem; font-size:0.82rem; color:var(--text-muted);">
                    <input type="checkbox" name="low_stock" id="stockLowFilter" value="1" <?php echo $filterLowStockOnly ? 'checked' : ''; ?>>
                    Stok menipis saja
                </label>
                <button type="submit" class="btn btn-primary btn-sm">Cari</button>
                <button type="button" class="btn btn-sm btn-secondary" id="stockResetBtn" style="<?php echo ($searchItemName || $filterLowStockOnly || $selectedCategory !== '') ? '' : 'display:none'; ?>">Clear</button>
            </form>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; margin-bottom:0.85rem;">
            <?php
            $categoryLinks = [];
            $categoryLinks[] = ['label' => 'Semua', 'value' => ''];
            foreach ($stockCategories as $cat) {
                $categoryLinks[] = ['label' => htmlspecialchars($cat), 'value' => $cat];
            }
            ?>
            <?php foreach ($categoryLinks as $categoryLink): ?>
                <?php $isActive = ($selectedCategory === '' && $categoryLink['value'] === '') || ($selectedCategory !== '' && strtolower((string)$categoryLink['value']) === strtolower($selectedCategory)); ?>
                <button type="button" class="btn btn-sm <?php echo $isActive ? 'btn-primary' : 'btn-outline-secondary'; ?>" data-gudang-category="<?php echo htmlspecialchars((string)$categoryLink['value']); ?>">
                    <?php echo $categoryLink['label']; ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php if ($summary['low'] > 0): ?>
            <div style="margin-bottom:0.75rem;"><span class="badge badge-warning"><?php echo $summary['low']; ?> item di bawah reorder</span></div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table" id="stockTable">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Kategori</th>
                        <th>Item</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Harga/pcs</th>
                        <th class="text-right">Nilai</th>
                        <th>Unit</th>
                        <th>Supplier</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockItems)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding: 2rem; color: var(--text-muted);">Belum ada stok gudang</td>
                        </tr>
                    <?php else: ?>
                        <?php $currentCategory = null; ?>
                        <?php foreach ($stockItems as $item): ?>
                            <?php $rowCategory = trim((string)($item['category'] ?? '')); ?>
                            <?php if ($rowCategory === '') {
                                $rowCategory = 'lainnya';
                            } ?>
                            <?php if ($currentCategory !== $rowCategory): ?>
                                <?php $currentCategory = $rowCategory; ?>
                                <tr>
                                    <td colspan="9" style="background:#f8fafc; font-weight:700; color:#334155; text-transform:capitalize; border-top:1px solid var(--border);">
                                        Kategori: <?php echo htmlspecialchars($currentCategory); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php $isLowRow = ((float)$item['quantity'] <= (float)($item['reorder_level'] ?? 0) && (float)($item['reorder_level'] ?? 0) > 0); ?>
                            <tr data-item="<?php echo htmlspecialchars(strtolower((string)$item['item_name'])); ?>" data-low="<?php echo $isLowRow ? '1' : '0'; ?>" data-category="<?php echo htmlspecialchars(strtolower((string)$rowCategory)); ?>">
                                <td style="font-weight:600;"><?php echo htmlspecialchars($item['stock_code'] ?? ('GN-LEGACY-' . str_pad((string)($item['id'] ?? 0), 4, '0', STR_PAD_LEFT))); ?></td>
                                <td><span class="badge badge-info" style="text-transform:capitalize;"><?php echo htmlspecialchars($rowCategory); ?></span></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                    <?php if (!empty($item['notes'])): ?><div style="font-size:0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($item['notes']); ?></div><?php endif; ?>
                                </td>
                                <td class="text-right" style="font-weight:700; color:<?php echo ((float)$item['quantity'] <= (float)($item['reorder_level'] ?? 0) && (float)($item['reorder_level'] ?? 0) > 0) ? '#d97706' : 'var(--text-primary)'; ?>;"><?php echo number_format($item['quantity'], 2); ?></td>
                                <td class="text-right">Rp <?php echo number_format((float)($item['harga_beli'] ?? 0), 0, ',', '.'); ?></td>
                                <td class="text-right" style="font-weight:700; color:#0f9d6a;">Rp <?php echo number_format((float)($item['total_harga'] ?? ((float)$item['quantity'] * (float)($item['harga_beli'] ?? 0))), 0, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td style="font-size:0.813rem;"><?php echo htmlspecialchars($item['supplier_name'] ?: '-'); ?></td>
                                <td>
                                    <div style="display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;">
                                        <button type="button" class="btn btn-sm" style="background:#0f9d6a;color:#fff;"
                                            onclick="openQuickStock(<?php echo htmlspecialchars(json_encode($item['item_name']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($item['category'] ?? 'lainnya'), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($item['unit']), ENT_QUOTES); ?>, <?php echo (float)($item['quantity'] ?? 0); ?>)">
                                            <i data-feather="plus" style="width:13px;height:13px;"></i> Tambah Stok
                                        </button>
                                        <a href="gudang-transfer.php?stock_id=<?php echo (int)$item['id']; ?>" class="btn btn-sm btn-primary">
                                            <i data-feather="send" style="width:14px; height:14px;"></i>
                                            Transfer
                                        </a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus item stok ini?')">
                                            <input type="hidden" name="action" value="delete_stock">
                                            <input type="hidden" name="stock_id" value="<?php echo (int)$item['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus">
                                                <i data-feather="trash-2" style="width:14px;height:14px;"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:grid; gap:1.25rem;">
        <div class="card">
            <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem; display:flex; align-items:center; justify-content:space-between; gap:0.5rem;">
                <span>PO Bisnis Menunggu Proses Gudang</span>
                <?php if ($pendingPoCount > 0): ?>
                    <span style="background:#ef4444; color:#fff; min-width:22px; height:22px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:800; padding:0 6px;"><?php echo (int)$pendingPoCount; ?></span>
                <?php endif; ?>
            </h3>
            <div style="display:grid; gap:0.75rem;">
                <?php if (empty($pendingReceipts)): ?>
                    <div style="color:var(--text-muted); font-size:0.875rem;">Tidak ada PO bisnis yang perlu diproses gudang</div>
                <?php else: ?>
                    <?php foreach ($pendingReceipts as $po): ?>
                        <div style="padding:0.75rem; border:1px solid var(--border); border-radius:0.75rem; background: var(--bg-secondary);">
                            <div style="font-weight:700;"><?php echo htmlspecialchars($po['po_number']); ?></div>
                            <div style="font-size:0.812rem; color:#0f172a; font-weight:700;">
                                <?php echo htmlspecialchars(($po['source_business_name'] ?: 'Business #' . (int)($po['source_business_id'] ?? 0)) . ' PO'); ?>
                            </div>
                            <div style="font-size:0.812rem; color:var(--text-muted);">Status: <?php echo htmlspecialchars($po['status']); ?></div>
                            <div style="font-size:0.812rem; color:var(--text-muted);"><?php echo (int)$po['items_count']; ?> item</div>
                            <div style="display:flex; gap:0.5rem; margin-top:0.5rem; flex-wrap:wrap;">
                                <a href="gudang-transfer.php?po_id=<?php echo (int)$po['id']; ?>&po_business=<?php echo urlencode((string)($po['source_business_slug'] ?? '')); ?>" class="btn btn-sm btn-success">Siapkan Transfer</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Batalkan permintaan PO ini?')">
                                    <input type="hidden" name="action" value="cancel_pending_po">
                                    <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                                    <input type="hidden" name="po_slug" value="<?php echo htmlspecialchars((string)($po['source_business_slug'] ?? '')); ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h3 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem;">Transfer Terakhir</h3>
            <div style="display:grid; gap:0.75rem;">
                <?php if (empty($recentTransfers)): ?>
                    <div style="color:var(--text-muted); font-size:0.875rem;">Belum ada transfer keluar</div>
                <?php else: ?>
                    <?php foreach ($recentTransfers as $transfer): ?>
                        <div style="padding:0.75rem; border:1px solid var(--border); border-radius:0.75rem; background: var(--bg-secondary);">
                            <div style="font-weight:700;"><?php echo htmlspecialchars($transfer['transfer_number']); ?></div>
                            <div style="font-size:0.812rem; color:var(--text-muted);"><?php echo htmlspecialchars($transfer['target_business_name']); ?></div>
                            <div style="font-size:0.812rem; color:var(--text-muted);"><?php echo (int)$transfer['items_count']; ?> item | <?php echo number_format((float)$transfer['total_qty'], 2); ?> qty</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    if (typeof feather !== 'undefined') feather.replace();
    const GUDANG_BASE = '<?php echo BASE_URL; ?>';

    // Live client-side stock filter with category chips and text search
    window.gudangFilterDebug = function() {
        const inp = document.getElementById('stockSearchInput');
        const chk = document.getElementById('stockLowFilter');
        const rst = document.getElementById('stockResetBtn');
        const categoryInput = document.getElementById('stockCategoryHidden');
        const categoryButtons = document.querySelectorAll('[data-gudang-category]');
        const tbody = document.querySelector('#stockTable tbody');

        console.log('=== GUDANG FILTER DIAGNOSTIC ===');
        console.log('Elements found:', {
            inp: !!inp,
            chk: !!chk,
            rst: !!rst,
            categoryInput: !!categoryInput,
            tbody: !!tbody
        });
        console.log('Category buttons found:', categoryButtons.length);

        if (categoryButtons.length > 0) {
            console.log('Button values:');
            categoryButtons.forEach((btn, i) => {
                console.log(`  [${i}]`, btn.getAttribute('data-gudang-category'), '| active:', btn.classList.contains('active'));
            });
        }

        if (tbody) {
            console.log('Table rows (first 5):');
            Array.from(tbody.rows).slice(0, 5).forEach((tr, i) => {
                console.log(`  [${i}]`, {
                    item: tr.dataset.item,
                    category: tr.dataset.category,
                    colSpan: tr.cells[0]?.colSpan
                });
            });
        }
    };

    window.gudangFilterDebug();

    (function() {
        const inp = document.getElementById('stockSearchInput');
        const chk = document.getElementById('stockLowFilter');
        const rst = document.getElementById('stockResetBtn');
        const categoryInput = document.getElementById('stockCategoryHidden');
        const categoryButtons = document.querySelectorAll('[data-gudang-category]');
        const tbody = document.querySelector('#stockTable tbody');

        if (!inp || !tbody || categoryButtons.length === 0) {
            console.error('[GUDANG] Missing required elements, filter disabled');
            return;
        }

        function normalizeText(value) {
            return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
        }

        function filterRows() {
            const searchText = normalizeText(inp.value);
            const selectedCat = categoryInput ? normalizeText(categoryInput.value) : '';
            const lowStockOnly = chk && chk.checked;

            let hiddenCount = 0;

            Array.from(tbody.rows).forEach(tr => {
                // Category header rows
                if (tr.cells.length === 1 && tr.cells[0].colSpan > 1) {
                    // Will be shown/hidden based on following items
                    return;
                }

                const itemName = normalizeText(tr.dataset.item || '');
                const rowCat = normalizeText(tr.dataset.category || '');
                const isLow = tr.dataset.low === '1';

                // Apply all filters
                let show = true;
                if (searchText && !itemName.includes(searchText)) show = false;
                if (lowStockOnly && !isLow) show = false;
                if (selectedCat && rowCat !== selectedCat) show = false;

                tr.style.display = show ? '' : 'none';
                if (!show) hiddenCount++;
            });

            // Show/hide category header rows
            let prevCatRow = null;
            Array.from(tbody.rows).forEach(tr => {
                if (tr.cells.length === 1 && tr.cells[0].colSpan > 1) {
                    prevCatRow = tr;
                } else if (prevCatRow) {
                    if (tr.style.display !== 'none') {
                        prevCatRow.style.display = '';
                        prevCatRow = null;
                    }
                }
            });

            // Hide category rows with no visible items
            let nextVisible = false;
            for (let i = tbody.rows.length - 1; i >= 0; i--) {
                const tr = tbody.rows[i];
                if (tr.cells.length === 1 && tr.cells[0].colSpan > 1) {
                    tr.style.display = nextVisible ? '' : 'none';
                    nextVisible = false;
                } else {
                    if (tr.style.display !== 'none') nextVisible = true;
                }
            }

            // Show/hide reset button
            const hasFilter = searchText || lowStockOnly || selectedCat;
            if (rst) rst.style.display = hasFilter ? '' : 'none';
        }

        // Category button click handler
        categoryButtons.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const clickedValue = this.getAttribute('data-gudang-category');
                const normalized = normalizeText(clickedValue);

                // Update hidden input
                if (categoryInput) categoryInput.value = normalized;

                // Update button active states
                categoryButtons.forEach(b => {
                    const btnVal = normalizeText(b.getAttribute('data-gudang-category'));
                    b.classList.toggle('active', btnVal === normalized);
                });

                // Run filter
                filterRows();
                console.log('[GUDANG] Filter by category:', normalized);
            });
        });

        // Search input handler
        if (inp) {
            inp.addEventListener('input', function() {
                filterRows();
            });
        }

        // Low stock checkbox handler
        if (chk) {
            chk.addEventListener('change', function() {
                filterRows();
            });
        }

        // Reset button handler
        if (rst) {
            rst.addEventListener('click', function(e) {
                e.preventDefault();
                inp.value = '';
                if (chk) chk.checked = false;
                if (categoryInput) categoryInput.value = '';

                categoryButtons.forEach(btn => {
                    btn.classList.toggle('active', btn.getAttribute('data-gudang-category') === '');
                });

                filterRows();
                console.log('[GUDANG] Filter reset');
            });
        }

        // Initial filter
        filterRows();
        console.log('[GUDANG] Filter initialized');
    })();

    document.addEventListener('click', function(e) {
        if (e.target === document.getElementById('manualStockModal')) document.getElementById('manualStockModal').style.display = 'none';
        if (e.target === document.getElementById('importStockModal')) document.getElementById('importStockModal').style.display = 'none';
        if (e.target === document.getElementById('orderSupplierModal')) document.getElementById('orderSupplierModal').style.display = 'none';
        if (e.target === document.getElementById('quickStockModal')) document.getElementById('quickStockModal').style.display = 'none';
        if (e.target === document.getElementById('dailyOutModal')) document.getElementById('dailyOutModal').style.display = 'none';
    });

    // Slim modal: tambah stok ke item yang sudah ada (dari tombol per baris)
    function openQuickStock(itemName, category, unit, currentQty) {
        var m = document.getElementById('quickStockModal');
        m.querySelector('[name="item_name"]').value = itemName;
        m.querySelector('[name="category"]').value = category || 'lainnya';
        m.querySelector('[name="unit"]').value = unit || 'pcs';
        m.querySelector('#qsTitle').textContent = itemName;
        m.querySelector('#qsCurrentQty').textContent = parseFloat(currentQty).toLocaleString('id-ID', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }) + ' ' + (unit || 'pcs');
        var qtyInput = m.querySelector('[name="quantity"]');
        qtyInput.value = '';
        m.style.display = 'flex';
        setTimeout(() => qtyInput.focus(), 60);
    }

    function openOrderModal(itemName, unit, supplierHint) {
        var m = document.getElementById('orderSupplierModal');
        m.querySelector('[name="item_name"]').value = itemName;
        m.querySelector('[name="unit"]').value = unit;
        m.style.display = 'flex';
        var sel = m.querySelector('[name="supplier_id"]');
        if (supplierHint && sel) {
            var hint = supplierHint.toLowerCase();
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].text.toLowerCase().includes(hint)) {
                    sel.selectedIndex = i;
                    break;
                }
            }
        }
    }

    // Open manual stock modal pre-filled with existing item data
    function openManualModalPreset(itemName, category, unit) {
        var m = document.getElementById('manualStockModal');
        m.querySelector('[name="item_name"]').value = itemName;
        m.querySelector('[name="category"]').value = category || 'lainnya';
        m.querySelector('[name="unit"]').value = unit || 'pcs';
        m.querySelector('[name="quantity"]').value = '';
        m.querySelector('[name="quantity"]').focus();
        m.style.display = 'flex';
    }

    // Live autocomplete for item name in manual stock modal
    let acTimer;
    const acInput = document.querySelector('#manualStockModal [name="item_name"]');
    const acDrop = document.getElementById('produkAcDrop');
    const acCategoryInput = acInput ? acInput.closest('form').querySelector('[name="category"]') : null;

    if (acInput && acDrop) {
        acInput.addEventListener('input', function() {
            clearTimeout(acTimer);
            const q = this.value.trim();
            // Reset category visual indicator when user modifies item name
            if (acCategoryInput) {
                acCategoryInput.style.background = '';
                acCategoryInput.style.borderColor = '';
                acCategoryInput.title = '';
            }
            if (q.length < 1) {
                acDrop.style.display = 'none';
                return;
            }
            acTimer = setTimeout(() => fetchAcResults(q), 280);
        });
        acInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') acDrop.style.display = 'none';
        });
        document.addEventListener('click', function(e) {
            if (!acDrop.contains(e.target) && e.target !== acInput) acDrop.style.display = 'none';
        });
    }

    async function fetchAcResults(q) {
        try {
            const r = await fetch(`${GUDANG_BASE}/api/gudang-produk-search.php?action=search&q=${encodeURIComponent(q)}`);
            const d = await r.json();
            if (!d.success || !d.data.length) {
                acDrop.style.display = 'none';
                return;
            }
            acDrop.innerHTML = d.data.map(p =>
                `<div class="ac-item" onclick="selectAcItem(${JSON.stringify(p.nama_barang)}, ${JSON.stringify(p.kategori||'lainnya')}, ${JSON.stringify(p.satuan||'pcs')})"
                    style="padding:0.55rem 0.85rem; cursor:pointer; border-bottom:1px solid #e2e8f0; font-size:0.875rem;">
                    <span style="font-weight:600;">${p.nama_barang}</span>
                    <span style="color:#64748b; margin-left:0.5rem;">${p.kategori||''} · ${p.satuan||'pcs'}</span>
                    <span style="color:#94a3b8; font-size:0.75rem; margin-left:0.5rem;">${p.kode_barang||''}</span>
                </div>`
            ).join('');
            acDrop.style.display = 'block';
        } catch (e) {}
    }

    function selectAcItem(nama, kategori, satuan) {
        acInput.value = nama;
        var m = document.getElementById('manualStockModal');
        var categoryInput = m.querySelector('[name="category"]');
        var oldCategory = categoryInput.value;
        categoryInput.value = kategori;

        // Visual indicator only (non-blocking) if category changed
        if (oldCategory && oldCategory !== kategori) {
            categoryInput.style.background = '#fef08a';
            categoryInput.style.borderColor = '#eab308';
            categoryInput.title = 'Kategori diubah otomatis dari autocomplete. Edit jika diperlukan.';
            console.log('[GUDANG] Category auto-set from autocomplete: ' + oldCategory + ' → ' + kategori);
        }

        m.querySelector('[name="unit"]').value = satuan;
        acDrop.style.display = 'none';
        m.querySelector('[name="quantity"]').focus();
    }

    function validateManualStockForm(form) {
        const itemName = form.querySelector('[name="item_name"]').value.trim();
        const category = form.querySelector('[name="category"]').value.trim();
        const quantity = parseFloat(form.querySelector('[name="quantity"]').value);

        if (!itemName) {
            alert('Nama item wajib diisi.');
            form.querySelector('[name="item_name"]').focus();
            return false;
        }
        if (!category) {
            alert('Kategori wajib diisi.');
            form.querySelector('[name="category"]').focus();
            return false;
        }
        if (!quantity || quantity <= 0) {
            alert('Qty masuk harus lebih dari 0.');
            form.querySelector('[name="quantity"]').focus();
            return false;
        }

        console.log('[GUDANG] Submitting manual stock:', {
            itemName,
            category,
            quantity
        });
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Menyimpan...';
        }
        return true;
    }
</script>

<div id="importStockModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:2050; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(760px, 100%); max-height:90vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; gap:1rem;">
            <div>
                <h3 style="font-size:1.05rem; margin:0;">Import Stock Gudang</h3>
                <div style="font-size:0.8rem; color:var(--text-muted); margin-top:0.25rem;">Kolom minimal: NAMA BARANG, SATUAN, SALDO AWAL. Bisa upload CSV atau paste langsung dari Excel.</div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('importStockModal').style.display='none'">Tutup</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_stock_sheet">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.9rem;">
                <div style="grid-column:1 / span 2;">
                    <label class="form-label">Upload File CSV</label>
                    <input type="file" name="import_stock_file" class="form-control" accept=".csv,.txt,.xlsx,.xls">
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.35rem;">Format `.xlsx` modern didukung jika server punya ekstensi ZIP. Format `.xls` lama tetap disarankan di-save sebagai `.xlsx` atau `CSV`. Alternatif paling cepat: copy dari Excel lalu paste ke kotak di bawah.</div>
                </div>
                <div>
                    <label class="form-label">Default Kategori</label>
                    <input type="text" name="default_category" class="form-control" value="lainnya" list="manualStockCategoryList">
                </div>
                <div>
                    <label class="form-label">Default Supplier</label>
                    <input type="text" name="default_supplier_name" class="form-control" placeholder="Opsional jika file tidak punya kolom supplier">
                </div>
                <div style="grid-column:1 / span 2;">
                    <label class="form-label">Catatan Default</label>
                    <input type="text" name="default_notes" class="form-control" value="Import stok awal gudang">
                </div>
                <div style="grid-column:1 / span 2;">
                    <label class="form-label">Paste Data dari Excel</label>
                    <textarea name="import_paste_data" class="form-control" rows="10" placeholder="Contoh header: NO[TAB]NAMA BARANG[TAB]Satuan[TAB]SALDO AWAL"></textarea>
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.35rem;">Import akan menambah qty ke stok yang sudah ada. Baris kosong atau qty 0 akan dilewati.</div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('importStockModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Import Sekarang</button>
            </div>
        </form>
    </div>
</div>

<div id="quickStockModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:2050; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(400px,100%);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Tambah Stok</div>
                <h3 id="qsTitle" style="font-size:1.05rem; margin:0.15rem 0 0; font-weight:700;"></h3>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('quickStockModal').style.display='none'">✕</button>
        </div>
        <div style="background:var(--bg-secondary); border-radius:0.5rem; padding:0.65rem 0.9rem; margin-bottom:1rem; font-size:0.875rem;">
            Stok saat ini: <strong id="qsCurrentQty" style="color:#0f9d6a;"></strong>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="manual_stock_in">
            <input type="hidden" name="item_name">
            <input type="hidden" name="category">
            <input type="hidden" name="unit">
            <input type="hidden" name="reorder_level" value="0">
            <div style="margin-bottom:1rem;">
                <label class="form-label" style="font-weight:600;">Qty Masuk *</label>
                <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required placeholder="0" style="font-size:1.1rem; padding:0.65rem;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('quickStockModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-success" style="font-weight:700;">Simpan Stock</button>
            </div>
        </form>
    </div>
</div>

<div id="dailyOutModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:2055; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(420px,100%);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Stock Keluar</div>
                <h3 style="font-size:1.05rem; margin:0.15rem 0 0; font-weight:700;">Catat Pengeluaran Harian</h3>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('dailyOutModal').style.display='none'">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="stock_out_daily">
            <div style="margin-bottom:0.85rem;">
                <label class="form-label">Nama Item *</label>
                <input type="text" name="item_name" class="form-control" required placeholder="Masukkan nama item...">
            </div>
            <div style="margin-bottom:0.85rem;">
                <label class="form-label">Qty Keluar *</label>
                <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required placeholder="0">
            </div>
            <div style="margin-bottom:1rem;">
                <label class="form-label">Catatan</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Contoh: Barang dipakai operasional, rusak, atau dikirim ke cabang"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('dailyOutModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-warning" style="font-weight:700; color:#111827;">Simpan Stock Keluar</button>
            </div>
        </form>
    </div>
</div>

<div id="manualStockModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:2000; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(640px, 100%); max-height:90vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1.05rem; margin:0;">Input Stock Manual</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('manualStockModal').style.display='none'">Tutup</button>
        </div>
        <form method="POST" id="manualStockForm" onsubmit="return validateManualStockForm(this)">
            <input type="hidden" name="action" value="manual_stock_in">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.9rem;">
                <div style="grid-column:1/span 2; position:relative;">
                    <label class="form-label">Nama Item * <a href="gudang-produk.php" target="_blank" style="font-size:0.75rem; margin-left:0.5rem;">(kelola produk)</a></label>
                    <input type="text" name="item_name" class="form-control" required autocomplete="off" placeholder="Ketik untuk cari atau isi nama baru...">
                    <div id="produkAcDrop" style="display:none; position:absolute; left:0; right:0; top:100%; background:#fff; border:1px solid #e2e8f0; border-radius:0 0 0.5rem 0.5rem; max-height:200px; overflow-y:auto; z-index:9999; box-shadow:0 4px 16px rgba(0,0,0,0.12);"></div>
                </div>
            </div>
            <div>
                <label class="form-label">Kategori *</label>
                <input type="text" name="category" class="form-control" list="manualStockCategoryList" placeholder="Contoh: minuman" required>
                <datalist id="manualStockCategoryList">
                    <option value="minuman"></option>
                    <option value="frozen"></option>
                    <option value="alat"></option>
                    <option value="sayur"></option>
                    <option value="daging"></option>
                    <option value="sembako"></option>
                    <option value="bumbu"></option>
                    <option value="lainnya"></option>
                </datalist>
            </div>
            <div>
                <label class="form-label">Unit *</label>
                <input type="text" name="unit" class="form-control" value="pcs" required>
            </div>
            <div>
                <label class="form-label">Qty Masuk *</label>
                <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div>
                <label class="form-label">Harga/pcs</label>
                <input type="number" name="unit_price" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div>
                <label class="form-label">Reorder Level</label>
                <input type="number" name="reorder_level" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div style="grid-column:1 / span 2;">
                <label class="form-label">Supplier (opsional)</label>
                <input type="text" name="supplier_name" class="form-control" placeholder="Contoh: CV Sumber Jaya">
            </div>
            <div style="grid-column:1 / span 2;">
                <label class="form-label">Catatan</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Contoh: Stok awal sebelum sistem PO aktif"></textarea>
            </div>
    </div>
    <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('manualStockModal').style.display='none'">Batal</button>
        <button type="submit" class="btn btn-success">Simpan Stock Manual</button>
    </div>
    </form>
</div>
</div>

<!-- Modal: Pesan ke Supplier -->
<div id="orderSupplierModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:2100; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(520px,100%); max-height:90vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1.05rem; margin:0; color:#dc2626;">🛒 Pesan ke Supplier</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('orderSupplierModal').style.display='none'">Tutup</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="order_supplier">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.85rem;">
                <div style="grid-column:1/span 2;">
                    <label class="form-label">Supplier *</label>
                    <select name="supplier_id" class="form-control" required>
                        <option value="">-- Pilih Supplier --</option>
                        <?php foreach ($gudangSuppliers as $sup): ?>
                            <option value="<?php echo (int)$sup['id']; ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                        <?php endforeach; ?>
                        <?php if (empty($gudangSuppliers)): ?>
                            <option value="" disabled>Belum ada supplier — tambah di menu Pemasok</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Nama Item *</label>
                    <input type="text" name="item_name" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Unit *</label>
                    <input type="text" name="unit" class="form-control" value="pcs" required>
                </div>
                <div style="grid-column:1/span 2;">
                    <label class="form-label">Qty yang Dipesan *</label>
                    <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required>
                </div>
                <div style="grid-column:1/span 2;">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Contoh: Urgent, butuh sebelum weekend"></textarea>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('orderSupplierModal').style.display='none'">Batal</button>
                <button type="submit" class="btn" style="background:#dc2626; color:#fff;">Buat PO Supplier</button>
            </div>
        </form>
    </div>
</div>