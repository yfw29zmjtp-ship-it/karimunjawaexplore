<?php
/**
 * Agent Tool: notify_frontdesk
 * Kirim pesan / notifikasi dari AI agent ke dashboard front desk.
 * Method: POST (application/json)
 * Header: X-Agent-Key: <key>
 * Body: {
 *   title, message,
 *   type?: "info"|"question"|"complaint"|"new_booking" (default: info)
 *   guest_name?, phone?, booking_code?
 * }
 */

header('Content-Type: application/json');
error_reporting(0); ini_set('display_errors', 0);

define('APP_ACCESS', true);
require_once '_auth.php';

$pdo = agent_auth_check();

try {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: $_POST;

    $title   = trim($body['title']   ?? '');
    $message = trim($body['message'] ?? '');

    if (!$title || !$message) {
        echo json_encode(['success' => false, 'error' => 'Field title dan message wajib diisi']);
        exit;
    }

    $type        = in_array($body['type'] ?? '', ['info','question','complaint','new_booking'])
                   ? $body['type'] : 'info';
    $guestName   = trim($body['guest_name']   ?? '');
    $phone       = trim($body['phone']        ?? '');
    $bookingCode = trim($body['booking_code'] ?? '');

    // Tambahkan konteks tamu ke pesan jika ada
    $fullMessage = $message;
    if ($guestName || $phone) {
        $ctx = [];
        if ($guestName) $ctx[] = "Tamu: $guestName";
        if ($phone)     $ctx[] = "HP: $phone";
        if ($bookingCode) $ctx[] = "Kode: $bookingCode";
        $fullMessage = implode(' | ', $ctx) . "\n" . $message;
    }

    // Ikon berdasarkan tipe
    $icons = [
        'info'        => '💬',
        'question'    => '❓',
        'complaint'   => '⚠️',
        'new_booking' => '📱',
    ];
    $icon  = $icons[$type] ?? '💬';

    // Cari booking_id jika ada booking_code
    $refId = 0;
    if ($bookingCode) {
        $stmtBk = $pdo->prepare("SELECT id FROM bookings WHERE booking_code = ?");
        $stmtBk->execute([$bookingCode]);
        $bk = $stmtBk->fetch(PDO::FETCH_ASSOC);
        if ($bk) $refId = (int)$bk['id'];
    }

    $pdo->prepare("INSERT INTO notifications (title, message, type, reference_id, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())")
        ->execute(["$icon $title", $fullMessage, $type, $refId]);

    echo json_encode([
        'success' => true,
        'message' => 'Notifikasi berhasil dikirim ke front desk',
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
