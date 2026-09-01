<?php
/**
 * Delete CQC Quotation
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

if ($id > 0) {
    try {
        $pdo = getCQCDatabaseConnection();
        
        // Delete items first
        $stmtItems = $pdo->prepare("DELETE FROM cqc_quotation_items WHERE quotation_id = ?");
        $stmtItems->execute([$id]);
        
        // Then delete quotation
        $stmt = $pdo->prepare("DELETE FROM cqc_quotations WHERE id = ?");
        $stmt->execute([$id]);
        
        $_SESSION['success'] = 'Quotation berhasil dihapus!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal menghapus quotation: ' . $e->getMessage();
    }
}

header('Location: index-cqc.php?tab=quotation');
exit;
