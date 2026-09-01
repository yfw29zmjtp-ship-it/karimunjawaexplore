<?php
// Pure API endpoint - NO CONFIG.PHP DEPENDENCY
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);

$response = ['success' => false, 'message' => '', 'group_bookings' => []];

try {
    $bookingId = intval($_GET['id'] ?? 0);
    if (!$bookingId) {
        echo json_encode(['success' => false, 'message' => 'ID required']);
        exit;
    }

    // Direct PDO connection - REVERT TO OLD (root, no password)
    $conn = new PDO(
        'mysql:host=localhost;dbname=adf2574_narayana_hotel;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get main booking
    $stmt = $conn->prepare("
        SELECT 
            b.id, b.booking_code, b.room_id, b.guest_id,
            b.check_in_date, b.check_out_date, b.total_nights, 
            b.room_price, b.discount, b.final_price, b.status,
            r.room_number, rt.type_name
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    // Get group bookings
    $groupBookings = [];
    if ($booking['guest_id'] && $booking['check_in_date'] && $booking['check_out_date']) {
        $gstmt = $conn->prepare("
            SELECT 
                b.id, b.booking_code, b.room_id, b.room_price,
                b.discount, b.final_price, b.status,
                r.room_number, rt.type_name
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            JOIN room_types rt ON r.room_type_id = rt.id
            WHERE b.guest_id = ? 
            AND b.check_in_date = ? 
            AND b.check_out_date = ?
            AND b.status NOT IN ('cancelled')
            ORDER BY r.room_number ASC
        ");
        $gstmt->execute([$booking['guest_id'], $booking['check_in_date'], $booking['check_out_date']]);
        $groupBookings = $gstmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $booking['group_bookings'] = $groupBookings;

    echo json_encode([
        'success' => true,
        'booking' => $booking
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
exit;
