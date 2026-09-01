<?php

/**
 * API: Notifications
 * Handles admin pending requests + staff notifications
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once dirname(dirname(__FILE__)) . '/includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'notifications' => []]);
    exit;
}

$user = $auth->getCurrentUser();
$db = Database::getInstance();
$type = $_GET['type'] ?? '';

// ═══ ADMIN: Get pending count ═══
if ($type === 'admin_count') {
    try {
        $leaveCount = $db->fetchOne("SELECT COUNT(*) as c FROM leave_requests WHERE status = 'pending'")['c'] ?? 0;
        $otCount = $db->fetchOne("SELECT COUNT(*) as c FROM overtime_requests WHERE status = 'pending'")['c'] ?? 0;
        echo json_encode(['success' => true, 'pending_count' => $leaveCount + $otCount]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'pending_count' => 0]);
    }
    exit;
}

// ═══ ADMIN: Get pending requests ═══
if ($type === 'admin_pending') {
    try {
        $leaves = $db->fetchAll("SELECT lr.id, lr.leave_type, lr.start_date, lr.end_date, lr.reason, lr.created_at,
            pe.full_name FROM leave_requests lr
            LEFT JOIN payroll_employees pe ON lr.employee_id = pe.id
            WHERE lr.status = 'pending' ORDER BY lr.created_at ASC") ?: [];
        $overtimes = $db->fetchAll("SELECT ot.id, ot.overtime_date, ot.reason, ot.created_at,
            pe.full_name FROM overtime_requests ot
            LEFT JOIN payroll_employees pe ON ot.employee_id = pe.id
            WHERE ot.status = 'pending' ORDER BY ot.created_at ASC") ?: [];
        echo json_encode(['success' => true, 'pending_leaves' => $leaves, 'pending_overtimes' => $overtimes]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'pending_leaves' => [], 'pending_overtimes' => []]);
    }
    exit;
}

// ═══ ADMIN: Approve/Reject action ═══
if ($type === 'admin_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $adminNotes = trim($_POST['admin_notes'] ?? '');
    $approver = $_SESSION['full_name'] ?? 'Admin';

    // Ensure notifications table exists
    $db->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL, message TEXT, data JSON, is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_read (user_id, is_read), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($action === 'approve_leave' || $action === 'reject_leave') {
        $leaveId = (int)($_POST['leave_id'] ?? 0);
        $newStatus = ($action === 'approve_leave') ? 'approved' : 'rejected';
        if ($leaveId > 0) {
            $db->query(
                "UPDATE leave_requests SET status = ?, approved_by = ?, approved_at = NOW(), admin_notes = ? WHERE id = ?",
                [$newStatus, $approver, $adminNotes, $leaveId]
            );
            $req = $db->fetchOne("SELECT employee_id, leave_type, start_date, end_date FROM leave_requests WHERE id = ?", [$leaveId]);
            if ($req) {
                $tl = ['cuti' => 'Cuti', 'sakit' => 'Sakit', 'izin' => 'Izin', 'cuti_khusus' => 'Cuti Khusus'][$req['leave_type']] ?? $req['leave_type'];
                $sl = $newStatus === 'approved' ? 'Disetujui' : 'Ditolak';
                $db->query("INSERT INTO notifications (user_id, type, title, message, data, created_at) VALUES (?, 'leave_response', ?, ?, ?, NOW())", [
                    $req['employee_id'],
                    $tl . ' ' . $sl,
                    $tl . ' (' . $req['start_date'] . ' s/d ' . $req['end_date'] . ') ' . strtolower($sl) . ($adminNotes ? '. Catatan: ' . $adminNotes : ''),
                    json_encode(['leave_id' => $leaveId, 'status' => $newStatus, 'leave_type' => $req['leave_type']])
                ]);
            }
            // Push notification to staff member
            try {
                require_once dirname(dirname(__FILE__)) . '/includes/PushNotificationHelper.php';
                $pushHelper = new PushNotificationHelper($db);
                $emoji = $newStatus === 'approved' ? '✅' : '❌';
                $pushHelper->sendToEmployees(
                    [$req['employee_id']],
                    "{$emoji} {$tl} {$sl}",
                    "{$tl} ({$req['start_date']} s/d {$req['end_date']}) " . strtolower($sl) . ($adminNotes ? ". Catatan: {$adminNotes}" : ''),
                    [
                        'type' => 'leave_response',
                        'tag'  => 'leave-resp-' . $leaveId,
                        'url'  => '/modules/payroll/staff-portal.php'
                    ]
                );
            } catch (\Throwable $pushErr) {
                error_log('Push notification error (leave approval): ' . $pushErr->getMessage());
            }

            echo json_encode(['success' => true, 'message' => $newStatus === 'approved' ? 'Cuti disetujui' : 'Cuti ditolak']);
            exit;
        }
    }

    if ($action === 'approve_overtime' || $action === 'reject_overtime') {
        $otId = (int)($_POST['overtime_id'] ?? 0);
        $newStatus = ($action === 'approve_overtime') ? 'approved' : 'rejected';
        if ($otId > 0) {
            $db->query(
                "UPDATE overtime_requests SET status = ?, approved_by = ?, approved_at = NOW(), admin_notes = ? WHERE id = ?",
                [$newStatus, $approver, $adminNotes, $otId]
            );
            $req = $db->fetchOne("SELECT employee_id, overtime_date FROM overtime_requests WHERE id = ?", [$otId]);
            if ($req) {
                $sl = $newStatus === 'approved' ? 'Disetujui' : 'Ditolak';
                $db->query("INSERT INTO notifications (user_id, type, title, message, data, created_at) VALUES (?, 'overtime_response', ?, ?, ?, NOW())", [
                    $req['employee_id'],
                    'Lembur ' . $sl,
                    'Pengajuan lembur tanggal ' . $req['overtime_date'] . ' ' . strtolower($sl) . ($adminNotes ? '. Catatan: ' . $adminNotes : ''),
                    json_encode(['overtime_id' => $otId, 'status' => $newStatus, 'overtime_date' => $req['overtime_date']])
                ]);
            }
            // Push notification to staff member
            try {
                require_once dirname(dirname(__FILE__)) . '/includes/PushNotificationHelper.php';
                $pushHelper = new PushNotificationHelper($db);
                $emoji = $newStatus === 'approved' ? '✅' : '❌';
                $pushHelper->sendToEmployees(
                    [$req['employee_id']],
                    "{$emoji} Lembur {$sl}",
                    'Pengajuan lembur tanggal ' . $req['overtime_date'] . ' ' . strtolower($sl) . ($adminNotes ? ". Catatan: {$adminNotes}" : ''),
                    [
                        'type' => 'overtime_response',
                        'tag'  => 'overtime-resp-' . $otId,
                        'url'  => '/modules/payroll/staff-portal.php'
                    ]
                );
            } catch (\Throwable $pushErr) {
                error_log('Push notification error (overtime approval): ' . $pushErr->getMessage());
            }

            echo json_encode(['success' => true, 'message' => $newStatus === 'approved' ? 'Lembur disetujui' : 'Lembur ditolak']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ═══ STAFF: Get own notifications ═══
$limit = min((int)($_GET['limit'] ?? 10), 50);

try {
    $db->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL, message TEXT, data JSON, is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_read (user_id, is_read), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $notifications = $db->fetchAll("SELECT id, type, title, message, data, is_read, created_at
        FROM notifications WHERE user_id = ? AND is_read = 0
        ORDER BY created_at DESC LIMIT ?", [$user['id'], $limit]) ?: [];

    $countResult = $db->fetchOne("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0", [$user['id']]);
    $unreadCount = $countResult['cnt'] ?? 0;

    $markRead = isset($_GET['mark_read']) && $_GET['mark_read'] === 'true';
    if ($markRead && !empty($notifications)) {
        $ids = array_column($notifications, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->query("UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders)", $ids);
    }

    $formatted = [];
    foreach ($notifications as $notif) {
        $formatted[] = [
            'id' => $notif['id'],
            'type' => $notif['type'],
            'title' => $notif['title'],
            'message' => $notif['message'],
            'data' => json_decode($notif['data'], true),
            'time' => date('H:i', strtotime($notif['created_at'])),
            'date' => date('d M Y', strtotime($notif['created_at'])),
            'relative' => getRelativeTime($notif['created_at'])
        ];
    }

    echo json_encode(['success' => true, 'notifications' => $formatted, 'unread_count' => $unreadCount]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'notifications' => [], 'unread_count' => 0]);
}

function getRelativeTime($datetime)
{
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    if ($diff->y > 0) return $diff->y . ' tahun lalu';
    if ($diff->m > 0) return $diff->m . ' bulan lalu';
    if ($diff->d > 0) return $diff->d . ' hari lalu';
    if ($diff->h > 0) return $diff->h . ' jam lalu';
    if ($diff->i > 0) return $diff->i . ' menit lalu';
    return 'Baru saja';
}
