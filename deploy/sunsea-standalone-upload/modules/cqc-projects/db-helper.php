<?php
/**
 * CQC Projects Database Helper
 * Auto-creates database AND tables if not exist
 */

function getCQCDatabaseConnection() {
    $isLocalhost = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false);
    
    $dbHost = 'localhost';
    $dbUser = $isLocalhost ? 'root' : 'adfb2574_adfsystem';
    $dbPass = $isLocalhost ? '' : '@Nnoc2025';
    $dbName = $isLocalhost ? 'adf_cqc' : 'adfb2574_cqc';
    
    try {
        // Create database if not exists
        $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Connect to database
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        
        // Auto-create tables if not exist
        $check = $pdo->query("SHOW TABLES LIKE 'cqc_projects'");
        if ($check->rowCount() === 0) {
            autoCreateCQCTables($pdo);
        }
        
        // Ensure termin invoices table exists
        $check = $pdo->query("SHOW TABLES LIKE 'cqc_termin_invoices'");
        if ($check->rowCount() === 0) {
            ensureCQCTerminTable($pdo);
        }
        
        return $pdo;
    } catch (Exception $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

function autoCreateCQCTables($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cqc_projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_name VARCHAR(200) NOT NULL,
            project_code VARCHAR(50) UNIQUE NOT NULL,
            description LONGTEXT,
            location VARCHAR(300),
            client_name VARCHAR(150),
            client_phone VARCHAR(20),
            client_email VARCHAR(100),
            solar_capacity_kwp DECIMAL(8,2) COMMENT 'Kapasitas dalam KWp',
            panel_count INT,
            panel_type VARCHAR(100),
            inverter_type VARCHAR(100),
            budget_idr DECIMAL(15,2),
            spent_idr DECIMAL(15,2) DEFAULT 0,
            status ENUM('planning','procurement','installation','testing','completed','on_hold') DEFAULT 'planning',
            progress_percentage INT DEFAULT 0,
            start_date DATE,
            end_date DATE,
            estimated_completion DATE,
            actual_completion DATE,
            project_manager_id INT,
            lead_installer_id INT,
            created_by INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_code (project_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cqc_expense_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(100) NOT NULL,
            category_icon VARCHAR(10) DEFAULT '📦',
            description VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cqc_project_expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            category_id INT,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            expense_date DATE NOT NULL,
            receipt_number VARCHAR(50),
            notes TEXT,
            created_by INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_project (project_id),
            FOREIGN KEY (project_id) REFERENCES cqc_projects(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES cqc_expense_categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Insert default categories
    $pdo->exec("
        INSERT IGNORE INTO cqc_expense_categories (id, category_name, category_icon, description) VALUES
        (1, 'Panel Surya', '☀️', 'Pembelian panel surya'),
        (2, 'Inverter', '🔌', 'Pembelian inverter'),
        (3, 'Kabel & Konektor', '🔗', 'Kabel, konektor, MC4'),
        (4, 'Tenaga Kerja', '👷', 'Upah pekerja instalasi'),
        (5, 'Struktur Mounting', '🏗️', 'Rangka dan mounting bracket'),
        (6, 'Perizinan', '📋', 'Biaya izin dan sertifikasi'),
        (7, 'Testing & Commissioning', '🔧', 'Biaya pengujian sistem'),
        (8, 'Logistik & Transportasi', '🚛', 'Ongkos kirim dan transportasi'),
        (9, 'Konsultasi & Desain', '📐', 'Biaya konsultan dan desain'),
        (10, 'Lain-lain', '📦', 'Pengeluaran lainnya')
    ");
    
    // Create CQC Termin Invoices table for contractor progress billing
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cqc_termin_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) UNIQUE NOT NULL,
            project_id INT NOT NULL,
            termin_number INT NOT NULL COMMENT 'Termin ke-1, ke-2, dst',
            invoice_date DATE NOT NULL,
            due_date DATE,
            description VARCHAR(500),
            
            -- Amount calculation
            contract_value DECIMAL(15,2) NOT NULL COMMENT 'Nilai kontrak total',
            percentage DECIMAL(5,2) NOT NULL COMMENT 'Persentase termin',
            base_amount DECIMAL(15,2) NOT NULL COMMENT 'Nilai sebelum PPN',
            ppn_percentage DECIMAL(5,2) DEFAULT 11 COMMENT 'PPN %',
            ppn_amount DECIMAL(15,2) DEFAULT 0,
            pph_percentage DECIMAL(5,2) DEFAULT 0 COMMENT 'PPh %',
            pph_amount DECIMAL(15,2) DEFAULT 0,
            retention_percentage DECIMAL(5,2) DEFAULT 0 COMMENT 'Retensi %',
            retention_amount DECIMAL(15,2) DEFAULT 0,
            total_amount DECIMAL(15,2) NOT NULL COMMENT 'Total tagihan',
            
            -- Payment
            payment_status ENUM('draft','sent','paid','partial','overdue') DEFAULT 'draft',
            paid_amount DECIMAL(15,2) DEFAULT 0,
            payment_date DATE,
            payment_method VARCHAR(50),
            payment_notes TEXT,
            
            notes TEXT,
            created_by INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_project (project_id),
            INDEX idx_status (payment_status),
            INDEX idx_date (invoice_date),
            FOREIGN KEY (project_id) REFERENCES cqc_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function isLocalhost() {
    return (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false);
}

function getCQCDatabaseName() {
    return isLocalhost() ? 'adf_cqc' : 'adfb2574_cqc';
}

/**
 * Ensure CQC Termin Invoices table exists (for migrations)
 */
function ensureCQCTerminTable($pdo) {
    $check = $pdo->query("SHOW TABLES LIKE 'cqc_termin_invoices'");
    if ($check->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cqc_termin_invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_number VARCHAR(50) UNIQUE NOT NULL,
                project_id INT NOT NULL,
                termin_number INT NOT NULL COMMENT 'Termin ke-1, ke-2, dst',
                invoice_date DATE NOT NULL,
                due_date DATE,
                description VARCHAR(500),
                
                contract_value DECIMAL(15,2) NOT NULL,
                percentage DECIMAL(5,2) NOT NULL,
                base_amount DECIMAL(15,2) NOT NULL,
                ppn_percentage DECIMAL(5,2) DEFAULT 11,
                ppn_amount DECIMAL(15,2) DEFAULT 0,
                pph_percentage DECIMAL(5,2) DEFAULT 0,
                pph_amount DECIMAL(15,2) DEFAULT 0,
                retention_percentage DECIMAL(5,2) DEFAULT 0,
                retention_amount DECIMAL(15,2) DEFAULT 0,
                total_amount DECIMAL(15,2) NOT NULL,
                
                payment_status ENUM('draft','sent','paid','partial','overdue') DEFAULT 'draft',
                paid_amount DECIMAL(15,2) DEFAULT 0,
                payment_date DATE,
                payment_method VARCHAR(50),
                payment_notes TEXT,
                
                notes TEXT,
                created_by INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_project (project_id),
                INDEX idx_status (payment_status),
                INDEX idx_date (invoice_date),
                FOREIGN KEY (project_id) REFERENCES cqc_projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    return true;
}

/**
 * Ensure CQC Quotations table exists
 */
function ensureCQCQuotationTable($pdo) {
    $check = $pdo->query("SHOW TABLES LIKE 'cqc_quotations'");
    if ($check->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cqc_quotations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_number VARCHAR(50) UNIQUE NOT NULL,
                quote_date DATE NOT NULL,
                valid_until DATE,
                
                -- Client info
                client_name VARCHAR(200) NOT NULL,
                client_attn VARCHAR(100),
                client_phone VARCHAR(50),
                client_email VARCHAR(100),
                client_address TEXT,
                
                -- From (company) info is taken from settings
                
                -- Quote details
                subject VARCHAR(255) COMMENT 'Quote subject/title',
                notes TEXT,
                terms_conditions TEXT,
                
                -- Amount calculation  
                subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
                discount_percentage DECIMAL(5,2) DEFAULT 0,
                discount_amount DECIMAL(15,2) DEFAULT 0,
                ppn_percentage DECIMAL(5,2) DEFAULT 11,
                ppn_amount DECIMAL(15,2) DEFAULT 0,
                total_amount DECIMAL(15,2) NOT NULL,
                
                -- Status
                status ENUM('draft','sent','approved','rejected','expired') DEFAULT 'draft',
                
                created_by INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_status (status),
                INDEX idx_date (quote_date),
                INDEX idx_client (client_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Create quotation items table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cqc_quotation_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quotation_id INT NOT NULL,
                description VARCHAR(500) NOT NULL,
                remarks VARCHAR(255),
                quantity DECIMAL(10,2) DEFAULT 1,
                unit VARCHAR(50) DEFAULT 'unit',
                unit_price DECIMAL(15,2) NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_quotation (quotation_id),
                FOREIGN KEY (quotation_id) REFERENCES cqc_quotations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    
    // Patch existing table: ensure subject col exists and status enum includes 'approved'
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM cqc_quotations LIKE 'subject'")->rowCount();
        if ($cols === 0) {
            $pdo->exec("ALTER TABLE cqc_quotations ADD COLUMN subject VARCHAR(255) AFTER client_address");
        }
        // Ensure discount_type and discount_value columns exist
        if ($pdo->query("SHOW COLUMNS FROM cqc_quotations LIKE 'discount_type'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE cqc_quotations ADD COLUMN discount_type ENUM('fixed','percentage') DEFAULT 'fixed' AFTER ppn_amount");
        }
        if ($pdo->query("SHOW COLUMNS FROM cqc_quotations LIKE 'discount_value'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE cqc_quotations ADD COLUMN discount_value DECIMAL(15,2) DEFAULT 0 AFTER discount_type");
        }
        // Patch status ENUM to include 'approved'
        $pdo->exec("ALTER TABLE cqc_quotations MODIFY COLUMN status ENUM('draft','sent','approved','rejected','expired') DEFAULT 'draft'");
        
        // Add project-related columns for quotation as main entry point
        if ($pdo->query("SHOW COLUMNS FROM cqc_quotations LIKE 'project_name'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE cqc_quotations ADD COLUMN project_name VARCHAR(200) AFTER subject");
        }
        if ($pdo->query("SHOW COLUMNS FROM cqc_quotations LIKE 'project_location'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE cqc_quotations ADD COLUMN project_location TEXT AFTER project_name");
        }
        if ($pdo->query("SHOW COLUMNS FROM cqc_quotations LIKE 'solar_capacity_kwp'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE cqc_quotations ADD COLUMN solar_capacity_kwp DECIMAL(10,2) DEFAULT 0 AFTER project_location");
        }
        if ($pdo->query("SHOW COLUMNS FROM cqc_quotations LIKE 'project_id'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE cqc_quotations ADD COLUMN project_id INT NULL AFTER id");
        }
    } catch (Exception $e) {}
    
    return true;
}

/**
 * Ensure CQC General Invoices table exists
 */
function ensureCQCGeneralInvoiceTable($pdo) {
    $check = $pdo->query("SHOW TABLES LIKE 'cqc_general_invoices'");
    if ($check->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cqc_general_invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_number VARCHAR(50) UNIQUE NOT NULL,
                invoice_date DATE NOT NULL,
                due_date DATE,
                
                -- Client info
                client_name VARCHAR(200) NOT NULL,
                client_phone VARCHAR(50),
                client_email VARCHAR(100),
                client_address TEXT,
                
                -- Invoice details
                subject VARCHAR(255) COMMENT 'Invoice subject/title',
                notes TEXT,
                
                -- Amount calculation  
                subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
                discount_percentage DECIMAL(5,2) DEFAULT 0,
                discount_amount DECIMAL(15,2) DEFAULT 0,
                ppn_percentage DECIMAL(5,2) DEFAULT 11,
                ppn_amount DECIMAL(15,2) DEFAULT 0,
                pph_percentage DECIMAL(5,2) DEFAULT 0,
                pph_amount DECIMAL(15,2) DEFAULT 0,
                total_amount DECIMAL(15,2) NOT NULL,
                
                -- Payment
                payment_status ENUM('draft','sent','paid','partial','overdue') DEFAULT 'draft',
                paid_amount DECIMAL(15,2) DEFAULT 0,
                payment_date DATE,
                payment_method VARCHAR(50),
                payment_notes TEXT,
                
                created_by INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_status (payment_status),
                INDEX idx_date (invoice_date),
                INDEX idx_client (client_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Create invoice items table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cqc_general_invoice_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT NOT NULL,
                description VARCHAR(500) NOT NULL,
                quantity DECIMAL(10,2) DEFAULT 1,
                unit VARCHAR(50) DEFAULT 'unit',
                unit_price DECIMAL(15,2) NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_invoice (invoice_id),
                FOREIGN KEY (invoice_id) REFERENCES cqc_general_invoices(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    return true;
}
