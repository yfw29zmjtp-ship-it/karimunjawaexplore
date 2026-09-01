<?php

/**
 * API: GET DRIVER / PARTNER RECAP
 * GET /api/get-driver-recap.php?month=YYYY-MM
 *
 * Monthly recap per driver/partner (car rental + airport/harbor drop trips),
 * used by the "Tagihan Driver" tab in modules/bills/index.php.
 * Mirrors the owner-recap logic from modules/frontdesk/rental-mobil-dashboard.php.
 */

if (ob_get_level()) ob_end_clean();

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

header('Content-Type: application/json; charset=utf-8');

try {
    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/auth.php';
    require_once '../includes/DriverPaymentHelper.php';

    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $businessId = $_SESSION['business_id'] ?? 1;
    $dropOwnerName = 'Bp. Moyong';

    $columnExists = static function (PDO $pdo, string $table, string $column): bool {
        if ($table === '' || $column === '') {
            return false;
        }
        try {
            $pdo->query("SELECT {$column} FROM {$table} LIMIT 1")->fetch();
            return true;
        } catch (Exception $e) {
            return false;
        }
    };

    try {
        $settingStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $settingStmt->execute(['driver_drop_partner_name']);
        $savedDriverName = trim((string)$settingStmt->fetchColumn());
        if ($savedDriverName !== '') {
            $dropOwnerName = $savedDriverName;
        }
    } catch (Exception $ignore) {
    }

    ensureDriverTripPaymentColumns($pdo);

    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    try {
        $pdo->query("SELECT 1 FROM rental_car_bookings LIMIT 1")->fetch();
    } catch (Exception $tableError) {
        throw new Exception('rental_car_bookings table does not exist yet.');
    }

    $hasCashBookTable = true;
    try {
        $pdo->query("SELECT 1 FROM cash_book LIMIT 1")->fetch();
    } catch (Exception $e) {
        $hasCashBookTable = false;
    }

    $hasCashbookPaymentMethod = $hasCashBookTable && $columnExists($pdo, 'cash_book', 'payment_method');
    $hasCbDriverPaidCashbookId = $columnExists($pdo, 'rental_car_bookings', 'driver_paid_cashbook_id');
    $hasHiiDriverPaidCashbookId = $columnExists($pdo, 'hotel_invoice_items', 'driver_paid_cashbook_id');
    $hasHiiTripType = $columnExists($pdo, 'hotel_invoice_items', 'trip_type');
    $hasHiiGuideName = $columnExists($pdo, 'hotel_invoice_items', 'guide_name');

    // ── Owner recap (car rental + airport/harbor drop trips with a linked driver car) ──
    $recap = [];
    try {
        $ownerStmt = $pdo->prepare("SELECT
            COALESCE(rc.partner_owner, ?) AS partner_owner,
            rc.owner_phone,
            COUNT(*) AS total_trips,
            COALESCE(SUM(hii.total_price),0) AS total_revenue,
            COALESCE(SUM(IF(hii.owner_amount > 0 OR hii.hotel_commission > 0, hii.owner_amount, hii.total_price)),0) AS owner_total,
            COALESCE(SUM(COALESCE(hii.hotel_commission,0)),0) AS hotel_total,
            AVG(COALESCE(rc.owner_commission_pct,0)) AS avg_comm_pct,
            GROUP_CONCAT(DISTINCT rc.car_name ORDER BY rc.car_name SEPARATOR ', ') AS cars,
            SUM(hii.service_type = 'car_rental') AS rental_trips,
            SUM(hii.service_type = 'airport_drop') AS airport_trips,
            SUM(hii.service_type = 'harbor_drop') AS harbor_trips
            FROM hotel_invoice_items hii
            JOIN hotel_invoices hi ON hi.id = hii.invoice_id
            LEFT JOIN (
                SELECT cb2.invoice_id, cb2.service_type, cb2.car_id
                FROM rental_car_bookings cb2
                WHERE cb2.business_id = ?
                GROUP BY cb2.invoice_id, cb2.service_type, cb2.car_id
            ) cb ON cb.invoice_id = hi.id AND cb.service_type = hii.service_type
            LEFT JOIN rental_cars rc ON rc.id = cb.car_id
            WHERE hi.business_id=?
              AND hi.status NOT IN ('cancelled')
              AND hi.payment_status = 'paid'
              AND hii.service_type IN ('car_rental','airport_drop','harbor_drop')
              AND DATE(COALESCE(hii.start_datetime, hi.created_at)) BETWEEN ? AND ?
              AND COALESCE(rc.partner_owner, ?) != ''
            GROUP BY COALESCE(rc.partner_owner, ?), rc.owner_phone
            ORDER BY total_revenue DESC");
        $ownerStmt->execute([$dropOwnerName, $businessId, $businessId, $monthStart, $monthEnd, $dropOwnerName, $dropOwnerName]);
        $recap = $ownerStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ownerErr) {
        error_log('get-driver-recap owner query fallback: ' . $ownerErr->getMessage());
    }

    $normalizeOwnerName = static function (?string $name): string {
        $name = strtolower(trim((string)$name));
        if ($name === '') {
            return '';
        }
        // Remove common Indonesian honorific prefixes so aliases collapse into one owner key.
        $name = preg_replace('/^(bp\.?|bpk\.?|bapak|pak|ibu|bu|mr\.?|mrs\.?|ms\.?)\s+/u', '', $name);
        $name = preg_replace('/[^a-z0-9]+/u', '', $name);
        return $name;
    };

    $ownerKey = static function (?string $name) use ($normalizeOwnerName): string {
        $name = $normalizeOwnerName($name);
        return $name !== '' ? $name : '__tanpa_pemilik__';
    };

    $dropKeyCanonical = $ownerKey($dropOwnerName);

    foreach ($recap as &$or) {
        $or['total_trips'] = (int)$or['total_trips'];
        $or['total_revenue'] = (float)$or['total_revenue'];
        $or['owner_total'] = (float)$or['owner_total'];
        $or['hotel_total'] = (float)$or['hotel_total'];
        $or['avg_comm_pct'] = (float)$or['avg_comm_pct'];
        $or['rental_trips'] = (int)$or['rental_trips'];
        $or['airport_trips'] = (int)$or['airport_trips'];
        $or['harbor_trips'] = (int)$or['harbor_trips'];
        $or['airport_total'] = 0.0;
        $or['harbor_total'] = 0.0;
        $or['paid_total'] = 0.0;
        $or['unpaid_total'] = 0.0;
        $or['paid_trips'] = 0;
        $or['unpaid_trips'] = 0;
        $or['detail_rows'] = [];
    }
    unset($or);

    // Merge owner aliases (example: "Moyong" and "Bp. Moyong") into a single recap card.
    $mergedRecap = [];
    foreach ($recap as $row) {
        $key = $ownerKey($row['partner_owner'] ?? '');
        if (!isset($mergedRecap[$key])) {
            if ($key === $dropKeyCanonical) {
                $row['partner_owner'] = $dropOwnerName;
            }
            $mergedRecap[$key] = $row;
            continue;
        }

        $mergedRecap[$key]['total_trips'] += (int)($row['total_trips'] ?? 0);
        $mergedRecap[$key]['total_revenue'] += (float)($row['total_revenue'] ?? 0);
        $mergedRecap[$key]['owner_total'] += (float)($row['owner_total'] ?? 0);
        $mergedRecap[$key]['hotel_total'] += (float)($row['hotel_total'] ?? 0);
        $mergedRecap[$key]['rental_trips'] += (int)($row['rental_trips'] ?? 0);
        $mergedRecap[$key]['airport_trips'] += (int)($row['airport_trips'] ?? 0);
        $mergedRecap[$key]['harbor_trips'] += (int)($row['harbor_trips'] ?? 0);

        if (!empty($row['owner_phone']) && empty($mergedRecap[$key]['owner_phone'])) {
            $mergedRecap[$key]['owner_phone'] = $row['owner_phone'];
        }

        $existingCars = array_filter(array_map('trim', explode(',', (string)($mergedRecap[$key]['cars'] ?? ''))));
        $newCars = array_filter(array_map('trim', explode(',', (string)($row['cars'] ?? ''))));
        $cars = array_values(array_unique(array_merge($existingCars, $newCars)));
        $mergedRecap[$key]['cars'] = implode(', ', $cars);

        if ($key === $dropKeyCanonical) {
            $mergedRecap[$key]['partner_owner'] = $dropOwnerName;
        }
    }

    foreach ($mergedRecap as &$row) {
        $row['avg_comm_pct'] = $row['total_revenue'] > 0
            ? ($row['owner_total'] / $row['total_revenue'] * 100)
            : 0.0;
    }
    unset($row);

    $recap = array_values($mergedRecap);

    $indexMap = [];
    foreach ($recap as $idx => $or) {
        $indexMap[$ownerKey($or['partner_owner'] ?? '')] = $idx;
    }

    // ── Detail rows: car rental + airport/harbor drop trips (linked driver car) ──
    $detailMap = [];
    try {
        $detailSelectCashbookId = $hasCbDriverPaidCashbookId ? 'cb.driver_paid_cashbook_id' : '0 as driver_paid_cashbook_id';
        $detailSelectPaymentMethod = $hasCashbookPaymentMethod ? 'paycb.payment_method' : 'NULL as payment_method';
        $detailJoinCashbook = ($hasCashBookTable && $hasCbDriverPaidCashbookId) ? 'LEFT JOIN cash_book paycb ON cb.driver_paid_cashbook_id = paycb.id' : '';
        $detailStmt = $pdo->prepare("SELECT
                COALESCE(rc.partner_owner, ?) AS partner_owner,
                hii.id AS trip_id,
                hii.service_type,
                COALESCE(hii.start_datetime, hi.created_at) AS trx_date,
                hi.guest_name,
                hi.room_number,
                hii.description AS trip_destination,
                hii.total_price,
                IF(hii.owner_amount > 0 OR hii.hotel_commission > 0, hii.owner_amount, hii.total_price) AS owner_amount,
                hii.driver_paid,
                hii.driver_paid_at,
                {$detailSelectCashbookId},
                {$detailSelectPaymentMethod},
                rc.car_name,
                rc.plate_number
                FROM hotel_invoice_items hii
                JOIN hotel_invoices hi ON hi.id = hii.invoice_id
                LEFT JOIN (
                    SELECT cb2.invoice_id, cb2.service_type, cb2.car_id, cb2.driver_paid_cashbook_id, cb2.driver_paid, cb2.driver_paid_at
                    FROM rental_car_bookings cb2
                    WHERE cb2.business_id = ?
                    GROUP BY cb2.invoice_id, cb2.service_type, cb2.car_id, cb2.driver_paid_cashbook_id, cb2.driver_paid, cb2.driver_paid_at
                ) cb ON cb.invoice_id = hi.id AND cb.service_type = hii.service_type
                LEFT JOIN rental_cars rc ON rc.id = cb.car_id
                {$detailJoinCashbook}
            WHERE hi.business_id=?
              AND hi.status NOT IN ('cancelled')
              AND hi.payment_status = 'paid'
              AND hii.service_type IN ('car_rental','airport_drop','harbor_drop')
              AND DATE(COALESCE(hii.start_datetime, hi.created_at)) BETWEEN ? AND ?
              AND COALESCE(rc.partner_owner, ?) != ''
            ORDER BY trx_date DESC, hii.id DESC");
        $detailStmt->execute([$dropOwnerName, $businessId, $businessId, $monthStart, $monthEnd, $dropOwnerName]);
        $seenDetailKeys = [];
        foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) as $detail) {
            $rowKey = 'trip:' . (int)$detail['trip_id'] . ':' . (string)($detail['service_type'] ?? '');
            if (isset($seenDetailKeys[$rowKey])) {
                continue;
            }
            $seenDetailKeys[$rowKey] = true;
            $key = $ownerKey($detail['partner_owner'] ?? '');
            $carLabel = trim(($detail['car_name'] ?? '') . ' (' . ($detail['plate_number'] ?? '') . ')');
            $destination = trim((string)($detail['trip_destination'] ?? ''));
            $label = $destination !== '' ? $destination : ($carLabel !== '' ? $carLabel : ucfirst((string)($detail['service_type'] ?? '')));
            $detailMap[$key][] = [
                'trip_id' => (int)$detail['trip_id'],
                'trx_date' => $detail['trx_date'],
                'guest_name' => $detail['guest_name'],
                'room_number' => $detail['room_number'],
                'label' => $label,
                'service_type' => $detail['service_type'],
                'source' => 'trip',
                'total_price' => (float)$detail['total_price'],
                'owner_amount' => (float)$detail['owner_amount'],
                'paid' => (bool)$detail['driver_paid'],
                'driver_paid_at' => $detail['driver_paid_at'],
                'driver_paid_cashbook_id' => isset($detail['driver_paid_cashbook_id']) ? (int)$detail['driver_paid_cashbook_id'] : 0,
                'payment_method' => $detail['payment_method'] ?? null,
            ];
        }
    } catch (Exception $detailErr) {
        error_log('get-driver-recap detail query fallback: ' . $detailErr->getMessage());
    }

    // ── Airport/Harbor Drop trips without explicit linked owner ─────────────────
    $dropKey = $ownerKey($dropOwnerName);

    try {
        $pdo->query("SELECT 1 FROM hotel_invoice_items LIMIT 1")->fetch();

        $dropSelectCashbookId = $hasHiiDriverPaidCashbookId ? 'hii.driver_paid_cashbook_id' : '0 as driver_paid_cashbook_id';
        $dropSelectPaymentMethod = $hasCashbookPaymentMethod ? 'paycb.payment_method' : 'NULL as payment_method';
        $dropJoinCashbook = ($hasCashBookTable && $hasHiiDriverPaidCashbookId) ? 'LEFT JOIN cash_book paycb ON hii.driver_paid_cashbook_id = paycb.id' : '';

        $dropSelectTripType = $hasHiiTripType ? 'hii.trip_type' : 'NULL as trip_type';
        $dropSelectGuideName = $hasHiiGuideName ? 'hii.guide_name' : 'NULL as guide_name';

        $dropStmt = $pdo->prepare("SELECT
            hi.guest_name, hi.room_number, COALESCE(hii.start_datetime, hi.created_at) as trx_date,
            hii.id as trip_id, hii.service_type, {$dropSelectTripType}, {$dropSelectGuideName}, hii.description, hii.total_price,
            IF(hii.owner_amount > 0 OR hii.hotel_commission > 0, hii.owner_amount, hii.total_price) as owner_amount,
            COALESCE(hii.hotel_commission, 0) as hotel_commission,
            hii.driver_paid, hii.driver_paid_at, {$dropSelectCashbookId},
            {$dropSelectPaymentMethod}
            FROM hotel_invoice_items hii
            JOIN hotel_invoices hi ON hii.invoice_id = hi.id
            {$dropJoinCashbook}
            WHERE hi.business_id=? AND hii.service_type IN ('airport_drop','harbor_drop','narayana_trip')
              AND hi.status NOT IN ('cancelled')
              AND hi.payment_status = 'paid'
              AND DATE(COALESCE(hii.start_datetime, hi.created_at)) BETWEEN ? AND ?
              AND (
                  hii.service_type = 'narayana_trip'
                  OR NOT EXISTS (
                      SELECT 1 FROM rental_car_bookings cb2
                      WHERE cb2.invoice_id = hii.invoice_id
                        AND cb2.business_id = hi.business_id
                        AND cb2.service_type = hii.service_type
                  )
              )
            ORDER BY COALESCE(hii.start_datetime, hi.created_at) DESC, hii.id DESC");
        $dropStmt->execute([$businessId, $monthStart, $monthEnd]);
        $dropDetails = $dropStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasClassicDrop = false;
        foreach ($dropDetails as $d) {
            if (in_array(($d['service_type'] ?? ''), ['airport_drop', 'harbor_drop'], true)) {
                $hasClassicDrop = true;
                break;
            }
        }

        if (!isset($indexMap[$dropKey]) && $hasClassicDrop) {
            $recap[] = [
                'partner_owner' => $dropOwnerName,
                'owner_phone' => null,
                'total_trips' => 0,
                'total_revenue' => 0.0,
                'owner_total' => 0.0,
                'hotel_total' => 0.0,
                'avg_comm_pct' => 100,
                'cars' => 'Airport Drop, Harbor Drop',
                'rental_trips' => 0,
                'airport_trips' => 0,
                'harbor_trips' => 0,
                'airport_total' => 0.0,
                'harbor_total' => 0.0,
                'paid_total' => 0.0,
                'unpaid_total' => 0.0,
                'paid_trips' => 0,
                'unpaid_trips' => 0,
                'detail_rows' => [],
            ];
            $indexMap[$dropKey] = count($recap) - 1;
        }

        $seenLegacyKeys = [];
        foreach ($dropDetails as $detail) {
            $legacyKey = 'legacy:' . (int)$detail['trip_id'] . ':' . (string)($detail['service_type'] ?? '');
            if (isset($seenLegacyKeys[$legacyKey])) {
                continue;
            }
            $seenLegacyKeys[$legacyKey] = true;
            $isNarayanaTrip = ($detail['service_type'] ?? '') === 'narayana_trip';
            $guideOwnerName = trim((string)($detail['guide_name'] ?? ''));
            if ($isNarayanaTrip && $guideOwnerName === '') {
                $guideOwnerName = 'Guide Narayana Trip';
            }
            $targetOwnerName = $isNarayanaTrip ? $guideOwnerName : $dropOwnerName;
            $targetKey = $ownerKey($targetOwnerName);

            if (!isset($indexMap[$targetKey])) {
                $recap[] = [
                    'partner_owner' => $targetOwnerName,
                    'owner_phone' => null,
                    'total_trips' => 0,
                    'total_revenue' => 0.0,
                    'owner_total' => 0.0,
                    'hotel_total' => 0.0,
                    'avg_comm_pct' => 100,
                    'cars' => $isNarayanaTrip ? 'Narayana Trip' : 'Airport Drop, Harbor Drop',
                    'rental_trips' => 0,
                    'airport_trips' => 0,
                    'harbor_trips' => 0,
                    'airport_total' => 0.0,
                    'harbor_total' => 0.0,
                    'paid_total' => 0.0,
                    'unpaid_total' => 0.0,
                    'paid_trips' => 0,
                    'unpaid_trips' => 0,
                    'detail_rows' => [],
                ];
                $indexMap[$targetKey] = count($recap) - 1;
            }

            $idx = $indexMap[$targetKey] ?? null;
            if ($idx === null) continue;
            $amount      = (float)$detail['total_price'];
            $ownerAmt    = (float)$detail['owner_amount'];
            $hotelComm   = (float)$detail['hotel_commission'];
            $recap[$idx]['total_trips']   += 1;
            $recap[$idx]['total_revenue'] += $amount;
            $recap[$idx]['owner_total']   += $ownerAmt;
            $recap[$idx]['hotel_total']   += $hotelComm;
            $recap[$idx]['avg_comm_pct']   = $recap[$idx]['total_revenue'] > 0
                ? round($recap[$idx]['owner_total'] / $recap[$idx]['total_revenue'] * 100, 1) : 100;
            if ($detail['service_type'] === 'airport_drop') {
                $recap[$idx]['airport_trips'] += 1;
                $recap[$idx]['airport_total'] += $amount;
            }
            if ($detail['service_type'] === 'harbor_drop') {
                $recap[$idx]['harbor_trips'] += 1;
                $recap[$idx]['harbor_total'] += $amount;
            }
            $tripTypeLabel = '';
            if (($detail['service_type'] ?? '') === 'narayana_trip') {
                $tripType = strtolower(trim((string)($detail['trip_type'] ?? '')));
                if ($tripType === 'open_trip') {
                    $tripTypeLabel = 'Open Trip';
                } elseif ($tripType === 'private_trip') {
                    $tripTypeLabel = 'Private Trip';
                }
            }
            $detailMap[$targetKey][] = [
                'trip_id' => (int)$detail['trip_id'],
                'trx_date' => $detail['trx_date'],
                'guest_name' => $detail['guest_name'],
                'room_number' => $detail['room_number'],
                'label' => trim(($tripTypeLabel ? ($tripTypeLabel . ' - ') : '') . ($detail['description'] ?: $detail['service_type'])),
                'service_type' => $detail['service_type'],
                'source' => 'legacy',
                'total_price' => $amount,
                'owner_amount' => (float)$detail['owner_amount'],
                'paid' => (bool)$detail['driver_paid'],
                'driver_paid_at' => $detail['driver_paid_at'],
                'driver_paid_cashbook_id' => isset($detail['driver_paid_cashbook_id']) ? (int)$detail['driver_paid_cashbook_id'] : 0,
                'payment_method' => $detail['payment_method'] ?? null,
            ];
        }
    } catch (Exception $dropError) {
        // hotel_invoice_items table not available - skip drop trips silently
    }

    $totals = ['trips' => 0, 'revenue' => 0.0, 'owner_total' => 0.0, 'hotel_total' => 0.0, 'paid_total' => 0.0, 'unpaid_total' => 0.0];
    foreach ($recap as &$or) {
        $key = $ownerKey($or['partner_owner'] ?? '');
        $rows = $detailMap[$key] ?? [];
        $or['detail_rows'] = $rows;
        foreach ($rows as $row) {
            if ($row['paid']) {
                $or['paid_total'] += $row['owner_amount'];
                $or['paid_trips']++;
            } else {
                $or['unpaid_total'] += $row['owner_amount'];
                $or['unpaid_trips']++;
            }
        }
        $totals['trips'] += $or['total_trips'];
        $totals['revenue'] += $or['total_revenue'];
        $totals['owner_total'] += $or['owner_total'];
        $totals['hotel_total'] += $or['hotel_total'];
        $totals['paid_total'] += $or['paid_total'];
        $totals['unpaid_total'] += $or['unpaid_total'];
    }
    unset($or);

    usort($recap, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

    echo json_encode([
        'success' => true,
        'month' => $month,
        'recap' => $recap,
        'totals' => $totals,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => get_class($e),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
    exit;
}
