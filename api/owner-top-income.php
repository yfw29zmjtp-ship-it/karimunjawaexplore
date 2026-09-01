<?php
/**
 * API: Owner Top Income
 * Get top 3 highest income transactions this month
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

// Get selected business
$branchId = isset($_GET['branch_id']) ? $_GET['branch_id'] : 'all';

try {
    $thisMonth = date('Y-m');
    
    // Get businesses list with their database names
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($branchId === 'all' || $branchId === '') {
        $stmt = $mainPdo->query("SELECT id, business_name, database_name FROM businesses WHERE is_active = 1 ORDER BY id");
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $mainPdo->prepare("SELECT id, business_name, database_name FROM businesses WHERE id = ? AND is_active = 1");
        $stmt->execute([$branchId]);
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $allIncomeTransactions = [];
    
    // Loop through each business database
    foreach ($businesses as $business) {
        try {
            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName($business['database_name']) . ";charset=utf8mb4", DB_USER, DB_PASS);
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get top income transactions this month
            $stmt = $bizPdo->prepare(
                "SELECT 
                    cb.id,
                    cb.description,
                    cb.amount,
                    cb.transaction_date,
                    COALESCE(c.category_name, 'Income') as category_name,
                    ? as business_name
                 FROM cash_book cb
                 LEFT JOIN categories c ON cb.category_id = c.id
                 WHERE cb.transaction_type = 'income' 
                   AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?
                 ORDER BY cb.amount DESC
                 LIMIT 10"
            );
            $stmt->execute([$business['business_name'], $thisMonth]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($transactions as $trans) {
                $allIncomeTransactions[] = $trans;
            }
            
        } catch (Exception $bizError) {
            // Skip this business if error
            continue;
        }
    }
    
    // Sort all transactions by amount descending and take top 3
    usort($allIncomeTransactions, function($a, $b) {
        return (float)$b['amount'] - (float)$a['amount'];
    });
    
    $topIncome = array_slice($allIncomeTransactions, 0, 3);
    
    echo json_encode([
        'success' => true,
        'top_income' => $topIncome,
        'month' => $thisMonth,
        'business_count' => count($businesses)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
