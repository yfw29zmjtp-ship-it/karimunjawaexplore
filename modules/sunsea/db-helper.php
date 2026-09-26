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
            'quotation_id' => "ALTER TABLE booking_orders ADD COLUMN quotation_id INT NULL AFTER id",
            'accommodation_manual' => "ALTER TABLE booking_orders ADD COLUMN accommodation_manual VARCHAR(200) NULL AFTER meal_notes",
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
        $itemColumnCheck->execute(['is_paid_mitra']);
        if ((int)$itemColumnCheck->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE booking_order_items ADD COLUMN is_paid_mitra TINYINT(1) DEFAULT 0 AFTER is_done");
        }
        $itemColumnCheck->execute(['item_type']);
        if ((int)$itemColumnCheck->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE booking_order_items ADD COLUMN item_type VARCHAR(30) NULL AFTER component_code");
        }

        // Backfill item_type utk baris pkg_detail lama (sebelum kolom ini ada) supaya fitur
        // "Ganti Penginapan/Transport" bisa mengenali & menghapus baris lama dari paket, bukan cuma dari mode ecer.
        // Terpisah dari try/catch utama: kalau trip_package_items belum ada, jangan gagalkan setup tabel lain di bawah.
        try {
            $pdo->exec("UPDATE booking_order_items boi
                JOIN booking_orders bo ON bo.id = boi.booking_id
                JOIN trip_package_items tpi ON tpi.package_id = bo.package_id AND tpi.item_name = boi.component_name COLLATE utf8mb4_general_ci
                SET boi.item_type = tpi.item_type
                WHERE boi.component_code = 'pkg_detail' AND boi.item_type IS NULL");
        } catch (Exception $e) {
            error_log('sunseaEnsureBookingSchema backfill item_type error: ' . $e->getMessage());
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
 * Ensure the trip_package_items table exists (detail layanan per paket:
 * tiket kapal, penginapan, transport, guide, catering, dll) so that when a
 * booking uses a paket, the real mitra obligations/tagihan can be tracked
 * per booking instead of guessed from Finance expense text.
 */
function sunseaEnsurePackageItemsSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS trip_package_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            package_id INT NOT NULL,
            item_type ENUM('tiket_kapal','penginapan','transport','guide','catering','fasilitas','dokumentasi','lainnya') DEFAULT 'lainnya',
            item_name VARCHAR(200) NOT NULL,
            cost_basis ENUM('per_pax','flat') DEFAULT 'per_pax',
            estimated_cost DECIMAL(15,2) DEFAULT 0.00,
            estimated_sell DECIMAL(15,2) DEFAULT 0.00,
            notes VARCHAR(255) NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pkgitems_package (package_id),
            CONSTRAINT fk_pkgitems_package FOREIGN KEY (package_id) REFERENCES trip_packages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_package_items' AND COLUMN_NAME = 'estimated_sell'");
        $check->execute();
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE trip_package_items ADD COLUMN estimated_sell DECIMAL(15,2) DEFAULT 0.00 AFTER estimated_cost");
        }

        $checkQty = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_package_items' AND COLUMN_NAME = 'qty'");
        $checkQty->execute();
        if ((int)$checkQty->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE trip_package_items ADD COLUMN qty DECIMAL(10,2) NOT NULL DEFAULT 1.00 AFTER cost_basis");
        }
    } catch (Exception $e) {
        error_log('sunseaEnsurePackageItemsSchema error: ' . $e->getMessage());
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
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations' AND COLUMN_NAME = ?");
        $check->execute(['itinerary']);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE quotations ADD COLUMN itinerary TEXT NULL AFTER trip_end_date");
        }
        // Label penginapan manual, dipakai saat tidak memilih dari database Penginapan.
        $check->execute(['accommodation_manual']);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE quotations ADD COLUMN accommodation_manual VARCHAR(200) NULL AFTER trip_end_date");
        }
        // Kapan penawaran pertama kali dibuka admin, untuk dot notifikasi "belum dibaca".
        $check->execute(['viewed_at']);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE quotations ADD COLUMN viewed_at DATETIME NULL DEFAULT NULL AFTER status");
        }
    } catch (Exception $e) {
        error_log('sunseaEnsureQuotationItinerarySchema error: ' . $e->getMessage());
    }
}

