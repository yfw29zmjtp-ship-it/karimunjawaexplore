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

// Get date parameter (default to today)
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get hourly transaction data for the selected date
// Since we don't have hourly data, we'll show transaction by time of day
$transData = $db->fetchAll(
    "SELECT 
        HOUR(transaction_time) as hour,
        SUM(CASE WHEN transaction_type = 'income' AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project')) THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN transaction_type = 'expense' AND (source_type IS NULL OR source_type != 'owner_project') THEN amount ELSE 0 END) as expense
    FROM cash_book
    WHERE DATE(transaction_date) = :date
    GROUP BY HOUR(transaction_time)
    ORDER BY hour ASC",
    ['date' => $date]
);

// Map transaction data by hour
$transMap = [];
foreach ($transData as $data) {
    $transMap[$data['hour']] = $data;
}

// Fill all 24 hours (missing hours will have 0 values)
$labels = [];
$income = [];
$expense = [];

for ($h = 0; $h < 24; $h++) {
    $labels[] = sprintf('%02d:00', $h);
    $income[] = isset($transMap[$h]) ? (float)$transMap[$h]['income'] : 0;
    $expense[] = isset($transMap[$h]) ? (float)$transMap[$h]['expense'] : 0;
}

// Return JSON response
echo json_encode([
    'success' => true,
    'labels' => $labels,
    'income' => $income,
    'expense' => $expense,
    'timestamp' => date('Y-m-d H:i:s'),
    'date' => $date
]);
