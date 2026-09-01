<?php
/**
 * API: Add New Bill
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
    
    // Get form data
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $category = $_POST['category'] ?? 'other';
    $due_date = $_POST['due_date'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    $bill_number = trim($_POST['bill_number'] ?? '');
    
    // Validation
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
    
    // Insert bill
    $stmt = $db->prepare("
        INSERT INTO investor_bills 
        (bill_number, title, description, amount, category, due_date, notes, status, created_by, created_at)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, NOW())
    ");
    
    $stmt->execute([
        $bill_number ?: null,
        $title,
        $description ?: null,
        $amount,
        $category,
        $due_date ?: null,
        $notes ?: null,
        $_SESSION['user_id'] ?? 1
    ]);
    
    $billId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Bill added successfully',
        'bill_id' => $billId
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
