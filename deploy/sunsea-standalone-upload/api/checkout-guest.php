<?php

/**
 * API: Check-out Guest
 * Update booking status to 'checked_out' and record check-out time
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

try {
    // Get booking ID from request
    $bookingId = $_POST['booking_id'] ?? null;

    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }

    // Get booking details
    $booking = $db->fetchOne("
        SELECT b.*, g.guest_name, r.room_number 
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ", [$bookingId]);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // Check if checked in
    if ($booking['status'] !== 'checked_in') {
        throw new Exception('Guest belum check-in, tidak bisa check-out');
    }

    // ==========================================
    // VALIDASI: Tagihan HARUS LUNAS untuk checkout
    // ==========================================
    $finalPrice = (float)($booking['final_price'] ?? 0);
    $pdo = $db->getConnection();
    $businessId = $_SESSION['business_id'] ?? 1;

    // ── Cek rental MOTOR aktif ─────────────────────────────────────────────
    $blockingIssues = [];
    try {
        $motorCheck = $pdo->prepare("
            SELECT rb.id, rb.guest_name, rm.plate_number, rm.motor_name, rb.start_datetime, rb.status
            FROM rental_motor_bookings rb
            JOIN rental_motors rm ON rb.motor_id = rm.id
            WHERE rb.business_id = ? AND rb.booking_id = ? AND rb.status IN ('active','overdue')
        ");
        $motorCheck->execute([$businessId, $bookingId]);
        $activeMotors = $motorCheck->fetchAll(PDO::FETCH_ASSOC);
        foreach ($activeMotors as $m) {
            $blockingIssues[] = "🏍️ Motor " . $m['plate_number'] . " (" . $m['motor_name'] . ") belum dikembalikan [" . $m['status'] . "]";
        }
    } catch (\Throwable $e) { /* table may not exist */
    }

    // ── Cek rental MOBIL/TAXI aktif ────────────────────────────────────────
    try {
        $carCheck = $pdo->prepare("
            SELECT cb.id, cb.guest_name, rc.plate_number, rc.car_name, cb.start_datetime, cb.status
            FROM rental_car_bookings cb
            JOIN rental_cars rc ON cb.car_id = rc.id
            WHERE cb.business_id = ? AND cb.booking_id = ? AND cb.status IN ('active','overdue')
        ");
        $carCheck->execute([$businessId, $bookingId]);
        $activeCars = $carCheck->fetchAll(PDO::FETCH_ASSOC);
        foreach ($activeCars as $c) {
            $blockingIssues[] = "🚗 Mobil/Taxi " . $c['plate_number'] . " (" . $c['car_name'] . ") belum dikembalikan [" . $c['status'] . "]";
        }
    } catch (\Throwable $e) { /* table may not exist */
    }

    if (!empty($blockingIssues)) {
        echo json_encode([
            'success'         => false,
            'type'            => 'rental_pending',
            'message'         => "Tidak bisa check-out! Ada rental yang belum diselesaikan:\n\n" .
                implode("\n", $blockingIssues) .
                "\n\nSilakan kembalikan kendaraan dan selesaikan pembayaran terlebih dahulu.",
            'blocking_issues' => $blockingIssues,
        ]);
        exit;
    }

    // If payment_status already marked as 'paid', skip amount validation
    if ($booking['payment_status'] !== 'paid') {
        $totalPaid = $db->fetchOne("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM booking_payments 
            WHERE booking_id = ?
        ", [$bookingId]);
        $bpTotal = (float)($totalPaid['total'] ?? 0);

        // Use max of booking_payments sum and bookings.paid_amount (consistent with add-booking-payment logic)
        $paidAmount = max($bpTotal, (float)($booking['paid_amount'] ?? 0));

        $remainingBalance = $finalPrice - $paidAmount;

        // Toleransi 1000 rupiah untuk pembulatan
        if ($remainingBalance > 1000) {
            echo json_encode([
                'success' => false,
                'type'    => 'unpaid',
                'message' => "Tidak bisa check-out! Tagihan belum LUNAS.\n\nTotal Tagihan: Rp " . number_format($finalPrice, 0, ',', '.') .
                    "\nSudah Dibayar: Rp " . number_format($paidAmount, 0, ',', '.') .
                    "\nKekurangan: Rp " . number_format($remainingBalance, 0, ',', '.') .
                    "\n\nSilakan selesaikan pembayaran terlebih dahulu.",
                'remaining_balance' => $remainingBalance,
                'final_price' => $finalPrice,
                'paid_amount' => $paidAmount
            ]);
            exit;
        }
    }

    // Start transaction
    $db->beginTransaction();

    // Update booking status to checked_out
    $db->query("
        UPDATE bookings 
        SET status = 'checked_out',
            actual_checkout_time = NOW(),
            checked_out_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ", [$currentUser['id'], $bookingId]);

    // Update room status to cleaning (kamar kotor / perlu dibersihkan setelah checkout)
    // Pastikan ENUM 'cleaning' tersedia pada kolom rooms.status (idempotent)
    try {
        $col = $db->fetchOne("SHOW COLUMNS FROM rooms LIKE 'status'");
        if ($col && isset($col['Type']) && stripos($col['Type'], "'cleaning'") === false) {
            $db->query("ALTER TABLE rooms MODIFY status enum('available','occupied','cleaning','maintenance','blocked') DEFAULT 'available'");
        }
    } catch (\Throwable $eAlt) {
        // ignore; fallback to 'available' below akan dipakai jika UPDATE gagal
    }

    try {
        $db->query("
            UPDATE rooms 
            SET status = 'cleaning',
                current_guest_id = NULL,
                updated_at = NOW()
            WHERE id = ?
        ", [$booking['room_id']]);
    } catch (\Throwable $eUpd) {
        // Fallback bila ENUM tidak menerima 'cleaning' (misalnya hosting belum migrasi)
        $db->query("
            UPDATE rooms 
            SET status = 'available',
                current_guest_id = NULL,
                updated_at = NOW()
            WHERE id = ?
        ", [$booking['room_id']]);
    }

    // Log activity
    $db->query("
        INSERT INTO activity_logs (user_id, action, description, created_at)
        VALUES (?, ?, ?, NOW())
    ", [
        $currentUser['id'],
        'check_out',
        "Check-out guest: {$booking['guest_name']} - Room {$booking['room_number']} - Booking #{$booking['booking_code']}"
    ]);

    $db->commit();

    // ==========================================
    // AUTO-SYNC UNSYNC'D PAYMENTS TO CASHBOOK
    // ==========================================
    $cashbookMsg = '';
    try {
        // Get master database name - Smart Detection for Hosting
        $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
        $masterDb = null;

        try {
            $masterDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $mErr) {
            // Fallback: Use current DB as master for Single-DB Hosting
            if (defined('DB_NAME')) {
                try {
                    $masterDb = new PDO(
                        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                        DB_USER,
                        DB_PASS,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                } catch (\Throwable $e2) {
                    $masterDb = $db->getConnection();
                }
            } else {
                $masterDb = $db->getConnection();
            }
        }
        $businessId = $_SESSION['business_id'] ?? 1;

        // Validate created_by user exists in business DB (login uses master DB)
        $cbUserId = $currentUser['id'] ?? 1;
        $userExists = $db->fetchOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$cbUserId]);
        if (!$userExists) {
            $firstUser = $db->fetchOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
            $cbUserId = $firstUser['id'] ?? 1;
        }

        // Check if synced_to_cashbook column exists
        $hasSyncCol = false;
        try {
            $syncColChk = $db->getConnection()->query("SHOW COLUMNS FROM booking_payments LIKE 'synced_to_cashbook'");
            $hasSyncCol = $syncColChk && $syncColChk->rowCount() > 0;
        } catch (\Throwable $e) {
        }

        // Get payments to sync
        if ($hasSyncCol) {
            $payments = $db->fetchAll("
                SELECT id, amount, payment_method, payment_date 
                FROM booking_payments WHERE booking_id = ? AND synced_to_cashbook = 0 ORDER BY id
            ", [$bookingId]);
        } else {
            // Fallback: get all payments and dedup in loop
            $payments = $db->fetchAll("
                SELECT id, amount, payment_method, payment_date 
                FROM booking_payments WHERE booking_id = ? ORDER BY id
            ", [$bookingId]);
        }

        $syncCount = 0;
        foreach ($payments as $pmt) {
            try {
                // Payment-level dedup: check by booking_code AND amount to allow multiple payments
                $exists = $db->fetchOne(
                    "SELECT id FROM cash_book WHERE description LIKE ? AND ABS(amount - ?) < 1 AND transaction_type = 'income' LIMIT 1",
                    ['%' . $booking['booking_code'] . '%', $pmt['amount']]
                );
                if ($exists) {
                    // Mark as synced if possible
                    if ($hasSyncCol) {
                        try {
                            $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", [$exists['id'], $pmt['id']]);
                        } catch (\Throwable $e) {
                        }
                    }
                    continue;
                }

                // Calculate net amount using booking_source with per-OTA fee rates
                $netAmt = (float)$pmt['amount'];
                $bookingSrc = strtolower(trim($booking['booking_source'] ?? ''));
                $normalizedSrc = str_replace(['.com', '.co.id', '.id'], '', $bookingSrc);
                $normalizedSrc = preg_replace('/[^a-z0-9]/', '', $normalizedSrc);
                $otaFeeMap = [
                    'agoda' => 'ota_fee_agoda',
                    'booking' => 'ota_fee_booking_com',
                    'bookingcom' => 'ota_fee_booking_com',
                    'tiket' => 'ota_fee_tiket_com',
                    'tiketcom' => 'ota_fee_tiket_com',
                    'airbnb' => 'ota_fee_airbnb',
                    'traveloka' => 'ota_fee_traveloka',
                    'expedia' => 'ota_fee_expedia',
                    'pegipegi' => 'ota_fee_other_ota',
                    'ota' => 'ota_fee_other_ota'
                ];
                $feeSettingKey = null;
                foreach ($otaFeeMap as $otaKey => $settingKey) {
                    if (strpos($normalizedSrc, $otaKey) !== false || $normalizedSrc === $otaKey) {
                        $feeSettingKey = $settingKey;
                        break;
                    }
                }
                if ($feeSettingKey) {
                    $feeStmt = $masterDb->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                    $feeStmt->execute([$feeSettingKey]);
                    $feeQ = $feeStmt->fetch(PDO::FETCH_ASSOC);
                    if ($feeQ && (float)$feeQ['setting_value'] > 0) {
                        $netAmt = $pmt['amount'] - ($pmt['amount'] * (float)$feeQ['setting_value'] / 100);
                    }
                }

                // Find cash account
                $acctType = ($pmt['payment_method'] === 'cash') ? 'cash' : 'bank';
                $acctStmt = $masterDb->prepare("SELECT id, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = ? AND is_active = 1 ORDER BY is_default_account DESC LIMIT 1");
                $acctStmt->execute([$businessId, $acctType]);
                $acct = $acctStmt->fetch(PDO::FETCH_ASSOC);

                // FALLBACK: If no specific account type found, get ANY active account
                if (!$acct) {
                    $fallbackStmt = $masterDb->prepare("SELECT id, current_balance FROM cash_accounts WHERE business_id = ? AND is_active = 1 ORDER BY is_default_account DESC LIMIT 1");
                    $fallbackStmt->execute([$businessId]);
                    $acct = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                }
                if (!$acct) continue;

                // Division & Category
                $div = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR LOWER(division_name) LIKE '%front%' OR LOWER(division_name) LIKE '%room%' OR LOWER(division_name) LIKE '%kamar%' ORDER BY id LIMIT 1");
                if (!$div) $div = $db->fetchOne("SELECT id FROM divisions ORDER BY id LIMIT 1");
                $cat = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' AND (LOWER(category_name) LIKE '%room%' OR LOWER(category_name) LIKE '%kamar%') ORDER BY id LIMIT 1");
                if (!$cat) $cat = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id LIMIT 1");

                $desc = "Pembayaran Reservasi - {$booking['guest_name']} (Room {$booking['room_number']}) - {$booking['booking_code']} [CHECKOUT-SYNC]";

                // Map payment_method to valid ENUM values
                $pmMap = ['bank_transfer' => 'transfer', 'credit_card' => 'debit', 'credit' => 'debit'];
                $cbMethod = strtolower($pmt['payment_method'] ?? 'cash');
                $cbMethod = $pmMap[$cbMethod] ?? $cbMethod;
                // Detect ENUM and ensure value is allowed
                if (!isset($allowedPaymentMethods)) {
                    $allowedPaymentMethods = null;
                    try {
                        $pmColInfo = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
                        if ($pmColInfo && strpos($pmColInfo['Type'], 'enum') === 0) {
                            preg_match_all("/'([^']+)'/", $pmColInfo['Type'], $enumMatches);
                            $allowedPaymentMethods = $enumMatches[1] ?? ['cash'];
                        }
                    } catch (\Throwable $e) {
                    }
                }
                if ($allowedPaymentMethods !== null && !in_array($cbMethod, $allowedPaymentMethods)) {
                    $cbMethod = in_array('other', $allowedPaymentMethods) ? 'other' : (in_array('cash', $allowedPaymentMethods) ? 'cash' : $allowedPaymentMethods[0]);
                }

                // Check if cash_account_id column exists
                if (!isset($hasCashAccountId)) {
                    $hasCashAccountId = false;
                    try {
                        $colChk = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
                        $hasCashAccountId = $colChk && $colChk->rowCount() > 0;
                    } catch (\Throwable $e) {
                    }
                }

                if ($hasCashAccountId) {
                    $cashBookInsert = $db->getConnection()->prepare("INSERT INTO cash_book (transaction_date, transaction_time, division_id, category_id, description, transaction_type, amount, payment_method, cash_account_id, created_by, created_at) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, ?, NOW())");
                    $cashBookInsert->execute([
                        $pmt['payment_date'],
                        $pmt['payment_date'],
                        $div['id'] ?? 1,
                        $cat['id'] ?? 1,
                        $desc,
                        $netAmt,
                        $cbMethod,
                        $acct['id'],
                        $cbUserId
                    ]);
                } else {
                    $cashBookInsert = $db->getConnection()->prepare("INSERT INTO cash_book (transaction_date, transaction_time, division_id, category_id, description, transaction_type, amount, payment_method, created_by, created_at) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, NOW())");
                    $cashBookInsert->execute([
                        $pmt['payment_date'],
                        $pmt['payment_date'],
                        $div['id'] ?? 1,
                        $cat['id'] ?? 1,
                        $desc,
                        $netAmt,
                        $cbMethod,
                        $cbUserId
                    ]);
                }

                $txId = $db->getConnection()->lastInsertId();

                // Mark payment as synced (only if column exists)
                if ($hasSyncCol) {
                    try {
                        $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", [$txId, $pmt['id']]);
                    } catch (\Throwable $e) {
                    }
                }

                try {
                    // SMART FIX: Check if transaction_id column exists
                    $hasTransIdCol = false;
                    try {
                        $chk = $masterDb->query("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_id'");
                        $hasTransIdCol = $chk && $chk->rowCount() > 0;
                    } catch (\Throwable $e) {
                    }

                    if ($hasTransIdCol) {
                        $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at) VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())")->execute([
                            $acct['id'],
                            $txId,
                            $pmt['payment_date'],
                            $desc,
                            $netAmt,
                            $booking['booking_code'],
                            $cbUserId
                        ]);
                    } else {
                        $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at) VALUES (?, DATE(?), ?, ?, 'income', ?, ?, NOW())")->execute([
                            $acct['id'],
                            $pmt['payment_date'],
                            $desc,
                            $netAmt,
                            $booking['booking_code'],
                            $cbUserId
                        ]);
                    }

                    $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$netAmt, $acct['id']]);
                } catch (\Throwable $masterErr) {
                    error_log("Checkout master sync error: " . $masterErr->getMessage());
                }
                $syncCount++;
            } catch (\Throwable $pmtErr) {
                error_log("Checkout sync error payment#{$pmt['id']}: " . $pmtErr->getMessage());
                continue;
            }
        }
        if ($syncCount > 0) {
            $cashbookMsg = " | {$syncCount} pembayaran di-sync ke Buku Kas";
        }
    } catch (\Throwable $cbErr) {
        error_log("Checkout cashbook sync error: " . $cbErr->getMessage());
    }

    // ═══ PUSH NOTIFICATION: Check-Out ═══
    try {
        require_once dirname(dirname(__FILE__)) . '/includes/PushNotificationHelper.php';
        $pushHelper = new PushNotificationHelper($db);
        $pushHelper->sendToAdmins(
            '🚪 Check-Out: ' . $booking['guest_name'],
            'Room ' . $booking['room_number'] . ' - ' . $booking['guest_name'] . ' telah check-out',
            [
                'type' => 'check_out',
                'tag'  => 'checkout-' . $bookingId,
                'url'  => '/modules/frontdesk/index.php',
                'booking_id' => $bookingId,
                'guest_name' => $booking['guest_name'],
                'room_number' => $booking['room_number']
            ]
        );
    } catch (\Throwable $pushErr) {
        error_log('Push notification error (check-out): ' . $pushErr->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => "Check-out berhasil! {$booking['guest_name']} - Room {$booking['room_number']}" . $cashbookMsg,
        'booking_id' => $bookingId,
        'guest_name' => $booking['guest_name'],
        'room_number' => $booking['room_number']
    ]);
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log("Check-out Error: " . $e->getMessage());

    // Clean output buffer before sending JSON
    ob_clean();

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Flush output buffer
ob_end_flush();
