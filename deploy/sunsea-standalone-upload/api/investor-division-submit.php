<?php
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $project_id = intval($_POST['project_id'] ?? 0);

    if (!$project_id) {
        echo json_encode(['success' => false, 'message' => 'Project ID wajib']);
        exit;
    }

    $stmt = $db->prepare("UPDATE project_division_expenses SET status = 'approved' WHERE project_id = ? AND status = 'pending'");
    $stmt->execute([$project_id]);
    $count = $stmt->rowCount();

    echo json_encode(['success' => true, 'message' => "$count pengeluaran divisi berhasil diajukan ke Owner", 'count' => $count]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
