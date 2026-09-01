<?php

/**
 * API: Send Notification to Owner
 * Called when end-shift or important event occurs
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? $_POST['type'] ?? '';
$data = $input['data'] ?? $_POST['data'] ?? [];

if (empty($type)) {
    echo json_encode(['success' => false, 'message' => 'Type required']);
    exit;
}

$db = Database::getInstance();

try {
    // Store notification in database
    $notification = [
        'type' => $type,
        'title' => '',
        'message' => '',
        'data' => json_encode($data),
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ];

    switch ($type) {
        case 'end_shift':
            $notification['title'] = '📊 Laporan End Shift';
            $notification['message'] = ($data['cashier_name'] ?? 'Kasir') . ' telah menyelesaikan shift. ' .
                'Total: Rp ' . number_format($data['total_sales'] ?? 0, 0, ',', '.');
            break;

        case 'new_booking':
            $notification['title'] = '🏨 Reservasi Baru';
            $notification['message'] = 'Reservasi baru dari ' . ($data['guest_name'] ?? 'tamu');
            break;

        case 'check_in':
            $notification['title'] = '✅ Check-In';
            $notification['message'] = ($data['guest_name'] ?? 'Tamu') . ' check-in ke kamar ' . ($data['room_number'] ?? '');
            break;

        case 'check_out':
            $notification['title'] = '🚪 Check-Out';
            $notification['message'] = ($data['guest_name'] ?? 'Tamu') . ' check-out dari kamar ' . ($data['room_number'] ?? '');
            break;

        case 'payment':
            $notification['title'] = '💰 Pembayaran Diterima';
            $notification['message'] = 'Pembayaran Rp ' . number_format($data['amount'] ?? 0, 0, ',', '.') .
                ' dari ' . ($data['guest_name'] ?? 'pelanggan');
            break;

        default:
            $notification['title'] = '🔔 Notifikasi';
            $notification['message'] = $data['message'] ?? 'Ada notifikasi baru';
    }

    // Get owner/admin users to notify
    $ownerRoles = $db->fetchAll("
        SELECT u.id, u.full_name, u.email 
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE r.role_code IN ('owner', 'admin', 'developer') 
        AND u.is_active = 1
    ");

    // Insert notification for each owner/admin
    $notifiedCount = 0;
    foreach ($ownerRoles as $owner) {
        try {
            $db->insert('notifications', [
                'user_id' => $owner['id'],
                'type' => $type,
                'title' => $notification['title'],
                'message' => $notification['message'],
                'data' => $notification['data'],
                'is_read' => 0,
                'created_at' => $notification['created_at']
            ]);
            $notifiedCount++;
        } catch (Exception $e) {
            // Table might not exist, continue
        }
    }

    // Send real Web Push notifications to subscribed browsers
    try {
        require_once dirname(dirname(__FILE__)) . '/includes/PushNotificationHelper.php';
        $pushHelper = new PushNotificationHelper($db);
        $pushResult = $pushHelper->sendToAdmins(
            $notification['title'],
            $notification['message'],
            [
                'type' => $type,
                'tag'  => $type . '-' . time(),
                'url'  => $data['url'] ?? '/index.php'
            ]
        );
    } catch (\Throwable $pushErr) {
        $pushResult = ['sent' => 0, 'error' => $pushErr->getMessage()];
        error_log('Push notification error: ' . $pushErr->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => "Notifikasi dikirim ke {$notifiedCount} owner/admin",
        'push_sent' => $pushResult['sent'] ?? 0,
        'notification' => [
            'type' => $type,
            'title' => $notification['title'],
            'message' => $notification['message']
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
