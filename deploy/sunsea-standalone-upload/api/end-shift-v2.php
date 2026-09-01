<?php
// Ultra-minimal - no includes, no buffers, pure JSON only
header('Content-Type: application/json; charset=utf-8');

// Check if user logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo '{"status":"error","message":"Unauthorized"}';
    exit;
}

// Get today's date
$today = date('Y-m-d');
$userId = (int)$_SESSION['user_id'];

// Connect to database
$mysqli = new mysqli('localhost', 'root', '', 'adf_system');
if ($mysqli->connect_error) {
    http_response_code(500);
    echo '{"status":"error","message":"Database error"}';
    exit;
}

$mysqli->set_charset('utf8');

// Fetch transactions
$totalIncome = 0;
$totalExpense = 0;
$transactions = [];

$query = "SELECT id, amount, transaction_type FROM cash_book WHERE DATE(transaction_date) = ?";
$stmt = $mysqli->prepare($query);
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
        $amount = (float)$row['amount'];
        if ($row['transaction_type'] === 'income') {
            $totalIncome += $amount;
        } else {
            $totalExpense += $amount;
        }
    }
    $stmt->close();
}

$mysqli->close();

// Return clean JSON
$response = [
    'status' => 'success',
    'data' => [
        'user' => [
            'id' => $userId,
            'name' => 'User'
        ],
        'daily_report' => [
            'date' => $today,
            'total_income' => (int)$totalIncome,
            'total_expense' => (int)$totalExpense,
            'net_balance' => (int)($totalIncome - $totalExpense),
            'transaction_count' => count($transactions)
        ]
    ]
];

echo json_encode($response);
exit;
?>