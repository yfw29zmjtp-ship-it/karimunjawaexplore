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
 * Resolve the "selected month" (YYYY-MM) shared across owner pages (dashboard,
 * finance, etc.) via session, so picking a month on one page carries over to
 * the others until changed again. A `month` GET param always wins and updates
 * the session; otherwise falls back to the session value, then current month.
 */
function sunseaGetSelectedMonth(): string
{
    $isValid = static function (?string $m): bool {
        return $m && preg_match('/^\d{4}-\d{2}$/', $m) && checkdate((int)substr($m, 5, 2), 1, (int)substr($m, 0, 4));
    };

    if (!empty($_GET['month']) && $isValid($_GET['month'])) {
        $_SESSION['sunsea_selected_month'] = $_GET['month'];
        return $_GET['month'];
    }

    if (!empty($_SESSION['sunsea_selected_month']) && $isValid($_SESSION['sunsea_selected_month'])) {
        return $_SESSION['sunsea_selected_month'];
    }

    return date('Y-m');
}

/**
 * Last 13 months (current + 12 back) as [YYYY-MM => 'Nama Bulan YYYY'] for
 * month-selector dropdowns, newest first.
 */
function sunseaGetMonthOptions(): array
{
    $idMonthNames = ['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'];
    $options = [];
    for ($i = 0; $i < 13; $i++) {
        $key = date('Y-m', strtotime("-{$i} months"));
        $options[$key] = $idMonthNames[substr($key, 5, 2)] . ' ' . substr($key, 0, 4);
    }
    return $options;
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
        // Daftar "Fasilitas yang Didapat" hasil edit manual (1 baris = 1 fasilitas).
        // Kalau NULL, tampilan tetap pakai fallback lama (trip_package_items / package_includes).
        $check->execute(['facilities_override']);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE quotations ADD COLUMN facilities_override TEXT NULL AFTER internal_notes");
        }
        // Rincian peserta + diskon per pax: JSON [{"name","type":"dewasa|anak|lainnya","discount":Rp}].
        // Total diskon per pax tetap dijumlahkan ke discount_amount supaya invoice/booking tidak berubah.
        $check->execute(['pax_breakdown']);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE quotations ADD COLUMN pax_breakdown TEXT NULL AFTER facilities_override");
        }
    } catch (Exception $e) {
        error_log('sunseaEnsureQuotationItinerarySchema error: ' . $e->getMessage());
    }
}

/**
 * Baris "Fasilitas yang didapat" untuk sebuah quotation: pakai override manual bila ada,
 * kalau tidak fallback ke detail layanan paket (trip_package_items) atau teks bebas paket.includes.
 */
function sunseaQuotationFacilityLines(PDO $pdo, array $quotation): array
{
    if (!empty($quotation['facilities_override'])) {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $quotation['facilities_override']) as $line) {
            $line = trim($line);
            if ($line !== '') $lines[] = $line;
        }
        return $lines;
    }

    $lines = [];
    if (!empty($quotation['package_id'])) {
        $pi = $pdo->prepare("SELECT item_name, notes FROM trip_package_items WHERE package_id=? ORDER BY sort_order");
        $pi->execute([(int)$quotation['package_id']]);
        foreach ($pi->fetchAll() as $row) {
            $lines[] = trim($row['item_name']) . (!empty($row['notes']) ? ' (' . trim($row['notes']) . ')' : '');
        }
    }
    if (empty($lines) && !empty($quotation['package_includes'])) {
        foreach (preg_split('/\r\n|\r|\n/', $quotation['package_includes']) as $line) {
            $line = trim($line, " \t-•");
            if ($line !== '') $lines[] = $line;
        }
    }
    return $lines;
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

        // Kolom untuk hak akses tambahan PER USER (di luar role), mis. izinkan satu user
        // Manager/Staff tertentu mengakses menu "Owner Dashboard" tanpa mengubah role-nya.
        // Disimpan sebagai daftar menu key dipisah koma, mis. "owner_dashboard,finance".
        $hasExtraPerm = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'extra_permissions'")->fetch();
        if (!$hasExtraPerm) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `extra_permissions` TEXT NULL AFTER `business_access`");
        }
    } catch (Exception $e) {
        error_log('sunseaEnsureUserSchema error: ' . $e->getMessage());
    }
}

/**
 * Daftar menu key yang izin aksesnya bisa diberikan per-user (di luar role),
 * lewat kolom users.extra_permissions. Dipakai bareng di Setting > User dan
 * di pengecekan akses (owner-*.php, layout-header.php).
 */
function sunseaExtraPermissionOptions(): array
{
    return [
        'owner_dashboard' => 'Owner Dashboard (Booking, Kalender, Invoice & Finance versi HP)',
    ];
}