/**
 * Ensure cash_book (Buku Kas Operasional) exists and supports linking an
 * expense/income entry to a specific booking (trip), on top of customer_id.
 */
function sunseaEnsureFinanceSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cash_book` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `cash_account_id`  INT,
            `transaction_date` DATE NOT NULL,
            `transaction_time` TIME DEFAULT '00:00:00',
            `type`             ENUM('income','expense') NOT NULL,
            `category`         VARCHAR(100),
            `description`      VARCHAR(255) NOT NULL,
            `amount`           DECIMAL(15,2) NOT NULL,
            `reference`        VARCHAR(100),
            `customer_id`      INT NULL,
            `invoice_id`       INT NULL,
            `created_by`       VARCHAR(100),
            `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_date (`transaction_date`),
            INDEX idx_type (`type`),
            INDEX idx_account (`cash_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $requiredColumns = [
            'booking_id' => "ALTER TABLE cash_book ADD COLUMN booking_id INT NULL AFTER customer_id",
            'booking_item_id' => "ALTER TABLE cash_book ADD COLUMN booking_item_id INT NULL AFTER booking_id",
            'payment_id' => "ALTER TABLE cash_book ADD COLUMN payment_id INT NULL AFTER invoice_id",
        ];
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_book' AND COLUMN_NAME = ?");
        foreach ($requiredColumns as $column => $alterSql) {
            $check->execute([$column]);
            if ((int)$check->fetchColumn() === 0) {
                $pdo->exec($alterSql);
            }
        }

        // Backfill: transaksi pemasukan lama dari pembayaran invoice belum tercatat nama tamunya, jadi tak bisa difilter per tamu.
        $pdo->exec("
            UPDATE cash_book cb
            JOIN invoices i ON i.id = cb.invoice_id
            SET cb.customer_id = i.customer_id
            WHERE cb.customer_id IS NULL AND cb.invoice_id IS NOT NULL
        ");

        // Backfill: hubungkan cash_book lama ke baris payments-nya (payment_id) supaya kalau DP
        // diedit tanggal/jumlahnya, cash_book ikut sinkron otomatis - hanya untuk kasus yang tidak
        // ambigu (1 invoice cuma 1 pembayaran & 1 baris cash_book income yang jumlahnya sama).
        $pdo->exec("
            UPDATE cash_book cb
            JOIN (
                SELECT p.id AS payment_id, p.invoice_id, p.amount
                FROM payments p
                WHERE (SELECT COUNT(*) FROM payments p2 WHERE p2.invoice_id = p.invoice_id) = 1
            ) p ON p.invoice_id = cb.invoice_id AND p.amount = cb.amount
            SET cb.payment_id = p.payment_id
            WHERE cb.payment_id IS NULL AND cb.type = 'income' AND cb.invoice_id IS NOT NULL
                AND (SELECT COUNT(*) FROM cash_book cb2 WHERE cb2.invoice_id = cb.invoice_id AND cb2.type = 'income') = 1
        ");

        // Auto-sync: kalau ada invoice yang paid_amount-nya lebih besar dari total yang sudah
        // tercatat di cash_book (mis. pembayaran diinput lewat cara lain di luar form "Tambah
        // Pembayaran", atau data lama sebelum auto-insert cash_book ada), otomatis tambahkan
        // selisihnya ke cash_book tiap kali halaman Finance/Laporan/Calendar dibuka - supaya
        // uang yang sudah diterima selalu otomatis kelihatan di Buku Kas tanpa perlu tool manual.
        $pdo->exec("
            INSERT INTO cash_book (transaction_date, type, category, description, amount, reference, invoice_id, customer_id, created_by)
            SELECT
                COALESCE((SELECT MAX(p.payment_date) FROM payments p WHERE p.invoice_id = i.id), CURDATE()),
                'income',
                'Penerimaan Trip',
                CONCAT('Pembayaran Invoice ', i.invoice_no, ' — ', c.name, ' (auto-sync selisih)'),
                (i.paid_amount - COALESCE(cb.total_recorded, 0)),
                i.invoice_no,
                i.id,
                i.customer_id,
                'system-auto-sync'
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS total_recorded
                FROM cash_book
                WHERE type = 'income' AND invoice_id IS NOT NULL
                GROUP BY invoice_id
            ) cb ON cb.invoice_id = i.id
            WHERE i.paid_amount > COALESCE(cb.total_recorded, 0) + 0.01
        ");
    } catch (Exception $e) {
        error_log('sunseaEnsureFinanceSchema error: ' . $e->getMessage());
    }
}

/**
 * Ensure the `roles` and `users` (login) tables exist.
 * These already exist on standalone Sunsea hosting, but this guards
 * fresh installs and keeps the pattern consistent with other modules.
 */
function sunseaEnsureUserSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `roles` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `role_code`  VARCHAR(30) NOT NULL UNIQUE,
            `role_name`  VARCHAR(100) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT IGNORE INTO `roles` (`id`, `role_code`, `role_name`) VALUES
            (1, 'developer', 'Developer / Owner'),
            (2, 'manager',   'Manager'),
            (3, 'staff',     'Staff')");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `username`        VARCHAR(50) NOT NULL UNIQUE,
            `password`        VARCHAR(255) NOT NULL,
            `full_name`       VARCHAR(150) NOT NULL,
            `email`           VARCHAR(150),
            `role_id`         INT DEFAULT 3,
            `business_access` VARCHAR(20) DEFAULT 'all',
            `is_active`       TINYINT(1) DEFAULT 1,
            `last_login`      TIMESTAMP NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_username (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('sunseaEnsureUserSchema error: ' . $e->getMessage());
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
 * Ensure trip_packages has a cover_image column and the trip_package_gallery table exists.
 */
function sunseaEnsurePackageMediaSchema(PDO $pdo): void
{
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_packages' AND COLUMN_NAME = 'cover_image'");
        $check->execute();
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE trip_packages ADD COLUMN cover_image VARCHAR(255) NULL AFTER base_price");
        }

        $checkOrder = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_packages' AND COLUMN_NAME = 'display_order'");
        $checkOrder->execute();
        if ((int)$checkOrder->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE trip_packages ADD COLUMN display_order INT NOT NULL DEFAULT 0 AFTER is_active");
            $pdo->exec("UPDATE trip_packages SET display_order = id");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `trip_package_gallery` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `package_id`  INT NOT NULL,
            `image_path`  VARCHAR(255) NOT NULL,
            `caption`     VARCHAR(150) NULL,
            `sort_order`  INT DEFAULT 0,
            `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pkggallery_package (`package_id`),
            CONSTRAINT fk_pkggallery_package FOREIGN KEY (`package_id`) REFERENCES `trip_packages`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        @error_log('sunseaEnsurePackageMediaSchema: ' . $e->getMessage());
    }
}

