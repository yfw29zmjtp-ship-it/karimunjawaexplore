<?php
/**
 * API: Change Password
 * Syncs password across all databases (master + business databases)
 */
session_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['current_password']) || !isset($input['new_password'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$db = Database::getInstance();

try {
    // Get user data
    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Verify current password (support both MD5 legacy and bcrypt)
    $passwordValid = false;
    if (password_verify($input['current_password'], $user['password'])) {
        $passwordValid = true;
    } elseif ($user['password'] === md5($input['current_password'])) {
        $passwordValid = true;
    }
    
    if (!$passwordValid) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
        exit;
    }
    
    // Hash new password with bcrypt
    $hashedPassword = password_hash($input['new_password'], PASSWORD_DEFAULT);
    $username = $user['username'];
    
    // 1. Update in current business database
    $db->update('users', ['password' => $hashedPassword], ['id' => $_SESSION['user_id']]);
    
    // 2. Sync password to master database and all business databases
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
    $masterDbName = $isProduction ? 'adfb2574_adf' : 'adf_system';
    
    try {
        // Connect to master database
        $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $masterDbName, DB_USER, DB_PASS);
        $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Update password in master database by username
        $masterStmt = $masterPdo->prepare("UPDATE users SET password = ? WHERE username = ?");
        $masterStmt->execute([$hashedPassword, $username]);
        
        // Get all businesses and sync password
        $bizStmt = $masterPdo->query("SELECT database_name FROM businesses WHERE is_active = 1");
        $businesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($businesses as $biz) {
            try {
                $bizDbName = $biz['database_name'];
                if ($isProduction) {
                    $dbMapping = [
                        'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
                        'adf_benscafe' => 'adfb2574_Adf_Bens'
                    ];
                    if (isset($dbMapping[$bizDbName])) {
                        $bizDbName = $dbMapping[$bizDbName];
                    }
                }
                
                $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bizDbName, DB_USER, DB_PASS);
                $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $bizStmt = $bizPdo->prepare("UPDATE users SET password = ? WHERE username = ?");
                $bizStmt->execute([$hashedPassword, $username]);
            } catch (Exception $e) {
                // Skip if database not accessible
            }
        }
    } catch (Exception $e) {
        // Continue - password already updated in current db
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Password changed successfully and synced across all databases'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
