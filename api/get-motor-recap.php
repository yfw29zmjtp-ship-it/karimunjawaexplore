<?php

/**
 * API: GET MOTOR PARTNER RECAP
 * GET /api/get-motor-recap.php?month=YYYY-MM
 *
 * Monthly recap per motor partner (external motors with partner owners),
 * used by the "Tagihan Motor" tab in modules/bills/index.php.
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

    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $businessId = $_SESSION['business_id'] ?? 1;

    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    try {
        $pdo->query("SELECT 1 FROM rental_motor_bookings LIMIT 1")->fetch();
    } catch (Exception $tableError) {
        throw new Exception('rental_motor_bookings table does not exist yet.');
    }

    // ── Motor Partner recap (motor rentals with partner owners) ──
    $recap = [];
    try {
        $ownerStmt = $pdo->prepare("SELECT
            rm.partner_owner, rm.owner_phone,
            COUNT(*) as total_rentals,
            COALESCE(SUM(rmb.total_price),0) as total_revenue,
            COALESCE(SUM(rmb.owner_amount),0) as owner_total,
            COALESCE(SUM(rmb.hotel_commission),0) as hotel_total,
            AVG(rm.owner_commission_pct) as avg_comm_pct,
            GROUP_CONCAT(DISTINCT rm.motor_name ORDER BY rm.motor_name SEPARATOR ', ') as motors
            FROM rental_motor_bookings rmb
            JOIN rental_motors rm ON rmb.motor_id = rm.id
            LEFT JOIN hotel_invoices hi ON hi.id = rmb.invoice_id AND hi.business_id = rmb.business_id
            WHERE rmb.business_id=? AND rmb.status IN ('active','returned')
                AND rmb.motor_id IS NOT NULL
                AND (
                    (
                        rmb.invoice_id IS NOT NULL
                        AND hi.id IS NOT NULL
                        AND hi.status NOT IN ('cancelled')
                        AND DATE(hi.created_at) BETWEEN ? AND ?
                    )
                    OR (
                        rmb.invoice_id IS NULL
                        AND DATE(COALESCE(rmb.actual_return, rmb.end_datetime, rmb.created_at)) BETWEEN ? AND ?
                    )
                )
                AND rm.partner_owner IS NOT NULL AND rm.partner_owner != ''
            GROUP BY rm.partner_owner, rm.owner_phone
            ORDER BY total_revenue DESC");
        $ownerStmt->execute([$businessId, $monthStart, $monthEnd, $monthStart, $monthEnd]);
        $recap = $ownerStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ownerErr) {
        error_log('get-motor-recap owner query: ' . $ownerErr->getMessage());
        $recap = [];
    }

    foreach ($recap as &$or) {
        $or['total_rentals'] = (int)$or['total_rentals'];
        $or['total_revenue'] = (float)$or['total_revenue'];
        $or['owner_total'] = (float)$or['owner_total'];
        $or['hotel_total'] = (float)$or['hotel_total'];
        $or['avg_comm_pct'] = (float)$or['avg_comm_pct'];
        $or['paid_total'] = 0.0;
        $or['unpaid_total'] = 0.0;
        $or['paid_rentals'] = 0;
        $or['unpaid_rentals'] = 0;
        $or['detail_rows'] = [];
    }
    unset($or);

    // ── Detail rows for each owner ──
    try {
        $detailStmt = $pdo->prepare("SELECT
            rmb.id as rental_id,
            rmb.guest_name, rmb.room_number,
            rm.motor_name, rm.plate_number, rm.partner_owner,
            COALESCE(rmb.total_price, 0) as total_price,
            COALESCE(rmb.owner_amount, 0) as owner_amount,
            COALESCE(rmb.hotel_commission, 0) as hotel_commission,
            CASE WHEN rmb.payment_date IS NOT NULL THEN 1 ELSE 0 END as paid,
            COALESCE(DATE(hi.created_at), DATE(rmb.start_datetime), DATE(rmb.created_at)) as trx_date,
            hi.payment_status as invoice_payment_status
            FROM rental_motor_bookings rmb
            JOIN rental_motors rm ON rmb.motor_id = rm.id
            LEFT JOIN hotel_invoices hi ON hi.id = rmb.invoice_id AND hi.business_id = rmb.business_id
            WHERE rmb.business_id=? AND rmb.status IN ('active','returned')
                AND rmb.motor_id IS NOT NULL
                AND (
                    (
                        rmb.invoice_id IS NOT NULL
                        AND hi.id IS NOT NULL
                        AND hi.status NOT IN ('cancelled')
                        AND DATE(hi.created_at) BETWEEN ? AND ?
                    )
                    OR (
                        rmb.invoice_id IS NULL
                        AND DATE(COALESCE(rmb.actual_return, rmb.end_datetime, rmb.created_at)) BETWEEN ? AND ?
                    )
                )
                AND rm.partner_owner IS NOT NULL AND rm.partner_owner != ''
            ORDER BY rmb.created_at DESC");
        $detailStmt->execute([$businessId, $monthStart, $monthEnd, $monthStart, $monthEnd]);
        $detailRows = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group detail rows by partner owner
        foreach ($detailRows as $row) {
            $ownerName = $row['partner_owner'] ?? '';
            $ownerPhone = null;

            // Find matching recap entry
            foreach ($recap as &$recapEntry) {
                if ($recapEntry['partner_owner'] === $ownerName) {
                    $ownerPhone = $recapEntry['owner_phone'];

                    $row['total_price'] = (float)$row['total_price'];
                    $row['owner_amount'] = (float)$row['owner_amount'];
                    $row['hotel_commission'] = (float)$row['hotel_commission'];
                    $row['paid'] = (bool)$row['paid'];

                    $recapEntry['detail_rows'][] = $row;

                    if ($row['paid']) {
                        $recapEntry['paid_total'] += $row['owner_amount'];
                        $recapEntry['paid_rentals']++;
                    } else {
                        $recapEntry['unpaid_total'] += $row['owner_amount'];
                        $recapEntry['unpaid_rentals']++;
                    }
                    break;
                }
            }
            unset($recapEntry);
        }
    } catch (Exception $detailErr) {
        error_log('get-motor-recap detail query: ' . $detailErr->getMessage());
    }

    $recap = array_values($recap);

    ob_clean();
    echo json_encode([
        'success' => true,
        'month' => $month,
        'recap' => $recap
    ]);
    exit;
} catch (Exception $e) {
    error_log('[get-motor-recap] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
