<?php
/**
 * API Endpoint: Switch Active Business
 * Handles business switching via AJAX
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/business_helper.php';
require_once __DIR__ . '/../includes/business_access.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please login first.'
    ]);
    exit;
}

$currentUser = $auth->getCurrentUser();

// Check if business_id is provided
if (!isset($_POST['business_id']) || empty($_POST['business_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Business ID is required.'
    ]);
    exit;
}

$businessId = sanitize($_POST['business_id']);

// Sync business configs first so newly added businesses are recognized
if (function_exists('autoSyncBusinessConfigs')) {
    autoSyncBusinessConfigs();
}

// Validate business exists first
$businessFile = __DIR__ . '/../config/businesses/' . $businessId . '.php';
if (!file_exists($businessFile)) {
    echo json_encode([
        'success' => false,
        'message' => 'Business not found. Invalid business ID: ' . $businessId
    ]);
    exit;
}

// Check if user has access to this business via master database
try {
    $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Resolve target business numeric ID dynamically (supports new businesses/slugs)
    $bizId = function_exists('getNumericBusinessId') ? (int)getNumericBusinessId($businessId) : 0;

    // Fallback if helper resolution fails
    if ($bizId <= 0) {
        $slugCodeCandidates = [
            strtoupper(str_replace('-', '_', $businessId)),
            strtoupper(str_replace('-', '', $businessId))
        ];

        foreach ($slugCodeCandidates as $candidateCode) {
            $bizStmt = $masterPdo->prepare("SELECT id FROM businesses WHERE business_code = ? LIMIT 1");
            $bizStmt->execute([$candidateCode]);
            $foundBizId = (int)$bizStmt->fetchColumn();
            if ($foundBizId > 0) {
                $bizId = $foundBizId;
                break;
            }
        }
    }
    
    if ($bizId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Business not found in system.'
        ]);
        exit;
    }
    
    // Check if username exists in master and has permission
    $username = $currentUser['username'];
    $userStmt = $masterPdo->prepare("SELECT u.id, r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = ?");
    $userStmt->execute([$username]);
    $masterUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$masterUserData) {
        echo json_encode([
            'success' => false,
            'message' => 'User not registered in master system. Contact developer.'
        ]);
        exit;
    }
    
    $masterId = $masterUserData['id'];
    $roleCode = $masterUserData['role_code'];
    
    // Developer role has full access - skip permission check
    if ($roleCode !== 'developer') {
        // Check permissions for non-developer users
        $permStmt = $masterPdo->prepare(
            "SELECT COUNT(*) FROM user_menu_permissions 
             WHERE user_id = ? AND business_id = ?"
        );
        $permStmt->execute([$masterId, $bizId]);
        $hasPermission = $permStmt->fetchColumn() > 0;
        
        if (!$hasPermission) {
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. You do not have permission to access this business.'
            ]);
            exit;
        }
    }
    
} catch (PDOException $e) {
    error_log('Switch business permission check error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'System error occurred.'
    ]);
    exit;
}

// Attempt to switch business
if (setActiveBusinessId($businessId)) {
    $businessName = getBusinessDisplayName($businessId);
    
    echo json_encode([
        'success' => true,
        'message' => "Successfully switched to: {$businessName}",
        'business_id' => $businessId,
        'business_name' => $businessName
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Business not found or invalid business ID.'
    ]);
}
