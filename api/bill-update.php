<?php
/**
 * API: Update Bill
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
    
    $bill_id = intval($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $category = $_POST['category'] ?? 'other';
    $due_date = $_POST['due_date'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    $bill_number = trim($_POST['bill_number'] ?? '');
    
    if ($bill_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid bill ID']);
        exit;
    }
    
    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Bill title is required']);
        exit;
    }
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
        exit;
    }
    
    // Parse amount if it's formatted
    if (is_string($amount)) {
        $amount = floatval(preg_replace('/[^0-9.]/', '', $amount));
    }
    
    $stmt = $db->prepare("
        UPDATE investor_bills SET
            bill_number = ?,
            title = ?,
            description = ?,
            amount = ?,
            category = ?,
            due_date = ?,
            notes = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
        $bill_number ?: null,
        $title,
        $description ?: null,
        $amount,
        $category,
        $due_date ?: null,
        $notes ?: null,
        $bill_id
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Bill updated successfully'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>