/**
 * Ensure tables used by the public website CMS (gallery + blog) exist.
 */
function sunseaEnsureWebsiteContentSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `website_gallery` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `image_path`  VARCHAR(255) NOT NULL,
            `caption`     VARCHAR(150) NULL,
            `category`    VARCHAR(30) NOT NULL DEFAULT 'umum',
            `sort_order`  INT DEFAULT 0,
            `is_active`   TINYINT(1) DEFAULT 1,
            `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Add category column for galleries created before this feature existed.
        try {
            $hasCategory = $pdo->query("SHOW COLUMNS FROM website_gallery LIKE 'category'")->fetch();
            if (!$hasCategory) {
                $pdo->exec("ALTER TABLE website_gallery ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'umum' AFTER caption");
            }
        } catch (Exception $e) {
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `website_blog` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `title`          VARCHAR(200) NOT NULL,
            `slug`           VARCHAR(220) NOT NULL UNIQUE,
            `excerpt`        VARCHAR(300) NULL,
            `content`        TEXT NULL,
            `cover_image`    VARCHAR(255) NULL,
            `is_published`   TINYINT(1) DEFAULT 1,
            `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        @error_log('sunseaEnsureWebsiteContentSchema: ' . $e->getMessage());
    }
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

/**
 * Save a Sunsea setting value into the settings table (insert or update).
 */
