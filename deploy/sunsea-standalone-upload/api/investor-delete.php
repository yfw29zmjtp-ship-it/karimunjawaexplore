<?php
/**
 * API: Delete Single Investor
 * Menghapus satu investor dan semua transaksi terkaitnya
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $investor_id = intval($_POST['investor_id'] ?? 0);

    if ($investor_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID investor tidak valid']);
        exit;
    }

    // Check if investor exists
    $stmt = $db->prepare("SELECT name, investor_name FROM investors WHERE id = ?");
    $stmt->execute([$investor_id]);
    $investor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$investor) {
        echo json_encode(['success' => false, 'message' => 'Investor tidak ditemukan']);
        exit;
    }

    $investor_name = $investor['name'] ?? $investor['investor_name'] ?? 'Unknown';

    // Delete investor transactions first (foreign key dependency)
    try {
        $stmt = $db->prepare("DELETE FROM investor_transactions WHERE investor_id = ?");
        $stmt->execute([$investor_id]);
    } catch (Exception $e) {
        // Table might not exist or has different structure
        error_log("Delete investor: investor_transactions delete failed - " . $e->getMessage());
    }

    // Delete investor
    $stmt = $db->prepare("DELETE FROM investors WHERE id = ?");
    $stmt->execute([$investor_id]);

    // Log the action
    $currentUser = $auth->getCurrentUser();
    error_log(sprintf(
        "INVESTOR DELETE: User %s deleted investor #%d (%s) with all associated transactions.",
        $currentUser['username'] ?? 'unknown',
        $investor_id,
        $investor_name
    ));

    echo json_encode([
        'success' => true,
        'message' => 'Investor "' . htmlspecialchars($investor_name) . '" dan semua dana setornya berhasil dihapus.',
        'deleted_investor_id' => $investor_id,
        'deleted_investor_name' => $investor_name
    ]);

} catch (PDOException $e) {
    error_log('Investor delete error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
