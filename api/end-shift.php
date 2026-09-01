<?php
// PURE JSON ONLY - NO FRAMEWORK, NO INCLUDES
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo '{"status":"error","message":"Unauthorized"}';
    exit;
}

$today = date('Y-m-d');
$userId = (int)$_SESSION['user_id'];

$conn = new mysqli('localhost', 'root', '', 'adf_system');
if ($conn->connect_error) {
    http_response_code(500);
    echo '{"status":"error","message":"DB connection failed"}';
    exit;
}
$conn->set_charset('utf8');

// Transactions
$transactions = [];
$totalIncome = 0;
$totalExpense = 0;

$stmt = $conn->prepare("SELECT id, amount, transaction_type, description FROM cash_book WHERE DATE(transaction_date) = ? ORDER BY transaction_date DESC");
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
        $amt = (float)$row['amount'];
        if ($row['transaction_type'] === 'income') $totalIncome += $amt;
        else $totalExpense += $amt;
    }
    $stmt->close();
}

// POs
$pos = [];
$stmt = $conn->prepare("SELECT id, po_number, total_amount, status FROM purchase_orders WHERE DATE(created_at) = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $pos[] = $row;
    $stmt->close();
}

// User
$userInfo = [];
$stmt = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $userInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$conn->close();

$response = [
    'status' => 'success',
    'data' => [
        'user' => [
            'name' => $userInfo['full_name'] ?? $userInfo['username'] ?? 'User'
        ],
        'daily_report' => [
            'date' => $today,
            'total_income' => (int)$totalIncome,
            'total_expense' => (int)$totalExpense,
            'net_balance' => (int)($totalIncome - $totalExpense),
            'transaction_count' => count($transactions),
            'transactions' => $transactions
        ],
        'pos_data' => [
            'count' => count($pos),
            'list' => $pos
        ]
    ]
];

echo json_encode($response);
exit;