function sunseaSetSetting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
        ->execute([$key, $value, $value]);
}

/**
 * Ensure the subscription_invoices table exists (billing ADF System charges this
 * business: flat monthly base fee + per-confirmed-guest fee).
 */
function sunseaEnsureSubscriptionBillingSchema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `subscription_invoices` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `period`        VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
            `base_fee`      DECIMAL(15,2) DEFAULT 0.00,
            `guest_count`   INT DEFAULT 0,
            `per_guest_fee` DECIMAL(15,2) DEFAULT 0.00,
            `guest_total`   DECIMAL(15,2) DEFAULT 0.00,
            `total_amount`  DECIMAL(15,2) DEFAULT 0.00,
            `status`        ENUM('unpaid','paid','cancelled') DEFAULT 'unpaid',
            `due_date`      DATE NULL,
            `order_id`      VARCHAR(40) NULL,
            `txn_id`        VARCHAR(100) NULL,
            `payment_link`  VARCHAR(255) NULL,
            `paid_at`       DATETIME NULL,
            `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_period` (`period`),
            INDEX idx_sub_status (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Add due_date for invoices created before this feature existed.
        try {
            $hasDueDate = $pdo->query("SHOW COLUMNS FROM subscription_invoices LIKE 'due_date'")->fetch();
            if (!$hasDueDate) {
                $pdo->exec("ALTER TABLE subscription_invoices ADD COLUMN due_date DATE NULL AFTER status");
            }
        } catch (Exception $e) {
        }
    } catch (Exception $e) {
        error_log('sunseaEnsureSubscriptionBillingSchema error: ' . $e->getMessage());
    }
}

/**
 * Read subscription billing configuration (base fee, per-guest fee, Pakasir
 * merchant credentials) from the settings table. These values are only ever
 * written by sunseaSyncSubscriptionConfig() — the local admin cannot edit
 * them directly, they are controlled centrally by ADF System.
 */
function sunseaSubscriptionConfig(PDO $pdo): array
{
    return [
        'base_fee' => (float) sunseaSetting($pdo, 'subscription_base_fee', '150000'),
        'per_guest_fee' => (float) sunseaSetting($pdo, 'subscription_per_guest_fee', '5000'),
        'pakasir_slug' => sunseaSetting($pdo, 'subscription_pakasir_slug', ''),
        'pakasir_api_key' => sunseaSetting($pdo, 'subscription_pakasir_api_key', ''),
        'pakasir_webhook_secret' => sunseaSetting($pdo, 'subscription_pakasir_webhook_secret', ''),
        'last_sync_at' => sunseaSetting($pdo, 'subscription_last_sync_at', ''),
        'last_sync_error' => sunseaSetting($pdo, 'subscription_last_sync_error', ''),
    ];
}

function sunseaSubscriptionIsConfigured(array $cfg): bool
{
    return $cfg['pakasir_slug'] !== '' && $cfg['pakasir_api_key'] !== '';
}

/**
 * Fetch the current pricing/gateway config from ADF System's central API and
 * cache it locally into the settings table. ADF System is the sole source of
 * truth for these numbers — this business's admin can only view them, not
 * edit them. On network failure, the previously cached values are kept as-is
 * and a `subscription_last_sync_error` note is recorded.
 */
