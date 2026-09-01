<?php
/**
 * Production Module - Database Helper
 * Auto-creates all required tables on first use
 */

function getProductionPdo() {
    $config = getActiveBusinessConfig();
    $dbName = $config['database'] ?? 'adf_pwf';

    // Map local db name to production name if on hosting
    if (function_exists('getDbName')) {
        $dbName = getDbName($dbName);
    }

    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    ensureProductionTables($pdo);
    return $pdo;
}

function ensureProductionTables(PDO $pdo): void {
    // Sales Orders
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sales_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(30) NOT NULL UNIQUE,
            customer_name VARCHAR(150) NOT NULL,
            customer_contact VARCHAR(100) NULL,
            customer_address TEXT NULL,
            total_amount DECIMAL(15,2) DEFAULT 0,
            dp_amount DECIMAL(15,2) DEFAULT 0,
            dp_paid TINYINT(1) DEFAULT 0,
            status ENUM('quotation','confirmed','in_production','ready','delivered','cancelled') DEFAULT 'quotation',
            notes TEXT NULL,
            order_date DATE NOT NULL,
            delivery_date DATE NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Sales Order Items
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sales_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            description TEXT NULL,
            quantity DECIMAL(10,2) DEFAULT 1,
            unit VARCHAR(20) DEFAULT 'pcs',
            unit_price DECIMAL(15,2) DEFAULT 0,
            subtotal DECIMAL(15,2) DEFAULT 0,
            FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Production Orders
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS production_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(30) NOT NULL UNIQUE,
            sales_order_id INT NULL,
            product_name VARCHAR(200) NOT NULL,
            product_type VARCHAR(100) NULL,
            quantity DECIMAL(10,2) DEFAULT 1,
            unit VARCHAR(20) DEFAULT 'pcs',
            status ENUM('pending','in_production','quality_check','completed','cancelled') DEFAULT 'pending',
            priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
            notes TEXT NULL,
            due_date DATE NULL,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Materials (Stok Bahan Baku)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS production_materials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            material_code VARCHAR(50) NOT NULL UNIQUE,
            material_name VARCHAR(150) NOT NULL,
            category ENUM('kayu','kain','besi','kaca','finishing','hardware','lainnya') DEFAULT 'lainnya',
            unit VARCHAR(20) DEFAULT 'pcs',
            stock_quantity DECIMAL(12,3) DEFAULT 0,
            min_stock DECIMAL(12,3) DEFAULT 0,
            unit_price DECIMAL(15,2) DEFAULT 0,
            supplier VARCHAR(150) NULL,
            location VARCHAR(100) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Material Usage (per production order)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS production_material_usage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            production_order_id INT NOT NULL,
            material_id INT NOT NULL,
            quantity_used DECIMAL(12,3) NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (material_id) REFERENCES production_materials(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Workshop Schedule
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS workshop_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            production_order_id INT NULL,
            team_member VARCHAR(150) NOT NULL,
            task_description TEXT NOT NULL,
            scheduled_date DATE NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            status ENUM('scheduled','in_progress','completed','cancelled') DEFAULT 'scheduled',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function genOrderNumber(PDO $pdo, string $prefix): string {
    $year = date('Y');
    $month = date('m');
    $table = ($prefix === 'SO') ? 'sales_orders' : 'production_orders';
    $like = $prefix . '-' . $year . $month . '%';
    $count = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE order_number LIKE '{$like}'")->fetchColumn();
    return $prefix . '-' . $year . $month . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
}
