<?php
/**
 * API: Convert USD to IDR
 */

session_start();
defined('APP_ACCESS') or define('APP_ACCESS', true);

header('Content-Type: application/json');

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/ExchangeRateManager.php';

$auth = new Auth();

// Check authentication
if (!$auth->isLoggedIn()) {
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
    $input = json_decode(file_get_contents('php://input'), true);
    $amount_usd = $input['amount_usd'] ?? null;

    if (!$amount_usd) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Amount USD required'
        ]);
        exit;
    }

    $db = Database::getInstance()->getConnection();
    $exchange_manager = new ExchangeRateManager($db);

    $result = $exchange_manager->convertToIDR((float) $amount_usd);
    
    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