function sunseaSyncSubscriptionConfig(PDO $pdo): array
{
    $syncUrl = sunseaSetting($pdo, 'subscription_sync_url', 'https://adfsystem.store/api/subscription-config.php');
    $clientKey = sunseaSetting($pdo, 'subscription_client_key', '');
    $clientToken = sunseaSetting($pdo, 'subscription_client_token', '');

    if ($clientKey === '' || $clientToken === '') {
        sunseaSetSetting($pdo, 'subscription_last_sync_error', 'Client Key/Token belum diisi.');
        return sunseaSubscriptionConfig($pdo);
    }

    $ch = curl_init($syncUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['client_key' => $clientKey, 'client_token' => $clientToken]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        sunseaSetSetting($pdo, 'subscription_last_sync_error', $curlError ?: "HTTP {$httpCode} dari server ADF System.");
        return sunseaSubscriptionConfig($pdo);
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['base_fee'])) {
        sunseaSetSetting($pdo, 'subscription_last_sync_error', $data['error'] ?? 'Respons tidak valid dari ADF System.');
        return sunseaSubscriptionConfig($pdo);
    }

    sunseaSetSetting($pdo, 'subscription_base_fee', (string) $data['base_fee']);
    sunseaSetSetting($pdo, 'subscription_per_guest_fee', (string) $data['per_guest_fee']);
    sunseaSetSetting($pdo, 'subscription_pakasir_slug', (string) ($data['pakasir_slug'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_pakasir_api_key', (string) ($data['pakasir_api_key'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_pakasir_webhook_secret', (string) ($data['pakasir_webhook_secret'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_last_sync_at', date('c'));
    sunseaSetSetting($pdo, 'subscription_last_sync_error', '');

    return sunseaSubscriptionConfig($pdo);
}

/**
 * Count confirmed (or further along: ongoing/completed) guests whose trip
 * `start_date` falls within the given period (YYYY-MM), and compute the charge.
 */
function sunseaCalculateSubscriptionCharge(PDO $pdo, string $period): array
{
    $cfg = sunseaSubscriptionConfig($pdo);
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(pax_count), 0) FROM booking_orders
         WHERE status IN ('confirmed', 'ongoing', 'completed')
         AND start_date BETWEEN ? AND ?"
    );
    $stmt->execute([$start, $end]);
    $guestCount = (int) $stmt->fetchColumn();

    $guestTotal = $guestCount * $cfg['per_guest_fee'];
    $totalAmount = $cfg['base_fee'] + $guestTotal;

    return [
        'base_fee' => $cfg['base_fee'],
        'guest_count' => $guestCount,
        'per_guest_fee' => $cfg['per_guest_fee'],
        'guest_total' => $guestTotal,
        'total_amount' => $totalAmount,
    ];
}

/**
 * Get the invoice row for a billing period, creating it if missing. If the
 * period is still unpaid, the guest count/total is refreshed to reflect any
 * bookings confirmed since the invoice was first generated. Paid/cancelled
 * invoices are left untouched (immutable history).
 */
function sunseaGetOrRefreshSubscriptionInvoice(PDO $pdo, string $period): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? LIMIT 1");
    $stmt->execute([$period]);
    $invoice = $stmt->fetch();

    $charge = sunseaCalculateSubscriptionCharge($pdo, $period);

    if (!$invoice) {
        $dueDate = date('Y-m-t', strtotime($period . '-01'));
        $pdo->prepare(
            "INSERT INTO subscription_invoices (period, base_fee, guest_count, per_guest_fee, guest_total, total_amount, status, due_date)
             VALUES (?, ?, ?, ?, ?, ?, 'unpaid', ?)"
        )->execute([$period, $charge['base_fee'], $charge['guest_count'], $charge['per_guest_fee'], $charge['guest_total'], $charge['total_amount'], $dueDate]);
    } elseif ($invoice['status'] === 'unpaid') {
        $pdo->prepare(
            "UPDATE subscription_invoices SET base_fee=?, guest_count=?, per_guest_fee=?, guest_total=?, total_amount=? WHERE period=?"
        )->execute([$charge['base_fee'], $charge['guest_count'], $charge['per_guest_fee'], $charge['guest_total'], $charge['total_amount'], $period]);
    }

    $stmt->execute([$period]);
    return $stmt->fetch() ?: null;
}

/**
 * Find the nearest unpaid invoice whose due date is within 7 days (or already
 * passed) so the topbar can show a "pay now" reminder banner. Returns null
 * when nothing is due soon.
 */
function sunseaGetSubscriptionReminder(PDO $pdo): ?array
{
    try {
        $stmt = $pdo->query(
            "SELECT * FROM subscription_invoices WHERE status = 'unpaid' AND due_date IS NOT NULL
             ORDER BY due_date ASC LIMIT 1"
        );
        $invoice = $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }

    if (!$invoice) {
        return null;
    }

    $daysLeft = (int) ceil((strtotime($invoice['due_date']) - strtotime(date('Y-m-d'))) / 86400);
    if ($daysLeft > 7) {
        return null;
    }

    return ['invoice' => $invoice, 'days_left' => $daysLeft, 'overdue' => $daysLeft < 0];
}

/**
 * Minimal standalone Pakasir API v2 client (create payment link / check status).
 * Separate from adfsystem-site's client since this app lives on its own hosting.
 */
function sunseaPakasirCreatePaymentLink(array $cfg, string $orderId, int $amount): ?array
{
    if (empty($cfg['pakasir_slug']) || empty($cfg['pakasir_api_key'])) {
        return null;
    }
    $slug = rawurlencode($cfg['pakasir_slug']);
    $orderIdEncoded = rawurlencode($orderId);
    $url = "https://app.pakasir.com/api/v2/create-transaction/{$slug}/{$orderIdEncoded}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['method' => 'payment_link', 'amount' => $amount]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Key: ' . $cfg['pakasir_api_key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['payment_link']) || empty($data['txn_id'])) {
        return null;
    }
    return ['txn_id' => $data['txn_id'], 'payment_link' => $data['payment_link']];
}

