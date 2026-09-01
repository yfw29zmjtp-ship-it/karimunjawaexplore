<?php
/**
 * API: Update Investor Data
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

    $investor_id = intval($_POST['investor_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Validate
    if ($investor_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID investor tidak valid']);
        exit;
    }

    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Nama investor harus diisi']);
        exit;
    }

    // Check what columns exist
    $stmt = $db->query("DESCRIBE investors");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Build update query based on available columns
    $update_parts = [];
    $params = [];

    // name column
    if (in_array('name', $columns)) {
        $update_parts[] = 'name = ?';
        $params[] = $name;
    } elseif (in_array('investor_name', $columns)) {
        $update_parts[] = 'investor_name = ?';
        $params[] = $name;
    }

    // phone
    if (in_array('phone', $columns)) {
        $update_parts[] = 'phone = ?';
        $params[] = $phone;
    } elseif (in_array('contact_phone', $columns)) {
        $update_parts[] = 'contact_phone = ?';
        $params[] = $phone;
    }

    // email
    if (in_array('email', $columns)) {
        $update_parts[] = 'email = ?';
        $params[] = $email;
    }

    // notes
    if (in_array('notes', $columns)) {
        $update_parts[] = 'notes = ?';
        $params[] = $notes;
    }

    // updated_by
    if (in_array('updated_by', $columns)) {
        $update_parts[] = 'updated_by = ?';
        $params[] = $_SESSION['user_id'] ?? 1;
    }

    // updated_at
    if (in_array('updated_at', $columns)) {
        $update_parts[] = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');
    }

    if (empty($update_parts)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada kolom untuk diupdate']);
        exit;
    }

    // Add investor_id to params for WHERE clause
    $params[] = $investor_id;

    $sql = "UPDATE investors SET " . implode(', ', $update_parts) . " WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Investor berhasil diperbarui',
        'investor_id' => $investor_id
    ]);

} catch (PDOException $e) {
    error_log('Investor update error: ' . $e->getMessage());
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
