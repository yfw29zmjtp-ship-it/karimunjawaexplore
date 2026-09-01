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
function updatePurchaseOrderItems($po_id, $items, $options = [])
{
    $db = Database::getInstance();

    try {
        if (empty($po_id) || !is_numeric($po_id)) {
            throw new Exception('PO ID tidak valid');
        }

        if (empty($items) || !is_array($items)) {
            throw new Exception('Item PO tidak boleh kosong');
        }

        $po = $db->fetchOne("SELECT id, po_number, status, total_amount, notes FROM purchase_orders_header WHERE id = ? LIMIT 1", [(int)$po_id]);
        if (!$po) {
            throw new Exception('Purchase Order tidak ditemukan');
        }

        if (!in_array((string)$po['status'], ['draft', 'submitted'], true)) {
            throw new Exception('PO ini sudah diproses dan tidak bisa diedit.');
        }

        $db->getConnection()->beginTransaction();

        $existingItems = $db->fetchAll('SELECT id, quantity, received_quantity FROM purchase_orders_detail WHERE po_header_id = ? ORDER BY id', [(int)$po_id]);
        $existingReceivedMap = [];
        foreach ($existingItems as $row) {
            $existingReceivedMap[(int)($row['id'] ?? 0)] = (float)($row['received_quantity'] ?? 0);
        }

        $totalAmount = 0.0;
        $processedItems = [];
        $lineNumber = 1;

        foreach ($items as $item) {
            $itemName = trim((string)($item['item_name'] ?? ''));
            $qty = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
            $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0;
            $unit = trim((string)($item['unit_of_measure'] ?? ($item['unit'] ?? 'pcs')));
            $notes = trim((string)($item['notes'] ?? ''));
            $description = trim((string)($item['item_description'] ?? ''));
            $divisionId = isset($item['division_id']) && $item['division_id'] !== '' ? (int)$item['division_id'] : null;

            if ($itemName === '') {
                throw new Exception('Nama item tidak boleh kosong');
            }
            if ($qty <= 0) {
                throw new Exception('Qty item "' . $itemName . '" harus lebih dari 0');
            }
            if ($unitPrice < 0) {
                throw new Exception('Harga item "' . $itemName . '" tidak valid');
            }

            $subtotal = $qty * $unitPrice;
            $totalAmount += $subtotal;

            $processedItems[] = [
                'line_number' => $lineNumber,
                'item_name' => $itemName,
                'item_description' => $description,
                'unit_of_measure' => $unit !== '' ? $unit : 'pcs',
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'notes' => $notes,
                'division_id' => $divisionId,
            ];

            $lineNumber++;
        }

        $db->delete('purchase_orders_detail', ['po_header_id' => (int)$po_id]);

        $detailCols = $db->fetchAll('SHOW COLUMNS FROM purchase_orders_detail');
        $detailFieldNames = array_map(function ($row) {
            return strtolower((string)($row['Field'] ?? ''));
        }, $detailCols);

        foreach ($processedItems as $item) {
            $detailData = [
                'po_header_id' => (int)$po_id,
                'item_name' => $item['item_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $item['subtotal'],
                'notes' => $item['notes'] !== '' ? $item['notes'] : null,
            ];

            if (in_array('line_number', $detailFieldNames, true)) {
                $detailData['line_number'] = $item['line_number'];
            }
            if (in_array('item_description', $detailFieldNames, true)) {
                $detailData['item_description'] = $item['item_description'];
            }
            if (in_array('unit_of_measure', $detailFieldNames, true)) {
                $detailData['unit_of_measure'] = $item['unit_of_measure'];
            }
            if (in_array('unit', $detailFieldNames, true) && !isset($detailData['unit_of_measure'])) {
                $detailData['unit'] = $item['unit_of_measure'];
            }
            if (in_array('division_id', $detailFieldNames, true) && $item['division_id'] !== null) {
                $detailData['division_id'] = $item['division_id'];
            }
            if (in_array('received_quantity', $detailFieldNames, true)) {
                $detailData['received_quantity'] = 0;
            }

            $db->insert('purchase_orders_detail', $detailData);
        }

        $discountAmount = isset($options['discount_amount']) ? (float)$options['discount_amount'] : 0.0;
        $taxAmount = isset($options['tax_amount']) ? (float)$options['tax_amount'] : 0.0;
        $grandTotal = $totalAmount - $discountAmount + $taxAmount;

        $headerCols = $db->fetchAll('SHOW COLUMNS FROM purchase_orders_header');
        $headerFieldNames = array_map(function ($row) {
            return strtolower((string)($row['Field'] ?? ''));
        }, $headerCols);

        $headerData = [
            'total_amount' => $totalAmount,
            'notes' => isset($options['notes']) ? trim((string)$options['notes']) : (string)($po['notes'] ?? ''),
        ];
        if (in_array('discount_amount', $headerFieldNames, true)) {
            $headerData['discount_amount'] = $discountAmount;
        }
        if (in_array('tax_amount', $headerFieldNames, true)) {
            $headerData['tax_amount'] = $taxAmount;
        }
        if (in_array('grand_total', $headerFieldNames, true)) {
            $headerData['grand_total'] = $grandTotal;
        }
        if (in_array('expected_delivery_date', $headerFieldNames, true) && isset($options['expected_delivery_date'])) {
            $headerData['expected_delivery_date'] = $options['expected_delivery_date'];
        }
        if (in_array('po_date', $headerFieldNames, true) && !empty($options['po_date']) && strtotime((string)$options['po_date'])) {
            $headerData['po_date'] = date('Y-m-d', strtotime((string)$options['po_date']));
        }
        if (in_array('updated_at', $headerFieldNames, true)) {
            $headerData['updated_at'] = date('Y-m-d H:i:s');
        }

        $db->update('purchase_orders_header', $headerData, 'id = :id', ['id' => (int)$po_id]);

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => 'PO berhasil diubah.',
            'po_number' => (string)($po['po_number'] ?? ''),
            'po_id' => (int)$po_id,
        ];
    } catch (Throwable $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}

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

// Self-healing backfill: some historical gudang_nasita_transfer_items rows were saved with
// unit_price/subtotal = 0 because the old transfer code did a plain "SELECT *" on
// gudang_nasita_stock, missing the fallback to the master barang price (fixed in
// transferGudangNasitaStock()). This re-resolves the price for any zero-priced item using the
// item's CURRENT stock/barang cost basis (best effort — historical price at time of transfer
// is not stored) so existing bills/history stop showing Rp 0 for items that clearly have a
// real cost. Safe to call repeatedly; only touches rows still at 0.
function gudangNasitaBackfillZeroPriceTransferItems($db = null): void
{
    $db = $db ?: Database::getInstance();
    try {
        $hasBarangIdCol = false;
        $hasHargaBeliCol = false;
        $cols = $db->fetchAll("SHOW COLUMNS FROM gudang_nasita_stock");
        foreach ($cols as $c) {
            $fieldName = strtolower((string)($c['Field'] ?? ''));
            if ($fieldName === 'barang_id') {
                $hasBarangIdCol = true;
            }
            if ($fieldName === 'harga_beli') {
                $hasHargaBeliCol = true;
            }
        }
        $hargaExpr = $hasHargaBeliCol
            ? ($hasBarangIdCol ? 'COALESCE(NULLIF(gs.harga_beli, 0), gb.harga_beli, 0)' : 'COALESCE(gs.harga_beli, 0)')
            : ($hasBarangIdCol ? 'COALESCE(gb.harga_beli, 0)' : '0');
        $barangJoin = $hasBarangIdCol ? 'LEFT JOIN gudang_nasita_barang gb ON gb.id = gs.barang_id' : '';

        $zeroItems = $db->fetchAll(
            "SELECT gti.id, gti.stock_id, gti.quantity
             FROM gudang_nasita_transfer_items gti
             WHERE COALESCE(gti.unit_price, 0) <= 0 AND gti.stock_id IS NOT NULL AND gti.stock_id > 0"
        ) ?: [];

        foreach ($zeroItems as $row) {
            $stock = $db->fetchOne(
                "SELECT {$hargaExpr} AS resolved_price
                 FROM gudang_nasita_stock gs
                 {$barangJoin}
                 WHERE gs.id = ? LIMIT 1",
                [(int)$row['stock_id']]
            );
            $resolvedPrice = (float)($stock['resolved_price'] ?? 0);
            if ($resolvedPrice > 0) {
                $qty = (float)$row['quantity'];
                $db->query(
                    "UPDATE gudang_nasita_transfer_items SET unit_price = ?, subtotal = ? WHERE id = ?",
                    [$resolvedPrice, $qty * $resolvedPrice, (int)$row['id']]
                );
            }
        }
    } catch (Throwable $e) {
        error_log('gudangNasitaBackfillZeroPriceTransferItems: ' . $e->getMessage());
    }
}

// Net bill adjustment for direct business-to-business stock transfers (table
// business_inter_stock_transfers, master DB — see modules/procurement/business-stock-incoming.php's
// "Transfer Stock Antar Bisnis" feature). When Business A hands stock (originally received from
// Gudang Nasita) directly to Business B, Gudang's billing must "follow" the goods: A's bill goes
// DOWN by that value, B's bill goes UP by the same value. Returning stock to Gudang itself only
// reduces the source business' bill (no one else gets billed for stock that came back to Gudang).
// Returns [slug => netAdjustment] for the given tracked slugs, optionally restricted to a date range.
function getBusinessInterStockTransferBillAdjustments(array $trackedSlugs, $fromDateTime = null, $toDateTime = null): array
{
    $adjustments = array_fill_keys($trackedSlugs, 0.0);
    try {
        $masterPdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . MASTER_DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $hasTable = $masterPdo->query("SHOW TABLES LIKE 'business_inter_stock_transfers'")->fetch();
        if (!$hasTable) {
            return $adjustments;
        }

        $cols = $masterPdo->query("SHOW COLUMNS FROM business_inter_stock_transfers")->fetchAll();
        $colNames = array_column($cols, 'Field');
        $hasPriceCols = in_array('unit_price', $colNames, true) || in_array('subtotal', $colNames, true);
        if (!$hasPriceCols) {
            // Legacy rows have no price info at all — nothing reliable to adjust with.
            return $adjustments;
        }

        $nilaiExpr = in_array('subtotal', $colNames, true)
            ? 'COALESCE(subtotal, quantity * COALESCE(unit_price, 0))'
            : 'quantity * COALESCE(unit_price, 0)';

        $sql = "SELECT source_business_slug, target_business_slug, {$nilaiExpr} AS nilai
                FROM business_inter_stock_transfers";
        $params = [];
        if ($fromDateTime !== null && $toDateTime !== null) {
            $sql .= " WHERE created_at BETWEEN ? AND ?";
            $params = [$fromDateTime, $toDateTime];
        }
        $stmt = $masterPdo->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $row) {
            $nilai = (float)($row['nilai'] ?? 0);
            if ($nilai <= 0) {
                continue;
            }
            $sourceSlug = strtolower(trim((string)($row['source_business_slug'] ?? '')));
            $targetSlug = strtolower(trim((string)($row['target_business_slug'] ?? '')));

            if (isset($adjustments[$sourceSlug])) {
                $adjustments[$sourceSlug] -= $nilai;
            }
            if ($targetSlug !== 'gudang-nasita' && isset($adjustments[$targetSlug])) {
                $adjustments[$targetSlug] += $nilai;
            }
        }
    } catch (Throwable $e) {
        error_log('getBusinessInterStockTransferBillAdjustments: ' . $e->getMessage());
    }
    return $adjustments;
}

