<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$conn = $db->getConnection();

// Check if confirmed
if (!isset($_GET['confirm']) || $_GET['confirm'] != '1') {
    $_SESSION['error'] = 'Invalid request';
    header('Location: purchase-orders.php');
    exit;
}

// Get PO ID
$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;

if ($po_id <= 0) {
    $_SESSION['error'] = 'Invalid PO ID';
    header('Location: purchase-orders.php');
    exit;
}

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Get PO details
    $stmt = $conn->prepare("SELECT po_number, status FROM purchase_orders_header WHERE id = ?");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$po) {
        throw new Exception('PO tidak ditemukan');
    }
    
    if ($po['status'] !== 'submitted') {
        throw new Exception('Hanya PO dengan status "Submitted" yang bisa di-reject');
    }
    
    // Delete PO details first (foreign key constraint)
    $stmt = $conn->prepare("DELETE FROM purchase_orders_detail WHERE po_header_id = ?");
    $stmt->execute([$po_id]);
    
    // Delete PO header
    $stmt = $conn->prepare("DELETE FROM purchase_orders_header WHERE id = ?");
    $stmt->execute([$po_id]);
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success'] = "✅ PO {$po['po_number']} berhasil di-reject dan dihapus";
    
} catch (Exception $e) {
    // Rollback on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    $_SESSION['error'] = '❌ Gagal reject PO: ' . $e->getMessage();
}

header('Location: purchase-orders.php');
exit;
