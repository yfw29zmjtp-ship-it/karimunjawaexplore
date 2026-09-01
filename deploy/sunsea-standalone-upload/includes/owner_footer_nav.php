<?php
/**
 * Owner Footer Navigation Helper
 * Loads configurable footer menu items per user from master DB
 */

// All available footer menu items for owner dashboard
function getOwnerFooterMenuDefinitions() {
    return [
        'home' => [
            'label' => 'Home',
            'icon' => '&#127968;', // 🏠
            'url_key' => 'dashboard-2028.php',
            'always_show' => true // Home always visible
        ],
        'frontdesk' => [
            'label' => 'Frontdesk',
            'icon' => '&#128197;', // 📅
            'url_key' => 'frontdesk-mobile.php',
            'requires_module' => 'frontdesk'
        ],
        'projects' => [
            'label' => 'Projects',
            'icon' => '&#128200;', // 📈
            'url_key' => 'investor-monitor.php',
            'requires_module' => ['project', 'investor']
        ],
        'cashbook' => [
            'label' => 'Cashbook',
            'icon' => '&#128176;', // 💰
            'url_key' => 'kasbook-daily-simple.php',
            'requires_module' => 'cashbook'
        ],
        'capital' => [
            'label' => 'Capital',
            'icon' => '&#127974;', // 🏦
            'url_key' => 'owner-capital-monitor.php',
            'requires_module' => 'investor'
        ],
        'health' => [
            'label' => 'Health',
            'icon' => '&#128202;', // 📊
            'url_key' => 'health-report.php',
            'requires_module' => null
        ],
        'logout' => [
            'label' => 'Logout',
            'icon' => '&#128682;', // 🚪
            'url_key' => 'logout',
            'always_show' => true
        ]
    ];
}

/**
 * Get footer menu items configured for a specific user
 * @param int|null $userId If null, uses session user_id
 * @return array Array of menu keys ['home', 'frontdesk', 'projects', 'logout']
 */
function getUserFooterMenus($userId = null) {
    if ($userId === null) {
        $userId = $_SESSION['user_id'] ?? null;
    }
    if (!$userId) {
        return getDefaultFooterMenus();
    }
    
    try {
        $masterPdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Ensure table exists
        $masterPdo->exec("CREATE TABLE IF NOT EXISTS owner_footer_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            menu_key VARCHAR(50) NOT NULL,
            menu_order INT DEFAULT 0,
            is_enabled TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_menu (user_id, menu_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $stmt = $masterPdo->prepare("SELECT menu_key FROM owner_footer_config WHERE user_id = ? AND is_enabled = 1 ORDER BY menu_order, id");
        $stmt->execute([$userId]);
        $menus = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($menus)) {
            return getDefaultFooterMenus();
        }
        
        return $menus;
        
    } catch (Exception $e) {
        error_log('getUserFooterMenus error: ' . $e->getMessage());
        return getDefaultFooterMenus();
    }
}

/**
 * Default footer menu items (fallback when not configured)
 */
function getDefaultFooterMenus() {
    return ['home', 'frontdesk', 'projects', 'logout'];
}

/**
 * Render the footer nav HTML
 * @param string $activePage Current page key ('home', 'frontdesk', 'projects', etc)
 * @param string $basePath Base URL path
 * @param array $enabledModules List of enabled modules for current business
 */
function renderOwnerFooterNav($activePage = 'home', $basePath = '', $enabledModules = []) {
    $allMenus = getOwnerFooterMenuDefinitions();
    $userMenuKeys = getUserFooterMenus();
    
    echo '<nav class="nav-bottom">';
    
    foreach ($userMenuKeys as $key) {
        if (!isset($allMenus[$key])) continue;
        $menu = $allMenus[$key];
        
        // Check module requirements (skip if required module not enabled)
        if (isset($menu['requires_module']) && !isset($menu['always_show'])) {
            $reqModules = (array)$menu['requires_module'];
            $hasModule = false;
            foreach ($reqModules as $mod) {
                if (in_array($mod, $enabledModules)) {
                    $hasModule = true;
                    break;
                }
            }
            if (!$hasModule) continue;
        }
        
        $isActive = ($key === $activePage) ? ' active' : '';
        
        if ($key === 'logout') {
            $url = $basePath . '/logout.php';
        } else {
            $url = $basePath . '/modules/owner/' . $menu['url_key'];
        }
        
        echo '<a href="' . htmlspecialchars($url) . '" class="nav-item' . $isActive . '">';
        echo '<span class="nav-icon" style="font-family: \'Segoe UI Emoji\', \'Apple Color Emoji\', sans-serif;">' . $menu['icon'] . '</span>';
        echo '<span>' . htmlspecialchars($menu['label']) . '</span>';
        echo '</a>';
    }
    
    echo '</nav>';
}
