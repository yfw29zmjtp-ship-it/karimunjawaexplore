-- ============================================
-- USER AVATARS TABLE
-- Menyimpan metadata file avatar user
-- ============================================

CREATE TABLE IF NOT EXISTS `user_avatars` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) NOT NULL COMMENT 'jpg, png, webp, gif',
    file_size INT NOT NULL COMMENT 'Ukuran file dalam bytes',
    file_path VARCHAR(500) NOT NULL COMMENT 'Relative path: /uploads/avatars/user_123.jpg',
    upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_upload_date (upload_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- NOTES:
-- - file_name: Nama file yang disimpan (misal: user_123.jpg)
-- - file_type: Tipe file image (jpg, png, webp, gif)
-- - file_size: Ukuran file dalam bytes untuk validasi
-- - file_path: Path relatif dari public_html untuk akses web
-- - UNIQUE constraint pada user_id: Satu user hanya punya satu avatar aktif
-- ============================================
