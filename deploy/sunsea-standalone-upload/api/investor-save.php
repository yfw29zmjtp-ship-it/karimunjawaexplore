<?php
/**
 * API: Simpan Investor Baru
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

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Validate
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Nama investor harus diisi']);
        exit;
    }

    // Check what columns exist
    $stmt = $db->query("DESCRIBE investors");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Build insert based on available columns
    $sql_cols = [];
    $params = [];

    // name column
    if (in_array('name', $columns)) {
        $sql_cols[] = 'name';
        $params[] = $name;
    } elseif (in_array('investor_name', $columns)) {
        $sql_cols[] = 'investor_name';
        $params[] = $name;
    }

    // phone
    if (in_array('phone', $columns) && $phone) {
        $sql_cols[] = 'phone';
        $params[] = $phone;
    }

    // email
    if (in_array('email', $columns) && $email) {
        $sql_cols[] = 'email';
        $params[] = $email;
    }

    // notes
    if (in_array('notes', $columns) && $notes) {
        $sql_cols[] = 'notes';
        $params[] = $notes;
    }

    // balance starts at 0
    if (in_array('balance', $columns)) {
        $sql_cols[] = 'balance';
        $params[] = 0;
    } elseif (in_array('total_capital', $columns)) {
        $sql_cols[] = 'total_capital';
        $params[] = 0;
    }

    // status
    if (in_array('status', $columns)) {
        $sql_cols[] = 'status';
        $params[] = 'active';
    }

    // created_by
    if (in_array('created_by', $columns)) {
        $sql_cols[] = 'created_by';
        $params[] = $_SESSION['user_id'] ?? 1;
    }

    // created_at
    if (in_array('created_at', $columns)) {
        $sql_cols[] = 'created_at';
        $params[] = date('Y-m-d H:i:s');
    }

    $sql = "INSERT INTO investors (" . implode(', ', $sql_cols) . ") VALUES (" . implode(', ', array_fill(0, count($params), '?')) . ")";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $investor_id = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Investor berhasil ditambahkan',
        'investor_id' => $investor_id
    ]);

} catch (PDOException $e) {
    error_log('Investor save error: ' . $e->getMessage());
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