/** Ambil daftar menu key yang di-grant khusus untuk satu user (dari kolom extra_permissions). */
function sunseaUserExtraPermissions(PDO $pdo, int $userId): array
{
    if ($userId <= 0) return [];
    try {
        $stmt = $pdo->prepare("SELECT extra_permissions FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $raw = (string)$stmt->fetchColumn();
        if ($raw === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($k) => $k !== ''));
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Cek apakah user saat ini boleh mengakses satu menu tertentu: selalu true untuk
 * Developer/Owner, atau kalau menu itu ada di daftar extra_permissions user tsb.
 */
function sunseaCanAccessMenu(PDO $pdo, ?array $currentUser, string $menuKey): bool
{
    $role = $currentUser['role'] ?? '';
    if (in_array($role, ['developer', 'owner'], true)) return true;
    return in_array($menuKey, sunseaUserExtraPermissions($pdo, (int)($currentUser['id'] ?? 0)), true);
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
 * Saldo kas yang dibawa dari periode sebelumnya (akumulasi semua transaksi sebelum $date).
 */
function sunseaCashBalanceBefore(PDO $pdo, string $date): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM cash_book WHERE transaction_date < ?");
    $stmt->execute([$date]);
    return (float)$stmt->fetchColumn();
}

/**
 * Pengeluaran Buku Kas untuk satu booking: yang ditautkan langsung ke booking, plus yang hanya ditautkan ke tamunya
 * (tanpa booking) selama tamu itu cuma punya satu booking - aturan yang sama dengan laporan.php.
 */
function sunseaFetchBookingExpenses(PDO $pdo, int $bookingId): array
{
    $stmt = $pdo->prepare("
        SELECT cb.transaction_date, cb.category, cb.description, cb.amount, cb.reference, cb.created_by
        FROM cash_book cb
        WHERE cb.type = 'expense' AND (
            cb.booking_id = ?
            OR (cb.booking_id IS NULL
                AND cb.customer_id = (SELECT b.customer_id FROM booking_orders b WHERE b.id = ?)
                AND NOT EXISTS (SELECT 1 FROM booking_orders b2 WHERE b2.customer_id = cb.customer_id AND b2.id <> ?))
        )
        ORDER BY cb.transaction_date, cb.id
    ");
    $stmt->execute([$bookingId, $bookingId, $bookingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Profit per trip untuk satu periode (dasar trip, bukan dasar kas):
 * - Pendapatan  = RAB (total harga jual item) booking confirmed/ongoing/completed yang TANGGAL MULAI trip-nya di periode ini,
 *                 kapan pun DP/pelunasannya dibayar.
 * - Biaya trip  = pengeluaran Buku Kas yang tertaut ke booking-booking tsb (aturan sama dgn sunseaFetchBookingExpenses),
 *                 kapan pun dibayarnya.
 * - Biaya ops   = pengeluaran di periode ini (tanggal transaksi) yang tidak tertaut ke trip confirmed mana pun.
 */
function sunseaTripProfit(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $activeStatuses = ['confirmed', 'ongoing', 'completed'];

    $revStmt = $pdo->prepare("
        SELECT COUNT(*) AS trip_count,
               COALESCE(SUM((SELECT COALESCE(SUM(i.total_sell),0) FROM booking_order_items i WHERE i.booking_id = b.id)),0) AS revenue
        FROM booking_orders b
        WHERE b.status IN ('confirmed','ongoing','completed') AND b.start_date BETWEEN ? AND ?
    ");
    $revStmt->execute([$dateFrom, $dateTo]);
    $rev = $revStmt->fetch(PDO::FETCH_ASSOC);

    // Tiap pengeluaran dipetakan ke booking-nya: booking_id langsung, atau lewat tamu kalau tamu itu cuma punya 1 booking.
    $expStmt = $pdo->prepare("
        SELECT cb.amount, cb.transaction_date, bo.start_date, bo.status
        FROM cash_book cb
        LEFT JOIN booking_orders bo ON bo.id = COALESCE(cb.booking_id, (
            SELECT b.id FROM booking_orders b
            WHERE b.customer_id = cb.customer_id
              AND NOT EXISTS (SELECT 1 FROM booking_orders b2 WHERE b2.customer_id = b.customer_id AND b2.id <> b.id)
        ))
        WHERE cb.type = 'expense' AND (cb.transaction_date BETWEEN ? AND ? OR bo.start_date BETWEEN ? AND ?)
    ");
    $expStmt->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);

    $tripCost = 0.0;
    $opsCost = 0.0;
    foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $isTripExpense = $e['status'] !== null && in_array($e['status'], $activeStatuses, true);
        if ($isTripExpense) {
            if ($e['start_date'] >= $dateFrom && $e['start_date'] <= $dateTo) {
                $tripCost += (float)$e['amount'];
            }
        } elseif ($e['transaction_date'] >= $dateFrom && $e['transaction_date'] <= $dateTo) {
            $opsCost += (float)$e['amount'];
        }
    }

    $revenue = (float)$rev['revenue'];
    return [
        'trip_count' => (int)$rev['trip_count'],
        'revenue'    => $revenue,
        'trip_cost'  => $tripCost,
        'ops_cost'   => $opsCost,
        'profit'     => $revenue - $tripCost - $opsCost,
    ];
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
            `period`        VARCHAR(40) NOT NULL COMMENT 'YYYY-MM for recurring, MANUAL-<id> for manual',
            `type`          ENUM('recurring','manual') DEFAULT 'recurring',
            `description`   VARCHAR(255) NULL COMMENT 'Only used for manual invoices',
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

        // Add type/description for invoices created before manual billing existed,
        // and widen period so it can hold both "YYYY-MM" and "MANUAL-<id>" values.
        try {
            $hasType = $pdo->query("SHOW COLUMNS FROM subscription_invoices LIKE 'type'")->fetch();
            if (!$hasType) {
                $pdo->exec("ALTER TABLE subscription_invoices ADD COLUMN type ENUM('recurring','manual') DEFAULT 'recurring' AFTER period");
                $pdo->exec("ALTER TABLE subscription_invoices ADD COLUMN description VARCHAR(255) NULL AFTER type");
            }
            $pdo->exec("ALTER TABLE subscription_invoices MODIFY COLUMN period VARCHAR(40) NOT NULL");
        } catch (Exception $e) {
        }

        // Dedup flag so the overdue push notification only fires once/day per invoice.
        try {
            $hasNotified = $pdo->query("SHOW COLUMNS FROM subscription_invoices LIKE 'overdue_notified_at'")->fetch();
            if (!$hasNotified) {
                $pdo->exec("ALTER TABLE subscription_invoices ADD COLUMN overdue_notified_at DATE NULL AFTER due_date");
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
        'subscription_start_date' => sunseaSetting($pdo, 'subscription_start_date', ''),
        'client_name' => sunseaSetting($pdo, 'subscription_client_name', ''),
        'pakasir_slug' => sunseaSetting($pdo, 'subscription_pakasir_slug', ''),
        'pakasir_api_key' => sunseaSetting($pdo, 'subscription_pakasir_api_key', ''),
        'pakasir_webhook_secret' => sunseaSetting($pdo, 'subscription_pakasir_webhook_secret', ''),
        'provider_name' => sunseaSetting($pdo, 'subscription_provider_name', 'ADF System'),
        'provider_logo' => sunseaSetting($pdo, 'subscription_provider_logo', ''),
        'provider_address' => sunseaSetting($pdo, 'subscription_provider_address', ''),
        'provider_email' => sunseaSetting($pdo, 'subscription_provider_email', ''),
        'last_sync_at' => sunseaSetting($pdo, 'subscription_last_sync_at', ''),
        'last_sync_error' => sunseaSetting($pdo, 'subscription_last_sync_error', ''),
        // 'console' = bayar lewat ADF Console (Mayar), dikirim oleh subscription-config Console; selain itu Pakasir lama
        'payment_gateway' => sunseaSetting($pdo, 'subscription_payment_gateway', 'pakasir'),
    ];
}

function sunseaSubscriptionIsConfigured(array $cfg): bool
{
    if (($cfg['payment_gateway'] ?? '') === 'console') {
        return true;
    }
    return $cfg['pakasir_slug'] !== '' && $cfg['pakasir_api_key'] !== '';
}

/**
 * Link bayar untuk satu tagihan: lewat ADF Console (Mayar, jumlah ditentukan Console) bila payment_gateway = console,
 * selain itu Pakasir lama. Return ['txn_id' => ?string, 'payment_link' => string] atau null.
 * Pembayaran lewat Console ikut lunas di sini lewat paid_periods saat sinkron (sunseaSyncSubscriptionConfig).
 */
function sunseaCreatePaymentLink(PDO $pdo, array $cfg, array $invoice, string $orderId, string $backUrl = ''): ?array
{
    if (($cfg['payment_gateway'] ?? '') !== 'console') {
        return sunseaPakasirCreatePaymentLink($cfg, $orderId, (int) round((float) $invoice['total_amount']));
    }
    $syncUrl = sunseaSetting($pdo, 'subscription_sync_url', 'https://adfsystem.store/api/subscription-config.php');
    $ch = curl_init(str_replace('subscription-config.php', 'subscription-pay.php', $syncUrl));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'client_key' => sunseaSetting($pdo, 'subscription_client_key', ''),
            'client_token' => sunseaSetting($pdo, 'subscription_client_token', ''),
            'period' => (string) $invoice['period'],
            'description' => (string) ($invoice['description'] ?: 'Langganan ' . $invoice['period']),
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string) $response, true);
    if (empty($data['payment_url'])) {
        error_log('sunseaCreatePaymentLink (console): ' . ($data['error'] ?? 'tidak ada respons'));
        return null;
    }
    $url = (string) $data['payment_url'];
    // Halaman bayar ADF: tombol "Kembali" setelah lunas menuju dashboard sistem ini (popup berhasil)
    if ($backUrl !== '' && strpos($url, '/bayar.php?t=') !== false) {
        $url .= '&back=' . rawurlencode($backUrl);
    }
    return ['txn_id' => null, 'payment_link' => $url];
}

/** Tagihan yang sudah lunas di ADF Console (dibayar online / ditandai lunas admin) → ikut lunas di sini. */
function sunseaApplyPaidPeriods(PDO $pdo, array $periods): void
{
    $find = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? AND status = 'unpaid' LIMIT 1");
    $upd = $pdo->prepare("UPDATE subscription_invoices SET status = 'paid', paid_at = NOW() WHERE id = ? AND status = 'unpaid'");
    foreach ($periods as $period) {
        $find->execute([(string) $period]);
        $inv = $find->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            continue;
        }
        $upd->execute([$inv['id']]);
        sunseaNotifyOwnersPush(
            'Pembayaran Berhasil',
            'Tagihan ADF System (' . ($inv['description'] ?: $inv['period']) . ') sebesar ' . sunseaRupiah((float) $inv['total_amount']) . ' sudah lunas.',
            ['url' => 'owner-subscription.php']
        );
    }
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
    sunseaSetSetting($pdo, 'subscription_client_name', (string) ($data['client_name'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_start_date', (string) ($data['subscription_start_date'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_pakasir_slug', (string) ($data['pakasir_slug'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_pakasir_api_key', (string) ($data['pakasir_api_key'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_pakasir_webhook_secret', (string) ($data['pakasir_webhook_secret'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_provider_name', (string) ($data['provider_name'] ?? 'ADF System'));
    sunseaSetSetting($pdo, 'subscription_provider_logo', (string) ($data['provider_logo'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_provider_address', (string) ($data['provider_address'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_provider_email', (string) ($data['provider_email'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_payment_gateway', (string) ($data['payment_gateway'] ?? 'pakasir'));
    sunseaSetSetting($pdo, 'subscription_last_sync_at', date('c'));
    sunseaSyncLedger($pdo, true); // tarif/tagihan dari ADF Store ikut disegarkan
    if (!empty($data['paid_periods']) && is_array($data['paid_periods'])) {
        sunseaApplyPaidPeriods($pdo, $data['paid_periods']); // sebelum sinkron tagihan manual, supaya yang lunas tidak dihapus
    }
    sunseaSetSetting($pdo, 'subscription_last_sync_error', '');

    return sunseaSubscriptionConfig($pdo);
}

/**
 * Pull ad-hoc "manual" invoices ADF System created for this client (e.g. a
 * one-off addon fee) and insert any not already present locally. Idempotent:
 * each manual invoice keeps a deterministic period "MANUAL-<id>" so re-running
 * this never creates duplicates.
 */
function sunseaSyncManualInvoices(PDO $pdo): void
{
    $syncUrl = sunseaSetting($pdo, 'subscription_sync_url', 'https://adfsystem.store/api/subscription-config.php');
    $manualUrl = str_replace('subscription-config.php', 'subscription-manual-invoices.php', $syncUrl);
    $clientKey = sunseaSetting($pdo, 'subscription_client_key', '');
    $clientToken = sunseaSetting($pdo, 'subscription_client_token', '');
    if ($clientKey === '' || $clientToken === '') {
        return;
    }

    try {
        $ch = curl_init($manualUrl);
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
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return;
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['manual_invoices'])) {
            return;
        }

        $seenPeriods = [];
        foreach ($data['manual_invoices'] as $item) {
            $period = 'MANUAL-' . $item['id'];
            $seenPeriods[] = $period;
            $stmt = $pdo->prepare("SELECT id FROM subscription_invoices WHERE period = ? LIMIT 1");
            $stmt->execute([$period]);
            if ($stmt->fetch()) {
                continue;
            }
            $pdo->prepare(
                "INSERT INTO subscription_invoices (period, type, description, base_fee, guest_count, per_guest_fee, guest_total, total_amount, status, due_date)
                 VALUES (?, 'manual', ?, ?, 0, 0, 0, ?, 'unpaid', ?)"
            )->execute([$period, $item['description'], (float) $item['amount'], (float) $item['amount'], $item['due_date']]);

            sunseaNotifyOwnersPush(
                'Tagihan Baru dari ADF System',
                ($item['description'] ?: 'Tagihan tambahan') . ' - ' . sunseaRupiah((float) $item['amount']) . ' (jatuh tempo ' . date('d M Y', strtotime($item['due_date'])) . ')',
                ['url' => 'owner-subscription.php']
            );
        }

        // Remove local manual invoices that were deleted on ADF System's side.
        // Only unpaid ones are pruned so paid history stays intact.
        // Hanya tagihan yang berasal dari ADF (MANUAL-<id>); tagihan susulan lokal (TOPUP-...) jangan ikut terhapus.
        $stmt = $pdo->query("SELECT period FROM subscription_invoices WHERE type = 'manual' AND status = 'unpaid' AND period LIKE 'MANUAL-%'");
        $localPeriods = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stalePeriods = array_diff($localPeriods, $seenPeriods);
        if (!empty($stalePeriods)) {
            $placeholders = implode(',', array_fill(0, count($stalePeriods), '?'));
            $pdo->prepare("DELETE FROM subscription_invoices WHERE type = 'manual' AND status = 'unpaid' AND period LIKE 'MANUAL-%' AND period IN ({$placeholders})")
                ->execute(array_values($stalePeriods));
        }
    } catch (Exception $e) {
        error_log('sunseaSyncManualInvoices error: ' . $e->getMessage());
    }
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

    // Buku tagihan ADF Store tersedia: invoice dihitung & dikelola di sana, di sini hanya dicerminkan.
    if (sunseaSyncLedger($pdo)) {
        $stmt->execute([$period]);
        return $stmt->fetch() ?: null;
    }

    $stmt->execute([$period]);
    $invoice = $stmt->fetch();

    $charge = sunseaCalculateSubscriptionCharge($pdo, $period);
    $cfg = sunseaSubscriptionConfig($pdo);
    $dueDate = sunseaSubscriptionDueDate($period, $cfg['subscription_start_date']);

    if (!$invoice) {
        $pdo->prepare(
            "INSERT INTO subscription_invoices (period, base_fee, guest_count, per_guest_fee, guest_total, total_amount, status, due_date)
             VALUES (?, ?, ?, ?, ?, ?, 'unpaid', ?)"
        )->execute([$period, $charge['base_fee'], $charge['guest_count'], $charge['per_guest_fee'], $charge['guest_total'], $charge['total_amount'], $dueDate]);

        $stmt->execute([$period]);
        $newInvoice = $stmt->fetch() ?: null;
        if ($newInvoice) {
            sunseaNotifyAdfSystemInvoiceCreated($pdo, $newInvoice);
            sunseaNotifyOwnersPush(
                'Tagihan Baru dari ADF System',
                'Tagihan langganan periode ' . $period . ' sebesar ' . sunseaRupiah((float) $newInvoice['total_amount']) . ' jatuh tempo ' . date('d M Y', strtotime($newInvoice['due_date'])) . '.',
                ['url' => 'owner-subscription.php']
            );
        }
        return $newInvoice;
    } elseif ($invoice['status'] === 'unpaid') {
        $pdo->prepare(
            "UPDATE subscription_invoices SET base_fee=?, guest_count=?, per_guest_fee=?, guest_total=?, total_amount=?, due_date=? WHERE period=?"
        )->execute([$charge['base_fee'], $charge['guest_count'], $charge['per_guest_fee'], $charge['guest_total'], $charge['total_amount'], $dueDate, $period]);
    }

    // Tamu yang masuk setelah invoice periode dibayar (dibekukan) ditagih lewat tagihan susulan.
    sunseaSyncGuestTopups($pdo);

    $stmt->execute([$period]);
    return $stmt->fetch() ?: null;
}

/** Jatuh tempo tagihan susulan = hari dibuat + sekian hari (lewat jatuh tempo login non-developer diblokir). */
const SUNSEA_TOPUP_GRACE_DAYS = 14;

/**
 * Tagihan susulan untuk tamu yang terdaftar SETELAH invoice periodenya lunas.
 *
 * Invoice bulanan dibayar di muka lalu dibekukan, jadi booking/pax yang masuk sesudahnya tidak
 * pernah ditagih. Di sini selisihnya dihitung otomatis: tamu periode itu sekarang (booking
 * confirmed/ongoing/completed yang mulai di bulan itu) dikurangi tamu yang sudah tertagih
 * (invoice bulanan + tagihan susulan sebelumnya yang tidak dibatalkan).
 *   - belum ada selisih            -> tidak ada tindakan
 *   - selisih > 0, tidak ada yang menunggu bayar -> buat tagihan susulan baru (+ notifikasi)
 *   - ada tagihan susulan belum dibayar tanpa link bayar -> jumlahnya disesuaikan / dihapus
 *   - ada tagihan susulan belum dibayar dengan link bayar -> dibiarkan (nominal link sudah tetap);
 *     sisa selisih ditagih di tagihan susulan berikutnya setelah itu lunas
 * Tarif per tamu memakai tarif ADF System saat ini. Hanya periode mulai setting
 * `subscription_topup_from_period` (default: bulan saat fitur pertama jalan) sampai bulan depan.
 * Tersimpan sebagai type 'manual' dengan period TOPUP-YYYY-MM-N supaya tampil/cetak/dibayar
 * seperti tagihan manual dan tidak ikut terhapus oleh sinkron tagihan manual ADF.
 */
function sunseaSyncGuestTopups(PDO $pdo): void
{
    static $ran = false; // cukup sekali per request; fungsi ini dipanggil dari beberapa jalur
    if ($ran) {
        return;
    }
    $ran = true;

    try {
        if (sunseaSetting($pdo, 'subscription_client_key', '') === '') {
            return; // belum tersambung ke ADF System: tarif belum pasti
        }
        $cfg = sunseaSubscriptionConfig($pdo);
        $fee = (float) $cfg['per_guest_fee'];
        if ($fee <= 0) {
            return;
        }

        $fromPeriod = sunseaSetting($pdo, 'subscription_topup_from_period', '');
        if (!preg_match('/^\d{4}-\d{2}$/', $fromPeriod)) {
            $fromPeriod = date('Y-m');
            sunseaSetSetting($pdo, 'subscription_topup_from_period', $fromPeriod);
        }
        $toPeriod = date('Y-m', strtotime('first day of next month'));

        $paidStmt = $pdo->prepare(
            "SELECT period, guest_count, base_fee FROM subscription_invoices
             WHERE status = 'paid' AND (type = 'recurring' OR type IS NULL) AND period BETWEEN ? AND ?
             ORDER BY period"
        );
        $paidStmt->execute([$fromPeriod, $toPeriod]);

        foreach ($paidStmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            $period = $inv['period'];
            if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
                continue;
            }
            $guestsNow = (int) sunseaCalculateSubscriptionCharge($pdo, $period)['guest_count'];

            $topStmt = $pdo->prepare(
                "SELECT id, period, status, guest_count, base_fee, txn_id FROM subscription_invoices
                 WHERE period LIKE ? ORDER BY id"
            );
            $topStmt->execute(['TOPUP-' . $period . '-%']);
            $topups = $topStmt->fetchAll(PDO::FETCH_ASSOC);

            $billed = (int) $inv['guest_count'];
            $baseBilled = (float) $inv['base_fee'];
            $pending = null; // tagihan susulan belum dibayar
            foreach ($topups as $t) {
                if ($t['status'] === 'paid' || $t['status'] === 'cancelled') { // dibatalkan = dibebaskan, tidak ditagih ulang
                    $billed += (int) $t['guest_count'];
                    $baseBilled += (float) $t['base_fee'];
                } else {
                    $pending = $t;
                }
            }
            // Selisih yang harus ditagih: tamu baru + kekurangan biaya dasar (invoice lunas dengan tarif lama/lebih kecil).
            $delta = max(0, $guestsNow - $billed);
            $baseDelta = max(0.0, (float) $cfg['base_fee'] - $baseBilled);
            $total = $baseDelta + $delta * $fee;

            if ($pending !== null) {
                if (!empty($pending['txn_id'])) {
                    continue; // link bayar sudah dibuat: nominal dikunci
                }
                if ($delta <= 0 && $baseDelta <= 0) {
                    $pdo->prepare("DELETE FROM subscription_invoices WHERE id = ? AND status = 'unpaid'")->execute([$pending['id']]);
                } elseif ($delta !== (int) $pending['guest_count'] || abs($baseDelta - (float) $pending['base_fee']) > 0.001) {
                    $pdo->prepare(
                        "UPDATE subscription_invoices SET description=?, base_fee=?, guest_count=?, per_guest_fee=?, guest_total=?, total_amount=? WHERE id=? AND status='unpaid'"
                    )->execute([sunseaTopupDescription($period), $baseDelta, $delta, $fee, $delta * $fee, $total, $pending['id']]);
                }
                continue;
            }

            if ($delta <= 0 && $baseDelta <= 0) {
                continue;
            }

            $dueDate = date('Y-m-d', strtotime('+' . SUNSEA_TOPUP_GRACE_DAYS . ' days'));
            $created = null;
            for ($n = count($topups) + 1; $n <= count($topups) + 3 && !$created; $n++) {
                try {
                    $pdo->prepare(
                        "INSERT INTO subscription_invoices (period, type, description, base_fee, guest_count, per_guest_fee, guest_total, total_amount, status, due_date)
                         VALUES (?, 'manual', ?, ?, ?, ?, ?, ?, 'unpaid', ?)"
                    )->execute(['TOPUP-' . $period . '-' . $n, sunseaTopupDescription($period), $baseDelta, $delta, $fee, $delta * $fee, $total, $dueDate]);
                    $created = 'TOPUP-' . $period . '-' . $n;
                } catch (PDOException $e) {
                    // period sudah ada (request paralel / nomor terpakai): coba nomor berikutnya
                }
            }
            if ($created) {
                $q = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? LIMIT 1");
                $q->execute([$created]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    sunseaNotifyAdfSystemInvoiceCreated($pdo, $row);
                    sunseaNotifyOwnersPush(
                        'Tagihan Tamu Tambahan',
                        $row['description'] . ' sebesar ' . sunseaRupiah((float) $row['total_amount']) . ', jatuh tempo ' . date('d M Y', strtotime($row['due_date'])) . '.',
                        ['url' => 'owner-subscription.php']
                    );
                }
            }
        }
    } catch (Exception $e) {
        error_log('sunseaSyncGuestTopups error: ' . $e->getMessage());
    }
}

function sunseaTopupDescription(string $period): string
{
    return 'Tagihan susulan periode ' . $period . ' (biaya bulanan & tamu)';
}

/**
 * Sinkron tagihan dengan ADF Store (buku tagihan di sana adalah sumber kebenaran).
 * Lapor pax per bulan (bulan lalu s/d bulan depan) + invoice lunas lokal + invoice yang link bayarnya
 * sudah dibuat; terima daftar invoice hasil hitungan ADF dan cerminkan ke tabel lokal
 * (batal/edit/lunas dari admin ADF ikut berlaku). Invoice lokal yang belum dibayar, belum punya link
 * bayar, dan tidak dikenal ADF dihapus.
 *
 * @return bool true bila buku tagihan ADF tersedia (maka hitungan lokal tidak dipakai);
 *              false bila belum tersambung / endpoint ADF belum ada (pakai hitungan lokal lama).
 */
/** Tombol/proses bayar baru dibuka mulai H-3 sebelum jatuh tempo (dan tetap terbuka bila sudah lewat). */
const SUNSEA_PAY_OPEN_DAYS_BEFORE = 3;

function sunseaPayOpenDate(array $invoice): ?string
{
    if (empty($invoice['due_date'])) {
        return null;
    }
    return date('Y-m-d', strtotime($invoice['due_date'] . ' -' . SUNSEA_PAY_OPEN_DAYS_BEFORE . ' days'));
}

function sunseaPayWindowOpen(array $invoice): bool
{
    // Tagihan manual dari ADF Console boleh langsung dibayar, tidak menunggu H-3
    if (($invoice['type'] ?? 'recurring') === 'manual') {
        return true;
    }
    $open = sunseaPayOpenDate($invoice);
    return $open === null || date('Y-m-d') >= $open;
}

function sunseaSyncLedger(PDO $pdo, bool $force = false): bool
{
    static $result = null;
    if ($result !== null && !$force) {
        return $result;
    }
    $clientKey = sunseaSetting($pdo, 'subscription_client_key', '');
    $clientToken = sunseaSetting($pdo, 'subscription_client_token', '');
    if ($clientKey === '' || $clientToken === '') {
        return $result = false;
    }
    $last = (int) sunseaSetting($pdo, 'subscription_ledger_last_sync', '0');
    if (!$force && $last > time() - 60) {
        return $result = (sunseaSetting($pdo, 'subscription_ledger_available', '') === '1');
    }

    try {
        $syncUrl = sunseaSetting($pdo, 'subscription_sync_url', 'https://adfsystem.store/api/subscription-config.php');
        $url = str_replace('subscription-config.php', 'subscription-invoices.php', $syncUrl);

        $usage = [];
        foreach ([date('Y-m', strtotime('first day of last month')), date('Y-m'), date('Y-m', strtotime('first day of next month'))] as $p) {
            $usage[$p] = (int) sunseaCalculateSubscriptionCharge($pdo, $p)['guest_count'];
        }
        $paid = $pdo->query(
            "SELECT period AS ref, base_fee, guest_count, per_guest_fee, total_amount, paid_at FROM subscription_invoices
             WHERE status = 'paid' AND (period REGEXP '^[0-9]{4}-[0-9]{2}$' OR period LIKE 'TOPUP-%')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $links = $pdo->query(
            "SELECT period FROM subscription_invoices WHERE status = 'unpaid' AND txn_id IS NOT NULL AND txn_id <> ''
             AND (period REGEXP '^[0-9]{4}-[0-9]{2}$' OR period LIKE 'TOPUP-%')"
        )->fetchAll(PDO::FETCH_COLUMN);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'client_key' => $clientKey,
                'client_token' => $clientToken,
                'usage' => $usage,
                'local_paid' => $paid,
                'links' => $links,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERAGENT => 'KarimunjawaExplore-Ledger/1.0',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = ($response !== false && $httpCode >= 200 && $httpCode < 300) ? json_decode($response, true) : null;
        if (!is_array($data) || empty($data['ledger']) || !isset($data['invoices']) || !is_array($data['invoices'])) {
            sunseaSetSetting($pdo, 'subscription_ledger_last_sync', (string) time());
            sunseaSetSetting($pdo, 'subscription_ledger_available', '0');
            return $result = false;
        }

        $refs = [];
        foreach ($data['invoices'] as $inv) {
            $ref = (string) ($inv['ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $refs[] = $ref;
            $isTopup = ($inv['kind'] ?? '') === 'topup';
            $status = in_array($inv['status'] ?? '', ['unpaid', 'paid', 'cancelled'], true) ? $inv['status'] : 'unpaid';
            $note = trim((string) ($inv['note'] ?? ''));
            // Tamu susulan periode sebelumnya (terdaftar setelah invoice bulan itu dibayar) digabung ke jumlah tamu.
            $carry = (int) ($inv['carry_guests'] ?? 0);
            $inv['guest_count'] = (int) $inv['guest_count'] + $carry;
            $carryNote = $carry > 0 ? 'Termasuk ' . $carry . ' tamu susulan periode ' . ($inv['carry_from'] ?? '') : '';
            $noteAll = trim($carryNote . ($carryNote !== '' && $note !== '' ? ' - ' : '') . $note);
            $desc = $isTopup ? sunseaTopupDescription((string) $inv['period']) . ($noteAll !== '' ? ' - ' . $noteAll : '') : ($noteAll !== '' ? $noteAll : null);
            $guestTotal = (int) $inv['guest_count'] * (float) $inv['per_guest_fee'];
            $dueDate = !empty($inv['due_date']) ? $inv['due_date'] : null;
            $paidAt = !empty($inv['paid_at']) ? date('Y-m-d H:i:s', strtotime($inv['paid_at'])) : null;

            $q = $pdo->prepare("SELECT id, status FROM subscription_invoices WHERE period = ? LIMIT 1");
            $q->execute([$ref]);
            $local = $q->fetch(PDO::FETCH_ASSOC);

            if (!$local) {
                $pdo->prepare(
                    "INSERT INTO subscription_invoices (period, type, description, base_fee, guest_count, per_guest_fee, guest_total, total_amount, status, due_date, paid_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$ref, $isTopup ? 'manual' : 'recurring', $desc, (float) $inv['base_fee'], (int) $inv['guest_count'], (float) $inv['per_guest_fee'], $guestTotal, (float) $inv['total'], $status, $dueDate, $paidAt]);
                if ($status === 'unpaid') {
                    $q->execute([$ref]);
                    $newRow = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? LIMIT 1");
                    $newRow->execute([$ref]);
                    if ($row = $newRow->fetch(PDO::FETCH_ASSOC)) {
                        sunseaNotifyAdfSystemInvoiceCreated($pdo, $row);
                        sunseaNotifyOwnersPush(
                            'Tagihan Baru dari ADF System',
                            ($desc ?: 'Tagihan langganan periode ' . $inv['period']) . ' sebesar ' . sunseaRupiah((float) $inv['total']) . ', jatuh tempo ' . ($dueDate ? date('d M Y', strtotime($dueDate)) : '-') . '.',
                            ['url' => 'owner-subscription.php']
                        );
                    }
                }
            } elseif ($local['status'] === 'unpaid') {
                $pdo->prepare(
                    "UPDATE subscription_invoices SET description=?, base_fee=?, guest_count=?, per_guest_fee=?, guest_total=?, total_amount=?, status=?, due_date=?, paid_at=? WHERE id=?"
                )->execute([$desc, (float) $inv['base_fee'], (int) $inv['guest_count'], (float) $inv['per_guest_fee'], $guestTotal, (float) $inv['total'], $status, $dueDate, $paidAt, $local['id']]);
            }
        }

        // Invoice belum dibayar yang tidak dikenal buku tagihan ADF (mis. hasil hitungan lokal lama) dibuang,
        // kecuali yang sudah punya link bayar.
        $stale = $pdo->query(
            "SELECT id, period FROM subscription_invoices WHERE status = 'unpaid' AND (txn_id IS NULL OR txn_id = '')
             AND (period REGEXP '^[0-9]{4}-[0-9]{2}$' OR period LIKE 'TOPUP-%')"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stale as $s) {
            if (!in_array($s['period'], $refs, true)) {
                $pdo->prepare("DELETE FROM subscription_invoices WHERE id = ? AND status = 'unpaid'")->execute([$s['id']]);
            }
        }

        sunseaSetSetting($pdo, 'subscription_ledger_last_sync', (string) time());
        sunseaSetSetting($pdo, 'subscription_ledger_available', '1');
        return $result = true;
    } catch (Throwable $e) {
        error_log('sunseaSyncLedger error: ' . $e->getMessage());
        return $result = false;
    }
}

/**
 * Compute the due date for a billing period based on the client's
 * subscription start date (its day-of-month becomes the recurring due day).
 * Falls back to the last day of the period's month if no start date is set,
 * or if the anchor day doesn't exist in that month (e.g. 31st in Feb).
 */
function sunseaSubscriptionDueDate(string $period, string $startDate): string
{
    $daysInMonth = (int) date('t', strtotime($period . '-01'));
    if ($startDate === '' || !strtotime($startDate)) {
        return date('Y-m-t', strtotime($period . '-01'));
    }
    $day = min((int) date('j', strtotime($startDate)), $daysInMonth);
    return sprintf('%s-%02d', $period, $day);
}

/**
 * If the nearest unpaid invoice already has a Pakasir txn_id (payment link was
 * generated), re-check its status directly with Pakasir and mark it paid when
 * completed. This is a safety net for missed/delayed webhook deliveries so the
 * system unblocks itself even if the webhook never arrives.
 */
function sunseaReconcilePendingSubscriptionPayment(PDO $pdo, array $invoice): void
{
    if ($invoice['status'] !== 'unpaid' || empty($invoice['txn_id'])) {
        return;
    }
    $cfg = sunseaSubscriptionConfig($pdo);
    $status = sunseaPakasirTransactionStatus($cfg, $invoice['txn_id']);
    if ($status !== null && ($status['status'] ?? '') === 'completed') {
        $paidAt = $status['completed_at'] ?? date('c');
        $pdo->prepare("UPDATE subscription_invoices SET status='paid', paid_at=? WHERE period=?")
            ->execute([$paidAt, $invoice['period']]);
        $invoice['status'] = 'paid';
        $invoice['paid_at'] = $paidAt;
        sunseaNotifyAdfSystemPaymentSuccess($pdo, $invoice);
        $label = ($invoice['type'] ?? 'recurring') === 'manual'
            ? ($invoice['description'] ?: 'Tagihan Manual')
            : ('Periode ' . $invoice['period']);
        sunseaNotifyOwnersPush(
            '✅ Pembayaran Berhasil',
            'Tagihan ADF System (' . $label . ') sebesar ' . sunseaRupiah((float) $invoice['total_amount']) . ' sudah lunas.',
            ['url' => 'owner-subscription.php']
        );
    }
}

/**
 * Nearest unpaid invoice by due date, regardless of how far away it is —
 * used both by the 7-day reminder banner and the always-on header status
 * badge. Reconciles a pending Pakasir payment first so a missed webhook
 * doesn't show a stale unpaid invoice. Returns null when fully caught up
 * (no unpaid invoice exists at all).
 *
 * @param bool $recurringOnly When true, ignores ad-hoc 'manual' invoices —
 *        used by the "Aktif sampai" status badge, which should reflect the
 *        core recurring subscription's due date, not one-off manual charges.
 */
function sunseaGetNearestUnpaidSubscriptionInvoice(PDO $pdo, bool $recurringOnly = false): ?array
{
    $typeFilter = $recurringOnly ? "AND type = 'recurring'" : '';
    try {
        $stmt = $pdo->query(
            "SELECT * FROM subscription_invoices WHERE status = 'unpaid' AND due_date IS NOT NULL {$typeFilter}
             ORDER BY due_date ASC LIMIT 1"
        );
        $invoice = $stmt->fetch();

        if ($invoice && !empty($invoice['txn_id'])) {
            sunseaReconcilePendingSubscriptionPayment($pdo, $invoice);
            $stmt = $pdo->query(
                "SELECT * FROM subscription_invoices WHERE status = 'unpaid' AND due_date IS NOT NULL {$typeFilter}
                 ORDER BY due_date ASC LIMIT 1"
            );
            $invoice = $stmt->fetch();
        }
    } catch (Exception $e) {
        return null;
    }

    return $invoice ?: null;
}

/**
 * Find the nearest unpaid invoice whose due date is within 7 days (or already
 * passed) so the topbar can show a "pay now" reminder banner. Returns null
 * when nothing is due soon. Tagihan manual dari ADF Console yang belum lunas
 * selalu didahulukan & ditampilkan berapa pun sisa harinya (bisa langsung dibayar).
 */
function sunseaGetSubscriptionReminder(PDO $pdo): ?array
{
    $invoice = null;
    try {
        $invoice = $pdo->query("SELECT * FROM subscription_invoices WHERE status = 'unpaid' AND type = 'manual' AND due_date IS NOT NULL ORDER BY due_date ASC LIMIT 1")->fetch() ?: null;
    } catch (Exception $e) {
    }
    $isManual = $invoice !== null;
    if (!$invoice) {
        $invoice = sunseaGetNearestUnpaidSubscriptionInvoice($pdo);
    }
    if (!$invoice) {
        return null;
    }

    $daysLeft = (int) ceil((strtotime($invoice['due_date']) - strtotime(date('Y-m-d'))) / 86400);
    // Tagihan rutin baru muncul saat sudah bisa dibayar (mulai H-3 sebelum jatuh tempo); sebelum itu cukup status hijau "Subscribe s/d …"
    if (!$isManual && !sunseaPayWindowOpen($invoice)) {
        return null;
    }

    // Push once/day (not on every page load) once the invoice is actually overdue.
    if ($daysLeft < 0 && $invoice['overdue_notified_at'] !== date('Y-m-d')) {
        sunseaNotifyOwnersPush(
            'Tagihan Langganan Terlambat',
            'Tagihan ADF System periode ' . $invoice['period'] . ' (' . sunseaRupiah((float) $invoice['total_amount']) . ') sudah melewati jatuh tempo. Segera bayar ya.',
            ['url' => 'owner-subscription.php']
        );
        $pdo->prepare("UPDATE subscription_invoices SET overdue_notified_at = ? WHERE id = ?")
            ->execute([date('Y-m-d'), $invoice['id']]);
    }

    return ['invoice' => $invoice, 'days_left' => $daysLeft, 'overdue' => $daysLeft < 0];
}

/**
 * Most recently paid subscription invoice, if paid within the last $withinHours
 * (default 72h, pass null for no time limit) — used for the "last payment"
 * line in the always-on subscription status popup and (within the window) the
 * one-off "payment successful" banner + print invoice link.
 */
function sunseaGetRecentPaidSubscriptionInvoice(PDO $pdo, ?int $withinHours = 72): ?array
{
    try {
        if ($withinHours === null) {
            $stmt = $pdo->query(
                "SELECT * FROM subscription_invoices WHERE status = 'paid' AND paid_at IS NOT NULL
                 ORDER BY paid_at DESC LIMIT 1"
            );
            return $stmt->fetch() ?: null;
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM subscription_invoices WHERE status = 'paid' AND paid_at IS NOT NULL
             AND paid_at >= ? ORDER BY paid_at DESC LIMIT 1"
        );
        $stmt->execute([date('Y-m-d H:i:s', time() - $withinHours * 3600)]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Tell ADF System a subscription invoice just got paid so it can email the
 * client's configured notify_email automatically (set up from ADF System's
 * own admin panel). Fire-and-forget: failures are logged, never fatal.
 */
function sunseaNotifyAdfSystemPaymentSuccess(PDO $pdo, array $invoice, bool $recordOnly = false): bool
{
    $syncUrl = sunseaSetting($pdo, 'subscription_sync_url', 'https://adfsystem.store/api/subscription-config.php');
    // recordOnly: endpoint khusus pencatatan (tidak pernah kirim email) untuk backfill riwayat.
    $notifyUrl = str_replace('subscription-config.php', $recordOnly ? 'subscription-payment-record.php' : 'subscription-payment-notify.php', $syncUrl);
    $clientKey = sunseaSetting($pdo, 'subscription_client_key', '');
    $clientToken = sunseaSetting($pdo, 'subscription_client_token', '');
    if ($clientKey === '' || $clientToken === '') {
        error_log('sunseaNotifyAdfSystemPaymentSuccess skipped: client_key/client_token not configured');
        return false;
    }

    try {
        $ch = curl_init($notifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'client_key' => $clientKey,
                'client_token' => $clientToken,
                'period' => $invoice['period'],
                'total_amount' => (float) $invoice['total_amount'],
                'paid_at' => $invoice['paid_at'] ?? date('c'),
                // order_id + description supaya ADF System mencatat pembayaran ini di halaman Transaksi-nya.
                'order_id' => $invoice['order_id'] ?? '',
                'description' => ($invoice['type'] ?? 'recurring') === 'manual'
                    ? ($invoice['description'] ?: 'Tagihan Manual')
                    : ('Langganan ' . $invoice['period']),
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERAGENT => 'KarimunjawaExplore-SubscriptionNotify/1.0',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $recordedOk = !$recordOnly || !empty((json_decode((string) $response, true) ?: [])['recorded']);
        if ($curlError !== '' || $httpCode >= 400 || $httpCode === 0 || !$recordedOk) {
            error_log("sunseaNotifyAdfSystemPaymentSuccess failed: url={$notifyUrl} http_code={$httpCode} curl_error={$curlError} response={$response}");
            return false;
        }
        error_log("sunseaNotifyAdfSystemPaymentSuccess ok: http_code={$httpCode} response={$response}");
        return true;
    } catch (Exception $e) {
        error_log('sunseaNotifyAdfSystemPaymentSuccess error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Sekali jalan: kirim (tanpa email) semua tagihan langganan yang SUDAH lunas ke ADF System, supaya
 * pembayaran sebelum fitur pencatatan transaksi ada ikut tampil di halaman Transaksi ADF.
 * Ditandai selesai lewat setting supaya tidak diulang; kalau ada yang gagal, dicoba lagi lain kali.
 */
function sunseaBackfillAdfPaymentHistory(PDO $pdo): void
{
    if (sunseaSetting($pdo, 'subscription_adf_payment_backfill_v1', '') === 'done') {
        return;
    }
    if (sunseaSetting($pdo, 'subscription_client_key', '') === '') {
        return;
    }
    // Kalau ADF System belum siap (endpoint belum ter-deploy), coba lagi paling cepat 1 jam kemudian.
    if ((int) sunseaSetting($pdo, 'subscription_adf_payment_backfill_try', '0') > time() - 3600) {
        return;
    }
    sunseaSetSetting($pdo, 'subscription_adf_payment_backfill_try', (string) time());
    try {
        $paid = $pdo->query("SELECT * FROM subscription_invoices WHERE status = 'paid' ORDER BY paid_at")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return;
    }
    $allOk = true;
    foreach ($paid as $inv) {
        if (!sunseaNotifyAdfSystemPaymentSuccess($pdo, $inv, true)) {
            $allOk = false;
        }
    }
    if ($allOk) {
        sunseaSetSetting($pdo, 'subscription_adf_payment_backfill_v1', 'done');
    }
}

/**
 * Tell ADF System a new subscription invoice/tagihan was just generated so it
 * can email the client's configured notify_email a billing notice. Fire-and-
 * forget: failures are logged, never fatal. Only called for genuinely NEW
 * invoices (not on every unpaid-invoice refresh).
 */
function sunseaNotifyAdfSystemInvoiceCreated(PDO $pdo, array $invoice): void
{
    $syncUrl = sunseaSetting($pdo, 'subscription_sync_url', 'https://adfsystem.store/api/subscription-config.php');
    $notifyUrl = str_replace('subscription-config.php', 'subscription-invoice-notify.php', $syncUrl);
    $clientKey = sunseaSetting($pdo, 'subscription_client_key', '');
    $clientToken = sunseaSetting($pdo, 'subscription_client_token', '');
    if ($clientKey === '' || $clientToken === '') {
        error_log('sunseaNotifyAdfSystemInvoiceCreated skipped: client_key/client_token not configured');
        return;
    }

    try {
        $ch = curl_init($notifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'client_key' => $clientKey,
                'client_token' => $clientToken,
                'period' => $invoice['period'],
                'total_amount' => (float) $invoice['total_amount'],
                'due_date' => $invoice['due_date'],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERAGENT => 'KarimunjawaExplore-SubscriptionNotify/1.0',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($curlError !== '' || $httpCode >= 400) {
            error_log("sunseaNotifyAdfSystemInvoiceCreated failed: url={$notifyUrl} http_code={$httpCode} curl_error={$curlError} response={$response}");
        } else {
            error_log("sunseaNotifyAdfSystemInvoiceCreated ok: http_code={$httpCode} response={$response}");
        }
    } catch (Exception $e) {
        error_log('sunseaNotifyAdfSystemInvoiceCreated error: ' . $e->getMessage());
    }
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

        // Push dikirim terlepas dari setting email admin, supaya owner tetap dapat notif HP
        // walau daftar email notifikasi kosong/belum diisi di pengaturan.
        // Arahkan ke halaman Owner Portal (owner-quotation-detail.php), BUKAN quotations.php
        // di sistem utama - path relatif ke owner-sw.js yang ada di modules/sunsea/.
        sunseaNotifyOwnersPush(
            'Booking Baru Masuk',
            ($q['customer_name'] ?: 'Tamu') . ' - ' . ($q['package_name'] ?: 'Paket') . ' (' . $tripDate . ($tripEndDate && $tripEndDate !== $tripDate ? ' - ' . $tripEndDate : '') . ')',
            ['url' => 'owner-quotation-detail.php?id=' . $quotationId]
        );

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

/**
 * Kirim web push notification ke owner/developer yang sudah subscribe,
 * supaya tetap dapat notifikasi walau app/browser tidak sedang dibuka.
 * Gagal silent (tidak throw) kalau vendor push library belum ke-install.
 */
function sunseaNotifyOwnersPush(string $title, string $body, array $data = []): void
{
    try {
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (!file_exists($autoloadPath)) return;
        require_once __DIR__ . '/../../config/database.php';
        require_once __DIR__ . '/../../includes/PushNotificationHelper.php';

        $push = new PushNotificationHelper(Database::getInstance());
        $push->sendToAdmins($title, $body, $data);
    } catch (Throwable $e) {
        error_log('sunseaNotifyOwnersPush error: ' . $e->getMessage());
    }
}

/**
 * Alamat kembali setelah bayar di halaman ADF Console (&back=…): dashboard sistem ini + ?sub_paid=<tagihan>,
 * supaya dashboard langsung sinkron & menampilkan popup "Pembayaran berhasil".
 */
function sunseaPaidReturnUrl(string $page, string $period): string
{
    return BASE_URL . '/modules/sunsea/' . $page . '?sub_paid=' . rawurlencode($period);
}

/**
 * Popup tagihan langganan (sama dengan sistem ADF lain): ikon, judul sesuai sisa hari, rincian, "Bayar Sekarang"
 * dan "Nanti saja" (disembunyikan sampai browser ditutup). $payAction = halaman yang menerima POST action=pay.
 */
function sunseaRenderBillPopup(array $reminder, bool $configured, string $payAction): void
{
    $inv = $reminder['invoice'];
    $days = (int) $reminder['days_left'];
    $isManual = ($inv['type'] ?? 'recurring') === 'manual';
    $label = $isManual ? ($inv['description'] ?: 'Tagihan Manual') : ('Langganan periode ' . $inv['period']);
    $title = $isManual && $days > 7 ? 'Tagihan Baru dari ADF System'
        : ($days === 0 ? 'Tagihan Jatuh Tempo Hari Ini' : ($days > 0 ? 'Tagihan Jatuh Tempo ' . $days . ' Hari Lagi' : 'Tagihan Lewat Jatuh Tempo'));
    $canPay = $configured && sunseaPayWindowOpen($inv);
    $key = 'ssBillLater_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $inv['period']);
    ?>
    <div class="ss-billpop" id="ssBillPop" style="display:none">
        <div class="ss-billpop-box">
            <div class="ss-billpop-ico">🧾</div>
            <h3><?php echo htmlspecialchars($title); ?></h3>
            <p>Segera selesaikan pembayaran langganan agar sistem tetap bisa digunakan tanpa gangguan.</p>
            <div class="ss-billpop-bill">
                <?php echo htmlspecialchars($label); ?> · <strong><?php echo sunseaRupiah((float) $inv['total_amount']); ?></strong>
                <?php if (!empty($inv['due_date'])): ?> · jatuh tempo <?php echo htmlspecialchars(date('d M Y', strtotime($inv['due_date']))); ?><?php endif; ?>
            </div>
            <?php if ($canPay): ?>
                <form method="POST" action="<?php echo htmlspecialchars($payAction); ?>" style="margin:0">
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="period" value="<?php echo htmlspecialchars($inv['period']); ?>">
                    <button type="submit" class="ss-billpop-btn">Bayar Sekarang</button>
                </form>
            <?php elseif ($configured): ?>
                <p class="ss-billpop-note">Pembayaran dibuka mulai <strong><?php echo date('d M Y', strtotime(sunseaPayOpenDate($inv))); ?></strong>.</p>
            <?php else: ?>
                <p class="ss-billpop-note" style="color:#dc2626">Koneksi pembayaran belum aktif. Hubungi ADF System.</p>
            <?php endif; ?>
            <button type="button" class="ss-billpop-later" onclick="try{sessionStorage.setItem('<?php echo $key; ?>','1')}catch(e){}document.getElementById('ssBillPop').style.display='none'">Nanti saja</button>
        </div>
    </div>
    <script>try { if (!sessionStorage.getItem('<?php echo $key; ?>')) document.getElementById('ssBillPop').style.display = 'flex'; } catch (e) { document.getElementById('ssBillPop').style.display = 'flex'; }</script>
    <?php
    sunseaBillPopupCss();
}

/** Popup setelah kembali dari halaman bayar (?sub_paid=<tagihan>): lunas → berhasil; belum tercatat → sedang diproses. */
function sunseaRenderPaidPopup(PDO $pdo): void
{
    $period = (string) ($_GET['sub_paid'] ?? '');
    if ($period === '') {
        return;
    }
    $st = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? LIMIT 1");
    $st->execute([$period]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return;
    }
    $paid = $inv['status'] === 'paid';
    $label = ($inv['type'] ?? 'recurring') === 'manual' ? ($inv['description'] ?: 'Tagihan Manual') : ('Langganan periode ' . $inv['period']);
    ?>
    <div class="ss-billpop" id="ssPaidPop" style="display:flex">
        <div class="ss-billpop-box">
            <div class="ss-billpop-ico ss-billpop-ok"><?php echo $paid ? '✓' : '⏳'; ?></div>
            <h3><?php echo $paid ? (($inv['type'] ?? '') === 'manual' ? 'Pembayaran Tagihan Manual Berhasil' : 'Pembayaran Berhasil') : 'Pembayaran Sedang Diproses'; ?></h3>
            <p><?php echo $paid ? 'Terima kasih! Tagihan sudah lunas dan bukti pembayaran dikirim ke email / WhatsApp.' : 'Pembayaran Anda sedang dicek. Status tagihan diperbarui otomatis dalam beberapa menit.'; ?></p>
            <div class="ss-billpop-bill"><?php echo htmlspecialchars($label); ?> · <strong><?php echo sunseaRupiah((float) $inv['total_amount']); ?></strong></div>
            <button type="button" class="ss-billpop-btn" onclick="document.getElementById('ssPaidPop').style.display='none';history.replaceState(null,'',location.pathname)">OK</button>
        </div>
    </div>
    <?php
    sunseaBillPopupCss();
}

function sunseaBillPopupCss(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
    <style>
        .ss-billpop { position: fixed; inset: 0; z-index: 100000; background: rgba(15, 23, 42, .78); backdrop-filter: blur(6px); align-items: center; justify-content: center; padding: 20px; }
        .ss-billpop-box { max-width: 380px; width: 100%; background: #fff; color: #1e293b; border-radius: 16px; padding: 26px 22px; text-align: center; box-shadow: 0 24px 60px rgba(0, 0, 0, .35); font-family: inherit; }
        .ss-billpop-ico { font-size: 34px; margin-bottom: 6px; }
        .ss-billpop-ok { width: 56px; height: 56px; margin: 0 auto 10px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: grid; place-items: center; font-size: 28px; font-weight: 800; }
        .ss-billpop-box h3 { margin: 0 0 8px; font-size: 17px; font-weight: 800; color: #0f172a; }
        .ss-billpop-box p { margin: 0 0 14px; font-size: 13px; line-height: 1.5; color: #64748b; }
        .ss-billpop-bill { margin: 0 0 16px; padding: 10px 12px; border-radius: 10px; background: #f1f5f9; font-size: 12.5px; color: #334155; }
        .ss-billpop-btn { display: block; width: 100%; padding: 11px; border: 0; border-radius: 10px; background: linear-gradient(135deg, #f97316, #ea580c); color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .ss-billpop-later { margin-top: 10px; background: none; border: 0; color: #64748b; font-size: 12.5px; cursor: pointer; font-family: inherit; }
        .ss-billpop-note { font-size: 12px !important; }
    </style>
    <?php
}
