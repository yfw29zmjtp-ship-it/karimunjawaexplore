<?php

/**
 * Business Helper Functions
 * Manage active business per user session
 */

/**
 * Derive slug from business_code (with known overrides for legacy businesses).
 */
function businessCodeToSlug($code)
{
    $knownSlugs = [
        'BENSCAFE' => 'bens-cafe',
        'NARAYANAHOTEL' => 'narayana-hotel',
        'CQC' => 'cqc',
        'DEMO' => 'demo',
        'SUNSEA' => 'sunsea'
    ];
    if (isset($knownSlugs[$code])) return $knownSlugs[$code];
    // Convert: EAT_MEET => eat-meet, EATMEET => eatmeet
    return strtolower(str_replace('_', '-', $code));
}

/**
 * Auto-generate missing config files from businesses table in DB.
 * Called automatically so any business registered in the DB gets a config file.
 */
function autoSyncBusinessConfigs()
{
    static $synced = false;
    if ($synced) return;
    $synced = true;

    $businessesPath = __DIR__ . '/../config/businesses/';
    if (!is_dir($businessesPath)) {
        @mkdir($businessesPath, 0755, true);
    }

    try {
        $masterDb = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : (defined('DB_NAME') ? DB_NAME : 'adf_system');
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $masterDb . ";charset=" . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Auto-add slug column if missing
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM businesses LIKE 'slug'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN slug VARCHAR(100) AFTER business_code");
            }
        } catch (Exception $e) {
        }

        $rows = $pdo->query("SELECT * FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

        $typeConfig = [
            'hotel'      => [
                'icon' => '🏨',
                'primary' => '#4338ca',
                'secondary' => '#1e1b4b',
                'extra_modules' => "'frontdesk', 'investor', 'project'"
            ],
            'restaurant' => ['icon' => '🍽️', 'primary' => '#dc2626', 'secondary' => '#7f1d1d', 'extra_modules' => ''],
            'cafe'       => ['icon' => '☕', 'primary' => '#92400e', 'secondary' => '#78350f', 'extra_modules' => ''],
            'retail'     => ['icon' => '🏪', 'primary' => '#0d9488', 'secondary' => '#134e4a', 'extra_modules' => ''],
            'manufacture' => ['icon' => '🏭', 'primary' => '#4f46e5', 'secondary' => '#312e81', 'extra_modules' => ''],
            'tourism'       => [
                'icon' => '🏝️',
                'primary' => '#0891b2',
                'secondary' => '#164e63',
                'extra_modules' => "'frontdesk', 'investor', 'project'"
            ],
            'travel_bureau' => [
                'icon' => '🌊',
                'primary' => '#0EA5E9',
                'secondary' => '#0C4A6E',
                'extra_modules' => "'sunsea'"
            ],
            'other'         => ['icon' => '🏢', 'primary' => '#059669', 'secondary' => '#065f46', 'extra_modules' => ''],
        ];

        foreach ($rows as $biz) {
            $code = $biz['business_code'];
            // Use slug column if available, otherwise derive with known overrides
            $slug = !empty($biz['slug']) ? $biz['slug'] : businessCodeToSlug($code);

            // Auto-populate slug in DB if empty
            if (empty($biz['slug'])) {
                try {
                    $pdo->prepare("UPDATE businesses SET slug = ? WHERE id = ?")->execute([$slug, $biz['id']]);
                } catch (Exception $e) {
                }
            }

            $configFile = $businessesPath . $slug . '.php';
            if (file_exists($configFile)) continue; // Already exists, skip

            $name = $biz['business_name'];
            $type = $biz['business_type'] ?? 'other';
            $dbName = $biz['database_name'];
            // For config file, store the LOCAL db name (adf_ prefix)
            $localDbName = $dbName;
            if (defined('DB_USER') && strpos($dbName, explode('_', DB_USER)[0] . '_') === 0) {
                // Convert hosting name back to local: adfb2574_eat_meet -> adf_eat_meet
                $localDbName = 'adf_' . substr($dbName, strlen(explode('_', DB_USER)[0] . '_'));
            }

            $tc = $typeConfig[$type] ?? $typeConfig['other'];
            $modules = "'cashbook', 'auth', 'settings', 'reports', 'divisions', 'procurement', 'sales', 'bills', 'payroll'";
            if (!empty($tc['extra_modules'])) {
                $modules .= ', ' . $tc['extra_modules'];
            }

            $content = "<?php\nreturn [\n    'business_id' => '" . addslashes($slug) . "',\n    'name' => '" . addslashes($name) . "',\n    'business_type' => '" . addslashes($type) . "',\n    'database' => '" . addslashes($localDbName) . "',\n    'logo' => '',\n    'enabled_modules' => [{$modules}],\n    'theme' => [\n        'color_primary' => '{$tc['primary']}',\n        'color_secondary' => '{$tc['secondary']}',\n        'icon' => '{$tc['icon']}'\n    ],\n    'cashbook_columns' => [],\n    'dashboard_widgets' => ['show_daily_sales' => true, 'show_orders' => true, 'show_revenue' => true]\n];\n";

            @file_put_contents($configFile, $content);
            error_log("autoSyncBusinessConfigs: Generated config for {$slug} at {$configFile}");
        }
    } catch (Exception $e) {
        error_log("autoSyncBusinessConfigs error: " . $e->getMessage());
    }
}

