<?php

/**
 * API: CREATE ROOM BLOCK
 * Create date-range room blocks for maintenance / operational purposes.
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
$currentUser = $auth->getCurrentUser();

function ensureRoomBlocksTable($db)
{
    $db->query("CREATE TABLE IF NOT EXISTS room_blocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        block_code VARCHAR(40) NULL,
        room_id INT NOT NULL,
        block_start_date DATE NOT NULL,
        block_end_date DATE NOT NULL,
        block_reason VARCHAR(50) NOT NULL DEFAULT 'maintenance',
        notes TEXT NULL,
        status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_room_dates (room_id, block_start_date, block_end_date),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try {
    ensureRoomBlocksTable($db);

    $roomId = (int)($_POST['room_id'] ?? 0);
    $startDate = trim((string)($_POST['block_start_date'] ?? ''));
    $endDate = trim((string)($_POST['block_end_date'] ?? ''));
    $reason = trim((string)($_POST['block_reason'] ?? 'maintenance'));
    $notes = trim((string)($_POST['block_notes'] ?? ''));

    if ($roomId <= 0 || $startDate === '' || $endDate === '') {
        throw new Exception('Room, tanggal mulai, dan tanggal selesai wajib diisi');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        throw new Exception('Format tanggal tidak valid');
    }

    $d1 = new DateTime($startDate);
    $d2 = new DateTime($endDate);
    if ($d2 <= $d1) {
        throw new Exception('Tanggal selesai block harus setelah tanggal mulai');
    }

    $validReasons = ['maintenance', 'deep_cleaning', 'owner_use', 'out_of_order', 'event_setup', 'other'];
    if (!in_array($reason, $validReasons, true)) {
        $reason = 'other';
    }

    $room = $db->fetchOne("SELECT id, room_number FROM rooms WHERE id = ?", [$roomId]);
    if (!$room) {
        throw new Exception('Room tidak ditemukan');
    }

    // Prevent block overlap with active bookings.
    $bookingConflict = $db->fetchOne(
        "SELECT b.id, b.booking_code
         FROM bookings b
         WHERE b.room_id = ?
           AND b.status IN ('pending','confirmed','checked_in')
           AND b.check_in_date < ?
           AND b.check_out_date > ?
         LIMIT 1",
        [$roomId, $endDate, $startDate]
    );
    if ($bookingConflict) {
        throw new Exception('Room sudah ada booking aktif pada rentang tanggal ini');
    }

    // Prevent overlap with existing active blocks.
    $blockConflict = $db->fetchOne(
        "SELECT id
         FROM room_blocks
         WHERE room_id = ?
           AND status = 'active'
           AND block_start_date < ?
           AND block_end_date > ?
         LIMIT 1",
        [$roomId, $endDate, $startDate]
    );
    if ($blockConflict) {
        throw new Exception('Room sudah diblok di rentang tanggal ini');
    }

    $blockCode = 'BLK-' . date('Ymd') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);

    $db->query(
        "INSERT INTO room_blocks
         (block_code, room_id, block_start_date, block_end_date, block_reason, notes, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 'active', ?)",
        [
            $blockCode,
            $roomId,
            $startDate,
            $endDate,
            $reason,
            $notes !== '' ? $notes : null,
            $currentUser['id'] ?? null
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Room ' . ($room['room_number'] ?? $roomId) . ' berhasil diblok',
        'block_code' => $blockCode,
        'room_id' => $roomId,
        'room_number' => $room['room_number'] ?? null
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
