<?php

/**
 * API: PAY DRIVER TRIP
 * POST /api/pay-driver-trip.php
 *
 * Marks a single trip (car rental / airport drop / harbor drop) as paid to
 * the driver/partner, and auto-syncs the expense to the cashbook - mirrors
 * the flow in pay-monthly-bill.php but operates on individual trip rows
 * instead of monthly_bills.
 * Used by the "Tagihan Driver" tab in modules/bills/index.php.
 *
 * POST data:
 * - trip_id: hotel_invoice_items.id (both source='trip' and 'legacy' — see
 *   api/get-driver-recap.php, which always selects "hii.id AS trip_id")
 * - source_type: car_rental | airport_drop | harbor_drop | narayana_trip
 * - payment_method: cash, transfer, card, other
 * - cash_account_id: Dari rekening mana (FK cash_accounts.id)
 * - driver_name: label used in the cashbook description
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/CashbookHelper.php';
require_once '../includes/DriverPaymentHelper.php';

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();
$businessId = $_SESSION['business_id'] ?? 1;

try {
    ensureDriverTripPaymentColumns($pdo);

    $db->beginTransaction();

    $tripId = (int)($_POST['trip_id'] ?? 0);
    $sourceType = trim($_POST['source_type'] ?? '');
    $source = trim($_POST['source'] ?? 'trip'); // both keyed by hotel_invoice_items.id; 'trip' also syncs a linked rental_car_bookings row, 'legacy' has none
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $cashAccountId = (int)($_POST['cash_account_id'] ?? 0);
    $driverName = trim($_POST['driver_name'] ?? 'Driver');

    if (!$tripId || !in_array($sourceType, ['car_rental', 'airport_drop', 'harbor_drop', 'narayana_trip'], true)) {
        throw new Exception('Data trip tidak valid');
    }

    if ($source !== 'legacy') {
        // trip_id is hotel_invoice_items.id (see api/get-driver-recap.php, which always
        // selects "hii.id AS trip_id") — NOT rental_car_bookings.id. Verify via hi join,
        // and LEFT JOIN the linked booking/car only for the car label + later sync.
        $trip = $db->fetchOne(
            "SELECT hii.id, hii.owner_amount, hii.driver_paid, hii.description AS trip_destination,
                    hi.business_id, hi.guest_name,
                    cb.invoice_id AS cb_invoice_id, cb.service_type AS cb_service_type,
                    rc.car_name, rc.plate_number
             FROM hotel_invoice_items hii
             JOIN hotel_invoices hi ON hii.invoice_id = hi.id
             LEFT JOIN rental_car_bookings cb ON cb.invoice_id = hii.invoice_id AND cb.service_type = hii.service_type AND cb.business_id = hi.business_id
             LEFT JOIN rental_cars rc ON rc.id = cb.car_id
             WHERE hii.id = ? AND hii.service_type = ? LIMIT 1",
            [$tripId, $sourceType]
        );
        if (!$trip) throw new Exception('Trip tidak ditemukan');
        if ((int)$trip['business_id'] !== (int)$businessId) throw new Exception('Trip tidak ditemukan untuk bisnis ini');
        if ((int)$trip['driver_paid'] === 1) throw new Exception('Trip ini sudah dibayar sebelumnya');

        $amount = (float)$trip['owner_amount'];
        $carLabel = trim(($trip['car_name'] ?? '') . ' (' . ($trip['plate_number'] ?? '') . ')');
        $serviceLabel = $sourceType === 'car_rental' ? 'Rental Mobil' : ($sourceType === 'airport_drop' ? 'Airport Drop' : 'Harbor Drop');
        $label = $sourceType === 'car_rental' ? "{$serviceLabel} {$carLabel}" : "{$serviceLabel}" . ($trip['trip_destination'] ? " - {$trip['trip_destination']}" : " - {$carLabel}");
        $guestLabel = $trip['guest_name'] ? " - {$trip['guest_name']}" : '';
        $updateSql = "UPDATE hotel_invoice_items SET driver_paid = 1, driver_paid_at = NOW(), driver_paid_cashbook_id = ? WHERE id = ?";
        $syncBookingInvoiceId = $trip['cb_invoice_id'] ?? null;
        $syncBookingServiceType = $trip['cb_service_type'] ?? null;
    } else {
        // Legacy airport/harbor drop trips logged before a driver car was linked - live in hotel_invoice_items
        $trip = $db->fetchOne(
            "SELECT hii.id, hi.business_id, hii.total_price, hii.owner_amount, hii.hotel_commission, hii.description, hii.service_type, hii.driver_paid,
                    hi.guest_name
             FROM hotel_invoice_items hii
             JOIN hotel_invoices hi ON hii.invoice_id = hi.id
             WHERE hii.id = ? AND hii.service_type = ? LIMIT 1",
            [$tripId, $sourceType]
        );
        if (!$trip) throw new Exception('Trip drop tidak ditemukan');
        if ((int)$trip['business_id'] !== (int)$businessId) throw new Exception('Trip tidak ditemukan untuk bisnis ini');
        if ((int)$trip['driver_paid'] === 1) throw new Exception('Trip ini sudah dibayar sebelumnya');

        $ownerAmount = (float)($trip['owner_amount'] ?? 0);
        $hotelCommission = (float)($trip['hotel_commission'] ?? 0);
        // Backward-compatible fallback for old rows before owner split existed.
        $amount = ($ownerAmount > 0 || $hotelCommission > 0)
            ? $ownerAmount
            : (float)$trip['total_price'];
        $label = ($sourceType === 'airport_drop'
            ? 'Airport Drop'
            : ($sourceType === 'harbor_drop' ? 'Harbor Drop' : 'Narayana Trip')) .
            ($trip['description'] ? " - {$trip['description']}" : '');
        $guestLabel = $trip['guest_name'] ? " - {$trip['guest_name']}" : '';
        $updateSql = "UPDATE hotel_invoice_items SET driver_paid = 1, driver_paid_at = NOW(), driver_paid_cashbook_id = ? WHERE id = ?";
    }

    if ($amount <= 0) {
        throw new Exception('Jumlah pembayaran tidak valid');
    }

    // ======================================
    // AUTO-SYNC TO CASHBOOK (same pattern as pay-monthly-bill.php)
    // ======================================
    $cbHelper = new CashbookHelper($db, $currentUser['id']);

    $divisionId = getRentCarDivisionId($pdo);
    $categoryId = getDriverPaymentCategoryId($pdo);

    $accountId = $cashAccountId;
    if (!$accountId) {
        $account = $cbHelper->getCashAccount($paymentMethod);
        $accountId = $account['id'] ?? 1;
    }

    $cbDescription = "Bayar Driver {$driverName} - {$label}{$guestLabel} [LUNAS]";

    $cbResult = $db->query(
        "INSERT INTO cash_book
        (division_id, category_id, transaction_type, transaction_date, transaction_time, amount, description, payment_method, cash_account_id, is_editable, created_by)
        VALUES (?, ?, 'expense', DATE(NOW()), TIME(NOW()), ?, ?, ?, ?, 1, ?)",
        [
            $divisionId,
            $categoryId,
            $amount,
            $cbDescription,
            $paymentMethod,
            $accountId,
            $currentUser['id']
        ]
    );

    if (!$cbResult) {
        throw new Exception('Failed to sync to cashbook');
    }

    $cashbookId = $db->getConnection()->lastInsertId();

    $db->query($updateSql, [$cashbookId, $tripId]);

    // Best-effort: keep the linked rental_car_bookings row (Rental Mobil owner
    // dashboard) in sync — matched via invoice_id + service_type, not by id.
    if ($source !== 'legacy' && !empty($syncBookingInvoiceId) && !empty($syncBookingServiceType)) {
        try {
            $pdo->prepare(
                "UPDATE rental_car_bookings
                 SET driver_paid = 1, driver_paid_at = NOW(), driver_paid_cashbook_id = ?
                 WHERE invoice_id = ? AND service_type = ? AND business_id = ?"
            )->execute([$cashbookId, $syncBookingInvoiceId, $syncBookingServiceType, $businessId]);
        } catch (Throwable $syncErr) {
            error_log('pay-driver-trip rental_car_bookings sync failed: ' . $syncErr->getMessage());
        }
    }

    // ======================================
    // SYNC TO MASTER CASH ACCOUNT LEDGER
    // Ensure operational account mutasi + balance move together with expense record
    // ======================================
    $masterSyncWarning = null;
    $masterDb = null;
    try {
        $masterBusinessId = getMasterBusinessId();
        $masterDb = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $masterDb->beginTransaction();

        ensureCashAccountTransactionsSchema($masterDb);

        $accStmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE id = ? AND business_id = ? AND is_active = 1 LIMIT 1");
        $accStmt->execute([$accountId, $masterBusinessId]);
        $account = $accStmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception('Rekening operasional tidak valid untuk bisnis ini');
        }

        $trxStmt = $masterDb->prepare("
            INSERT INTO cash_account_transactions
            (cash_account_id, transaction_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at)
            VALUES (?, ?, DATE(NOW()), ?, ?, 'expense', ?, ?, NOW())
        ");
        $trxStmt->execute([
            $accountId,
            $cashbookId,
            $cbDescription,
            $amount,
            'DRV-' . $sourceType . '-' . $tripId,
            $currentUser['id']
        ]);

        $balStmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
        $balStmt->execute([$amount, $accountId]);
        $masterDb->commit();
    } catch (Exception $masterEx) {
        if ($masterDb instanceof PDO && $masterDb->inTransaction()) {
            $masterDb->rollBack();
        }
        // Do not block trip payment status when optional master-ledger sync fails.
        $masterSyncWarning = $masterEx->getMessage();
        error_log('pay-driver-trip master sync warning: ' . $masterSyncWarning);
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "Pembayaran Rp " . number_format($amount, 0, ',', '.') . " ke {$driverName} berhasil dicatat",
        'amount' => $amount,
        'cashbook_id' => $cashbookId,
        'master_sync_warning' => $masterSyncWarning
    ]);
} catch (Exception $e) {
    try {
        $db->rollBack();
    } catch (Exception $ignore) {
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
