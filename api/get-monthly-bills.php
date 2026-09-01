<?php

/**
 * API: GET MONTHLY BILLS
 * GET /api/get-monthly-bills.php
 * 
 * Retrieve monthly bills with filters
 * Query params:
 * - month: YYYY-MM (filter by month)
 * - status: pending, partial, paid, cancelled
 * - division_id: filter by division
 * - limit: default 100
 * - offset: default 0
 */

// Prevent any output buffering issues
if (ob_get_level()) ob_end_clean();

// Set error handling BEFORE includes
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Don't display, we'll catch and return as JSON
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Always output JSON
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
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    // Test if tables exist before querying
    try {
        $db->query("SELECT 1 FROM monthly_bills LIMIT 1")->fetch();
    } catch (Exception $tableError) {
        throw new Exception('monthly_bills table does not exist. Please run migrations first.');
    }
    $month = $_GET['month'] ?? date('Y-m');
    $status = $_GET['status'] ?? null;
    $divisionId = $_GET['division_id'] ?? null;
    $limit = (int)($_GET['limit'] ?? 100);
    $offset = (int)($_GET['offset'] ?? 0);

    // Build query
    $where = ["DATE_FORMAT(bill_month, '%Y-%m') = ?"];
    $params = [$month];

    if ($status) {
        $where[] = "status = ?";
        $params[] = $status;
    }

    if ($divisionId) {
        $where[] = "division_id = ?";
        $params[] = (int)$divisionId;
    }

    $whereClause = implode(' AND ', $where);

    // Get bills
    $query = "
        SELECT 
            mb.*,
            d.division_name,
            c.category_name,
            COUNT(bp.id) as payment_count,
            SUM(bp.amount) as total_payments,
            rcb.total_price as trip_total,
            rcb.hotel_commission as hotel_profit
        FROM monthly_bills mb
        LEFT JOIN divisions d ON mb.division_id = d.id
        LEFT JOIN categories c ON mb.category_id = c.id
        LEFT JOIN bill_payments bp ON mb.id = bp.bill_id
        LEFT JOIN rental_car_bookings rcb ON mb.source_type = 'driver_trip' AND mb.source_ref_id = rcb.id
        WHERE $whereClause
        GROUP BY mb.id
        ORDER BY mb.bill_month DESC, mb.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;

    $bills = $db->query($query, $params)->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $countQuery = "
        SELECT COUNT(DISTINCT mb.id) as total
        FROM monthly_bills mb
        WHERE $whereClause
    ";
    $countParams = array_slice($params, 0, count($params) - 2);
    $countResult = $db->fetchOne($countQuery, $countParams);
    $total = $countResult['total'] ?? 0;

    // Format response
    $formattedBills = [];
    foreach ($bills as $bill) {
        $formattedBills[] = [
            'id' => (int)$bill['id'],
            'bill_code' => $bill['bill_code'],
            'bill_name' => $bill['bill_name'],
            'bill_month' => $bill['bill_month'],
            'amount' => (float)$bill['amount'],
            'paid_amount' => (float)$bill['paid_amount'],
            'remaining' => (float)$bill['amount'] - (float)$bill['paid_amount'],
            'status' => $bill['status'],
            'division_name' => $bill['division_name'],
            'category_name' => $bill['category_name'],
            'due_date' => $bill['due_date'],
            'is_recurring' => (int)$bill['is_recurring'],
            'payment_count' => (int)$bill['payment_count'],
            'notes' => $bill['notes'],
            'source_type' => $bill['source_type'] ?? null,
            'trip_total' => isset($bill['trip_total']) ? (float)$bill['trip_total'] : null,
            'hotel_profit' => isset($bill['hotel_profit']) ? (float)$bill['hotel_profit'] : null
        ];
    }

    echo json_encode([
        'success' => true,
        'bills' => $formattedBills,
        'total' => $total,
        'month' => $month
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
