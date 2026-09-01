<?php

/**
 * Database Connection Class
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);
require_once 'config.php';

class Database
{
    private static $instance = null;
    private $connection;
    private static $currentDatabase = null;

    /**
     * Private constructor - Singleton Pattern
     */
    private function __construct($dbName = null)
    {
        try {
            // Get database name from business config if not provided
            if ($dbName === null) {
                if (defined('ACTIVE_BUSINESS_ID')) {
                    // Use business-specific database based on ACTIVE_BUSINESS_ID
                    require_once __DIR__ . '/../includes/business_helper.php';
                    $businessConfig = getActiveBusinessConfig();
                    $dbName = $businessConfig['database'];
                } else {
                    $dbName = DB_NAME;
                }
            }

            // Convert database naming for hosting
            $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
            if ($isProduction) {
                // Map local database names to hosting database names
                $dbMapping = [
                    'adf_system' => 'adfb2574_adf',
                    'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
                    'adf_benscafe' => 'adfb2574_Adf_Bens',
                    'adf_eat_meet' => 'adfb2574_eat_meet',
                    'adf_demo' => 'adfb2574_demo',
                    'adf_cqc' => 'adfb2574_cqc',
                ];

                if (isset($dbMapping[$dbName])) {
                    $dbName = $dbMapping[$dbName];
                } else if (strpos($dbName, 'adf_') === 0) {
                    // Auto-map adf_* -> {cpanel_prefix}_* using the CURRENT hosting
                    // account's DB_USER (e.g. 'adfb2574_adfsystem' -> 'adfb2574_'),
                    // so this resolves correctly on ANY cPanel account/domain, not
                    // just the original adfsystem.online one (needed for Sunsea /
                    // Explore Karimunjawa standalone hosting).
                    $hostingPrefix = 'adfb2574_';
                    if (defined('DB_USER')) {
                        $userParts = explode('_', DB_USER);
                        if (count($userParts) >= 2) {
                            $hostingPrefix = $userParts[0] . '_';
                        }
                    }
                    $dbName = $hostingPrefix . substr($dbName, 4);
                }
            }

            self::$currentDatabase = $dbName;

            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Auto-sync schema once per session for business databases
            $this->autoSyncSchema($dbName);
        } catch (PDOException $e) {
            $errorCode = (string)($e->errorInfo[1] ?? $e->getCode());
            $rawMessage = $e->getMessage();

            // Shared-hosting common case: DB exists but user is not assigned to it.
            if ($errorCode === '1044') {
                $safeDb = htmlspecialchars((string)$dbName, ENT_QUOTES, 'UTF-8');
                $safeUser = htmlspecialchars((string)DB_USER, ENT_QUOTES, 'UTF-8');
                die("Database access denied for business database '<strong>{$safeDb}</strong>'.<br>" .
                    "MySQL user '<strong>{$safeUser}</strong>' belum punya izin ke database tersebut.<br><br>" .
                    "Perbaikan cepat di cPanel:<br>" .
                    "1) Buka <strong>MySQL Databases</strong><br>" .
                    "2) Pada <strong>Add User To Database</strong>, pilih user '<strong>{$safeUser}</strong>' dan DB '<strong>{$safeDb}</strong>'<br>" .
                    "3) Beri privilege <strong>ALL PRIVILEGES</strong><br>" .
                    "4) Kembali ke ADF lalu refresh halaman<br><br>" .
                    "Detail: " . htmlspecialchars($rawMessage, ENT_QUOTES, 'UTF-8'));
            }

            die("Database Connection Error to '{$dbName}': " . $rawMessage);
        }
    }

    /**
     * Auto-sync database schema: ensure all required tables and columns exist.
     * Runs ONCE per session per database to avoid repeated queries.
     */
    private function autoSyncSchema($dbName)
    {
        // Skip for master database — handle it separately
        $masterNames = ['adf_system', 'adfb2574_adf', defined('MASTER_DB_NAME') ? MASTER_DB_NAME : ''];
        $isMaster = in_array($dbName, $masterNames);

        // Only run once per session per database (version bump forces re-check)
        $schemaVersion = 8; // v8: create cash_book in master DB too (businesses sharing master DB need it)
        $sessionKey = '_schema_synced_v' . $schemaVersion . '_' . md5($dbName);
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[$sessionKey])) return;

        try {
            if ($isMaster) {
                // ---- MASTER DB: fix cash_accounts columns ----
                $existingCols = [];
                try {
                    $existingCols = $this->connection->query("SHOW COLUMNS FROM cash_accounts")->fetchAll(PDO::FETCH_COLUMN);
                } catch (PDOException $e) { /* table may not exist */
                }

                if (!empty($existingCols)) {
                    $masterCols = [
                        'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
                        'description' => "TEXT NULL",
                        'is_default_account' => "TINYINT(1) NOT NULL DEFAULT 0",
                        'current_balance' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
                        'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ];
                    foreach ($masterCols as $col => $def) {
                        if (!in_array($col, $existingCols)) {
                            try {
                                $this->connection->exec("ALTER TABLE cash_accounts ADD COLUMN `$col` $def");
                            } catch (PDOException $e) {
                            }
                        }
                    }
                }

                try {
                    $menuSeeds = [
                        ['gudang_nasita', 'Gudang Nasita', 'bi bi-boxes', 'modules/procurement/gudang-nasita.php', 8],
                        ['warehouse', 'Gudang Nasita (Legacy)', 'bi bi-boxes', 'modules/procurement/gudang-nasita.php', 9],
                        ['warehouse_transfers', 'Transfer Gudang', 'bi bi-arrow-left-right', 'modules/procurement/gudang-transfer.php', 10],
                    ];
                    $menuStmt = $this->connection->prepare(
                        "INSERT INTO menu_items (menu_code, menu_name, menu_icon, menu_url, menu_order, is_active) VALUES (?, ?, ?, ?, ?, 1)\n                         ON DUPLICATE KEY UPDATE menu_name = VALUES(menu_name), menu_icon = VALUES(menu_icon), menu_url = VALUES(menu_url), menu_order = VALUES(menu_order), is_active = VALUES(is_active)"
                    );
                    foreach ($menuSeeds as $menuSeed) {
                        $menuStmt->execute($menuSeed);
                    }

                    // Enable Gudang Nasita menu only for target businesses.
                    $menuIdRow = $this->connection->query("SELECT id FROM menu_items WHERE menu_code = 'gudang_nasita' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if (!empty($menuIdRow['id'])) {
                        $targetCodes = ['narayanahotel', 'benscafe', 'eatmeet', 'eaatmeet'];
                        $bizRows = $this->connection->query("SELECT id, business_code, business_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
                        $cfgStmt = $this->connection->prepare(
                            "INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE is_enabled = 1"
                        );
                        foreach ($bizRows as $biz) {
                            $codeNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$biz['business_code']));
                            $nameNorm = strtolower((string)($biz['business_name'] ?? ''));
                            $isTarget = in_array($codeNorm, $targetCodes, true)
                                || strpos($nameNorm, 'narayana') !== false
                                || strpos($nameNorm, 'bens') !== false
                                || strpos($nameNorm, 'eat') !== false;
                            if ($isTarget) {
                                $cfgStmt->execute([(int)$biz['id'], (int)$menuIdRow['id']]);
                            }
                        }
                    }
                } catch (PDOException $e) {
                }

                // Some businesses (e.g. Gudang Nasita) share this master database.
                // cash_book is normally only created in the non-master branch below,
                // so ensure it exists here too (safe/idempotent, no data loss risk).
                try {
                    $this->connection->exec("CREATE TABLE IF NOT EXISTS cash_book (
                        id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), transaction_date DATE NOT NULL, transaction_time TIME,
                        division_id INT, category_id INT, category_name VARCHAR(100), description TEXT,
                        transaction_type ENUM('income','expense') NOT NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                        payment_method VARCHAR(30) DEFAULT 'cash', cash_account_id INT, notes TEXT,
                        attachment VARCHAR(255), created_by INT, shift VARCHAR(20),
                        source_type VARCHAR(30) DEFAULT 'manual', source_id INT NULL, reference_no VARCHAR(50) NULL,
                        is_editable TINYINT(1) DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    )");
                } catch (PDOException $e) {
                }
            } else {
                // Get existing tables
                $tables = $this->connection->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

                // ============================================================
                // Required tables — CREATE IF NOT EXISTS
                // Column names MUST match what the PHP code actually uses!
                // ============================================================
                $requiredTables = [
                    'settings' => "CREATE TABLE IF NOT EXISTS settings (
                    id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT,
                    setting_type VARCHAR(20) DEFAULT 'string', description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'divisions' => "CREATE TABLE IF NOT EXISTS divisions (
                    id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_code VARCHAR(20), division_name VARCHAR(100) NOT NULL,
                    description TEXT, division_type ENUM('income','expense','both') DEFAULT 'both', is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'categories' => "CREATE TABLE IF NOT EXISTS categories (
                    id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), division_id INT, category_name VARCHAR(100) NOT NULL,
                    category_type ENUM('income','expense') DEFAULT 'income', description TEXT, is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'cash_book' => "CREATE TABLE IF NOT EXISTS cash_book (
                    id INT AUTO_INCREMENT PRIMARY KEY, branch_id VARCHAR(50), transaction_date DATE NOT NULL, transaction_time TIME,
                    division_id INT, category_id INT, category_name VARCHAR(100), description TEXT,
                    transaction_type ENUM('income','expense') NOT NULL, amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                    payment_method VARCHAR(30) DEFAULT 'cash', cash_account_id INT, notes TEXT,
                    attachment VARCHAR(255), created_by INT, shift VARCHAR(20),
                    source_type VARCHAR(30) DEFAULT 'manual', source_id INT NULL, reference_no VARCHAR(50) NULL,
                    is_editable TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'users' => "CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL,
                    full_name VARCHAR(100), email VARCHAR(100), phone VARCHAR(20),
                    role ENUM('owner','admin','manager','frontdesk','cashier','accountant','staff') DEFAULT 'staff',
                    role_id INT, business_access TEXT, is_active TINYINT(1) DEFAULT 1,
                    last_login DATETIME, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'roles' => "CREATE TABLE IF NOT EXISTS roles (
                    id INT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(50) NOT NULL, role_code VARCHAR(30),
                    description TEXT, is_system_role TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                    'user_preferences' => "CREATE TABLE IF NOT EXISTS user_preferences (
                    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, branch_id VARCHAR(50),
                    theme VARCHAR(20) DEFAULT 'dark', language VARCHAR(5) DEFAULT 'id', sidebar_collapsed TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'user_permissions' => "CREATE TABLE IF NOT EXISTS user_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, permission VARCHAR(50) NOT NULL,
                    can_view TINYINT(1) DEFAULT 1, can_create TINYINT(1) DEFAULT 0, can_edit TINYINT(1) DEFAULT 0, can_delete TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                    'audit_logs' => "CREATE TABLE IF NOT EXISTS audit_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action_type VARCHAR(50),
                    table_name VARCHAR(50), record_id INT, old_values TEXT, new_values TEXT,
                    ip_address VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                    'suppliers' => "CREATE TABLE IF NOT EXISTS suppliers (
                    id INT AUTO_INCREMENT PRIMARY KEY, supplier_name VARCHAR(100) NOT NULL, contact_person VARCHAR(100),
                    phone VARCHAR(20), email VARCHAR(100), address TEXT, is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'purchase_orders_header' => "CREATE TABLE IF NOT EXISTS purchase_orders_header (
                    id INT AUTO_INCREMENT PRIMARY KEY, business_id INT NULL, po_number VARCHAR(30) UNIQUE, supplier_id INT,
                    po_date DATE NOT NULL, delivery_date DATE, status ENUM('draft','sent','received','cancelled') DEFAULT 'draft',
                    total_amount DECIMAL(15,2) DEFAULT 0, notes TEXT, created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'purchase_orders_detail' => "CREATE TABLE IF NOT EXISTS purchase_orders_detail (
                    id INT AUTO_INCREMENT PRIMARY KEY, po_header_id INT, item_name VARCHAR(200),
                    quantity DECIMAL(10,2) DEFAULT 1, unit VARCHAR(20), unit_price DECIMAL(15,2) DEFAULT 0,
                    total_price DECIMAL(15,2) DEFAULT 0, notes TEXT
                )",
                    'gudang_nasita_stock' => "CREATE TABLE IF NOT EXISTS gudang_nasita_stock (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    stock_code VARCHAR(30) UNIQUE,
                    item_name VARCHAR(200) NOT NULL,
                    category VARCHAR(80) DEFAULT 'lainnya',
                    unit VARCHAR(20) DEFAULT 'pcs',
                    quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
                    reorder_level DECIMAL(15,2) DEFAULT 0,
                    supplier_name VARCHAR(150) NULL,
                    notes TEXT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_item_name (item_name),
                    INDEX idx_stock_code (stock_code),
                    INDEX idx_is_active (is_active)
                )",
                    'gudang_nasita_movements' => "CREATE TABLE IF NOT EXISTS gudang_nasita_movements (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    stock_id INT NOT NULL,
                    movement_date DATE NOT NULL,
                    movement_type ENUM('in_supplier','out_transfer','adjustment') NOT NULL,
                    quantity DECIMAL(15,2) NOT NULL,
                    reference_type VARCHAR(50) NULL,
                    reference_id INT NULL,
                    reference_number VARCHAR(50) NULL,
                    target_business_id INT NULL,
                    notes TEXT NULL,
                    created_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_stock_id (stock_id),
                    INDEX idx_movement_date (movement_date),
                    INDEX idx_reference (reference_type, reference_id),
                    INDEX idx_target_business (target_business_id)
                )",
                    'gudang_nasita_transfers' => "CREATE TABLE IF NOT EXISTS gudang_nasita_transfers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    transfer_number VARCHAR(50) UNIQUE,
                    target_business_id INT NOT NULL,
                    target_business_name VARCHAR(150) NOT NULL,
                    source_po_id INT NULL,
                    status ENUM('draft','sent','received','cancelled') DEFAULT 'draft',
                    notes TEXT NULL,
                    created_by INT NULL,
                    received_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_target_business (target_business_id),
                    INDEX idx_status (status),
                    INDEX idx_source_po (source_po_id)
                )",
                    'gudang_nasita_transfer_items' => "CREATE TABLE IF NOT EXISTS gudang_nasita_transfer_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    transfer_id INT NOT NULL,
                    stock_id INT NOT NULL,
                    item_name VARCHAR(200) NOT NULL,
                    unit VARCHAR(20) DEFAULT 'pcs',
                    quantity DECIMAL(15,2) NOT NULL,
                    notes TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_transfer_id (transfer_id),
                    INDEX idx_stock_id (stock_id)
                )",
                    'sales_invoices_header' => "CREATE TABLE IF NOT EXISTS sales_invoices_header (
                    id INT AUTO_INCREMENT PRIMARY KEY, invoice_number VARCHAR(30) UNIQUE, customer_name VARCHAR(100),
                    invoice_date DATE NOT NULL, due_date DATE, status ENUM('draft','sent','paid','cancelled') DEFAULT 'draft',
                    total_amount DECIMAL(15,2) DEFAULT 0, notes TEXT, created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'sales_invoices_detail' => "CREATE TABLE IF NOT EXISTS sales_invoices_detail (
                    id INT AUTO_INCREMENT PRIMARY KEY, invoice_header_id INT, item_name VARCHAR(200),
                    quantity DECIMAL(10,2) DEFAULT 1, unit VARCHAR(20), unit_price DECIMAL(15,2) DEFAULT 0,
                    total_price DECIMAL(15,2) DEFAULT 0, notes TEXT
                )",
                    'bill_templates' => "CREATE TABLE IF NOT EXISTS bill_templates (
                    id INT AUTO_INCREMENT PRIMARY KEY, template_name VARCHAR(100), description TEXT,
                    amount DECIMAL(15,2) DEFAULT 0, frequency ENUM('monthly','weekly','yearly','once') DEFAULT 'monthly',
                    is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                    'bill_records' => "CREATE TABLE IF NOT EXISTS bill_records (
                    id INT AUTO_INCREMENT PRIMARY KEY, template_id INT, bill_date DATE, amount DECIMAL(15,2),
                    status ENUM('pending','paid','overdue') DEFAULT 'pending', paid_date DATE, notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                    'transaction_attachments' => "CREATE TABLE IF NOT EXISTS transaction_attachments (
                    id INT AUTO_INCREMENT PRIMARY KEY, transaction_id INT, transaction_type VARCHAR(20),
                    file_name VARCHAR(255), file_path VARCHAR(500), file_type VARCHAR(50), file_size INT,
                    uploaded_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                    // ========== FRONTDESK TABLES ==========
                    // Column names match what modules/frontdesk/*.php code actually uses
                    'room_types' => "CREATE TABLE IF NOT EXISTS room_types (
                    id INT AUTO_INCREMENT PRIMARY KEY, type_name VARCHAR(100) NOT NULL,
                    base_price DECIMAL(12,2) DEFAULT 0, max_occupancy INT DEFAULT 2,
                    description TEXT, amenities TEXT, color_code VARCHAR(7) DEFAULT '#6366f1',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'rooms' => "CREATE TABLE IF NOT EXISTS rooms (
                    id INT AUTO_INCREMENT PRIMARY KEY, room_number VARCHAR(20) NOT NULL,
                    room_type_id INT, floor_number INT DEFAULT 1,
                    status VARCHAR(20) DEFAULT 'available', current_guest_id INT NULL,
                    notes TEXT, position_x INT DEFAULT 0, position_y INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'guests' => "CREATE TABLE IF NOT EXISTS guests (
                    id INT AUTO_INCREMENT PRIMARY KEY, guest_name VARCHAR(200) NOT NULL,
                    id_card_type VARCHAR(20) DEFAULT 'ktp', id_card_number VARCHAR(50),
                    phone VARCHAR(20), email VARCHAR(100), address TEXT,
                    nationality VARCHAR(50) DEFAULT 'Indonesia',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'bookings' => "CREATE TABLE IF NOT EXISTS bookings (
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
                )",
                    'booking_payments' => "CREATE TABLE IF NOT EXISTS booking_payments (
                    id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL,
                    amount DECIMAL(12,2) NOT NULL, payment_method VARCHAR(50) DEFAULT 'cash',
                    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                    reference_number VARCHAR(100), notes TEXT,
                    processed_by INT, created_by INT,
                    synced_to_cashbook TINYINT(1) DEFAULT 0, cashbook_id INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                    'breakfast_menus' => "CREATE TABLE IF NOT EXISTS breakfast_menus (
                    id INT AUTO_INCREMENT PRIMARY KEY, menu_name VARCHAR(100) NOT NULL,
                    description TEXT, category VARCHAR(30) DEFAULT 'indonesian',
                    price DECIMAL(10,2) DEFAULT 0.00,
                    is_free TINYINT(1) DEFAULT 1, is_available TINYINT(1) DEFAULT 1,
                    image_url VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'breakfast_orders' => "CREATE TABLE IF NOT EXISTS breakfast_orders (
                    id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NULL,
                    guest_name VARCHAR(100), room_number VARCHAR(20),
                    total_pax INT DEFAULT 1, breakfast_time TIME,
                    breakfast_date DATE, location VARCHAR(20) DEFAULT 'restaurant',
                    menu_items TEXT, special_requests TEXT,
                    total_price DECIMAL(10,2) DEFAULT 0.00,
                    order_status VARCHAR(20) DEFAULT 'pending',
                    created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'breakfast_log' => "CREATE TABLE IF NOT EXISTS breakfast_log (
                    id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, guest_id INT,
                    menu_id INT NULL, quantity INT DEFAULT 1, date DATE NOT NULL,
                    status VARCHAR(20) DEFAULT 'taken', marked_by INT,
                    marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, notes VARCHAR(255)
                )",
                    'frontdesk_rooms' => "CREATE TABLE IF NOT EXISTS frontdesk_rooms (
                    id INT AUTO_INCREMENT PRIMARY KEY, room_number VARCHAR(10), room_type VARCHAR(50),
                    floor INT DEFAULT 1, price DECIMAL(15,2) DEFAULT 0, status VARCHAR(20) DEFAULT 'available',
                    guest_name VARCHAR(100), check_in DATE, check_out DATE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    // ========== INVESTOR TABLES ==========
                    'investors' => "CREATE TABLE IF NOT EXISTS investors (
                    id INT AUTO_INCREMENT PRIMARY KEY, investor_name VARCHAR(100) NOT NULL, phone VARCHAR(20),
                    email VARCHAR(100), address TEXT, notes TEXT, is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                    'investor_transactions' => "CREATE TABLE IF NOT EXISTS investor_transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, transaction_type ENUM('investment','return','dividend'),
                    amount DECIMAL(15,2), transaction_date DATE, notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                    'investor_balances' => "CREATE TABLE IF NOT EXISTS investor_balances (
                    id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, balance DECIMAL(15,2) DEFAULT 0,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )",
                    'investor_bills' => "CREATE TABLE IF NOT EXISTS investor_bills (
                    id INT AUTO_INCREMENT PRIMARY KEY, investor_id INT, bill_name VARCHAR(100),
                    amount DECIMAL(15,2), due_date DATE, status ENUM('pending','paid','overdue') DEFAULT 'pending',
                    paid_date DATE, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                ];

                foreach ($requiredTables as $tbl => $sql) {
                    if (!in_array($tbl, $tables)) {
                        try {
                            $this->connection->exec($sql);
                        } catch (PDOException $e) {
                        }
                    }
                }

                // ============================================================
                // Required columns on EXISTING tables (ALTER TABLE ADD)
                // Covers columns that may be missing from older databases
                // ============================================================
                $requiredColumns = [
                    'settings' => [
                        'setting_type' => "VARCHAR(20) DEFAULT 'string'",
                        'description' => "TEXT NULL",
                        'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ],
                    'cash_book' => [
                        'cash_account_id' => "INT NULL",
                        'shift' => "VARCHAR(20) NULL",
                        'branch_id' => "VARCHAR(50) NULL",
                        'transaction_time' => "TIME NULL",
                        'notes' => "TEXT NULL",
                        'attachment' => "VARCHAR(255) NULL",
                        'source_type' => "VARCHAR(30) DEFAULT 'manual'",
                        'source_id' => "INT NULL",
                        'reference_no' => "VARCHAR(50) NULL",
                        'is_editable' => "TINYINT(1) DEFAULT 1",
                        'category_name' => "VARCHAR(100) NULL",
                    ],
                    'divisions' => [
                        'branch_id' => "VARCHAR(50) NULL",
                        'division_code' => "VARCHAR(20) NULL",
                        'division_type' => "ENUM('income','expense','both') DEFAULT 'both'",
                    ],
                    'categories' => [
                        'branch_id' => "VARCHAR(50) NULL",
                    ],
                    'users' => [
                        'role_id' => "INT NULL",
                        'business_access' => "TEXT NULL",
                        'last_login' => "DATETIME NULL",
                        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ],
                    'purchase_orders_header' => [
                        'business_id' => "INT NULL",
                    ],
                    'gudang_nasita_stock' => [
                        'category' => "VARCHAR(80) DEFAULT 'lainnya'",
                    ],
                    // ---- FRONTDESK table columns ----
                    'room_types' => [
                        'color_code' => "VARCHAR(7) DEFAULT '#6366f1'",
                        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ],
                    'rooms' => [
                        'floor_number' => "INT DEFAULT 1",
                        'current_guest_id' => "INT NULL",
                        'notes' => "TEXT NULL",
                        'position_x' => "INT DEFAULT 0",
                        'position_y' => "INT DEFAULT 0",
                        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ],
                    'guests' => [
                        'guest_name' => "VARCHAR(200) NOT NULL DEFAULT ''",
                        'id_card_type' => "VARCHAR(20) DEFAULT 'ktp'",
                        'id_card_number' => "VARCHAR(50) NULL",
                        'phone' => "VARCHAR(20) NULL",
                        'email' => "VARCHAR(100) NULL",
                        'address' => "TEXT NULL",
                        'nationality' => "VARCHAR(50) DEFAULT 'Indonesia'",
                        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ],
                    'bookings' => [
                        'booking_code' => "VARCHAR(20) NULL",
                        'guest_id' => "INT NULL",
                        'room_id' => "INT NULL",
                        'check_in_date' => "DATE NULL",
                        'check_out_date' => "DATE NULL",
                        'total_nights' => "INT DEFAULT 1",
                        'room_price' => "DECIMAL(12,2) DEFAULT 0",
                        'total_price' => "DECIMAL(12,2) DEFAULT 0",
                        'discount' => "DECIMAL(12,2) DEFAULT 0",
                        'final_price' => "DECIMAL(12,2) DEFAULT 0",
                        'paid_amount' => "DECIMAL(12,2) DEFAULT 0",
                        'adults' => "INT DEFAULT 1",
                        'children' => "INT DEFAULT 0",
                        'payment_status' => "VARCHAR(20) DEFAULT 'unpaid'",
                        'booking_source' => "VARCHAR(50) DEFAULT 'walk_in'",
                        'special_request' => "TEXT NULL",
                        'guest_count' => "INT DEFAULT 1",
                        'actual_checkin_time' => "DATETIME NULL",
                        'actual_checkout_time' => "DATETIME NULL",
                        'notes' => "TEXT NULL",
                        'created_by' => "INT NULL",
                    ],
                    'booking_payments' => [
                        'booking_id' => "INT NOT NULL",
                        'amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0",
                        'payment_method' => "VARCHAR(50) DEFAULT 'cash'",
                        'payment_date' => "DATETIME NULL",
                        'reference_number' => "VARCHAR(100) NULL",
                        'notes' => "TEXT NULL",
                        'processed_by' => "INT NULL",
                        'created_by' => "INT NULL",
                        'synced_to_cashbook' => "TINYINT(1) DEFAULT 0",
                        'cashbook_id' => "INT NULL",
                    ],
                    'breakfast_menus' => [
                        'category' => "VARCHAR(30) DEFAULT 'indonesian'",
                        'price' => "DECIMAL(10,2) DEFAULT 0.00",
                        'is_free' => "TINYINT(1) DEFAULT 1",
                        'is_available' => "TINYINT(1) DEFAULT 1",
                        'image_url' => "VARCHAR(255) NULL",
                        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ],
                    'breakfast_orders' => [
                        'booking_id' => "INT NULL",
                        'guest_name' => "VARCHAR(100) NULL",
                        'room_number' => "VARCHAR(20) NULL",
                        'total_pax' => "INT DEFAULT 1",
                        'breakfast_time' => "TIME NULL",
                        'breakfast_date' => "DATE NULL",
                        'location' => "VARCHAR(20) DEFAULT 'restaurant'",
                        'menu_items' => "TEXT NULL",
                        'special_requests' => "TEXT NULL",
                        'total_price' => "DECIMAL(10,2) DEFAULT 0.00",
                        'order_status' => "VARCHAR(20) DEFAULT 'pending'",
                        'created_by' => "INT NULL",
                        'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ],
                ];

                foreach ($requiredColumns as $tbl => $cols) {
                    if (!in_array($tbl, $tables)) continue; // newly created tables already have all cols

                    $existingCols = $this->connection->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($cols as $col => $def) {
                        if (!in_array($col, $existingCols)) {
                            try {
                                $this->connection->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
                            } catch (PDOException $e) {
                                // Column might already exist under race condition, ignore
                            }
                        }
                    }
                }
            } // end else (business DB)

            // Mark as synced for this session
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[$sessionKey] = time();
            }
        } catch (PDOException $e) {
            error_log("autoSyncSchema error for $dbName: " . $e->getMessage());
        }
    }

    /**
     * Get Database Instance
     * @param bool $forceNew Force new connection (for switching databases)
     */
    public static function getInstance($forceNew = false)
    {
        if (self::$instance === null || $forceNew) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get current database name
     */
    public static function getCurrentDatabase()
    {
        return self::$currentDatabase;
    }

    /**
     * Switch to different database
     * @param string $dbName Database name to switch to
     */
    public static function switchDatabase($dbName)
    {
        self::$instance = new self($dbName);
        return self::$instance;
    }

    /**
     * Get PDO Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserializing
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Execute Query
     */
    public function query($sql, $params = [])
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch Single Row
     */
    public function fetchOne($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetch() : false;
    }

    /**
     * Fetch All Rows
     */
    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Insert Data
     */
    public function insert($table, $data)
    {
        $fields = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($data);
            return $this->connection->lastInsertId();
        } catch (PDOException $e) {
            error_log("Insert Error in table {$table}: " . $e->getMessage());
            error_log("SQL: " . $sql);
            error_log("Data: " . print_r($data, true));
            throw $e; // Re-throw untuk ditangkap di level atas
        }
    }

    /**
     * Update Data
     */
    public function update($table, $data, $where, $whereParams = [])
    {
        $fields = [];
        foreach (array_keys($data) as $field) {
            $fields[] = "{$field} = :{$field}";
        }
        $fields = implode(', ', $fields);
        $sql = "UPDATE {$table} SET {$fields} WHERE {$where}";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute(array_merge($data, $whereParams));
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Update Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete Data
     */
    public function delete($table, $where, $params = [])
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Delete Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Begin Transaction
     */
    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    /**
     * Check if in transaction
     */
    public function inTransaction()
    {
        return $this->connection->inTransaction();
    }

    /**
     * Commit Transaction
     */
    public function commit()
    {
        return $this->connection->commit();
    }

    /**
     * Rollback Transaction
     */
    public function rollback()
    {
        return $this->connection->rollBack();
    }
}
