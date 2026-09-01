<?php
/**
 * API - GET BREAKFAST ORDERS
 * Mendapatkan daftar breakfast orders
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();

try {
    $bookingId = $_GET['booking_id'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if ($bookingId) {
        // Get orders for specific booking
        $query = "SELECT bo.* FROM breakfast_orders bo
                  WHERE bo.booking_id = ?
                  ORDER BY bo.breakfast_date DESC, bo.breakfast_time DESC";
        $orders = $db->fetchAll($query, [$bookingId]);
    } else {
        // Get all orders for today — deduplicate per guest per date
                $query = "SELECT bo.* FROM breakfast_orders bo
                                    WHERE bo.breakfast_date = ?
                                    AND bo.id = (
                                            SELECT MAX(bo2.id) FROM breakfast_orders bo2
                                            WHERE bo2.guest_name = bo.guest_name
                                                AND bo2.breakfast_date = bo.breakfast_date
                                                AND bo2.room_number = bo.room_number
                                    )
                                    ORDER BY bo.breakfast_time ASC";
        $orders = $db->fetchAll($query, [$date]);
    }
    
    // Decode JSON fields
    foreach ($orders as &$order) {
        $order['menu_items'] = json_decode($order['menu_items'], true);
        // Decode room_number JSON array for display
        $decodedRoom = json_decode($order['room_number'], true);
        if (is_array($decodedRoom)) {
            $order['room_number'] = implode(', ', $decodedRoom);
        }
    }
    
    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);
    
} catch (Exception $e) {
    error_log("Get Breakfast Orders Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