function getGudangNasitaTransfers($limit = 50)
{
    $db = Database::getInstance();

    try {
        ensureGudangNasitaOperationalTablesCompatibility();
    } catch (Throwable $e) {
        error_log('getGudangNasitaTransfers schema bootstrap skipped: ' . $e->getMessage());
    }
    try {
        gudangNasitaBackfillZeroPriceTransferItems($db);
    } catch (Throwable $e) {
        error_log('getGudangNasitaTransfers price backfill skipped: ' . $e->getMessage());
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
        // Deterministic match: if duplicate rows exist with the same name in different
        // case/whitespace, always prefer the EXACT (binary) match, then the active row
        // with the highest quantity — otherwise a bare LIMIT 1 with no ORDER BY can land
        // on an unrelated/hidden duplicate, silently updating a row the user never sees.
        $stock = $db->fetchOne(
            "SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?)
             ORDER BY (BINARY item_name = ?) DESC, COALESCE(is_active,1) DESC, quantity DESC, id ASC
             LIMIT 1",
            [$itemName, $itemName]
        );
        // Reactivate soft-deleted row so we update instead of insert
        if ($stock && !(int)($stock['is_active'] ?? 1)) {
            $db->update('gudang_nasita_stock', ['is_active' => 1], 'id = :id', ['id' => $stock['id']]);
        }

        // Self-heal: barang_id is UNIQUE on gudang_nasita_stock, but historical bugs left
        // some rows linked to the WRONG catalog product (e.g. "Bir Bintang" row pointing
        // at "Bir Bintang Large"'s barang_id). If $barangId is already claimed by a row
        // with a DIFFERENT item_name, re-resolve that row's OWN correct barang_id and free
        // this one up, instead of letting the INSERT below fail on the unique constraint.
        if (!$stock && $barangId !== null) {
            $conflictRow = $db->fetchOne(
                'SELECT id, item_name FROM gudang_nasita_stock WHERE barang_id = ? LIMIT 1',
                [$barangId]
            );
            if ($conflictRow && strcasecmp((string)$conflictRow['item_name'], $itemName) !== 0) {
                $correctIdForConflictRow = ensureGudangNasitaBarangId($conflictRow['item_name'], $unit, $category, $notes);
                if ($correctIdForConflictRow !== null && (int)$correctIdForConflictRow !== (int)$barangId) {
                    // Conflicting row has its OWN distinct catalog identity — just relink it there.
                    $db->update('gudang_nasita_stock', ['barang_id' => $correctIdForConflictRow], 'id = :id', ['id' => $conflictRow['id']]);
                } else {
                    // Conflicting row has NO catalog identity of its own (e.g. old informal
                    // name like "Prost big" that was never properly cataloged) — it's just
                    // a legacy alias for the SAME catalog product as $itemName. Rename it to
                    // the current/correct name and reuse it as the target stock row, instead
                    // of creating a duplicate.
                    $db->update('gudang_nasita_stock', ['item_name' => $itemName], 'id = :id', ['id' => $conflictRow['id']]);
                    $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ? LIMIT 1', [$conflictRow['id']]);
                    if ($stock && !(int)($stock['is_active'] ?? 1)) {
                        $db->update('gudang_nasita_stock', ['is_active' => 1], 'id = :id', ['id' => $stock['id']]);
                    }
                }
            }
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
            if (gudangNasitaStockRequiresBarangId() && $barangId !== null) {
                // $barangId already resolved (and conflict-checked) above; use it directly.
                $insertData['barang_id'] = $barangId;
            }
            if (gudangNasitaStockHasColumn('jumlah_stok')) {
                $insertData['jumlah_stok'] = 0;
            }

            error_log('[GUDANG] Creating NEW item: ' . json_encode(['item' => $itemName, 'category' => $category]));
            $stockId = $db->insert('gudang_nasita_stock', $insertData);
            $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ? LIMIT 1', [$stockId]);
        } else {
            error_log('[GUDANG] REUSING EXISTING item: ' . json_encode(['item' => $itemName, 'old_cat' => $stock['category'], 'new_cat' => $category]));
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
        // Only update columns that actually exist to prevent silent rollback from unknown-column errors
        $existingCols = gudangNasitaStockColumns();
        $updateData = array_intersect_key($updateData, array_flip($existingCols));

        $updateResult = $db->update('gudang_nasita_stock', $updateData, 'id = :id', ['id' => $stock['id']]);
        if ($updateResult === false) {
            throw new Exception('Gagal update stok (cek error_log server untuk detail SQL).');
        }

        $referenceNumber = 'MAN-' . date('YmdHis');
        // unit_price and subtotal are NOT in the gudang_nasita_movements schema; omit to prevent rollback
        $db->insert('gudang_nasita_movements', [
            'stock_id' => $stock['id'],
            'movement_date' => date('Y-m-d'),
            'movement_type' => 'adjustment',
            'quantity' => $quantity,
            'reference_type' => 'manual_stock',
            'reference_id' => null,
            'reference_number' => $referenceNumber,
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
    $originDbName = Database::getCurrentDatabase();
    $targetDbName = DB_NAME;

    $gudangCfgPath = __DIR__ . '/../config/businesses/gudang-nasita.php';
    if (file_exists($gudangCfgPath)) {
        try {
            $gudangCfg = require $gudangCfgPath;
            $targetDbName = trim((string)($gudangCfg['database'] ?? '')) !== ''
                ? trim((string)($gudangCfg['database']))
                : $targetDbName;
        } catch (Throwable $e) {
            error_log('recordGudangNasitaDailyStockOut config load failed: ' . $e->getMessage());
        }
    }

    if ($originDbName !== $targetDbName) {
        Database::switchDatabase($targetDbName);
        $db = Database::getInstance();
    }

    try {
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

            // Deterministic match: prefer exact (binary) name match, then the active row
            // with the highest quantity — a bare LIMIT 1 with no ORDER BY can silently
            // land on a hidden duplicate row instead of the one the user sees.
            $stock = $db->fetchOne(
                "SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?) AND COALESCE(is_active,1) = 1
                 ORDER BY (BINARY item_name = ?) DESC, quantity DESC, id ASC
                 LIMIT 1",
                [$itemName, $itemName]
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
            // Only update columns that actually exist — an unknown-column PDOException
            // was previously being swallowed by Database::update()'s internal try/catch
            // (it just returns false), so the caller never noticed the update never ran.
            $existingCols = gudangNasitaStockColumns();
            $updateData = array_intersect_key($updateData, array_flip($existingCols));

            $updateResult = $db->update('gudang_nasita_stock', $updateData, 'id = :id', ['id' => $stock['id']]);
            if ($updateResult === false) {
                throw new Exception('Gagal update stok (cek error_log server untuk detail SQL).');
            }

            $movementType = trim((string)($options['movement_type'] ?? 'out_transfer'));
            if (!in_array($movementType, ['out_transfer', 'adjustment'], true)) {
                $movementType = 'out_transfer';
            }

            $referenceNumber = 'OUT-' . date('YmdHis');
            $db->insert('gudang_nasita_movements', [
                'stock_id' => $stock['id'],
                'movement_date' => date('Y-m-d'),
                'movement_type' => $movementType,
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
        } catch (Throwable $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->getConnection()->rollBack();
            }
            error_log('recordGudangNasitaDailyStockOut failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    } finally {
        if ($originDbName !== '' && $originDbName !== $targetDbName) {
            Database::switchDatabase($originDbName);
        }
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
            // Match by name only so existing stock is updated regardless of unit mismatch.
            // Do NOT filter by is_active here — a previously soft-deleted row must still be
            // found so we can reactivate it instead of INSERTing a duplicate that collides
            // on the UNIQUE barang_id constraint (see addGudangNasitaManualStock for the
            // same self-heal pattern).
            if (gudangNasitaStockRequiresBarangId()) {
                $stock = $db->fetchOne(
                    "SELECT gs.*, gb.nama_barang AS master_item_name
                     FROM gudang_nasita_stock gs
                     LEFT JOIN gudang_nasita_barang gb ON gb.id = gs.barang_id
                     WHERE LOWER(COALESCE(gs.item_name, gb.nama_barang, '')) = LOWER(?)
                     LIMIT 1",
                    [$item['item_name']]
                );
            } else {
                $stock = $db->fetchOne("SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?) LIMIT 1", [$item['item_name']]);
            }
            if (!$stock) {
                $stock = $db->fetchOne("SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) LIKE LOWER(?) ORDER BY COALESCE(quantity, jumlah_stok, 0) DESC LIMIT 1", ['%' . trim($item['item_name']) . '%']);
            }

            if ($stock && !(int)($stock['is_active'] ?? 1)) {
                $db->update('gudang_nasita_stock', ['is_active' => 1], 'id = :id', ['id' => $stock['id']]);
                $stock['is_active'] = 1;
            }

            if (!$stock) {
                $barangId = gudangNasitaStockRequiresBarangId()
                    ? ensureGudangNasitaBarangId(trim($item['item_name']), $unit, 'lainnya', $notes ?: ('Auto created from PO ' . $po['po_number']))
                    : null;

                // Self-heal: barang_id is UNIQUE on gudang_nasita_stock. If it's already
                // claimed by another row (e.g. a legacy/renamed alias), relink that row to
                // its own correct barang_id instead of letting this INSERT fail outright.
                if ($barangId !== null) {
                    $conflictRow = $db->fetchOne('SELECT id, item_name FROM gudang_nasita_stock WHERE barang_id = ? LIMIT 1', [$barangId]);
                    if ($conflictRow && strcasecmp((string)$conflictRow['item_name'], trim($item['item_name'])) !== 0) {
                        $correctIdForConflictRow = ensureGudangNasitaBarangId($conflictRow['item_name'], $unit, 'lainnya', $notes);
                        if ($correctIdForConflictRow !== null && (int)$correctIdForConflictRow !== (int)$barangId) {
                            $db->update('gudang_nasita_stock', ['barang_id' => $correctIdForConflictRow], 'id = :id', ['id' => $conflictRow['id']]);
                        } else {
                            $db->update('gudang_nasita_stock', ['item_name' => trim($item['item_name'])], 'id = :id', ['id' => $conflictRow['id']]);
                            $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ? LIMIT 1', [$conflictRow['id']]);
                            if ($stock && !(int)($stock['is_active'] ?? 1)) {
                                $db->update('gudang_nasita_stock', ['is_active' => 1], 'id = :id', ['id' => $stock['id']]);
                            }
                        }
                    }
                }
            }

            if (!$stock) {
                if (gudangNasitaStockRequiresBarangId() && $barangId === null) {
                    // barang_id is NOT NULL on gudang_nasita_stock — item must exist in the
                    // Database Produk master catalog before it can be received into a NEW stock row.
                    throw new Exception('Item "' . trim($item['item_name']) . '" belum terdaftar di Database Produk Gudang Nasita. Tambahkan dulu di menu Database Produk, baru terima barang ini.');
                }

                $insertData = [
                    'item_name' => trim($item['item_name']),
                    'category' => 'lainnya',
                    'unit' => $unit,
                    'quantity' => 0,
                    'supplier_name' => $po['supplier_name'] ?? null,
                    'notes' => $notes ?: ('Auto created from PO ' . $po['po_number'])
                ];

                if (gudangNasitaStockHasColumn('stock_code')) {
                    $insertData['stock_code'] = generateGudangNasitaStockCode();
                }
                if (gudangNasitaStockRequiresBarangId()) {
                    $insertData['barang_id'] = $barangId;
                }
                if (gudangNasitaStockHasColumn('jumlah_stok')) {
                    $insertData['jumlah_stok'] = 0;
                }
                if (gudangNasitaStockHasColumn('expiry_date') && !empty($item['expiry_date'])) {
                    $insertData['expiry_date'] = $item['expiry_date'];
                }
                // Only insert columns that actually exist (e.g. harga_beli/total_harga live on
                // gudang_nasita_barang, not on this table, in some deployments).
                $insertData = array_intersect_key($insertData, array_flip(gudangNasitaStockColumns()));

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
            if (gudangNasitaStockHasColumn('expiry_date') && !empty($item['expiry_date'])) {
                $stockUpdate['expiry_date'] = $item['expiry_date'];
            }
            // Only update columns that actually exist to prevent silent rollback from unknown-column errors.
            $stockUpdate = array_intersect_key($stockUpdate, array_flip(gudangNasitaStockColumns()));
            $updateResult = $db->update('gudang_nasita_stock', $stockUpdate, 'id = :id', ['id' => $stock['id']]);
            if ($updateResult === false) {
                throw new Exception('Gagal update stok gudang untuk ' . $item['item_name'] . ' (cek error_log server untuk detail SQL).');
            }

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
    } catch (Throwable $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        error_log('[GUDANG] receivePurchaseOrderToGudang FAILED: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

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

    // Only lookup — do NOT auto-create. Products must be added via Database Produk page.
    // This prevents deleted items from reappearing after a PO or stock transfer.
    $existing = $db->fetchOne('SELECT id FROM gudang_nasita_barang WHERE LOWER(nama_barang) = LOWER(?) LIMIT 1', [$itemName]);
    return $existing ? (int)$existing['id'] : null;
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
        'expiry_date' => "DATE NULL",
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

            // Resolve stock with the SAME price fallback used by the stock listing (getGudangNasitaStock):
            // prefer stock-level harga_beli, fall back to the master barang price when stock price is 0/missing.
            // A plain "SELECT *" here was the root cause of Rp 0 subtotals on transfers for items whose
            // stock-level harga_beli was never set, even though a valid barang-level price existed.
            $hasBarangIdCol = gudangNasitaStockHasColumn('barang_id');
            $hasHargaBeliCol = gudangNasitaStockHasColumn('harga_beli');
            $hargaFallbackExpr = $hasHargaBeliCol
                ? ($hasBarangIdCol ? 'COALESCE(NULLIF(gs.harga_beli, 0), gb.harga_beli, 0)' : 'COALESCE(gs.harga_beli, 0)')
                : ($hasBarangIdCol ? 'COALESCE(gb.harga_beli, 0)' : '0');
            $barangJoinSql = $hasBarangIdCol ? 'LEFT JOIN gudang_nasita_barang gb ON gb.id = gs.barang_id' : '';
            $stock = $db->fetchOne(
                "SELECT gs.*, {$hargaFallbackExpr} AS harga_beli
                 FROM gudang_nasita_stock gs
                 {$barangJoinSql}
                 WHERE gs.id = ? LIMIT 1",
                [$stockId]
            );
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
            $stockUpdateData = [
                'quantity'    => $remaining,
                'harga_beli'  => $remaining > 0 ? ($remainingValue / $remaining) : $unitPrice,
                'total_harga' => $remainingValue
            ];
            // Only update columns that exist to prevent silent failure from unknown-column errors
            $stockUpdateData = array_intersect_key($stockUpdateData, array_flip(gudangNasitaStockColumns()));
            $db->update('gudang_nasita_stock', $stockUpdateData, 'id = :id', ['id' => $stockId]);

            $db->insert('gudang_nasita_transfer_items', [
                'transfer_id' => $transferId,
                'stock_id'    => $stockId,
                'item_name'   => $stock['item_name'],
                'unit'        => $stock['unit'],
                'quantity'    => $qty,
                'unit_price'  => $unitPrice,
                'subtotal'    => $lineSubtotal,
                'notes'       => $item['notes'] ?? null
            ]);

            // unit_price and subtotal are NOT in gudang_nasita_movements schema; omit to prevent rollback
            $db->insert('gudang_nasita_movements', [
                'stock_id'           => $stockId,
                'movement_date'      => date('Y-m-d'),
                'movement_type'      => 'out_transfer',
                'quantity'           => $qty,
                'reference_type'     => 'transfer',
                'reference_id'       => $transferId,
                'reference_number'   => $transferNumber,
                'target_business_id' => $targetBusinessId,
                'notes'              => $notes ?: ('Transfer ke ' . $business['business_name']),
                'created_by'         => $createdBy
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

    $stmt = $pdo->query("
        SELECT poh.id, poh.po_number, poh.po_date, poh.status,
               poh.business_id AS source_business_id,
               COUNT(pod.id) AS items_count,
               poh.created_at
        FROM purchase_orders_header poh
        LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
        WHERE poh.status IN ('submitted', 'approved', 'partially_received')
        GROUP BY poh.id
        ORDER BY poh.created_at DESC
        LIMIT 20
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Attach item-level detail per PO so callers (e.g. dashboard) can show "apa aja yang terkirim".
    // NOTE: purchase_orders_detail schema differs between business DBs — Gudang Nasita's own
    // (legacy) table uses `unit`/`total_price`, while newer procurement-module DBs (e.g. bens-cafe)
    // use `unit_of_measure`/`subtotal`. Select * and normalize in PHP so this works for both,
    // instead of hardcoding column names that don't exist in every schema.
    if (!empty($rows)) {
        $itemsStmt = $pdo->prepare("SELECT * FROM purchase_orders_detail WHERE po_header_id = ? ORDER BY id ASC");
        foreach ($rows as &$row) {
            $itemsStmt->execute([(int)$row['id']]);
            $rawItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $row['items'] = array_map(function ($it) {
                $qty = (float)($it['quantity'] ?? 0);
                $unitPrice = (float)($it['unit_price'] ?? 0);
                return [
                    'item_name' => $it['item_name'] ?? '',
                    'quantity' => $it['quantity'] ?? 0,
                    'unit' => $it['unit'] ?? ($it['unit_of_measure'] ?? ''),
                    'unit_price' => $it['unit_price'] ?? 0,
                    'total_price' => $it['total_price'] ?? ($it['subtotal'] ?? ($qty * $unitPrice)),
                ];
            }, $rawItems);
        }
        unset($row);
    }

    return $rows;
}

/**
 * Cross-database lookup of PO requests raised by businesses that Gudang Nasita still needs to process.
 * Shared by gudang-nasita.php and the Gudang dashboard so the "PO Masuk" bell/count stays in sync.
 */
function getGudangNasitaPendingBusinessPo(): array
{
    $targetBusinessConfigs = ['narayana-hotel', 'bens-cafe', 'eaat-meet', 'eat-meet'];
    $pendingReceipts = [];

    foreach ($targetBusinessConfigs as $bizSlug) {
        $cfgPath = __DIR__ . '/../config/businesses/' . $bizSlug . '.php';
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

    return array_slice($pendingReceipts, 0, 12);
}

// ── Shared Gudang Nasita monthly bill payment logic ──────────────────────────
// Extracted so both modules/procurement/gudang-tagihan.php and the Tagihan menu's
// "Gudang" tab (api/pay-gudang-bill.php) call the exact same tested logic.

// Matches a gudang transfer/target_business_name row to one of the 3 tracked slugs.
function gudangTagihanMatchBizSlug(string $businessName): ?string
{
    $norm = preg_replace('/[^a-z0-9]/', '', strtolower($businessName));
    if ($norm === '') {
        return null;
    }
    if (strpos($norm, 'narayana') !== false || strpos($norm, 'hotel') !== false) {
        return 'narayana-hotel';
    }
    if (strpos($norm, 'bens') !== false || strpos($norm, 'cafe') !== false) {
        return 'bens-cafe';
    }
    if (strpos($norm, 'eat') !== false || strpos($norm, 'meet') !== false) {
        return 'eaat-meet';
    }
    return null;
}

// Ensures the tracking table exists (lives alongside gudang_nasita_transfers).
function gudangTagihanEnsurePaymentsTable($gudangDb): void
{
    try {
        $gudangDb->query("CREATE TABLE IF NOT EXISTS gudang_nasita_tagihan_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_slug VARCHAR(50) NOT NULL,
            bill_month CHAR(7) NOT NULL,
            transfer_nilai DECIMAL(15,2) NOT NULL DEFAULT 0,
            tkbm_share DECIMAL(15,2) NOT NULL DEFAULT 0,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            business_cash_book_id INT NULL,
            gudang_cash_book_id INT NULL,
            paid_by INT NULL,
            paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_biz_month (business_slug, bill_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
}

// Returns [DatabaseInstance, originDbName, gudangDbName] for Gudang Nasita's own database.
function gudangTagihanGetGudangDb(): array
{
    $cfgPath = __DIR__ . '/../config/businesses/gudang-nasita.php';
    $dbName = '';
    if (file_exists($cfgPath)) {
        $cfg = require $cfgPath;
        $dbName = (string)($cfg['database'] ?? '');
    }
    $originDb = Database::getCurrentDatabase();
    $gudangDb = ($dbName && $dbName !== $originDb) ? Database::switchDatabase($dbName) : Database::getInstance();
    return [$gudangDb, $originDb, $dbName];
}

// Records a business paying its monthly bill: writes an expense to the business' own cash_book
// (taken from its bank account), an income entry to Gudang Nasita's cash_book (money received),
// moves both accounts' balances in the master ledger, and marks the bill as paid.
function gudangTagihanPayMonthlyBill(string $slug, string $month, int $userId): string
{
    $gudangMonthlyBizList = [
        ['slug' => 'narayana-hotel', 'name' => 'Narayana Hotel', 'icon' => '🏨'],
        ['slug' => 'bens-cafe',      'name' => 'Bens Cafe',      'icon' => '☕'],
        ['slug' => 'eaat-meet',      'name' => 'Eat Meet',       'icon' => '🍽️'],
    ];

    $bizInfo = null;
    foreach ($gudangMonthlyBizList as $b) {
        if ($b['slug'] === $slug) {
            $bizInfo = $b;
            break;
        }
    }
    if (!$bizInfo) {
        throw new Exception('Bisnis tidak dikenali.');
    }

    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $periodLabel = date('F Y', strtotime($monthStart));

    [$gudangDb, $originDb, $gudangDbName] = gudangTagihanGetGudangDb();
    gudangTagihanEnsurePaymentsTable($gudangDb);

    $existing = $gudangDb->fetchOne(
        'SELECT id FROM gudang_nasita_tagihan_payments WHERE business_slug = ? AND bill_month = ? LIMIT 1',
        [$slug, $month]
    );
    if ($existing) {
        throw new Exception('Tagihan bulan ini untuk ' . $bizInfo['name'] . ' sudah dibayar.');
    }

    // Recompute the amount server-side (never trust the client) using the same logic as the recap.
    $monthRows = $gudangDb->fetchAll(
        "SELECT gt.target_business_name,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0) AS total_nilai
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled') AND gt.created_at BETWEEN ? AND ?
         GROUP BY gt.target_business_name",
        [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']
    ) ?: [];
    $transferNilai = 0.0;
    foreach ($monthRows as $mr) {
        if (gudangTagihanMatchBizSlug((string)($mr['target_business_name'] ?? '')) === $slug) {
            $transferNilai += (float)$mr['total_nilai'];
        }
    }

    // Perpindahan barang antar bisnis (di luar Gudang) juga ikut menggeser tagihan bulan ini.
    $interBizAdj = getBusinessInterStockTransferBillAdjustments(
        [$slug],
        $monthStart . ' 00:00:00',
        $monthEnd . ' 23:59:59'
    );
    $transferNilai = max(0, $transferNilai + ($interBizAdj[$slug] ?? 0.0));

    $tkbmRow = $gudangDb->fetchOne(
        'SELECT COALESCE(SUM(total_biaya), 0) AS t FROM gudang_nasita_tkbm WHERE tanggal BETWEEN ? AND ?',
        [$monthStart, $monthEnd]
    );
    $tkbmShare = (float)($tkbmRow['t'] ?? 0) / count($gudangMonthlyBizList);
    $totalAmount = $transferNilai + $tkbmShare;

    if ($totalAmount <= 0) {
        throw new Exception('Tidak ada tagihan untuk dibayarkan bulan ini.');
    }

    $bizCfgPath = __DIR__ . '/../config/businesses/' . $slug . '.php';
    if (!file_exists($bizCfgPath)) {
        throw new Exception('Konfigurasi bisnis tidak ditemukan.');
    }
    $bizCfg = require $bizCfgPath;
    $bizDbName = (string)($bizCfg['database'] ?? '');
    if ($bizDbName === '') {
        throw new Exception('Database bisnis tidak ditemukan.');
    }

    $bizNumericId = getNumericBusinessId($slug);
    $gudangNumericId = getNumericBusinessId('gudang-nasita');
    if (!$bizNumericId || !$gudangNumericId) {
        throw new Exception('ID bisnis tidak ditemukan di master.');
    }

    $masterPdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . MASTER_DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' AND is_active = 1 ORDER BY id LIMIT 1");
    $stmt->execute([$bizNumericId]);
    $bizBankAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bizBankAccount) {
        throw new Exception('Rekening bank untuk ' . $bizInfo['name'] . ' belum tersedia.');
    }
    $stmt->execute([$gudangNumericId]);
    $gudangBankAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$gudangBankAccount) {
        throw new Exception('Rekening bank Gudang Nasita belum tersedia.');
    }

    $paymentDesc = 'Bayar Tagihan Gudang Nasita - Periode ' . $periodLabel;
    $incomeDesc  = 'Diterima dari ' . $bizInfo['name'] . ' - Tagihan Bulan ' . $periodLabel;

    // 1) Expense di buku kas bisnis pembayar, dipotong dari rekening bank bisnis tsb.
    $bizDb = Database::switchDatabase($bizDbName);
    try {
        $bizDb->getConnection()->exec("ALTER TABLE `cash_book` DROP FOREIGN KEY `cash_book_ibfk_3`");
    } catch (Throwable $e) {
    }
    try {
        $bizDb->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `division_id` INT NULL");
        $bizDb->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `category_id` INT NULL");
    } catch (Throwable $e) {
    }

    $expCat = $bizDb->fetchOne("SELECT id FROM categories WHERE LOWER(category_name) = 'bayar tagihan gudang nasita' AND category_type = 'expense' LIMIT 1");
    $expCategoryId = $expCat['id'] ?? null;
    if (!$expCategoryId) {
        $divForCat = $bizDb->fetchOne('SELECT id FROM divisions LIMIT 1');
        $expCategoryId = $bizDb->insert('categories', [
            'division_id'    => $divForCat['id'] ?? null,
            'category_name'  => 'Bayar Tagihan Gudang Nasita',
            'category_type'  => 'expense',
            'is_active'      => 1,
        ]);
    }
    $bizDiv = $bizDb->fetchOne('SELECT id FROM divisions LIMIT 1');

    $bizCashBookId = $bizDb->insert('cash_book', [
        'transaction_date' => date('Y-m-d'),
        'transaction_time' => date('H:i:s'),
        'division_id'      => $bizDiv['id'] ?? null,
        'category_id'      => $expCategoryId,
        'transaction_type' => 'expense',
        'amount'           => $totalAmount,
        'description'      => $paymentDesc,
        'payment_method'   => 'transfer',
        'cash_account_id'  => $bizBankAccount['id'],
        'created_by'       => $userId ?: null,
        'source_type'      => 'gudang_tagihan_payment',
        'is_editable'      => 0,
    ]);

    // 2) Income di buku kas Gudang Nasita, masuk ke rekening bank Gudang Nasita.
    $gudangDb = Database::switchDatabase($gudangDbName);
    try {
        $gudangDb->getConnection()->exec("ALTER TABLE `cash_book` DROP FOREIGN KEY `cash_book_ibfk_3`");
    } catch (Throwable $e) {
    }
    try {
        $gudangDb->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `division_id` INT NULL");
        $gudangDb->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `category_id` INT NULL");
    } catch (Throwable $e) {
    }

    $incCat = $gudangDb->fetchOne("SELECT id FROM categories WHERE LOWER(category_name) = 'pendapatan tagihan bisnis' AND category_type = 'income' LIMIT 1");
    $incCategoryId = $incCat['id'] ?? null;
    if (!$incCategoryId) {
        $divForCat2 = $gudangDb->fetchOne('SELECT id FROM divisions LIMIT 1');
        $incCategoryId = $gudangDb->insert('categories', [
            'division_id'    => $divForCat2['id'] ?? null,
            'category_name'  => 'Pendapatan Tagihan Bisnis',
            'category_type'  => 'income',
            'is_active'      => 1,
        ]);
    }
    $gudangDiv = $gudangDb->fetchOne('SELECT id FROM divisions LIMIT 1');

    $gudangCashBookId = $gudangDb->insert('cash_book', [
        'transaction_date' => date('Y-m-d'),
        'transaction_time' => date('H:i:s'),
        'division_id'      => $gudangDiv['id'] ?? null,
        'category_id'      => $incCategoryId,
        'transaction_type' => 'income',
        'amount'           => $totalAmount,
        'description'      => $incomeDesc,
        'payment_method'   => 'transfer',
        'cash_account_id'  => $gudangBankAccount['id'],
        'created_by'       => $userId ?: null,
        'source_type'      => 'gudang_tagihan_income',
        'is_editable'      => 0,
    ]);

    // 3) Pindahkan saldo di ledger master (rekening bank bisnis berkurang, rekening bank Gudang Nasita bertambah).
    try {
        $masterPdo->beginTransaction();
        $trx = $masterPdo->prepare(
            "INSERT INTO cash_account_transactions (cash_account_id, transaction_type, amount, description, transaction_date, created_at)
             VALUES (?, 'expense', ?, ?, CURDATE(), NOW())"
        );
        $trx->execute([$bizBankAccount['id'], $totalAmount, $paymentDesc]);
        $masterPdo->prepare('UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?')
            ->execute([$totalAmount, $bizBankAccount['id']]);

        $trx2 = $masterPdo->prepare(
            "INSERT INTO cash_account_transactions (cash_account_id, transaction_type, amount, description, transaction_date, created_at)
             VALUES (?, 'income', ?, ?, CURDATE(), NOW())"
        );
        $trx2->execute([$gudangBankAccount['id'], $totalAmount, $incomeDesc]);
        $masterPdo->prepare('UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?')
            ->execute([$totalAmount, $gudangBankAccount['id']]);

        $masterPdo->commit();
    } catch (Throwable $e) {
        if ($masterPdo->inTransaction()) {
            $masterPdo->rollBack();
        }
        throw new Exception('Gagal memindahkan saldo rekening: ' . $e->getMessage());
    }

    // 4) Catat status lunas supaya tidak bisa dibayar dobel.
    $gudangDb->insert('gudang_nasita_tagihan_payments', [
        'business_slug'         => $slug,
        'bill_month'            => $month,
        'transfer_nilai'        => $transferNilai,
        'tkbm_share'            => $tkbmShare,
        'amount'                => $totalAmount,
        'business_cash_book_id' => $bizCashBookId ?: null,
        'gudang_cash_book_id'   => $gudangCashBookId ?: null,
        'paid_by'               => $userId ?: null,
    ]);

    if ($originDb) {
        Database::switchDatabase($originDb);
    }

    return 'Tagihan ' . $bizInfo['name'] . ' bulan ' . $periodLabel . ' sebesar Rp ' . number_format($totalAmount, 0, ',', '.') . ' berhasil dibayar dan tercatat di buku kas.';
}

/**
 * ============================================================
 * STAFF STOCK ACCESS — cross-business permission system
 * Lets an admin grant specific Staff Portal users (identified by
 * email) read access to Gudang Nasita + selected business stock,
 * plus the ability to record daily stock-out and create PO's to
 * Gudang Nasita, directly from the Staff Portal PWA (their phone).
 * ============================================================
 */

/**
 * Ensure the master `staff_stock_access` table exists.
 * Stored in the MASTER DB so it's resolvable regardless of which
 * business's staff-api.php instance is handling the request.
 */
function ensureStaffStockAccessTable()
{
    $originDbName = Database::getCurrentDatabase();
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;

    try {
        if ($originDbName !== $masterDbName) {
            Database::switchDatabase($masterDbName);
        }
        $db = Database::getInstance();

        $db->query("CREATE TABLE IF NOT EXISTS staff_stock_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_email VARCHAR(255) NOT NULL,
            staff_name VARCHAR(255) NULL,
            allowed_businesses TEXT NULL,
            can_view_gudang_nasita TINYINT(1) NOT NULL DEFAULT 0,
            can_reduce_stock TINYINT(1) NOT NULL DEFAULT 0,
            can_create_po TINYINT(1) NOT NULL DEFAULT 0,
            can_input_stock_masuk TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_staff_email (staff_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Lazily add the column for installs where the table already existed pre-feature.
        $existingCols = array_column($db->fetchAll('SHOW COLUMNS FROM staff_stock_access') ?: [], 'Field');
        if (!in_array('can_input_stock_masuk', $existingCols, true)) {
            $db->query('ALTER TABLE staff_stock_access ADD COLUMN can_input_stock_masuk TINYINT(1) NOT NULL DEFAULT 0 AFTER can_create_po');
        }
    } catch (Throwable $e) {
        error_log('ensureStaffStockAccessTable error: ' . $e->getMessage());
    } finally {
        if ($originDbName !== '' && $originDbName !== $masterDbName) {
            Database::switchDatabase($originDbName);
        }
    }
}

/**
 * Fetch a staff's stock-access grant by email (case-insensitive).
 * @return array|null Grant row with 'allowed_businesses' decoded to an array, or null.
 */
function getStaffStockAccessByEmail($email)
{
    $email = trim((string)$email);
    if ($email === '') {
        return null;
    }

    ensureStaffStockAccessTable();

    $originDbName = Database::getCurrentDatabase();
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;

    try {
        if ($originDbName !== $masterDbName) {
            Database::switchDatabase($masterDbName);
        }
        $db = Database::getInstance();

        $row = $db->fetchOne(
            "SELECT * FROM staff_stock_access WHERE LOWER(staff_email) = LOWER(?) AND is_active = 1 LIMIT 1",
            [$email]
        );

        if (!$row) {
            return null;
        }

        $decoded = json_decode((string)($row['allowed_businesses'] ?? '[]'), true);
        $row['allowed_businesses'] = is_array($decoded) ? array_values($decoded) : [];

        return $row;
    } catch (Throwable $e) {
        error_log('getStaffStockAccessByEmail error: ' . $e->getMessage());
        return null;
    } finally {
        if ($originDbName !== '' && $originDbName !== $masterDbName) {
            Database::switchDatabase($originDbName);
        }
    }
}

/**
 * List every stock-access grant (for the admin management page).
 */
function getAllStaffStockAccessGrants()
{
    ensureStaffStockAccessTable();

    $originDbName = Database::getCurrentDatabase();
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;

    try {
        if ($originDbName !== $masterDbName) {
            Database::switchDatabase($masterDbName);
        }
        $db = Database::getInstance();

        $rows = $db->fetchAll("SELECT * FROM staff_stock_access ORDER BY staff_name ASC, staff_email ASC") ?: [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string)($row['allowed_businesses'] ?? '[]'), true);
            $row['allowed_businesses'] = is_array($decoded) ? array_values($decoded) : [];
        }
        unset($row);

        return $rows;
    } catch (Throwable $e) {
        error_log('getAllStaffStockAccessGrants error: ' . $e->getMessage());
        return [];
    } finally {
        if ($originDbName !== '' && $originDbName !== $masterDbName) {
            Database::switchDatabase($originDbName);
        }
    }
}

/**
 * Create/update (upsert by email) a staff stock-access grant.
 */
function saveStaffStockAccessGrant($email, $name, array $allowedBusinesses, $canViewGudang, $canReduceStock, $canCreatePo, $createdBy = null, $canInputStockMasuk = false)
{
    $email = strtolower(trim((string)$email));
    if ($email === '') {
        return ['success' => false, 'message' => 'Email staff wajib diisi.'];
    }

    ensureStaffStockAccessTable();

    $originDbName = Database::getCurrentDatabase();
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;

    try {
        if ($originDbName !== $masterDbName) {
            Database::switchDatabase($masterDbName);
        }
        $db = Database::getInstance();

        $db->query(
            "INSERT INTO staff_stock_access
                (staff_email, staff_name, allowed_businesses, can_view_gudang_nasita, can_reduce_stock, can_create_po, can_input_stock_masuk, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                staff_name = VALUES(staff_name),
                allowed_businesses = VALUES(allowed_businesses),
                can_view_gudang_nasita = VALUES(can_view_gudang_nasita),
                can_reduce_stock = VALUES(can_reduce_stock),
                can_create_po = VALUES(can_create_po),
                can_input_stock_masuk = VALUES(can_input_stock_masuk),
                is_active = 1",
            [
                $email,
                trim((string)$name),
                json_encode(array_values($allowedBusinesses)),
                $canViewGudang ? 1 : 0,
                $canReduceStock ? 1 : 0,
                $canCreatePo ? 1 : 0,
                $canInputStockMasuk ? 1 : 0,
                $createdBy,
            ]
        );

        return ['success' => true, 'message' => 'Akses stock staff berhasil disimpan.'];
    } catch (Throwable $e) {
        error_log('saveStaffStockAccessGrant error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal menyimpan akses: ' . $e->getMessage()];
    } finally {
        if ($originDbName !== '' && $originDbName !== $masterDbName) {
            Database::switchDatabase($originDbName);
        }
    }
}

/**
 * Deactivate/delete a staff stock-access grant by its numeric id.
 */
function deleteStaffStockAccessGrant($id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }

    $originDbName = Database::getCurrentDatabase();
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;

    try {
        if ($originDbName !== $masterDbName) {
            Database::switchDatabase($masterDbName);
        }
        $db = Database::getInstance();
        $db->query("DELETE FROM staff_stock_access WHERE id = ?", [$id]);
        return true;
    } catch (Throwable $e) {
        error_log('deleteStaffStockAccessGrant error: ' . $e->getMessage());
        return false;
    } finally {
        if ($originDbName !== '' && $originDbName !== $masterDbName) {
            Database::switchDatabase($originDbName);
        }
    }
}

/**
 * Resolve a safe fallback `created_by` user id for the CURRENTLY connected DB
 * (used when the actor is a staff-portal account, which isn't in `users`).
 */
function resolveFallbackAdminUserId($db)
{
    try {
        $admin = $db->fetchOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        if ($admin) {
            return (int)$admin['id'];
        }
        $fallback = $db->fetchOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        return $fallback ? (int)$fallback['id'] : 1;
    } catch (Throwable $e) {
        return 1;
    }
}

/**
 * Compute a simplified, read-only stock summary for a given business slug —
 * safe to call for ANY business (not just the caller's session business).
 * Mirrors the core computation used in modules/procurement/business-stock-incoming.php.
 *
 * @param string $businessSlug e.g. 'narayana-hotel', 'bens-cafe', 'eaat-meet'
 * @return array List of ['item_name','unit','current_qty','total_received']
 */
function getBusinessStockSummaryForStaff($businessSlug)
{
    $businessSlug = strtolower(trim((string)$businessSlug));
    $cfgPath = __DIR__ . '/../config/businesses/' . $businessSlug . '.php';
    if ($businessSlug === '' || !file_exists($cfgPath)) {
        return [];
    }

    $cfg = require $cfgPath;
    $bizDbName = trim((string)($cfg['database'] ?? ''));
    $bizName = trim((string)($cfg['name'] ?? ''));
    if ($bizDbName === '') {
        return [];
    }

    $activeBusinessId = (int)getNumericBusinessId($businessSlug);

    $targetBusinessNames = array_values(array_unique(array_filter([$bizName])));
    if (in_array(preg_replace('/[^a-z0-9]/', '', $businessSlug), ['eatmeet', 'eaatmeet'], true)) {
        $targetBusinessNames = array_values(array_unique(array_merge($targetBusinessNames, ['Eat Meet', 'Eaat Meet', 'Eat & Meet'])));
    }

    $buildKey = function ($itemName, $unit) {
        return strtolower(trim((string)$itemName)) . '||' . strtolower(trim((string)$unit));
    };
    $getMapQty = function ($map, $key) {
        return isset($map[$key]) ? (float)$map[$key] : 0;
    };

    $rawStockMap = [];
    $manualStockMap = [];
    $baselineMap = [];
    $dailyOutMap = [];
    $interTransferInMap = [];
    $interTransferOutMap = [];
    $stockMetaMap = [];

    $registerStockMeta = function ($itemName, $unit) use (&$stockMetaMap, $buildKey) {
        $itemName = (string)$itemName;
        $unit = (string)$unit;
        $key = $buildKey($itemName, $unit);
        if ($key !== '||' && !isset($stockMetaMap[$key])) {
            $stockMetaMap[$key] = ['item_name' => $itemName, 'unit' => $unit];
        }
    };

    $originDbName = Database::getCurrentDatabase();

    try {
        // 1) Gudang Nasita cross-DB read: total received via transfers targeted at this business.
        $gudangCfgPath = __DIR__ . '/../config/businesses/gudang-nasita.php';
        if (file_exists($gudangCfgPath)) {
            $gudangCfg = require $gudangCfgPath;
            $gudangDbName = (string)($gudangCfg['database'] ?? '');
            if ($gudangDbName !== '') {
                try {
                    $gudangDb = Database::switchDatabase($gudangDbName);

                    $hasTargetBusinessId = false;
                    try {
                        $transferCols = $gudangDb->fetchAll('SHOW COLUMNS FROM gudang_nasita_transfers');
                        foreach ($transferCols as $col) {
                            if (strtolower((string)($col['Field'] ?? '')) === 'target_business_id') {
                                $hasTargetBusinessId = true;
                                break;
                            }
                        }
                    } catch (Throwable $e) {
                    }

                    $targetNamePredicates = [];
                    $targetNameParams = [];
                    foreach ($targetBusinessNames as $targetName) {
                        $targetNamePredicates[] = 'LOWER(TRIM(gt.target_business_name)) LIKE LOWER(?)';
                        $targetNameParams[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $targetName) . '%';
                    }
                    $targetNameSql = $targetNamePredicates ? implode(' OR ', $targetNamePredicates) : '1 = 0';
                    $targetFilterSql = $hasTargetBusinessId
                        ? '(gt.target_business_id = ? OR (' . $targetNameSql . '))'
                        : '(' . $targetNameSql . ')';
                    $targetFilterParams = $hasTargetBusinessId
                        ? array_merge([$activeBusinessId], $targetNameParams)
                        : $targetNameParams;

                    $rawStockSummary = $gudangDb->fetchAll(
                        "SELECT gti.item_name, gti.unit, COALESCE(SUM(gti.quantity), 0) AS total_received
                         FROM gudang_nasita_transfer_items gti
                         JOIN gudang_nasita_transfers gt ON gt.id = gti.transfer_id
                         WHERE {$targetFilterSql}
                         GROUP BY gti.item_name, gti.unit
                         ORDER BY gti.item_name ASC",
                        $targetFilterParams
                    ) ?: [];

                    foreach ($rawStockSummary as $row) {
                        $itemName = (string)($row['item_name'] ?? '');
                        $unit = (string)($row['unit'] ?? 'pcs');
                        $key = $buildKey($itemName, $unit);
                        $rawStockMap[$key] = (float)($row['total_received'] ?? 0);
                        $registerStockMeta($itemName, $unit);
                    }
                } catch (Throwable $e) {
                    error_log('getBusinessStockSummaryForStaff gudang read error: ' . $e->getMessage());
                }
            }
        }

        // 2) Switch to the target business's own DB for manual entries, baselines, daily-out.
        Database::switchDatabase($bizDbName);
        $db = Database::getInstance();

        if ($activeBusinessId > 0) {
            try {
                $manualRows = $db->fetchAll(
                    'SELECT item_name, unit, COALESCE(SUM(quantity),0) AS total_manual FROM business_manual_stock_entries WHERE business_id = ? GROUP BY item_name, unit',
                    [$activeBusinessId]
                );
                foreach ($manualRows as $mRow) {
                    $itemName = (string)($mRow['item_name'] ?? '');
                    $unit = (string)($mRow['unit'] ?? 'pcs');
                    $key = $buildKey($itemName, $unit);
                    $manualStockMap[$key] = (float)($mRow['total_manual'] ?? 0);
                    $registerStockMeta($itemName, $unit);
                }
            } catch (Throwable $e) {
            }

            try {
                $baselineRows = $db->fetchAll(
                    'SELECT item_name, unit, baseline_qty FROM business_stock_reset_baseline WHERE business_id = ?',
                    [$activeBusinessId]
                );
                foreach ($baselineRows as $bRow) {
                    $key = $buildKey($bRow['item_name'] ?? '', $bRow['unit'] ?? '');
                    $baselineMap[$key] = (float)($bRow['baseline_qty'] ?? 0);
                    $registerStockMeta($bRow['item_name'] ?? '', $bRow['unit'] ?? '');
                }
            } catch (Throwable $e) {
            }

            try {
                $dailyOutRowsForSummary = $db->fetchAll(
                    'SELECT item_name, unit, quantity FROM business_stock_daily_out WHERE business_id = ? AND DATE(created_at) = CURDATE()',
                    [$activeBusinessId]
                );
                foreach ($dailyOutRowsForSummary as $dailyRow) {
                    $itemName = trim((string)($dailyRow['item_name'] ?? ''));
                    $unit = trim((string)($dailyRow['unit'] ?? 'pcs'));
                    $qty = (float)($dailyRow['quantity'] ?? 0);
                    if ($itemName === '' || $qty <= 0) {
                        continue;
                    }
                    $key = $buildKey($itemName, $unit);
                    $dailyOutMap[$key] = ($dailyOutMap[$key] ?? 0) + $qty;
                    $registerStockMeta($itemName, $unit);
                }
            } catch (Throwable $e) {
            }
        }

        // 3) Master DB read: direct inter-business transfers in/out.
        try {
            $masterDsn = 'mysql:host=' . DB_HOST . ';dbname=' . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME) . ';charset=' . DB_CHARSET;
            $masterPdo = new PDO($masterDsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $hasInterTable = $masterPdo->query("SHOW TABLES LIKE 'business_inter_stock_transfers'")->fetch();
            if ($hasInterTable && $businessSlug !== '') {
                $stmtIn = $masterPdo->prepare("SELECT item_name, unit, SUM(quantity) AS qty FROM business_inter_stock_transfers WHERE target_business_slug = ? GROUP BY item_name, unit");
                $stmtIn->execute([$businessSlug]);
                foreach ($stmtIn->fetchAll() as $row) {
                    $interTransferInMap[$buildKey($row['item_name'] ?? '', $row['unit'] ?? '')] = (float)($row['qty'] ?? 0);
                    $registerStockMeta($row['item_name'] ?? '', $row['unit'] ?? '');
                }

                $stmtOut = $masterPdo->prepare("SELECT item_name, unit, SUM(quantity) AS qty FROM business_inter_stock_transfers WHERE source_business_slug = ? GROUP BY item_name, unit");
                $stmtOut->execute([$businessSlug]);
                foreach ($stmtOut->fetchAll() as $row) {
                    $interTransferOutMap[$buildKey($row['item_name'] ?? '', $row['unit'] ?? '')] = (float)($row['qty'] ?? 0);
                    $registerStockMeta($row['item_name'] ?? '', $row['unit'] ?? '');
                }
            }
        } catch (Throwable $e) {
            error_log('getBusinessStockSummaryForStaff master transfer read error: ' . $e->getMessage());
        }

        $stockSummary = [];
        foreach ($stockMetaMap as $meta) {
            $itemName = (string)($meta['item_name'] ?? '');
            $unit = (string)($meta['unit'] ?? 'pcs');
            $key = $buildKey($itemName, $unit);
            $receivedQty = $getMapQty($rawStockMap, $key);
            $gross = $receivedQty + $getMapQty($manualStockMap, $key) + $getMapQty($interTransferInMap, $key);
            $currentQty = $gross - $getMapQty($baselineMap, $key) - $getMapQty($dailyOutMap, $key) - $getMapQty($interTransferOutMap, $key);
            $currentQty = $currentQty > 0 ? $currentQty : 0;

            if ($currentQty <= 0 && $receivedQty <= 0) {
                continue;
            }

            $stockSummary[] = [
                'item_name' => $itemName,
                'unit' => $unit,
                'current_qty' => $currentQty,
                'total_received' => $receivedQty,
            ];
        }

        usort($stockSummary, function ($a, $b) {
            return strcasecmp((string)$a['item_name'], (string)$b['item_name']);
        });

        return $stockSummary;
    } catch (Throwable $e) {
        error_log('getBusinessStockSummaryForStaff error: ' . $e->getMessage());
        return [];
    } finally {
        if ($originDbName !== '') {
            Database::switchDatabase($originDbName);
        }
    }
}

/**
 * Record a staff-submitted daily stock-out for a specific business: logs the
 * entry into that business's own business_stock_daily_out table only. Does
 * NOT touch Gudang Nasita's central warehouse stock.
 *
 * @return array ['success' => bool, 'message' => string]
 */
function recordStaffDailyStockOut($businessSlug, $itemName, $unit, $qty, $notes, $staffLabel)
{
    $businessSlug = strtolower(trim((string)$businessSlug));
    $cfgPath = __DIR__ . '/../config/businesses/' . $businessSlug . '.php';
    if ($businessSlug === '' || !file_exists($cfgPath)) {
        return ['success' => false, 'message' => 'Bisnis tidak valid.'];
    }

    $cfg = require $cfgPath;
    $bizDbName = trim((string)($cfg['database'] ?? ''));
    if ($bizDbName === '') {
        return ['success' => false, 'message' => 'Database bisnis tidak ditemukan.'];
    }

    $itemName = trim((string)$itemName);
    $unit = trim((string)$unit) !== '' ? trim((string)$unit) : 'pcs';
    $qty = (float)$qty;
    $notes = trim((string)$notes);
    $staffLabel = trim((string)$staffLabel);

    if ($itemName === '' || $qty <= 0) {
        return ['success' => false, 'message' => 'Data stock keluar tidak valid.'];
    }

    $activeBusinessId = (int)getNumericBusinessId($businessSlug);
    if ($activeBusinessId <= 0) {
        return ['success' => false, 'message' => 'Business ID tidak ditemukan.'];
    }

    $originDbName = Database::getCurrentDatabase();

    try {
        Database::switchDatabase($bizDbName);
        $db = Database::getInstance();

        $db->query("CREATE TABLE IF NOT EXISTS business_stock_daily_out (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_business_item_unit (business_id, item_name, unit),
            INDEX idx_business_created_at (business_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $fallbackUserId = resolveFallbackAdminUserId($db);

        // Only reduces this business's own stock ledger (business_stock_daily_out) —
        // does NOT touch Gudang Nasita's central warehouse stock.
        $db->insert('business_stock_daily_out', [
            'business_id' => $activeBusinessId,
            'item_name' => $itemName,
            'unit' => $unit,
            'quantity' => $qty,
            'notes' => ($notes !== '' ? $notes : 'Pengeluaran stok harian') . ' (via Staff Portal: ' . ($staffLabel !== '' ? $staffLabel : 'Staff') . ')',
            'created_by' => $fallbackUserId,
        ]);

        return ['success' => true, 'message' => 'Stock keluar berhasil dicatat.'];
    } catch (Throwable $e) {
        error_log('recordStaffDailyStockOut error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal catat stock keluar: ' . $e->getMessage()];
    } finally {
        if ($originDbName !== '') {
            Database::switchDatabase($originDbName);
        }
    }
}

/**
 * Create a Purchase Order to Gudang Nasita on behalf of a staff-portal user,
 * for a specific business. Mirrors modules/procurement/create-po.php's flow
 * (PO header stored in the BUSINESS's own DB, addressed to an auto-resolved
 * "Gudang Nasita" internal supplier row) but without any Auth/session
 * dependency, since staff accounts aren't in the `users` table.
 *
 * @param string $businessSlug
 * @param array $items List of ['item_name' => string, 'quantity' => float, 'unit' => string]
 * @param string $notes
 * @param string $staffLabel Display name recorded in the PO notes for traceability
 * @return array ['success' => bool, 'message' => string, 'po_number' => string]
 */
function createStaffPoToGudang($businessSlug, array $items, $notes, $staffLabel)
{
    $businessSlug = strtolower(trim((string)$businessSlug));
    $cfgPath = __DIR__ . '/../config/businesses/' . $businessSlug . '.php';
    if ($businessSlug === '' || !file_exists($cfgPath)) {
        return ['success' => false, 'message' => 'Bisnis tidak valid.'];
    }

    $cfg = require $cfgPath;
    $bizDbName = trim((string)($cfg['database'] ?? ''));
    if ($bizDbName === '') {
        return ['success' => false, 'message' => 'Database bisnis tidak ditemukan.'];
    }

    $notes = trim((string)$notes);
    $staffLabel = trim((string)$staffLabel);

    $validItems = [];
    foreach ($items as $it) {
        $nm = trim((string)($it['item_name'] ?? ''));
        $qt = (float)($it['quantity'] ?? 0);
        $un = trim((string)($it['unit'] ?? 'pcs'));
        if ($nm !== '' && $qt > 0) {
            $validItems[] = ['item_name' => $nm, 'quantity' => $qt, 'unit' => $un !== '' ? $un : 'pcs'];
        }
    }

    if (empty($validItems)) {
        return ['success' => false, 'message' => 'Tambahkan minimal 1 item dengan qty yang valid.'];
    }

    $originDbName = Database::getCurrentDatabase();

    try {
        Database::switchDatabase($bizDbName);
        $db = Database::getInstance();

        // Ensure PO tables exist (same schema/backfill as gudang-po-supplier.php / create-po.php).
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

        // Resolve/create the "Gudang Nasita" internal supplier row in this business's own DB.
        $fallbackUserId = resolveFallbackAdminUserId($db);
        $gudangSupplier = $db->fetchOne("SELECT id FROM suppliers WHERE LOWER(supplier_name) LIKE '%gudang nasita%' LIMIT 1");
        if (!$gudangSupplier) {
            $supplierColumns = $db->fetchAll("SHOW COLUMNS FROM suppliers");
            $colMap = [];
            foreach ($supplierColumns as $col) {
                $field = strtolower((string)($col['Field'] ?? ''));
                if ($field !== '') {
                    $colMap[$field] = true;
                }
            }
            $insertData = ['supplier_name' => 'Gudang Nasita'];
            if (isset($colMap['contact_person'])) {
                $insertData['contact_person'] = 'Internal Warehouse';
            }
            if (isset($colMap['is_active'])) {
                $insertData['is_active'] = 1;
            }
            if (isset($colMap['created_by']) && $fallbackUserId) {
                $insertData['created_by'] = $fallbackUserId;
            }
            $supplierId = $db->insert('suppliers', $insertData);
            $gudangSupplier = $supplierId ? ['id' => $supplierId] : null;
        }
        if (!$gudangSupplier) {
            return ['success' => false, 'message' => 'Supplier internal Gudang Nasita belum tersedia.'];
        }

        $poPrefix = 'GDN-' . date('Ymd') . '-';
        $lastPo = $db->fetchOne("SELECT po_number FROM purchase_orders_header WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1", [$poPrefix . '%']);
        $poSeq = $lastPo ? ((int)substr((string)$lastPo['po_number'], -3) + 1) : 1;
        $poNumber = $poPrefix . str_pad((string)$poSeq, 3, '0', STR_PAD_LEFT);

        $fullNotes = ($notes !== '' ? $notes : 'Permintaan stok dari Staff Portal') . ' (Diajukan oleh: ' . ($staffLabel !== '' ? $staffLabel : 'Staff') . ')';

        $db->getConnection()->beginTransaction();

        $poHeaderId = $db->insert('purchase_orders_header', [
            'business_id' => null,
            'po_number' => $poNumber,
            'supplier_id' => (int)$gudangSupplier['id'],
            'po_date' => date('Y-m-d'),
            'status' => 'submitted',
            'total_amount' => 0,
            'notes' => $fullNotes,
            'created_by' => $fallbackUserId,
        ]);
        if (!$poHeaderId) {
            throw new Exception('Gagal membuat header PO.');
        }

        $detailCols = $db->fetchAll("SHOW COLUMNS FROM purchase_orders_detail");
        $detailColNames = array_column($detailCols, 'Field');
        $firstDiv = in_array('division_id', $detailColNames, true)
            ? $db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1")
            : null;

        foreach ($validItems as $idx => $it) {
            $detailData = [
                'po_header_id' => $poHeaderId,
                'item_name' => $it['item_name'],
                'unit_of_measure' => $it['unit'],
                'quantity' => $it['quantity'],
                'unit_price' => 0,
                'subtotal' => 0,
                'received_quantity' => 0,
            ];
            if (in_array('line_number', $detailColNames, true)) {
                $detailData['line_number'] = $idx + 1;
            }
            if ($firstDiv) {
                $detailData['division_id'] = (int)$firstDiv['id'];
            }
            $insertedId = $db->insert('purchase_orders_detail', $detailData);
            if (!$insertedId) {
                throw new Exception('Gagal menyimpan item: ' . $it['item_name']);
            }
        }

        $db->getConnection()->commit();

        // Auto-register new item names into Gudang Nasita's item master.
        try {
            $gudangCfgPath = __DIR__ . '/../config/businesses/gudang-nasita.php';
            if (file_exists($gudangCfgPath)) {
                $gudangCfg = require $gudangCfgPath;
                $gudangDbName = (string)($gudangCfg['database'] ?? '');
                if ($gudangDbName !== '') {
                    $gudangDb = Database::switchDatabase($gudangDbName);
                    foreach ($validItems as $it) {
                        $exists = $gudangDb->fetchOne('SELECT id FROM gudang_nasita_barang WHERE LOWER(nama_barang) = LOWER(?) AND COALESCE(is_active,1) = 1 LIMIT 1', [$it['item_name']]);
                        if ($exists) {
                            continue;
                        }
                        $prefix = 'BRG-';
                        $last = $gudangDb->fetchOne('SELECT kode_barang FROM gudang_nasita_barang WHERE kode_barang LIKE ? ORDER BY kode_barang DESC LIMIT 1', [$prefix . '%']);
                        $seq = $last ? ((int)substr((string)$last['kode_barang'], -4) + 1) : 1;
                        $gudangDb->insert('gudang_nasita_barang', [
                            'kode_barang' => $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT),
                            'nama_barang' => $it['item_name'],
                            'satuan' => $it['unit'],
                            'is_active' => 1,
                        ]);
                    }
                    Database::switchDatabase($bizDbName);
                }
            }
        } catch (Throwable $regErr) {
            error_log('createStaffPoToGudang item auto-register error: ' . $regErr->getMessage());
        }

        return ['success' => true, 'message' => 'PO berhasil dibuat: ' . $poNumber, 'po_number' => $poNumber];
    } catch (Throwable $e) {
        try {
            $db = Database::getInstance();
            if ($db->getConnection()->inTransaction()) {
                $db->getConnection()->rollBack();
            }
        } catch (Throwable $rbErr) {
        }
        error_log('createStaffPoToGudang error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal membuat PO: ' . $e->getMessage()];
    } finally {
        if ($originDbName !== '') {
            Database::switchDatabase($originDbName);
        }
    }
}

/**
 * Record incoming stock ("barang datang") directly into Gudang Nasita's central
 * warehouse stock, on behalf of a staff-portal user. Reuses the same tested logic
 * as the "Input Stok Manual" flow in modules/procurement/gudang-nasita.php
 * (addGudangNasitaManualStock), without any Auth/session dependency since staff
 * accounts aren't in the `users` table.
 *
 * @return array ['success' => bool, 'message' => string, ...]
 */
function recordStaffStockMasukToGudang($itemName, $unit, $qty, $unitPrice, $supplierName, $notes, $staffLabel)
{
    $gudangCfgPath = __DIR__ . '/../config/businesses/gudang-nasita.php';
    if (!file_exists($gudangCfgPath)) {
        return ['success' => false, 'message' => 'Konfigurasi Gudang Nasita tidak ditemukan.'];
    }
    $gudangCfg = require $gudangCfgPath;
    $gudangDbName = trim((string)($gudangCfg['database'] ?? ''));
    if ($gudangDbName === '') {
        return ['success' => false, 'message' => 'Database Gudang Nasita tidak ditemukan.'];
    }

    $itemName = trim((string)$itemName);
    $unit = trim((string)$unit) !== '' ? trim((string)$unit) : 'pcs';
    $qty = (float)$qty;
    $unitPrice = (float)$unitPrice;
    $supplierName = trim((string)$supplierName);
    $notes = trim((string)$notes);
    $staffLabel = trim((string)$staffLabel);

    if ($itemName === '' || $qty <= 0) {
        return ['success' => false, 'message' => 'Data stock barang datang tidak valid.'];
    }

    $originDbName = Database::getCurrentDatabase();

    try {
        Database::switchDatabase($gudangDbName);
        $db = Database::getInstance();

        // Preserve the item's existing catalog category (if any) instead of always
        // resetting it to 'lainnya' — the staff-portal form doesn't ask for category.
        $category = 'lainnya';
        $existingStock = $db->fetchOne(
            "SELECT category FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?)
             ORDER BY (BINARY item_name = ?) DESC, COALESCE(is_active,1) DESC, quantity DESC, id ASC
             LIMIT 1",
            [$itemName, $itemName]
        );
        if ($existingStock && trim((string)($existingStock['category'] ?? '')) !== '') {
            $category = trim((string)$existingStock['category']);
        }

        // Diagnostic: surface duplicate rows sharing the same name (case/whitespace-insensitive) —
        // these cause the write to silently land on a hidden duplicate instead of the visible one.
        $dupCount = $db->fetchOne("SELECT COUNT(*) AS c FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?)", [$itemName]);
        if ($dupCount && (int)$dupCount['c'] > 1) {
            error_log('[GUDANG] DUPLICATE item_name detected for "' . $itemName . '": ' . (int)$dupCount['c'] . ' rows');
        }

        $fallbackUserId = resolveFallbackAdminUserId($db);

        $result = addGudangNasitaManualStock($itemName, $unit, $qty, $fallbackUserId, [
            'category' => $category,
            'unit_price' => $unitPrice,
            'supplier_name' => $supplierName,
            'notes' => ($notes !== '' ? $notes : 'Input stok barang datang') . ' (via Staff Portal: ' . ($staffLabel !== '' ? $staffLabel : 'Staff') . ')',
        ]);

        return $result;
    } catch (Throwable $e) {
        error_log('recordStaffStockMasukToGudang error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal catat stock barang datang: ' . $e->getMessage()];
    } finally {
        if ($originDbName !== '') {
            Database::switchDatabase($originDbName);
        }
    }
}
