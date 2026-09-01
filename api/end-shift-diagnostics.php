<?php
/**
 * End Shift Diagnostics - Test API & Database
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

// Check authentication
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

header('Content-Type: application/json');

$diagnostics = [
    'status' => 'ok',
    'checks' => [],
    'errors' => []
];

// Check 1: Database connection
try {
    $conn = $db->getConnection();
    if ($conn) {
        $diagnostics['checks'][] = ['name' => 'Database Connection', 'status' => 'OK'];
    }
} catch (Exception $e) {
    $diagnostics['errors'][] = 'Database Connection Failed: ' . $e->getMessage();
    $diagnostics['status'] = 'error';
}

// Check 2: Users table exists
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM users LIMIT 1");
    $diagnostics['checks'][] = ['name' => 'Users Table', 'status' => 'OK', 'count' => $result['count'] ?? 0];
} catch (Exception $e) {
    $diagnostics['errors'][] = 'Users Table Check Failed: ' . $e->getMessage();
}

// Check 3: Cash Book table exists
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM cash_book LIMIT 1");
    $diagnostics['checks'][] = ['name' => 'Cash Book Table', 'status' => 'OK', 'count' => $result['count'] ?? 0];
} catch (Exception $e) {
    $diagnostics['errors'][] = 'Cash Book Table: ' . $e->getMessage();
}

// Check 4: Purchase Orders table exists
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM purchase_orders LIMIT 1");
    $diagnostics['checks'][] = ['name' => 'Purchase Orders Table', 'status' => 'OK', 'count' => $result['count'] ?? 0];
} catch (Exception $e) {
    $diagnostics['errors'][] = 'Purchase Orders Table: ' . $e->getMessage();
}

// Check 5: Divisions table exists
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM divisions LIMIT 1");
    $diagnostics['checks'][] = ['name' => 'Divisions Table', 'status' => 'OK', 'count' => $result['count'] ?? 0];
} catch (Exception $e) {
    $diagnostics['errors'][] = 'Divisions Table: ' . $e->getMessage();
}

// Check 6: Business Settings table exists
try {
    $result = $db->fetchOne("SELECT COUNT(*) as count FROM business_settings LIMIT 1");
    $diagnostics['checks'][] = ['name' => 'Business Settings Table', 'status' => 'OK', 'count' => $result['count'] ?? 0];
} catch (Exception $e) {
    $diagnostics['errors'][] = 'Business Settings Table: ' . $e->getMessage();
}

// Check 7: Get today's transactions
try {
    $today = date('Y-m-d');
    $transactions = $db->fetchAll("
        SELECT COUNT(*) as count FROM cash_book 
        WHERE DATE(transaction_date) = ?
    ", [$today]);
    $transCount = $transactions[0]['count'] ?? 0;
    $diagnostics['checks'][] = ['name' => 'Today Transactions', 'status' => 'OK', 'count' => $transCount];
} catch (Exception $e) {
    $diagnostics['errors'][] = 'Today Transactions Query: ' . $e->getMessage();
}

// Check 8: Get today's POs
try {
    $today = date('Y-m-d');
    $pos = $db->fetchAll("
        SELECT COUNT(*) as count FROM purchase_orders 
        WHERE DATE(created_at) = ?
    ", [$today]);
    $poCount = $pos[0]['count'] ?? 0;
    $diagnostics['checks'][] = ['name' => 'Today POs', 'status' => 'OK', 'count' => $poCount];
} catch (Exception $e) {
    $diagnostics['errors'][] = 'Today POs Query: ' . $e->getMessage();
}

// Check 9: Current user info
$diagnostics['user'] = [
    'id' => $currentUser['id'] ?? null,
    'username' => $currentUser['username'] ?? null,
    'role' => $currentUser['role'] ?? null,
    'business_id' => $currentUser['business_id'] ?? null
];

// Check 10: User columns
try {
    $userCols = $db->fetchAll("SHOW COLUMNS FROM users WHERE Field IN ('phone', 'email')");
    $diagnostics['checks'][] = ['name' => 'User Columns', 'status' => 'OK', 'columns' => count($userCols)];
} catch (Exception $e) {
    $diagnostics['errors'][] = 'User Columns Check: ' . $e->getMessage();
}

echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
