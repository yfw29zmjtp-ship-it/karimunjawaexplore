-- User Permissions System
-- Create table to store user permissions for menu access control

CREATE TABLE IF NOT EXISTS user_permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    permission_key VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_permission (user_id, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permission keys:
-- 'dashboard' - Dashboard
-- 'cashbook' - Buku Kas Besar
-- 'divisions' - Per Divisi
-- 'frontdesk' - Front Desk
-- 'sales_invoice' - Sales Invoice
-- 'procurement' - Procurement
-- 'reports' - Laporan
-- 'users' - Kelola User
-- 'settings' - Pengaturan
