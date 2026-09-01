<?php
/**
 * API: Owner Weekly Chart
 * Get 7 days transaction data for chart
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$currentUser = $auth->getCurrentUser();

// Check if user is owner, admin, manager, or developer
if (!in_array($currentUser['role'], ['owner', 'admin', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Switch to hotel database
$db = Database::switchDatabase(getDbName('adf_narayana_hotel'));
$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

try {
    // Get last 7 days
    $dates = [];
    for ($i = 6; $i >= 0; $i--) {
        $dates[] = date('Y-m-d', strtotime("-$i days"));
    }
    
    // Build WHERE clause for branch filter
    $branchWhere = '';
    $params = [];
    if ($branchId) {
        $branchWhere = ' AND branch_id = :branch_id';
        $params['branch_id'] = $branchId;
    }
    
    // Get transaction data
    $transData = $db->fetchAll(
        "SELECT 
            DATE(transaction_date) as date,
            SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as income,
            SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as expense
         FROM cash_book
         WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)" . $branchWhere . "
         GROUP BY DATE(transaction_date)
         ORDER BY date",
        $params
    );
    
    // Map transaction data by date
    $transMap = [];
    foreach ($transData as $data) {
        $transMap[$data['date']] = $data;
    }
    
    // Fill all 7 days (missing days will have 0 values)
    $labels = [];
    $income = [];
    $expense = [];
    
    $dayNames = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    
    foreach ($dates as $date) {
        $dayIndex = date('w', strtotime($date));
        $dayShort = date('d/m', strtotime($date));
        $labels[] = $dayNames[$dayIndex] . ' ' . $dayShort;
        
        $income[] = isset($transMap[$date]) ? (float)$transMap[$date]['income'] : 0;
        $expense[] = isset($transMap[$date]) ? (float)$transMap[$date]['expense'] : 0;
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'income' => $income,
        'expense' => $expense,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
