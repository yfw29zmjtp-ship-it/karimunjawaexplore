<?php

/**
 * API: DELETE/CANCEL ROOM BLOCK
 * Soft-cancel active room block from calendar.
 */

ob_start();
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_ACCESS', true);

ob_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
ob_end_clean();

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!$auth->hasPermission('frontdesk')) {
    echo json_encode(['success' => false, 'message' => 'No permission']);
    exit;
}

$db = Database::getInstance();

try {
    $blockId = (int)($_POST['block_id'] ?? 0);
    if ($blockId <= 0) {
        throw new Exception('Block ID tidak valid');
    }

    $row = $db->fetchOne("SELECT id, status FROM room_blocks WHERE id = ?", [$blockId]);
    if (!$row) {
        throw new Exception('Data block tidak ditemukan');
    }
    if (($row['status'] ?? '') !== 'active') {
        throw new Exception('Block sudah tidak aktif');
    }

    $db->query("UPDATE room_blocks SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [$blockId]);

    echo json_encode(['success' => true, 'message' => 'Block room berhasil dibatalkan']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
