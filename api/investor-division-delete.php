<?php
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $id = intval($_POST['division_id'] ?? 0);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID wajib']);
        exit;
    }

    $stmt = $db->prepare("DELETE FROM project_division_expenses WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Pengeluaran divisi berhasil dihapus']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
