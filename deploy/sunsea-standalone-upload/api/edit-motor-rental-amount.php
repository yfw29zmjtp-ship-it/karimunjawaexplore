<?php
/**
 * API: EDIT MOTOR RENTAL AMOUNT
 * POST /api/edit-motor-rental-amount.php
 * Updates total_price, owner_amount, hotel_commission for a motor rental booking.
 */

if (ob_get_level()) ob_end_clean();
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

try {
    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/auth.php';

    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $db          = Database::getInstance();
    $pdo         = $db->getConnection();
    $bizId       = (int)($_SESSION['business_id'] ?? 1);
    $rentalId    = (int)($_POST['rental_id'] ?? 0);
    $totalPrice  = (float)($_POST['total_price'] ?? -1);
    $ownerAmount = (float)($_POST['owner_amount'] ?? -1);

    if ($rentalId <= 0) throw new Exception('rental_id tidak valid');
    if ($totalPrice < 0) throw new Exception('Total tarif tidak valid');
    if ($ownerAmount < 0) throw new Exception('Bagian mitra tidak valid');
    if ($ownerAmount > $totalPrice) throw new Exception('Bagian mitra tidak boleh melebihi total tarif');

    $hotelCommission = round($totalPrice - $ownerAmount, 2);

    $stmt = $pdo->prepare(
        "UPDATE rental_motor_bookings
         SET total_price=?, owner_amount=?, hotel_commission=?
         WHERE id=? AND business_id=?"
    );
    $stmt->execute([$totalPrice, $ownerAmount, $hotelCommission, $rentalId, $bizId]);
    if ($stmt->rowCount() === 0) throw new Exception('Rental tidak ditemukan');

    echo json_encode(['success' => true, 'message' => 'Nominal berhasil diperbarui']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
