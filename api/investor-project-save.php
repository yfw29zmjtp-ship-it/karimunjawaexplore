<?php
/**
 * API: Simpan Projek Investor
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

    $project_name = trim($_POST['project_name'] ?? '');
    $project_code = trim($_POST['project_code'] ?? '') ?: null;
    $budget_idr = floatval($_POST['budget_idr'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status_input = $_POST['status'] ?? 'ongoing';
    
    // Map status values to database-compatible values
    $status_map = [
        'planning' => 'planning',
        'ongoing' => 'ongoing', 
        'on_hold' => 'on_hold',
        'completed' => 'completed',
        'cancelled' => 'cancelled'
    ];
    
    // Try common variations if direct mapping fails
    $status = $status_map[$status_input] ?? 'ongoing';
    
    // Debug: Log what we're trying to insert
    error_log("Inserting project with status: '$status' (original: '$status_input')");

    // Validate
    if (empty($project_name)) {
        echo json_encode(['success' => false, 'message' => 'Nama projek harus diisi']);
        exit;
    }
    
    if ($budget_idr <= 0) {
        echo json_encode(['success' => false, 'message' => 'Budget harus lebih dari 0']);
        exit;
    }

    // Check if projects table exists, if not create it
    try {
        $db->query("SELECT 1 FROM projects LIMIT 1");
        $table_exists = true;
    } catch (Exception $e) {
        $table_exists = false;
    }

    if (!$table_exists) {
        // Create projects table with flexible column names
        $db->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_name VARCHAR(150) NOT NULL,
                project_code VARCHAR(50),
                description TEXT,
                budget_idr DECIMAL(15,2) DEFAULT 0,
                status ENUM('planning', 'ongoing', 'on_hold', 'completed', 'cancelled') DEFAULT 'planning',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_by INT,
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Check if project_expenses table exists
    try {
        $db->query("SELECT 1 FROM project_expenses LIMIT 1");
    } catch (Exception $e) {
        // Create project_expenses table
        $db->exec("
            CREATE TABLE IF NOT EXISTS project_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                category_id INT,
                amount DECIMAL(15,2) NOT NULL,
                description TEXT,
                expense_date DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by INT,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                INDEX idx_project (project_id),
                INDEX idx_date (expense_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // Get actual column names to use flexible naming
    try {
        $stmt = $db->query("DESCRIBE projects");
        $columnsInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_column($columnsInfo, 'Field');
    } catch (Exception $e) {
        // Fallback: assume standard columns
        $columns = ['id', 'project_name', 'project_code', 'description', 'budget_idr', 'status', 'created_at', 'created_by'];
    }
    
    // Build INSERT statement dynamically based on available columns
    $insert_cols = [];
    $insert_vals = [];
    $params = [];
    
    // Add required columns and their values
    // Handle both 'name' and 'project_name' columns
    if (in_array('name', $columns)) {
        $insert_cols[] = 'name';
        $insert_vals[] = '?';
        $params[] = $project_name;
    }
    
    if (in_array('project_name', $columns)) {
        $insert_cols[] = 'project_name';
        $insert_vals[] = '?';
        $params[] = $project_name;
    }
    
    if (in_array('project_code', $columns) && !empty($project_code)) {
        $insert_cols[] = 'project_code';
        $insert_vals[] = '?';
        $params[] = $project_code;
    }
    
    if (in_array('description', $columns)) {
        $insert_cols[] = 'description';
        $insert_vals[] = '?';
        $params[] = $description;
    }
    
    if (in_array('budget_idr', $columns)) {
        $insert_cols[] = 'budget_idr';
        $insert_vals[] = '?';
        $params[] = $budget_idr;
    } elseif (in_array('budget', $columns)) {
        $insert_cols[] = 'budget';
        $insert_vals[] = '?';
        $params[] = $budget_idr;
    }
    
    if (in_array('status', $columns)) {
        // Try to determine valid enum values first
        try {
            $stmt = $db->query("SHOW COLUMNS FROM projects LIKE 'status'");
            $col_info = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($col_info && strpos($col_info['Type'], 'enum') !== false) {
                // Extract enum values from type
                preg_match("/enum\((.+)\)/", $col_info['Type'], $matches);
                if (isset($matches[1])) {
                    $enum_values = str_getcsv($matches[1], ',', "'");
                    // If our status is not in enum, use first available value
                    if (!in_array($status, $enum_values)) {
                        $status = $enum_values[0] ?? 'active';
                        error_log("Status '$status_input' not in enum, using '$status' instead");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Could not check status enum: " . $e->getMessage());
        }
        
        $insert_cols[] = 'status';
        $insert_vals[] = '?';
        $params[] = $status;
    }
    
    if (in_array('created_by', $columns)) {
        $insert_cols[] = 'created_by';
        $insert_vals[] = '?';
        $params[] = $_SESSION['user_id'] ?? 1;
    }
    
    // Don't manually add created_at - let database handle with DEFAULT CURRENT_TIMESTAMP

    // Build and execute INSERT
    if (empty($insert_cols)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada kolom yang sesuai di tabel projects']);
        exit;
    }
    
    $sql = "INSERT INTO projects (" . implode(', ', $insert_cols) . ") VALUES (" . implode(', ', $insert_vals) . ")";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $project_id = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Projek berhasil ditambahkan',
        'project_id' => $project_id
    ]);

} catch (PDOException $e) {
    error_log('Project save error: ' . $e->getMessage());
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
