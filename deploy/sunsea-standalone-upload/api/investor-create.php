<?php
/**
 * API: Create New Investor
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

// Load config first
require_once __DIR__ . '/../config/config.php';

// Start session with correct name
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/InvestorManager.php';

$auth = new Auth();

// Check authentication and permission
if (!$auth->isLoggedIn() || !$auth->hasPermission('investor')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $investor_manager = new InvestorManager($db);

    $data = [
        'name' => $_POST['investor_name'] ?? null,
        'contact' => $_POST['contact_phone'] ?? null,
        'email' => $_POST['email'] ?? null,
        'notes' => $_POST['notes'] ?? null
    ];

    // Validate required fields
    if (!$data['name']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Nama investor harus diisi'
        ]);
        exit;
    }

    $result = $investor_manager->createInvestor($data, $_SESSION['user_id']);
    
    http_response_code($result['success'] ? 201 : 400);
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
