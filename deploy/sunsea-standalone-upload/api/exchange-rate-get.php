<?php
/**
 * API: Get Current Exchange Rate
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

try {
    $db = Database::getInstance()->getConnection();
    $exchange_manager = new ExchangeRateManager($db);

    // Get current rate
    $rate = $exchange_manager->getCurrentRate();

    if (!$rate) {
        // Try to update rate from API
        $update_result = $exchange_manager->updateRateAuto();
        if ($update_result['success']) {
            $rate = $exchange_manager->getCurrentRate();
        }
    }

    if (!$rate) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Exchange rate not available',
            'rate' => 15500 // Fallback rate
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'rate' => (float) $rate['usd_to_idr'],
        'date' => $rate['date_of_rate'],
        'source' => $rate['source']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'rate' => 15500 // Fallback
    ]);
}
?>