function sunseaPakasirTransactionStatus(array $cfg, string $txnId): ?array
{
    if (empty($cfg['pakasir_slug']) || empty($cfg['pakasir_api_key'])) {
        return null;
    }
    $slug = rawurlencode($cfg['pakasir_slug']);
    $txnIdEncoded = rawurlencode($txnId);
    $url = "https://app.pakasir.com/api/v2/transaction-status/{$slug}/{$txnIdEncoded}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $cfg['pakasir_api_key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/**
 * Build a cache-busted BASE_URL for a stored uploads-relative path so a re-uploaded
 * file (same filename) shows immediately instead of a stale browser-cached version.
 */
function sunseaAssetUrl(string $relPath): string
{
    if ($relPath === '') return '';
    $relPath = ltrim($relPath, '/');
    $localFile = __DIR__ . '/../../' . $relPath;
    $v = file_exists($localFile) ? filemtime($localFile) : time();
    return BASE_URL . '/' . $relPath . '?v=' . $v;
}

/**
 * Build a wa.me link from a local phone number (e.g. 08123456789) with an optional prefilled message.
 */
function sunseaWaLink(string $phone, string $message = ''): string
{
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') return '';
    if (substr($digits, 0, 1) === '0') {
        $digits = '62' . substr($digits, 1);
    } elseif (substr($digits, 0, 2) !== '62') {
        $digits = '62' . $digits;
    }
    $url = 'https://wa.me/' . $digits;
    if ($message !== '') {
        $url .= '?text=' . rawurlencode($message);
    }
    return $url;
}

/**
 * Parse the "company_whatsapp_admins" setting into a list of ['label' => ..., 'wa' => wa.me base
 * link] for the website chat widget. Accepts "Nama|NomorWA" (preferred) or a looser "Nama NomorWA"
 * (name then phone at the end, no pipe) so admins who forget the "|" still get their own name
 * instead of a generic "Admin N" fallback. Falls back to a single entry built from company_phone
 * when the multi-admin setting is empty.
 */
function sunseaWaAdminList(PDO $pdo): array
{
    $raw = sunseaSetting($pdo, 'company_whatsapp_admins', '');
    $admins = [];
    $lineNo = 0;
    foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $lineNo++;

        $label = '';
        $phone = '';
        if (strpos($line, '|') !== false) {
            $parts = explode('|', $line, 2);
            $label = trim($parts[0]);
            $phone = trim($parts[1]);
        } elseif (preg_match('/^(.*?)[\s,;-]+(\+?\d[\d\s\-]{6,}\d)$/', $line, $m)) {
            $label = trim($m[1]);
            $phone = trim($m[2]);
        } else {
            $phone = $line;
        }
        if ($label === '') $label = 'Admin ' . $lineNo;

        $wa = sunseaWaLink($phone);
        if ($wa !== '') $admins[] = ['label' => $label, 'wa' => $wa];
    }

    if (!$admins) {
        $companyPhone = sunseaSetting($pdo, 'company_phone', '');
        $wa = $companyPhone ? sunseaWaLink($companyPhone) : '';
        if ($wa !== '') $admins[] = ['label' => 'Admin', 'wa' => $wa];
    }

    return $admins;
}

