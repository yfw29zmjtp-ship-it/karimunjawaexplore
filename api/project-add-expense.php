<?php
/**
 * API: Add Project Expense (dengan auto-deduction investor balance)
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
require_once __DIR__ . '/../includes/InvestorManager.php';

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
    $investor_manager = new InvestorManager($db);

    $data = [
        'project_id' => $_POST['project_id'] ?? null,
        'expense_category_id' => $_POST['expense_category_id'] ?? null,
        'expense_date' => $_POST['expense_date'] ?? null,
        'expense_time' => $_POST['expense_time'] ?? date('H:i:s'),
        'amount_idr' => $_POST['amount_idr'] ?? null,
        'description' => $_POST['description'] ?? null,
        'reference_no' => $_POST['reference_no'] ?? null,
        'payment_method' => $_POST['payment_method'] ?? 'cash',
        'status' => 'paid'  // Auto-approved & paid, langsung potong kas investor
    ];

    // Validate required fields
    if (!$data['project_id'] || !$data['expense_category_id'] || !$data['amount_idr']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Project, kategori, dan jumlah harus diisi'
        ]);
        exit;
    }

    // Add expense (status paid, investor balance will be auto-deducted)
    $result = $project_manager->addExpense(
        $data['project_id'],
        $data,
        $_SESSION['user_id']
    );

    if ($result['success']) {
        // Expense was paid - Investor balance is automatically deducted by ProjectManager
        error_log('Expense paid - Investor balance auto-deducted: Rp ' . number_format($data['amount_idr'], 0, ',', '.'));
    }
    
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
