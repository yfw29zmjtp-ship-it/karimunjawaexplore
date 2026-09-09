<?php

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/EmailHelper.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['unread' => 0]);
    exit;
}

$db = Database::getInstance();
$emailConfig = EmailHelper::resolveConfig($db);
if ($emailConfig === null) {
    echo json_encode(['unread' => 0]);
    exit;
}

// Opening a real IMAP connection on every poll (every 1-2 min, from EVERY admin page) is
// too slow/expensive on shared hosting - cache the result for a few minutes instead.
$cacheFile = sys_get_temp_dir() . '/karexp_email_unread.json';
$cacheTtl = 180; // seconds
$cached = @json_decode((string)@file_get_contents($cacheFile), true);
if (is_array($cached) && isset($cached['t'], $cached['unread']) && (time() - (int)$cached['t']) < $cacheTtl) {
    echo json_encode(['unread' => (int)$cached['unread']]);
    exit;
}

try {
    $emailHelper = new EmailHelper($emailConfig);
    $unread = $emailHelper->countUnread();
    @file_put_contents($cacheFile, json_encode(['t' => time(), 'unread' => $unread]));
    echo json_encode(['unread' => $unread]);
} catch (Throwable $e) {
    // Cache the failure too (briefly) so a broken mailbox doesn't hammer IMAP every poll.
    @file_put_contents($cacheFile, json_encode(['t' => time(), 'unread' => 0]));
    echo json_encode(['unread' => 0]);
}