/**
 * Get list of all available businesses
 * @return array Array of business configurations
 */
function getAvailableBusinesses()
{
    // Auto-generate missing config files from DB
    autoSyncBusinessConfigs();

    $businessesPath = __DIR__ . '/../config/businesses/';
    $businesses = [];

    if (is_dir($businessesPath)) {
        $files = scandir($businessesPath);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $businessId = pathinfo($file, PATHINFO_FILENAME);
                $config = require $businessesPath . $file;
                // Add ID to config
                $config['id'] = $businessId;
                $businesses[$businessId] = $config;
            }
        }
    }

    // Deduplicate by database name (keep config with custom data over auto-generated)
    $seen = [];
    foreach ($businesses as $bizId => $config) {
        $db = $config['database'] ?? '';
        if (isset($seen[$db])) {
            $existingId = $seen[$db];
            // Keep the one with a customized icon (not default auto-generated)
            $defaultIcons = ['🏨', '🍽️', '☕', '🏪', '🏭', '🏝️', '🏢'];
            $existingIcon = $businesses[$existingId]['theme']['icon'] ?? '';
            $currentIcon = $config['theme']['icon'] ?? '';
            if (in_array($existingIcon, $defaultIcons) && !in_array($currentIcon, $defaultIcons)) {
                // Current has custom icon, remove existing
                unset($businesses[$existingId]);
                $seen[$db] = $bizId;
            } else {
                // Keep existing, remove current
                unset($businesses[$bizId]);
            }
        } else {
            $seen[$db] = $bizId;
        }
    }

    // Sort by name
    uasort($businesses, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    return $businesses;
}

/**
 * Get active business ID from session
 * @return string Business ID
 */
function getActiveBusinessId()
{
    // Auto-sync config files from DB first
    autoSyncBusinessConfigs();

    // Session should already be started by config.php
    // Check if business is set in session AND config file exists
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['active_business_id'])) {
        $bizId = $_SESSION['active_business_id'];
        // Validate: sanitize and check config file exists
        $bizId = preg_replace('/[^a-zA-Z0-9_-]/', '', $bizId);
        $bizFile = __DIR__ . '/../config/businesses/' . $bizId . '.php';
        if (file_exists($bizFile)) {
            return $bizId;
        }
        // Invalid session value - clear it so we fall through to default
        unset($_SESSION['active_business_id']);
        error_log("business_helper: Invalid active_business_id '{$bizId}' in session, resetting.");
    }

    // Default to narayana-hotel if available, otherwise first available business
    $businesses = getAvailableBusinesses();
    if (!empty($businesses)) {
        $defaultBusinessId = isset($businesses['narayana-hotel']) ? 'narayana-hotel' : array_key_first($businesses);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['active_business_id'] = $defaultBusinessId;
        }
        return $defaultBusinessId;
    }

    // Fallback
    return 'narayana-hotel';
}

/**
 * Get the preferred default business from a list of businesses
 * Prefers narayana-hotel, falls back to first available
 * @param array $businesses Associative array keyed by business slug
 * @return string Business slug
 */
function getPreferredDefaultBusiness($businesses)
{
    if (empty($businesses)) return 'narayana-hotel';
    if (isset($businesses['narayana-hotel'])) return 'narayana-hotel';
    return array_key_first($businesses);
}

/**
 * Set active business ID in session
 * @param string $businessCode Business code/slug to set as active (e.g., 'narayana-hotel')
 * @return bool Success
 */
