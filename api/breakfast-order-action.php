<?php
define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$id = (int)($input['id'] ?? 0);

$db = Database::getInstance();
$pdo = $db->getConnection();

// cleanup_duplicates doesn't require ID
if ($id <= 0 && $action !== 'cleanup_duplicates') {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
    exit;
}

if ($action === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM breakfast_orders WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Order tidak ditemukan']);
    }
} elseif ($action === 'cleanup_duplicates') {
    // Clean up duplicate breakfast orders for a specific date
    $date = $input['date'] ?? date('Y-m-d');
    
    try {
        // Find duplicates: same guest_name, breakfast_date, breakfast_time, room_number
        // Keep the NEWEST one (highest id), delete older duplicates
        // NOTE: Do NOT compare menu_items TEXT — JSON formatting may differ slightly
        $findDups = $pdo->prepare("
            SELECT bo.id 
            FROM breakfast_orders bo
            INNER JOIN (
                SELECT guest_name, breakfast_date, breakfast_time, room_number, MAX(id) as keep_id
                FROM breakfast_orders 
                WHERE breakfast_date = ?
                GROUP BY guest_name, breakfast_date, breakfast_time, room_number
                HAVING COUNT(*) > 1
            ) dups ON bo.guest_name = dups.guest_name 
                  AND bo.breakfast_date = dups.breakfast_date 
                  AND bo.breakfast_time = dups.breakfast_time 
                  AND bo.room_number = dups.room_number
                  AND bo.id != dups.keep_id
            WHERE bo.breakfast_date = ?
        ");
        $findDups->execute([$date, $date]);
        $dupIds = $findDups->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($dupIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($dupIds), '?'));
            $delStmt = $pdo->prepare("DELETE FROM breakfast_orders WHERE id IN ($placeholders)");
            $delStmt->execute($dupIds);
            echo json_encode(['success' => true, 'message' => count($dupIds) . ' order duplikat berhasil dihapus', 'deleted' => count($dupIds)]);
        } else {
            echo json_encode(['success' => true, 'message' => 'Tidak ada duplikat ditemukan', 'deleted' => 0]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
}
