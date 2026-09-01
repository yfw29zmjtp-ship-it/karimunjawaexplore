<?php
/**
 * API: Hapus Projek Investor
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

    // Validate
    if ($project_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID projek tidak valid']);
        exit;
    }

    // Check if project exists
    // First, detect available columns
    try {
        $stmt = $db->query("DESCRIBE projects");
        $columnsInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_column($columnsInfo, 'Field');
    } catch (Exception $e) {
        $columns = ['id', 'name']; // fallback
    }
    
    // Build name query dynamically
    $name_col = 'COALESCE(';
    if (in_array('project_name', $columns)) $name_col .= 'project_name, ';
    if (in_array('name', $columns)) $name_col .= 'name, ';
    $name_col .= "'Project') as project_name";
    
    $stmt = $db->prepare("SELECT id, $name_col FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        echo json_encode(['success' => false, 'message' => 'Projek tidak ditemukan']);
        exit;
    }

    // Begin transaction
    $db->beginTransaction();

    try {
        // Delete project expenses first (foreign key dependency)
        $deleted_expenses = 0;
        try {
            $stmt = $db->prepare("DELETE FROM project_expenses WHERE project_id = ?");
            $stmt->execute([$project_id]);
            $deleted_expenses = $stmt->rowCount();
        } catch (Exception $e) {
            // project_expenses table might not exist yet, that's fine
        }

        // Delete project
        $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Tidak dapat menghapus projek');
        }

        // Commit transaction
        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => "Projek '{$project['project_name']}' berhasil dihapus",
            'deleted_expenses' => $deleted_expenses
        ]);

    } catch (Exception $e) {
        // Rollback transaction
        $db->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('Project delete error: ' . $e->getMessage());
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