<?php

/**
 * Business Access Control Middleware
 * Check if user has access to selected business
 */

if (!defined('APP_ACCESS')) {
    die('Direct access not permitted');
}

/**
 * Get business code to slug mapping from businesses table
 * @param PDO $pdo Master database connection
 * @return array ['BUSINESS_CODE' => 'business-slug', ...]
 */
function getBusinessCodeToSlugMap($pdo = null)
{
    // Static hardcoded fallback + dynamic from DB
    $map = [
        'BENSCAFE'      => 'bens-cafe',
        'NARAYANAHOTEL' => 'narayana-hotel',
        'CQC'           => 'cqc',
        'DEMO'          => 'demo',
        'SUNSEA'        => 'sunsea',
        'PWF'           => 'pwf-furniture',
        'PWFFURNITURE'  => 'pwf-furniture',
        'GUDANGNASITA'  => 'gudang-nasita',
        'GUDANG_NASITA' => 'gudang-nasita',
    ];

    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT id, business_code, slug, database_name FROM businesses WHERE is_active = 1");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Prefer the actual `slug` column (reliable, hosting-prefix independent).
                // Only fall back to guessing from database_name if slug is empty, and
                // only if not already covered by the hardcoded map above.
                if (!isset($map[$row['business_code']])) {
                    if (!empty($row['slug'])) {
                        $map[$row['business_code']] = $row['slug'];
                    } else {
                        $map[$row['business_code']] = strtolower(str_replace('_', '-', preg_replace('/^adf_/', '', $row['database_name'])));
                    }
                }
            }
        } catch (Exception $e) {
        }
    }

    return $map;
}

/**
 * Check if owner user has access to a specific business via user_business_assignment
 * @param int $userId Master user ID
 * @param string $businessSlug e.g. 'bens-cafe', 'narayana-hotel'
 * @return bool
 */
function checkOwnerBusinessAccess($userId, $businessSlug)
{
    try {
        $masterPdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Check if user_business_assignment table exists
        $tableCheck = $masterPdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'user_business_assignment'")->fetchColumn();
        if (!$tableCheck) {
            // Table doesn't exist yet — fall back to legacy access rules
            return true;
        }

        // Check if owner has ANY assignments at all
        $countStmt = $masterPdo->prepare("SELECT COUNT(*) FROM user_business_assignment WHERE user_id = ?");
        $countStmt->execute([$userId]);
        $totalAssignments = (int)$countStmt->fetchColumn();

        // If no assignment exists, fallback to explicit menu permissions for this business.
        // This keeps compatibility with setups where developer configured only menu permissions.
        if ($totalAssignments === 0) {
            $codeMap = getBusinessCodeToSlugMap($masterPdo);
            $slugToId = [];
            $bizStmt = $masterPdo->query("SELECT id, business_code FROM businesses WHERE is_active = 1");
            while ($row = $bizStmt->fetch(PDO::FETCH_ASSOC)) {
                $slug = $codeMap[$row['business_code']] ?? strtolower($row['business_code']);
                $slugToId[$slug] = $row['id'];
            }

            $targetBizId = $slugToId[$businessSlug] ?? null;
            if (!$targetBizId) {
                return false;
            }

            $permStmt = $masterPdo->prepare("SELECT COUNT(*) FROM user_menu_permissions WHERE user_id = ? AND business_id = ? AND can_view = 1");
            $permStmt->execute([$userId, $targetBizId]);
            return (int)$permStmt->fetchColumn() > 0;
        }

        // Owner has assignments — check if current business is in the list
        $codeMap = getBusinessCodeToSlugMap($masterPdo);
        $slugToId = [];

        // Build slug → business_id map
        $bizStmt = $masterPdo->query("SELECT id, business_code FROM businesses WHERE is_active = 1");
        while ($row = $bizStmt->fetch(PDO::FETCH_ASSOC)) {
            $slug = $codeMap[$row['business_code']] ?? strtolower($row['business_code']);
            $slugToId[$slug] = $row['id'];
        }

        $targetBizId = $slugToId[$businessSlug] ?? null;
        if (!$targetBizId) {
            return false;
        }

        $checkStmt = $masterPdo->prepare("SELECT COUNT(*) FROM user_business_assignment WHERE user_id = ? AND business_id = ?");
        $checkStmt->execute([$userId, $targetBizId]);
        return (int)$checkStmt->fetchColumn() > 0;
    } catch (Exception $e) {
        error_log('checkOwnerBusinessAccess error: ' . $e->getMessage());
        // On error, deny access rather than fail-open.
        return false;
    }
}

