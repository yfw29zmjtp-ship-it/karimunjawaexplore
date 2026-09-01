<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
    
    if ($po_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid PO ID']);
        exit;
    }
    
    // Approve dan posting ke cash_book
    $result = approvePurchaseOrderAndPay($po_id, $currentUser['id']);
    
    echo json_encode($result);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
