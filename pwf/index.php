<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/business_helper.php';

$auth = new Auth();

if ($auth->isLoggedIn()) {
    @setActiveBusinessId('pwf-furniture');
    header('Location: ' . BASE_URL . '/modules/pwf-office/dashboard.php');
    exit;
}

header('Location: ' . BASE_URL . '/pwf-login.php');
exit;
