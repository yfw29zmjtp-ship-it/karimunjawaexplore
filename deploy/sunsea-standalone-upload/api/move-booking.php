<?php
/**
 * API: Move Booking (Drag & Drop)
 * Update booking dates and/or room when dragged on calendar
 */

// LOG ALL ERRORS
$logFile = __DIR__ . '/../api_debug.log';
ini_set('log_errors', 1);
ini_set('error_log', $logFile);

header('Content-Type: application/json');
if (ob_get_level() === 0) ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('APP_ACCESS', true);

try {
    error_log("=== move-booking.php START ===");
    
    require_once '../config/config.php';
    error_log("config.php loaded");
    
    require_once '../config/database.php';
    error_log("database.php loaded");
    
    require_once '../includes/auth.php';
    error_log("auth.php loaded");
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    $auth = new Auth();
    error_log("Auth instantiated");
    
    if (!$auth->isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (!$auth->hasPermission('frontdesk')) {
        echo json_encode(['success' => false, 'message' => 'No permission']);
        exit;
    }

    $db = Database::getInstance();
    error_log("Database instance obtained");
    $conn = $db->getConnection();

    $bookingId = intval($_POST['booking_id'] ?? 0);
    $newCheckIn = trim($_POST['new_check_in'] ?? '');
    $newCheckOut = trim($_POST['new_check_out'] ?? '');
    $newRoomId = intval($_POST['new_room_id'] ?? 0);

    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }

    // Get current booking
    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // Cannot move checked_out bookings
    if ($booking['status'] === 'checked_out') {
        throw new Exception('Cannot move checked-out bookings');
    }

    // Determine new dates
    $checkIn = $newCheckIn ?: $booking['check_in_date'];
    $checkOut = $newCheckOut ?: $booking['check_out_date'];
    $roomId = $newRoomId ?: $booking['room_id'];

    // Validate dates
    $ciDate = new DateTime($checkIn);
    $coDate = new DateTime($checkOut);
    if ($coDate <= $ciDate) {
        throw new Exception('Check-out must be after check-in');
    }

    $nights = $ciDate->diff($coDate)->days;

    // Check room availability (exclude current booking)
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM bookings 
        WHERE room_id = ? 
        AND id != ? 
        AND status NOT IN ('cancelled', 'checked_out')
        AND check_in_date < ? 
        AND check_out_date > ?
    ");
    $stmt->execute([$roomId, $bookingId, $checkOut, $checkIn]);
    $conflict = $stmt->fetchColumn();

    if ($conflict > 0) {
        throw new Exception('Room is not available for the selected dates');
    }

    // Get room price from room_types via rooms
    $stmt = $conn->prepare("
        SELECT rt.base_price 
        FROM rooms r 
        JOIN room_types rt ON r.room_type_id = rt.id 
        WHERE r.id = ?
    ");
    $stmt->execute([$roomId]);
    $roomData = $stmt->fetch(PDO::FETCH_ASSOC);

    // Use existing room_price from booking, or base_price for new room
    $roomPrice = $booking['room_price'];
    if ($roomId != $booking['room_id'] && $roomData) {
        $roomPrice = $roomData['base_price'];
    }

    $totalPrice = $roomPrice * $nights;
    $discount = floatval($booking['discount'] ?? 0);
    $finalPrice = $totalPrice - $discount;

    // Update booking
    $stmt = $conn->prepare("
        UPDATE bookings SET 
            check_in_date = ?,
            check_out_date = ?,
            room_id = ?,
            total_nights = ?,
            room_price = ?,
            total_price = ?,
            final_price = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $checkIn, $checkOut, $roomId, $nights,
        $roomPrice, $totalPrice, $finalPrice, $bookingId
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Booking moved successfully',
        'data' => [
            'booking_id' => $bookingId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_id' => $roomId,
            'nights' => $nights,
            'total_price' => $totalPrice,
            'final_price' => $finalPrice
        ]
    ]);

} catch (Exception $e) {
    error_log("ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    error_log("Stack: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $t) {
    error_log("FATAL: " . $t->getMessage() . " at " . $t->getFile() . ":" . $t->getLine());
    error_log("Stack: " . $t->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $t->getMessage()]);
}
error_log("=== move-booking.php END ===");
ob_end_flush();