<?php

/**
 * DRIVER / PARTNER VEHICLE PAYMENT HELPER
 * ----------------------------------------------------------------------
 * Shared logic for Hotel Service transactions (car_rental, airport_drop,
 * harbor_drop) that involve a partner-owned vehicle/driver (e.g. rental_cars
 * with partner_owner set). When flagged, the driver's share of the trip
 * price is automatically turned into a Tagihan (monthly_bills) entry once
 * the related hotel invoice has been processed/paid.
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

/**
 * Ensure the DB columns/tables needed for driver payment tracking exist.
 * Safe to call on every request (checks column existence before altering).
 */
function ensureDriverPaymentSchema(PDO $pdo): void
{
    // rental_cars: how the hotel's cut is calculated by default for this vehicle
    try {
        $pdo->query("SELECT commission_type FROM rental_cars LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE rental_cars
                ADD COLUMN commission_type ENUM('percent','nominal') NOT NULL DEFAULT 'percent' AFTER owner_commission_pct,
                ADD COLUMN commission_nominal DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'potongan nominal hotel jika commission_type=nominal' AFTER commission_type");
        } catch (Exception $e2) {
            error_log('ensureDriverPaymentSchema rental_cars: ' . $e2->getMessage());
        }
    }

    // rental_cars: driver daily rate (harga bayar ke driver per hari/trip)
    try {
        $pdo->query("SELECT driver_daily_rate FROM rental_cars LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE rental_cars ADD COLUMN driver_daily_rate DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'harga bayar ke driver per hari/trip' AFTER daily_rate");
        } catch (Exception $e2) {
            error_log('ensureDriverPaymentSchema driver_daily_rate: ' . $e2->getMessage());
        }
    }

    // rental_car_bookings: which service produced it + driver-payment flag/snapshot
    try {
        $pdo->query("SELECT needs_driver_payment FROM rental_car_bookings LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE rental_car_bookings
                ADD COLUMN service_type VARCHAR(30) NOT NULL DEFAULT 'car_rental' AFTER car_id,
                ADD COLUMN needs_driver_payment TINYINT(1) NOT NULL DEFAULT 0 AFTER hotel_commission,
                ADD COLUMN commission_type ENUM('percent','nominal') NOT NULL DEFAULT 'percent' AFTER needs_driver_payment,
                ADD COLUMN commission_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER commission_type,
                ADD COLUMN billed_to_tagihan TINYINT(1) NOT NULL DEFAULT 0 AFTER commission_value");
        } catch (Exception $e2) {
            error_log('ensureDriverPaymentSchema rental_car_bookings: ' . $e2->getMessage());
        }
    }

    // monthly_bills: must exist (Tagihan module) + traceability columns
    $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_bills (
        id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        bill_code varchar(20) NOT NULL UNIQUE,
        division_id int(11) DEFAULT NULL,
        category_id int(11) DEFAULT NULL,
        bill_name varchar(100) NOT NULL,
        bill_month date NOT NULL,
        amount decimal(12,2) NOT NULL,
        due_date date DEFAULT NULL,
        status enum('pending','partial','paid','cancelled') DEFAULT 'pending',
        paid_amount decimal(12,2) DEFAULT 0.00,
        payment_method varchar(50) DEFAULT NULL,
        cash_account_id_source int(11) DEFAULT NULL,
        notes text,
        is_recurring tinyint(1) DEFAULT 0,
        created_by int(11),
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_bill_month (bill_month),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // bill_payments must also exist — monthly_bills listing/report queries LEFT JOIN it,
    // so creating monthly_bills alone (without this) would break the Tagihan list.
    $pdo->exec("CREATE TABLE IF NOT EXISTS bill_payments (
        id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        bill_id int(11) NOT NULL,
        payment_date datetime NOT NULL,
        amount decimal(12,2) NOT NULL,
        payment_method varchar(50) DEFAULT NULL,
        cash_account_id int(11) DEFAULT NULL,
        reference_number varchar(50) DEFAULT NULL,
        synced_to_cashbook tinyint(1) DEFAULT 0,
        cashbook_id int(11) DEFAULT NULL,
        notes text,
        created_by int(11),
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bill_id (bill_id),
        KEY idx_synced_to_cashbook (synced_to_cashbook),
        KEY idx_payment_date (payment_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $pdo->query("SELECT source_type FROM monthly_bills LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE monthly_bills
                ADD COLUMN source_type VARCHAR(30) DEFAULT NULL,
                ADD COLUMN source_ref_id INT DEFAULT NULL");
        } catch (Exception $e2) {
            error_log('ensureDriverPaymentSchema monthly_bills: ' . $e2->getMessage());
        }
    }
}

/**
 * Split a trip's total price between the driver/partner and the hotel.
 * - 'percent': driver gets {$commissionValue}% of total, hotel keeps the rest.
 * - 'nominal': hotel keeps a flat Rp {$commissionValue} cut, driver gets the rest.
 *
 * @return array{0: float, 1: float} [$driverAmount, $hotelAmount]
 */
function calcDriverSplit(float $total, string $commissionType, float $commissionValue): array
{
    if ($commissionType === 'nominal') {
        $hotelAmount  = max(0, min($commissionValue, $total));
        $driverAmount = round($total - $hotelAmount, 2);
    } else {
        $driverAmount = round($total * (max(0, min(100, $commissionValue)) / 100), 2);
        $hotelAmount  = round($total - $driverAmount, 2);
    }
    return [$driverAmount, $hotelAmount];
}

/**
 * Create a Tagihan (monthly_bills) entry for the driver's share of a trip,
 * based on a rental_car_bookings row. Idempotent: will not create a
 * duplicate bill for the same booking. Call only after the related hotel
 * invoice has been processed (guest payment synced).
 */
function createDriverPayableBill(PDO $pdo, ?int $userId, array $booking, string $serviceLabel): bool
{
    if (empty($booking['needs_driver_payment'])) return false;
    if (!empty($booking['billed_to_tagihan'])) return false;
    $driverAmount = (float)($booking['owner_amount'] ?? 0);
    if ($driverAmount <= 0) return false;

    $billCode = 'DRV' . str_pad((string)$booking['id'], 6, '0', STR_PAD_LEFT);

    // Idempotency guard: skip if a bill for this booking already exists
    $chk = $pdo->prepare("SELECT id FROM monthly_bills WHERE bill_code = ? OR (source_type='driver_trip' AND source_ref_id=?) LIMIT 1");
    $chk->execute([$billCode, $booking['id']]);
    if ($chk->fetchColumn()) {
        $pdo->prepare("UPDATE rental_car_bookings SET billed_to_tagihan=1 WHERE id=?")->execute([$booking['id']]);
        return false;
    }

    $carStmt = $pdo->prepare("SELECT plate_number, car_name, partner_owner FROM rental_cars WHERE id=?");
    $carStmt->execute([$booking['car_id']]);
    $car        = $carStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $driverName = $car['partner_owner'] ?: 'Driver';
    $plate      = $car['plate_number'] ?? '';

    $billName = "Bayar Driver {$driverName} - {$serviceLabel}" . ($plate ? " ({$plate})" : '');

    $notesParts = [];
    if (!empty($booking['guest_name'])) $notesParts[] = "Tamu: {$booking['guest_name']}";
    if (!empty($booking['trip_destination'])) $notesParts[] = "Tujuan: {$booking['trip_destination']}";
    $notesParts[] = "Auto-generated dari Hotel Service (booking #{$booking['id']})";

    $pdo->prepare("INSERT INTO monthly_bills
        (bill_code, bill_name, bill_month, amount, status, notes, source_type, source_ref_id, created_by)
        VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([
            $billCode,
            $billName,
            date('Y-m-01'),
            $driverAmount,
            'pending',
            implode(' | ', $notesParts),
            'driver_trip',
            $booking['id'],
            $userId
        ]);

    $pdo->prepare("UPDATE rental_car_bookings SET billed_to_tagihan=1 WHERE id=?")->execute([$booking['id']]);
    return true;
}

/**
 * Get (or create) the "Rent Car" division used for driver/partner trip
 * payments in the cashbook. Can be renamed anytime via Settings > Kelola
 * Divisi (modules/settings/divisions.php) - no code change needed for that.
 */
function getRentCarDivisionId(PDO $pdo): int
{
    $division = $pdo->query("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%rent car%' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($division) {
        return (int)$division['id'];
    }

    try {
        $pdo->exec("INSERT INTO divisions (division_code, division_name, division_type, is_active) VALUES ('RENTCAR', 'Rent Car', 'both', 1)");
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('getRentCarDivisionId: failed to create Rent Car division: ' . $e->getMessage());
        $fallbackDivision = $pdo->query("SELECT id FROM divisions ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return (int)($fallbackDivision['id'] ?? 1);
    }
}

/**
 * Get (or create) the expense category used for driver/partner trip payments
 * in the cashbook ("Hotel Service" category under the "Rent Car" division).
 * CashbookHelper::getCategoryId() always resolves to a 'Room Sell'-style
 * INCOME category (built for guest payments), which is wrong for this
 * expense - so driver payments need their own category/division lookup.
 *
 * NOTE: categories.division_id is NOT NULL (FK to divisions), so a division
 * must be resolved/created first before the category can be inserted.
 *
 * Both the division name and category name can be renamed anytime by the
 * user via Settings > Kelola Divisi / Kelola Kategori (modules/settings/
 * divisions.php and categories.php) - no code change needed for that.
 */
function getDriverPaymentCategoryId(PDO $pdo): int
{
    $divisionId = getRentCarDivisionId($pdo);

    // Resolve an existing expense category for driver/rent-car payments
    $category = $pdo->prepare("
        SELECT id FROM categories
        WHERE category_type = 'expense'
          AND (
              LOWER(category_name) LIKE '%hotel service%'
              OR LOWER(category_name) LIKE '%rent car%'
              OR LOWER(category_name) LIKE '%rental mobil%'
              OR LOWER(category_name) LIKE '%sewa mobil%'
          )
        ORDER BY (division_id = ?) DESC, id ASC LIMIT 1
    ");
    $category->execute([$divisionId]);
    $category = $category->fetch(PDO::FETCH_ASSOC);

    if ($category) {
        return (int)$category['id'];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO categories (category_name, category_type, division_id, created_at) VALUES ('Hotel Service', 'expense', ?, NOW())");
        $stmt->execute([$divisionId]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('getDriverPaymentCategoryId: failed to create Hotel Service category: ' . $e->getMessage());
        // Last resort: any expense category so the insert doesn't hard-fail
        $fallback = $pdo->query("SELECT id FROM categories WHERE category_type = 'expense' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return (int)($fallback['id'] ?? 1);
    }
}

/**
 * Ensure the DB columns needed for PER-TRIP driver payment tracking exist
 * (used by the "Tagihan Driver" tab / api/pay-driver-trip.php). Independent
 * of the monthly_bills-based auto-billing flow above - this tracks payment
 * status directly on the trip row (rental_car_bookings / hotel_invoice_items).
 * Safe to call on every request (checks column existence before altering).
 */
function ensureDriverTripPaymentColumns(PDO $pdo): void
{
    try {
        $pdo->query("SELECT driver_paid FROM rental_car_bookings LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE rental_car_bookings
                ADD COLUMN driver_paid TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN driver_paid_at DATETIME DEFAULT NULL,
                ADD COLUMN driver_paid_cashbook_id INT DEFAULT NULL");
        } catch (Exception $e2) {
            error_log('ensureDriverTripPaymentColumns rental_car_bookings: ' . $e2->getMessage());
        }
    }

    try {
        $pdo->query("SELECT driver_paid FROM hotel_invoice_items LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE hotel_invoice_items
                ADD COLUMN driver_paid TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN driver_paid_at DATETIME DEFAULT NULL,
                ADD COLUMN driver_paid_cashbook_id INT DEFAULT NULL");
        } catch (Exception $e2) {
            error_log('ensureDriverTripPaymentColumns hotel_invoice_items: ' . $e2->getMessage());
        }
    }

    // hotel_invoice_items: store owner_amount and hotel_commission per line
    try {
        $pdo->query("SELECT owner_amount FROM hotel_invoice_items LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE hotel_invoice_items
                ADD COLUMN owner_amount DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'bagian driver/pemilik',
                ADD COLUMN hotel_commission DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'potongan hotel'");
        } catch (Exception $e2) {
            error_log('ensureDriverTripPaymentColumns hotel_invoice_items owner_amount: ' . $e2->getMessage());
        }
    }
}

/**
 * Ensure the MASTER database's cash_account_transactions table has the
 * transaction_id column (links a ledger row back to its cash_book entry).
 * Some hosting environments' tables were created before this column existed
 * (schema drift between local dev DB and production DB) - safe to call on
 * every request (checks column existence before altering).
 */
function ensureCashAccountTransactionsSchema(PDO $masterPdo): void
{
    try {
        $masterPdo->query("SELECT transaction_id FROM cash_account_transactions LIMIT 1");
    } catch (Exception $e) {
        try {
            $masterPdo->exec("ALTER TABLE cash_account_transactions
                ADD COLUMN transaction_id INT DEFAULT NULL AFTER cash_account_id");
        } catch (Exception $e2) {
            error_log('ensureCashAccountTransactionsSchema: ' . $e2->getMessage());
        }
    }
}
