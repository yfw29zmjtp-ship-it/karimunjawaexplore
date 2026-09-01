<?php
/**
 * API: Reset Investor Data
 * Menghapus semua data investor dan transaksinya
 */

define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json');

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate confirmation
$confirm = isset($_POST['confirm']) ? strtoupper(trim($_POST['confirm'])) : '';
if ($confirm !== 'RESET') {
    echo json_encode(['success' => false, 'message' => 'Konfirmasi tidak valid. Ketik RESET untuk melanjutkan.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Count data before deletion for reporting
    $investorCount = 0;
    $transactionCount = 0;
    
    try {
        $investorCount = $db->query("SELECT COUNT(*) FROM investors")->fetchColumn();
    } catch (Exception $e) {
        error_log("Reset: Count investors failed - " . $e->getMessage());
    }
    
    try {
        $transactionCount = $db->query("SELECT COUNT(*) FROM investor_transactions")->fetchColumn();
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // Delete investor transactions first (foreign key dependency)
    try {
        $db->exec("DELETE FROM investor_transactions");
    } catch (Exception $e) {
        // Table might not exist or has different structure
        error_log("Reset: investor_transactions delete failed - " . $e->getMessage());
    }
    
    // Delete investors
    try {
        $db->exec("DELETE FROM investors");
    } catch (Exception $e) {
        error_log("Reset: investors delete failed - " . $e->getMessage());
        throw $e;
    }
    
    // Reset auto increment
    try {
        $db->exec("ALTER TABLE investors AUTO_INCREMENT = 1");
    } catch (Exception $e) {
        // Ignore if fails
        error_log("Reset: ALTER TABLE investors failed - " . $e->getMessage());
    }
    
    try {
        $db->exec("ALTER TABLE investor_transactions AUTO_INCREMENT = 1");
    } catch (Exception $e) {
        // Ignore if fails
        error_log("Reset: ALTER TABLE investor_transactions failed - " . $e->getMessage());
    }
    
    // Log the action
    $currentUser = $auth->getCurrentUser();
    error_log(sprintf(
        "INVESTOR RESET: User %s reset all investor data. Deleted %d investors, %d transactions.",
        $currentUser['username'] ?? 'unknown',
        $investorCount,
        $transactionCount
    ));
    
    echo json_encode([
        'success' => true,
        'message' => sprintf(
            'Berhasil menghapus %d investor dan %d transaksi.',
            $investorCount,
            $transactionCount
        ),
        'deleted' => [
            'investors' => $investorCount,
            'transactions' => $transactionCount
        ]
    ]);
    
} catch (Exception $e) {
    error_log("INVESTOR RESET ERROR: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Gagal mereset data: ' . $e->getMessage()
    ]);
}
