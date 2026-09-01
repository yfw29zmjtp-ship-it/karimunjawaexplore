<?php
/**
 * API: Simpan Pengeluaran Investor
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

    $project_id = intval($_POST['project_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $category_id = intval($_POST['category_id'] ?? 0) ?: null;
    $division_name = trim($_POST['division_name'] ?? '');

    // Validate
    if (!$project_id) {
        echo json_encode(['success' => false, 'message' => 'Project tidak ditemukan']);
        exit;
    }
    
    if (empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Deskripsi pengeluaran harus diisi']);
        exit;
    }
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jumlah harus lebih dari 0']);
        exit;
    }

    // Verify project exists
    $stmt = $db->prepare("SELECT id FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Projek tidak ditemukan']);
        exit;
    }

    // Ensure project_expenses table exists
    try {
        $db->query("SELECT 1 FROM project_expenses LIMIT 1");
    } catch (Exception $e) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS project_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                description TEXT,
                expense_date DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by INT,
                INDEX idx_project (project_id),
                INDEX idx_date (expense_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Detect available columns in project_expenses
    try {
        $stmt = $db->query("DESCRIBE project_expenses");
        $expCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Exception $e) {
        $expCols = ['id', 'project_id', 'amount', 'description', 'expense_date', 'created_by'];
    }

    // Build dynamic INSERT
    $ins_cols = ['project_id'];
    $ins_vals = ['?'];
    $ins_params = [$project_id];

    if (in_array('description', $expCols)) {
        $ins_cols[] = 'description'; $ins_vals[] = '?'; $ins_params[] = $description;
    }
    if (in_array('amount', $expCols)) {
        $ins_cols[] = 'amount'; $ins_vals[] = '?'; $ins_params[] = $amount;
    }
    if (in_array('expense_date', $expCols)) {
        $ins_cols[] = 'expense_date'; $ins_vals[] = '?'; $ins_params[] = $expense_date;
    }
    if (in_array('category_id', $expCols) && $category_id) {
        $ins_cols[] = 'category_id'; $ins_vals[] = '?'; $ins_params[] = $category_id;
    }
    if (in_array('division_name', $expCols) && $division_name !== '') {
        $ins_cols[] = 'division_name'; $ins_vals[] = '?'; $ins_params[] = $division_name;
    }
    if (in_array('created_by', $expCols)) {
        $ins_cols[] = 'created_by'; $ins_vals[] = '?'; $ins_params[] = $_SESSION['user_id'] ?? 1;
    }

    $sql = "INSERT INTO project_expenses (" . implode(', ', $ins_cols) . ") VALUES (" . implode(', ', $ins_vals) . ")";
    $stmt = $db->prepare($sql);
    $stmt->execute($ins_params);

    $expense_id = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Pengeluaran berhasil dicatat',
        'expense_id' => $expense_id
    ]);

} catch (PDOException $e) {
    error_log('Expense save error: ' . $e->getMessage());
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
?>
