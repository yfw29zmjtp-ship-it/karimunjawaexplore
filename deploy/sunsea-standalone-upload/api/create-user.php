<?php
/**
 * API: Create User - Using Direct PDO
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/businesses.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['username']) || !isset($input['password']) || !isset($input['full_name']) || !isset($input['role'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // Create PDO connection
    $pdo = new PDO('mysql:host=localhost;dbname=narayana', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if username exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$input['username']]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit;
    }
    
    // Prepare business access
    $businessAccess = !empty($input['business_ids']) ? json_encode(array_map('intval', $input['business_ids'])) : json_encode([]);
    
    // Get email if provided
    $email = isset($input['email']) ? $input['email'] : null;
    
    // Insert to main database (narayana)
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, password, full_name, email, role, business_access, is_active, created_at, updated_at) 
         VALUES (?, MD5(?), ?, ?, ?, ?, 1, NOW(), NOW())"
    );
    $stmt->execute([
        $input['username'], 
        $input['password'], 
        $input['full_name'], 
        $email,
        $input['role'], 
        $businessAccess
    ]);
    
    $userId = $pdo->lastInsertId();
    
    // Insert to all business databases
    $syncedDatabases = [];
    foreach ($BUSINESSES as $business) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO {$business['database']}.users (id, username, password, full_name, email, role, business_access, is_active, created_at, updated_at) 
                 VALUES (?, ?, MD5(?), ?, ?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE 
                    password = VALUES(password),
                    full_name = VALUES(full_name),
                    email = VALUES(email),
                    role = VALUES(role),
                    business_access = VALUES(business_access),
                    updated_at = NOW()"
            );
            $stmt->execute([
                $userId,
                $input['username'], 
                $input['password'], 
                $input['full_name'],
                $email,
                $input['role'], 
                $businessAccess
            ]);
            $syncedDatabases[] = $business['name'];
        } catch (Exception $e) {
            // Log but continue if one database fails
            error_log("Failed to sync to {$business['database']}: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'synced_to' => $syncedDatabases,
        'message' => 'User created successfully and synced to all business databases'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
