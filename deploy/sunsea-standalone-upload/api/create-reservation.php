<?php

/**
 * API: CREATE RESERVATION
 * Handles reservation creation from calendar
 */

// Start output buffering FIRST before any includes
ob_start();

// Suppress all errors/warnings
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_ACCESS', true);

// Capture output from includes
ob_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/CashbookHelper.php';
ob_end_clean();

// Clear ALL buffered output before sending JSON
while (ob_get_level()) {
    ob_end_clean();
}

// Set JSON header AFTER clearing buffers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

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

try {
    // Validate required fields
    $required = ['guest_name', 'check_in_date', 'check_out_date', 'room_id', 'room_price', 'booking_source'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field $field is required");
        }
    }

    // Get form data
    $guestName = trim($_POST['guest_name']);
    $guestPhone = trim($_POST['guest_phone'] ?? '');
    $guestEmail = trim($_POST['guest_email'] ?? '');
    $guestNationality = trim($_POST['guest_nationality'] ?? ($_POST['nationality'] ?? 'Indonesia'));
    if ($guestNationality === '') {
        $guestNationality = 'Indonesia';
    }
    $guestIdNumber = trim($_POST['guest_id_number'] ?? '');
    $groupId = trim($_POST['group_id'] ?? '');

    $checkInDate = $_POST['check_in_date'];
    $checkOutDate = $_POST['check_out_date'];
    $roomId = (int)($_POST['room_id'] ?? 0);
    $roomPrice = (float)($_POST['room_price'] ?? 0);
    $totalNights = (int)($_POST['total_nights'] ?? 0);
    $adultCount = (int)($_POST['adult_count'] ?? 1);
    $childrenCount = (int)($_POST['children_count'] ?? 0);
    $bookingSource = $_POST['booking_source'];
    $discount = (float)($_POST['discount'] ?? 0);
    $totalPrice = (float)($_POST['total_price'] ?? 0);
    $finalPrice = (float)($_POST['final_price'] ?? 0);
    $specialRequest = trim($_POST['special_request'] ?? '');
    $paymentStatus = $_POST['payment_status'] ?? 'unpaid';
    $paidAmount = (float)($_POST['paid_amount'] ?? 0);
    $paymentMethodRaw = $_POST['payment_method'] ?? 'cash';
    $paymentMethod = strtolower(trim($paymentMethodRaw));
    if ($paymentMethod === 'qr') {
        $paymentMethod = 'qris';
    }
    $allowedMethods = ['cash', 'card', 'transfer', 'qris', 'ota', 'bank_transfer', 'other'];
    if (!in_array($paymentMethod, $allowedMethods, true) && strpos($paymentMethod, 'ota_') !== 0) {
        $paymentMethod = 'cash';
    }

    // Save original booking source for OTA fee calculation
    $originalBookingSource = $bookingSource;

    // Map booking source to database values
    // Keep specific OTA names for tracking in cashbook
    $sourceMap = [
        'walk_in' => 'walk_in',
        'phone' => 'phone',
        'online' => 'online',
        'agoda' => 'agoda',
        'booking' => 'booking',
        'tiket' => 'tiket',
        'airbnb' => 'airbnb',
        'traveloka' => 'traveloka',
        'expedia' => 'expedia',
        'pegipegi' => 'pegipegi',
        'ota' => 'ota',
        'other' => 'ota'
    ];
    $bookingSource = $sourceMap[$bookingSource] ?? $bookingSource;

    // Validate dates
    $checkIn = new DateTime($checkInDate);
    $checkOut = new DateTime($checkOutDate);

    if ($checkOut <= $checkIn) {
        throw new Exception("Check-out date must be after check-in date");
    }

    // Calculate nights if not provided
    if ($totalNights == 0) {
        $interval = $checkIn->diff($checkOut);
        $totalNights = $interval->days;
    }

    // Check room availability
    $conflicts = $db->fetchAll("
        SELECT id FROM bookings 
        WHERE room_id = ? 
        AND status != 'cancelled'
        AND (
            (check_in_date < ? AND check_out_date > ?)
            OR (check_in_date >= ? AND check_in_date < ?)
        )
    ", [$roomId, $checkOutDate, $checkInDate, $checkInDate, $checkOutDate]);

    if (!empty($conflicts)) {
        throw new Exception("Room is not available for selected dates");
    }

    $db->beginTransaction();

    // Auto-create group_id column if not exists
    try {
        $colCheck = $db->fetchOne("SHOW COLUMNS FROM bookings LIKE 'group_id'");
        if (!$colCheck) {
            $db->getConnection()->exec("ALTER TABLE bookings ADD COLUMN group_id VARCHAR(50) NULL DEFAULT NULL AFTER booking_code, ADD INDEX idx_group_id (group_id)");
        }
    } catch (\Throwable $e) {
        // Column might already exist
    }

    // ALWAYS CREATE NEW GUEST for each reservation
    // This prevents name changes when same phone/email is used
    $idCardNumber = !empty($guestIdNumber) ? $guestIdNumber : 'TEMP-' . date('YmdHis') . '-' . rand(1000, 9999);
    $db->query("
        INSERT INTO guests (guest_name, phone, email, id_card_number, nationality, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ", [$guestName, $guestPhone, $guestEmail, $idCardNumber, $guestNationality]);
    $guestId = $db->getConnection()->lastInsertId();

    // Generate booking code
    $bookingCode = 'BK-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

    // Check if booking code exists
    $exists = $db->fetchOne("SELECT id FROM bookings WHERE booking_code = ?", [$bookingCode]);
    if ($exists) {
        $bookingCode = 'BK-' . date('YmdHis') . '-' . rand(100, 999);
    }

    // ==========================================
    // OTA BOOKING: Auto-set paid_amount
    // ==========================================
    // Detect if OTA booking - use booking_sources table (source_type) for reliable detection
    $isOTABooking = false;
    $sourceInfo = null;
    try {
        $sourceInfo = $db->fetchOne("SELECT source_type FROM booking_sources WHERE source_key = ? AND is_active = 1", [$originalBookingSource]);
        if ($sourceInfo) {
            $isOTABooking = ($sourceInfo['source_type'] ?? '') !== 'direct';
        }
    } catch (\Throwable $e) {
        // Table might not exist, fall through to hardcoded detection
    }

    // Fallback: hardcoded detection if not found in booking_sources table
    if (!$isOTABooking && !$sourceInfo) {
        $normalizedSource = strtolower(trim($originalBookingSource ?? ''));
        $normalizedSource = str_replace(['.com', '.co.id', '.id'], '', $normalizedSource);
        $normalizedSource = preg_replace('/[^a-z0-9]/', '', $normalizedSource);
        $otaSources = ['agoda', 'booking', 'bookingcom', 'tiket', 'tiketcom', 'airbnb', 'ota', 'traveloka', 'pegipegi', 'expedia'];
        foreach ($otaSources as $otaKey) {
            if (strpos($normalizedSource, $otaKey) !== false || $normalizedSource === $otaKey) {
                $isOTABooking = true;
                break;
            }
        }
    }
    // Also check mapped source
    if ($bookingSource === 'ota') {
        $isOTABooking = true;
    }

    // For OTA bookings: DO NOT auto-set paid_amount
    // OTA payment akan dicatat saat check-in via CashbookHelper
    // paid_amount harus 0 sampai tamu check-in
    if ($isOTABooking) {
        $paidAmount = 0; // OTA belum bayar, tunggu check-in
        $paymentStatus = 'unpaid'; // Explicit mark as unpaid for OTA
        error_log("CREATE-RESERVATION: OTA booking detected ({$originalBookingSource}) - set paid_amount=0, payment_status=unpaid (akan masuk kas saat check-in)");
    } else {
        // For non-OTA (Direct): ensure payment status matches paid amount
        if ($paidAmount <= 0) {
            $paymentStatus = 'unpaid';
        } elseif ($paidAmount >= $finalPrice) {
            $paymentStatus = 'paid';
        } else {
            $paymentStatus = 'partial';
        }
    }

    // Create booking
    $bookingStmt = $db->query("
        INSERT INTO bookings (
            booking_code, group_id, guest_id, room_id, 
            check_in_date, check_out_date, total_nights,
            adults, children,
            room_price, total_price, discount, final_price,
            booking_source, status, payment_status, paid_amount,
            special_request, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?, NOW())
    ", [
        $bookingCode,
        $groupId ?: null,
        $guestId,
        $roomId,
        $checkInDate,
        $checkOutDate,
        $totalNights,
        $adultCount,
        $childrenCount,
        $roomPrice,
        $totalPrice,
        $discount,
        $finalPrice,
        $bookingSource,
        $paymentStatus,
        $paidAmount,
        $specialRequest
    ]);

    if (!$bookingStmt) {
        throw new Exception('Failed to create booking');
    }

    $bookingId = $db->getConnection()->lastInsertId();

    // ==========================================
    // Initialize variables before payment check
    // ==========================================
    $cashbookInserted = false;
    $cashbookMessage = '';
    $cashAccountName = '';
    $otaFeePercent = 0;
    $otaFeeAmount = 0;
    $netAmount = $paidAmount; // Use paidAmount for calculations

    // Create initial payment record if paid amount exists
    if ($paidAmount > 0) {
        $columnInfo = $db->fetchOne("SHOW COLUMNS FROM booking_payments LIKE 'payment_method'");
        if (!empty($columnInfo['Type']) && preg_match("/^enum\((.*)\)$/i", $columnInfo['Type'], $matches)) {
            $enumValues = array_map(function ($value) {
                return trim($value, "'\"");
            }, explode(',', $matches[1]));
            if (!in_array($paymentMethod, $enumValues, true)) {
                $paymentMethod = $enumValues[0] ?? 'cash';
            }
        }

        $paymentStmt = $db->query("
            INSERT INTO booking_payments (booking_id, amount, payment_method)
            VALUES (?, ?, ?)
        ", [$bookingId, $paidAmount, $paymentMethod]);

        if (!$paymentStmt) {
            throw new Exception('Failed to create payment record');
        }
        $newPaymentId = $db->getConnection()->lastInsertId();

        // ==========================================
        // AUTO-INSERT TO CASHBOOK SYSTEM (via Helper)
        // DIRECT: langsung masuk kas (uang sudah diterima)
        // OTA: SKIP - masuk kas saat tamu check-in (jika paid_amount sudah di-set)
        // ==========================================
        // Use $isOTABooking detected earlier (Line ~175)

        if ($isOTABooking) {
            // OTA: JANGAN masuk kas sekarang (nanti saat check-in)
            $cashbookMessage = "Booking OTA ({$originalBookingSource}) - akan masuk buku kas saat check-in";
            error_log("CREATE-RESERVATION: OTA booking detected ({$originalBookingSource}), SKIP cashbook sync - will sync at check-in. paid_amount was set to {$paidAmount}");
        } else {
            // DIRECT: langsung masuk kas karena uang sudah diterima
            try {
                // Log for debugging - include session info
                error_log("CREATE-RESERVATION: Starting cashbook sync for payment #{$newPaymentId}, booking #{$bookingId}");
                error_log("CREATE-RESERVATION: SESSION business_id=" . ($_SESSION['business_id'] ?? 'NOT SET') .
                    ", active_business_id=" . ($_SESSION['active_business_id'] ?? 'NOT SET') .
                    ", user_id=" . ($_SESSION['user_id'] ?? 'NOT SET'));
                error_log("CREATE-RESERVATION: DB_NAME=" . DB_NAME . ", ACTIVE_BUSINESS_ID=" . ACTIVE_BUSINESS_ID);

                // Get room info for description
                $roomInfo = $db->fetchOne("SELECT room_number FROM rooms WHERE id = ?", [$roomId]);
                $roomNumber = $roomInfo['room_number'] ?? '';

                // Use CashbookHelper for reliable sync
                $cashbookHelper = new CashbookHelper($db, $_SESSION['business_id'] ?? 1, $_SESSION['user_id'] ?? 1);

                error_log("CREATE-RESERVATION: CashbookHelper initialized, calling syncPaymentToCashbook...");

                $syncResult = $cashbookHelper->syncPaymentToCashbook([
                    'payment_id' => $newPaymentId,
                    'booking_id' => $bookingId,
                    'amount' => $paidAmount,
                    'payment_method' => $paymentMethod,
                    'guest_name' => $guestName,
                    'booking_code' => $bookingCode,
                    'room_number' => $roomNumber,
                    'booking_source' => $originalBookingSource, // Use original source for OTA fee
                    'final_price' => $finalPrice,
                    'total_paid' => $paidAmount,
                    'is_new_reservation' => true
                ]);

                $cashbookInserted = $syncResult['success'];
                $cashbookMessage = $syncResult['message'];
                $cashAccountName = $syncResult['account_name'];

                error_log("CREATE-RESERVATION: Sync result - success=" . ($cashbookInserted ? 'YES' : 'NO') . ", message={$cashbookMessage}");

                if ($syncResult['ota_fee']) {
                    $otaFeePercent = $syncResult['ota_fee']['fee_percent'];
                    $otaFeeAmount = $syncResult['ota_fee']['fee_amount'];
                    $netAmount = $syncResult['ota_fee']['net'];
                }
            } catch (\Throwable $cashbookError) {
                // Log error but don't fail the reservation
                $cashbookMessage = "Error mencatat ke buku kas: " . $cashbookError->getMessage();
                error_log("CREATE-RESERVATION: Cashbook auto-insert error: " . $cashbookError->getMessage() . " | File: " . $cashbookError->getFile() . " | Line: " . $cashbookError->getLine());
            }
        } // end else (direct booking)
    }

    $db->commit();

    // Prepare success message
    $successMessage = 'Reservation created successfully';
    if ($paidAmount > 0) {
        if ($isOTA) {
            $successMessage .= "\n\n💳 Booking OTA ({$originalBookingSource})";
            $successMessage .= "\nPembayaran Rp " . number_format($paidAmount, 0, ',', '.') . " tercatat";
            $successMessage .= "\n⏰ Akan masuk Buku Kas saat tamu CHECK-IN";
        } elseif ($cashbookInserted) {
            $successMessage .= "\n\n✅ Payment tercatat di Buku Kas!";
            if ($otaFeePercent > 0) {
                $successMessage .= "\nGross: Rp " . number_format($paidAmount, 0, ',', '.');
                $successMessage .= "\nOTA Fee ({$otaFeePercent}%): -Rp " . number_format($otaFeeAmount, 0, ',', '.');
                $successMessage .= "\nNet: Rp " . number_format($netAmount, 0, ',', '.') . " → {$cashAccountName}";
            } else {
                $successMessage .= "\nRp " . number_format($paidAmount, 0, ',', '.') . " → {$cashAccountName}";
            }
            if ($paidAmount >= $finalPrice) {
                $successMessage .= "\nStatus: LUNAS";
            } else {
                $remaining = $finalPrice - $paidAmount;
                $successMessage .= "\nStatus: DP (Sisa: Rp " . number_format($remaining, 0, ',', '.') . ")";
            }
        } else {
            $successMessage .= "\n\n⚠️ " . $cashbookMessage;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'booking_id' => $bookingId,
        'booking_code' => $bookingCode,
        'group_id' => $groupId ?: null,
        'cashbook_inserted' => $cashbookInserted,
        'cash_account' => $cashAccountName,
        'paid_amount' => $paidAmount,
        // DEBUG: Add OTA fee info
        'debug' => [
            'ota_fee_percent' => $otaFeePercent,
            'ota_fee_amount' => $otaFeeAmount,
            'net_amount' => $netAmount,
            'original_booking_source' => $originalBookingSource,
            'mapped_booking_source' => $bookingSource
        ]
    ]);
} catch (\Throwable $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    error_log("Create Reservation Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
exit;