/**
 * List of all company contact phone numbers: primary "company_phone" plus any lines in the
 * "company_phone_extra" setting (one number per line). Used everywhere contact info is shown
 * (invoice/quotation print header, website footer, kontak page) so adding a number in Pengaturan
 * updates all of them at once.
 */
function sunseaCompanyPhones(PDO $pdo): array
{
    $phones = [];
    $primary = trim(sunseaSetting($pdo, 'company_phone', ''));
    if ($primary !== '') $phones[] = $primary;

    $extraRaw = sunseaSetting($pdo, 'company_phone_extra', '');
    foreach (preg_split('/\r\n|\r|\n/', trim($extraRaw)) as $line) {
        $line = trim($line);
        if ($line !== '' && !in_array($line, $phones, true)) $phones[] = $line;
    }

    return $phones;
}

/**
 * Token untuk share link publik (tanpa login) - dipakai supaya customer bisa buka
 * "Cetak / PDF" penawaran langsung dari link yang dikirim via WhatsApp, tanpa harus login.
 * Tidak bisa ditebak tanpa tahu DB_PASS server (dipakai sebagai kunci HMAC).
 */
function sunseaShareToken(string $type, int $id): string
{
    return substr(hash_hmac('sha256', $type . ':' . $id, DB_PASS . '|sunsea-share-salt'), 0, 24);
}

/**
 * Some packages are priced as a fixed-size group (e.g. "Family Trip untuk 4 Orang",
 * "Honeymoon untuk 2 Orang") - base_price already covers the whole group, so pax MUST
 * NOT be freely multiplied or the quote/invoice becomes wildly overpriced (e.g. 4x).
 * Detects this from either `min_pax === max_pax` (explicit, from Paket Wisata admin form)
 * or a "Pax untuk N Orang" pattern in the package name (fallback for packages where the
 * admin only labelled the name but didn't set min/max_pax). Returns 0 if the package is
 * NOT a fixed-group package (regular per-pax pricing).
 */
function sunseaPackageFixedPax(array $package): int
{
    $minPax = (int)($package['min_pax'] ?? 0);
    $maxPax = (int)($package['max_pax'] ?? 0);
    if ($minPax > 0 && $minPax === $maxPax) {
        return $minPax;
    }
    if (preg_match('/Pax\s+untuk\s+(\d+)\s+Orang/i', (string)($package['name'] ?? ''), $m)) {
        return (int)$m[1];
    }
    return 0;
}

/**
 * List of admin notification emails from the "notif_admin_emails" setting (one address per line).
 */
function sunseaNotifAdminEmails(PDO $pdo): array
{
    $raw = sunseaSetting($pdo, 'notif_admin_emails', '');
    $emails = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $line) {
        $line = trim($line);
        if ($line !== '' && filter_var($line, FILTER_VALIDATE_EMAIL) && !in_array($line, $emails, true)) {
            $emails[] = $line;
        }
    }
    return $emails;
}

/**
 * Email semua admin (setting "notif_admin_emails") saat ada penawaran baru masuk dari website
 * (form quick-quote di beranda / form kontak), lengkap dengan tombol "Follow Up" yang langsung
 * membuka detail penawarannya. Dipanggil setelah INSERT ke tabel quotations selesai. Best-effort:
 * kalau email gagal terkirim (SMTP belum disetel, dsb), gagal senyap - tidak boleh menggagalkan
 * proses booking tamu di website.
 */
