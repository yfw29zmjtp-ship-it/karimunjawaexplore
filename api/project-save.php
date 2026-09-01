<?php
/**
 * API: Simpan Projek Baru (Simple)
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
    $description = trim($_POST['description'] ?? '');
    $budget = floatval($_POST['budget'] ?? 0);
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    $status = $_POST['status'] ?? 'planning';

    // Validate
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Nama projek harus diisi']);
        exit;
    }
    
    if ($budget <= 0) {
        echo json_encode(['success' => false, 'message' => 'Budget harus lebih dari 0']);
        exit;
    }

    // Generate project code
    $project_code = 'PRJ-' . date('ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

    // Check what columns exist
    $stmt = $db->query("DESCRIBE projects");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Build insert based on available columns
    $sql = "INSERT INTO projects (";
    $values = [];
    $params = [];

    // name column
    if (in_array('name', $columns)) {
        $sql_cols[] = 'name';
        $params[] = $name;
    } elseif (in_array('project_name', $columns)) {
        $sql_cols[] = 'project_name';
        $params[] = $name;
    }

    // code column
    if (in_array('code', $columns)) {
        $sql_cols[] = 'code';
        $params[] = $project_code;
    } elseif (in_array('project_code', $columns)) {
        $sql_cols[] = 'project_code';
        $params[] = $project_code;
    }

    // budget column
    if (in_array('budget', $columns)) {
        $sql_cols[] = 'budget';
        $params[] = $budget;
    } elseif (in_array('budget_idr', $columns)) {
        $sql_cols[] = 'budget_idr';
        $params[] = $budget;
    }

    // description
    if (in_array('description', $columns)) {
        $sql_cols[] = 'description';
        $params[] = $description;
    }

    // start_date
    if (in_array('start_date', $columns) && $start_date) {
        $sql_cols[] = 'start_date';
        $params[] = $start_date;
    }

    // end_date
    if (in_array('end_date', $columns) && $end_date) {
        $sql_cols[] = 'end_date';
        $params[] = $end_date;
    }

    // status
    if (in_array('status', $columns)) {
        $sql_cols[] = 'status';
        $params[] = $status;
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

    $sql = "INSERT INTO projects (" . implode(', ', $sql_cols) . ") VALUES (" . implode(', ', array_fill(0, count($params), '?')) . ")";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $project_id = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Projek berhasil dibuat',
        'project_id' => $project_id,
        'project_code' => $project_code
    ]);

} catch (PDOException $e) {
    error_log('Project create error: ' . $e->getMessage());
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
