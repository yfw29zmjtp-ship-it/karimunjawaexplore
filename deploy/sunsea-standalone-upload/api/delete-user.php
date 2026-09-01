<?php
/**
 * API: Delete User - Using Direct PDO
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/businesses.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing user_id']);
    exit;
}

try {
    // Create PDO connection
    $pdo = new PDO('mysql:host=localhost;dbname=narayana', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Delete from main database
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$input['user_id']]);
    
    // Delete from all business databases
    $deletedFrom = [];
    foreach ($BUSINESSES as $business) {
        try {
            $stmt = $pdo->prepare("DELETE FROM {$business['database']}.users WHERE id = ?");
            $stmt->execute([$input['user_id']]);
            $deletedFrom[] = $business['name'];
        } catch (Exception $e) {
            error_log("Failed to delete from {$business['database']}: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true, 
        'deleted_from' => $deletedFrom,
        'message' => 'User deleted from all databases'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
