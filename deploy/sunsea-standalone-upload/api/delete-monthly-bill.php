<?php

/**
 * API: DELETE MONTHLY BILL
 * POST /api/delete-monthly-bill.php
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('finance')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();

try {
    $billId = (int)$_POST['bill_id'];
    if (!$billId) throw new Exception('Bill ID required');

    $bill = $db->fetchOne("SELECT * FROM monthly_bills WHERE id = ? LIMIT 1", [$billId]);
    if (!$bill) throw new Exception('Bill tidak ditemukan');

    // Check if bill already paid
    if ($bill['paid_amount'] > 0) {
        throw new Exception('Tidak bisa delete tagihan yang sudah dibayar. Update status menjadi "cancelled" saja.');
    }

    // Delete associated payments first (cascade)
    $db->query("DELETE FROM bill_payments WHERE bill_id = ?", [$billId]);

    // Delete bill
    $result = $db->query("DELETE FROM monthly_bills WHERE id = ?", [$billId]);

    if (!$result) {
        throw new Exception('Failed to delete bill');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Tagihan berhasil dihapus'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>
