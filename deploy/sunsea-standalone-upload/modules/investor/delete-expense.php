<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$id = $_GET['id'] ?? null;

if ($id) {
    try {
        $db = Database::getInstance();
        $db->execute("DELETE FROM project_expenses WHERE id = ?", [$id]);
    } catch (Exception $e) {
        // Log error if needed
    }
}

// Redirect back to investor page with finance tab active
header("Location: index.php?tab=finance");
exit;
