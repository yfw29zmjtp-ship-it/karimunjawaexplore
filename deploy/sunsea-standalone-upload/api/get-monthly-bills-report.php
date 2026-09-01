<?php

/**
 * API: MONTHLY BILLS REPORT
 * GET /api/get-monthly-bills-report.php
 * 
 * Get comprehensive monthly report
 * Query params:
 * - month: YYYY-MM
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

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

try {
    $month = $_GET['month'] ?? date('Y-m');
    $monthDate = $month . '-01';

    // ======================================
    // SUMMARY STATISTICS
    // ======================================

    // Total bills
    $summary = $db->fetchOne("
        SELECT 
            COUNT(*) as total_bills,
            SUM(amount) as total_amount,
            SUM(paid_amount) as total_paid,
            SUM(amount) - SUM(paid_amount) as total_remaining
        FROM monthly_bills
        WHERE DATE_FORMAT(bill_month, '%Y-%m') = ?
    ", [$month]);

    // By status
    $byStatus = $db->query("
        SELECT 
            status,
            COUNT(*) as count,
            SUM(amount) as total_amount,
            SUM(paid_amount) as total_paid
        FROM monthly_bills
        WHERE DATE_FORMAT(bill_month, '%Y-%m') = ?
        GROUP BY status
    ", [$month])->fetchAll(PDO::FETCH_ASSOC);

    // By category
    $byCategory = $db->query("
        SELECT 
            c.category_name,
            COUNT(mb.id) as count,
            SUM(mb.amount) as total_amount,
            SUM(mb.paid_amount) as total_paid
        FROM monthly_bills mb
        LEFT JOIN categories c ON mb.category_id = c.id
        WHERE DATE_FORMAT(mb.bill_month, '%Y-%m') = ?
        GROUP BY mb.category_id
        ORDER BY total_amount DESC
    ", [$month])->fetchAll(PDO::FETCH_ASSOC);

    // Payment methods breakdown
    $paymentMethods = $db->query("
        SELECT 
            bp.payment_method,
            COUNT(*) as count,
            SUM(bp.amount) as total_amount
        FROM bill_payments bp
        JOIN monthly_bills mb ON bp.bill_id = mb.id
        WHERE DATE_FORMAT(mb.bill_month, '%Y-%m') = ?
        GROUP BY bp.payment_method
        ORDER BY total_amount DESC
    ", [$month])->fetchAll(PDO::FETCH_ASSOC);

    // ======================================
    // DETAILED LIST
    // ======================================

    $billsList = $db->query("
        SELECT 
            mb.*,
            c.category_name,
            COUNT(bp.id) as payment_count,
            SUM(bp.amount) as sum_payments
        FROM monthly_bills mb
        LEFT JOIN categories c ON mb.category_id = c.id
        LEFT JOIN bill_payments bp ON mb.id = bp.bill_id
        WHERE DATE_FORMAT(mb.bill_month, '%Y-%m') = ?
        GROUP BY mb.id
        ORDER BY mb.amount DESC
    ", [$month])->fetchAll(PDO::FETCH_ASSOC);

    $formattedBills = [];
    foreach ($billsList as $bill) {
        $formattedBills[] = [
            'id' => (int)$bill['id'],
            'bill_code' => $bill['bill_code'],
            'bill_name' => $bill['bill_name'],
            'category_name' => $bill['category_name'],
            'amount' => (float)$bill['amount'],
            'paid_amount' => (float)$bill['paid_amount'],
            'remaining' => (float)$bill['amount'] - (float)$bill['paid_amount'],
            'status' => $bill['status'],
            'payment_count' => (int)$bill['payment_count'],
            'is_recurring' => (int)$bill['is_recurring']
        ];
    }

    echo json_encode([
        'success' => true,
        'month' => $month,
        'summary' => [
            'total_bills' => (int)($summary['total_bills'] ?? 0),
            'total_amount' => (float)($summary['total_amount'] ?? 0),
            'total_paid' => (float)($summary['total_paid'] ?? 0),
            'total_remaining' => (float)($summary['total_remaining'] ?? 0),
            'percentage_paid' => $summary['total_amount'] > 0 
                ? round((($summary['total_paid'] ?? 0) / $summary['total_amount']) * 100, 2)
                : 0
        ],
        'by_status' => $byStatus,
        'by_category' => $byCategory,
        'payment_methods' => $paymentMethods,
        'bills' => $formattedBills
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>
