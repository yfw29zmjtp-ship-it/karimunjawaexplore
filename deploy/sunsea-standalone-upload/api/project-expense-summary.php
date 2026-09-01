<?php
/**
 * API: Get Project Expense Summary (for Charts)
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
require_once __DIR__ . '/../includes/ProjectManager.php';

$auth = new Auth();

// Check authentication and permission
if (!$auth->isLoggedIn() || !$auth->hasPermission('project')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $project_manager = new ProjectManager($db);

    $project_id = $_GET['project_id'] ?? null;

    if (!$project_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Project ID required'
        ]);
        exit;
    }

    $summary = $project_manager->getExpenseSummaryByCategory($project_id);

    echo json_encode([
        'success' => true,
        'data' => $summary
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
