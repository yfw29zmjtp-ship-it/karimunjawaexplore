<?php
/**
 * API: Hapus Data Kontraktor
 */
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $project_id = intval($_POST['project_id'] ?? 0);
    $contractor_id = intval($_POST['contractor_id'] ?? 0);

    if (!$project_id || !$contractor_id) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
        exit;
    }

    $stmt = $db->prepare("DELETE FROM project_contractors WHERE id = ? AND project_id = ?");
    $stmt->execute([$contractor_id, $project_id]);

    echo json_encode(['success' => true, 'message' => 'Kontraktor berhasil dihapus']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
