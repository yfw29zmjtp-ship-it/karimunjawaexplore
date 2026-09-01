<?php

/**
 * API: EDIT/UPDATE MONTHLY BILL
 * POST /api/edit-monthly-bill.php
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

    // Get existing bill
    $bill = $db->fetchOne("SELECT * FROM monthly_bills WHERE id = ? LIMIT 1", [$billId]);
    if (!$bill) throw new Exception('Bill tidak ditemukan');

    // Only allow edit if not paid yet (or partially paid)
    if ($bill['status'] === 'cancelled') {
        throw new Exception('Tagihan yang dibatalkan tidak bisa di-edit');
    }

    // Update fields (hanya field tertentu yang bisa di-edit)
    $updates = [];
    $params = [];

    if (isset($_POST['bill_name'])) {
        $updates[] = "bill_name = ?";
        $params[] = trim($_POST['bill_name']);
    }

    if (isset($_POST['amount']) && $_POST['amount'] > 0) {
        $updates[] = "amount = ?";
        $params[] = (float)$_POST['amount'];
    }

    if (isset($_POST['due_date'])) {
        $updates[] = "due_date = ?";
        $params[] = $_POST['due_date'] ?: null;
    }

    if (isset($_POST['notes'])) {
        $updates[] = "notes = ?";
        $params[] = trim($_POST['notes']);
    }

    if (empty($updates)) {
        throw new Exception('Tidak ada field untuk di-update');
    }

    $params[] = $billId;
    $query = "UPDATE monthly_bills SET " . implode(', ', $updates) . " WHERE id = ?";
    
    $result = $db->query($query, $params);
    if (!$result) {
        throw new Exception('Failed to update bill');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Tagihan berhasil diperbarui'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>
