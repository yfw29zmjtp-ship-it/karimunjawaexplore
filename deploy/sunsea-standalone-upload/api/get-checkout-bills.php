<?php

/**
 * API: Get Checkout Bills
 * Returns all pending/unpaid bills for a booking before checkout:
 * - Active/overdue rental motor
 * - Active/overdue rental car/taxi
 * - Unpaid hotel service invoices linked to this booking
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

ob_clean();
header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('frontdesk')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db  = Database::getInstance();
$pdo = $db->getConnection();
$businessId = $_SESSION['business_id'] ?? 1;
$bookingId  = (int)($_GET['booking_id'] ?? 0);

if (!$bookingId) {
    echo json_encode(['success' => false, 'message' => 'booking_id required']);
    exit;
}

try {
    // 1) Get booking details
    $booking = $db->fetchOne("
        SELECT b.*, g.guest_name, r.room_number
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ", [$bookingId]);

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    $bills = [
        'motor'  => [],
        'car'    => [],
        'invoices' => [],
        'has_blocking' => false
    ];

    // 2) Active / overdue rental MOTOR for this booking
    try {
        $motorStmt = $pdo->prepare("
            SELECT rb.id, rb.guest_name, rb.room_number, rb.start_datetime, rb.end_datetime,
                   rb.daily_rate, rb.total_price, rb.status, rb.deposit,
                   rm.plate_number, rm.motor_name, rm.color
            FROM rental_motor_bookings rb
            JOIN rental_motors rm ON rb.motor_id = rm.id
            WHERE rb.business_id = ? AND rb.booking_id = ? AND rb.status IN ('active','overdue')
        ");
        $motorStmt->execute([$businessId, $bookingId]);
        $motorRentals = $motorStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($motorRentals as $r) {
            $start    = new DateTime($r['start_datetime']);
            $now      = new DateTime();
            $estDays  = max(1, (int)ceil($start->diff($now)->days + 1));
            $estPrice = max(100000, round($estDays * (float)$r['daily_rate'], 2));
            $bills['motor'][] = [
                'id'          => $r['id'],
                'label'       => $r['motor_name'] . ' (' . $r['plate_number'] . ')',
                'guest_name'  => $r['guest_name'],
                'room_number' => $r['room_number'],
                'start'       => $r['start_datetime'],
                'end'         => $r['end_datetime'],
                'daily_rate'  => (float)$r['daily_rate'],
                'est_price'   => $estPrice,
                'status'      => $r['status'],
                'deposit'     => (float)$r['deposit'],
            ];
            $bills['has_blocking'] = true;
        }
    } catch (\Throwable $e) {
        // table may not exist yet — ignore
    }

    // 3) Active / overdue rental CAR/TAXI for this booking
    try {
        $carStmt = $pdo->prepare("
            SELECT cb.id, cb.guest_name, cb.room_number, cb.start_datetime, cb.end_datetime,
                   cb.daily_rate, cb.total_price, cb.status, cb.deposit,
                   rc.plate_number, rc.car_name, rc.car_type
            FROM rental_car_bookings cb
            JOIN rental_cars rc ON cb.car_id = rc.id
            WHERE cb.business_id = ? AND cb.booking_id = ? AND cb.status IN ('active','overdue')
        ");
        $carStmt->execute([$businessId, $bookingId]);
        $carRentals = $carStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($carRentals as $r) {
            $start    = new DateTime($r['start_datetime']);
            $now      = new DateTime();
            $estDays  = max(1, (int)ceil($start->diff($now)->days + 1));
            $estPrice = max(0, round($estDays * (float)$r['daily_rate'], 2));
            $bills['car'][] = [
                'id'          => $r['id'],
                'label'       => $r['car_name'] . ' (' . $r['plate_number'] . ') - ' . ($r['car_type'] ?? ''),
                'guest_name'  => $r['guest_name'],
                'room_number' => $r['room_number'],
                'start'       => $r['start_datetime'],
                'end'         => $r['end_datetime'],
                'daily_rate'  => (float)$r['daily_rate'],
                'est_price'   => $estPrice,
                'status'      => $r['status'],
                'deposit'     => (float)$r['deposit'],
            ];
            $bills['has_blocking'] = true;
        }
    } catch (\Throwable $e) {
        // table may not exist yet — ignore
    }

    // 4) Unpaid hotel service invoices linked to this booking
    try {
        $invStmt = $pdo->prepare("
            SELECT id, invoice_number, total, paid_amount, payment_status, notes
            FROM hotel_invoices
            WHERE business_id = ? AND booking_id = ?
              AND cashbook_synced = 0
              AND payment_status IN ('unpaid','partial')
              AND status NOT IN ('cancelled')
        ");
        $invStmt->execute([$businessId, $bookingId]);
        $unpaidInvoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($unpaidInvoices as $inv) {
            $remaining = (float)$inv['total'] - (float)$inv['paid_amount'];
            if ($remaining > 100) {
                // Get invoice items for detail breakdown
                $itemStmt = $pdo->prepare("
                    SELECT service_type, description, quantity, unit_price, total_price
                    FROM hotel_invoice_items
                    WHERE invoice_id = ?
                    ORDER BY id
                ");
                $itemStmt->execute([$inv['id']]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

                $bills['invoices'][] = [
                    'id'              => $inv['id'],
                    'invoice_number'  => $inv['invoice_number'],
                    'total'           => (float)$inv['total'],
                    'paid_amount'     => (float)$inv['paid_amount'],
                    'remaining'       => $remaining,
                    'payment_status'  => $inv['payment_status'],
                    'notes'           => $inv['notes'],
                    'items'           => $items,  // Include items for detailed display
                ];
                $bills['has_blocking'] = true;
            }
        }
    } catch (\Throwable $e) {
        // ignore
    }

    // 5) Room charge outstanding
    $finalPrice  = (float)($booking['final_price'] ?? 0);
    $paidRow     = $db->fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM booking_payments WHERE booking_id=?", [$bookingId]);
    $paidAmount  = max((float)($paidRow['total'] ?? 0), (float)($booking['paid_amount'] ?? 0));
    $roomBalance = $finalPrice - $paidAmount;
    if ($roomBalance < 0) $roomBalance = 0;

    $bills['room'] = [
        'guest_name'     => $booking['guest_name'],
        'room_number'    => $booking['room_number'],
        'booking_code'   => $booking['booking_code'],
        'final_price'    => $finalPrice,
        'paid_amount'    => $paidAmount,
        'remaining'      => $roomBalance,
        'payment_status' => $booking['payment_status'],
    ];

    if ($roomBalance > 1000) {
        $bills['has_blocking'] = true;
    }

    echo json_encode(['success' => true, 'bills' => $bills]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
