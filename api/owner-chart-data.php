<?php
/**
 * API: Owner Chart Data
 * Get chart data for income/expense trends
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

// Check if user is owner, admin, manager, or developer
$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'], ['owner', 'admin', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$period = isset($_GET['period']) ? $_GET['period'] : '7days';

// Validate period
$validPeriods = ['7days', '30days', '12months'];
if (!in_array($period, $validPeriods)) {
    $period = '7days';
}

try {
    // Switch to hotel database
    $db = Database::switchDatabase(getDbName('adf_narayana_hotel'));
    
    $labels = [];
    $incomeData = [];
    $expenseData = [];
    
    if ($period === '7days') {
        // Last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $labels[] = date('D', strtotime($date));
            
            $result = $db->fetchOne(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
                 FROM cash_book
                 WHERE transaction_date = ?",
                [$date]
            );
            
            $incomeData[] = (float)($result['income'] ?? 0);
            $expenseData[] = (float)($result['expense'] ?? 0);
        }
        
    } elseif ($period === '30days') {
        // This month - show weekly summaries
        $weekNum = 1;
        $startOfMonth = date('Y-m-01');
        $endOfMonth = date('Y-m-t');
        
        // Split into 4 weeks
        for ($week = 0; $week < 4; $week++) {
            $weekStart = date('Y-m-d', strtotime($startOfMonth . " +$week weeks"));
            $weekEnd = date('Y-m-d', strtotime($weekStart . " +6 days"));
            if ($weekEnd > $endOfMonth) $weekEnd = $endOfMonth;
            
            $labels[] = "Week " . ($week + 1);
            
            $result = $db->fetchOne(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
                 FROM cash_book
                 WHERE transaction_date BETWEEN ? AND ?",
                [$weekStart, $weekEnd]
            );
            
            $incomeData[] = (float)($result['income'] ?? 0);
            $expenseData[] = (float)($result['expense'] ?? 0);
        }
        
    } elseif ($period === '12months') {
        // This year by month
        $currentYear = date('Y');
        
        for ($month = 1; $month <= 12; $month++) {
            $monthStr = sprintf('%04d-%02d', $currentYear, $month);
            $labels[] = date('M', mktime(0, 0, 0, $month, 1));
            
            $result = $db->fetchOne(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
                 FROM cash_book
                 WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ?",
                [$monthStr]
            );
            
            $incomeData[] = (float)($result['income'] ?? 0);
            $expenseData[] = (float)($result['expense'] ?? 0);
        }
    }
    
    echo json_encode([
        'success' => true,
        'period' => $period,
        'data' => [
            'labels' => $labels,
            'income' => $incomeData,
            'expense' => $expenseData
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'period' => $period
    ]);
}
