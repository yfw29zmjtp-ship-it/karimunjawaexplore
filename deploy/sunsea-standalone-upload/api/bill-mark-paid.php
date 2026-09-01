<?php
/**
 * API: Mark Bill as Paid
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
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    
    if ($bill_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid bill ID']);
        exit;
    }
    
    // Check if bill exists
    $stmt = $db->prepare("SELECT * FROM investor_bills WHERE id = ?");
    $stmt->execute([$bill_id]);
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bill) {
        echo json_encode(['success' => false, 'message' => 'Bill not found']);
        exit;
    }
    
    // Mark as paid
    $stmt = $db->prepare("
        UPDATE investor_bills SET
            status = 'paid',
            payment_date = ?,
            payment_method = ?,
            paid_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
        $payment_date,
        $payment_method,
        $_SESSION['username'] ?? $_SESSION['user_id'] ?? 'User',
        $bill_id
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Bill marked as paid'
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