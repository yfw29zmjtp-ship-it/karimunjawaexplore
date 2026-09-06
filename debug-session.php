<?php

/**
 * DEBUG: Session health check (uses real app bootstrap)
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== SESSION DEBUG (APP BOOTSTRAP) ===\n\n";

echo "1) BASIC\n";
echo "   HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";
echo "   SESSION_NAME: " . session_name() . "\n";
echo "   SESSION_STATUS: " . session_status() . " (1=disabled, 2=none, 3=active)\n";
echo "   SESSION_ID: " . (session_id() ?: 'EMPTY') . "\n\n";

echo "2) STORAGE\n";
$sessionPath = session_save_path();
echo "   session_save_path: " . ($sessionPath !== '' ? $sessionPath : 'EMPTY') . "\n";
echo "   path_exists: " . (is_dir($sessionPath) ? 'YES' : 'NO') . "\n";
echo "   path_writable: " . (is_writable($sessionPath) ? 'YES' : 'NO') . "\n";

$sid = session_id();
if ($sid !== '' && $sessionPath !== '') {
    $sep = substr($sessionPath, -1) === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR;
    $file = $sessionPath . $sep . 'sess_' . $sid;
    echo "   expected_file: $file\n";
    echo "   file_exists: " . (file_exists($file) ? 'YES' : 'NO') . "\n";
}
echo "\n";

echo "3) APP SESSION KEYS\n";
echo "   logged_in: " . (isset($_SESSION['logged_in']) ? var_export($_SESSION['logged_in'], true) : 'NOT SET') . "\n";
echo "   user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
echo "   role: " . ($_SESSION['role'] ?? 'NOT SET') . "\n";
echo "   active_business_id: " . ($_SESSION['active_business_id'] ?? 'NOT SET') . "\n";
echo "   debug_login_type_received: " . ($_SESSION['debug_login_type_received'] ?? 'NOT SET') . "\n";
echo "   debug_login_branch: " . ($_SESSION['debug_login_branch'] ?? 'NOT SET') . "\n\n";

echo "4) COOKIES\n";
echo "   NARAYANA_SESSION cookie: " . (isset($_COOKIE['NARAYANA_SESSION']) ? 'FOUND' : 'NOT FOUND') . "\n";
echo "   adf_remember_token: " . (isset($_COOKIE['adf_remember_token']) ? 'FOUND' : 'NOT FOUND') . "\n";
echo "   adf_saved_user: " . (isset($_COOKIE['adf_saved_user']) ? 'FOUND' : 'NOT FOUND') . "\n\n";

echo "=== END DEBUG ===\n";
