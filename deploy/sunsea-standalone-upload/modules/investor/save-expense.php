<?php
// Suppress errors that might break JSON response
error_reporting(0);
ini_set('display_errors', 0);

// Start buffering to catch any unwanted output from includes
ob_start();

define('APP_ACCESS', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

// Clean buffer after includes
ob_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}


// Support JSON input
$input = json_decode(file_get_contents('php://input'), true);
if ($input) {
    $project_id = $input['project_id'] ?? null;
    $amount = $input['amount'] ?? 0;
    $category = $input['category'] ?? 'other';
    $expense_date = $input['expense_date'] ?? date('Y-m-d');
    $description = $input['description'] ?? '';
} else {
    $project_id = $_POST['project_id'] ?? null;
    $amount = $_POST['amount'] ?? 0;
    $category = $_POST['category'] ?? 'other';
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $description = $_POST['description'] ?? '';
}

if (!$project_id || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Insert ke project_expenses (include 'amount' column to satisfy NOT NULL constraint)
    $db->execute(
        "INSERT INTO project_expenses (project_id, amount_idr, amount, category, expense_date, description) 
         VALUES (?, ?, ?, ?, ?, ?)",
        [$project_id, $amount, $amount, $category, $expense_date, $description]
    );
    
    // Update project expenses total
    $stmt = $db->getConnection()->prepare("
        SELECT SUM(amount_idr) as total FROM project_expenses WHERE project_id = ?
    ");
    $stmt->execute([$project_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalExpenses = $result['total'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'message' => 'Pengeluaran berhasil dicatat',
        'totalExpenses' => $totalExpenses
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
