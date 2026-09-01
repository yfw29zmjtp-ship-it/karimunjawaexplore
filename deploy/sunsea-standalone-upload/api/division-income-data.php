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

// Check if source_type column exists
$hasSourceTypeCol = false;
try {
    $colCheck = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'source_type'");
    $hasSourceTypeCol = $colCheck && $colCheck->rowCount() > 0;
} catch (\Throwable $e) {
    $hasSourceTypeCol = false;
}

// Build exclusion filter for owner fund (not hotel income)
$ownerFundFilter = '';
if ($hasSourceTypeCol) {
    $ownerFundFilter = " AND (cb.source_type IS NULL OR cb.source_type NOT IN ('owner_fund','owner_project'))";
}

// Get income data by division for the selected month (excluding owner fund)
$divisionIncomeData = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(cb.amount), 0) as total
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND cb.transaction_type = 'income'
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $ownerFundFilter . "
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    HAVING total > 0
    ORDER BY total DESC",
    ['month' => $month]
);

// Prepare data for chart
$divisions = [];
$amounts = [];

foreach ($divisionIncomeData as $data) {
    $divisions[] = $data['division_name'];
    $amounts[] = (float)$data['total'];
}

// Return JSON response
echo json_encode([
    'success' => true,
    'divisions' => $divisions,
    'amounts' => $amounts,
    'month' => $month,
    'timestamp' => date('Y-m-d H:i:s')
]);
