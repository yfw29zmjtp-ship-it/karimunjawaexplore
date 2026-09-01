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

// Get all-time data (yearly breakdown from first transaction to now)
$transData = $db->fetchAll(
    "SELECT 
        YEAR(transaction_date) as year,
        SUM(CASE WHEN transaction_type = 'income' AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project')) THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN transaction_type = 'expense' AND (source_type IS NULL OR source_type != 'owner_project') THEN amount ELSE 0 END) as expense
    FROM cash_book
    GROUP BY YEAR(transaction_date)
    ORDER BY year ASC"
);

// Prepare data
$labels = [];
$income = [];
$expense = [];

foreach ($transData as $data) {
    $labels[] = (string)$data['year'];
    $income[] = (float)$data['income'];
    $expense[] = (float)$data['expense'];
}

// If no data, show current year with 0 values
if (empty($labels)) {
    $labels[] = date('Y');
    $income[] = 0;
    $expense[] = 0;
}

// Return JSON response
echo json_encode([
    'success' => true,
    'labels' => $labels,
    'income' => $income,
    'expense' => $expense,
    'timestamp' => date('Y-m-d H:i:s'),
    'years' => count($labels)
]);
