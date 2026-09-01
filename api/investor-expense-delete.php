<?php
/**
 * API: Hapus Pengeluaran Investor
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

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $expense_id = intval($_POST['expense_id'] ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);

    // Validate
    if (!$expense_id) {
        echo json_encode(['success' => false, 'message' => 'Pengeluaran tidak ditemukan']);
        exit;
    }

    // Verify expense exists
    if ($project_id > 0) {
        $stmt = $db->prepare("SELECT id FROM project_expenses WHERE id = ? AND project_id = ?");
        $stmt->execute([$expense_id, $project_id]);
    } else {
        $stmt = $db->prepare("SELECT id FROM project_expenses WHERE id = ?");
        $stmt->execute([$expense_id]);
    }
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Pengeluaran tidak ditemukan']);
        exit;
    }

    // Delete expense
    $stmt = $db->prepare("DELETE FROM project_expenses WHERE id = ?");
    $stmt->execute([$expense_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Pengeluaran berhasil dihapus'
    ]);

} catch (PDOException $e) {
    error_log('Expense delete error: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
