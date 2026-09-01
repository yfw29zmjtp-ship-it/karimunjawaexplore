<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

// Check if user is logged in
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();

// Get month parameter (default to current month)
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Get expense data by category for the selected month
$expenseCategoryData = $db->fetchAll(
    "SELECT 
        c.category_name,
        COALESCE(SUM(cb.amount), 0) as total
    FROM categories c
    LEFT JOIN cash_book cb ON c.id = cb.category_id 
        AND cb.transaction_type = 'expense'
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month
        AND (cb.source_type IS NULL OR cb.source_type != 'owner_project')
    GROUP BY c.id, c.category_name
    HAVING total > 0
    ORDER BY total DESC",
    ['month' => $month]
);

// Prepare data for chart
$categories = [];
$amounts = [];

foreach ($expenseCategoryData as $data) {
    $categories[] = $data['category_name'];
    $amounts[] = (float)$data['total'];
}

// Return JSON response
echo json_encode([
    'success' => true,
    'categories' => $categories,
    'amounts' => $amounts,
    'month' => $month,
    'timestamp' => date('Y-m-d H:i:s')
]);
