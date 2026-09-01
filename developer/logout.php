<?php
/**
 * Developer Panel - Logout
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->logout();

// logout() already redirects, but just in case:
header('Location: login.php');
exit;
