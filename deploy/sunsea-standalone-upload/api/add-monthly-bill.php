<?php

/**
 * API: MONTHLY BILLS - ADD/CREATE
 * POST /api/add-monthly-bill.php
 * 
 * Create new monthly bill entry
 */

// OUTPUT BUFFERING first
if (ob_get_level()) ob_end_clean();
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

try {
    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/auth.php';
    require_once '../includes/CashbookHelper.php';

    // Start session if needed
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.name', 'NARAYANA_SESSION');
        session_start();
    }

    $auth = new Auth();
    if (!$auth->isLoggedIn() || !$auth->hasPermission('finance')) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $db = Database::getInstance();
    $currentUser = $auth->getCurrentUser();

    // Validate required fields
    $required = ['bill_name', 'bill_month', 'amount'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field $field required");
        }
    }

    // Get POST data
    $billName = trim($_POST['bill_name']);
    $billMonth = $_POST['bill_month']; // Format: 2026-04
    $amount = (float)$_POST['amount'];
    $dueDate = $_POST['due_date'] ?? null;
    $divisionId = (int)($_POST['division_id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $isRecurring = (int)($_POST['is_recurring'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    // Validate month format (YYYY-MM)
    if (!preg_match('/^\d{4}-\d{2}$/', $billMonth)) {
        throw new Exception('Month format harus YYYY-MM');
    }
    
    // Convert month to date format (first day of month)
    $billMonthDate = $billMonth . '-01';
    DateTime::createFromFormat('Y-m-d', $billMonthDate); // validate
    
    // Generate bill code
    $billCode = 'BL-' . str_replace('-', '', $billMonth) . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

    // Insert into monthly_bills
    $result = $db->query(
        "INSERT INTO monthly_bills 
        (bill_code, division_id, category_id, bill_name, bill_month, amount, due_date, is_recurring, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $billCode,
            $divisionId ?: null,
            $categoryId ?: null,
            $billName,
            $billMonthDate,
            $amount,
            $dueDate ?: null,
            $isRecurring,
            $notes,
            $currentUser['id']
        ]
    );

    if (!$result) {
        throw new Exception('Failed to create bill');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Tagihan berhasil dibuat',
        'bill_code' => $billCode,
        'bill_id' => $db->getConnection()->lastInsertId()
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>
