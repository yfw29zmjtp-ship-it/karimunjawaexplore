<?php
/**
 * CANCEL BOOKING API
 * Change booking status to 'cancelled' with manual refund processing
 * 
 * Refund Policy:
 * - Manual refund only - no automatic calculation
 * - Front desk staff must manually specify refund amount if applicable
 * - Refund will only be processed if specifically provided in request
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();

// Get business_id from user or session
$businessId = $currentUser['business_id'] ?? $_SESSION['business_id'] ?? 1;

$input = json_decode(file_get_contents('php://input'), true);
$bookingId = $input['booking_id'] ?? null;

if (!$bookingId) {
    echo json_encode(['success' => false, 'message' => 'Booking ID required']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Get booking info with guest name
    $stmt = $pdo->prepare("
        SELECT b.*, g.guest_name 
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }
    
    // Cannot cancel if already checked in or checked out
    if (in_array($booking['status'], ['checked_in', 'checked_out'])) {
        echo json_encode(['success' => false, 'message' => 'Cannot cancel booking that is already checked in or checked out']);
        exit;
    }
    
    // Cannot cancel if already cancelled
    if ($booking['status'] === 'cancelled') {
        echo json_encode(['success' => false, 'message' => 'Booking is already cancelled']);
        exit;
    }
    
    // Update booking status to cancelled
    $stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'cancelled', 
            updated_at = NOW(),
            special_request = CONCAT(COALESCE(special_request, ''), '\n[CANCELLED] Dibatalkan oleh front desk')
        WHERE id = ?
    ");
    $stmt->execute([$bookingId]);
    
    // Log activity
    $logDesc = "Cancelled booking {$booking['booking_code']} - {$booking['guest_name']}";
    
    try {
        $checkUser = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $checkUser->execute([$currentUser['id']]);
        
        if ($checkUser->fetch()) {
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, description, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $currentUser['id'],
                'cancel_booking',
                $logDesc
            ]);
        }
    } catch (Exception $logError) {
        error_log("Activity log insert failed: " . $logError->getMessage());
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Booking cancelled successfully',
        'data' => [
            'booking_code' => $booking['booking_code'],
            'guest_name' => $booking['guest_name'],
            'check_in_date' => $booking['check_in_date']
        ]
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Cancel Booking Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>
