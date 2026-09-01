<?php
/**
 * Developer Panel Authentication
 * Simple authentication for developer access
 */

class DevAuth {
    private $pdo;
    
    public function __construct() {
        // Use system database connection from parent config
        require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
        $this->pdo = $GLOBALS['pdo'] ?? null;
        
        if (!$this->pdo) {
            // Fallback: create new connection
            $this->pdo = new PDO(
                'mysql:host=localhost;dbname=adf_narayana_hotel;charset=utf8mb4',
                'root', '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['dev_user_id']) && isset($_SESSION['dev_username']);
    }
    
    /**
     * Require login - redirect to login page if not authenticated
     */
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }
    
    /**
     * Attempt login
     */
    public function login($username, $password) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                // Check if user has developer/admin role
                $roleStmt = $this->pdo->prepare("SELECT * FROM roles WHERE id = ?");
                $roleStmt->execute([$user['role_id']]);
                $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
                
                // Only allow admin or developer roles
                if ($role && in_array(strtolower($role['role_name']), ['admin', 'developer', 'super admin'])) {
                    $_SESSION['dev_user_id'] = $user['id'];
                    $_SESSION['dev_username'] = $user['username'];
                    $_SESSION['dev_full_name'] = $user['full_name'];
                    $_SESSION['dev_role_name'] = $role['role_name'];
                    return true;
                }
                return false; // Not authorized role
            }
            return false;
        } catch (Exception $e) {
            error_log("DevAuth login error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        session_destroy();
        header('Location: login.php');
        exit;
    }
    
    /**
     * Get current logged in user
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['dev_user_id'],
            'username' => $_SESSION['dev_username'],
            'full_name' => $_SESSION['dev_full_name'],
            'role_name' => $_SESSION['dev_role_name'] ?? 'Developer'
        ];
    }
    
    /**
     * Get PDO connection
     */
    public function getConnection() {
        return $this->pdo;
    }
}
