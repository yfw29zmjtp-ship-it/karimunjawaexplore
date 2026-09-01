<?php
/**
 * API: Create New Project
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $project_manager = new ProjectManager($db);

    $data = [
        'project_code' => $_POST['project_code'] ?? null,
        'project_name' => $_POST['project_name'] ?? null,
        'description' => $_POST['description'] ?? null,
        'location' => $_POST['location'] ?? null,
        'budget_idr' => $_POST['budget_idr'] ?? null,
        'status' => $_POST['status'] ?? 'planning',
        'start_date' => $_POST['start_date'] ?? null,
        'end_date' => $_POST['end_date'] ?? null
    ];

    // Validate required fields
    if (!$data['project_code'] || !$data['project_name']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Kode project dan nama project harus diisi'
        ]);
        exit;
    }

    $result = $project_manager->createProject($data, $_SESSION['user_id']);
    
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
