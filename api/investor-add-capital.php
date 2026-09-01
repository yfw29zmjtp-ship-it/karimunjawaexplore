<?php
/**
 * API: Add Capital Transaction (USD to IDR Conversion)
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

require_once __DIR__ . '/../config/config.php';

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

    $investor_id = $_POST['investor_id'] ?? null;
    $amount_usd = $_POST['amount_usd'] ?? null;

    // Validate
    if (!$investor_id || !$amount_usd) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Investor ID dan jumlah USD harus diisi'
        ]);
        exit;
    }

    // Check investor exists
    $investor = $investor_manager->getInvestorById($investor_id);
    if (!$investor) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Investor tidak ditemukan'
        ]);
        exit;
    }

    $data = [
        'amount_usd' => (float) $amount_usd,
        'transaction_date' => $_POST['transaction_date'] ?? date('Y-m-d'),
        'transaction_time' => $_POST['transaction_time'] ?? date('H:i:s'),
        'payment_method' => $_POST['payment_method'] ?? 'bank_transfer',
        'reference_no' => $_POST['reference_no'] ?? null,
        'description' => $_POST['description'] ?? null
    ];

    $result = $investor_manager->addCapitalTransaction($investor_id, $data, $_SESSION['user_id']);
    
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
