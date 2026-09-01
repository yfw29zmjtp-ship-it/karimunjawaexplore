<?php
/**
 * API: Get Investor Capital Summary (for Chart)
 */

session_start();
defined('APP_ACCESS') or define('APP_ACCESS', true);

header('Content-Type: application/json');

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/InvestorManager.php';

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

try {
    $db = Database::getInstance()->getConnection();
    $investor_manager = new InvestorManager($db);

    $summary = $investor_manager->getCapitalSummary();

    echo json_encode([
        'success' => true,
        'data' => $summary,
        'total_investors' => count($summary),
        'total_capital' => array_sum(array_column($summary, 'total_capital'))
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
