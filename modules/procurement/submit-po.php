<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['po_id'])) {
    $po_id = (int)$_POST['po_id'];
    
    // Update status to submitted
    $result = updatePurchaseOrderStatus($po_id, 'submitted', $currentUser['id']);
    
    if ($result['success']) {
        $_SESSION['success'] = 'PO berhasil di-submit!';
    } else {
        $_SESSION['error'] = $result['message'];
    }
    
    header('Location: purchase-orders.php');
    exit;
}

header('Location: purchase-orders.php');
exit;
