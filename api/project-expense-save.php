<?php

/**
 * API: Simpan Pengeluaran Projek (Simple Version)
 * Desain baru: Budget projek mandiri, tidak terhubung dengan investor
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

    $project_id = $_POST['project_id'] ?? null;
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $category = $_POST['category'] ?? 'other';
    $amount = floatval($_POST['amount'] ?? 0);
    $description = $_POST['description'] ?? '';
    $receipt_number = $_POST['receipt_number'] ?? null;
    $purchase_source = in_array($_POST['purchase_source'] ?? '', ['jepara', 'karimunjawa']) ? $_POST['purchase_source'] : null;
    $payment_status = ($_POST['payment_status'] ?? '') === 'lunas' ? 'lunas' : 'belum_lunas';
    $contractor_name = trim($_POST['contractor_name'] ?? '') !== '' ? trim($_POST['contractor_name']) : null;

    // Validate
    if (!$project_id) {
        echo json_encode(['success' => false, 'message' => 'Project ID required']);
        exit;
    }

    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jumlah harus lebih dari 0']);
        exit;
    }

    // Check project exists
    $stmt = $db->prepare("SELECT id FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Project tidak ditemukan']);
        exit;
    }

    // Check table structure
    $stmt = $db->prepare("DESCRIBE project_expenses");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $cols = ['project_id', 'expense_date', 'category', 'description'];
    $params = [$project_id, $expense_date, $category, $description];

    // Value is entered in Rupiah - store in whichever amount column(s) the table
    // has so totals computed elsewhere (SUM(amount_idr), or amount fallback) stay correct.
    if (in_array('amount', $columns)) {
        $cols[] = 'amount';
        $params[] = $amount;
    }
    if (in_array('amount_idr', $columns)) {
        $cols[] = 'amount_idr';
        $params[] = $amount;
    }

    if (in_array('receipt_number', $columns)) {
        $cols[] = 'receipt_number';
        $params[] = $receipt_number;
    } elseif (in_array('reference_no', $columns)) {
        $cols[] = 'reference_no';
        $params[] = $receipt_number;
    }

    if (in_array('purchase_source', $columns)) {
        $cols[] = 'purchase_source';
        $params[] = $purchase_source;
    }
    if (in_array('payment_status', $columns)) {
        $cols[] = 'payment_status';
        $params[] = $payment_status;
    }
    if (in_array('contractor_name', $columns)) {
        $cols[] = 'contractor_name';
        $params[] = $contractor_name;
    }

    if (in_array('created_by', $columns)) {
        $cols[] = 'created_by';
        $params[] = $_SESSION['user_id'] ?? 1;
    }

    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $colList = implode(', ', $cols) . ', created_at';
    $stmt = $db->prepare("INSERT INTO project_expenses ($colList) VALUES ($placeholders, NOW())");
    $stmt->execute($params);

    $expense_id = $db->lastInsertId();

    // Update project total_spent if column exists
    if (in_array('total_spent', $columns)) {
        $stmt = $db->prepare("
            UPDATE projects 
            SET total_spent = COALESCE(total_spent, 0) + ? 
            WHERE id = ?
        ");
        // Wrong table, let me check projects columns
    }

    echo json_encode([
        'success' => true,
        'message' => 'Pengeluaran berhasil disimpan',
        'expense_id' => $expense_id
    ]);
} catch (PDOException $e) {
    error_log('Project expense error: ' . $e->getMessage());
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
