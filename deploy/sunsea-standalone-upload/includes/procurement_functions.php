<?php

/**
 * Procurement Module Functions
 * Narayana Hotel Management System
 */

require_once __DIR__ . '/../config/database.php';
if (file_exists(__DIR__ . '/CloudinaryHelper.php')) {
    require_once __DIR__ . '/CloudinaryHelper.php';
}

/**
 * Generate Purchase Order Number
 * Format: PO-YYYYMM-XXXX
 * 
 * @return string Generated PO number
 */
function generatePONumber()
{
    $db = Database::getInstance();

    $prefix = 'PO-' . date('Ym') . '-';

    // Get the last PO number for this month
    $lastPO = $db->fetchOne("
        SELECT po_number 
        FROM purchase_orders_header 
        WHERE po_number LIKE ? 
        ORDER BY po_number DESC 
        LIMIT 1
    ", [$prefix . '%']);

    if ($lastPO) {
        // Extract the sequence number
        $lastNumber = (int)substr($lastPO['po_number'], -4);
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }

    return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
}

/**
 * Create a new Purchase Order
 * 
 * @param int $supplier_id Supplier ID
 * @param string $po_date Purchase Order date (Y-m-d format)
 * @param array $items Array of items with keys: item_name, quantity, unit_price, division_id
 * @param array $options Optional parameters: expected_delivery_date, notes, status
 * @return array ['success' => bool, 'po_id' => int, 'po_number' => string, 'message' => string]
 */
function createPurchaseOrder($supplier_id, $po_date, $items, $options = [])
{
    $db = Database::getInstance();

    try {
        // Validate inputs
        if (empty($supplier_id) || !is_numeric($supplier_id)) {
            throw new Exception("Invalid supplier ID");
        }

        if (empty($po_date)) {
            throw new Exception("Purchase Order date is required");
        }

        if (empty($items) || !is_array($items)) {
            throw new Exception("Items array is required and must not be empty");
        }

        // Verify supplier exists
        $supplier = $db->fetchOne("SELECT id FROM suppliers WHERE id = ? AND is_active = 1", [$supplier_id]);
        if (!$supplier) {
            throw new Exception("Supplier not found or inactive");
        }

        // Begin transaction
        $db->getConnection()->beginTransaction();

        // Calculate totals
        $total_amount = 0;
        $line_number = 1;
        $validated_items = [];

        $requireDivision = false;
        try {
            $detailCols = $db->fetchAll('SHOW COLUMNS FROM purchase_orders_detail');
            $detailColNames = array_map(function ($row) {
                return strtolower((string)($row['Field'] ?? ''));
            }, $detailCols);
            if (in_array('division_id', $detailColNames, true)) {
                $divCountRow = $db->fetchOne('SELECT COUNT(*) AS total FROM divisions');
                $requireDivision = ((int)($divCountRow['total'] ?? 0) > 0);
            }
        } catch (Throwable $e) {
            $requireDivision = false;
        }

        foreach ($items as $item) {
            // Validate each item
            if (empty($item['item_name'])) {
                throw new Exception("Item name is required for line {$line_number}");
            }

            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                throw new Exception("Valid quantity is required for line {$line_number}");
            }

            if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || $item['unit_price'] < 0) {
                throw new Exception("Valid unit price is required for line {$line_number}");
            }

            if ($requireDivision) {
                if (empty($item['division_id']) || !is_numeric($item['division_id'])) {
                    throw new Exception("Division ID is required for line {$line_number}");
                }

                // Verify division exists
                $division = $db->fetchOne("SELECT id FROM divisions WHERE id = ?", [$item['division_id']]);
                if (!$division) {
                    throw new Exception("Division not found for line {$line_number}");
                }
            }

            // Calculate subtotal
            $subtotal = $item['quantity'] * $item['unit_price'];
            $total_amount += $subtotal;

            // Store validated item
            $validated_items[] = [
                'line_number' => $line_number,
                'item_name' => trim($item['item_name']),
                'item_description' => isset($item['item_description']) ? trim($item['item_description']) : null,
                'unit_of_measure' => isset($item['unit_of_measure']) ? trim($item['unit_of_measure']) : 'pcs',
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $subtotal,
                'division_id' => isset($item['division_id']) && is_numeric($item['division_id']) ? (int)$item['division_id'] : null,
                'notes' => isset($item['notes']) ? trim($item['notes']) : null
            ];

            $line_number++;
        }

        // Get current user ID
        $auth = new Auth();
        $currentUser = $auth->getCurrentUser();
        $created_by = $currentUser['id'];

        // Fix: Validate created_by user exists (Handle session mismatch)
        $user_check = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$created_by]);
        if (!$user_check) {
            // Try to find by username
            $user_by_name = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$currentUser['username']]);
            if ($user_by_name) {
                $created_by = $user_by_name['id'];
            } else {
                // Fallback to Admin (ID 1)
                $admin = $db->fetchOne("SELECT id FROM users WHERE id = 1 OR role = 'admin' LIMIT 1");
                $created_by = $admin ? $admin['id'] : 1;
            }
        }

        // Generate PO Number
        $po_number = generatePONumber();
        $businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
        if ($businessId <= 0 && defined('ACTIVE_BUSINESS_ID') && function_exists('getNumericBusinessId')) {
            $resolvedBusinessId = getNumericBusinessId((string)ACTIVE_BUSINESS_ID);
            if (!empty($resolvedBusinessId)) {
                $businessId = (int)$resolvedBusinessId;
            }
        }
        if ($businessId <= 0 && !empty($_SESSION['active_business_id']) && function_exists('getNumericBusinessId')) {
            $resolvedBusinessId = getNumericBusinessId((string)$_SESSION['active_business_id']);
            if (!empty($resolvedBusinessId)) {
                $businessId = (int)$resolvedBusinessId;
            }
        }
        if ($businessId <= 0 && !empty($_SESSION['active_business_id'])) {
            $activeBizSlug = strtolower((string)$_SESSION['active_business_id']);
            $normalizedCode = strtoupper(str_replace(['-', '_'], '', $activeBizSlug));
            try {
                $masterPdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=" . DB_CHARSET,
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $bizRow = $masterPdo->prepare("\n                    SELECT id\n                    FROM businesses\n                    WHERE slug = ?\n                       OR UPPER(REPLACE(REPLACE(business_code, '_', ''), '-', '')) = ?\n                    ORDER BY id ASC\n                    LIMIT 1\n                ");
                $bizRow->execute([$activeBizSlug, $normalizedCode]);
                $row = $bizRow->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['id'])) {
                    $businessId = (int)$row['id'];
                    $_SESSION['business_id'] = $businessId;
                }
            } catch (Throwable $e) {
            }
        }
        if ($businessId <= 0) {
            $businessId = null;
        }

        // Prepare header data
        $discount_amount = isset($options['discount_amount']) ? $options['discount_amount'] : 0;
        $tax_amount = isset($options['tax_amount']) ? $options['tax_amount'] : 0;
        $grand_total = $total_amount - $discount_amount + $tax_amount;

        // Probe actual columns to avoid INSERT failures on older-schema DBs
        $hdrCols = $db->fetchAll("SHOW COLUMNS FROM purchase_orders_header");
        $hdrColNames = array_column($hdrCols, 'Field');

        $header_data = [
            'business_id' => $businessId,
            'po_number'   => $po_number,
            'supplier_id' => $supplier_id,
            'po_date'     => $po_date,
            'status'      => isset($options['status']) ? $options['status'] : 'draft',
            'total_amount' => $total_amount,
            'notes'       => isset($options['notes']) ? $options['notes'] : null,
            'created_by'  => $created_by,
        ];
        if (in_array('expected_delivery_date', $hdrColNames)) {
            $header_data['expected_delivery_date'] = isset($options['expected_delivery_date']) ? $options['expected_delivery_date'] : null;
        }
        if (in_array('discount_amount', $hdrColNames)) {
            $header_data['discount_amount'] = $discount_amount;
        }
        if (in_array('tax_amount', $hdrColNames)) {
            $header_data['tax_amount'] = $tax_amount;
        }
        if (in_array('grand_total', $hdrColNames)) {
            $header_data['grand_total'] = $grand_total;
        }

        // Insert header
        $po_header_id = $db->insert('purchase_orders_header', $header_data);

        if (!$po_header_id) {
            throw new Exception("Failed to create Purchase Order header");
        }

        // Insert details (probe columns once to avoid failures on older-schema DBs)
        $dtlCols    = $db->fetchAll("SHOW COLUMNS FROM purchase_orders_detail");
        $dtlColNames = array_column($dtlCols, 'Field');

        foreach ($validated_items as $item) {
            // Build detail row using only columns that exist in this DB's schema
            $detail_data = [
                'po_header_id' => $po_header_id,
                'item_name'    => $item['item_name'],
                'quantity'     => $item['quantity'],
                'unit_price'   => $item['unit_price'],
            ];
            if (in_array('received_quantity', $dtlColNames)) {
                $detail_data['received_quantity'] = 0;
            }
            // unit column: new schema = unit_of_measure, old schema = unit
            if (in_array('unit_of_measure', $dtlColNames)) {
                $detail_data['unit_of_measure'] = $item['unit_of_measure'];
            } elseif (in_array('unit', $dtlColNames)) {
                $detail_data['unit'] = $item['unit_of_measure'];
            }
            // subtotal column: new schema = subtotal, old schema = total_price
            if (in_array('subtotal', $dtlColNames)) {
                $detail_data['subtotal'] = $item['subtotal'];
            } elseif (in_array('total_price', $dtlColNames)) {
                $detail_data['total_price'] = $item['subtotal'];
            }
            if (in_array('line_number', $dtlColNames)) {
                $detail_data['line_number'] = $item['line_number'];
            }
            if (in_array('item_description', $dtlColNames)) {
                $detail_data['item_description'] = $item['item_description'];
            }
            if (in_array('division_id', $dtlColNames)) {
                $detail_data['division_id'] = $item['division_id'];
            }
            if (in_array('notes', $dtlColNames)) {
                $detail_data['notes'] = $item['notes'];
            }

            $detail_id = $db->insert('purchase_orders_detail', $detail_data);

            if (!$detail_id) {
                throw new Exception("Failed to insert item: {$item['item_name']}");
            }
        }

        // Commit transaction
        $db->getConnection()->commit();

        return [
            'success' => true,
            'po_id' => $po_header_id,
            'po_number' => $po_number,
            'total_amount' => $total_amount,
            'grand_total' => $grand_total,
            'items_count' => count($validated_items),
            'message' => "Purchase Order {$po_number} created successfully"
        ];
    } catch (Exception $e) {
        // Rollback on error
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get Purchase Order by ID
 * 
 * @param int $po_id Purchase Order ID
 * @return array|null Purchase Order data with details
 */
function getPurchaseOrder($po_id)
{
    $db = Database::getInstance();

    // Get header
    $header = $db->fetchOne("
        SELECT 
            poh.*,
            s.supplier_name,
            s.supplier_code,
            u.full_name as created_by_name
        FROM purchase_orders_header poh
        LEFT JOIN suppliers s ON poh.supplier_id = s.id
        LEFT JOIN users u ON poh.created_by = u.id
        WHERE poh.id = ?
    ", [$po_id]);

    if (!$header) {
        return null;
    }

    // Get details. Fallback to plain detail query when divisions table is unavailable.
    $details = $db->fetchAll("
        SELECT 
            pod.*,
            d.division_name,
            d.division_code
        FROM purchase_orders_detail pod
        LEFT JOIN divisions d ON pod.division_id = d.id
        WHERE pod.po_header_id = ?
        ORDER BY pod.id
    ", [$po_id]);
    if (empty($details)) {
        $details = $db->fetchAll("
            SELECT pod.*
            FROM purchase_orders_detail pod
            WHERE pod.po_header_id = ?
            ORDER BY pod.id
        ", [$po_id]);
    }

    $header['items'] = $details;

    return $header;
}

/**
 * Update Purchase Order status
 * 
 * @param int $po_id Purchase Order ID
 * @param string $status New status (draft, submitted, approved, rejected, partially_received, completed, cancelled)
 * @param int $approved_by User ID who approved (optional)
 * @return array ['success' => bool, 'message' => string]
 */
function updatePurchaseOrderStatus($po_id, $status, $approved_by = null)
{
    $db = Database::getInstance();

    try {
        // Validate status
        $valid_statuses = ['draft', 'submitted', 'approved', 'rejected', 'partially_received', 'completed', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            throw new Exception("Invalid status: {$status}");
        }

        // Check if PO exists
        $po = $db->fetchOne("SELECT id, status FROM purchase_orders_header WHERE id = ?", [$po_id]);
        if (!$po) {
            throw new Exception("Purchase Order not found");
        }

        $update_data = ['status' => $status];

        // If approving, set approved_by and approved_at
        if ($status === 'approved' && $approved_by) {
            $update_data['approved_by'] = $approved_by;
            $update_data['approved_at'] = date('Y-m-d H:i:s');
        }

        $result = $db->update('purchase_orders_header', $update_data, 'id = :id', ['id' => $po_id]);

        if ($result) {
            return [
                'success' => true,
                'message' => "Purchase Order status updated to {$status}"
            ];
        } else {
            throw new Exception("Failed to update status");
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function generateGudangNasitaStockCode()
{
    ensureGudangNasitaStockSchemaCompatibility();
    $db = Database::getInstance();
    $prefix = 'GN-' . date('Ym') . '-';

    if (!gudangNasitaStockHasColumn('stock_code')) {
        // Legacy schema fallback: code is virtual from row id.
        return $prefix . str_pad('1', 4, '0', STR_PAD_LEFT);
    }

    $lastStock = $db->fetchOne("\n        SELECT stock_code\n        FROM gudang_nasita_stock\n        WHERE stock_code LIKE ?\n        ORDER BY stock_code DESC\n        LIMIT 1\n    ", [$prefix . '%']);

    if ($lastStock && !empty($lastStock['stock_code'])) {
        $lastNumber = (int)substr($lastStock['stock_code'], -4);
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }

    return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
}

function getGudangNasitaStock($limit = 200)
{
    $db = Database::getInstance();

    // Run each ensure separately so one failure doesn't block the other.
    try {
        ensureGudangNasitaStockSchemaCompatibility();
    } catch (Throwable $e) {
        error_log('getGudangNasitaStock stock schema skipped: ' . $e->getMessage());
    }
    try {
        ensureGudangNasitaOperationalTablesCompatibility();
    } catch (Throwable $e) {
        error_log('getGudangNasitaStock operational tables skipped: ' . $e->getMessage());
    }

    // Refresh column cache after potential ALTERs.
    gudangNasitaStockColumns(true);

    $cols = gudangNasitaStockColumns();

    $codeExpr = in_array('stock_code', $cols, true)
        ? 'gs.stock_code'
        : "CONCAT('GN-LEGACY-', LPAD(gs.id, 4, '0'))";

    // Build expressions only for columns that actually exist to avoid "Unknown column" errors.
    $hasBarangId    = in_array('barang_id', $cols, true);
    $barangJoin     = $hasBarangId
        ? "LEFT JOIN gudang_nasita_barang gb ON gb.id = gs.barang_id"
        : '';
    // Prefer stock-level harga_beli; fall back to master barang price when stock price is 0 or missing.
    $hargaBeliExpr  = in_array('harga_beli', $cols, true)
        ? ($hasBarangId ? 'COALESCE(NULLIF(gs.harga_beli, 0), gb.harga_beli, 0)' : 'COALESCE(gs.harga_beli, 0)')
        : ($hasBarangId ? 'COALESCE(gb.harga_beli, 0)' : '0');
    $qtyExpr        = in_array('quantity', $cols, true)     ? 'COALESCE(gs.quantity, 0)'     : 'COALESCE(gs.jumlah_stok, 0)';
    $totalHargaExpr = in_array('total_harga', $cols, true)
        ? "COALESCE(NULLIF(gs.total_harga, 0), {$qtyExpr} * {$hargaBeliExpr}, 0)"
        : "{$qtyExpr} * {$hargaBeliExpr}";
    $isActiveWhere  = in_array('is_active', $cols, true)   ? 'COALESCE(gs.is_active, 1) = 1' : '1 = 1';
    $itemNameOrder  = in_array('item_name', $cols, true)    ? 'gs.item_name'                  : 'gs.id';
    $categoryOrder  = in_array('category', $cols, true)     ? "COALESCE(gs.category, 'lainnya')" : "'lainnya'";

    // Only use movements subqueries if the table exists.
    $movementsExist = (bool)$db->fetchOne("SHOW TABLES LIKE 'gudang_nasita_movements'");
    $totalInExpr  = $movementsExist
        ? "COALESCE((SELECT SUM(quantity) FROM gudang_nasita_movements gm WHERE gm.stock_id = gs.id AND gm.movement_type = 'in_supplier'), 0)"
        : '0';
    $totalOutExpr = $movementsExist
        ? "COALESCE((SELECT SUM(quantity) FROM gudang_nasita_movements gm WHERE gm.stock_id = gs.id AND gm.movement_type = 'out_transfer'), 0)"
        : '0';

    $rows = $db->fetchAll("
        SELECT
            gs.*,
            {$codeExpr}      AS stock_code,
            {$hargaBeliExpr} AS harga_beli,
            {$totalHargaExpr} AS total_harga,
            {$totalInExpr}   AS total_in,
            {$totalOutExpr}  AS total_out
        FROM gudang_nasita_stock gs
        {$barangJoin}
        WHERE {$isActiveWhere}
        ORDER BY {$categoryOrder} ASC, {$itemNameOrder} ASC
        LIMIT {$limit}
    ");

    return is_array($rows) ? $rows : [];
}

function getGudangNasitaTransfers($limit = 50)
{
    $db = Database::getInstance();

    try {
        ensureGudangNasitaOperationalTablesCompatibility();
    } catch (Throwable $e) {
        error_log('getGudangNasitaTransfers schema bootstrap skipped: ' . $e->getMessage());
    }
    return $db->fetchAll("\n        SELECT\n            gt.*,\n            u.full_name AS created_by_name,\n            r.full_name AS received_by_name,\n            COUNT(gti.id) AS items_count,\n            COALESCE(SUM(gti.quantity), 0) AS total_qty\n        FROM gudang_nasita_transfers gt\n        LEFT JOIN users u ON gt.created_by = u.id\n        LEFT JOIN users r ON gt.received_by = r.id\n        LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id\n        GROUP BY gt.id\n        ORDER BY gt.created_at DESC\n        LIMIT {$limit}\n    ");
}

function addGudangNasitaManualStock($itemName, $unit, $quantity, $createdBy, $options = [])
{
    $db = Database::getInstance();

    try {
        ensureGudangNasitaStockSchemaCompatibility();
    } catch (Throwable $e) {
        error_log('addGudangNasitaManualStock stock schema skipped: ' . $e->getMessage());
    }
    try {
        ensureGudangNasitaOperationalTablesCompatibility();
    } catch (Throwable $e) {
        error_log('addGudangNasitaManualStock operational tables skipped: ' . $e->getMessage());
    }
    // Refresh column cache after schema ensure runs.
    gudangNasitaStockColumns(true);

    try {
        $itemName = trim((string)$itemName);
        $unit = trim((string)$unit);
        $quantity = (float)$quantity;
        $category = strtolower(trim((string)($options['category'] ?? '')));
        if ($category === '') {
            $category = 'lainnya';
        }

        if ($itemName === '') {
            throw new Exception('Nama item wajib diisi');
        }
        if ($unit === '') {
            $unit = 'pcs';
        }
        if ($quantity <= 0) {
            throw new Exception('Qty manual harus lebih dari 0');
        }

        $unitPrice = isset($options['unit_price']) ? (float)$options['unit_price'] : 0;
        $supplierName = trim((string)($options['supplier_name'] ?? ''));
        $notes = trim((string)($options['notes'] ?? ''));
        $reorderLevel = isset($options['reorder_level']) ? (float)$options['reorder_level'] : null;

        $db->getConnection()->beginTransaction();

        // Match by EXACT item_name ONLY — never fall back to barang_id. A stock row's
        // barang_id can be stale/wrong from historical bugs (e.g. linked to the wrong
        // master product), and matching on it silently merges distinct items that only
        // look similar (e.g. "Bir Bintang" vs "Bir Bintang large"). barang_id is still
        // resolved below purely to tag NEW rows for catalog linkage/reporting.
        $barangId = gudangNasitaStockRequiresBarangId()
            ? ensureGudangNasitaBarangId($itemName, $unit, $category, $notes)
            : null;
        $stock = $db->fetchOne(
            "SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?) LIMIT 1",
            [$itemName]
        );
        // Reactivate soft-deleted row so we update instead of insert
        if ($stock && !(int)($stock['is_active'] ?? 1)) {
            $db->update('gudang_nasita_stock', ['is_active' => 1], 'id = :id', ['id' => $stock['id']]);
        }

        if (!$stock) {
            $insertData = [
                'item_name' => $itemName,
                'category' => $category,
                'unit' => $unit,
                'quantity' => 0,
                'reorder_level' => $reorderLevel !== null && $reorderLevel >= 0 ? $reorderLevel : 0,
                'supplier_name' => $supplierName !== '' ? $supplierName : null,
                'notes' => $notes !== '' ? $notes : 'Input stok manual awal'
            ];

            if (gudangNasitaStockHasColumn('stock_code')) {
                $insertData['stock_code'] = generateGudangNasitaStockCode();
            }
            if (gudangNasitaStockRequiresBarangId()) {
                // $barangId already resolved above; use it directly to avoid re-lookup
                $insertData['barang_id'] = $barangId ?? ensureGudangNasitaBarangId($itemName, $unit, $category, $notes);
            }
            if (gudangNasitaStockHasColumn('jumlah_stok')) {
                $insertData['jumlah_stok'] = 0;
            }

            $stockId = $db->insert('gudang_nasita_stock', $insertData);
            $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ? LIMIT 1', [$stockId]);
        }

        $newQty = gudangNasitaCurrentQty($stock) + $quantity;
        $existingValue = gudangNasitaCurrentStockValue($stock);
        $incomingValue = $quantity * ($unitPrice > 0 ? $unitPrice : gudangNasitaCurrentUnitCost($stock));
        $newValue = $existingValue + $incomingValue;
        $newUnitCost = $newQty > 0 ? ($newValue / $newQty) : 0;
        $updateData = [
            'quantity' => $newQty,
            'harga_beli' => $newUnitCost,
            'total_harga' => $newValue,
            'supplier_name' => $supplierName !== '' ? $supplierName : ($stock['supplier_name'] ?? null),
            'notes' => $notes !== '' ? $notes : ($stock['notes'] ?? null),
            'category' => $category,
        ];
        if (gudangNasitaStockHasColumn('jumlah_stok')) {
            $updateData['jumlah_stok'] = $newQty;
        }
        if ($reorderLevel !== null && $reorderLevel >= 0) {
            $updateData['reorder_level'] = $reorderLevel;
        }

        $db->update('gudang_nasita_stock', $updateData, 'id = :id', ['id' => $stock['id']]);

        $referenceNumber = 'MAN-' . date('YmdHis');
        $db->insert('gudang_nasita_movements', [
            'stock_id' => $stock['id'],
            'movement_date' => date('Y-m-d'),
            'movement_type' => 'adjustment',
            'quantity' => $quantity,
            'reference_type' => 'manual_stock',
            'reference_id' => null,
            'reference_number' => $referenceNumber,
            'unit_price' => $unitPrice > 0 ? $unitPrice : gudangNasitaCurrentUnitCost($stock),
            'subtotal' => $incomingValue,
            'notes' => $notes !== '' ? $notes : 'Input stok manual awal',
            'created_by' => $createdBy
        ]);

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => 'Stok manual berhasil ditambahkan',
            'stock_id' => (int)$stock['id'],
            'new_qty' => (float)$updateData['quantity']
        ];
    } catch (Throwable $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }
        error_log('[GUDANG] addGudangNasitaManualStock FAILED: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function recordGudangNasitaDailyStockOut($itemName, $quantity, $createdBy, $options = [])
{
    $db = Database::getInstance();

    try {
        ensureGudangNasitaStockSchemaCompatibility();
        ensureGudangNasitaOperationalTablesCompatibility();
    } catch (Throwable $e) {
        error_log('recordGudangNasitaDailyStockOut schema bootstrap skipped: ' . $e->getMessage());
    }

    try {
        $itemName = trim((string)$itemName);
        $quantity = (float)$quantity;
        $notes = trim((string)($options['notes'] ?? ''));

        if ($itemName === '') {
            throw new Exception('Nama item stok wajib diisi');
        }
        if ($quantity <= 0) {
            throw new Exception('Qty stock keluar harus lebih dari 0');
        }

        $db->getConnection()->beginTransaction();

        $stock = $db->fetchOne(
            "SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?) AND COALESCE(is_active,1) = 1 LIMIT 1",
            [$itemName]
        );

        if (!$stock) {
            throw new Exception('Item stok tidak ditemukan di Gudang Nasita');
        }

        $currentQty = (float)gudangNasitaCurrentQty($stock);
        if ($quantity > $currentQty) {
            throw new Exception('Qty stock keluar melebihi stok tersedia untuk item ' . $stock['item_name']);
        }

        $unitPrice = (float)gudangNasitaCurrentUnitCost($stock);
        $lineSubtotal = $quantity * $unitPrice;
        $remainingQty = $currentQty - $quantity;
        $remainingValue = max(0, (float)gudangNasitaCurrentStockValue($stock) - $lineSubtotal);

        $updateData = [
            'quantity' => $remainingQty,
            'total_harga' => $remainingValue,
            'harga_beli' => $remainingQty > 0 ? ($remainingValue / $remainingQty) : 0,
            'notes' => $notes !== '' ? $notes : ($stock['notes'] ?? null),
        ];
        if (gudangNasitaStockHasColumn('jumlah_stok')) {
            $updateData['jumlah_stok'] = $remainingQty;
        }

        $db->update('gudang_nasita_stock', $updateData, 'id = :id', ['id' => $stock['id']]);

        $referenceNumber = 'OUT-' . date('YmdHis');
        $db->insert('gudang_nasita_movements', [
            'stock_id' => $stock['id'],
            'movement_date' => date('Y-m-d'),
            'movement_type' => 'out_transfer',
            'quantity' => $quantity,
            'reference_type' => 'daily_stock_out',
            'reference_id' => null,
            'reference_number' => $referenceNumber,
            'unit_price' => $unitPrice,
            'subtotal' => $lineSubtotal,
            'notes' => $notes !== '' ? $notes : 'Pengeluaran stok harian',
            'created_by' => $createdBy,
        ]);

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => 'Stock keluar berhasil dicatat',
            'stock_id' => (int)$stock['id'],
            'remaining_qty' => (float)$remainingQty,
        ];
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }
        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}

function receivePurchaseOrderToGudang($po_id, array $receivedItems, $receivedBy, $notes = '')
{
    $db = Database::getInstance();

    try {
        ensureGudangNasitaStockSchemaCompatibility();
        ensureGudangNasitaOperationalTablesCompatibility();
    } catch (Throwable $e) {
        error_log('receivePurchaseOrderToGudang schema bootstrap skipped: ' . $e->getMessage());
    }

    try {
        $po = getPurchaseOrder($po_id);
        if (!$po) {
            throw new Exception('Purchase Order not found');
        }

        $db->getConnection()->beginTransaction();

        $totalReceived = 0;
        $allCompleted = true;

        foreach ($po['items'] as $item) {
            $detailId = (int)$item['id'];
            $orderedQty = (float)$item['quantity'];
            $existingReceived = (float)($item['received_quantity'] ?? 0);
            $remainingQty = max(0, $orderedQty - $existingReceived);
            $receivedQty = isset($receivedItems[$detailId]) ? (float)$receivedItems[$detailId] : 0;

            if ($receivedQty <= 0) {
                if ($remainingQty > 0) {
                    $allCompleted = false;
                }
                continue;
            }

            if ($receivedQty > $remainingQty) {
                throw new Exception('Qty received melebihi sisa qty untuk item ' . $item['item_name']);
            }

            $unit = trim($item['unit_of_measure'] ?: 'pcs');
            // Match by name only so existing stock is updated regardless of unit mismatch
            if (gudangNasitaStockRequiresBarangId()) {
                $stock = $db->fetchOne(
                    "SELECT gs.*, gb.nama_barang AS master_item_name
                     FROM gudang_nasita_stock gs
                     LEFT JOIN gudang_nasita_barang gb ON gb.id = gs.barang_id
                     WHERE LOWER(COALESCE(gs.item_name, gb.nama_barang, '')) = LOWER(?)
                     AND COALESCE(gs.is_active, 1) = 1
                     LIMIT 1",
                    [$item['item_name']]
                );
            } else {
                $stock = $db->fetchOne("SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?) AND is_active = 1 LIMIT 1", [$item['item_name']]);
            }
            if (!$stock) {
                $stock = $db->fetchOne("SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) LIKE LOWER(?) AND COALESCE(is_active,1) = 1 ORDER BY COALESCE(quantity, jumlah_stok, 0) DESC LIMIT 1", ['%' . trim($item['item_name']) . '%']);
            }

            if (!$stock) {
                $insertData = [
                    'item_name' => trim($item['item_name']),
                    'category' => 'lainnya',
                    'unit' => $unit,
                    'quantity' => 0,
                    'harga_beli' => isset($item['unit_price']) ? (float)$item['unit_price'] : 0,
                    'total_harga' => 0,
                    'supplier_name' => $po['supplier_name'] ?? null,
                    'notes' => $notes ?: ('Auto created from PO ' . $po['po_number'])
                ];

                if (gudangNasitaStockHasColumn('stock_code')) {
                    $insertData['stock_code'] = generateGudangNasitaStockCode();
                }
                if (gudangNasitaStockRequiresBarangId()) {
                    $insertData['barang_id'] = ensureGudangNasitaBarangId(trim($item['item_name']), $unit, 'lainnya', $notes ?: ('Auto created from PO ' . $po['po_number']));
                }
                if (gudangNasitaStockHasColumn('jumlah_stok')) {
                    $insertData['jumlah_stok'] = 0;
                }

                $stockId = $db->insert('gudang_nasita_stock', $insertData);
                $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ? LIMIT 1', [$stockId]);
            }

            $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : 0;
            $lineSubtotal = isset($item['subtotal']) ? (float)$item['subtotal'] : ($receivedQty * $unitPrice);
            $existingQty = gudangNasitaCurrentQty($stock);
            $existingValue = gudangNasitaCurrentStockValue($stock);
            $incomingValue = $lineSubtotal > 0 ? $lineSubtotal : ($receivedQty * $unitPrice);
            $newQty = $existingQty + $receivedQty;
            $newValue = $existingValue + $incomingValue;
            $newUnitCost = $newQty > 0 ? ($newValue / $newQty) : $unitPrice;
            $stockUpdate = [
                'quantity' => $newQty,
                'harga_beli' => $newUnitCost,
                'total_harga' => $newValue,
                'supplier_name' => $po['supplier_name'] ?? $stock['supplier_name'],
                'notes' => $notes ?: $stock['notes']
            ];
            if (gudangNasitaStockHasColumn('jumlah_stok')) {
                $stockUpdate['jumlah_stok'] = $newQty;
            }
            $db->update('gudang_nasita_stock', $stockUpdate, 'id = :id', ['id' => $stock['id']]);

            $db->insert('gudang_nasita_movements', [
                'stock_id' => $stock['id'],
                'movement_date' => date('Y-m-d'),
                'movement_type' => 'in_supplier',
                'quantity' => $receivedQty,
                'reference_type' => 'purchase_order',
                'reference_id' => $po_id,
                'reference_number' => $po['po_number'],
                'unit_price' => $unitPrice,
                'subtotal' => $incomingValue,
                'notes' => $notes ?: ('Received from supplier ' . ($po['supplier_name'] ?? '')),
                'created_by' => $receivedBy
            ]);

            $newReceived = $existingReceived + $receivedQty;
            $db->update('purchase_orders_detail', [
                'received_quantity' => $newReceived
            ], 'id = :id', ['id' => $detailId]);
            $totalReceived += $receivedQty;
            if ($newReceived < $orderedQty) {
                $allCompleted = false;
            }
        }

        if ($totalReceived <= 0) {
            throw new Exception('Tidak ada qty yang diterima');
        }

        if ($allCompleted) {
            $db->update('purchase_orders_header', [
                'status' => 'completed',
            ], 'id = :id', ['id' => $po_id]);
        } else {
            $db->update('purchase_orders_header', [
                'status' => 'partially_received',
            ], 'id = :id', ['id' => $po_id]);
        }

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => 'Barang berhasil dimasukkan ke Gudang Nasita',
            'total_received' => $totalReceived,
            'all_completed' => $allCompleted
        ];
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function generateGudangNasitaTransferNumber()
{
    $db = Database::getInstance();
    $prefix = 'GNT-' . date('Ym') . '-';

    $lastTransfer = $db->fetchOne("\n        SELECT transfer_number\n        FROM gudang_nasita_transfers\n        WHERE transfer_number LIKE ?\n        ORDER BY transfer_number DESC\n        LIMIT 1\n    ", [$prefix . '%']);

    if ($lastTransfer && !empty($lastTransfer['transfer_number'])) {
        $lastNumber = (int)substr($lastTransfer['transfer_number'], -4);
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }

    return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
}

function gudangNasitaStockColumns($refresh = false)
{
    static $columns = null;

    if (!$refresh && $columns !== null) {
        return $columns;
    }

    $db = Database::getInstance();
    $rows = $db->fetchAll('SHOW COLUMNS FROM gudang_nasita_stock');
    $columns = array_column($rows, 'Field');

    return $columns;
}

function gudangNasitaStockHasColumn($columnName)
{
    return in_array($columnName, gudangNasitaStockColumns(), true);
}

function gudangNasitaStockRequiresBarangId()
{
    return gudangNasitaStockHasColumn('barang_id');
}

function gudangNasitaCurrentQty(array $stock)
{
    if (isset($stock['quantity'])) {
        return (float)$stock['quantity'];
    }
    if (isset($stock['jumlah_stok'])) {
        return (float)$stock['jumlah_stok'];
    }
    return 0.0;
}

function gudangNasitaCurrentUnitCost(array $stock)
{
    if (isset($stock['harga_beli']) && (float)$stock['harga_beli'] > 0) {
        return (float)$stock['harga_beli'];
    }
    $qty = gudangNasitaCurrentQty($stock);
    if ($qty > 0 && isset($stock['total_harga']) && (float)$stock['total_harga'] > 0) {
        return (float)$stock['total_harga'] / $qty;
    }
    return 0.0;
}

function gudangNasitaCurrentStockValue(array $stock)
{
    if (isset($stock['total_harga']) && (float)$stock['total_harga'] > 0) {
        return (float)$stock['total_harga'];
    }
    return gudangNasitaCurrentQty($stock) * gudangNasitaCurrentUnitCost($stock);
}

function generateGudangNasitaBarangCode()
{
    $db = Database::getInstance();
    $prefix = 'GNB-' . date('Ym') . '-';

    $last = $db->fetchOne('SELECT kode_barang FROM gudang_nasita_barang WHERE kode_barang LIKE ? ORDER BY kode_barang DESC LIMIT 1', [$prefix . '%']);
    if ($last && !empty($last['kode_barang'])) {
        $seq = (int)substr($last['kode_barang'], -4) + 1;
    } else {
        $seq = 1;
    }

    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function ensureGudangNasitaBarangId($itemName, $unit = 'pcs', $category = 'lainnya', $notes = '')
{
    $db = Database::getInstance();

    $tableExists = $db->fetchOne("SHOW TABLES LIKE 'gudang_nasita_barang'");
    if (!$tableExists) {
        return null;
    }

    $existing = $db->fetchOne('SELECT id FROM gudang_nasita_barang WHERE LOWER(nama_barang) = LOWER(?) LIMIT 1', [$itemName]);
    if ($existing) {
        return (int)$existing['id'];
    }

    $cols = $db->fetchAll('SHOW COLUMNS FROM gudang_nasita_barang');
    $colNames = array_column($cols, 'Field');

    $insertData = [];
    if (in_array('kode_barang', $colNames, true)) {
        $insertData['kode_barang'] = generateGudangNasitaBarangCode();
    }
    if (in_array('nama_barang', $colNames, true)) {
        $insertData['nama_barang'] = $itemName;
    }
    if (in_array('deskripsi', $colNames, true)) {
        $insertData['deskripsi'] = $notes !== '' ? $notes : 'Auto created from Gudang Nasita stock input';
    }
    if (in_array('satuan', $colNames, true)) {
        $insertData['satuan'] = $unit !== '' ? $unit : 'pcs';
    }
    if (in_array('kategori', $colNames, true)) {
        $insertData['kategori'] = $category !== '' ? $category : 'lainnya';
    }
    if (in_array('harga_beli', $colNames, true)) {
        $insertData['harga_beli'] = 0;
    }
    if (in_array('harga_jual', $colNames, true)) {
        $insertData['harga_jual'] = 0;
    }
    if (in_array('is_active', $colNames, true)) {
        $insertData['is_active'] = 1;
    }

    $id = $db->insert('gudang_nasita_barang', $insertData);
    return (int)$id;
}

function ensureGudangNasitaStockSchemaCompatibility()
{
    static $checked = false;

    if ($checked) {
        return;
    }
    $checked = true;

    $db = Database::getInstance();
    $columns = gudangNasitaStockColumns();

    $requiredColumns = [
        'item_name' => "VARCHAR(200) NOT NULL DEFAULT ''",
        'category' => "VARCHAR(80) DEFAULT 'lainnya'",
        'unit' => "VARCHAR(20) DEFAULT 'pcs'",
        'quantity' => "DECIMAL(15,2) NOT NULL DEFAULT 0",
        'reorder_level' => "DECIMAL(15,2) DEFAULT 0",
        'supplier_name' => "VARCHAR(150) NULL",
        'notes' => "TEXT NULL",
        'is_active' => "TINYINT(1) DEFAULT 1",
        'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    foreach ($requiredColumns as $col => $definition) {
        if (!in_array($col, $columns, true)) {
            $db->query("ALTER TABLE gudang_nasita_stock ADD COLUMN `{$col}` {$definition}");
        }
    }

    // Refresh columns after potential ALTERs.
    $columns = gudangNasitaStockColumns(true);

    if (!in_array('stock_code', $columns, true)) {
        // Add stock_code for compatibility with current procurement flow.
        $db->query('ALTER TABLE gudang_nasita_stock ADD COLUMN stock_code VARCHAR(30) NULL AFTER id');

        // Refresh cached columns after DDL.
        $columns = gudangNasitaStockColumns(true);

        if (in_array('stock_code', $columns, true)) {
            $db->query("UPDATE gudang_nasita_stock SET stock_code = CONCAT('GN-', DATE_FORMAT(NOW(), '%Y%m'), '-', LPAD(id, 4, '0')) WHERE stock_code IS NULL OR stock_code = ''");
        }
    }

    if (in_array('stock_code', $columns, true)) {
        $db->query("UPDATE gudang_nasita_stock SET stock_code = CONCAT('GN-', DATE_FORMAT(NOW(), '%Y%m'), '-', LPAD(id, 4, '0')) WHERE stock_code IS NULL OR stock_code = ''");

        $stockCodeIdx = $db->fetchOne("SHOW INDEX FROM gudang_nasita_stock WHERE Key_name = 'idx_stock_code'");
        if (!$stockCodeIdx) {
            $db->query('ALTER TABLE gudang_nasita_stock ADD UNIQUE KEY idx_stock_code (stock_code)');
        }
    }

    $itemNameIdx = $db->fetchOne("SHOW INDEX FROM gudang_nasita_stock WHERE Key_name = 'idx_item_name'");
    if (!$itemNameIdx) {
        $db->query('ALTER TABLE gudang_nasita_stock ADD INDEX idx_item_name (item_name)');
    }

    $isActiveIdx = $db->fetchOne("SHOW INDEX FROM gudang_nasita_stock WHERE Key_name = 'idx_is_active'");
    if (!$isActiveIdx) {
        $db->query('ALTER TABLE gudang_nasita_stock ADD INDEX idx_is_active (is_active)');
    }
}

function ensureGudangNasitaOperationalTablesCompatibility()
{
    static $checked = false;

    if ($checked) {
        return;
    }
    $checked = true;

    $db = Database::getInstance();

    $db->query("CREATE TABLE IF NOT EXISTS gudang_nasita_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stock_id INT NOT NULL,
        movement_date DATE NOT NULL,
        movement_type ENUM('in_supplier','out_transfer','adjustment') NOT NULL,
        quantity DECIMAL(15,2) NOT NULL,
        unit_price DECIMAL(15,2) NULL,
        subtotal DECIMAL(15,2) NULL,
        reference_type VARCHAR(50) NULL,
        reference_id INT NULL,
        reference_number VARCHAR(50) NULL,
        target_business_id INT NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_stock_id (stock_id),
        INDEX idx_movement_date (movement_date),
        INDEX idx_reference (reference_type, reference_id),
        INDEX idx_target_business (target_business_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS gudang_nasita_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_number VARCHAR(50) UNIQUE,
        target_business_id INT NOT NULL,
        target_business_name VARCHAR(150) NOT NULL,
        source_po_id INT NULL,
        status ENUM('draft','sent','received','cancelled') DEFAULT 'draft',
        notes TEXT NULL,
        created_by INT NULL,
        received_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_target_business (target_business_id),
        INDEX idx_status (status),
        INDEX idx_source_po (source_po_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS gudang_nasita_transfer_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_id INT NOT NULL,
        stock_id INT NOT NULL,
        item_name VARCHAR(200) NOT NULL,
        unit VARCHAR(20) DEFAULT 'pcs',
        quantity DECIMAL(15,2) NOT NULL,
        unit_price DECIMAL(15,2) NULL,
        subtotal DECIMAL(15,2) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_transfer_id (transfer_id),
        INDEX idx_stock_id (stock_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Backfill missing columns for legacy tables that were created with older schema.
    try {
        $transferColsRaw = $db->fetchAll("SHOW COLUMNS FROM gudang_nasita_transfers");
        $transferCols = [];
        foreach ($transferColsRaw as $c) {
            $transferCols[strtolower((string)($c['Field'] ?? ''))] = true;
        }

        $transferRequired = [
            'transfer_number' => "VARCHAR(50) NULL",
            'target_business_id' => "INT NULL",
            'target_business_name' => "VARCHAR(150) NULL",
            'source_po_id' => "INT NULL",
            'status' => "VARCHAR(20) NULL",
            'notes' => "TEXT NULL",
            'created_by' => "INT NULL",
            'received_by' => "INT NULL",
            'created_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];

        foreach ($transferRequired as $col => $ddl) {
            if (!isset($transferCols[$col])) {
                $db->query("ALTER TABLE gudang_nasita_transfers ADD COLUMN `{$col}` {$ddl}");
            }
        }

        $transferNumIdx = $db->fetchOne("SHOW INDEX FROM gudang_nasita_transfers WHERE Key_name = 'idx_transfer_number'");
        if (!$transferNumIdx) {
            $db->query("ALTER TABLE gudang_nasita_transfers ADD INDEX idx_transfer_number (transfer_number)");
        }
    } catch (Throwable $e) {
        error_log('ensureGudangNasitaOperationalTablesCompatibility transfers backfill error: ' . $e->getMessage());
    }

    try {
        $transferItemColsRaw = $db->fetchAll("SHOW COLUMNS FROM gudang_nasita_transfer_items");
        $transferItemCols = [];
        foreach ($transferItemColsRaw as $c) {
            $transferItemCols[strtolower((string)($c['Field'] ?? ''))] = true;
        }

        $transferItemRequired = [
            'transfer_id' => "INT NULL",
            'stock_id' => "INT NULL",
            'item_name' => "VARCHAR(200) NULL",
            'unit' => "VARCHAR(20) DEFAULT 'pcs'",
            'quantity' => "DECIMAL(15,2) NOT NULL DEFAULT 0",
            'unit_price' => "DECIMAL(15,2) NULL",
            'subtotal' => "DECIMAL(15,2) NULL",
            'notes' => "TEXT NULL",
            'created_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
        ];

        foreach ($transferItemRequired as $col => $ddl) {
            if (!isset($transferItemCols[$col])) {
                $db->query("ALTER TABLE gudang_nasita_transfer_items ADD COLUMN `{$col}` {$ddl}");
            }
        }
    } catch (Throwable $e) {
        error_log('ensureGudangNasitaOperationalTablesCompatibility transfer_items backfill error: ' . $e->getMessage());
    }

    try {
        $movementColsRaw = $db->fetchAll("SHOW COLUMNS FROM gudang_nasita_movements");
        $movementCols = [];
        foreach ($movementColsRaw as $c) {
            $movementCols[strtolower((string)($c['Field'] ?? ''))] = true;
        }

        foreach (['unit_price' => "DECIMAL(15,2) NULL", 'subtotal' => "DECIMAL(15,2) NULL"] as $col => $ddl) {
            if (!isset($movementCols[$col])) {
                $db->query("ALTER TABLE gudang_nasita_movements ADD COLUMN `{$col}` {$ddl}");
            }
        }
    } catch (Throwable $e) {
        error_log('ensureGudangNasitaOperationalTablesCompatibility movements backfill error: ' . $e->getMessage());
    }
}

function transferGudangNasitaStock($targetBusinessId, array $items, $createdBy, $notes = '', $sourcePoId = null, $businessName = null)
{
    $db = Database::getInstance();

    try {
        ensureGudangNasitaOperationalTablesCompatibility();
    } catch (Throwable $e) {
        error_log('transferGudangNasitaStock schema bootstrap skipped: ' . $e->getMessage());
    }
    try {
        if (empty($items)) {
            throw new Exception('Minimal 1 item transfer wajib diisi');
        }

        $targetBusinessId = (int)$targetBusinessId;
        $business = $db->fetchOne('SELECT id, business_name FROM businesses WHERE id = ? LIMIT 1', [$targetBusinessId]);

        // If ID mismatch happens (common in cross-DB context), remap by business name in current DB.
        if (!$business && $businessName !== null && trim((string)$businessName) !== '') {
            $business = $db->fetchOne(
                'SELECT id, business_name FROM businesses WHERE LOWER(TRIM(business_name)) = LOWER(TRIM(?)) LIMIT 1',
                [trim((string)$businessName)]
            );
            if ($business) {
                $targetBusinessId = (int)$business['id'];
            }
        }

        if (!$business) {
            throw new Exception('Tujuan bisnis tidak ditemukan di database gudang. Periksa data businesses.');
        }

        $db->getConnection()->beginTransaction();

        $transferNumber = generateGudangNasitaTransferNumber();

        $transferColsRaw = $db->fetchAll('SHOW COLUMNS FROM gudang_nasita_transfers');
        $transferCols = [];
        foreach ($transferColsRaw as $col) {
            $field = strtolower((string)($col['Field'] ?? ''));
            if ($field !== '') {
                $transferCols[$field] = true;
            }
        }

        $transferData = [];

        if (isset($transferCols['transfer_number'])) {
            $transferData['transfer_number'] = $transferNumber;
        }
        if (isset($transferCols['no_transfer'])) {
            $transferData['no_transfer'] = $transferNumber;
        }

        if (isset($transferCols['target_business_id'])) {
            $transferData['target_business_id'] = $targetBusinessId;
        }
        if (isset($transferCols['bisnis_tujuan_id'])) {
            $transferData['bisnis_tujuan_id'] = $targetBusinessId;
        }

        if (isset($transferCols['target_business_name'])) {
            $transferData['target_business_name'] = $business['business_name'];
        }
        if (isset($transferCols['source_po_id'])) {
            $transferData['source_po_id'] = $sourcePoId;
        }
        if (isset($transferCols['status'])) {
            $transferData['status'] = 'received';
        }

        if (isset($transferCols['notes'])) {
            $transferData['notes'] = $notes;
        }
        if (isset($transferCols['catatan'])) {
            $transferData['catatan'] = $notes;
        }

        if (isset($transferCols['tanggal_transfer'])) {
            $transferData['tanggal_transfer'] = date('Y-m-d');
        }
        if (isset($transferCols['created_by'])) {
            $transferData['created_by'] = $createdBy;
        }

        $transferId = $db->insert('gudang_nasita_transfers', $transferData);

        $totalQty = 0;
        foreach ($items as $item) {
            $stockId = (int)($item['stock_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            if ($stockId <= 0 || $qty <= 0) {
                continue;
            }

            $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ? LIMIT 1', [$stockId]);
            if (!$stock) {
                throw new Exception('Stock tidak ditemukan');
            }

            $available = (float)$stock['quantity'];
            $unitPrice = gudangNasitaCurrentUnitCost($stock);
            $lineSubtotal = $qty * $unitPrice;
            if ($qty > $available) {
                throw new Exception('Stok tidak cukup untuk item ' . $stock['item_name']);
            }

            $remaining = $available - $qty;
            $remainingValue = max(0, gudangNasitaCurrentStockValue($stock) - $lineSubtotal);
            $db->update('gudang_nasita_stock', [
                'quantity' => $remaining,
                'harga_beli' => $remaining > 0 ? ($remainingValue / $remaining) : $unitPrice,
                'total_harga' => $remainingValue
            ], 'id = :id', ['id' => $stockId]);

            $db->insert('gudang_nasita_transfer_items', [
                'transfer_id' => $transferId,
                'stock_id' => $stockId,
                'item_name' => $stock['item_name'],
                'unit' => $stock['unit'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'notes' => $item['notes'] ?? null
            ]);

            $db->insert('gudang_nasita_movements', [
                'stock_id' => $stockId,
                'movement_date' => date('Y-m-d'),
                'movement_type' => 'out_transfer',
                'quantity' => $qty,
                'reference_type' => 'transfer',
                'reference_id' => $transferId,
                'reference_number' => $transferNumber,
                'target_business_id' => $targetBusinessId,
                'unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'notes' => $notes ?: ('Transfer ke ' . $business['business_name']),
                'created_by' => $createdBy
            ]);

            $totalQty += $qty;
        }

        if ($totalQty <= 0) {
            throw new Exception('Tidak ada item transfer yang valid');
        }

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => 'Barang berhasil ditransfer dari Gudang Nasita',
            'transfer_id' => $transferId,
            'transfer_number' => $transferNumber,
            'total_qty' => $totalQty,
            'business_name' => $business['business_name']
        ];
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get all Purchase Orders with filters
 * 
 * @param array $filters Optional filters: status, supplier_id, date_from, date_to
 * @param int $limit Limit results (default 100)
 * @param int $offset Offset for pagination (default 0)
 * @return array Purchase Orders list
 */
function getPurchaseOrders($filters = [], $limit = 100, $offset = 0)
{
    $db = Database::getInstance();

    $whereConditions = [];
    $params = [];
    $poDateExpr = 'poh.po_date';

    try {
        $headerCols = $db->fetchAll('SHOW COLUMNS FROM purchase_orders_header');
        $headerColNames = array_map(function ($row) {
            return strtolower((string)($row['Field'] ?? ''));
        }, $headerCols ?: []);

        $hasPoDate = in_array('po_date', $headerColNames, true);
        $hasCreatedAt = in_array('created_at', $headerColNames, true);

        if ($hasPoDate && $hasCreatedAt) {
            $poDateExpr = 'COALESCE(poh.po_date, DATE(poh.created_at))';
        } elseif ($hasCreatedAt && !$hasPoDate) {
            $poDateExpr = 'DATE(poh.created_at)';
        }
    } catch (Throwable $e) {
        $poDateExpr = 'poh.po_date';
    }

    if (!empty($filters['status'])) {
        $whereConditions[] = 'poh.status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['supplier_id'])) {
        $whereConditions[] = 'poh.supplier_id = :supplier_id';
        $params['supplier_id'] = $filters['supplier_id'];
    }
    if (!empty($filters['business_id'])) {
        $whereConditions[] = 'poh.business_id = :business_id';
        $params['business_id'] = $filters['business_id'];
    }
    if (!empty($filters['business_id_or_null'])) {
        $whereConditions[] = '(poh.business_id = :biz_id_or_null OR poh.business_id IS NULL)';
        $params['biz_id_or_null'] = $filters['business_id_or_null'];
    }
    if (!empty($filters['exclude_gdn_prefix'])) {
        $whereConditions[] = "poh.po_number NOT LIKE 'GDN-%'";
    }
    if (!empty($filters['date_from'])) {
        $whereConditions[] = "{$poDateExpr} >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $whereConditions[] = "{$poDateExpr} <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);

    try {
        $query = "
            SELECT
                poh.*,
                s.supplier_name,
                s.supplier_code,
                u.full_name AS created_by_name,
                COUNT(pod.id) AS items_count
            FROM purchase_orders_header poh
            LEFT JOIN suppliers s ON poh.supplier_id = s.id
            LEFT JOIN users u ON poh.created_by = u.id
            LEFT JOIN purchase_orders_detail pod ON poh.id = pod.po_header_id
            {$whereClause}
            GROUP BY poh.id
            ORDER BY poh.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $result = $db->fetchAll($query, $params);
        if (is_array($result)) {
            return $result;
        }
    } catch (Throwable $e) {
        error_log('getPurchaseOrders full query error: ' . $e->getMessage());
    }

    try {
        $raw = $db->fetchAll("SELECT * FROM purchase_orders_header ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        if (!is_array($raw)) {
            return [];
        }

        $filtered = [];
        foreach ($raw as $row) {
            if (!empty($filters['status']) && (string)($row['status'] ?? '') !== (string)$filters['status']) {
                continue;
            }
            $poNumber = (string)($row['po_number'] ?? '');
            if (!empty($filters['exclude_gdn_prefix']) && strpos($poNumber, 'GDN-') === 0) {
                continue;
            }
            if (!empty($filters['business_id_or_null'])) {
                $businessId = (int)($row['business_id'] ?? 0);
                $targetBusinessId = (int)$filters['business_id_or_null'];
                if ($businessId !== $targetBusinessId && $businessId !== 0) {
                    continue;
                }
            }
            $row['items_count'] = 0;
            $filtered[] = $row;
        }

        return $filtered;
    } catch (Throwable $e) {
        error_log('getPurchaseOrders absolute fallback error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Approve Purchase Order and Post to Cash Book
 * This function approves the PO, marks it as completed, and creates cash_book entry
 * 
 * @param int $po_id Purchase Order ID
 * @param int $approved_by User ID who approved
 * @param array $options Optional: payment_date, payment_notes
 * @return array ['success' => bool, 'message' => string, 'cash_book_id' => int]
 */
function approvePurchaseOrderAndPay($po_id, $approved_by, $options = [])
{
    $db = Database::getInstance();

    try {
        // Get PO details
        $po = getPurchaseOrder($po_id);
        if (!$po) {
            throw new Exception("Purchase Order not found");
        }

        // Check if already approved/completed
        if (in_array($po['status'], ['completed', 'cancelled'])) {
            throw new Exception("Purchase Order already {$po['status']}");
        }

        $db->getConnection()->beginTransaction();

        // 1. Fix: Validate approved_by user exists (Handle session mismatch)
        $user_check = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$approved_by]);
        if (!$user_check) {
            // Fallback to Admin (ID 1)
            $admin = $db->fetchOne("SELECT id FROM users WHERE id = 1 OR role = 'admin' LIMIT 1");
            $approved_by = $admin ? $admin['id'] : 1;
        }

        // 2. Handle File Upload (Attachment)
        $attachment_path = null;
        if (isset($options['attachment_file']) && $options['attachment_file']['error'] === UPLOAD_ERR_OK) {
            $file_extension = strtolower(pathinfo($options['attachment_file']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'gif'];

            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = 'PO_' . $po['po_number'] . '_' . time() . '.' . $file_extension;
                $cloudinary = CloudinaryHelper::getInstance();
                $uploadResult = $cloudinary->smartUpload($options['attachment_file'], 'uploads/purchase_attachments', $new_filename, 'attachments', 'po_' . $po['po_number']);
                if ($uploadResult['success']) {
                    $attachment_path = $uploadResult['path'];
                }
            }
        }

        // 3. Save Attachment to Separate Table (transaction_attachments)
        if ($attachment_path) {
            $db->insert('transaction_attachments', [
                'transaction_type' => 'purchase_order',
                'transaction_id' => $po_id,
                'file_path' => $attachment_path,
                'file_name' => $new_filename,
                'file_type' => $file_extension,
                'uploaded_by' => $approved_by
            ]);
        }

        // 4. Update PO Status to Completed
        $update_data = [
            'status' => 'completed',
            'approved_by' => $approved_by,
            'approved_at' => date('Y-m-d H:i:s')
        ];

        if ($attachment_path) {
            $update_data['attachment_path'] = $attachment_path; // Backward compatibility
        }

        $db->update('purchase_orders_header', $update_data, 'id = :id', ['id' => $po_id]);

        // 5. Create Cash Book Entry (Only if not exists)
        $existing_payment = $db->fetchOne(
            "SELECT id FROM cash_book WHERE source_type = 'purchase_order' AND reference_no = ?",
            [$po['po_number']]
        );

        $cash_book_id = 0;

        if ($existing_payment) {
            // Payment already exists, skip insert
            $cash_book_id = $existing_payment['id'];
        } else {
            // Prepare cash_book entry
            $payment_date = isset($options['payment_date']) ? $options['payment_date'] : date('Y-m-d');
            $payment_notes = isset($options['payment_notes']) ? $options['payment_notes'] :
                "Pembayaran PO #{$po['po_number']} - {$po['supplier_name']}";

            // Get expense category
            // Prefer explicit Payment category, otherwise default expense
            $expense_category = $db->fetchOne("SELECT id FROM categories WHERE category_name LIKE '%Payment Supplier%' OR category_name LIKE '%Pembayaran Supplier%' LIMIT 1");

            if (!$expense_category) {
                $expense_category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'expense' LIMIT 1");
            }

            if (!$expense_category) {
                // Create default category
                try {
                    $category_id = $db->insert('categories', [
                        'category_name' => 'Pembayaran Supplier',
                        'category_type' => 'expense',
                        'description' => 'Pembayaran PO ke Supplier',
                        'is_active' => 1
                    ]);
                } catch (Exception $cat_ex) {
                    throw new Exception("Gagal create kategori: " . $cat_ex->getMessage());
                }
            } else {
                $category_id = $expense_category['id'];
            }

            // Get division
            $division_id = 1;
            if (isset($po['items'][0]['division_id']) && $po['items'][0]['division_id'] > 0) {
                $division_id = $po['items'][0]['division_id'];
            } else {
                $first_div = $db->fetchOne("SELECT id FROM divisions LIMIT 1");
                if ($first_div) {
                    $division_id = $first_div['id'];
                }
            }

            // Post to cash_book (pengeluaran)
            $cash_book_data = [
                'transaction_date' => $payment_date,
                'transaction_time' => date('H:i:s'),
                'description' => $payment_notes,
                'amount' => $po['total_amount'],
                'transaction_type' => 'expense',
                'payment_method' => 'cash',
                'category_id' => $category_id,
                'division_id' => $division_id,
                'created_by' => $approved_by,
                'source_type' => 'purchase_order',
                'reference_no' => $po['po_number'],
                'is_editable' => 0
            ];

            try {
                $cash_book_id = $db->insert('cash_book', $cash_book_data);
                if (!$cash_book_id) {
                    throw new Exception("Insert returned false");
                }
            } catch (Exception $cb_ex) {
                throw new Exception("Gagal post ke cash book: " . $cb_ex->getMessage());
            }
        }

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => "Purchase Order approved and payment posted to cash book",
            'po_number' => $po['po_number'],
            'amount' => $po['total_amount'],
            'cash_book_id' => $cash_book_id
        ];
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Delete Purchase Order (only if status is draft)
    $db = Database::getInstance();
    
    try {
        // Check if PO exists and is draft
        $po = $db->fetchOne("SELECT id, status, po_number FROM purchase_orders_header WHERE id = ?", [$po_id]);
        if (!$po) {
            throw new Exception("Purchase Order not found");
        }
        
        if ($po['status'] !== 'draft') {
            throw new Exception("Only draft Purchase Orders can be deleted. Current status: {$po['status']}");
        }
        
        $db->getConnection()->beginTransaction();
        
        // Delete details first (cascade will handle this, but being explicit)
        $db->delete('purchase_orders_detail', ['po_header_id' => $po_id]);
        
        // Delete header
        $result = $db->delete('purchase_orders_header', ['id' => $po_id]);
        
        if (!$result) {
            throw new Exception("Failed to delete Purchase Order");
        }
        
        $db->getConnection()->commit();
        
        return [
            'success' => true,
            'message' => "Purchase Order {$po['po_number']} deleted successfully"
        ];
        
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }
        
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Store a finalized Purchase Invoice (Real Purchase Transaction)
 * This function records the actual purchase and automatically posts to General Ledger
 * 
 * @param string $invoice_number Invoice number from supplier
 * @param int $supplier_id Supplier ID
 * @param string $invoice_date Invoice date (Y-m-d format)
 * @param array $items Array of items with keys: item_name, quantity, unit_price, division_id
 * @param array $options Optional parameters: po_id, due_date, received_date, notes, discount_amount, tax_amount, attachment_path
 * @return array ['success' => bool, 'purchase_id' => int, 'invoice_number' => string, 'gl_entries' => array, 'message' => string]
 */
function storePurchase($invoice_number, $supplier_id, $invoice_date, $items, $options = [])
{
    $db = Database::getInstance();

    try {
        // Validate inputs
        if (empty($invoice_number)) {
            throw new Exception("Invoice number is required");
        }

        if (empty($supplier_id) || !is_numeric($supplier_id)) {
            throw new Exception("Invalid supplier ID");
        }

        if (empty($invoice_date)) {
            throw new Exception("Invoice date is required");
        }

        if (empty($items) || !is_array($items)) {
            throw new Exception("Items array is required and must not be empty");
        }

        // Check if invoice number already exists
        $existing = $db->fetchOne("SELECT id FROM purchases_header WHERE invoice_number = ?", [$invoice_number]);
        if ($existing) {
            throw new Exception("Invoice number {$invoice_number} already exists");
        }

        // Verify supplier exists
        $supplier = $db->fetchOne("SELECT id, supplier_name FROM suppliers WHERE id = ? AND is_active = 1", [$supplier_id]);
        if (!$supplier) {
            throw new Exception("Supplier not found or inactive");
        }

        // Begin transaction
        $db->getConnection()->beginTransaction();

        // Calculate totals and validate items
        $total_amount = 0;
        $line_number = 1;
        $validated_items = [];
        $division_totals = []; // Track expense per division

        foreach ($items as $item) {
            // Validate each item
            if (empty($item['item_name'])) {
                throw new Exception("Item name is required for line {$line_number}");
            }

            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                throw new Exception("Valid quantity is required for line {$line_number}");
            }

            if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || $item['unit_price'] < 0) {
                throw new Exception("Valid unit price is required for line {$line_number}");
            }

            if (empty($item['division_id']) || !is_numeric($item['division_id'])) {
                throw new Exception("Division ID is required for line {$line_number}");
            }

            // Verify division exists
            $division = $db->fetchOne("SELECT id, division_name FROM divisions WHERE id = ?", [$item['division_id']]);
            if (!$division) {
                throw new Exception("Division not found for line {$line_number}");
            }

            // Calculate subtotal
            $subtotal = $item['quantity'] * $item['unit_price'];
            $total_amount += $subtotal;

            // Track division totals for GL posting
            if (!isset($division_totals[$item['division_id']])) {
                $division_totals[$item['division_id']] = [
                    'division_name' => $division['division_name'],
                    'amount' => 0
                ];
            }
            $division_totals[$item['division_id']]['amount'] += $subtotal;

            // Store validated item
            $validated_items[] = [
                'line_number' => $line_number,
                'item_name' => trim($item['item_name']),
                'item_description' => isset($item['item_description']) ? trim($item['item_description']) : null,
                'unit_of_measure' => isset($item['unit_of_measure']) ? trim($item['unit_of_measure']) : 'pcs',
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $subtotal,
                'division_id' => $item['division_id'],
                'po_detail_id' => isset($item['po_detail_id']) ? $item['po_detail_id'] : null,
                'notes' => isset($item['notes']) ? trim($item['notes']) : null
            ];

            $line_number++;
        }

        // Get current user ID
        $auth = new Auth();
        $currentUser = $auth->getCurrentUser();
        $created_by = $currentUser['user_id'];

        // Prepare header data
        $discount_amount = isset($options['discount_amount']) ? $options['discount_amount'] : 0;
        $tax_amount = isset($options['tax_amount']) ? $options['tax_amount'] : 0;
        $grand_total = $total_amount - $discount_amount + $tax_amount;
        $received_date = isset($options['received_date']) ? $options['received_date'] : $invoice_date;

        $header_data = [
            'invoice_number' => trim($invoice_number),
            'po_id' => isset($options['po_id']) ? $options['po_id'] : null,
            'supplier_id' => $supplier_id,
            'invoice_date' => $invoice_date,
            'due_date' => isset($options['due_date']) ? $options['due_date'] : null,
            'received_date' => $received_date,
            'total_amount' => $total_amount,
            'discount_amount' => $discount_amount,
            'tax_amount' => $tax_amount,
            'grand_total' => $grand_total,
            'payment_status' => 'unpaid',
            'paid_amount' => 0,
            'gl_posted' => 0,
            'notes' => isset($options['notes']) ? $options['notes'] : null,
            'attachment_path' => isset($options['attachment_path']) ? $options['attachment_path'] : null,
            'created_by' => $created_by
        ];

        // Insert header
        $purchase_header_id = $db->insert('purchases_header', $header_data);

        if (!$purchase_header_id) {
            throw new Exception("Failed to create Purchase Invoice header");
        }

        // Insert details
        foreach ($validated_items as $item) {
            $item['purchase_header_id'] = $purchase_header_id;

            $detail_id = $db->insert('purchases_detail', $item);

            if (!$detail_id) {
                throw new Exception("Failed to insert item: {$item['item_name']}");
            }
        }

        // Auto-Post to General Ledger
        $gl_entries = [];
        $fiscal_year = date('Y', strtotime($invoice_date));
        $fiscal_period = date('m', strtotime($invoice_date));

        // Entry 1: DEBIT - Expense Account (per division)
        foreach ($division_totals as $division_id => $division_data) {
            $debit_entry = [
                'gl_date' => $invoice_date,
                'account_code' => '5101', // Office Supplies / Operating Expense (can be parameterized)
                'account_name' => 'Purchase Expense - ' . $division_data['division_name'],
                'description' => "Purchase Invoice {$invoice_number} from {$supplier['supplier_name']} - {$division_data['division_name']}",
                'debit' => $division_data['amount'],
                'credit' => 0,
                'transaction_type' => 'purchase',
                'transaction_ref_id' => $purchase_header_id,
                'transaction_ref_number' => $invoice_number,
                'division_id' => $division_id,
                'fiscal_year' => $fiscal_year,
                'fiscal_period' => $fiscal_period,
                'posted_by' => $created_by,
                'notes' => "Auto-posted from Purchase Invoice"
            ];

            $gl_id = $db->insert('general_ledger', $debit_entry);
            if (!$gl_id) {
                throw new Exception("Failed to post GL entry (Debit)");
            }

            $gl_entries[] = [
                'gl_id' => $gl_id,
                'type' => 'debit',
                'account' => '5101',
                'amount' => $division_data['amount'],
                'division_id' => $division_id
            ];
        }

        // Entry 2: CREDIT - Cash/Bank Account (Accounts Payable)
        $credit_entry = [
            'gl_date' => $invoice_date,
            'account_code' => '2101', // Accounts Payable
            'account_name' => 'Accounts Payable',
            'description' => "Purchase Invoice {$invoice_number} from {$supplier['supplier_name']}",
            'debit' => 0,
            'credit' => $grand_total,
            'transaction_type' => 'purchase',
            'transaction_ref_id' => $purchase_header_id,
            'transaction_ref_number' => $invoice_number,
            'division_id' => null, // Not division-specific
            'fiscal_year' => $fiscal_year,
            'fiscal_period' => $fiscal_period,
            'posted_by' => $created_by,
            'notes' => "Auto-posted from Purchase Invoice"
        ];

        $gl_id = $db->insert('general_ledger', $credit_entry);
        if (!$gl_id) {
            throw new Exception("Failed to post GL entry (Credit)");
        }

        $gl_entries[] = [
            'gl_id' => $gl_id,
            'type' => 'credit',
            'account' => '2101',
            'amount' => $grand_total,
            'division_id' => null
        ];

        // Update purchase header to mark as GL posted
        $db->update('purchases_header', [
            'gl_posted' => 1,
            'gl_posted_at' => date('Y-m-d H:i:s')
        ], ['id' => $purchase_header_id]);

        // If linked to PO, update PO received quantities
        if (isset($options['po_id']) && $options['po_id']) {
            foreach ($validated_items as $item) {
                if ($item['po_detail_id']) {
                    // Update received quantity in PO detail
                    $db->getConnection()->exec("
                        UPDATE purchase_orders_detail 
                        SET received_quantity = received_quantity + {$item['quantity']}
                        WHERE id = {$item['po_detail_id']}
                    ");
                }
            }

            // Check if all items in PO are fully received
            $po_status = $db->fetchOne("
                SELECT 
                    CASE 
                        WHEN SUM(received_quantity) >= SUM(quantity) THEN 'completed'
                        WHEN SUM(received_quantity) > 0 THEN 'partially_received'
                        ELSE 'approved'
                    END as new_status
                FROM purchase_orders_detail
                WHERE po_header_id = ?
            ", [$options['po_id']]);

            if ($po_status) {
                $db->update('purchase_orders_header', [
                    'status' => $po_status['new_status']
                ], 'id = :id', ['id' => $options['po_id']]);
            }
        }

        // Commit transaction
        $db->getConnection()->commit();

        return [
            'success' => true,
            'purchase_id' => $purchase_header_id,
            'invoice_number' => $invoice_number,
            'total_amount' => $total_amount,
            'grand_total' => $grand_total,
            'items_count' => count($validated_items),
            'gl_entries' => $gl_entries,
            'gl_posted' => true,
            'message' => "Purchase Invoice {$invoice_number} saved and posted to GL successfully"
        ];
    } catch (Exception $e) {
        // Rollback on error
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get Purchase Invoice by ID
 * 
 * @param int $purchase_id Purchase Invoice ID
 * @return array|null Purchase data with details and GL entries
 */
function getPurchase($purchase_id)
{
    $db = Database::getInstance();

    // Get header
    $header = $db->fetchOne("
        SELECT 
            ph.*,
            s.supplier_name,
            s.supplier_code,
            u.full_name as created_by_name,
            poh.po_number
        FROM purchases_header ph
        LEFT JOIN suppliers s ON ph.supplier_id = s.id
        LEFT JOIN users u ON ph.created_by = u.user_id
        LEFT JOIN purchase_orders_header poh ON ph.po_id = poh.id
        WHERE ph.id = ?
    ", [$purchase_id]);
    // Get details
    $details = $db->fetchAll("
                $hargaExpr = gudangNasitaStockHasColumn('harga_beli') ? 'COALESCE(gs.harga_beli, 0)' : '0';
                $totalHargaExpr = gudangNasitaStockHasColumn('total_harga')
                    ? 'COALESCE(gs.total_harga, COALESCE(gs.quantity, 0) * COALESCE(gs.harga_beli, 0), 0)'
                    : '(COALESCE(gs.quantity, 0) * ' . $hargaExpr . ')';
        SELECT 
            pd.*,
            d.division_name,
            d.division_code
        FROM purchases_detail pd
        LEFT JOIN divisions d ON pd.division_id = d.id
        WHERE pd.purchase_header_id = ?
        ORDER BY pd.line_number
    ", [$purchase_id]);

    $header['items'] = $details;

    // Get GL entries if posted
    if ($header['gl_posted']) {
        $gl_entries = $db->fetchAll("
            SELECT *
            FROM general_ledger
            WHERE transaction_type = 'purchase' 
                AND transaction_ref_id = ?
                AND reversed = 0
            ORDER BY id
        ", [$purchase_id]);

        $header['gl_entries'] = $gl_entries;
    }

    return $header;
}

/**
 * Get all Purchase Invoices with filters
 * 
 * @param array $filters Optional filters: payment_status, supplier_id, date_from, date_to, gl_posted
 * @param int $limit Limit results (default 100)
 * @param int $offset Offset for pagination (default 0)
 * @return array Purchase Invoices list
 */
function getPurchases($filters = [], $limit = 100, $offset = 0)
{
    $db = Database::getInstance();

    $where_conditions = [];
    $params = [];

    if (isset($filters['payment_status']) && !empty($filters['payment_status'])) {
        $where_conditions[] = "ph.payment_status = :payment_status";
        $params['payment_status'] = $filters['payment_status'];
    }

    if (isset($filters['supplier_id']) && !empty($filters['supplier_id'])) {
        $where_conditions[] = "ph.supplier_id = :supplier_id";
        $params['supplier_id'] = $filters['supplier_id'];
    }

    if (isset($filters['date_from']) && !empty($filters['date_from'])) {
        $where_conditions[] = "ph.invoice_date >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }

    if (isset($filters['date_to']) && !empty($filters['date_to'])) {
        $where_conditions[] = "ph.invoice_date <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    if (isset($filters['gl_posted'])) {
        $where_conditions[] = "ph.gl_posted = :gl_posted";
        $params['gl_posted'] = $filters['gl_posted'];
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $query = "
        SELECT 
            ph.*,
            s.supplier_name,
            s.supplier_code,
            u.full_name as created_by_name,
            poh.po_number,
            COUNT(pd.id) as items_count
        FROM purchases_header ph
        LEFT JOIN suppliers s ON ph.supplier_id = s.id
        LEFT JOIN users u ON ph.created_by = u.user_id
        LEFT JOIN purchase_orders_header poh ON ph.po_id = poh.id
        LEFT JOIN purchases_detail pd ON ph.id = pd.purchase_header_id
        {$where_clause}
        GROUP BY ph.id
        ORDER BY ph.invoice_date DESC, ph.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    return $db->fetchAll($query, $params);
}