/**
 * Check if current user has access to active business
 * @return bool
 */
function checkBusinessAccess()
{
    global $auth;

    if (!isset($auth) || !$auth->isLoggedIn()) {
        return false;
    }

    $currentUser = $auth->getCurrentUser();
    $role = $currentUser['role'] ?? 'staff';

    // Developer has full access everywhere
    if ($role === 'developer') {
        return true;
    }

    // All non-developer users — check via user_business_assignment table
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        return checkOwnerBusinessAccess($userId, ACTIVE_BUSINESS_ID);
    }

    return false;
}

/**
 * Require business access or redirect
 */
function requireBusinessAccess()
{
    if (!checkBusinessAccess()) {
        $_SESSION['error'] = 'Anda tidak memiliki akses ke bisnis ini. Silakan hubungi administrator.';
        header('Location: ' . BASE_URL . '/logout.php');
        exit;
    }
}

/**
 * Get available businesses for current user from master database
 * @return array Filtered list of businesses user can access
 */
function getUserAvailableBusinesses()
{
    // Check login via session (works with or without Auth class)
    $isLoggedIn = false;
    if (isset($GLOBALS['auth']) && method_exists($GLOBALS['auth'], 'isLoggedIn')) {
        $isLoggedIn = $GLOBALS['auth']->isLoggedIn();
    } else {
        $isLoggedIn = !empty($_SESSION['logged_in']) || !empty($_SESSION['user_id']);
    }

    if (!$isLoggedIn) {
        return [];
    }

    $username = $_SESSION['username'] ?? null;
    if (!$username) {
        return [];
    }

    $userRole = $_SESSION['role'] ?? 'staff';

    // Developer role has access to all businesses
    if ($userRole === 'developer') {
        return getAvailableBusinesses();
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
            return [];
        }

        $masterId = $masterUser['id'];
        $codeToIdMap = getBusinessCodeToSlugMap($masterPdo);

        // All non-developer users — use user_business_assignment
        // Check if user has any assignments
        $countStmt = $masterPdo->prepare("SELECT COUNT(*) FROM user_business_assignment WHERE user_id = ?");
        $countStmt->execute([$masterId]);
        $totalAssignments = (int)$countStmt->fetchColumn();

        // If user has no assignments configured, do not expose all businesses.
        if ($totalAssignments === 0) {
            return [];
        }

        // Get assigned businesses - ordered by first assignment (not alphabetical)
        $bizStmt = $masterPdo->prepare("
            SELECT DISTINCT b.id, b.business_code, b.business_name, MIN(uba.id) as first_assignment_id
            FROM businesses b
            JOIN user_business_assignment uba ON b.id = uba.business_id
            WHERE uba.user_id = ? AND b.is_active = 1
            GROUP BY b.id, b.business_code, b.business_name
            ORDER BY first_assignment_id ASC
        ");
        $bizStmt->execute([$masterId]);
        $userBusinesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($userBusinesses)) {
            return [];
        }

        $allBusinesses = getAvailableBusinesses();
        $filtered = [];

        foreach ($userBusinesses as $biz) {
            $businessId = $codeToIdMap[$biz['business_code']] ?? strtolower($biz['business_code']);
            if (isset($allBusinesses[$businessId])) {
                $filtered[$businessId] = $allBusinesses[$businessId];
            }
        }

        return $filtered;
    } catch (Exception $e) {
        error_log('getUserAvailableBusinesses error: ' . $e->getMessage());
        return [];
    }
}
