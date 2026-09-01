<?php
/**
 * API: Add Booking Payment
 * Insert payment record and update booking payment status
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/CashbookHelper.php';

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

try {
    $bookingId = $_POST['booking_id'] ?? null;
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';

    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }
    if ($amount <= 0) {
        throw new Exception('Amount must be greater than 0');
    }

    $validMethods = ['cash', 'card', 'transfer', 'qris', 'ota', 'bank_transfer', 'other', 'edc'];
    if (!in_array($paymentMethod, $validMethods, true) && strpos($paymentMethod, 'ota_') !== 0) {
        $paymentMethod = 'cash';
    }

    $booking = $db->fetchOne("SELECT id, final_price, paid_amount FROM bookings WHERE id = ?", [$bookingId]);
    if (!$booking) {
        throw new Exception('Booking not found');
    }

    $db->beginTransaction();

    // INSERT ke booking_payments - coba dengan created_at, fallback tanpa
    try {
        $db->query("INSERT INTO booking_payments (booking_id, amount, payment_method, processed_by, payment_date, created_at) VALUES (?, ?, ?, ?, NOW(), NOW())", [
            $bookingId, $amount, $paymentMethod, $currentUser['id']
        ]);
    } catch (\Throwable $e) {
        // Fallback: kolom created_at mungkin tidak ada
        try {
            $db->query("INSERT INTO booking_payments (booking_id, amount, payment_method, processed_by, payment_date) VALUES (?, ?, ?, ?, NOW())", [
                $bookingId, $amount, $paymentMethod, $currentUser['id']
            ]);
        } catch (\Throwable $e2) {
            // booking_payments tidak ada - lanjutkan, update langsung di bookings saja
            error_log("booking_payments insert failed: " . $e2->getMessage());
        }
    }

    // Hitung total dari booking_payments + paid_amount yang sudah ada
    $payment = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as paid FROM booking_payments WHERE booking_id = ?", [$bookingId]);
    $bpTotal  = (float)($payment['paid'] ?? 0);
    // Gunakan nilai terbesar antara booking_payments sum dan paid_amount lama + amount baru
    $totalPaid = max($bpTotal, (float)$booking['paid_amount'] + $amount);
    $remaining = max(0, (float)$booking['final_price'] - $totalPaid);

    if ($totalPaid <= 0) {
        $paymentStatus = 'unpaid';
    } elseif ($remaining <= 0) {
        $paymentStatus = 'paid';
    } else {
        $paymentStatus = 'partial';
    }

    // Selalu update langsung ke bookings - ini yang dibaca oleh Pay button
    $db->query("UPDATE bookings SET paid_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?", [
        $totalPaid,
        $paymentStatus,
        $bookingId
    ]);

    // ==========================================
    // AUTO-INSERT TO CASHBOOK SYSTEM (via Helper)
    // ONLY for DIRECT BOOKING - OTA akan tercatat saat check-in
    // ==========================================
    $cashbookInserted = false;
    $cashbookMessage = '';
    $cashAccountName = '';
    
    // Initialize OTA fee variables
    $otaFeePercent = 0;
    $otaFeeAmount = 0;
    $netAmount = $amount;
    
    try {
        // Get booking details for description
        $bookingDetails = $db->fetchOne("
            SELECT b.booking_code, b.booking_source, b.final_price, 
                   g.guest_name, r.room_number
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.id = ?
        ", [$bookingId]);
        
        // Use booking_sources table (source_type) for reliable OTA detection
        $isOTA = false;
        $sourceInfo = null;
        try {
            $sourceInfo = $db->fetchOne("SELECT source_type FROM booking_sources WHERE source_key = ? AND is_active = 1", [$bookingDetails['booking_source']]);
            if ($sourceInfo) {
                $isOTA = ($sourceInfo['source_type'] ?? '') !== 'direct';
            }
        } catch (\Throwable $e) {
            // Table might not exist, fall through to hardcoded detection
        }
        
        // Fallback: hardcoded detection if not found in booking_sources table
        if (!$isOTA && !$sourceInfo) {
            $normalizedSource = strtolower(trim($bookingDetails['booking_source'] ?? ''));
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
        
        // Direct booking: langsung masuk buku kas (uang sudah diterima)
        // OTA booking: masuk buku kas saat check-in (uang dari platform belum cair)
        if ($isOTA) {
            $cashbookMessage = "Booking OTA - akan tercatat di buku kas saat check-in";
        } else {
            // DIRECT: langsung sync ke buku kas karena uang sudah diterima
            require_once '../includes/CashbookHelper.php';
            $businessId = $_SESSION['business_id'] ?? $db->fetchOne("SELECT business_id FROM users WHERE id = ?", [$currentUser['id']])['business_id'] ?? 1;
            $cashbookHelper = new CashbookHelper($db, $businessId, $currentUser['id'] ?? 1);
            
            $syncResult = $cashbookHelper->syncPaymentToCashbook([
                'payment_id'     => null,
                'booking_id'     => $bookingId,
                'amount'         => $amount,
                'payment_method' => $paymentMethod,
                'guest_name'     => $bookingDetails['guest_name'] ?? 'Guest',
                'booking_code'   => $bookingDetails['booking_code'] ?? '',
                'room_number'    => $bookingDetails['room_number'] ?? '',
                'booking_source' => $bookingDetails['booking_source'] ?? 'direct',
                'final_price'    => $bookingDetails['final_price'] ?? 0,
                'total_paid'     => $totalPaid,
                'is_new_reservation' => false,
                'is_ota_checkin' => false
            ]);
            
            if ($syncResult['success']) {
                $cashbookInserted = true;
                $cashAccountName = $syncResult['account_name'] ?? '';
                $cashbookMessage = "Tercatat di buku kas: " . $cashAccountName;
                
                // Mark payment as synced
                if (!empty($syncResult['transaction_id'])) {
                    try {
                        $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE booking_id = ? ORDER BY id DESC LIMIT 1", 
                            [$syncResult['transaction_id'], $bookingId]);
                    } catch (\Throwable $e) {}
                }
            } else {
                $cashbookMessage = "Pembayaran tersimpan, gagal sync kas: " . ($syncResult['message'] ?? 'Unknown');
                error_log("Direct payment cashbook sync failed: " . ($syncResult['message'] ?? 'Unknown'));
            }
        }
        
    } catch (\Throwable $cashbookError) {
        // Log error but don't fail the payment
        $cashbookMessage = "Error mencatat ke buku kas: " . $cashbookError->getMessage();
        error_log("Cashbook auto-insert error: " . $cashbookError->getMessage());
    }

    $db->commit();
    
    // Prepare success message
    $statusLabel = $paymentStatus === 'paid' ? 'LUNAS ✅' : 'PARTIAL - Sisa: Rp ' . number_format($remaining, 0, ',', '.');
    $successMessage = "Pembayaran tersimpan ✅";
    $successMessage .= "\nRp " . number_format($amount, 0, ',', '.') . " dicatat untuk booking " . ($bookingDetails['booking_code'] ?? '');
    
    if ($isOTA) {
        $successMessage .= "\n\n⏰ OTA - Akan masuk Buku Kas saat CHECK-IN";
    } elseif ($cashbookInserted) {
        $successMessage .= "\n\n💰 Langsung tercatat di Buku Kas";
    } else {
        $successMessage .= "\n\n⚠️ Pembayaran tersimpan, gagal sync ke buku kas";
    }
    $successMessage .= "\nStatus: " . $statusLabel;

    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'total_paid' => $totalPaid,
        'remaining' => $remaining,
        'payment_status' => $paymentStatus,
        'cashbook_inserted' => $cashbookInserted,
        'cashbook_at_checkin' => $isOTA,
        'is_ota' => $isOTA
    ]);

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

ob_end_flush();
