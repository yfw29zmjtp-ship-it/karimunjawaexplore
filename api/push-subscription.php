<?php

/**
 * API: Push Subscription Management
 * Subscribe/unsubscribe browser push notifications
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once dirname(dirname(__FILE__)) . '/config/vapid.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ GET: Return VAPID public key (no vendor needed) ═══
if ($method === 'GET' && $action === 'vapid-public-key') {
    echo json_encode([
        'success'   => true,
        'publicKey' => VAPID_PUBLIC_KEY,
    ]);
    exit;
}

// ═══ GET: Diagnostic - kirim 1 push test ke diri sendiri (owner/admin/developer) ═══
// Dipakai untuk cek kenapa notifikasi tidak masuk, tanpa perlu akses DB langsung.
if ($method === 'GET' && $action === 'test-send') {
    session_status() === PHP_SESSION_NONE && session_start();
    if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['owner', 'admin', 'developer'], true)) {
        echo json_encode(['success' => false, 'message' => 'Harus login sebagai owner/admin/developer']);
        exit;
    }

    $autoloadPath = dirname(dirname(__FILE__)) . '/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        echo json_encode(['success' => false, 'message' => 'Server push library belum ter-install (vendor/autoload.php tidak ada)']);
        exit;
    }
    require_once $autoloadPath;
    require_once dirname(dirname(__FILE__)) . '/includes/PushNotificationHelper.php';

    $db = Database::getInstance();
    $push = new PushNotificationHelper($db);
    $myCount = $push->getSubscriptionCount((int)$_SESSION['user_id']);
    $result = $push->sendToAdmins('Test Notifikasi', 'Ini contoh notifikasi push manual dari Owner Portal.', ['url' => 'owner-dashboard.php']);

    echo json_encode([
        'success' => true,
        'your_subscription_count' => $myCount,
        'send_result' => $result,
        'note' => $myCount === 0
            ? 'Belum ada subscription tersimpan untuk akun Anda - aktifkan dulu tombol "Aktifkan Notifikasi" di Owner Portal.'
            : null,
    ]);
    exit;
}

// All other actions require POST and the PushNotificationHelper
if ($method !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check if vendor autoload exists (required for push operations)
$autoloadPath = dirname(dirname(__FILE__)) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo json_encode(['success' => false, 'message' => 'Server push library not installed. Run composer install.']);
    exit;
}

require_once dirname(dirname(__FILE__)) . '/includes/PushNotificationHelper.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$db = Database::getInstance();
$push = new PushNotificationHelper($db);

$action = $input['action'] ?? $action;

// ═══ SUBSCRIBE ═══
if ($action === 'subscribe') {
    $subscription = $input['subscription'] ?? null;
    $userId       = isset($input['user_id']) ? (int)$input['user_id'] : null;
    $employeeId   = isset($input['employee_id']) ? (int)$input['employee_id'] : null;

    // Also check session for user_id
    if (!$userId) {
        session_status() === PHP_SESSION_NONE && session_start();
        $userId = $_SESSION['user_id'] ?? null;
    }

    if (!$subscription || empty($subscription['endpoint'])) {
        echo json_encode(['success' => false, 'message' => 'Subscription data required']);
        exit;
    }

    $result = $push->saveSubscription($subscription, $userId, $employeeId);
    echo json_encode([
        'success' => $result,
        'message' => $result ? 'Push subscription berhasil disimpan' : 'Gagal menyimpan subscription',
    ]);
    exit;
}

// ═══ UNSUBSCRIBE ═══
if ($action === 'unsubscribe') {
    $endpoint = $input['endpoint'] ?? '';
    if (empty($endpoint)) {
        echo json_encode(['success' => false, 'message' => 'Endpoint required']);
        exit;
    }

    $push->removeSubscription($endpoint);
    echo json_encode(['success' => true, 'message' => 'Push subscription dihapus']);
    exit;
}

// ═══ STATUS ═══
if ($action === 'status') {
    session_status() === PHP_SESSION_NONE && session_start();
    $userId     = $_SESSION['user_id'] ?? null;
    $employeeId = isset($input['employee_id']) ? (int)$input['employee_id'] : null;

    $count = $push->getSubscriptionCount($userId, $employeeId);
    echo json_encode([
        'success'    => true,
        'subscribed' => $count > 0,
        'count'      => $count,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