function sunseaNotifyAdminNewQuotation(PDO $pdo, int $quotationId): void
{
    try {
        $emails = sunseaNotifAdminEmails($pdo);
        if (!$emails) {
            return;
        }

        require_once __DIR__ . '/../../includes/EmailHelper.php';
        require_once __DIR__ . '/../../includes/SmtpMailer.php';

        $db = Database::getInstance();
        $emailConfig = EmailHelper::resolveConfig($db);
        if ($emailConfig === null) {
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT q.quotation_no, q.trip_date, q.trip_end_date, q.pax_count, q.total_amount, q.notes,
                    c.name AS customer_name, c.phone AS customer_phone,
                    p.name AS package_name
             FROM quotations q
             LEFT JOIN customers c ON c.id = q.customer_id
             LEFT JOIN trip_packages p ON p.id = q.package_id
             WHERE q.id = ?
             LIMIT 1"
        );
        $stmt->execute([$quotationId]);
        $q = $stmt->fetch();
        if (!$q) {
            return;
        }

        $companyName = sunseaSetting($pdo, 'company_name', 'Karimunjawa Explore');
        $followUpUrl = BASE_URL . '/modules/sunsea/quotations.php?action=view&id=' . $quotationId;
        $tripDate = $q['trip_date'] ? date('d M Y', strtotime($q['trip_date'])) : '-';
        $tripEndDate = $q['trip_end_date'] ? date('d M Y', strtotime($q['trip_end_date'])) : '';

        $waMessage = "Halo {$q['customer_name']}, terima kasih sudah menghubungi {$companyName} untuk penawaran No. {$q['quotation_no']}. Kami bantu follow up ya kak.";
        $waUrl = $q['customer_phone'] ? sunseaWaLink($q['customer_phone'], $waMessage) : '';

        $subject = 'Booking Baru Masuk - ' . $q['quotation_no'] . ' (Perlu Follow Up)';
        $bodyHtml = '<div style="font-family:Arial,sans-serif;max-width:520px;">'
            . '<h2 style="color:#0f766e;margin-bottom:4px;">Ada Booking Baru dari Website</h2>'
            . '<p style="color:#334155;">Permintaan penawaran baru masuk dan perlu segera ditindaklanjuti.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:14px 0;font-size:14px;color:#334155;">'
            . '<tr><td style="padding:4px 0;color:#64748b;">No. Penawaran</td><td style="padding:4px 0;font-weight:600;">' . htmlspecialchars($q['quotation_no']) . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#64748b;">Nama Tamu</td><td style="padding:4px 0;font-weight:600;">' . htmlspecialchars($q['customer_name'] ?: '-') . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#64748b;">No. WhatsApp</td><td style="padding:4px 0;font-weight:600;">' . htmlspecialchars($q['customer_phone'] ?: '-') . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#64748b;">Paket</td><td style="padding:4px 0;font-weight:600;">' . htmlspecialchars($q['package_name'] ?: '-') . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#64748b;">Tanggal Trip</td><td style="padding:4px 0;font-weight:600;">' . htmlspecialchars($tripDate . ($tripEndDate ? ' - ' . $tripEndDate : '')) . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#64748b;">Jumlah Pax</td><td style="padding:4px 0;font-weight:600;">' . (int)$q['pax_count'] . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#64748b;">Estimasi Nominal</td><td style="padding:4px 0;font-weight:600;">' . sunseaRupiah((float)$q['total_amount']) . '</td></tr>'
            . '</table>'
            . '<a href="' . htmlspecialchars($followUpUrl) . '" style="display:inline-block;padding:12px 24px;background:#0f766e;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:700;margin-right:10px;">Follow Up Sekarang</a>'
            . ($waUrl !== '' ? '<a href="' . htmlspecialchars($waUrl) . '" style="display:inline-block;padding:12px 24px;background:#25D366;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:700;">Follow Up via WhatsApp</a>' : '')
            . '<p style="color:#94a3b8;font-size:12px;margin-top:18px;">Email otomatis dari sistem ' . htmlspecialchars($companyName) . '.</p>'
            . '</div>';

        $mailer = new SmtpMailer(
            $emailConfig['smtp_host'] ?? $emailConfig['host'],
            (int)($emailConfig['smtp_port'] ?? 465),
            $emailConfig['smtp_encryption'] ?? 'ssl',
            $emailConfig['user'],
            $emailConfig['pass']
        );

        foreach ($emails as $to) {
            try {
                $mailer->send($to, $subject, $bodyHtml, $companyName);
            } catch (Throwable $e) {
                error_log('sunseaNotifyAdminNewQuotation send to ' . $to . ' failed: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('sunseaNotifyAdminNewQuotation error: ' . $e->getMessage());
    }
}
