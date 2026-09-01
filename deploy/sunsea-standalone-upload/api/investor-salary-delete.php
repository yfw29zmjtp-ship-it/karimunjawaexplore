<?php
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $salary_id = intval($_POST['salary_id'] ?? 0);

    if (!$salary_id) {
        echo json_encode(['success' => false, 'message' => 'Salary ID wajib']);
        exit;
    }

    $stmt = $db->prepare("DELETE FROM project_salaries WHERE id = ?");
    $stmt->execute([$salary_id]);

    echo json_encode(['success' => true, 'message' => 'Data gaji berhasil dihapus']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
