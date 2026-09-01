<?php
/**
 * API: Simpan Data Kontraktor
 */
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $project_id = intval($_POST['project_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $bidang = trim($_POST['bidang'] ?? '');
    $pic_name = trim($_POST['pic_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (!$project_id || !$name) {
        echo json_encode(['success' => false, 'message' => 'Nama kontraktor wajib diisi']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO project_contractors 
        (project_id, name, bidang, pic_name, phone)
        VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$project_id, $name, $bidang, $pic_name, $phone]);

    echo json_encode(['success' => true, 'message' => 'Kontraktor berhasil ditambahkan']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
