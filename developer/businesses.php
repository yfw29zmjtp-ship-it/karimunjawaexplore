<?php

/**
 * Developer Panel - Business Management
 * Create, Edit businesses with automatic database creation
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/DatabaseManager.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Business Management';

$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;
$error = '';
$success = '';

// Detect hosting environment
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

// Auto-detect hosting DB prefix from DB_USER (e.g., 'adfb2574_adfsystem' -> 'adfb2574_')
$dbPrefix = '';
if ($isProduction && defined('DB_USER')) {
    $parts = explode('_', DB_USER);
    if (count($parts) >= 2) {
        $dbPrefix = $parts[0] . '_';
    }
}

// Auto-fix: Ensure businesses table has 'description' column
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM businesses LIKE 'description'");
    if ($colCheck->rowCount() === 0) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN description TEXT AFTER owner_id");
    }
} catch (Exception $e) {
}

// Auto-fix: Ensure businesses table has 'logo_url' column
try {
    $colCheck2 = $pdo->query("SHOW COLUMNS FROM businesses LIKE 'logo_url'");
    if ($colCheck2->rowCount() === 0) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN logo_url VARCHAR(255) AFTER description");
    }
} catch (Exception $e) {
}

// Business types
$businessTypes = ['hotel', 'restaurant', 'retail', 'manufacture', 'tourism', 'travel_bureau', 'other'];

// Ensure travel_bureau is supported in the businesses enum
try {
    $typeCol = $pdo->query("SHOW COLUMNS FROM businesses LIKE 'business_type'")->fetch(PDO::FETCH_ASSOC);
    $currentTypeDef = strtolower($typeCol['Type'] ?? '');
    if ($currentTypeDef && strpos($currentTypeDef, "'travel_bureau'") === false) {
        $pdo->exec("ALTER TABLE businesses MODIFY business_type ENUM('hotel', 'restaurant', 'retail', 'manufacture', 'tourism', 'travel_bureau', 'other') DEFAULT 'other'");
    }
} catch (Exception $e) {
}

// Get owners (users with owner or developer role)
$owners = [];
try {
    $owners = $pdo->query("
        SELECT u.* FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.role_code IN ('developer', 'owner') AND u.is_active = 1
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Get menus for assignment
$menus = [];
try {
    $menus = $pdo->query("SELECT * FROM menu_items WHERE is_active = 1 ORDER BY menu_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Which businesses already use each menu (for "Dipakai oleh" hints) +
// full per-business menu map (for the "Copy menu dari bisnis lain" helper)
$menuUsageMap = [];   // menu_id => [business_name, ...]
$businessMenuMap = []; // business_id => [menu_id, ...]
$businessListForCopy = [];
try {
    $usageRows = $pdo->query("
        SELECT bmc.menu_id, bmc.business_id, b.business_name
        FROM business_menu_config bmc
        JOIN businesses b ON b.id = bmc.business_id
        WHERE bmc.is_enabled = 1
        ORDER BY b.business_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $seenBiz = [];
    foreach ($usageRows as $row) {
        $menuUsageMap[$row['menu_id']][] = $row['business_name'];
        $businessMenuMap[$row['business_id']][] = (int)$row['menu_id'];
        if (!isset($seenBiz[$row['business_id']])) {
            $seenBiz[$row['business_id']] = true;
            $businessListForCopy[] = ['id' => (int)$row['business_id'], 'name' => $row['business_name']];
        }
    }
    usort($businessListForCopy, fn($a, $b) => strcasecmp($a['name'], $b['name']));
} catch (Exception $e) {
}

// cPanel URL for this hosting
$cpanelUrl = 'https://guangmao.iixcp.rumahweb.net:2083';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // =============================================
    // STEP 1: Register business (save to master DB only, NO database creation)
    // =============================================
    if ($formAction === 'register_business') {
        $businessCode = strtoupper(trim($_POST['business_code'] ?? ''));
        $businessName = trim($_POST['business_name'] ?? '');
        $businessType = $_POST['business_type'] ?? 'other';
        $ownerId = (int)($_POST['owner_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $selectedMenus = $_POST['menus'] ?? [];

        $dbName = 'adf_' . strtolower(preg_replace('/[^a-z0-9]/i', '_', $businessCode));
        $addonDomain = trim($_POST['addon_domain'] ?? '');

        if (empty($businessCode) || empty($businessName) || $ownerId === 0) {
            $error = 'Please fill all required fields';
        } else {
            try {
                $check = $pdo->prepare("SELECT COUNT(*) FROM businesses WHERE business_code = ? OR database_name = ?");
                $check->execute([$businessCode, $dbName]);
                if ($check->fetchColumn() > 0) {
                    $error = 'Business code or database already exists';
                } else {
                    // Resolve actual DB name for hosting
                    $actualDbName = $dbName;
                    if ($isProduction) {
                        $actualDbName = getDbName($dbName);
                    }

                    // Generate slug from business name (clean, URL-friendly)
                    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $businessName), '-'));
                    if (empty($slug)) {
                        $slug = strtolower(str_replace('_', '-', $businessCode));
                    }

                    // Ensure slug column exists
                    try {
                        $colCheck = $pdo->query("SHOW COLUMNS FROM businesses LIKE 'slug'")->fetchAll();
                        if (empty($colCheck)) {
                            $pdo->exec("ALTER TABLE businesses ADD COLUMN slug VARCHAR(100) AFTER business_code");
                        }
                    } catch (Exception $e) {
                    }

                    // Insert business record with slug
                    $stmt = $pdo->prepare("
                        INSERT INTO businesses (business_code, slug, business_name, business_type, database_name, owner_id, description, addon_domain, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
                    ");
                    $stmt->execute([$businessCode, $slug, $businessName, $businessType, $actualDbName, $ownerId, $description, $addonDomain ?: null]);
                    $businessId = $pdo->lastInsertId();

                    // Create cash accounts in master
                    try {
                        $pdo->prepare("INSERT INTO cash_accounts (business_id, account_name, account_type, current_balance, is_default_account, description, is_active) VALUES (?, 'Petty Cash', 'cash', 0, 1, 'Uang cash dari tamu / operasional', 1)")->execute([$businessId]);
                        $pdo->prepare("INSERT INTO cash_accounts (business_id, account_name, account_type, current_balance, is_default_account, description, is_active) VALUES (?, 'Bank', 'bank', 0, 0, 'Rekening bank utama bisnis', 1)")->execute([$businessId]);
                        $pdo->prepare("INSERT INTO cash_accounts (business_id, account_name, account_type, current_balance, is_default_account, description, is_active) VALUES (?, 'Kas Modal Owner', 'owner_capital', 0, 0, 'Modal operasional dari owner', 1)")->execute([$businessId]);
                    } catch (Exception $e) {
                    }

                    // Assign menus
                    try {
                        $menuStmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
                        foreach ($selectedMenus as $menuId) {
                            $menuStmt->execute([$businessId, $menuId]);
                        }
                    } catch (Exception $e) {
                    }

                    $auth->logAction('create_business', 'businesses', $businessId, null, ['name' => $businessName, 'database' => $actualDbName]);

                    // Auto-generate config file immediately (so system recognizes this business)
                    $autoSlug = $slug; // Use the slug we generated above
                    $autoConfigPath = dirname(dirname(__FILE__)) . '/config/businesses/' . $autoSlug . '.php';
                    if (!file_exists($autoConfigPath)) {
                        $typeConf = [
                            'hotel'         => ['icon' => '🏨', 'primary' => '#4338ca', 'secondary' => '#1e1b4b', 'extra' => "'frontdesk', 'investor', 'project'"],
                            'restaurant'    => ['icon' => '🍽️', 'primary' => '#dc2626', 'secondary' => '#7f1d1d', 'extra' => ''],
                            'cafe'          => ['icon' => '☕', 'primary' => '#92400e', 'secondary' => '#78350f', 'extra' => ''],
                            'retail'        => ['icon' => '🏪', 'primary' => '#0d9488', 'secondary' => '#134e4a', 'extra' => ''],
                            'manufacture'   => ['icon' => '🏭', 'primary' => '#4f46e5', 'secondary' => '#312e81', 'extra' => ''],
                            'tourism'       => ['icon' => '🏝️', 'primary' => '#0891b2', 'secondary' => '#164e63', 'extra' => "'frontdesk', 'investor', 'project'"],
                            'travel_bureau' => ['icon' => '🌊', 'primary' => '#0EA5E9', 'secondary' => '#0C4A6E', 'extra' => "'sunsea'"],
                            'other'         => ['icon' => '🏢', 'primary' => '#059669', 'secondary' => '#065f46', 'extra' => ''],
                        ];
                        $tc = $typeConf[$businessType] ?? $typeConf['other'];
                        $mods = "'cashbook', 'auth', 'settings', 'reports', 'divisions', 'procurement', 'sales', 'bills', 'payroll'";
                        if (!empty($tc['extra'])) $mods .= ', ' . $tc['extra'];
                        $cfgContent = "<?php\nreturn [\n    'business_id' => '{$autoSlug}',\n    'name' => '" . addslashes($businessName) . "',\n    'business_type' => '{$businessType}',\n    'database' => '{$dbName}',\n    'logo' => '',\n    'enabled_modules' => [{$mods}],\n    'theme' => [\n        'color_primary' => '{$tc['primary']}',\n        'color_secondary' => '{$tc['secondary']}',\n        'icon' => '{$tc['icon']}'\n    ],\n    'cashbook_columns' => [],\n    'dashboard_widgets' => ['show_daily_sales' => true, 'show_orders' => true, 'show_revenue' => true]\n];\n";
                        @file_put_contents($autoConfigPath, $cfgContent);
                    }

                    // Try to CREATE DATABASE automatically
                    $dbCreated = false;
                    try {
                        $rootPdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
                        $rootPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$actualDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                        // Some shared-hosting MySQL setups require explicit privilege assignment
                        // even when DB and user share the same cPanel prefix.
                        try {
                            $grantUser = DB_USER;
                            $rootPdo->exec("GRANT ALL PRIVILEGES ON `{$actualDbName}`.* TO '{$grantUser}'@'localhost'");
                            $rootPdo->exec("FLUSH PRIVILEGES");
                        } catch (Exception $grantErr) {
                            // Ignore silently: many shared hosts deny GRANT statement.
                            error_log("Auto GRANT failed for {$actualDbName}: " . $grantErr->getMessage());
                        }

                        $dbCreated = true;
                    } catch (Exception $dbCreateErr) {
                        // Shared hosting - CREATE DATABASE not allowed, need cPanel
                        error_log("Auto CREATE DATABASE failed: " . $dbCreateErr->getMessage());
                    }

                    if ($dbCreated) {
                        // DB created automatically! Skip to step 3 (setup tables)
                        header('Location: businesses.php?action=setup&id=' . $businessId . '&step=3&auto_db=1');
                    } else {
                        // Need manual cPanel DB creation
                        header('Location: businesses.php?action=setup&id=' . $businessId . '&step=2');
                    }
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // =============================================
    // STEP 3: Setup database tables & seed data
    // =============================================
    if ($formAction === 'setup_database') {
        $setupBizId = (int)$_POST['business_id'];
        $bizStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
        $bizStmt->execute([$setupBizId]);
        $setupBiz = $bizStmt->fetch(PDO::FETCH_ASSOC);

        if ($setupBiz) {
            $bizDbName = $setupBiz['database_name'];
            $businessName = $setupBiz['business_name'];
            $businessType = $setupBiz['business_type'];
            $seedSuccess = false;
            $setupError = '';

            try {
                $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bizDbName, DB_USER, DB_PASS);
                $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                if ($businessType === 'travel_bureau') {
                    $sunseaSetupPath = dirname(dirname(__FILE__)) . '/database/sunsea-setup.sql';
                    if (!file_exists($sunseaSetupPath)) {
                        throw new Exception('File database/sunsea-setup.sql tidak ditemukan.');
                    }

                    $sqlContent = file_get_contents($sunseaSetupPath);
                    $sqlContent = preg_replace('/^--.*$/m', '', $sqlContent);
                    $statements = array_filter(array_map('trim', explode(';', $sqlContent)));

                    foreach ($statements as $statement) {
                        if ($statement !== '') {
                            $bizPdo->exec($statement);
                        }
                    }

                    // Compatibility patch: ensure global settings module can write user preferences
                    // even if DB was initialized before latest Sunsea schema.
                    try {
                        $prefCols = $bizPdo->query("SHOW COLUMNS FROM user_preferences")->fetchAll(PDO::FETCH_COLUMN);
                        if (!in_array('language', $prefCols)) {
                            $bizPdo->exec("ALTER TABLE user_preferences ADD COLUMN language VARCHAR(5) DEFAULT 'id' AFTER theme");
                        }
                        if (!in_array('sidebar_collapsed', $prefCols)) {
                            $bizPdo->exec("ALTER TABLE user_preferences ADD COLUMN sidebar_collapsed TINYINT(1) DEFAULT 0 AFTER language");
                        }
                        if (!in_array('created_at', $prefCols)) {
                            $bizPdo->exec("ALTER TABLE user_preferences ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER sidebar_collapsed");
                        }
                    } catch (Exception $prefPatchErr) {
                        error_log('Sunsea user_preferences patch skipped: ' . $prefPatchErr->getMessage());
                    }
                } else {
                    // Create all essential tables
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS divisions (
                    id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_code VARCHAR(20), division_name VARCHAR(100) NOT NULL,
                    description TEXT, division_type ENUM('income','expense','both') DEFAULT 'both', is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS categories (
                    id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_id INT, category_name VARCHAR(100) NOT NULL,
                    category_type ENUM('income','expense') DEFAULT 'income', description TEXT, is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS settings (
                    id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT,
                    setting_type VARCHAR(20) DEFAULT 'string', description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS cash_book (
                    id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), transaction_date DATE NOT NULL, transaction_time TIME,
                    division_id INT, category_id INT, category_name VARCHAR(100), description TEXT,
                    transaction_type ENUM('income','expense') NOT NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                    payment_method VARCHAR(30) DEFAULT 'cash', cash_account_id INT, notes TEXT,
                    attachment VARCHAR(255), created_by INT, shift VARCHAR(20),
                    source_type VARCHAR(30) DEFAULT 'manual', source_id INT NULL, reference_no VARCHAR(50) NULL,
                    is_editable TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL,
                    full_name VARCHAR(100), email VARCHAR(100), phone VARCHAR(20),
                    role ENUM('owner','admin','manager','frontdesk','cashier','accountant','staff') DEFAULT 'staff',
                    role_id INT, business_access TEXT, is_active TINYINT(1) DEFAULT 1,
                    last_login DATETIME, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS roles (
                    id INT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(50) NOT NULL, role_code VARCHAR(30),
                    description TEXT, is_system_role TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS user_preferences (
                    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, branch_id VARCHAR(50),
                    theme VARCHAR(20) DEFAULT 'dark', language VARCHAR(5) DEFAULT 'id', sidebar_collapsed TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, permission VARCHAR(50) NOT NULL,
                    can_view TINYINT(1) DEFAULT 1, can_create TINYINT(1) DEFAULT 0, can_edit TINYINT(1) DEFAULT 0, can_delete TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action_type VARCHAR(50),
                    table_name VARCHAR(50), record_id INT, old_values TEXT, new_values TEXT,
                    ip_address VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
                    id INT AUTO_INCREMENT PRIMARY KEY, supplier_name VARCHAR(100) NOT NULL, contact_person VARCHAR(100),
                    phone VARCHAR(20), email VARCHAR(100), address TEXT, is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders_header (
                    id INT AUTO_INCREMENT PRIMARY KEY, po_number VARCHAR(30) UNIQUE, supplier_id INT,
                    po_date DATE NOT NULL, delivery_date DATE, status ENUM('draft','sent','received','cancelled') DEFAULT 'draft',
                    total_amount DECIMAL(15,2) DEFAULT 0, notes TEXT, created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders_detail (
                    id INT AUTO_INCREMENT PRIMARY KEY, po_header_id INT, item_name VARCHAR(200),
                    quantity DECIMAL(10,2) DEFAULT 1, unit VARCHAR(20), unit_price DECIMAL(15,2) DEFAULT 0,
                    total_price DECIMAL(15,2) DEFAULT 0, notes TEXT
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS sales_invoices_header (
                    id INT AUTO_INCREMENT PRIMARY KEY, invoice_number VARCHAR(30) UNIQUE, customer_name VARCHAR(100),
                    invoice_date DATE NOT NULL, due_date DATE, status ENUM('draft','sent','paid','cancelled') DEFAULT 'draft',
                    total_amount DECIMAL(15,2) DEFAULT 0, notes TEXT, created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS sales_invoices_detail (
                    id INT AUTO_INCREMENT PRIMARY KEY, invoice_header_id INT, item_name VARCHAR(200),
                    quantity DECIMAL(10,2) DEFAULT 1, unit VARCHAR(20), unit_price DECIMAL(15,2) DEFAULT 0,
                    total_price DECIMAL(15,2) DEFAULT 0, notes TEXT
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS bill_templates (
                    id INT AUTO_INCREMENT PRIMARY KEY, template_name VARCHAR(100), description TEXT,
                    amount DECIMAL(15,2) DEFAULT 0, frequency ENUM('monthly','weekly','yearly','once') DEFAULT 'monthly',
                    is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS bill_records (
                    id INT AUTO_INCREMENT PRIMARY KEY, template_id INT, bill_date DATE, amount DECIMAL(15,2),
                    status ENUM('pending','paid','overdue') DEFAULT 'pending', paid_date DATE, notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS transaction_attachments (
                    id INT AUTO_INCREMENT PRIMARY KEY, transaction_id INT, transaction_type VARCHAR(20),
                    file_name VARCHAR(255), file_path VARCHAR(500), file_type VARCHAR(50), file_size INT,
                    uploaded_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS rooms (
                    id INT AUTO_INCREMENT PRIMARY KEY, room_number VARCHAR(20) NOT NULL,
                    room_type_id INT, floor_number INT DEFAULT 1,
                    status VARCHAR(20) DEFAULT 'available', current_guest_id INT NULL,
                    notes TEXT, position_x INT DEFAULT 0, position_y INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS room_types (
                    id INT AUTO_INCREMENT PRIMARY KEY, type_name VARCHAR(100) NOT NULL,
                    base_price DECIMAL(12,2) DEFAULT 0, max_occupancy INT DEFAULT 2,
                    description TEXT, amenities TEXT, color_code VARCHAR(7) DEFAULT '#6366f1',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS guests (
                    id INT AUTO_INCREMENT PRIMARY KEY, guest_name VARCHAR(200) NOT NULL,
                    id_card_type VARCHAR(20) DEFAULT 'ktp', id_card_number VARCHAR(50),
                    phone VARCHAR(20), email VARCHAR(100), address TEXT,
                    nationality VARCHAR(50) DEFAULT 'Indonesia',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS bookings (
                    id INT AUTO_INCREMENT PRIMARY KEY, booking_code VARCHAR(20) UNIQUE,
                    guest_id INT, room_id INT,
                    check_in_date DATE NOT NULL, check_out_date DATE NOT NULL,
                    total_nights INT DEFAULT 1, adults INT DEFAULT 1, children INT DEFAULT 0,
                    room_price DECIMAL(12,2) DEFAULT 0, total_price DECIMAL(12,2) DEFAULT 0,
                    discount DECIMAL(12,2) DEFAULT 0, final_price DECIMAL(12,2) DEFAULT 0,
                    paid_amount DECIMAL(12,2) DEFAULT 0,
                    status VARCHAR(20) DEFAULT 'confirmed',
                    payment_status VARCHAR(20) DEFAULT 'unpaid',
                    booking_source VARCHAR(50) DEFAULT 'walk_in',
                    special_request TEXT, notes TEXT, guest_count INT DEFAULT 1,
                    actual_checkin_time DATETIME NULL, actual_checkout_time DATETIME NULL,
                    created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS booking_payments (
                    id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL,
                    amount DECIMAL(12,2) NOT NULL, payment_method VARCHAR(50) DEFAULT 'cash',
                    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                    reference_number VARCHAR(100), notes TEXT,
                    processed_by INT, created_by INT,
                    synced_to_cashbook TINYINT(1) DEFAULT 0, cashbook_id INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS frontdesk_rooms (
                    id INT AUTO_INCREMENT PRIMARY KEY, room_number VARCHAR(10), room_type VARCHAR(50),
                    floor INT DEFAULT 1, price DECIMAL(15,2) DEFAULT 0, status VARCHAR(20) DEFAULT 'available',
                    guest_name VARCHAR(100), check_in DATE, check_out DATE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS breakfast_menus (
                    id INT AUTO_INCREMENT PRIMARY KEY, menu_name VARCHAR(100) NOT NULL,
                    description TEXT, category VARCHAR(30) DEFAULT 'indonesian',
                    price DECIMAL(10,2) DEFAULT 0.00,
                    is_free TINYINT(1) DEFAULT 1, is_available TINYINT(1) DEFAULT 1,
                    image_url VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
                    id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NULL,
                    guest_name VARCHAR(100), room_number VARCHAR(20),
                    total_pax INT DEFAULT 1, breakfast_time TIME,
                    breakfast_date DATE, location VARCHAR(20) DEFAULT 'restaurant',
                    menu_items TEXT, special_requests TEXT,
                    total_price DECIMAL(10,2) DEFAULT 0.00,
                    order_status VARCHAR(20) DEFAULT 'pending',
                    created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS breakfast_log (
                    id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, guest_id INT,
                    menu_id INT NULL, quantity INT DEFAULT 1, date DATE NOT NULL,
                    status VARCHAR(20) DEFAULT 'taken', marked_by INT,
                    marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, notes VARCHAR(255)
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS investors (
                    id INT AUTO_INCREMENT PRIMARY KEY, investor_name VARCHAR(100) NOT NULL, phone VARCHAR(20),
                    email VARCHAR(100), address TEXT, notes TEXT, is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS investor_transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, transaction_type ENUM('investment','return','dividend'),
                    amount DECIMAL(15,2), transaction_date DATE, notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS investor_balances (
                    id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, balance DECIMAL(15,2) DEFAULT 0,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                    $bizPdo->exec("CREATE TABLE IF NOT EXISTS investor_bills (
                    id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, bill_name VARCHAR(100),
                    amount DECIMAL(15,2), due_date DATE, status ENUM('pending','paid','overdue') DEFAULT 'pending',
                    paid_date DATE, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                    $bizId = strtolower(str_replace(' ', '-', $businessName));

                    $bizPdo->exec("INSERT IGNORE INTO roles (id, role_name, role_code, description, is_system_role) VALUES
                    (1, 'Admin', 'admin', 'System administrator', 1),
                    (2, 'Manager', 'manager', 'Business manager', 1),
                    (3, 'Staff', 'staff', 'Regular staff', 1),
                    (4, 'Developer', 'developer', 'System developer', 1),
                    (5, 'Owner', 'owner', 'Business owner', 1)");

                    $bizPdo->exec("INSERT IGNORE INTO divisions (id, branch_id, division_code, division_name, division_type) VALUES
                    (1, '{$bizId}', 'KITCHEN', 'Kitchen', 'both'),
                    (2, '{$bizId}', 'BAR', 'Bar', 'both'),
                    (3, '{$bizId}', 'RESTO', 'Resto', 'both'),
                    (4, '{$bizId}', 'HOUSEKEEPING', 'Housekeeping', 'expense'),
                    (5, '{$bizId}', 'HOTEL', 'Hotel', 'income'),
                    (6, '{$bizId}', 'GARDENER', 'Gardener', 'expense'),
                    (7, '{$bizId}', 'OTHERS', 'Lain-lain', 'both'),
                    (8, '{$bizId}', 'PC', 'Petty Cash', 'both')");

                    $bizPdo->exec("INSERT IGNORE INTO categories (branch_id, division_id, category_name, category_type, description) VALUES
                    ('{$bizId}', 1, 'Food Sales', 'income', 'Revenue from food sales'),
                    ('{$bizId}', 1, 'Food Supplies', 'expense', 'Purchase of food ingredients'),
                    ('{$bizId}', 2, 'Beverage Sales', 'income', 'Revenue from beverage sales'),
                    ('{$bizId}', 2, 'Beverage Inventory', 'expense', 'Purchase of beverages'),
                    ('{$bizId}', 3, 'Room Rental', 'income', 'Room rental income'),
                    ('{$bizId}', 3, 'Staff Salary', 'expense', 'Employee salaries'),
                    ('{$bizId}', 4, 'Housekeeping Service', 'income', 'Cleaning services'),
                    ('{$bizId}', 4, 'Room Supplies', 'expense', 'Room cleaning supplies'),
                    ('{$bizId}', 5, 'Hotel Income', 'income', 'Hotel revenue'),
                    ('{$bizId}', 5, 'Hotel Expense', 'expense', 'Hotel operational expenses'),
                    ('{$bizId}', 7, 'Other Income', 'income', 'Miscellaneous income'),
                    ('{$bizId}', 7, 'Other Expense', 'expense', 'Miscellaneous expenses')");

                    $bizPdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
                    ('business_name', '{$businessName}', 'string', 'Business name'),
                    ('business_type', '{$businessType}', 'string', 'Business type'),
                    ('currency', 'IDR', 'string', 'Currency'),
                    ('timezone', 'Asia/Jakarta', 'string', 'Timezone'),
                    ('date_format', 'd/m/Y', 'string', 'Date format'),
                    ('fiscal_year_start', '01', 'string', 'Fiscal year start month'),
                    ('show_running_balance', '1', 'boolean', 'Show running balance'),
                    ('show_daily_total', '1', 'boolean', 'Show daily total'),
                    ('enable_shift', '1', 'boolean', 'Enable shift'),
                    ('enable_approval', '0', 'boolean', 'Require approval'),
                    ('min_kas_awal', '0', 'string', 'Minimum kas awal'),
                    ('kas_awal_default', '0', 'string', 'Default kas awal'),
                    ('print_receipt', '1', 'boolean', 'Enable print receipt'),
                    ('demo_password', 'admin', 'string', 'Demo password')");

                    if ($businessType === 'hotel') {
                        $bizPdo->exec("INSERT IGNORE INTO room_types (id, type_name, base_price, max_occupancy, description, color_code) VALUES
                            (1, 'Standard', 500000, 2, 'Standard room', '#6366f1'),
                            (2, 'Deluxe', 750000, 2, 'Deluxe room', '#10b981'),
                            (3, 'Suite', 1200000, 4, 'Suite room', '#f59e0b')");
                    }
                }

                $seedSuccess = true;
            } catch (Exception $seedErr) {
                $setupError = $seedErr->getMessage();
            }

            if ($seedSuccess) {
                // Also generate config file and activate business (merge step 4 here)
                $cfgCode = $setupBiz['business_code'];
                $cfgSlug = !empty($setupBiz['slug']) ? $setupBiz['slug'] : businessCodeToSlug($cfgCode);
                $cfgPath = dirname(dirname(__FILE__)) . '/config/businesses/' . $cfgSlug . '.php';

                if (!file_exists($cfgPath)) {
                    $cfgType = $setupBiz['business_type'] ?? 'other';
                    $cfgName = $setupBiz['business_name'];
                    $localDb = $bizDbName;
                    // If hosting name, convert back to local name for config
                    if (defined('DB_USER') && strpos($bizDbName, explode('_', DB_USER)[0] . '_') === 0) {
                        $localDb = 'adf_' . substr($bizDbName, strlen(explode('_', DB_USER)[0] . '_'));
                    }

                    $typeConf2 = [
                        'hotel'         => ['icon' => '🏨', 'primary' => '#4338ca', 'secondary' => '#1e1b4b', 'extra' => "'frontdesk', 'investor', 'project'"],
                        'restaurant'    => ['icon' => '🍽️', 'primary' => '#dc2626', 'secondary' => '#7f1d1d', 'extra' => ''],
                        'cafe'          => ['icon' => '☕', 'primary' => '#92400e', 'secondary' => '#78350f', 'extra' => ''],
                        'retail'        => ['icon' => '🏪', 'primary' => '#0d9488', 'secondary' => '#134e4a', 'extra' => ''],
                        'manufacture'   => ['icon' => '🏭', 'primary' => '#4f46e5', 'secondary' => '#312e81', 'extra' => ''],
                        'tourism'       => ['icon' => '🏝️', 'primary' => '#0891b2', 'secondary' => '#164e63', 'extra' => "'frontdesk', 'investor', 'project'"],
                        'travel_bureau' => ['icon' => '🌊', 'primary' => '#0EA5E9', 'secondary' => '#0C4A6E', 'extra' => "'sunsea'"],
                        'other'         => ['icon' => '🏢', 'primary' => '#059669', 'secondary' => '#065f46', 'extra' => ''],
                    ];
                    $tc2 = $typeConf2[$cfgType] ?? $typeConf2['other'];
                    $mods2 = "'cashbook', 'auth', 'settings', 'reports', 'divisions', 'procurement', 'sales', 'bills'";
                    if (!empty($tc2['extra'])) $mods2 .= ', ' . $tc2['extra'];

                    $configContent2 = "<?php\nreturn [\n    'business_id' => '{$cfgSlug}',\n    'name' => '" . addslashes($cfgName) . "',\n    'business_type' => '{$cfgType}',\n    'database' => '{$localDb}',\n    'logo' => '',\n    'enabled_modules' => [{$mods2}],\n    'theme' => [\n        'color_primary' => '{$tc2['primary']}',\n        'color_secondary' => '{$tc2['secondary']}',\n        'icon' => '{$tc2['icon']}'\n    ],\n    'cashbook_columns' => [],\n    'dashboard_widgets' => ['show_daily_sales' => true, 'show_orders' => true, 'show_revenue' => true]\n];\n";
                    @file_put_contents($cfgPath, $configContent2);
                }

                // Activate business
                $pdo->prepare("UPDATE businesses SET is_active = 1 WHERE id = ?")->execute([$setupBizId]);

                // Auto-assign owner to this business
                try {
                    $ownerRow = $pdo->prepare("SELECT owner_id FROM businesses WHERE id = ?");
                    $ownerRow->execute([$setupBizId]);
                    $ownerId = (int)$ownerRow->fetchColumn();
                    if ($ownerId > 0) {
                        $checkAssign = $pdo->prepare("SELECT COUNT(*) FROM user_business_assignment WHERE user_id = ? AND business_id = ?");
                        $checkAssign->execute([$ownerId, $setupBizId]);
                        if ($checkAssign->fetchColumn() == 0) {
                            $pdo->prepare("INSERT INTO user_business_assignment (user_id, business_id) VALUES (?, ?)")->execute([$ownerId, $setupBizId]);
                        }
                    }
                } catch (Exception $e) {
                }

                header('Location: businesses.php?action=setup&id=' . $setupBizId . '&step=done');
            } else {
                header('Location: businesses.php?action=setup&id=' . $setupBizId . '&step=3&db_error=' . urlencode($setupError));
            }
            exit;
        }
    }

    // =============================================
    // STEP 4: Generate config file
    // =============================================
    if ($formAction === 'generate_config') {
        $configBizId = (int)$_POST['business_id'];
        $bizStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
        $bizStmt->execute([$configBizId]);
        $configBiz = $bizStmt->fetch(PDO::FETCH_ASSOC);

        if ($configBiz) {
            $code = $configBiz['business_code'];
            $slug = !empty($configBiz['slug']) ? $configBiz['slug'] : businessCodeToSlug($code);
            $name = $configBiz['business_name'];
            $type = $configBiz['business_type'];
            $localDbName = 'adf_' . strtolower(preg_replace('/[^a-z0-9]/i', '_', $code));

            // Determine modules based on type
            $modules = "        'cashbook',\n        'auth',\n        'settings',\n        'reports',\n        'divisions',\n        'procurement',\n        'sales',\n        'bills'";
            if ($type === 'travel_bureau') {
                $modules .= ",\n        'sunsea'";
            } elseif (in_array($type, ['hotel', 'tourism'])) {
                $modules .= ",\n        'frontdesk',\n        'investor',\n        'project'";
            }

            // Determine icon & colors based on type
            $typeConfig = [
                'hotel'         => ['icon' => '🏨', 'primary' => '#4338ca', 'secondary' => '#1e1b4b'],
                'restaurant'    => ['icon' => '🍽️', 'primary' => '#dc2626', 'secondary' => '#7f1d1d'],
                'cafe'          => ['icon' => '☕', 'primary' => '#92400e', 'secondary' => '#78350f'],
                'retail'        => ['icon' => '🏪', 'primary' => '#0d9488', 'secondary' => '#134e4a'],
                'manufacture'   => ['icon' => '🏭', 'primary' => '#4f46e5', 'secondary' => '#312e81'],
                'tourism'       => ['icon' => '🏝️', 'primary' => '#0891b2', 'secondary' => '#164e63'],
                'travel_bureau' => ['icon' => '🌊', 'primary' => '#0EA5E9', 'secondary' => '#0C4A6E'],
                'other'         => ['icon' => '🏢', 'primary' => '#059669', 'secondary' => '#065f46'],
            ];
            $tc = $typeConfig[$type] ?? $typeConfig['other'];

            $configContent = "<?php\nreturn [\n    'business_id' => '{$slug}',\n    'name' => '{$name}',\n    'business_type' => '{$type}',\n    'database' => '{$localDbName}',\n\n    'logo' => '',\n\n    'enabled_modules' => [\n{$modules}\n    ],\n\n    'theme' => [\n        'color_primary' => '{$tc['primary']}',\n        'color_secondary' => '{$tc['secondary']}',\n        'icon' => '{$tc['icon']}'\n    ],\n\n    'cashbook_columns' => [],\n\n    'dashboard_widgets' => [\n        'show_daily_sales' => true,\n        'show_orders' => true,\n        'show_revenue' => true\n    ]\n];\n";

            $configPath = dirname(dirname(__FILE__)) . '/config/businesses/' . $slug . '.php';

            if (file_put_contents($configPath, $configContent)) {
                // Activate the business
                $pdo->prepare("UPDATE businesses SET is_active = 1 WHERE id = ?")->execute([$configBizId]);
                header('Location: businesses.php?action=setup&id=' . $configBizId . '&step=5&config_ok=1');
            } else {
                header('Location: businesses.php?action=setup&id=' . $configBizId . '&step=4&config_error=1');
            }
            exit;
        }
    }

    // =============================================
    // UPDATE (existing edit action)
    // =============================================
    if ($_POST['form_action'] === 'update') {
        $businessCode = strtoupper(trim($_POST['business_code'] ?? ''));
        $businessName = trim($_POST['business_name'] ?? '');
        $businessType = $_POST['business_type'] ?? 'other';
        $ownerId = (int)($_POST['owner_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $addonDomain = trim($_POST['addon_domain'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $selectedMenus = $_POST['menus'] ?? [];

        if (empty($businessCode) || empty($businessName) || $ownerId === 0) {
            $error = 'Please fill all required fields';
        } else {
            try {
                $updateId = (int)$_POST['business_id'];
                $stmt = $pdo->prepare("
                    UPDATE businesses SET business_code = ?, business_name = ?, business_type = ?, owner_id = ?, description = ?, addon_domain = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$businessCode, $businessName, $businessType, $ownerId, $description, $addonDomain ?: null, $isActive, $updateId]);

                $pdo->prepare("DELETE FROM business_menu_config WHERE business_id = ?")->execute([$updateId]);
                $menuStmt = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)");
                foreach ($selectedMenus as $menuId) {
                    $menuStmt->execute([$updateId, $menuId]);
                }

                $auth->logAction('update_business', 'businesses', $updateId, null, ['name' => $businessName]);
                $_SESSION['success_message'] = 'Business updated successfully!';
                header('Location: businesses.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle delete
if ($action === 'delete' && $editId) {
    try {
        $bizStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
        $bizStmt->execute([$editId]);
        $bizToDelete = $bizStmt->fetch(PDO::FETCH_ASSOC);

        if ($bizToDelete) {
            $pdo->prepare("DELETE FROM businesses WHERE id = ?")->execute([$editId]);
            // Also delete config file if exists
            $delSlug = !empty($bizToDelete['slug']) ? $bizToDelete['slug'] : businessCodeToSlug($bizToDelete['business_code']);
            $delConfigPath = dirname(dirname(__FILE__)) . '/config/businesses/' . $delSlug . '.php';
            if (file_exists($delConfigPath)) {
                @unlink($delConfigPath);
            }
            $auth->logAction('delete_business', 'businesses', $editId, $bizToDelete);
            $_SESSION['success_message'] = 'Business deleted! Note: Database was NOT deleted for safety.';
        }
        header('Location: businesses.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Failed to delete: ' . $e->getMessage();
    }
}

// Get business for editing
$editBusiness = null;
$editMenus = [];
if ($action === 'edit' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
    $stmt->execute([$editId]);
    $editBusiness = $stmt->fetch(PDO::FETCH_ASSOC);
    $menuStmt = $pdo->prepare("SELECT menu_id FROM business_menu_config WHERE business_id = ? AND is_enabled = 1");
    $menuStmt->execute([$editId]);
    $editMenus = $menuStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get business for setup wizard
$setupBusiness = null;
$setupStep = $_GET['step'] ?? '2';
if ($action === 'setup' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
    $stmt->execute([$editId]);
    $setupBusiness = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check database connection for step detection
    if ($setupBusiness) {
        $setupDbName = $setupBusiness['database_name'];
        $dbConnected = false;
        $tableCount = 0;
        $configSlug = !empty($setupBusiness['slug']) ? $setupBusiness['slug'] : businessCodeToSlug($setupBusiness['business_code']);
        $configExists = file_exists(dirname(dirname(__FILE__)) . '/config/businesses/' . $configSlug . '.php');

        try {
            $testPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $setupDbName, DB_USER, DB_PASS);
            $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $dbConnected = true;
            $tableCount = (int)$testPdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$setupDbName}'")->fetchColumn();
        } catch (Exception $e) {
            $dbConnected = false;
        }
    }
}

// Get all businesses
$businesses = [];
try {
    $stmt = $pdo->query("
        SELECT b.*, u.full_name as owner_name,
               (SELECT COUNT(*) FROM business_menu_config WHERE business_id = b.id AND is_enabled = 1) as menu_count,
               (SELECT COUNT(*) FROM user_business_assignment WHERE business_id = b.id) as user_count
        FROM businesses b
        LEFT JOIN users u ON b.owner_id = u.id
        ORDER BY b.created_at DESC
    ");
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .wizard-steps {
        display: flex;
        justify-content: center;
        gap: 0;
        margin-bottom: 2rem;
        position: relative;
    }

    .wizard-step {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        background: #1e1e2d;
        border-radius: 10px;
        color: #6c757d;
        font-size: 0.85rem;
        font-weight: 500;
        position: relative;
        transition: all 0.3s;
    }

    .wizard-step .step-num {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #2d2d3d;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.8rem;
    }

    .wizard-step.active {
        background: linear-gradient(135deg, #6f42c1, #8b5cf6);
        color: #fff;
        box-shadow: 0 4px 15px rgba(111, 66, 193, .4);
    }

    .wizard-step.active .step-num {
        background: rgba(255, 255, 255, .2);
    }

    .wizard-step.done {
        background: #1a3a2a;
        color: #10b981;
    }

    .wizard-step.done .step-num {
        background: #10b981;
        color: #fff;
    }

    .wizard-connector {
        width: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #3d3d4d;
        font-size: 1rem;
    }

    .setup-card {
        background: #1e1e2d;
        border-radius: 14px;
        padding: 2rem;
        border: 1px solid rgba(255, 255, 255, .06);
    }

    .setup-card h4 {
        color: #fff;
        margin-bottom: 0.5rem;
    }

    .setup-card .subtitle {
        color: #8b8b9e;
        font-size: 0.9rem;
    }

    .instruction-box {
        background: #151521;
        border: 1px solid rgba(111, 66, 193, .3);
        border-radius: 10px;
        padding: 1.5rem;
        margin: 1.5rem 0;
    }

    .instruction-box .step-label {
        color: #8b5cf6;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 0.5rem;
    }

    .instruction-box p {
        color: #c4c4d4;
        margin: 0.5rem 0;
        font-size: 0.9rem;
    }

    .instruction-box code {
        background: #2d2d3d;
        padding: 2px 8px;
        border-radius: 4px;
        color: #10b981;
        font-size: 0.85rem;
    }

    .btn-cpanel {
        background: linear-gradient(135deg, #ff6b35, #f7931e);
        border: none;
        color: #fff;
        padding: 12px 24px;
        border-radius: 10px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: all 0.3s;
    }

    .btn-cpanel:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(255, 107, 53, .4);
        color: #fff;
    }

    .btn-setup {
        background: linear-gradient(135deg, #6f42c1, #8b5cf6);
        border: none;
        color: #fff;
        padding: 14px 32px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 1rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-setup:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(111, 66, 193, .4);
        color: #fff;
    }

    .btn-config {
        background: linear-gradient(135deg, #10b981, #059669);
        border: none;
        color: #fff;
        padding: 14px 32px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 1rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-config:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(16, 185, 129, .4);
        color: #fff;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .status-badge.pending {
        background: rgba(245, 158, 11, .15);
        color: #f59e0b;
    }

    .status-badge.success {
        background: rgba(16, 185, 129, .15);
        color: #10b981;
    }

    .status-badge.error {
        background: rgba(239, 68, 68, .15);
        color: #ef4444;
    }

    .completion-card {
        text-align: center;
        padding: 3rem;
    }

    .completion-card .icon-big {
        font-size: 4rem;
        margin-bottom: 1rem;
    }

    .completion-card h3 {
        color: #10b981;
    }

    .biz-status-col {
        min-width: 120px;
    }
</style>

<div class="container-fluid py-4">
    <?php if ($action === 'setup' && $setupBusiness): ?>
        <!-- ============================================= -->
        <!-- SETUP WIZARD -->
        <!-- ============================================= -->
        <?php
        $sDb = $setupBusiness['database_name'];
        $sCode = $setupBusiness['business_code'];
        $sName = $setupBusiness['business_name'];
        $sSlug = !empty($setupBusiness['slug']) ? $setupBusiness['slug'] : businessCodeToSlug($sCode);
        $hostingDbName = $sDb;
        $isDone = ($setupStep === 'done' || (isset($_GET['step']) && $_GET['step'] === 'done'));
        ?>

        <div class="row justify-content-center">
            <div class="col-lg-10">
                <!-- Wizard Header -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="text-white mb-0"><i class="bi bi-rocket-takeoff me-2"></i>Setup: <?= htmlspecialchars($sName) ?></h4>
                    <a href="businesses.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
                </div>

                <!-- Step Indicators (simplified: 3 steps) -->
                <div class="wizard-steps flex-wrap">
                    <div class="wizard-step done">
                        <span class="step-num"><i class="bi bi-check"></i></span> Register
                    </div>
                    <div class="wizard-connector"><i class="bi bi-chevron-right"></i></div>
                    <div class="wizard-step <?= $setupStep == 2 ? 'active' : ($setupStep == 3 || $isDone ? 'done' : '') ?>">
                        <span class="step-num"><?= ($setupStep == 3 || $isDone) ? '<i class="bi bi-check"></i>' : '2' ?></span> Database
                    </div>
                    <div class="wizard-connector"><i class="bi bi-chevron-right"></i></div>
                    <div class="wizard-step <?= $setupStep == 3 ? 'active' : ($isDone ? 'done' : '') ?>">
                        <span class="step-num"><?= $isDone ? '<i class="bi bi-check"></i>' : '3' ?></span> Setup & Selesai
                    </div>
                </div>

                <!-- ========== STEP 2: Create Database in cPanel ========== -->
                <?php if ($setupStep == 2): ?>
                    <div class="setup-card">
                        <h4><i class="bi bi-database-add me-2"></i>Step 2: Buat Database di cPanel</h4>
                        <p class="subtitle">Database tidak bisa dibuat otomatis di shared hosting. Ikuti langkah berikut:</p>

                        <div class="instruction-box">
                            <div class="step-label">Langkah A — Buat Database</div>
                            <p>1. Klik tombol di bawah untuk buka <strong>cPanel → MySQL Databases</strong></p>
                            <p>2. Di bagian <strong>"Create New Database"</strong>, masukkan nama:</p>
                            <p><code style="font-size:1.1rem; padding: 6px 14px;"><?= htmlspecialchars($hostingDbName) ?></code>
                                <button onclick="copyText('<?= htmlspecialchars($hostingDbName) ?>')" class="btn btn-sm btn-outline-light ms-2" title="Copy">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </p>
                            <p class="text-warning" style="font-size:0.8rem;"><i class="bi bi-info-circle me-1"></i>cPanel akan otomatis menambahkan prefix <code><?= $dbPrefix ?></code>. Pastikan nama database setelah prefix sesuai.</p>
                        </div>

                        <div class="instruction-box">
                            <div class="step-label">Langkah B — Assign User ke Database</div>
                            <p>1. Scroll ke bagian <strong>"Add User to Database"</strong></p>
                            <p>2. Pilih User: <code><?= htmlspecialchars(DB_USER) ?></code></p>
                            <p>3. Pilih Database: <code><?= htmlspecialchars($hostingDbName) ?></code></p>
                            <p>4. Centang <strong>ALL PRIVILEGES</strong> → Klik <strong>"Make Changes"</strong></p>
                        </div>

                        <div class="d-flex gap-3 mt-4 flex-wrap">
                            <a href="<?= $cpanelUrl ?>/cpsess0000000000/frontend/jupiter/sql/index.html"
                                target="_blank" class="btn-cpanel">
                                <i class="bi bi-box-arrow-up-right"></i> Buka cPanel MySQL
                            </a>

                            <a href="businesses.php?action=setup&id=<?= $setupBusiness['id'] ?>&step=3" class="btn-setup">
                                <i class="bi bi-arrow-right-circle"></i> Sudah Selesai → Lanjut Step 3
                            </a>
                        </div>

                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="bi bi-lightbulb me-1"></i>
                                Jika sudah pernah buat database-nya, langsung klik "Lanjut Step 3".
                            </small>
                        </div>
                    </div>

                    <!-- ========== STEP 3: Setup Tables ========== -->
                <?php elseif ($setupStep == 3): ?>
                    <div class="setup-card">
                        <h4><i class="bi bi-table me-2"></i>Step 3: Setup Tables & Selesaikan</h4>
                        <p class="subtitle">Buat semua tabel yang diperlukan, generate config, dan aktifkan bisnis.</p>

                        <?php if (isset($_GET['auto_db'])): ?>
                            <div class="alert alert-success mt-3">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>Database berhasil dibuat otomatis!</strong> Lanjut setup tabel di bawah.
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['db_error'])): ?>
                            <div class="alert alert-danger mt-3">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Gagal connect ke database!</strong> Pastikan database sudah dibuat di cPanel dan user sudah di-assign.
                                <br><small class="text-light"><?= htmlspecialchars($_GET['db_error']) ?></small>
                            </div>
                            <a href="businesses.php?action=setup&id=<?= $setupBusiness['id'] ?>&step=2" class="btn btn-outline-warning mt-2">
                                <i class="bi bi-arrow-left me-1"></i> Kembali ke Step 2
                            </a>
                        <?php else: ?>

                            <div class="instruction-box">
                                <div class="step-label">Info Database</div>
                                <p>Database: <code><?= htmlspecialchars($hostingDbName) ?></code></p>
                                <p>Status:
                                    <?php if ($dbConnected): ?>
                                        <span class="status-badge success"><i class="bi bi-check-circle"></i> Connected (<?= $tableCount ?> tables)</span>
                                    <?php else: ?>
                                        <span class="status-badge error"><i class="bi bi-x-circle"></i> Tidak bisa connect</span>
                                    <?php endif; ?>
                                </p>
                                <?php if (!$dbConnected): ?>
                                    <p class="text-warning mt-2"><i class="bi bi-exclamation-triangle me-1"></i>Database belum ada atau user belum di-assign.
                                        <a href="businesses.php?action=setup&id=<?= $setupBusiness['id'] ?>&step=2" class="text-info">Kembali ke Step 2</a>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="instruction-box">
                                <div class="step-label">Yang Akan Dibuat</div>
                                <?php if (($setupBusiness['business_type'] ?? '') === 'travel_bureau'): ?>
                                    <p><i class="bi bi-check2 text-success me-1"></i> Schema Sunsea travel bureau otomatis</p>
                                    <p><i class="bi bi-check2 text-success me-1"></i> Tabel customer, trip_packages, quotations, invoices, payments</p>
                                    <p><i class="bi bi-check2 text-success me-1"></i> Cashbook, cash accounts, bills, employees, settings</p>
                                    <p><i class="bi bi-check2 text-success me-1"></i> Nomor otomatis SS-QUO dan SS-INV</p>
                                <?php else: ?>
                                    <p><i class="bi bi-check2 text-success me-1"></i> 25+ tabel (cash_book, rooms, bookings, suppliers, dll)</p>
                                    <p><i class="bi bi-check2 text-success me-1"></i> 5 roles (Admin, Manager, Staff, Developer, Owner)</p>
                                    <p><i class="bi bi-check2 text-success me-1"></i> 8 divisions default</p>
                                    <p><i class="bi bi-check2 text-success me-1"></i> 12 categories default</p>
                                    <p><i class="bi bi-check2 text-success me-1"></i> 14 settings default</p>
                                    <?php if ($setupBusiness['business_type'] === 'hotel'): ?>
                                        <p><i class="bi bi-check2 text-success me-1"></i> 3 room types (Standard, Deluxe, Suite)</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex gap-3 mt-4">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="form_action" value="setup_database">
                                    <input type="hidden" name="business_id" value="<?= $setupBusiness['id'] ?>">
                                    <button type="submit" class="btn-setup" <?= !$dbConnected ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>>
                                        <i class="bi bi-play-circle"></i> Setup Database & Aktifkan Bisnis
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ========== DONE ========== -->
                <?php elseif ($isDone): ?>
                    <div class="setup-card">
                        <div class="completion-card">
                            <div class="icon-big">🎉</div>
                            <h3>Bisnis Berhasil Dibuat!</h3>
                            <p class="text-muted mb-4"><?= htmlspecialchars($sName) ?> siap digunakan.</p>

                            <div class="row justify-content-center g-3 mb-4">
                                <div class="col-auto">
                                    <div class="instruction-box text-center" style="padding: 1rem 2rem;">
                                        <small class="text-muted d-block">Database</small>
                                        <code><?= htmlspecialchars($sDb) ?></code>
                                        <br><span class="status-badge success mt-1"><i class="bi bi-check-circle"></i> OK</span>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="instruction-box text-center" style="padding: 1rem 2rem;">
                                        <small class="text-muted d-block">Config File</small>
                                        <code><?= htmlspecialchars($sSlug) ?>.php</code>
                                        <br><span class="status-badge success mt-1"><i class="bi bi-check-circle"></i> OK</span>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="instruction-box text-center" style="padding: 1rem 2rem;">
                                        <small class="text-muted d-block">Status</small>
                                        <span style="color:#10b981; font-weight:600;">Active</span>
                                        <br><span class="status-badge success mt-1"><i class="bi bi-check-circle"></i> OK</span>
                                    </div>
                                </div>
                            </div>

                            <?php
                            $staffLoginUrl = (defined('BASE_URL') ? BASE_URL : '') . '/login.php?biz=' . $sSlug;
                            ?>
                            <div class="instruction-box">
                                <div class="step-label">Staff Login Link</div>
                                <p>
                                    <code style="font-size:0.9rem;"><?= htmlspecialchars($staffLoginUrl) ?></code>
                                    <button onclick="copyText('<?= htmlspecialchars($staffLoginUrl) ?>')" class="btn btn-sm btn-outline-light ms-2"><i class="bi bi-clipboard"></i></button>
                                </p>
                            </div>

                            <div class="instruction-box">
                                <div class="step-label">Langkah Selanjutnya</div>
                                <p><i class="bi bi-arrow-right-circle text-info me-1"></i> Buka <strong>Owner Access</strong> untuk assign user ke bisnis ini</p>
                                <p><i class="bi bi-arrow-right-circle text-info me-1"></i> Atau buka <strong>User Management</strong> untuk buat user baru untuk bisnis ini</p>
                            </div>

                            <div class="d-flex justify-content-center gap-3 mt-4">
                                <a href="businesses.php" class="btn btn-lg btn-outline-light">
                                    <i class="bi bi-list me-1"></i> Kembali ke List
                                </a>
                                <a href="owner-access.php" class="btn btn-lg btn-outline-info">
                                    <i class="bi bi-people me-1"></i> Setup Akses User
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($action === 'add' || $action === 'edit'): ?>
        <!-- ============================================= -->
        <!-- ADD / EDIT FORM (Step 1 for add) -->
        <!-- ============================================= -->
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h5><i class="bi bi-building-<?php echo $action === 'add' ? 'add' : 'gear'; ?> me-2"></i>
                            <?php echo $action === 'add' ? 'Add New Business' : 'Edit Business'; ?>
                        </h5>
                        <a href="businesses.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Back to List
                        </a>
                    </div>

                    <div class="p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($action === 'add'): ?>
                            <!-- Step indicator for add -->
                            <div class="wizard-steps flex-wrap mb-4">
                                <div class="wizard-step active"><span class="step-num">1</span> Register</div>
                                <div class="wizard-connector"><i class="bi bi-chevron-right"></i></div>
                                <div class="wizard-step"><span class="step-num">2</span> Database</div>
                                <div class="wizard-connector"><i class="bi bi-chevron-right"></i></div>
                                <div class="wizard-step"><span class="step-num">3</span> Setup & Selesai</div>
                            </div>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Step 1:</strong> Isi data bisnis. Sistem akan otomatis coba buat database. Jika di shared hosting, Anda akan dipandu buat DB di cPanel.
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="form_action" value="<?php echo $action === 'add' ? 'register_business' : 'update'; ?>">
                            <?php if ($editBusiness): ?>
                                <input type="hidden" name="business_id" value="<?php echo $editBusiness['id']; ?>">
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Business Code <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control text-uppercase" name="business_code" required
                                        placeholder="e.g., HOTEL_01, CAFE_BENS"
                                        value="<?php echo htmlspecialchars($editBusiness['business_code'] ?? ''); ?>"
                                        <?php echo $action === 'edit' ? 'readonly' : ''; ?>>
                                    <small class="text-muted">Unique identifier, will be used for database name</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Business Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="business_name" required
                                        placeholder="e.g., Narayana Hotel, Ben's Cafe"
                                        value="<?php echo htmlspecialchars($editBusiness['business_name'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Business Type <span class="text-danger">*</span></label>
                                    <select class="form-select" name="business_type" required>
                                        <?php foreach ($businessTypes as $type): ?>
                                            <option value="<?php echo $type; ?>" <?php echo ($editBusiness['business_type'] ?? '') === $type ? 'selected' : ''; ?>>
                                                <?php echo ucwords(str_replace('_', ' ', $type)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Owner <span class="text-danger">*</span></label>
                                    <select class="form-select" name="owner_id" required>
                                        <option value="">Select Owner</option>
                                        <?php foreach ($owners as $owner): ?>
                                            <option value="<?php echo $owner['id']; ?>" <?php echo ($editBusiness['owner_id'] ?? '') == $owner['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($owner['full_name']); ?> (@<?php echo $owner['username']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="2"><?php echo htmlspecialchars($editBusiness['description'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Addon Domain <small class="text-muted">(opsional)</small></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-globe"></i></span>
                                    <input type="text" class="form-control" name="addon_domain"
                                        placeholder="contoh: pwfoffice.com"
                                        value="<?php echo htmlspecialchars($editBusiness['addon_domain'] ?? ''); ?>">
                                </div>
                                <small class="text-muted">
                                    Domain khusus bisnis ini (addon domain cPanel). Kosongkan jika bisnis ini cukup diakses via <strong>adfsystem.online</strong>.
                                    Setelah diisi, pengguna yang membuka domain ini akan langsung diarahkan ke bisnis ini secara otomatis.
                                </small>
                            </div>

                            <?php if ($editBusiness): ?>
                                <div class="mb-3">
                                    <label class="form-label">Database Name</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($editBusiness['database_name']); ?>" readonly>
                                    <small class="text-muted">Database name cannot be changed after creation</small>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label">Enable Menus for this Business</label>

                                <?php if (!empty($businessListForCopy)): ?>
                                    <div class="input-group input-group-sm mb-3" style="max-width: 420px;">
                                        <span class="input-group-text"><i class="bi bi-clipboard-check"></i></span>
                                        <select class="form-select" id="copyMenuFromBiz">
                                            <option value="">Salin menu dari bisnis lain...</option>
                                            <?php foreach ($businessListForCopy as $b): ?>
                                                <?php if (!$editBusiness || $b['id'] != $editBusiness['id']): ?>
                                                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-secondary" id="applyCopyMenuBtn">Terapkan</button>
                                    </div>
                                    <small class="text-muted d-block mb-2">Pilih bisnis contoh (mis. Bens Cafe), lalu klik Terapkan untuk mencontek menu yang sudah dipakainya.</small>
                                <?php endif; ?>

                                <div class="row">
                                    <?php foreach ($menus as $menu): ?>
                                        <div class="col-md-4 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input menu-checkbox" type="checkbox" name="menus[]" value="<?php echo $menu['id']; ?>"
                                                    id="menu_<?php echo $menu['id']; ?>"
                                                    <?php echo in_array($menu['id'], $editMenus) || $action === 'add' ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="menu_<?php echo $menu['id']; ?>">
                                                    <i class="<?php echo $menu['menu_icon']; ?> me-1"></i>
                                                    <?php echo htmlspecialchars($menu['menu_name']); ?>
                                                </label>
                                                <?php if (!empty($menuUsageMap[$menu['id']])): ?>
                                                    <div class="text-muted" style="font-size: 0.72rem; padding-left: 1.6rem;">
                                                        Dipakai: <?php echo htmlspecialchars(implode(', ', $menuUsageMap[$menu['id']])); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-muted fst-italic" style="font-size: 0.72rem; padding-left: 1.6rem;">
                                                        Belum dipakai bisnis manapun
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php if (!empty($businessMenuMap)): ?>
                                <script>
                                    const businessMenuMap = <?php echo json_encode($businessMenuMap); ?>;
                                    document.getElementById('applyCopyMenuBtn')?.addEventListener('click', function() {
                                        const bizId = document.getElementById('copyMenuFromBiz').value;
                                        if (!bizId) return;
                                        const enabledMenuIds = (businessMenuMap[bizId] || []).map(String);
                                        document.querySelectorAll('.menu-checkbox').forEach(function(cb) {
                                            cb.checked = enabledMenuIds.includes(cb.value);
                                        });
                                    });
                                </script>
                            <?php endif; ?>

                            <?php if ($action === 'edit'): ?>
                                <div class="mb-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                            <?php echo ($editBusiness['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">Active Business</label>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-<?php echo $action === 'add' ? 'arrow-right-circle' : 'check-lg'; ?> me-1"></i><?php echo $action === 'add' ? 'Register & Buat Database →' : 'Update Business'; ?>
                                </button>
                                <a href="businesses.php" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Businesses List -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">Business Management</h4>
                    <a href="?action=add" class="btn btn-primary">
                        <i class="bi bi-building-add me-1"></i>Add New Business
                    </a>
                </div>
            </div>
        </div>

        <div class="content-card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Business</th>
                            <th>Type</th>
                            <th>Database</th>
                            <th>Owner</th>
                            <th>Menus</th>
                            <th>Users</th>
                            <th>Status</th>
                            <th>Staff Login Link</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($businesses)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="bi bi-building fs-1 d-block mb-2"></i>
                                    No businesses found. <a href="?action=add">Create one</a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($businesses as $biz): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($biz['business_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($biz['business_code']); ?></small>
                                        <?php if (!empty($biz['addon_domain'])): ?>
                                            <br><a href="https://<?php echo htmlspecialchars($biz['addon_domain']); ?>" target="_blank" class="text-decoration-none">
                                                <small class="text-primary"><i class="bi bi-globe me-1"></i><?php echo htmlspecialchars($biz['addon_domain']); ?></small>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo ucwords(str_replace('_', ' ', $biz['business_type'])); ?></span>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($biz['database_name']); ?></code>
                                    </td>
                                    <td><?php echo htmlspecialchars($biz['owner_name'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $biz['menu_count']; ?> menus</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo $biz['user_count']; ?> users</span>
                                    </td>
                                    <td class="biz-status-col">
                                        <?php
                                        $bizSlugCheck = !empty($biz['slug']) ? $biz['slug'] : businessCodeToSlug($biz['business_code']);
                                        $bizConfigExists = file_exists(dirname(dirname(__FILE__)) . '/config/businesses/' . $bizSlugCheck . '.php');
                                        if ($biz['is_active'] && $bizConfigExists): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                                        <?php elseif (!$biz['is_active'] && !$bizConfigExists): ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Setup Needed</span>
                                        <?php elseif (!$biz['is_active']): ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">Partial</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $businessSlug = !empty($biz['slug']) ? $biz['slug'] : businessCodeToSlug($biz['business_code']);
                                        $staffLoginUrl = BASE_URL . '/login.php?biz=' . $businessSlug;
                                        ?>
                                        <div class="input-group input-group-sm" style="max-width: 350px;">
                                            <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars($staffLoginUrl); ?>" readonly id="bizLoginLink<?php echo $biz['id']; ?>" style="font-size: 0.75rem;">
                                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyBizLoginLink(<?php echo $biz['id']; ?>)" title="Copy Link">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!$biz['is_active'] || !$bizConfigExists): ?>
                                            <a href="?action=setup&id=<?php echo $biz['id']; ?>&step=2" class="btn btn-sm btn-warning" title="Continue Setup">
                                                <i class="bi bi-gear"></i> Setup
                                            </a>
                                        <?php endif; ?>
                                        <a href="../developer-access.php?dev_access=<?php echo base64_encode($biz['database_name']); ?>"
                                            class="btn btn-sm btn-success" title="Open Business (Developer Access)" target="_blank">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                        <a href="?action=edit&id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="permissions.php?business_id=<?php echo $biz['id']; ?>" class="btn btn-sm btn-outline-info" title="User Permissions">
                                            <i class="bi bi-shield-lock"></i>
                                        </a>
                                        <button onclick="confirmDeleteBusiness('?action=delete&id=<?php echo $biz['id']; ?>', '<?php echo addslashes($biz['business_name']); ?>')"
                                            class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function copyText(text) {
        navigator.clipboard.writeText(text).then(function() {
            const btn = event.target.closest('button');
            if (btn) {
                const orig = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2 text-success"></i>';
                setTimeout(() => {
                    btn.innerHTML = orig;
                }, 1500);
            }
        }).catch(function() {
            // Fallback
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        });
    }

    function copyBizLoginLink(bizId) {
        const input = document.getElementById('bizLoginLink' + bizId);
        copyText(input.value);
    }

    function confirmDeleteBusiness(url, name) {
        Swal.fire({
            title: 'Hapus bisnis "' + name + '"?',
            html: 'Tindakan ini akan <b>ikut menghapus otomatis</b>:<br>' +
                '&bull; Semua akun staff yang terhubung ke bisnis ini (assignment)<br>' +
                '&bull; Semua permission/akses menu yang sudah diatur untuk bisnis ini<br><br>' +
                '<b>Database bisnis TIDAK dihapus</b>, tapi link staff & permission akan hilang dan tidak otomatis kembali. Yakin lanjut?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, hapus bisnis ini',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>