function setActiveBusinessId($businessCode)
{
    // Session should already be started by config.php
    // Just set the value if session is active
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Auto-sync config files from DB (generates missing configs)
        autoSyncBusinessConfigs();

        // Validate business exists
        $businessFile = __DIR__ . '/../config/businesses/' . $businessCode . '.php';
        if (file_exists($businessFile)) {
            $_SESSION['active_business_id'] = $businessCode;

            // Also set numeric business_id from master database
            $numericId = getNumericBusinessId($businessCode);
            if ($numericId) {
                $_SESSION['business_id'] = $numericId;
            }

            return true;
        }
    }

    return false;
}

/**
 * Get numeric business ID from master database
 * Maps string business codes like 'narayana-hotel' to numeric IDs like 1
 * @param string $businessCode Business code/slug (e.g., 'narayana-hotel', 'bens-cafe')
 * @return int|null Numeric business ID or null if not found
 */
function getNumericBusinessId($businessCode)
{
    // Try slug column first (most reliable), then fallback to code lookup
    // $businessCode here is actually the slug (e.g., 'bens-cafe', 'eat-meet')
    $slugToLookup = $businessCode;

    // Known slug -> code overrides for legacy businesses
    $codeMap = [
        'narayana-hotel' => 'NARAYANAHOTEL',
        'bens-cafe' => 'BENSCAFE',
        'demo' => 'DEMO'
    ];

    $dbCode = isset($codeMap[$businessCode]) ? $codeMap[$businessCode] : strtoupper(str_replace('-', '_', $businessCode));

    try {
        $masterPdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=" . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Try by slug first (most reliable)
        try {
            $stmt = $masterPdo->prepare("SELECT id FROM businesses WHERE slug = ? LIMIT 1");
            $stmt->execute([$slugToLookup]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // slug column might not exist yet
            $row = null;
        }

        if (!$row) {
            // Fallback: try by business_code
            $stmt = $masterPdo->prepare("SELECT id FROM businesses WHERE business_code = ? LIMIT 1");
            $stmt->execute([$dbCode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        error_log("getNumericBusinessId error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get active business configuration
 * @return array Business configuration array
 */
function getActiveBusinessConfig()
{
    $businessId = getActiveBusinessId();
    $businessFile = __DIR__ . '/../config/businesses/' . $businessId . '.php';

    if (file_exists($businessFile)) {
        return require $businessFile;
    }

    // Return default/empty config if file not found
    return [
        'business_id' => $businessId,
        'name' => 'Unknown Business',
        'business_type' => 'general',
        'database' => defined('DB_NAME') ? DB_NAME : 'adf_system',
        'enabled_modules' => ['cashbook', 'auth', 'settings'],
        'theme' => [
            'color_primary' => '#4338ca',
            'color_secondary' => '#818cf8',
            'icon' => '🏢'
        ]
    ];
}

/**
 * Check if user has access to specific business
 * @param string $businessId Business ID to check
 * @param object $user User object (optional, uses current user if not provided)
 * @return bool True if user has access
 */
function userHasBusinessAccess($businessId, $user = null)
{
    // For now, all authenticated users can access all businesses
    // Later you can add business-specific permissions in users table
    return true;
}

/**
 * Get business name with icon
 * @param string $businessId Business ID (optional, uses active business if not provided)
 * @return string Formatted business name with icon
 */
function getBusinessDisplayName($businessId = null)
{
    if ($businessId === null) {
        $config = getActiveBusinessConfig();
    } else {
        $businessFile = __DIR__ . '/../config/businesses/' . $businessId . '.php';
        if (file_exists($businessFile)) {
            $config = require $businessFile;
        } else {
            return 'Unknown Business';
        }
    }

    return $config['theme']['icon'] . ' ' . $config['name'];
}

/**
 * Get business logo path
 * @return string|null Logo URL or null
 */
function getBusinessLogo()
{
    $config = getActiveBusinessConfig();
    $businessId = $config['business_id'];

    try {
        $db = Database::getInstance();

        // Priority 1: Business-specific logo from settings table (new format: company_logo_businessid)
        $businessLogo = $db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_key = :key",
            ['key' => 'company_logo_' . $businessId]
        );

        if ($businessLogo && $businessLogo['setting_value']) {
            $val = $businessLogo['setting_value'];
            // If it's a Cloudinary URL, return directly
            if (strpos($val, 'http') === 0) {
                return $val;
            }
            $logoPath = __DIR__ . '/../uploads/logos/' . $val;
            if (file_exists($logoPath)) {
                $timestamp = filemtime($logoPath);
                return BASE_URL . '/uploads/logos/' . $val . '?v=' . $timestamp;
            }
        }

        // Priority 2: Custom logo from config
        if (!empty($config['logo'])) {
            $val = $config['logo'];
            if (strpos($val, 'http') === 0) return $val;
            $logoPath = __DIR__ . '/../uploads/logos/' . $val;
            if (file_exists($logoPath)) {
                $timestamp = filemtime($logoPath);
                return BASE_URL . '/uploads/logos/' . $val . '?v=' . $timestamp;
            }
        }

        // Priority 3: Business-specific logo file (business-id_logo.png or .jpg)
        foreach (['png', 'jpg', 'jpeg', 'gif'] as $ext) {
            $defaultLogo = __DIR__ . '/../uploads/logos/' . $businessId . '_logo.' . $ext;
            if (file_exists($defaultLogo)) {
                $timestamp = filemtime($defaultLogo);
                return BASE_URL . '/uploads/logos/' . $businessId . '_logo.' . $ext . '?v=' . $timestamp;
            }
        }

        // Priority 4: Hotel logo from settings
        $hotelLogo = $db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_key = 'hotel_logo' LIMIT 1"
        );
        if ($hotelLogo && $hotelLogo['setting_value']) {
            $val = $hotelLogo['setting_value'];
            if (strpos($val, 'http') === 0) return $val;
            $logoFile = __DIR__ . '/../uploads/logos/' . $val;
            if (file_exists($logoFile)) {
                $timestamp = filemtime($logoFile);
                return BASE_URL . '/uploads/logos/' . $val . '?v=' . $timestamp;
            }
        }

        // Priority 5: Global company logo from settings (fallback)
        $globalLogo = $db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_key = 'company_logo' LIMIT 1"
        );
        if ($globalLogo && $globalLogo['setting_value']) {
            $val = $globalLogo['setting_value'];
            if (strpos($val, 'http') === 0) return $val;
            $settingsLogo = __DIR__ . '/../uploads/logos/' . $val;
            if (file_exists($settingsLogo)) {
                $timestamp = filemtime($settingsLogo);
                return BASE_URL . '/uploads/logos/' . $val . '?v=' . $timestamp;
            }
        }
    } catch (Exception $e) {
        // Silent fail
    }

    return null;
}

/**
 * Get business theme CSS variables
 * @return string CSS variables
 */
function getBusinessThemeCSS()
{
    $config = getActiveBusinessConfig();
    $theme = $config['theme'] ?? [];

    $primary = $theme['color_primary'] ?? '#4338ca';
    $secondary = $theme['color_secondary'] ?? '#3730a3';

    return ":root {
        --business-primary: {$primary};
        --business-secondary: {$secondary};
        --accent-primary: {$primary};
        --accent-secondary: {$secondary};
    }";
}

/**
 * Check if module is enabled for current business
 * @param string $module Module name
 * @return bool
 */
function isModuleEnabled($module)
{
    $config = getActiveBusinessConfig();
    $enabledModules = $config['enabled_modules'] ?? [];
    return in_array($module, $enabledModules);
}

/**
 * Get business-specific view file path
 * Falls back to default view if business-specific view doesn't exist
 * 
 * @param string $module Module name (e.g., 'cashbook', 'dashboard')
 * @param string $view View name (e.g., 'index', 'add', 'edit')
 * @return string|null View file path or null if not found
 */
function getBusinessView($module, $view)
{
    $config = getActiveBusinessConfig();
    $businessType = $config['business_type'];
    $businessId = $config['business_id'];

    // Priority 1: Business-specific view (by ID)
    $businessSpecificView = __DIR__ . "/../modules/{$module}/views/{$businessId}/{$view}.php";
    if (file_exists($businessSpecificView)) {
        return $businessSpecificView;
    }

    // Priority 2: Business type view (shared by type)
    $typeView = __DIR__ . "/../modules/{$module}/views/{$businessType}/{$view}.php";
    if (file_exists($typeView)) {
        return $typeView;
    }

    // Priority 3: Default view
    $defaultView = __DIR__ . "/../modules/{$module}/{$view}.php";
    if (file_exists($defaultView)) {
        return $defaultView;
    }

    return null;
}

/**
 * Get the numeric business ID for the currently active business.
 * Cached per-request so the DB is only queried once.
 * Also ensures cash_accounts exist for this business (auto-creates if missing).
 * @return int Numeric business ID (defaults to 1 if lookup fails)
 */
function getMasterBusinessId()
{
    static $cachedId = null;
    if ($cachedId !== null) return $cachedId;

    // Try session first (set by setActiveBusinessId)
    if (isset($_SESSION['business_id']) && $_SESSION['business_id'] > 0) {
        $cachedId = (int)$_SESSION['business_id'];
    } else {
        // Query master DB via existing helper
        $activeId = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'narayana-hotel';
        $id = getNumericBusinessId($activeId);
        $cachedId = $id ?: 1;
    }

    // Auto-ensure cash accounts exist (once per session, version-tracked)
    $sessionKey = '_cash_accounts_checked_v2_' . $cachedId;
    if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION[$sessionKey])) {
        ensureCashAccountsExist($cachedId);
        $_SESSION[$sessionKey] = true;
    }

    return $cachedId;
}

/**
 * Ensure cash_accounts exist for a given business in the master database.
 * Auto-creates Petty Cash, Bank, Kas Modal Owner if none exist.
 * Also auto-fixes missing columns in cash_accounts table.
 * @param int $businessId Numeric business ID
 */
function ensureCashAccountsExist($businessId)
{
    try {
        $masterPdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=" . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // First: ensure table has required columns
        $cols = $masterPdo->query("SHOW COLUMNS FROM cash_accounts")->fetchAll(PDO::FETCH_COLUMN);
        $addCols = [
            'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
            'description' => "TEXT NULL",
            'is_default_account' => "TINYINT(1) NOT NULL DEFAULT 0",
            'current_balance' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        foreach ($addCols as $col => $def) {
            if (!in_array($col, $cols)) {
                try {
                    $masterPdo->exec("ALTER TABLE cash_accounts ADD COLUMN `$col` $def");
                } catch (PDOException $e) {
                }
            }
        }

        // Check if this business has any cash accounts
        $stmt = $masterPdo->prepare("SELECT COUNT(*) FROM cash_accounts WHERE business_id = ?");
        $stmt->execute([$businessId]);
        $count = (int)$stmt->fetchColumn();

        if ($count === 0) {
            // Auto-create 3 default accounts
            // Build INSERT dynamically based on available columns
            $refreshedCols = $masterPdo->query("SHOW COLUMNS FROM cash_accounts")->fetchAll(PDO::FETCH_COLUMN);

            $defaults = [
                ['Petty Cash', 'cash', 'Uang cash dari tamu / operasional', 1],
                ['Bank', 'bank', 'Rekening bank utama bisnis', 0],
                ['Kas Modal Owner', 'owner_capital', 'Modal operasional dari owner', 0],
            ];

            foreach ($defaults as $acc) {
                $data = ['business_id' => $businessId, 'account_name' => $acc[0], 'account_type' => $acc[1]];
                if (in_array('description', $refreshedCols)) $data['description'] = $acc[2];
                if (in_array('is_default_account', $refreshedCols)) $data['is_default_account'] = $acc[3];
                if (in_array('current_balance', $refreshedCols)) $data['current_balance'] = 0;
                if (in_array('is_active', $refreshedCols)) $data['is_active'] = 1;

                $fields = implode(', ', array_keys($data));
                $placeholders = implode(', ', array_fill(0, count($data), '?'));

                $stmt = $masterPdo->prepare("INSERT INTO cash_accounts ($fields) VALUES ($placeholders)");
                $stmt->execute(array_values($data));
            }

            error_log("ensureCashAccountsExist: Auto-created 3 cash accounts for business_id=$businessId");
        }
    } catch (Throwable $e) {
        error_log("ensureCashAccountsExist error: " . $e->getMessage());
    }
}

/**
 * Get cashbook columns for current business
 * @return array Business-specific cashbook columns
 */
function getBusinessCashbookColumns()
{
    $config = getActiveBusinessConfig();
    return $config['cashbook_columns'] ?? [];
}

/**
 * Get business terminology
 * @param string $key Terminology key
 * @return string Translated term
 */
function getBusinessTerm($key)
{
    $config = getActiveBusinessConfig();
    $terminology = $config['terminology'] ?? [];

    return $terminology[$key] ?? ucfirst($key);
}
