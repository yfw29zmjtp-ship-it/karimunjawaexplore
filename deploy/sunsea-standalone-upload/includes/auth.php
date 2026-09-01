<?php

/**
 * Authentication Class
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);
require_once __DIR__ . '/../config/database.php';

class Auth
{
    private $db;
    private static $usersColumnCache = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function usersTableHasColumn($columnName)
    {
        if (array_key_exists($columnName, self::$usersColumnCache)) {
            return self::$usersColumnCache[$columnName];
        }

        try {
            $result = $this->db->fetchOne(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ? LIMIT 1",
                [$columnName]
            );
            self::$usersColumnCache[$columnName] = (bool) $result;
        } catch (Exception $e) {
            self::$usersColumnCache[$columnName] = false;
        }

        return self::$usersColumnCache[$columnName];
    }

    private function updateUserActivity($userId, $pdo = null)
    {
        $hasLastLogin = $this->usersTableHasColumn('last_login');
        $hasUpdatedAt = $this->usersTableHasColumn('updated_at');

        if ($hasLastLogin || $hasUpdatedAt) {
            try {
                if ($pdo instanceof PDO) {
                    $fields = [];
                    if ($hasLastLogin) {
                        $fields[] = 'last_login = NOW()';
                    }
                    if ($hasUpdatedAt) {
                        $fields[] = 'updated_at = NOW()';
                    }

                    if (!empty($fields)) {
                        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
                        $stmt->execute([$userId]);
                    }
                    return;
                }

                $fields = [];
                if ($hasLastLogin) {
                    $fields[] = 'last_login = NOW()';
                }
                if ($hasUpdatedAt) {
                    $fields[] = 'updated_at = NOW()';
                }

                if (!empty($fields)) {
                    $this->db->query('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', [$userId]);
                }
            } catch (Exception $e) {
            }
        }
    }

    public function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }
    }

    public function login($username, $password)
    {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $passwordMatch = false;
            if ($user) {
                if (password_verify($password, $user['password'])) {
                    $passwordMatch = true;
                } else if ($user['password'] === md5($password)) {
                    $passwordMatch = true;
                }
            }

            if ($user && $passwordMatch) {
                $this->startSession();

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];

                // Handle different database structures
                // Business DB has 'role' column, Master DB has 'role_id'
                if (isset($user['role'])) {
                    $_SESSION['role'] = $user['role'];
                } else {
                    // Master database - need to get role from role_id
                    try {
                        $roleStmt = $pdo->prepare("SELECT role_code FROM roles WHERE id = ?");
                        $roleStmt->execute([$user['role_id'] ?? 1]);
                        $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);
                        $_SESSION['role'] = $roleData['role_code'] ?? 'staff';
                    } catch (Exception $e) {
                        $_SESSION['role'] = 'staff';
                    }
                }

                $_SESSION['business_access'] = $user['business_access'] ?? 'all';
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();

                try {
                    $stmt = $pdo->prepare("SELECT theme, language FROM user_preferences WHERE user_id = ?");
                    $stmt->execute([$user['id']]);
                    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($preferences) {
                        $_SESSION['user_theme'] = $preferences['theme'];
                        $_SESSION['user_language'] = $preferences['language'];
                    } else {
                        $_SESSION['user_theme'] = 'dark';
                        $_SESSION['user_language'] = 'id';
                    }
                } catch (PDOException $e) {
                    $_SESSION['user_theme'] = 'dark';
                    $_SESSION['user_language'] = 'id';
                }

                $this->updateUserActivity($user['id'], $pdo);

                // Log to audit_logs
                try {
                    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, ip_address, created_at) VALUES (?, 'login', 'users', ?, ?, NOW())");
                    $stmt->execute([$user['id'], $user['id'], $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
                } catch (Exception $e) {
                }

                return true;
            }

            return false;
        } catch (PDOException $e) {
            error_log("Auth login error: " . $e->getMessage());
            return false;
        }
    }

    public function logout()
    {
        $this->startSession();
        session_unset();
        session_destroy();
        return true;
    }

    public function isLoggedIn()
    {
        $this->startSession();
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    public function getCurrentUser()
    {
        $this->startSession();
        if ($this->isLoggedIn()) {
            return [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'full_name' => $_SESSION['full_name'],
                'role' => $_SESSION['role']
            ];
        }
        return null;
    }

    public function hasRole($role)
    {
        $this->startSession();
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }

    public function requireLogin()
    {
        if (!$this->isLoggedIn()) {
            $currentHost = strtolower(preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? ''));
            // Redirect to PWF login for pwfoffice.com and any subdomain
            if ($currentHost === 'pwfoffice.com' || str_ends_with($currentHost, '.pwfoffice.com')) {
                header('Location: ' . BASE_URL . '/pwf-login.php');
            } else {
                header('Location: ' . BASE_URL . '/login.php');
            }
            exit;
        }

        // Verify user still exists and is active in master database (check every 60 seconds)
        $lastUserCheck = $_SESSION['last_user_check'] ?? 0;
        if (time() - $lastUserCheck > 60) {
            try {
                $masterPdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $checkStmt = $masterPdo->prepare("SELECT id, is_active FROM users WHERE id = ? LIMIT 1");
                $checkStmt->execute([$_SESSION['user_id']]);
                $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingUser || !$existingUser['is_active']) {
                    // User deleted or deactivated — force logout
                    session_unset();
                    session_destroy();
                    header('Location: ' . BASE_URL . '/login.php?error=account_removed');
                    exit;
                }
                $_SESSION['last_user_check'] = time();
            } catch (Throwable $e) {
                // DB error — don't block, just skip check
            }
        }

        // Update last activity (every 5 minutes to reduce DB load)
        $lastUpdate = $_SESSION['last_activity_update'] ?? 0;
        if (time() - $lastUpdate > 300) { // 5 minutes
            $this->updateUserActivity($_SESSION['user_id']);
            $_SESSION['last_activity_update'] = time();
        }

        if (!isset($_SESSION['user_theme']) || !isset($_SESSION['user_language'])) {
            try {
                $preferences = $this->db->fetchOne(
                    "SELECT theme, language FROM user_preferences WHERE user_id = ?",
                    [$_SESSION['user_id']]
                );

                if ($preferences) {
                    $_SESSION['user_theme'] = $preferences['theme'];
                    $_SESSION['user_language'] = $preferences['language'];
                } else {
                    $_SESSION['user_theme'] = 'dark';
                    $_SESSION['user_language'] = 'id';
                }
            } catch (Exception $e) {
                $_SESSION['user_theme'] = 'dark';
                $_SESSION['user_language'] = 'id';
            }
        }
    }

    public function requireRole($role)
    {
        $this->requireLogin();
        if (!$this->hasRole($role)) {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }

    public function hasPermission($module)
    {
        // Check if user is logged in
        if (!$this->isLoggedIn()) {
            return false;
        }

        $userRole = $_SESSION['role'] ?? 'staff';

        // Get username from session
        $username = $_SESSION['username'] ?? null;
        if (!$username) {
            return false;
        }

        try {
            // Connect to master database
            $masterPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Get user ID from master
            $userStmt = $masterPdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $userStmt->execute([$username]);
            $masterUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$masterUser) {
                // User not in master database, fallback to role-based
                if ($userRole === 'developer') return true;
                return $this->hasPermissionFallback($module);
            }

            $masterId = $masterUser['id'];

            // Get current business ID from session
            $activeBusinessId = $_SESSION['active_business_id'] ?? null;

            // If no active business set, fallback (shouldn't happen after login)
            if (!$activeBusinessId) {
                if ($userRole === 'developer') return true;
                error_log("⚠️ FALLBACK: No active_business_id in session for user {$username} (ID {$masterId})");
                error_log("Session active_business_id = " . var_export($_SESSION['active_business_id'] ?? 'MISSING', true));
                return $this->hasPermissionFallback($module);
            }

            // Resolve numeric business ID dynamically for any business slug/code
            $businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
            if ($businessId <= 0 && function_exists('getNumericBusinessId')) {
                $businessId = (int)getNumericBusinessId($activeBusinessId);
                if ($businessId > 0) {
                    $_SESSION['business_id'] = $businessId;
                }
            }

            if ($businessId <= 0) {
                // Business not found, fallback
                if ($userRole === 'developer') return true;
                error_log("Warning: Business not found for active_business_id {$activeBusinessId}");
                return $this->hasPermissionFallback($module);
            }

            // For developers: check if permissions have been configured for this business
            // If no entries exist at all, grant full access (backward compatible)
            if ($userRole === 'developer') {
                $countStmt = $masterPdo->prepare("
                    SELECT COUNT(*) as total
                    FROM user_menu_permissions
                    WHERE user_id = ? AND business_id = ?
                ");
                $countStmt->execute([$masterId, $businessId]);
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);

                if (!$countResult || (int)$countResult['total'] === 0) {
                    // No permissions configured yet for this developer+business, grant full access
                    return true;
                }
            }

            // Check permission in master database
            // Query directly using menu_code (no JOIN needed)
            $permStmt = $masterPdo->prepare("
                SELECT can_view
                FROM user_menu_permissions
                WHERE user_id = ? 
                  AND business_id = ? 
                  AND menu_code = ?
                  AND can_view = 1
                LIMIT 1
            ");
            $permStmt->execute([$masterId, $businessId, $module]);
            $permission = $permStmt->fetch(PDO::FETCH_ASSOC);

            // If found and can_view = 1, return true
            if ($permission) {
                return true;
            }

            // If not found, return false (no fallback)
            return false;
        } catch (Throwable $e) {
            // Log error for debugging - IMPORTANT FOR TROUBLESHOOTING
            error_log("⚠️ Permission check FAILED for user_id=" . ($_SESSION['user_id'] ?? 'none') . ", module=" . $module . ": " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            // Fallback to role-based on error
            return $this->hasPermissionFallback($module);
        }
    }

    /**
     * Check granular permission (can_edit, can_delete, can_create) for the current user/business.
     * Developer role respects configured permissions per business (falls back to allow-all if unconfigured).
     */
    private function checkGranularPerm(string $module, string $column): bool
    {
        if (!$this->isLoggedIn()) return false;
        $userRole = $_SESSION['role'] ?? 'staff';
        // Admin (system-wide) and Owner (business owner) always have full access -
        // granular per-menu permissions only restrict staff/manager/accountant roles.
        if (in_array($userRole, ['admin', 'owner'])) return true;

        $username = $_SESSION['username'] ?? null;
        if (!$username) return false;

        try {
            $masterPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $userRow = $masterPdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $userRow->execute([$username]);
            $masterUser = $userRow->fetch(PDO::FETCH_ASSOC);
            if (!$masterUser) return ($userRole === 'developer');

            $activeBusinessId = $_SESSION['active_business_id'] ?? null;
            if (!$activeBusinessId) return ($userRole === 'developer');

            $businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
            if ($businessId <= 0 && function_exists('getNumericBusinessId')) {
                $businessId = (int)getNumericBusinessId($activeBusinessId);
                if ($businessId > 0) {
                    $_SESSION['business_id'] = $businessId;
                }
            }
            if ($businessId <= 0) return ($userRole === 'developer');

            $business = ['id' => $businessId];

            // For developers: if no permissions configured for this business, allow all
            if ($userRole === 'developer') {
                $countStmt = $masterPdo->prepare("SELECT COUNT(*) as total FROM user_menu_permissions WHERE user_id = ? AND business_id = ?");
                $countStmt->execute([$masterUser['id'], $business['id']]);
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                if (!$countResult || (int)$countResult['total'] === 0) {
                    return true;
                }
            }

            $sql = "SELECT {$column} FROM user_menu_permissions WHERE user_id=? AND business_id=? AND menu_code=? LIMIT 1";
            $permRow = $masterPdo->prepare($sql);
            $permRow->execute([$masterUser['id'], $business['id'], $module]);
            $perm = $permRow->fetch(PDO::FETCH_ASSOC);
            return $perm && (int)$perm[$column] === 1;
        } catch (Throwable $e) {
            error_log("⚠️ Granular perm check failed: " . $e->getMessage());
            // On error: fallback allow for developer/admin/manager/owner, deny for staff
            return in_array($userRole, ['developer', 'owner', 'manager']);
        }
    }

    public function canEdit(string $module): bool
    {
        return $this->checkGranularPerm($module, 'can_edit');
    }
    public function canDelete(string $module): bool
    {
        return $this->checkGranularPerm($module, 'can_delete');
    }
    public function canCreate(string $module): bool
    {
        return $this->checkGranularPerm($module, 'can_create');
    }

    /**
     * Fallback permission check based on role (for backward compatibility)
     */
    private function hasPermissionFallback($module)
    {
        $userRole = $_SESSION['role'] ?? 'staff';

        // Try old user_permissions table in business database
        try {
            $user_id = $_SESSION['user_id'] ?? null;
            if ($user_id) {
                $conn = $this->db->getConnection();
                $query = "SELECT * FROM user_permissions WHERE user_id = ? AND permission = ? LIMIT 1";
                $stmt = $conn->prepare($query);
                $stmt->execute([$user_id, $module]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            // Table might not exist, continue to role-based
        }

        // Final fallback: role-based permissions
        $rolePermissions = [
            'admin' => ['dashboard', 'cashbook', 'divisions', 'frontdesk', 'sales_invoice', 'procurement', 'bills', 'reports', 'settings', 'investor', 'project', 'payroll', 'finance', 'owner', 'database', 'cqc-projects'],
            'manager' => ['dashboard', 'cashbook', 'divisions', 'frontdesk', 'sales_invoice', 'procurement', 'bills', 'reports', 'settings', 'investor', 'project', 'payroll', 'finance'],
            'accountant' => ['dashboard', 'cashbook', 'reports', 'procurement', 'bills', 'investor', 'project', 'payroll', 'finance'],
            'staff' => ['dashboard', 'cashbook', 'investor', 'project']
        ];

        $permissions = $rolePermissions[$userRole] ?? ['dashboard'];

        // Log when using fallback
        error_log("🔴 USING FALLBACK: user_id=" . ($_SESSION['user_id'] ?? 'none') . ", role=" . $userRole . ", module=" . $module . ", has_perm=" . (in_array($module, $permissions) ? "YES" : "NO"));

        return in_array($module, $permissions);
    }
}
