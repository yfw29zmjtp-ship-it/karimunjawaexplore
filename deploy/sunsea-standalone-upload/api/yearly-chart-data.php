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

// Get year parameter (default to current year)
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Month names (short)
$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];

// Get monthly transaction data for the year
$transData = $db->fetchAll(
    "SELECT 
        MONTH(transaction_date) as month,
        SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as expense
    FROM cash_book
    WHERE YEAR(transaction_date) = :year
    GROUP BY MONTH(transaction_date)
    ORDER BY month ASC",
    ['year' => $year]
);

// Map transaction data by month
$transMap = [];
foreach ($transData as $data) {
    $transMap[$data['month']] = $data;
}

// Fill all 12 months (missing months will have 0 values)
$labels = [];
$income = [];
$expense = [];

for ($m = 1; $m <= 12; $m++) {
    $labels[] = $monthNames[$m - 1];
    $income[] = isset($transMap[$m]) ? (float)$transMap[$m]['income'] : 0;
    $expense[] = isset($transMap[$m]) ? (float)$transMap[$m]['expense'] : 0;
}

// Return JSON response
echo json_encode([
    'success' => true,
    'labels' => $labels,
    'income' => $income,
    'expense' => $expense,
    'timestamp' => date('Y-m-d H:i:s'),
    'year' => $year
]);
