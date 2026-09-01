<?php
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $worker_id = intval($_POST['worker_id'] ?? 0);

    if (!$worker_id) {
        echo json_encode(['success' => false, 'message' => 'Worker ID wajib']);
        exit;
    }

    // Delete related salaries first
    try {
        $db->prepare("DELETE FROM project_salaries WHERE worker_id = ?")->execute([$worker_id]);
    } catch (Exception $e) { /* ignore */ }

    $stmt = $db->prepare("DELETE FROM project_workers WHERE id = ?");
    $stmt->execute([$worker_id]);

    echo json_encode(['success' => true, 'message' => 'Pekerja berhasil dihapus']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
