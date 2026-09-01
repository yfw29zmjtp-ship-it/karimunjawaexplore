<?php

/**
 * API: Mark Room Clean
 * Flip room status dari 'cleaning' (dirty) → 'available' setelah dibersihkan.
 * Dipakai oleh frontdesk denah & staff portal occupancy.
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

ob_clean();
header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Frontdesk & staff yang punya akses frontdesk boleh menandai bersih
if (!$auth->hasPermission('frontdesk') && !$auth->hasPermission('staff')) {
    echo json_encode(['success' => false, 'message' => 'No permission']);
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

try {
    $roomId = (int)($_POST['room_id'] ?? $_GET['room_id'] ?? 0);
    if ($roomId <= 0) {
        throw new Exception('Room ID tidak valid');
    }

    $room = $db->fetchOne("SELECT id, room_number, status FROM rooms WHERE id = ?", [$roomId]);
    if (!$room) {
        throw new Exception('Kamar tidak ditemukan');
    }

    if ($room['status'] !== 'cleaning') {
        echo json_encode([
            'success' => true,
            'message' => 'Kamar tidak dalam status dirty',
            'room_status' => $room['status']
        ]);
        exit;
    }

    // Pastikan ENUM tetap memuat 'available'
    $db->query("UPDATE rooms SET status = 'available', updated_at = NOW() WHERE id = ? AND status = 'cleaning'", [$roomId]);

    // Log aktivitas (best effort)
    try {
        $db->query(
            "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())",
            [
                $currentUser['id'] ?? 0,
                'room_clean',
                'Tandai bersih Room ' . ($room['room_number'] ?? $roomId)
            ]
        );
    } catch (\Throwable $eLog) { /* ignore */
    }

    echo json_encode([
        'success' => true,
        'message' => 'Kamar ' . $room['room_number'] . ' sudah bersih',
        'room_status' => 'available'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
