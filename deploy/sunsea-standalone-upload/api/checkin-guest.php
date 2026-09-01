<?php

/**
 * API: Check-in Guest
 * Update booking status to 'checked_in' and record check-in time
 */

// Suppress all errors and warnings to prevent non-JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any unexpected output
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// Clean any output that might have been generated
ob_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!$auth->hasPermission('frontdesk')) {
    echo json_encode(['success' => false, 'message' => 'No permission']);
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Validate user exists in database - with fallback
$validUserId = null;
if ($currentUser && !empty($currentUser['id'])) {
    $userExists = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$currentUser['id']]);
    if ($userExists) {
        $validUserId = $currentUser['id'];
    }
}

// If current user not found, fallback to first active admin user
if (!$validUserId) {
    $fallbackUser = $db->fetchOne("SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    if ($fallbackUser) {
        $validUserId = $fallbackUser['id'];
        error_log("Check-in: Current user invalid, using fallback user ID " . $validUserId);
    }
}

try {
    // Handle JSON input if $_POST is empty
    if (empty($_POST)) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($jsonInput)) {
            $_POST = $jsonInput;
        }
    }

    // Get booking ID from request
    $bookingId = $_POST['booking_id'] ?? null;

    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }

    $createInvoice = ($_POST['create_invoice'] ?? '0') == '1';
    $payNow    = ($_POST['pay_now']    ?? '0') === '1';
    $payAmount = (float)($_POST['pay_amount'] ?? 0);
    $payMethod = trim($_POST['pay_method'] ?? 'cash');
    $validPayMethods = ['cash', 'card', 'transfer', 'qris', 'ota', 'bank_transfer', 'other', 'edc'];
    if (!in_array($payMethod, $validPayMethods)) $payMethod = 'cash';

    // Get booking details
    $booking = $db->fetchOne("
        SELECT b.*, g.guest_name, g.phone as guest_phone, g.email as guest_email, r.room_number 
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ", [$bookingId]);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // Idempotent behavior: if already checked-in, return success and stop.
    if ($booking['status'] === 'checked_in') {
        echo json_encode([
            'success' => true,
            'message' => 'Guest sudah berstatus check-in.',
            'already_checked_in' => true,
            'booking_id' => (int)$bookingId
        ]);
        exit;
    }

    // Check if booking is confirmed or pending
    if (!in_array($booking['status'], ['confirmed', 'pending'])) {
        throw new Exception('Booking status tidak valid untuk check-in');
    }

    // Calculate remaining payment
    $payment = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as paid FROM booking_payments WHERE booking_id = ?", [$bookingId]);
    $totalPaid = max((float)$payment['paid'], (float)$booking['paid_amount']);
    $remaining = max(0, (float)$booking['final_price'] - $totalPaid);

    // ==========================================
    // NEW LOGIC: OTA vs Direct Booking Check-in
    // ==========================================
    // Use booking_sources table (source_type) for reliable OTA detection
    $isOTA = false;
    $sourceInfo = null;
    try {
        $sourceInfo = $db->fetchOne("SELECT source_type FROM booking_sources WHERE source_key = ? AND is_active = 1", [$booking['booking_source']]);
        if ($sourceInfo) {
            $isOTA = ($sourceInfo['source_type'] ?? '') !== 'direct';
        }
    } catch (\Throwable $e) {
        // Table might not exist, fall through to hardcoded detection
    }

    // Fallback: hardcoded detection if not found in booking_sources table
    if (!$isOTA && !$sourceInfo) {
        $normalizedSource = strtolower(trim($booking['booking_source'] ?? ''));
        $normalizedSource = str_replace(['.com', '.co.id', '.id'], '', $normalizedSource);
        $normalizedSource = preg_replace('/[^a-z0-9]/', '', $normalizedSource);

        $otaSources = ['agoda', 'booking', 'bookingcom', 'tiket', 'tiketcom', 'airbnb', 'ota', 'traveloka', 'pegipegi', 'expedia'];
        foreach ($otaSources as $ota) {
            if (strpos($normalizedSource, $ota) !== false || $normalizedSource === $ota) {
                $isOTA = true;
                break;
            }
        }
    }

    // Start transaction
    $db->beginTransaction();

    // Jika user memilih bayar sekarang saat check-in: tambahkan pembayaran
    if ($payNow && $payAmount > 0) {
        $db->insert('booking_payments', [
            'booking_id' => $bookingId,
            'amount'     => $payAmount,
            'payment_date'   => date('Y-m-d H:i:s'),
            'payment_method' => $payMethod,
            'notes'          => 'Dibayar saat check-in',
            'processed_by'   => $validUserId
        ]);
        // Recalculate setelah pembayaran baru
        $payment   = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as paid FROM booking_payments WHERE booking_id = ?", [$bookingId]);
        $totalPaid = (float)$payment['paid'];
        $remaining = max(0, (float)$booking['final_price'] - $totalPaid);
        $newPayStatus = $remaining <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid');
        $db->query("UPDATE bookings SET paid_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?", [$totalPaid, $newPayStatus, $bookingId]);
    }

    if ($isOTA && !$payNow) {
        // OTA: Auto-handle payment at check-in
        // If remaining amount exists AND no payment record yet, create auto-payment record
        if ($remaining > 0) {
            $otaSourceKey = strtolower(trim($booking['booking_source'] ?? 'ota'));

            // Check if we already have a booking_payment for this OTA booking
            $existingPayment = $db->fetchOne("
                SELECT id FROM booking_payments 
                WHERE booking_id = ? 
                LIMIT 1
            ", [$bookingId]);

            // Only create auto-payment if no payment record exists yet
            if (!$existingPayment) {
                $db->insert('booking_payments', [
                    'booking_id'   => $bookingId,
                    'amount'       => $remaining,
                    'payment_date' => date('Y-m-d H:i:s'),
                    'payment_method' => 'ota_' . $otaSourceKey,
                    'notes'        => 'Auto-payment at check-in (OTA: ' . $booking['booking_source'] . ')',
                    'processed_by' => $validUserId
                ]);
            }
        }

        // Update paid_amount dan payment_status setelah OTA payment handling
        $payment   = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as paid FROM booking_payments WHERE booking_id = ?", [$bookingId]);
        $totalPaid = (float)$payment['paid'];
        $remaining = max(0, (float)$booking['final_price'] - $totalPaid);
        $newPayStatus = $remaining <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid');
        $db->query("UPDATE bookings SET paid_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?", [$totalPaid, $newPayStatus, $bookingId]);
    } elseif (!$isOTA && !$payNow) {
        // Direct booking bayar nanti: buat invoice untuk sisa tagihan
        if ($remaining > 0) {
            $createInvoice = true;
        }
    }

    // Create invoice for remaining balance if required
    $invoiceNumber = null;
    if ($remaining > 0 && $createInvoice) {
        // Priority 1: Exact match for 'Hotel' or 'Front Desk'
        $division = $db->fetchOne("SELECT id FROM divisions WHERE is_active = 1 AND (division_name = 'Hotel' OR division_name = 'Front Desk' OR division_name = 'Room Sell') LIMIT 1");

        // Priority 2: Contains 'Hotel' or 'Front'
        if (!$division) {
            $division = $db->fetchOne("SELECT id FROM divisions WHERE is_active = 1 AND (division_name LIKE '%Hotel%' OR division_name LIKE '%Front%') ORDER BY id ASC LIMIT 1");
        }

        // Priority 3: Fallback to any division
        if (!$division) {
            $division = $db->fetchOne("SELECT id FROM divisions WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        }

        if (!$division) {
            throw new Exception('Divisi tidak ditemukan untuk invoice');
        }

        $prefix = 'INV-' . date('Ym') . '-';
        $lastInvoice = $db->fetchOne("SELECT invoice_number FROM sales_invoices_header WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1", [$prefix . '%']);
        $newNumber = 1;
        if ($lastInvoice) {
            $lastNumber = (int)substr($lastInvoice['invoice_number'], -4);
            $newNumber = $lastNumber + 1;
        }
        $invoiceNumber = $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);

        $invoiceId = $db->insert('sales_invoices_header', [
            'invoice_number' => $invoiceNumber,
            'invoice_date' => date('Y-m-d'),
            'customer_name' => $booking['guest_name'],
            'customer_phone' => $booking['guest_phone'] ?? null,
            'customer_email' => $booking['guest_email'] ?? null,
            'customer_address' => null,
            'division_id' => $division['id'],
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
            'subtotal' => $remaining,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $remaining,
            'paid_amount' => 0,
            'notes' => 'Auto invoice from check-in. Booking #' . $booking['booking_code'],
            'created_by' => $validUserId
        ]);

        $db->insert('sales_invoices_detail', [
            'invoice_header_id' => $invoiceId,
            'item_name' => 'Room Revenue - ' . $booking['booking_code'],
            'item_description' => 'Room ' . $booking['room_number'] . ' ' . $booking['check_in_date'] . ' - ' . $booking['check_out_date'],
            'category' => 'Room Revenue',
            'quantity' => 1,
            'unit_price' => $remaining,
            'total_price' => $remaining,
            'notes' => null
        ]);
    }

    // Update booking status to checked_in
    $db->query("
        UPDATE bookings 
        SET status = 'checked_in',
            actual_checkin_time = NOW(),
            checked_in_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ", [$validUserId, $bookingId]);

    // Update room status to occupied
    $db->query("
        UPDATE rooms 
        SET status = 'occupied',
            current_guest_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ", [$booking['guest_id'], $booking['room_id']]);

    // Log activity
    if ($validUserId) {
        $db->query("
            INSERT INTO activity_logs (user_id, action, description, created_at)
            VALUES (?, ?, ?, NOW())
        ", [
            $validUserId,
            'check_in',
            "Check-in guest: {$booking['guest_name']} - Room {$booking['room_number']} - Booking #{$booking['booking_code']}"
        ]);
    }

    // ==========================================
    // SYNC TO CASHBOOK:
    // - OTA: sync saat check-in (uang baru tercatat masuk kas saat tamu datang)
    // - Direct bayar sekarang saat check-in: sync jumlah yang baru dibayar
    // - Direct sudah bayar sebelumnya: sudah di-sync saat pembayaran, skip (dedup di CashbookHelper)
    // ==========================================
    $cashbookSynced = false;
    $directAlreadyPaid = (!$isOTA && !$payNow && $totalPaid > 0);
    if ($isOTA || $payNow || $directAlreadyPaid) {
        try {
            require_once '../includes/CashbookHelper.php';
            $cashbookHelper = new CashbookHelper($db, $_SESSION['business_id'] ?? 1, $validUserId ?? 1);

            if ($payNow && $payAmount > 0) {
                // Direct/OTA bayar sekarang: sync jumlah yang baru dibayar
                $syncAmount = $payAmount;
                $syncMethod = $payMethod;
            } else {
                // OTA atau direct yang sudah bayar sebelumnya: sync total yang sudah dibayar
                $totalPayment = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM booking_payments WHERE booking_id = ?", [$bookingId]);
                $syncAmount = (float)$totalPayment['total'];
                // Fallback: gunakan paid_amount dari bookings
                if ($syncAmount <= 0) $syncAmount = $totalPaid;
                if ($syncAmount <= 0) $syncAmount = (float)$booking['paid_amount'];
                if ($syncAmount <= 0) $syncAmount = (float)$booking['final_price'];
                // OTA: Set payment method as "OTA [source]" (human readable)
                $syncMethod = $isOTA ? ('OTA ' . ($booking['booking_source'] ?? 'OTA')) : 'transfer';
            }

            $syncResult = $cashbookHelper->syncPaymentToCashbook([
                'payment_id'     => null,
                'booking_id'     => $bookingId,
                'amount'         => $syncAmount,
                'payment_method' => $syncMethod,
                'guest_name'     => $booking['guest_name'],
                'booking_code'   => $booking['booking_code'],
                'room_number'    => $booking['room_number'],
                'booking_source' => $booking['booking_source'],
                'booking_notes'  => $booking['notes'] ?? $booking['special_request'] ?? '',
                'final_price'    => $booking['final_price'],
                'total_paid'     => $totalPaid,
                'is_new_reservation' => false,
                'is_ota_checkin' => $isOTA
            ]);

            $cashbookSynced = $syncResult['success'];

            if ($cashbookSynced && !empty($syncResult['transaction_id'])) {
                try {
                    $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE booking_id = ?", [$syncResult['transaction_id'], $bookingId]);
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
            error_log("Cashbook sync error at check-in: " . $e->getMessage());
        }
    }

    $db->commit();

    // Build success message
    $successMessage = "Check-in berhasil! {$booking['guest_name']} - Room {$booking['room_number']}";

    if ($cashbookSynced) {
        if ($isOTA && !$payNow && !empty($syncResult['ota_fee'])) {
            $otaFee = $syncResult['ota_fee'];
            $netAmt = $otaFee['net'] ?? $totalPaid;
            $successMessage .= "\n\n💳 OTA " . ucfirst($booking['booking_source']) . " - otomatis masuk kas bank";
            if ($otaFee['fee_percent'] > 0) {
                $successMessage .= "\n📉 Fee OTA (" . $otaFee['fee_percent'] . "%): -Rp " . number_format($otaFee['fee_amount'], 0, ',', '.');
            }
            $successMessage .= "\n✅ Rp " . number_format($netAmt, 0, ',', '.') . " tercatat di Buku Kas (Kas Bank)";
        } else {
            $syncedAmt = ($payNow && $payAmount > 0) ? $payAmount : $totalPaid;
            $successMessage .= "\n\n✅ Rp " . number_format($syncedAmt, 0, ',', '.') . " tercatat di Buku Kas";
        }
    } elseif ($payNow && !$cashbookSynced) {
        $successMessage .= "\n\n⚠️ Pembayaran tersimpan namun gagal sync ke buku kas";
    } elseif (!$payNow && $remaining > 0) {
        $successMessage .= "\n\n⏰ Sisa tagihan Rp " . number_format($remaining, 0, ',', '.') . " belum dibayar";
        if ($invoiceNumber) $successMessage .= "\n📋 Invoice #{$invoiceNumber} telah dibuat";
        $successMessage .= "\nHarap lunasi sebelum CHECK-OUT!";
    }

    // ═══ PUSH NOTIFICATION: Check-In ═══
    try {
        require_once dirname(dirname(__FILE__)) . '/includes/PushNotificationHelper.php';
        $pushHelper = new PushNotificationHelper($db);
        $pushHelper->sendToAdmins(
            '✅ Check-In: ' . $booking['guest_name'],
            'Room ' . $booking['room_number'] . ' - ' . $booking['guest_name'] . ' telah check-in',
            [
                'type' => 'check_in',
                'tag'  => 'checkin-' . $bookingId,
                'url'  => '/modules/frontdesk/index.php',
                'booking_id' => $bookingId,
                'guest_name' => $booking['guest_name'],
                'room_number' => $booking['room_number']
            ]
        );
    } catch (\Throwable $pushErr) {
        error_log('Push notification error (check-in): ' . $pushErr->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'booking_id' => $bookingId,
        'guest_name' => $booking['guest_name'],
        'room_number' => $booking['room_number'],
        'invoice_number' => $invoiceNumber,
        'is_ota' => $isOTA,
        'cashbook_synced' => $cashbookSynced ?? false,
        'remaining_balance' => $remaining,
        'final_price' => (float)$booking['final_price'],
        'paid_amount' => $totalPaid
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log("Check-in Error: " . $e->getMessage());

    // Clean output buffer before sending JSON
    ob_clean();

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Flush output buffer
ob_end_flush();
