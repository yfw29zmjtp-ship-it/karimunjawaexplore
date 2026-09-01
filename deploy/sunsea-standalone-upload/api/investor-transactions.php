<?php
/**
 * API: Get Investor Transactions
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    $investor_id = intval($_GET['investor_id'] ?? 0);
    
    if ($investor_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid investor ID', 'transactions' => []]);
        exit;
    }
    
    // Check what columns exist
    $stmt = $db->query("DESCRIBE investor_transactions");
    $columnsInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $available_columns = array_column($columnsInfo, 'Field');
    
    // Build SELECT clause based on available columns
    $select_parts = ['id', 'investor_id'];
    
    // Amount
    if (in_array('amount', $available_columns)) {
        $select_parts[] = 'amount';
    } elseif (in_array('amount_idr', $available_columns)) {
        $select_parts[] = 'amount_idr as amount';
    } else {
        $select_parts[] = '0 as amount';
    }
    
    // Type
    if (in_array('type', $available_columns)) {
        $select_parts[] = 'type';
    } elseif (in_array('transaction_type', $available_columns)) {
        $select_parts[] = 'transaction_type as type';
    } else {
        $select_parts[] = "'capital' as type";
    }
    
    // Description
    if (in_array('description', $available_columns)) {
        $select_parts[] = 'description';
    } elseif (in_array('note', $available_columns)) {
        $select_parts[] = 'note as description';
    } else {
        $select_parts[] = "'Investor capital deposit' as description";
    }
    
    // Created date
    if (in_array('created_at', $available_columns)) {
        $select_parts[] = 'created_at';
    } elseif (in_array('transaction_date', $available_columns)) {
        $select_parts[] = 'transaction_date as created_at';
    } elseif (in_array('date', $available_columns)) {
        $select_parts[] = 'date as created_at';
    } else {
        $select_parts[] = 'NOW() as created_at';
    }
    
    $select_clause = implode(', ', $select_parts);
    
    // Fetch investor transactions
    $sql = "SELECT {$select_clause} FROM investor_transactions WHERE investor_id = :investor_id ORDER BY created_at DESC LIMIT 100";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([':investor_id' => $investor_id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'transactions' => []
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'transactions' => []
    ]);
}
?>
