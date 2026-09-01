<?php
/**
 * API: Owner Branches - Simple Version
 * Return current business only (no multi-tenant)
 */

// Use same session name as main app
define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Auth check - try session role first, fallback to logged_in user
$role = $_SESSION['role'] ?? null;
$userId = $_SESSION['user_id'] ?? null;
$isLoggedIn = $_SESSION['logged_in'] ?? false;

// Debug info
$debugInfo = [
    'session_role' => $role,
    'user_id' => $userId,
    'logged_in' => $isLoggedIn,
    'business_id' => $_SESSION['business_id'] ?? null,
    'active_business_id' => $_SESSION['active_business_id'] ?? null,
    'session_id' => session_id()
];

if (!$role && $isLoggedIn && $userId) {
    try {
        $authDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $roleStmt = $authDb->prepare("SELECT r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $roleStmt->execute([$userId]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($roleRow) {
            $role = $roleRow['role_code'];
            $_SESSION['role'] = $role;
            $debugInfo['role_from_db'] = $role;
        }
    } catch (Exception $e) {
        $debugInfo['db_error'] = $e->getMessage();
    }
}

// Very lenient check - allow if ANY auth indicator exists
$hasBusinessId = !empty($_SESSION['business_id']) || !empty($_SESSION['active_business_id']);
if (!$isLoggedIn && !$role && !$hasBusinessId) {
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized - Please login first or select a business',
        'debug' => $debugInfo,
        'hint' => 'Need session: logged_in=true OR role OR business_id'
    ]);
    exit;
}

// Check role permissions
if ($role && !in_array($role, ['owner', 'admin', 'manager', 'developer', 'frontdesk', 'staff'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'Access denied - Invalid role',
        'debug' => $debugInfo
    ]);
    exit;
}

// Query all businesses from master database
try {
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;
    $masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $masterDbName, DB_USER, DB_PASS);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $bizStmt = $masterPdo->query("SELECT id, business_code, business_name, business_type, database_name FROM businesses WHERE is_active = 1 ORDER BY business_name");
    $businesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $branches = [];
    foreach ($businesses as $biz) {
        $branches[] = [
            'id' => (int)$biz['id'],
            'business_code' => $biz['business_code'],
            'branch_name' => $biz['business_name'],
            'business_name' => $biz['business_name'],
            'business_type' => $biz['business_type'],
            'database_name' => $biz['database_name']
        ];
    }
    
    // Fallback if no businesses in table
    if (empty($branches)) {
        // Hardcoded fallback for known businesses
        $branches = [
            [
                'id' => 1,
                'business_code' => 'narayana',
                'branch_name' => 'Narayana Hotel',
                'business_name' => 'Narayana Hotel',
                'business_type' => 'hotel',
                'database_name' => 'adf_narayana_hotel'
            ],
            [
                'id' => 2,
                'business_code' => 'benscafe',
                'branch_name' => "Ben's Cafe",
                'business_name' => "Ben's Cafe",
                'business_type' => 'restaurant',
                'database_name' => 'adf_benscafe'
            ]
        ];
    }
    
    echo json_encode([
        'success' => true,
        'branches' => $branches,
        'count' => count($branches),
        'debug' => $debugInfo
    ]);
} catch (Exception $e) {
    // Fallback to single business
    $businessName = defined('BUSINESS_NAME') ? BUSINESS_NAME : 'My Business';
    $fallbackDb = defined('ACTIVE_DB_NAME') ? ACTIVE_DB_NAME : DB_NAME;
    
    echo json_encode([
        'success' => true,
        'branches' => [
            [
                'id' => 1,
                'business_code' => 'default',
                'branch_name' => $businessName,
                'business_name' => $businessName,
                'business_type' => 'hotel',
                'database_name' => $fallbackDb
            ]
        ],
        'count' => 1,
        'error' => $e->getMessage(),
        'debug' => $debugInfo
    ]);
}
?>
