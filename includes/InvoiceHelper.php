<?php

/**
 * Invoice Helper — Consolidated invoice management
 * Ensures 1 invoice per guest, all services consolidated
 */

/**
 * Get or create a single unpaid invoice for a guest
 * This ensures all services (motor, car, laundry, etc.) go into 1 invoice per booking
 * 
 * @param PDO $pdo
 * @param int $businessId
 * @param int|null $bookingId
 * @param string $guestName
 * @param string|null $guestPhone
 * @param string|null $roomNumber
 * @return int Invoice ID
 */
function getOrCreateGuestInvoice(
    PDO $pdo,
    int $businessId,
    ?int $bookingId,
    string $guestName,
    ?string $guestPhone = null,
    ?string $roomNumber = null
): int {
    // 1) Try to find EXISTING unpaid invoice for this booking
    if ($bookingId) {
        $stmt = $pdo->prepare("
            SELECT id FROM hotel_invoices
            WHERE business_id = ? AND booking_id = ?
              AND payment_status IN ('unpaid','partial')
              AND status = 'confirmed'
              AND cashbook_synced = 0
            LIMIT 1
        ");
        $stmt->execute([$businessId, $bookingId]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int)$existing;
        }
    }

    // 2) Try to find EXISTING unpaid invoice by guest name (fallback if no booking_id)
    $stmt = $pdo->prepare("
        SELECT id FROM hotel_invoices
        WHERE business_id = ? AND guest_name = ?
          AND payment_status IN ('unpaid','partial')
          AND status = 'confirmed'
          AND cashbook_synced = 0
          AND (booking_id IS NULL OR booking_id = ?)
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$businessId, $guestName, $bookingId]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    // 3) Create new invoice
    $prefix = 'HSV-' . date('Ym') . '-';
    $last = $pdo->query("SELECT invoice_number FROM hotel_invoices 
                         WHERE invoice_number LIKE '{$prefix}%' 
                         ORDER BY invoice_number DESC LIMIT 1")->fetchColumn();
    $seq = $last ? ((int)substr($last, -4) + 1) : 1;
    $invNo = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO hotel_invoices
        (business_id, invoice_number, booking_id, guest_name, guest_phone, room_number,
         total, paid_amount, payment_status, payment_method, status, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
    ");
    $stmt->execute([
        $businessId,
        $invNo,
        $bookingId,
        $guestName,
        $guestPhone,
        $roomNumber,
        0,      // total (will be updated as items added)
        0,      // paid_amount
        'unpaid',
        'cash',
        'confirmed',
        $_SESSION['user_id'] ?? null
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Add item to invoice (for any service type)
 */
function addInvoiceItem(
    PDO $pdo,
    int $invoiceId,
    string $serviceType,
    string $description,
    float $quantity = 1,
    float $unitPrice = 0,
    ?string $startDt = null,
    ?string $endDt = null
): int {
    $stmt = $pdo->prepare("
        INSERT INTO hotel_invoice_items
        (invoice_id, service_type, description, quantity, unit_price, total_price, start_datetime, end_datetime)
        VALUES (?,?,?,?,?,?,?,?)
    ");

    $totalPrice = round($quantity * $unitPrice, 2);
    $stmt->execute([
        $invoiceId,
        $serviceType,
        $description,
        $quantity,
        $unitPrice,
        $totalPrice,
        $startDt,
        $endDt
    ]);

    // Update invoice total
    $pdo->prepare("UPDATE hotel_invoices SET total = total + ? WHERE id = ? AND cashbook_synced = 0")
        ->execute([$totalPrice, $invoiceId]);

    return (int)$pdo->lastInsertId();
}

/**
 * Update invoice item price and recalculate invoice total
 */
function updateInvoiceItem(
    PDO $pdo,
    int $itemId,
    float $newPrice
): bool {
    // Get old price
    $oldItem = $pdo->prepare("SELECT invoice_id, total_price FROM hotel_invoice_items WHERE id = ?");
    $oldItem->execute([$itemId]);
    $item = $oldItem->fetch(PDO::FETCH_ASSOC);

    if (!$item) return false;

    $invId = (int)$item['invoice_id'];
    $oldPrice = (float)$item['total_price'];
    $difference = $newPrice - $oldPrice;

    // Update item
    $pdo->prepare("UPDATE hotel_invoice_items SET total_price = ? WHERE id = ?")
        ->execute([$newPrice, $itemId]);

    // Update invoice total
    if ($difference != 0) {
        $pdo->prepare("UPDATE hotel_invoices SET total = total + ? WHERE id = ? AND cashbook_synced = 0")
            ->execute([$difference, $invId]);
    }

    return true;
}

/**
 * Find hotel invoice item by type and description (for updating)
 */
function findInvoiceItem(
    PDO $pdo,
    int $invoiceId,
    string $serviceType,
    string $description
): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM hotel_invoice_items
        WHERE invoice_id = ? AND service_type = ? AND description = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId, $serviceType, $description]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}
