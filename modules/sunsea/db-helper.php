<?php

/**
 * Sunsea Module - Database Helper
 * Provides PDO connection to adf_sunsea database
 * 
 * Designed to be portable - if Sunsea moves to its own hosting,
 * only the connection credentials here need to change.
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

/**
 * Get a PDO connection to the Sunsea database.
 * Respects local vs. production naming convention automatically.
 *
 * @return PDO
 * @throws Exception on connection failure
 */
function getSunseaConnection(): PDO
{
    // Use the central Database class (it reads ACTIVE_BUSINESS_ID = 'sunsea' and picks adf_sunsea)
    if (class_exists('Database')) {
        return Database::getInstance()->getConnection();
    }

    // Standalone fallback (if ever hosted separately)
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

    if ($isProduction) {
        $host = 'localhost';
        $db   = 'adfb2574_sunsea';
        $user = 'adfb2574_adfsystem';
        $pass = '@Nnoc2025';
    } else {
        $host = 'localhost';
        $db   = 'adf_sunsea';
        $user = 'root';
        $pass = '';
    }

    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    return $pdo;
}

/**
 * Ensure booking-related tables and columns exist.
 * Safe to call on every request.
 */
function sunseaEnsureBookingSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `booking_orders` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `booking_no`      VARCHAR(30) UNIQUE NOT NULL,
            `customer_id`     INT NOT NULL,
            `booking_mode`    ENUM('paket','ecer') DEFAULT 'paket',
            `package_id`      INT NULL,
            `start_date`      DATE NOT NULL,
            `end_date`        DATE NOT NULL,
            `pax_count`       SMALLINT DEFAULT 1,
            `ticket_kapal_type` ENUM('none','single','pp') DEFAULT 'none',
            `include_btn_ticket` TINYINT(1) DEFAULT 0,
            `transport_notes` TEXT NULL,
            `meal_notes`      TEXT NULL,
            `island_trip`     TINYINT(1) DEFAULT 0,
            `land_trip`       TINYINT(1) DEFAULT 0,
            `documentation`   TINYINT(1) DEFAULT 0,
            `coordinator_id`  INT NULL,
            `guide_darat_id`  INT NULL,
            `guide_laut_id`   INT NULL,
            `status`          ENUM('draft','confirmed','ongoing','completed','cancelled') DEFAULT 'draft',
            `cost_total`      DECIMAL(15,2) DEFAULT 0.00,
            `sell_total`      DECIMAL(15,2) DEFAULT 0.00,
            `margin_amount`   DECIMAL(15,2) DEFAULT 0.00,
            `notes`           TEXT,
            `created_by`      VARCHAR(100),
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_booking_dates (`start_date`, `end_date`),
            INDEX idx_booking_status (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $requiredColumns = [
            'package_id' => "ALTER TABLE booking_orders ADD COLUMN package_id INT NULL AFTER booking_mode",
            'ticket_kapal_type' => "ALTER TABLE booking_orders ADD COLUMN ticket_kapal_type ENUM('none','single','pp') DEFAULT 'none' AFTER pax_count",
            'include_btn_ticket' => "ALTER TABLE booking_orders ADD COLUMN include_btn_ticket TINYINT(1) DEFAULT 0 AFTER ticket_kapal_type",
            'transport_notes' => "ALTER TABLE booking_orders ADD COLUMN transport_notes TEXT NULL AFTER include_btn_ticket",
            'meal_notes' => "ALTER TABLE booking_orders ADD COLUMN meal_notes TEXT NULL AFTER transport_notes",
            'island_trip' => "ALTER TABLE booking_orders ADD COLUMN island_trip TINYINT(1) DEFAULT 0 AFTER meal_notes",
            'land_trip' => "ALTER TABLE booking_orders ADD COLUMN land_trip TINYINT(1) DEFAULT 0 AFTER island_trip",
            'documentation' => "ALTER TABLE booking_orders ADD COLUMN documentation TINYINT(1) DEFAULT 0 AFTER land_trip",
            'coordinator_id' => "ALTER TABLE booking_orders ADD COLUMN coordinator_id INT NULL AFTER documentation",
            'guide_darat_id' => "ALTER TABLE booking_orders ADD COLUMN guide_darat_id INT NULL AFTER coordinator_id",
            'guide_laut_id' => "ALTER TABLE booking_orders ADD COLUMN guide_laut_id INT NULL AFTER guide_darat_id",
            'margin_amount' => "ALTER TABLE booking_orders ADD COLUMN margin_amount DECIMAL(15,2) DEFAULT 0.00 AFTER sell_total",
            'notes' => "ALTER TABLE booking_orders ADD COLUMN notes TEXT NULL AFTER margin_amount",
            'created_by' => "ALTER TABLE booking_orders ADD COLUMN created_by VARCHAR(100) NULL AFTER notes",
            'ticket_kapal_booked' => "ALTER TABLE booking_orders ADD COLUMN ticket_kapal_booked TINYINT(1) DEFAULT 0 AFTER ticket_kapal_type",
            'driver_name' => "ALTER TABLE booking_orders ADD COLUMN driver_name VARCHAR(150) NULL AFTER guide_laut_id",
        ];

        $columnCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_orders' AND COLUMN_NAME = ?");
        foreach ($requiredColumns as $column => $alterSql) {
            $columnCheck->execute([$column]);
            if ((int)$columnCheck->fetchColumn() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS booking_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            component_code VARCHAR(50) NOT NULL,
            component_name VARCHAR(200) NOT NULL,
            qty DECIMAL(12,2) DEFAULT 1,
            unit VARCHAR(50) DEFAULT 'unit',
            price_cost DECIMAL(15,2) DEFAULT 0,
            price_sell DECIMAL(15,2) DEFAULT 0,
            total_cost DECIMAL(15,2) DEFAULT 0,
            total_sell DECIMAL(15,2) DEFAULT 0,
            details_json TEXT NULL,
            sort_order INT DEFAULT 0,
            is_done TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_booking_items_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $itemColumnCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_order_items' AND COLUMN_NAME = ?");
        $itemColumnCheck->execute(['is_done']);
        if ((int)$itemColumnCheck->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE booking_order_items ADD COLUMN is_done TINYINT(1) DEFAULT 0 AFTER sort_order");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS booking_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            activity_date DATE NOT NULL,
            activity_type VARCHAR(50) DEFAULT 'other',
            title VARCHAR(200) NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_booking_sched_booking (booking_id),
            INDEX idx_booking_sched_date (activity_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('sunseaEnsureBookingSchema error: ' . $e->getMessage());
    }
}

/**
 * Ensure guides / coordinators / facilities / caterings master tables exist.
 * Prevents white-screen / silent save-failure on their respective database
 * pages when a table hasn't been created yet (e.g. fresh install where
 * database/sunsea-setup.sql wasn't fully run).
 */
function sunseaEnsureMasterDataSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `guides` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `guide_code`      VARCHAR(20) UNIQUE,
            `guide_type`      ENUM('darat','laut') NOT NULL,
            `name`            VARCHAR(150) NOT NULL,
            `phone`           VARCHAR(30),
            `email`           VARCHAR(120),
            `daily_rate_cost` DECIMAL(15,2) DEFAULT 0.00,
            `daily_rate_sell` DECIMAL(15,2) DEFAULT 0.00,
            `status`          ENUM('available','on_trip','off') DEFAULT 'available',
            `notes`           TEXT,
            `is_active`       TINYINT(1) DEFAULT 1,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_guide_type (`guide_type`),
            INDEX idx_guide_status (`status`),
            INDEX idx_guide_active (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('sunseaEnsureMasterDataSchema (guides) error: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coordinators` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `coordinator_code` VARCHAR(20) UNIQUE,
            `name`             VARCHAR(150) NOT NULL,
            `phone`            VARCHAR(30),
            `email`            VARCHAR(120),
            `area`             VARCHAR(120),
            `notes`            TEXT,
            `is_active`        TINYINT(1) DEFAULT 1,
            `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_coord_active (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('sunseaEnsureMasterDataSchema (coordinators) error: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `facilities` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `facility_code` VARCHAR(20) UNIQUE,
            `name`          VARCHAR(150) NOT NULL,
            `category`      VARCHAR(80),
            `unit`          VARCHAR(30) DEFAULT 'unit',
            `price_cost`    DECIMAL(15,2) DEFAULT 0.00,
            `price_sell`    DECIMAL(15,2) DEFAULT 0.00,
            `stock_qty`     DECIMAL(10,2) DEFAULT 0,
            `status`        ENUM('ready','maintenance','unavailable') DEFAULT 'ready',
            `notes`         TEXT,
            `is_active`     TINYINT(1) DEFAULT 1,
            `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_facility_active (`is_active`),
            INDEX idx_facility_status (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('sunseaEnsureMasterDataSchema (facilities) error: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `caterings` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `catering_code` VARCHAR(20) UNIQUE,
            `vendor_name`   VARCHAR(150) NOT NULL,
            `menu_name`     VARCHAR(150) NOT NULL,
            `category`      VARCHAR(80),
            `portion_unit`  VARCHAR(30) DEFAULT 'porsi',
            `price_cost`    DECIMAL(15,2) DEFAULT 0.00,
            `price_sell`    DECIMAL(15,2) DEFAULT 0.00,
            `phone`         VARCHAR(30),
            `location`      VARCHAR(120),
            `notes`         TEXT,
            `is_active`     TINYINT(1) DEFAULT 1,
            `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_catering_active (`is_active`),
            INDEX idx_catering_vendor (`vendor_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('sunseaEnsureMasterDataSchema (caterings) error: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `transport_items` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `transport_code`  VARCHAR(20) UNIQUE,
            `transport_type`  ENUM('darat','laut') NOT NULL,
            `name`            VARCHAR(150) NOT NULL,
            `unit`            VARCHAR(30) DEFAULT 'trip',
            `price_cost`      DECIMAL(15,2) DEFAULT 0.00,
            `price_sell`      DECIMAL(15,2) DEFAULT 0.00,
            `notes`           TEXT,
            `is_active`       TINYINT(1) DEFAULT 1,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_transport_type (`transport_type`),
            INDEX idx_transport_active (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('sunseaEnsureMasterDataSchema (transport_items) error: ' . $e->getMessage());
    }
}

/**
 * Ensure accommodation master tables exist.
 * Prevents white-screen on Hotel/Homestay database page when tables are missing.
 */
function sunseaEnsureAccommodationSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS accommodation_partners (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_code VARCHAR(30) UNIQUE NOT NULL,
            partner_type ENUM('hotel','homestay') DEFAULT 'hotel',
            name VARCHAR(200) NOT NULL,
            contact_person VARCHAR(120) NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(120) NULL,
            address TEXT NULL,
            location VARCHAR(150) NULL,
            notes TEXT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_partner_name (name),
            INDEX idx_partner_type (partner_type),
            INDEX idx_partner_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $partnerColumns = [
            'partner_code' => "ALTER TABLE accommodation_partners ADD COLUMN partner_code VARCHAR(30) UNIQUE NOT NULL AFTER id",
            'partner_type' => "ALTER TABLE accommodation_partners ADD COLUMN partner_type ENUM('hotel','homestay') DEFAULT 'hotel' AFTER partner_code",
            'name' => "ALTER TABLE accommodation_partners ADD COLUMN name VARCHAR(200) NOT NULL AFTER partner_type",
            'contact_person' => "ALTER TABLE accommodation_partners ADD COLUMN contact_person VARCHAR(120) NULL AFTER name",
            'phone' => "ALTER TABLE accommodation_partners ADD COLUMN phone VARCHAR(50) NULL AFTER contact_person",
            'email' => "ALTER TABLE accommodation_partners ADD COLUMN email VARCHAR(120) NULL AFTER phone",
            'address' => "ALTER TABLE accommodation_partners ADD COLUMN address TEXT NULL AFTER email",
            'location' => "ALTER TABLE accommodation_partners ADD COLUMN location VARCHAR(150) NULL AFTER address",
            'notes' => "ALTER TABLE accommodation_partners ADD COLUMN notes TEXT NULL AFTER location",
            'is_active' => "ALTER TABLE accommodation_partners ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER notes",
            'created_at' => "ALTER TABLE accommodation_partners ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER is_active",
            'updated_at' => "ALTER TABLE accommodation_partners ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        $columnCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accommodation_partners' AND COLUMN_NAME = ?");
        foreach ($partnerColumns as $column => $alterSql) {
            $columnCheck->execute([$column]);
            if ((int)$columnCheck->fetchColumn() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $codeCheck = $pdo->query("SELECT COUNT(*) FROM accommodation_partners WHERE (partner_code IS NULL OR partner_code = '')");
        if ((int)$codeCheck->fetchColumn() > 0) {
            $rows = $pdo->query("SELECT id FROM accommodation_partners WHERE (partner_code IS NULL OR partner_code = '') ORDER BY id")->fetchAll();
            $updCode = $pdo->prepare("UPDATE accommodation_partners SET partner_code=? WHERE id=?");
            foreach ($rows as $row) {
                $updCode->execute(['SS-ACC-' . str_pad((string)$row['id'], 3, '0', STR_PAD_LEFT), (int)$row['id']]);
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS accommodation_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_id INT NOT NULL,
            room_type VARCHAR(150) NOT NULL,
            capacity SMALLINT DEFAULT 2,
            price_cost DECIMAL(15,2) DEFAULT 0.00,
            price_sell DECIMAL(15,2) DEFAULT 0.00,
            quota INT DEFAULT 0,
            notes TEXT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rooms_partner (partner_id),
            INDEX idx_rooms_active (is_active),
            CONSTRAINT fk_rooms_partner FOREIGN KEY (partner_id) REFERENCES accommodation_partners(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $roomColumns = [
            'partner_id' => "ALTER TABLE accommodation_rooms ADD COLUMN partner_id INT NOT NULL AFTER id",
            'room_type' => "ALTER TABLE accommodation_rooms ADD COLUMN room_type VARCHAR(150) NOT NULL AFTER partner_id",
            'capacity' => "ALTER TABLE accommodation_rooms ADD COLUMN capacity SMALLINT DEFAULT 2 AFTER room_type",
            'price_cost' => "ALTER TABLE accommodation_rooms ADD COLUMN price_cost DECIMAL(15,2) DEFAULT 0.00 AFTER capacity",
            'price_sell' => "ALTER TABLE accommodation_rooms ADD COLUMN price_sell DECIMAL(15,2) DEFAULT 0.00 AFTER price_cost",
            'quota' => "ALTER TABLE accommodation_rooms ADD COLUMN quota INT DEFAULT 0 AFTER price_sell",
            'notes' => "ALTER TABLE accommodation_rooms ADD COLUMN notes TEXT NULL AFTER quota",
            'is_active' => "ALTER TABLE accommodation_rooms ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER notes",
            'created_at' => "ALTER TABLE accommodation_rooms ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER is_active",
            'updated_at' => "ALTER TABLE accommodation_rooms ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        $roomColumnCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accommodation_rooms' AND COLUMN_NAME = ?");
        foreach ($roomColumns as $column => $alterSql) {
            $roomColumnCheck->execute([$column]);
            if ((int)$roomColumnCheck->fetchColumn() === 0) {
                $pdo->exec($alterSql);
            }
        }
    } catch (Exception $e) {
        error_log('sunseaEnsureAccommodationSchema error: ' . $e->getMessage());
    }
}

/**
 * Ensure quotations table has an itinerary column (added after initial deploy).
 */
function sunseaEnsureQuotationItinerarySchema(PDO $pdo): void
{
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = 'itinerary'");
        $check->execute();
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE quotations ADD COLUMN itinerary TEXT NULL AFTER trip_end_date");
        }
    } catch (Exception $e) {
        error_log('sunseaEnsureQuotationItinerarySchema error: ' . $e->getMessage());
    }
}

/**
 * Get next auto-number for quotation / invoice.
 * Format: SS-QUO-2026-001
 *
 * @param PDO    $pdo
 * @param string $type  'quotation' | 'invoice'
 * @return string
 */
function sunseaNextNumber(PDO $pdo, string $type): string
{
    $prefixMap = [
        'quotation' => 'SS-QUO',
        'invoice'   => 'SS-INV',
        'booking'   => 'SS-BOOK',
    ];
    $prefix = $prefixMap[$type] ?? 'SS-DOC';
    $year   = date('Y');

    // Reset counter when year changes
    $pdo->prepare(
        "INSERT INTO sequences (seq_name, last_value, year) VALUES (?, 0, ?)
         ON DUPLICATE KEY UPDATE 
           last_value = IF(year < VALUES(year), 0, last_value),
           year       = IF(year < VALUES(year), VALUES(year), year)"
    )->execute([$type, $year]);

    $pdo->prepare("UPDATE sequences SET last_value = last_value + 1, year = ? WHERE seq_name = ?")
        ->execute([$year, $type]);

    $row = $pdo->prepare("SELECT last_value FROM sequences WHERE seq_name = ?");
    $row->execute([$type]);
    $num = (int)$row->fetchColumn();

    return $prefix . '-' . $year . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

/**
 * Format Rupiah
 */
function sunseaRupiah(float $amount, bool $short = false): string
{
    if ($short && $amount >= 1_000_000) {
        return 'Rp ' . number_format($amount / 1_000_000, 1) . ' jt';
    }
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/**
 * Get Sunsea setting value from settings table.
 */
function sunseaSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== '') ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}
