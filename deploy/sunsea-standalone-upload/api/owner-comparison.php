<?php
/**
 * API: Owner Comparison
 * Get comparison data between all businesses
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

$period = isset($_GET['period']) ? $_GET['period'] : 'today'; // today, this_month, this_year

try {
    // Get businesses from main master DB using direct PDO
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $mainPdo->query("SELECT id, business_name, database_name FROM businesses WHERE is_active = 1 ORDER BY id");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build date filter based on period
    $dateFilter = "";
    switch ($period) {
        case 'today':
            $dateFilter = "transaction_date = CURDATE()";
            break;
        case 'this_month':
            $dateFilter = "DATE_FORMAT(transaction_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
            break;
        case 'this_year':
            $dateFilter = "YEAR(transaction_date) = YEAR(CURDATE())";
            break;
        default:
            $dateFilter = "transaction_date = CURDATE()";
    }
    
    // Get stats from each business database
    $businessStats = [];
    
    foreach ($businesses as $business) {
        try {
            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName($business['database_name']) . ";charset=utf8mb4", DB_USER, DB_PASS);
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get categories to exclude (modal, transfer dana, petty cash, etc.)
            // These are capital/operational funds, not actual business income
            $excludeCategories = [];
            try {
                $catStmt = $bizPdo->query("
                    SELECT id FROM categories 
                    WHERE LOWER(category_name) LIKE '%modal%' 
                    OR LOWER(category_name) LIKE '%transfer%dana%'
                    OR LOWER(category_name) LIKE '%petty%cash%'
                    OR LOWER(category_name) LIKE '%kas%kecil%'
                    OR LOWER(category_name) LIKE '%capital%'
                ");
                $excludeCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {}
            
            $excludeClause = "";
            if (!empty($excludeCategories)) {
                $excludeClause = " AND (category_id IS NULL OR category_id NOT IN (" . implode(',', $excludeCategories) . "))";
            }
            
            // Also exclude by description for entries without proper category
            $excludeDescClause = " AND (
                LOWER(description) NOT LIKE '%modal operasional%'
                AND LOWER(description) NOT LIKE '%transfer dana%'
                AND LOWER(description) NOT LIKE '%petty cash%'
                AND LOWER(description) NOT LIKE '%kas kecil%'
            )";
            
            $stmt = $bizPdo->query(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense,
                    COUNT(CASE WHEN transaction_type = 'income' THEN 1 END) as income_count,
                    COUNT(CASE WHEN transaction_type = 'expense' THEN 1 END) as expense_count
                 FROM cash_book 
                 WHERE $dateFilter $excludeClause $excludeDescClause"
            );
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $businessStats[] = [
                'id' => $business['id'],
                'name' => $business['business_name'],
                'income' => (float)($stats['income'] ?? 0),
                'expense' => (float)($stats['expense'] ?? 0),
                'net' => (float)($stats['income'] ?? 0) - (float)($stats['expense'] ?? 0),
                'income_count' => (int)($stats['income_count'] ?? 0),
                'expense_count' => (int)($stats['expense_count'] ?? 0)
            ];
        } catch (Exception $e) {
            // If database doesn't exist or cash_book table missing, show 0 data
            $businessStats[] = [
                'id' => $business['id'],
                'name' => $business['business_name'],
                'income' => 0,
                'expense' => 0,
                'net' => 0,
                'income_count' => 0,
                'expense_count' => 0
            ];
        }
    }
    
    // Calculate totals
    $totalIncome = array_sum(array_column($businessStats, 'income'));
    $totalExpense = array_sum(array_column($businessStats, 'expense'));
    
    echo json_encode([
        'success' => true,
        'period' => $period,
        'businesses' => $businessStats,
        'totals' => [
            'income' => $totalIncome,
            'expense' => $totalExpense,
            'net' => $totalIncome - $totalExpense
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
