<?php
if (!defined('APP_ACCESS')) define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->logout();

$cookiePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';
$isSecure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('adf_remember_token',        '', time()-3600, $cookiePath,'', $isSecure, true);
setcookie('adf_owner_remember_token',  '', time()-3600, $cookiePath,'', $isSecure, true);
setcookie('adf_saved_user',            '', time()-3600, $cookiePath,'', $isSecure, true);
setcookie('adf_owner_saved_user',      '', time()-3600, $cookiePath,'', $isSecure, true);

redirect(BASE_URL . '/pwf-login.php');
