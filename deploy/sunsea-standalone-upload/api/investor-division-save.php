<?php
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $project_id = intval($_POST['project_id'] ?? 0);
    $division_name = trim($_POST['division_name'] ?? '');
    $contractor_name = trim($_POST['contractor_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');

    if (!$project_id || !$division_name || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO project_division_expenses 
        (project_id, division_name, contractor_name, description, amount, expense_date)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$project_id, $division_name, $contractor_name, $description, $amount, $expense_date]);

    echo json_encode(['success' => true, 'message' => 'Pengeluaran divisi berhasil dicatat']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
