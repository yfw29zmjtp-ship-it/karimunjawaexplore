<?php

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $partnerName = trim((string)($_POST['partner_name'] ?? ''));
    if ($partnerName === '') {
        throw new Exception('Nama mitra tidak boleh kosong');
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $existsStmt = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
    $existsStmt->execute(['driver_drop_partner_name']);
    $existingId = (int)$existsStmt->fetchColumn();

    if ($existingId > 0) {
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE id = ?");
        $stmt->execute([$partnerName, $existingId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute(['driver_drop_partner_name', $partnerName]);
    }

    echo json_encode([
        'success' => true,
        'partner_name' => $partnerName,
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
