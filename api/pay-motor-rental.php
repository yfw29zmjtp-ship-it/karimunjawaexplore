<?php

/**
 * API: PAY MOTOR RENTAL (mitra commission)
 * POST /api/pay-motor-rental.php
 * Marks a motor rental's owner_amount as paid and syncs to cashbook.
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/CashbookHelper.php';

ob_start();
error_reporting(0);
ini_set('display_errors', '0');
while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db         = Database::getInstance();
$pdo        = $db->getConnection();
$bizId      = (int)($_SESSION['business_id'] ?? 1);
$userId     = $auth->getCurrentUser()['id'] ?? null;

try {
    $rentalId      = (int)($_POST['rental_id'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
    $cashAccountId = (int)($_POST['cash_account_id'] ?? 0);
    $mitraName     = trim($_POST['mitra_name'] ?? 'Mitra');

    if (!$rentalId) throw new Exception('rental_id tidak valid');

    $rental = $pdo->prepare(
        "SELECT rmb.*, rm.motor_name, rm.plate_number, rm.partner_owner
         FROM rental_motor_bookings rmb
         JOIN rental_motors rm ON rmb.motor_id = rm.id
         WHERE rmb.id = ? AND rmb.business_id = ? LIMIT 1"
    );
    $rental->execute([$rentalId, $bizId]);
    $row = $rental->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Rental tidak ditemukan');

    $ownerAmount = (float)$row['owner_amount'];
    if ($ownerAmount <= 0) throw new Exception('Tidak ada komisi mitra untuk dibayar (Rp 0)');

    $db->beginTransaction();

    // Mark as paid
    $pdo->prepare("UPDATE rental_motor_bookings SET payment_date=NOW(), updated_at=NOW() WHERE id=? AND business_id=?")
        ->execute([$rentalId, $bizId]);

    // Sync to cashbook as expense (keluar)
    if ($cashAccountId > 0) {
        $desc = 'Bayar mitra motor: ' . ($row['motor_name'] ?? '') . ' (' . ($row['plate_number'] ?? '') . ')' .
            ' - ' . ($row['guest_name'] ? 'Tamu: ' . $row['guest_name'] : '') .
            ' [' . $mitraName . ']';
        try {
            CashbookHelper::addEntry($pdo, $bizId, [
                'account_id'     => $cashAccountId,
                'type'           => 'keluar',
                'amount'         => $ownerAmount,
                'description'    => $desc,
                'category'       => 'Komisi Motor Mitra',
                'payment_method' => $paymentMethod,
                'ref_type'       => 'motor_rental',
                'ref_id'         => $rentalId,
                'created_by'     => $userId,
            ]);
        } catch (\Throwable $cbErr) {
            // Cashbook sync optional — don't fail the payment if cashbook errors
            error_log('[pay-motor-rental] cashbook error: ' . $cbErr->getMessage());
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Pembayaran mitra berhasil dicatat']);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
