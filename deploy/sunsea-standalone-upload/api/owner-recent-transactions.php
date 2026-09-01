<?php

/**
 * API: Owner Recent Transactions
 * Get recent transactions
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

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$todayOnly = isset($_GET['today']) && $_GET['today'] == '1';
$allDaily = isset($_GET['all_daily']) && $_GET['all_daily'] == '1';
$branchId = isset($_GET['branch_id']) ? $_GET['branch_id'] : 'all';

try {
    // Get businesses list from master database
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($branchId === 'all' || $branchId === '') {
        $stmt = $mainPdo->query("SELECT id, business_name, database_name FROM businesses WHERE is_active = 1 ORDER BY id");
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $mainPdo->prepare("SELECT id, business_name, database_name FROM businesses WHERE id = ? AND is_active = 1");
        $stmt->execute([(int)$branchId]);
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $allTransactions = [];

    foreach ($businesses as $business) {
        try {
            $db = Database::switchDatabase(getDbName($business['database_name']));

            // Build date filter
            if ($allDaily || $todayOnly) {
                $dateFilter = "DATE(cb.transaction_date) = CURDATE()";
            } else {
                $dateFilter = "DATE(cb.transaction_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            }

            // Build limit clause
            $limitClause = $allDaily ? "" : "LIMIT ?";
            $params = $allDaily ? [] : [$limit];

            // Get recent transactions from this business
            $transactions = $db->fetchAll(
                "SELECT 
                    cb.*,
                    d.division_name,
                    c.category_name,
                    u.full_name as user_name
                 FROM cash_book cb
                 LEFT JOIN divisions d ON cb.division_id = d.id
                 LEFT JOIN categories c ON cb.category_id = c.id
                 LEFT JOIN users u ON cb.created_by = u.id
                 WHERE $dateFilter
                 ORDER BY cb.transaction_date DESC, cb.transaction_time DESC
                 $limitClause",
                $params
            );

            // Add business name to each transaction
            foreach ($transactions as &$tx) {
                $tx['business_name'] = $business['business_name'];
                $tx['business_id'] = $business['id'];
                $tx['formatted_date'] = date('d M Y', strtotime($tx['transaction_date']));
                $tx['formatted_amount'] = number_format($tx['amount'], 0, ',', '.');
            }

            $allTransactions = array_merge($allTransactions, $transactions);
        } catch (Exception $e) {
            // Skip this business if database error
            continue;
        }
    }

    // Sort all transactions by date and time descending
    usort($allTransactions, function ($a, $b) {
        $dateCompare = strcmp($b['transaction_date'], $a['transaction_date']);
        if ($dateCompare !== 0) return $dateCompare;
        return strcmp($b['transaction_time'] ?? '', $a['transaction_time'] ?? '');
    });

    // Apply limit for combined results
    if (!$allDaily && count($allTransactions) > $limit) {
        $allTransactions = array_slice($allTransactions, 0, $limit);
    }

    echo json_encode([
        'success' => true,
        'transactions' => $allTransactions,
        'count' => count($allTransactions),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
