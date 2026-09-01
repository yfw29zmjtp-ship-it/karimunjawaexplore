<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

if (isset($_GET['id'])) {
    $invoice_id = (int)$_GET['id'];
    
    try {
        // Start transaction
        $db->getConnection()->beginTransaction();
        
        // Get invoice data
        $invoice = $db->fetchOne("
            SELECT * FROM sales_invoices_header WHERE id = ?
        ", [$invoice_id]);
        
        if (!$invoice) {
            throw new Exception('Invoice tidak ditemukan');
        }
        
        // Check if already paid - if paid, need to delete cashbook entry too
        if ($invoice['payment_status'] === 'paid') {
            // Delete cashbook entry
            $db->query("
                DELETE FROM cashbook 
                WHERE transaction_type = 'income' 
                AND description LIKE :desc
                AND amount = :amount
                LIMIT 1
            ", [
                'desc' => '%' . $invoice['invoice_number'] . '%',
                'amount' => $invoice['total_amount']
            ]);
        }
        
        // Delete invoice details first (foreign key constraint)
        $db->query("DELETE FROM sales_invoices_detail WHERE invoice_header_id = ?", [$invoice_id]);
        
        // Delete invoice header
        $db->query("DELETE FROM sales_invoices_header WHERE id = ?", [$invoice_id]);
        
        // Commit transaction
        $db->getConnection()->commit();
        
        setFlashMessage('success', '✅ Invoice ' . $invoice['invoice_number'] . ' berhasil dihapus!');
        header('Location: index.php');
        exit;
        
    } catch (Exception $e) {
        $db->getConnection()->rollBack();
        setFlashMessage('error', 'Error: ' . $e->getMessage());
        header('Location: index.php');
        exit;
    }
} else {
    header('Location: index.php');
    exit;
}
