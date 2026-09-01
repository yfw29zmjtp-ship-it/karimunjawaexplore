<?php
/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Get Categories AJAX
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

$db = Database::getInstance();

$type = $_GET['type'] ?? 'income';

// Get categories filtered by category_type (was incorrectly 'transaction_type')
$categories = $db->fetchAll(
    "SELECT id, category_name 
     FROM categories 
     WHERE category_type = :type AND is_active = 1 
     ORDER BY category_name",
    ['type' => $type]
);

echo json_encode($categories);
?>
