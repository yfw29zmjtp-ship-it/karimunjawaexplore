<?php
/**
 * API: Get Single Investor Data
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
    
    $investor_id = intval($_GET['id'] ?? 0);
    
    if ($investor_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid investor ID']);
        exit;
    }
    
    // Check what columns exist
    $stmt = $db->query("DESCRIBE investors");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Build query based on available columns
    $select_cols = ['id'];
    
    // Add flexible column names for name
    if (in_array('name', $columns)) {
        $select_cols[] = 'name';
    } elseif (in_array('investor_name', $columns)) {
        $select_cols[] = 'investor_name';
    }
    
    // Add flexible column names for phone
    if (in_array('phone', $columns)) {
        $select_cols[] = 'phone';
    } elseif (in_array('contact_phone', $columns)) {
        $select_cols[] = 'contact_phone';
    }
    
    // Add email if exists
    if (in_array('email', $columns)) {
        $select_cols[] = 'email';
    }
    
    // Add notes if exists
    if (in_array('notes', $columns)) {
        $select_cols[] = 'notes';
    }
    
    // Add balance columns
    if (in_array('balance', $columns)) {
        $select_cols[] = 'balance';
    } elseif (in_array('total_capital', $columns)) {
        $select_cols[] = 'total_capital';
    }
    
    $sql = "SELECT " . implode(', ', $select_cols) . " FROM investors WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$investor_id]);
    
    $investor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$investor) {
        echo json_encode(['success' => false, 'message' => 'Investor tidak ditemukan']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'investor' => $investor
    ]);
    
} catch (PDOException $e) {
    error_log('Investor get error: ' . $e->getMessage());
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
