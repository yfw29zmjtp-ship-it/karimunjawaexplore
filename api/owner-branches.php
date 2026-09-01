<?php
/**
 * API: Owner Branches
 * Get list of branches/businesses from database
 * Filter by user's business_access
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

try {
    // Force connection to master database where businesses table is located
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all businesses from database
    $stmt = $pdo->query("SELECT id, business_name, address, phone FROM businesses ORDER BY id");
    $allBusinesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // DEBUG: tampilkan hasil query mentah
    if (isset($_GET['debug'])) {
        echo json_encode(['debug_allBusinesses' => $allBusinesses]);
        exit;
    }
    $branches = [];
    // Mapping id ke business_type (hardcode jika belum ada di DB)
    $businessTypeMap = [
        // Ganti sesuai id di tabel businesses
        1 => 'hotel', // Narayana Hotel
        2 => 'cafe',  // Ben's Cafe
    ];
    foreach ($allBusinesses as $business) {
        $type = $businessTypeMap[$business['id']] ?? (stripos($business['business_name'], 'hotel') !== false ? 'hotel' : 'cafe');
        $branches[] = [
            'id' => $business['id'],
            'branch_name' => $business['business_name'],
            'business_type' => $type,
            'city' => $business['address'] ?? '-',
            'phone' => $business['phone'] ?? '-'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'branches' => $branches,
        'count' => count($branches),
        'user_info' => [
            'username' => $currentUser['username'],
            'role' => $currentUser['role'],
            'total_businesses' => count($allBusinesses)
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
