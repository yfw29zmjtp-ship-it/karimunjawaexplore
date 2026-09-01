<?php
/**
 * Developer Panel - Logout
 */

require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->logout();
