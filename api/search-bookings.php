<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    $searchParam = '%' . $q . '%';

    $stmt = $conn->prepare("
        SELECT 
            b.id,
            b.booking_code,
            b.check_in_date,
            b.check_out_date,
            b.status,
            b.payment_status,
            b.final_price,
            COALESCE(b.paid_amount, 0) as paid_amount,
            g.guest_name,
            g.phone as guest_phone,
            r.room_number,
            rt.type_name as room_type
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE (g.guest_name LIKE ? OR g.phone LIKE ? OR b.booking_code LIKE ?)
        ORDER BY 
            FIELD(b.status, 'checked_in', 'confirmed', 'pending', 'checked_out', 'cancelled'),
            b.check_in_date DESC
        LIMIT 15
    ");
    $stmt->execute([$searchParam, $searchParam, $searchParam]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
