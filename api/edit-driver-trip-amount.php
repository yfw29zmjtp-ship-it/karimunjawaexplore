<?php

/**
 * API: EDIT DRIVER TRIP AMOUNT
 * POST /api/edit-driver-trip-amount.php
 *
 * Updates total_price/owner_amount/hotel_commission for a driver trip.
 * Both source='trip' and source='legacy' rows are keyed by trip_id =
 * hotel_invoice_items.id (see api/get-driver-recap.php, which always
 * selects "hii.id AS trip_id" for both branches). source='trip' additionally
 * best-effort syncs the linked rental_car_bookings row (matched via
 * invoice_id + service_type, since that table has its own copy of these
 * columns used by the Rental Mobil owner dashboard).
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

    $db   = Database::getInstance();
    $pdo  = $db->getConnection();
    $bizId = (int)($_SESSION['business_id'] ?? 1);

    $tripId     = (int)($_POST['trip_id'] ?? 0);
    $source     = trim($_POST['source'] ?? '');
    $totalPrice = (float)($_POST['total_price'] ?? -1);
    $ownerAmount = (float)($_POST['owner_amount'] ?? -1);

    if ($tripId <= 0) throw new Exception('trip_id tidak valid');
    if (!in_array($source, ['trip', 'legacy'], true)) throw new Exception('source tidak valid');
    if ($totalPrice < 0) throw new Exception('Total tarif tidak valid');
    if ($ownerAmount < 0) throw new Exception('Bagian pemilik tidak valid');
    if ($ownerAmount > $totalPrice) throw new Exception('Bagian pemilik tidak boleh melebihi total tarif');

    if ($source === 'trip') {
        // trip_id is hotel_invoice_items.id here (see get-driver-recap.php) — NOT
        // rental_car_bookings.id. Verify ownership via the hotel_invoices join.
        $check = $pdo->prepare(
            "SELECT hii.id, hii.invoice_id, hii.service_type FROM hotel_invoice_items hii
             JOIN hotel_invoices hi ON hii.invoice_id = hi.id
             WHERE hii.id = ? AND hi.business_id = ? LIMIT 1"
        );
        $check->execute([$tripId, $bizId]);
        $tripRow = $check->fetch();
        if (!$tripRow) throw new Exception('Trip tidak ditemukan atau bukan milik bisnis ini');

        $hotelCommission = $totalPrice - $ownerAmount;
        $stmt = $pdo->prepare(
            "UPDATE hotel_invoice_items
             SET total_price = ?, owner_amount = ?, hotel_commission = ?
             WHERE id = ?"
        );
        $stmt->execute([$totalPrice, $ownerAmount, $hotelCommission, $tripId]);

        // Best-effort: keep the linked rental_car_bookings row (used by the Rental
        // Mobil owner dashboard) in sync — it has its own copy of these columns.
        try {
            $pdo->prepare(
                "UPDATE rental_car_bookings
                 SET total_price = ?, owner_amount = ?, hotel_commission = ?
                 WHERE invoice_id = ? AND service_type = ? AND business_id = ?"
            )->execute([$totalPrice, $ownerAmount, $hotelCommission, $tripRow['invoice_id'], $tripRow['service_type'], $bizId]);
        } catch (Throwable $syncErr) {
            error_log('edit-driver-trip-amount rental_car_bookings sync failed: ' . $syncErr->getMessage());
        }
    } else {
        // legacy: hotel_invoice_items — verify business via hotel_invoices join
        $check = $pdo->prepare(
            "SELECT hii.id FROM hotel_invoice_items hii
             JOIN hotel_invoices hi ON hii.invoice_id = hi.id
             WHERE hii.id = ? AND hi.business_id = ? LIMIT 1"
        );
        $check->execute([$tripId, $bizId]);
        if (!$check->fetch()) throw new Exception('Trip tidak ditemukan atau bukan milik bisnis ini');

        $hotelCommission = $totalPrice - $ownerAmount;
        $stmt = $pdo->prepare(
            "UPDATE hotel_invoice_items
             SET total_price = ?, owner_amount = ?, hotel_commission = ?
             WHERE id = ?"
        );
        $stmt->execute([$totalPrice, $ownerAmount, $hotelCommission, $tripId]);
    }

    echo json_encode(['success' => true, 'message' => 'Nominal berhasil diperbarui']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
