<?php
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $project_id = intval($_POST['project_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $role = trim($_POST['role'] ?? 'Tukang');
    $daily_rate = floatval($_POST['daily_rate'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');

    if (!$project_id || !$name) {
        echo json_encode(['success' => false, 'message' => 'Project ID dan nama pekerja wajib diisi']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO project_workers (project_id, name, role, daily_rate, phone) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$project_id, $name, $role, $daily_rate, $phone]);

    echo json_encode(['success' => true, 'message' => 'Pekerja berhasil ditambahkan', 'id' => $db->lastInsertId()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
