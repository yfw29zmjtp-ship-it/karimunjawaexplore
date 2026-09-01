<?php
/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Logout
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$auth = new Auth();
$auth->logout();

// Clear remember-me tokens
$cookiePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
setcookie('adf_owner_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
setcookie('adf_saved_user', '', time() - 3600, $cookiePath, '', $isSecure, true);
setcookie('adf_owner_saved_user', '', time() - 3600, $cookiePath, '', $isSecure, true);

redirect(BASE_URL . '/login.php');
?>
