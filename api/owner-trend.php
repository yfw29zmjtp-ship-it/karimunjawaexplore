<?php
/**
 * API: Owner Trend Data
 * Get income/expense trend for last N days
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
if (!in_array($currentUser['role'], ['owner', 'admin', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$days = isset($_GET['days']) ? intval($_GET['days']) : 7;
if ($days < 1 || $days > 30) $days = 7;

try {
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get active businesses
    $stmt = $mainPdo->query("SELECT id, business_name, database_name FROM businesses WHERE is_active = 1 ORDER BY id");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Initialize arrays for each day
    $labels = [];
    $incomeByDay = [];
    $expenseByDay = [];
    
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('d M', strtotime($date));
        $incomeByDay[$date] = 0;
        $expenseByDay[$date] = 0;
    }
    
    // Aggregate from all businesses
    foreach ($businesses as $business) {
        try {
            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName($business['database_name']) . ";charset=utf8mb4", DB_USER, DB_PASS);
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get owner capital account IDs to exclude
            $ownerCapitalIds = [];
            try {
                $stmt = $mainPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
                $stmt->execute([$business['id']]);
                $ownerCapitalIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {}
            
            $excludeClause = "";
            if (!empty($ownerCapitalIds)) {
                $placeholders = implode(',', array_fill(0, count($ownerCapitalIds), '?'));
                $excludeClause = " AND (cash_account_id IS NULL OR cash_account_id NOT IN ($placeholders))";
            }
            
            $startDate = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
            $endDate = date('Y-m-d');
            
            $params = [$startDate, $endDate];
            if (!empty($ownerCapitalIds)) {
                $params = array_merge($params, $ownerCapitalIds);
            }
            
            $stmt = $bizPdo->prepare(
                "SELECT transaction_date, transaction_type, SUM(amount) as total 
                 FROM cash_book 
                 WHERE transaction_date BETWEEN ? AND ?" . $excludeClause . "
                 GROUP BY transaction_date, transaction_type"
            );
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($results as $row) {
                $date = $row['transaction_date'];
                if (isset($incomeByDay[$date])) {
                    if ($row['transaction_type'] === 'income') {
                        $incomeByDay[$date] += (float)$row['total'];
                    } else {
                        $expenseByDay[$date] += (float)$row['total'];
                    }
                }
            }
            
        } catch (Exception $e) {
            continue;
        }
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'income' => array_values($incomeByDay),
        'expense' => array_values($expenseByDay)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
