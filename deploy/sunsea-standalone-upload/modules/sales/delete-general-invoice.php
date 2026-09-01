<?php
/**
 * CQC Delete General Invoice
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once '../cqc-projects/db-helper.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    header('Location: index-cqc.php?tab=general');
    exit;
}

try {
    $pdo = getCQCDatabaseConnection();
    
    // Get invoice info first
    $stmt = $pdo->prepare("SELECT invoice_number, payment_status FROM cqc_general_invoices WHERE id = ?");
    $stmt->execute([$id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception("Faktur tidak ditemukan.");
    }
    
    if ($invoice['payment_status'] === 'paid') {
        throw new Exception("Tidak dapat menghapus faktur yang sudah dibayar.");
    }
    
    // Delete invoice items first
    $stmt = $pdo->prepare("DELETE FROM cqc_general_invoice_items WHERE invoice_id = ?");
    $stmt->execute([$id]);
    
    // Delete main invoice
    $stmt = $pdo->prepare("DELETE FROM cqc_general_invoices WHERE id = ?");
    $stmt->execute([$id]);
    
    $_SESSION['success'] = "Faktur {$invoice['invoice_number']} berhasil dihapus.";
    
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: index-cqc.php?tab=general');
exit;
