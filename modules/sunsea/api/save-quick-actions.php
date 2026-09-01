<?php

/**
 * Sunsea - Save Quick Action Settings API
 */
define('APP_ACCESS', true);
require_once '../../../config/config.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth.php';
require_once '../db-helper.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->requireLogin();

    $pdo = getSunseaConnection();

    // Get JSON data
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // Sanitize and validate
    $validKeys = ['tamu', 'booking', 'calendar', 'partner', 'guide', 'coordinator', 'facility', 'customer', 'quotation', 'invoice', 'calculator', 'package'];
    $settings = [];

    foreach ($validKeys as $key) {
        $settings[$key] = isset($input[$key]) && $input[$key] === true;
    }

    // Save to settings table
    $settingValue = json_encode($settings);

    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");

    $stmt->execute(['quick_actions_visible', $settingValue, $settingValue]);

    echo json_encode(['success' => true, 'message' => 'Pengaturan disimpan']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
