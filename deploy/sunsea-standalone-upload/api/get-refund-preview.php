<?php
/**
 * GET REFUND PREVIEW API
 * Get booking information for manual refund processing
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$pdo = $db->getConnection();

$bookingId = $_GET['booking_id'] ?? null;

if (!$bookingId) {
    echo json_encode(['success' => false, 'message' => 'Booking ID required']);
    exit;
}

try {
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
    
    // Calculate days until check-in for information only
    $today = new DateTime('today');
    $checkInDate = new DateTime($booking['check_in_date']);
    $interval = $today->diff($checkInDate);
    $daysUntilCheckin = $interval->invert ? -$interval->days : $interval->days;
    
    // Manual refund policy - no automatic calculation
    $paidAmount = floatval($booking['paid_amount'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'booking_id' => $booking['id'],
            'booking_code' => $booking['booking_code'],
            'guest_name' => $booking['guest_name'],
            'check_in_date' => $booking['check_in_date'],
            'check_out_date' => $booking['check_out_date'],
            'final_price' => floatval($booking['final_price']),
            'paid_amount' => $paidAmount,
            'days_until_checkin' => $daysUntilCheckin,
            'refund_policy' => 'Refund Manual - Tentukan jumlah refund',
            'refund_percentage' => 0, // No automatic percentage
            'refund_amount' => 0,     // No automatic refund
            'forfeit_amount' => 0,    // No automatic forfeit calculation
            'policy_color' => '#64748b' // neutral gray
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get Refund Preview Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>
