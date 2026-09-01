<?php

/**
 * API: Update Pengeluaran Projek
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $expense_id = $_POST['expense_id'] ?? null;
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $category = $_POST['category'] ?? 'other';
    $amount = floatval($_POST['amount'] ?? 0);
    $description = $_POST['description'] ?? '';
    $receipt_number = $_POST['receipt_number'] ?? null;
    $purchase_source = in_array($_POST['purchase_source'] ?? '', ['jepara', 'karimunjawa']) ? $_POST['purchase_source'] : null;
    $payment_status = ($_POST['payment_status'] ?? '') === 'lunas' ? 'lunas' : 'belum_lunas';
    $contractor_name = trim($_POST['contractor_name'] ?? '') !== '' ? trim($_POST['contractor_name']) : null;

    if (!$expense_id) {
        echo json_encode(['success' => false, 'message' => 'Expense ID required']);
        exit;
    }

    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jumlah harus lebih dari 0']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM project_expenses WHERE id = ?");
    $stmt->execute([$expense_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Data pengeluaran tidak ditemukan']);
        exit;
    }

    // Check table structure so we only update columns that actually exist
    $stmt = $db->prepare("DESCRIBE project_expenses");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sets = ['expense_date = ?', 'category = ?', 'description = ?'];
    $params = [$expense_date, $category, $description];

    if (in_array('amount', $columns)) {
        $sets[] = 'amount = ?';
        $params[] = $amount;
    }
    if (in_array('amount_idr', $columns)) {
        $sets[] = 'amount_idr = ?';
        $params[] = $amount;
    }

    if (in_array('receipt_number', $columns)) {
        $sets[] = 'receipt_number = ?';
        $params[] = $receipt_number;
    } elseif (in_array('reference_no', $columns)) {
        $sets[] = 'reference_no = ?';
        $params[] = $receipt_number;
    }

    if (in_array('purchase_source', $columns)) {
        $sets[] = 'purchase_source = ?';
        $params[] = $purchase_source;
    }
    if (in_array('payment_status', $columns)) {
        $sets[] = 'payment_status = ?';
        $params[] = $payment_status;
    }
    if (in_array('contractor_name', $columns)) {
        $sets[] = 'contractor_name = ?';
        $params[] = $contractor_name;
    }

    if (in_array('updated_at', $columns)) {
        $sets[] = 'updated_at = NOW()';
    }

    $params[] = $expense_id;

    $stmt = $db->prepare("UPDATE project_expenses SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Pengeluaran berhasil diperbarui'
    ]);
} catch (PDOException $e) {
    error_log('Project expense update error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
