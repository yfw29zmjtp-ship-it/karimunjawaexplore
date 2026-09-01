<?php
/**
 * CQC Quick Access
 * Shortcut untuk akses menu CQC dari berbagai tempat
 */

session_start();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_to'] = 'cqc-menu.php';
    header('Location: login.php');
    exit;
}

// Switch to CQC if not already
if ($_SESSION['active_business_id'] !== 'cqc') {
    $_SESSION['active_business_id'] = 'cqc';
}

// Redirect to menu
header('Location: cqc-menu.php');
exit;
