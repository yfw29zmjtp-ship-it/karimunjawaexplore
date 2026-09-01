<?php

function getPwfOfficePdo(): PDO
{
    $config = function_exists('getActiveBusinessConfig') ? getActiveBusinessConfig() : [];
    $dbName = $config['database'] ?? 'adf_pwf';

    if (function_exists('getDbName')) {
        $dbName = getDbName($dbName);
    }

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    ensurePwfOfficeTables($pdo);
    return $pdo;
}

function ensurePwfOfficeTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_code VARCHAR(30) UNIQUE,
        customer_name VARCHAR(150) NOT NULL,
        phone VARCHAR(50) NULL,
        address TEXT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_buyers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        buyer_code VARCHAR(30) UNIQUE,
        buyer_name VARCHAR(150) NOT NULL,
        contact_person VARCHAR(150) NULL,
        email VARCHAR(150) NULL,
        phone VARCHAR(50) NULL,
        notes TEXT NULL,
        access_key VARCHAR(80) UNIQUE,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_buyer_name (buyer_name),
        INDEX idx_access_key (access_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_buyer_customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        buyer_id INT NOT NULL,
        customer_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_buyer_customer (buyer_id, customer_id),
        INDEX idx_buyer (buyer_id),
        INDEX idx_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_craftsmen (
        id INT AUTO_INCREMENT PRIMARY KEY,
        craftsman_code VARCHAR(30) UNIQUE,
        craftsman_name VARCHAR(150) NOT NULL,
        phone VARCHAR(50) NULL,
        specialty VARCHAR(100) NULL,
        is_active TINYINT(1) DEFAULT 1,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(30) UNIQUE,
        customer_id INT NOT NULL,
        order_date DATE NOT NULL,
        due_date DATE NULL,
        product_name VARCHAR(200) NOT NULL,
        specification TEXT NULL,
        dimensions VARCHAR(120) NULL,
        quantity DECIMAL(10,2) DEFAULT 1,
        unit VARCHAR(20) DEFAULT 'pcs',
        image_path VARCHAR(255) NULL,
        assigned_craftsman_id INT NULL,
        progress_percent INT DEFAULT 0,
        status ENUM('draft','on_progress','qc','ready_ship','shipped','completed','cancelled') DEFAULT 'draft',
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_customer (customer_id),
        INDEX idx_craftsman (assigned_craftsman_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Alter pwf_orders - add pricing & detail columns
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN unit_price DECIMAL(15,2) DEFAULT 0");
    } catch (\PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN total_price DECIMAL(15,2) DEFAULT 0");
    } catch (\PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN wood_color VARCHAR(100) NULL");
    } catch (\PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN finish VARCHAR(100) NULL");
    } catch (\PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN description TEXT NULL");
    } catch (\PDOException $e) {
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_order_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        craftsman_id INT NULL,
        progress_date DATE NOT NULL,
        achievement_percent INT DEFAULT 0,
        work_note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_shipments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        shipment_date DATE NOT NULL,
        container_type ENUM('20ft','40ft','40hc','lcl') DEFAULT '20ft',
        destination_country VARCHAR(100) NULL,
        destination_port VARCHAR(100) NULL,
        forwarder VARCHAR(150) NULL,
        tracking_no VARCHAR(100) NULL,
        status ENUM('draft','booked','onboard','arrived','closed') DEFAULT 'draft',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // qty_done migration — add column if not exists
    try {
        $pdo->exec("ALTER TABLE pwf_orders ADD COLUMN qty_done DECIMAL(10,2) NOT NULL DEFAULT 0");
    } catch (\PDOException $e) {
    }

    // partial_ship status migration — extend ENUM if not already
    try {
        $pdo->exec("ALTER TABLE pwf_orders MODIFY COLUMN status ENUM('draft','on_progress','qc','ready_ship','partial_ship','shipped','completed','cancelled') DEFAULT 'draft'");
    } catch (\PDOException $e) {
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_containers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        container_code VARCHAR(30) UNIQUE,
        container_no VARCHAR(50) NULL,
        container_type ENUM('20ft','40ft','40hc','lcl') DEFAULT '20ft',
        shipment_date DATE NOT NULL,
        destination_country VARCHAR(100) NULL,
        destination_port VARCHAR(100) NULL,
        forwarder VARCHAR(150) NULL,
        tracking_no VARCHAR(100) NULL,
        bl_no VARCHAR(100) NULL,
        status ENUM('draft','booked','onboard','arrived','closed') DEFAULT 'draft',
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_container_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        container_id INT NOT NULL,
        order_id INT NOT NULL,
        qty_shipped DECIMAL(10,2) DEFAULT 0,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_container (container_id),
        INDEX idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // SPK (Surat Perintah Kerja - Production Order) table
    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_spk (
        id INT AUTO_INCREMENT PRIMARY KEY,
        spk_no VARCHAR(50) UNIQUE,
        order_id INT NOT NULL,
        craftsman_id INT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        status ENUM('draft','assigned','in_progress','completed','cancelled') DEFAULT 'draft',
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_order (order_id),
        INDEX idx_craftsman (craftsman_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Warehouse Stock table
    $pdo->exec("CREATE TABLE IF NOT EXISTS pwf_warehouse_stock (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stock_code VARCHAR(30) UNIQUE,
        product_name VARCHAR(200) NOT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
        unit VARCHAR(20) DEFAULT 'pcs',
        finish VARCHAR(100) NULL,
        wood_color VARCHAR(100) NULL,
        dimensions VARCHAR(120) NULL,
        specification TEXT NULL,
        image_path VARCHAR(255) NULL,
        notes TEXT NULL,
        order_id INT NULL,
        source ENUM('manual','from_order_failed') DEFAULT 'manual',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_product_name (product_name),
        INDEX idx_order (order_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Check whether the current logged-in PWF user is allowed to perform an
 * action ('view'|'create'|'edit'|'delete') on a given menu code (e.g. 'pwf_warehouse').
 * Owner/developer always pass. If the user has NO permission rows configured
 * at all (default/legacy accounts), access is allowed (matches layout.php's
 * sidebar filter, which only restricts once at least one permission row exists).
 */
function pwfUserHasAccess(string $menuCode, string $action = 'view'): bool
{
    static $cache = [];

    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['owner', 'developer'], true)) {
        return true;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return false;
    }

    $cacheKey = $userId . '|' . $menuCode;
    if (!isset($cache[$cacheKey])) {
        $default = ['view' => true, 'create' => true, 'edit' => true, 'delete' => true];
        try {
            $masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $pwfBiz = $masterPdo->query("SELECT id FROM businesses WHERE business_name LIKE '%PWF%' OR business_name LIKE '%Prapen%' LIMIT 1")->fetch();
            $pwfBizId = $pwfBiz['id'] ?? null;

            $countStmt = $masterPdo->prepare("SELECT COUNT(*) FROM user_menu_permissions WHERE user_id = ? AND business_id = ?");
            $countStmt->execute([$userId, $pwfBizId]);
            $hasAnyRestriction = ((int)$countStmt->fetchColumn()) > 0;

            if (!$hasAnyRestriction) {
                $cache[$cacheKey] = $default;
            } else {
                $stmt = $masterPdo->prepare(
                    "SELECT ump.can_view, ump.can_create, ump.can_edit, ump.can_delete
                     FROM user_menu_permissions ump
                     INNER JOIN menu_items mi ON ump.menu_id = mi.id
                     WHERE ump.user_id = ? AND ump.business_id = ? AND mi.menu_code = ?"
                );
                $stmt->execute([$userId, $pwfBizId, $menuCode]);
                $row = $stmt->fetch();
                $cache[$cacheKey] = [
                    'view'   => (bool)($row['can_view'] ?? false),
                    'create' => (bool)($row['can_create'] ?? false),
                    'edit'   => (bool)($row['can_edit'] ?? false),
                    'delete' => (bool)($row['can_delete'] ?? false),
                ];
            }
        } catch (\Throwable $e) {
            $cache[$cacheKey] = $default;
        }
    }

    return $cache[$cacheKey][$action] ?? false;
}

/**
 * Return the relative URL (inside modules/pwf-office/) the currently logged-in
 * PWF user should land on right after login, based on their menu permissions.
 * Owner/developer and users with no permission rows (default full access) land
 * on dashboard.php as before. Restricted users land on the first menu (in the
 * standard sidebar order) they actually have can_view access to.
 */
function pwfGetDefaultLandingUrl(): string
{
    $default = 'dashboard.php';

    $order = [
        'dashboard'    => 'dashboard.php',
        'orders'       => 'orders.php',
        'progress'     => 'progress.php',
        'warehouse'    => 'warehouse.php',
        'shipping'     => 'shipping.php',
        'rekap-order'  => 'rekap-order.php',
        'buyers'       => 'buyers.php',
        'customers'    => 'customers.php',
        'craftsmen'    => 'craftsmen.php',
        'containers'   => 'db-containers.php',
        'settings-main' => 'settings.php',
        'manage'       => 'manage/index.php',
    ];

    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['owner', 'developer'], true)) {
        return $default;
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return $default;
    }

    try {
        $masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $pwfBiz = $masterPdo->query("SELECT id FROM businesses WHERE business_name LIKE '%PWF%' OR business_name LIKE '%Prapen%' LIMIT 1")->fetch();
        $pwfBizId = $pwfBiz['id'] ?? null;
        if (!$pwfBizId) {
            return $default;
        }

        $stmt = $masterPdo->prepare("
            SELECT DISTINCT mi.menu_code
            FROM user_menu_permissions ump
            INNER JOIN menu_items mi ON ump.menu_id = mi.id
            WHERE ump.user_id = ? AND ump.business_id = ? AND ump.can_view = 1
        ");
        $stmt->execute([$userId, $pwfBizId]);
        $allowedCodes = [];
        foreach ($stmt->fetchAll() as $row) {
            $allowedCodes[] = preg_replace('/^pwf_/', '', strtolower($row['menu_code']));
        }

        // No permission rows at all -> default/legacy full access -> dashboard
        if (empty($allowedCodes)) {
            return $default;
        }

        $codeMap = ['rekap' => 'rekap-order'];
        $mapped = [];
        foreach ($allowedCodes as $code) {
            $mapped[] = $codeMap[$code] ?? $code;
        }
        $allowedCodes = $mapped;

        foreach ($order as $key => $url) {
            if (in_array($key, $allowedCodes, true)) {
                return $url;
            }
        }
    } catch (\Throwable $e) {
        return $default;
    }

    return $default;
}

function genPwfCode(PDO $pdo, string $prefix): string
{
    $yearMonth = date('Ym');
    $table = 'pwf_orders';
    $column = 'order_code';

    if ($prefix === 'CUS') {
        $table = 'pwf_customers';
        $column = 'customer_code';
    } elseif ($prefix === 'BUY') {
        $table = 'pwf_buyers';
        $column = 'buyer_code';
    } elseif ($prefix === 'TKG') {
        $table = 'pwf_craftsmen';
        $column = 'craftsman_code';
    } elseif ($prefix === 'STK') {
        $table = 'pwf_warehouse_stock';
        $column = 'stock_code';
    }

    $like = $prefix . '-' . $yearMonth . '-%';
    // Use the highest existing sequence number (not COUNT) so deleted rows
    // don't cause duplicate codes. SUBSTRING_INDEX grabs the numeric suffix.
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX({$column}, '-', -1) AS UNSIGNED))
         FROM {$table} WHERE {$column} LIKE ?"
    );
    $stmt->execute([$like]);
    $next = (int)$stmt->fetchColumn() + 1;

    return sprintf('%s-%s-%03d', $prefix, $yearMonth, $next);
}
