-- ============================================================
--  KARIMUNJAWA EXPLORE (SUNSEA) - Fresh Standalone Master Schema
--  Dibuat baru dari nol, khusus untuk hosting standalone ini.
--  Import file ini SEBELUM database/sunsea-setup.sql, ke database
--  YANG SAMA (single database dipakai untuk master + data Sunsea).
--
--  Setelah import, login dengan:
--    username: admin
--    password: sunsea123
--  WAJIB ganti password ini setelah login pertama kali!
-- ============================================================

-- ============================================================
-- 1. ROLES
-- ============================================================
CREATE TABLE IF NOT EXISTS `roles` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `role_code`  VARCHAR(30) NOT NULL UNIQUE,
    `role_name`  VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `roles` (`id`, `role_code`, `role_name`) VALUES
(1, 'developer', 'Developer / Owner'),
(2, 'manager',   'Manager'),
(3, 'staff',     'Staff');

-- ============================================================
-- 2. USERS (master)
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
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
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`),
    INDEX idx_username (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin user - username: admin / password: sunsea123 (GANTI setelah login!)
INSERT IGNORE INTO `users` (`id`, `username`, `password`, `full_name`, `role_id`, `business_access`, `is_active`) VALUES
(1, 'admin', '$2y$10$nmsoqYnxHHeyHfkuyilUiOqDUl47zddD8G94xanp6HR6/vHeJdIx2', 'Administrator', 1, 'all', 1);

-- ============================================================
-- 3. BUSINESSES (master) - hanya 1 baris: Sunsea / Explore Karimunjawa
--    PENTING: ganti 'GANTI_NAMA_DATABASE' di bawah dengan nama database
--    yang sama persis dengan yang Anda isi di config/local-db-config.php
--    (karena hosting ini pakai 1 database untuk master + data Sunsea).
-- ============================================================
CREATE TABLE IF NOT EXISTS `businesses` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `business_code`   VARCHAR(50) NOT NULL UNIQUE,
    `business_name`   VARCHAR(150) NOT NULL,
    `business_type`   VARCHAR(50) DEFAULT 'other',
    `database_name`   VARCHAR(100) NOT NULL,
    `slug`            VARCHAR(100),
    `addon_domain`    VARCHAR(150),
    `owner_id`        INT NULL,
    `description`     TEXT,
    `is_active`       TINYINT(1) DEFAULT 1,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_slug (`slug`),
    INDEX idx_active (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `businesses` (`id`, `business_code`, `business_name`, `business_type`, `database_name`, `slug`, `owner_id`, `description`, `is_active`) VALUES
(1, 'SUNSEA', 'Explore Karimunjawa', 'travel_bureau', 'karw6956_explore', 'sunsea', 1, 'Biro Wisata Explore Karimunjawa - Invoice, Penawaran, Kalkulasi Harga', 1);

-- ============================================================
-- 4. MENU ITEMS + BUSINESS MENU CONFIG (kosong, hanya untuk kompatibilitas kode)
-- ============================================================
CREATE TABLE IF NOT EXISTS `menu_items` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `menu_name`   VARCHAR(100) NOT NULL,
    `menu_code`   VARCHAR(50) NOT NULL UNIQUE,
    `menu_icon`   VARCHAR(50),
    `menu_url`    VARCHAR(150),
    `menu_order`  INT DEFAULT 0,
    `is_active`   TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_menu_config` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `business_id`  INT NOT NULL,
    `menu_id`      INT NOT NULL,
    `is_enabled`   TINYINT(1) DEFAULT 1,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_business_menu (`business_id`, `menu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. USER MENU PERMISSIONS (kosong - user 'admin' pakai role developer,
--    jadi otomatis dapat akses penuh selama tabel ini belum diisi baris apapun)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_menu_permissions` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT NOT NULL,
    `business_id` INT NOT NULL,
    `menu_code`   VARCHAR(50) NOT NULL,
    `can_view`    TINYINT(1) DEFAULT 1,
    `can_create`  TINYINT(1) DEFAULT 1,
    `can_edit`    TINYINT(1) DEFAULT 1,
    `can_delete`  TINYINT(1) DEFAULT 1,
    UNIQUE KEY uk_user_biz_menu (`user_id`, `business_id`, `menu_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. AUDIT LOGS (log login/aktivitas)
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT,
    `action_type` VARCHAR(50),
    `table_name`  VARCHAR(100),
    `record_id`   INT,
    `ip_address`  VARCHAR(45),
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (`user_id`),
    INDEX idx_created (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
