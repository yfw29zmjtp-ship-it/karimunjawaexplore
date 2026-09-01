<?php
/**
 * API: Recent Transactions
 * Get latest transactions from all businesses
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

// Try Auth class first, fallback to session check
$authOk = false;
$userRole = null;

try {
    $auth = new Auth();
    if ($auth->isLoggedIn()) {
        $currentUser = $auth->getCurrentUser();
        $userRole = $currentUser['role'] ?? null;
        if (in_array($userRole, ['owner', 'admin', 'manager', 'developer'])) {
            $authOk = true;
        }
    }
} catch (Exception $e) {
    // Auth class failed, try session directly
}

// Fallback to session check if Auth class didn't work
if (!$authOk) {
    $isLoggedIn = $_SESSION['logged_in'] ?? false;
    $sessionRole = $_SESSION['role'] ?? null;
    $hasBusinessId = !empty($_SESSION['business_id']) || !empty($_SESSION['active_business_id']);
    
    // Allow if any auth indicator exists
    if ($isLoggedIn || $sessionRole || $hasBusinessId) {
        $authOk = true;
        $userRole = $sessionRole ?? 'owner'; // Default to owner if role not set
    }
}

if (!$authOk) {
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized - Please login first',
        'hint' => 'Need session: logged_in=true OR role OR business_id'
    ]);
    exit;
}

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
if ($limit < 1 || $limit > 50) $limit = 10;

// Get business filter parameters
$requestedDb = isset($_GET['db']) ? $_GET['db'] : '';
$requestedBizId = isset($_GET['biz_id']) ? intval($_GET['biz_id']) : 0;

try {
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get active businesses (filter by requested business if specified)
    if ($requestedDb && $requestedBizId) {
        $stmt = $mainPdo->prepare("SELECT id, business_name, database_name FROM businesses WHERE id = ? AND is_active = 1");
        $stmt->execute([$requestedBizId]);
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $mainPdo->query("SELECT id, business_name, database_name FROM businesses WHERE is_active = 1 ORDER BY id");
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $allTransactions = [];
    
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
            $params = [];
            if (!empty($ownerCapitalIds)) {
                $placeholders = implode(',', array_fill(0, count($ownerCapitalIds), '?'));
                $excludeClause = " WHERE (cash_account_id IS NULL OR cash_account_id NOT IN ($placeholders))";
                $params = $ownerCapitalIds;
            }
            
            $stmt = $bizPdo->prepare(
                "SELECT cb.id, cb.transaction_date, cb.description, cb.amount, cb.transaction_type,
                        COALESCE(d.name, 'General') as division_name, 
                        COALESCE(c.name, '') as category_name
                 FROM cash_book cb
                 LEFT JOIN divisions d ON cb.division_id = d.id
                 LEFT JOIN categories c ON cb.category_id = c.id
                 $excludeClause
                 ORDER BY cb.transaction_date DESC, cb.id DESC
                 LIMIT " . ($limit * 2)
            );
            $stmt->execute($params);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($transactions as $tx) {
                $tx['business_name'] = $business['business_name'];
                $allTransactions[] = $tx;
            }
            
        } catch (Exception $e) {
            continue;
        }
    }
    
    // Sort by date desc and limit
    usort($allTransactions, function($a, $b) {
        $dateCompare = strcmp($b['transaction_date'], $a['transaction_date']);
        if ($dateCompare !== 0) return $dateCompare;
        return $b['id'] - $a['id'];
    });
    
    $allTransactions = array_slice($allTransactions, 0, $limit);
    
    echo json_encode([
        'success' => true,
        'transactions' => $allTransactions
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
