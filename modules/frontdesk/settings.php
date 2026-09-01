<?php

/**
 * FRONT DESK SETTINGS
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================
// SECURITY & AUTHENTICATION
// ============================================
$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();

// Verify permission - with fallback to role-based
if (!$auth->hasPermission('frontdesk')) {
    // Check role-based fallback
    $allowedRoles = ['admin', 'manager'];
    if (!in_array($currentUser['role'], $allowedRoles)) {
        header('Location: ' . BASE_URL . '/403.php');
        exit;
    }
}

$db = Database::getInstance();
$pdo = $db->getConnection();

// Debug: Check which database we're using
$currentDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
error_log("SETTINGS: Using database: " . $currentDb);

// Force fresh schema info
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$activeTab = $_GET['tab'] ?? 'rooms';
$message = '';
$error = '';

// ==================== CHECK IF TABLES EXIST ====================
$tablesExist = false;
try {
    $result = $pdo->query("SHOW TABLES LIKE 'rooms'")->fetch();
    $tablesExist = (is_array($result));
} catch (Exception $e) {
    $tablesExist = false;
}

if (!$tablesExist) {
    $error = "⚠️ Database tables belum diinisialisasi. <a href='" . BASE_URL . "/setup-frontdesk-tables.php' style='color: #6366f1; text-decoration: underline; font-weight: 600;'>Klik di sini untuk setup database FrontDesk</a>";
    $activeTab = 'setup'; // Force to setup tab
}

// ==================== ROOMS MANAGEMENT ====================
if ($activeTab === 'rooms' && $tablesExist) {
    // Add/Edit Room
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'add_room') {
                $stmt = $pdo->prepare("INSERT INTO rooms (room_number, room_type_id, floor_number, status) 
                                     VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['room_number'],
                    $_POST['room_type_id'],
                    $_POST['floor_number'],
                    'available'
                ]);
                $message = "✓ Kamar berhasil ditambahkan!";
            } elseif ($_POST['action'] === 'edit_room') {
                $stmt = $pdo->prepare("UPDATE rooms SET room_number=?, room_type_id=?, floor_number=?, status=? WHERE id=?");
                $stmt->execute([
                    $_POST['room_number'],
                    $_POST['room_type_id'],
                    $_POST['floor_number'],
                    $_POST['status'],
                    $_POST['room_id']
                ]);
                $message = "✓ Kamar berhasil diupdate!";
            } elseif ($_POST['action'] === 'delete_room') {
                $stmt = $pdo->prepare("DELETE FROM rooms WHERE id=?");
                $stmt->execute([$_POST['room_id']]);
                $message = "✓ Kamar berhasil dihapus!";
            }
        } catch (Exception $e) {
            $error = "❌ Error: " . $e->getMessage();
        }
    }

    // Get rooms with types
    try {
        $stmt = $pdo->query("SELECT r.id, r.room_number, r.room_type_id, r.floor_number, r.status, 
                                   rt.type_name, rt.base_price
                            FROM rooms r
                            JOIN room_types rt ON r.room_type_id = rt.id
                            ORDER BY r.floor_number, r.room_number");
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rooms = [];
    }

    // Get room types
    try {
        $stmt = $pdo->query("SELECT id, type_name, base_price FROM room_types ORDER BY type_name");
        $roomTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $roomTypes = [];
    }
}

// ==================== ROOM TYPES MANAGEMENT ====================
elseif ($activeTab === 'room_types') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'add_type') {
                $stmt = $pdo->prepare("INSERT INTO room_types (type_name, base_price, max_occupancy, amenities, color_code) 
                                     VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['type_name'],
                    $_POST['base_price'],
                    $_POST['max_occupancy'],
                    $_POST['amenities'],
                    $_POST['color_code']
                ]);
                $message = "✓ Tipe kamar berhasil ditambahkan!";
            } elseif ($_POST['action'] === 'edit_type') {
                $stmt = $pdo->prepare("UPDATE room_types SET type_name=?, base_price=?, max_occupancy=?, amenities=?, color_code=? WHERE id=?");
                $stmt->execute([
                    $_POST['type_name'],
                    $_POST['base_price'],
                    $_POST['max_occupancy'],
                    $_POST['amenities'],
                    $_POST['color_code'],
                    $_POST['type_id']
                ]);
                $message = "✓ Tipe kamar berhasil diupdate!";
            } elseif ($_POST['action'] === 'delete_type') {
                // Check if there are any bookings with rooms of this type
                $checkBookings = $pdo->prepare("SELECT COUNT(*) FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE r.room_type_id = ?");
                $checkBookings->execute([$_POST['type_id']]);
                $bookingCount = $checkBookings->fetchColumn();

                if ($bookingCount > 0) {
                    $error = "❌ Tidak bisa hapus! Ada {$bookingCount} booking aktif menggunakan kamar dengan tipe ini.";
                } else {
                    // Delete all rooms with this type first
                    $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_type_id=?");
                    $stmt->execute([$_POST['type_id']]);

                    // Then delete the room type
                    $stmt = $pdo->prepare("DELETE FROM room_types WHERE id=?");
                    $stmt->execute([$_POST['type_id']]);
                    $message = "✓ Tipe kamar dan semua kamar terkait berhasil dihapus!";
                }
            }
        } catch (Exception $e) {
            $error = "❌ Error: " . $e->getMessage();
        }
    }

    $stmt = $pdo->query("SELECT id, type_name, base_price, max_occupancy, amenities, color_code FROM room_types ORDER BY type_name");
    $roomTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ==================== BREAKFAST MENU MANAGEMENT ====================
elseif ($activeTab === 'breakfast_menu') {
    $deleteLocalImage = function ($path) {
        $path = trim((string)$path);
        if ($path === '' || strpos($path, 'http') === 0) {
            return;
        }
        $abs = BASE_PATH . '/' . ltrim($path, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    };

    $storeMenuImage = function ($fileKey, $oldPath = '') use ($deleteLocalImage) {
        if (empty($_FILES[$fileKey]) || (int)($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $oldPath;
        }

        if ((int)($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new Exception('Upload gambar menu gagal.');
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $origName = $_FILES[$fileKey]['name'] ?? 'menu-image';
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            throw new Exception('Format gambar harus JPG, JPEG, PNG, WEBP, atau GIF.');
        }

        $maxSize = 5 * 1024 * 1024;
        if ((int)($_FILES[$fileKey]['size'] ?? 0) > $maxSize) {
            throw new Exception('Ukuran gambar maksimal 5MB.');
        }

        $uploadDir = BASE_PATH . '/uploads/breakfast-menu';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $newName = 'menu_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $uploadDir . '/' . $newName;
        if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target)) {
            throw new Exception('Tidak bisa menyimpan gambar menu.');
        }

        if (!empty($oldPath)) {
            $deleteLocalImage($oldPath);
        }

        return 'uploads/breakfast-menu/' . $newName;
    };

    $storePortalLogo = function ($fileKey, $oldPath = '') use ($deleteLocalImage) {
        if (empty($_FILES[$fileKey]) || (int)($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $oldPath;
        }

        if ((int)($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new Exception('Upload logo portal gagal.');
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $origName = $_FILES[$fileKey]['name'] ?? 'portal-logo';
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            throw new Exception('Format logo harus JPG, JPEG, PNG, atau WEBP.');
        }

        $maxSize = 3 * 1024 * 1024;
        if ((int)($_FILES[$fileKey]['size'] ?? 0) > $maxSize) {
            throw new Exception('Ukuran logo maksimal 3MB.');
        }

        $uploadDir = BASE_PATH . '/uploads/portal-assets';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $newName = 'portal_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $uploadDir . '/' . $newName;
        if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target)) {
            throw new Exception('Tidak bisa menyimpan logo portal.');
        }

        if (!empty($oldPath)) {
            $deleteLocalImage($oldPath);
        }

        return 'uploads/portal-assets/' . $newName;
    };

    $defaultPortalInfoText = implode("\n\n", [
        'Hello, here is your breakfast menu selection portal.',
        'Each room has a fixed breakfast allowance.',
        'Please confirm your selection through this link only. If you need to make changes, please contact Front Office.'
    ]);

    $defaultPortalLinkTemplate = implode("\n", [
        'Hello {guest_name},',
        'Please select your breakfast menu using the link below:',
        '{portal_link}',
        '{room_line}',
        'The system will limit the selection based on your breakfast allowance.',
        'Thank you.'
    ]);

    $portalInfoRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_wa_info_text' LIMIT 1");
    $portalInfoText = trim((string)($portalInfoRow['setting_value'] ?? ''));
    if ($portalInfoText === '') {
        $portalInfoText = $defaultPortalInfoText;
    }

    $portalTemplateRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_guest_link_template' LIMIT 1");
    $portalLinkTemplate = trim((string)($portalTemplateRow['setting_value'] ?? ''));
    if ($portalLinkTemplate === '') {
        $portalLinkTemplate = $defaultPortalLinkTemplate;
    }

    $portalLogoRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_portal_logo_path' LIMIT 1");
    $portalLogoPath = trim((string)($portalLogoRow['setting_value'] ?? ''));
    $portalLogoUrl = $portalLogoPath !== ''
        ? ((strpos($portalLogoPath, 'http') === 0) ? $portalLogoPath : BASE_URL . '/' . ltrim($portalLogoPath, '/'))
        : '';

    // Create table if not exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_menus (
            id INT PRIMARY KEY AUTO_INCREMENT,
            menu_name VARCHAR(100) NOT NULL,
            description TEXT,
            category ENUM('western', 'indonesian', 'asian', 'drinks', 'beverages', 'extras') DEFAULT 'western',
            price DECIMAL(10,2) DEFAULT 0.00,
            is_free BOOLEAN DEFAULT TRUE COMMENT 'TRUE = Free breakfast, FALSE = Extra/Paid',
            is_available BOOLEAN DEFAULT TRUE,
            image_url VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_available (is_available),
            INDEX idx_free (is_free)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'save_portal_templates') {
                $infoText = trim((string)($_POST['portal_info_text'] ?? ''));
                if ($infoText === '') {
                    $infoText = $defaultPortalInfoText;
                }

                $template = trim((string)($_POST['portal_link_template'] ?? ''));
                if ($template === '') {
                    $template = $defaultPortalLinkTemplate;
                }

                $newLogoPath = $storePortalLogo('portal_logo', $portalLogoPath);
                if (!empty($_POST['remove_portal_logo'])) {
                    $deleteLocalImage($newLogoPath);
                    $newLogoPath = '';
                }

                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'text') ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute(['breakfast_wa_info_text', $infoText, $infoText]);
                $stmt->execute(['breakfast_guest_link_template', $template, $template]);
                $stmt->execute(['breakfast_portal_logo_path', $newLogoPath, $newLogoPath]);
                $message = "✓ Guest portal text berhasil disimpan!";
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=breakfast_menu");
                exit;
            } elseif ($_POST['action'] === 'add_menu') {
                // Debug log
                error_log("ADD MENU: " . print_r($_POST, true));

                // Check current database and table structure
                $currentDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
                error_log("INSERT TO DATABASE: " . $currentDb);

                // Check if table exists and get columns
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'breakfast_menus'")->fetch();
                if (!$tableCheck) {
                    throw new Exception("Table breakfast_menus tidak ada di database {$currentDb}!");
                }

                $columns = $pdo->query("SHOW COLUMNS FROM breakfast_menus")->fetchAll(PDO::FETCH_COLUMN);
                error_log("AVAILABLE COLUMNS: " . implode(', ', $columns));

                $hasIsFree = in_array('is_free', $columns);
                $hasImageUrl = in_array('image_url', $columns);

                if (!$hasIsFree) {
                    // Try to add the column
                    error_log("KOLOM is_free TIDAK ADA! Mencoba menambahkan...");
                    try {
                        $pdo->exec("ALTER TABLE breakfast_menus ADD COLUMN is_free BOOLEAN DEFAULT TRUE AFTER price");
                        error_log("Kolom is_free berhasil ditambahkan!");
                        $hasIsFree = true;
                    } catch (Exception $e) {
                        error_log("Gagal menambahkan kolom is_free: " . $e->getMessage());
                        // Will try INSERT without is_free column
                    }
                }

                if (!$hasImageUrl) {
                    try {
                        $pdo->exec("ALTER TABLE breakfast_menus ADD COLUMN image_url VARCHAR(255) NULL AFTER is_available");
                        $hasImageUrl = true;
                    } catch (Exception $e) {
                        error_log("Gagal menambahkan kolom image_url: " . $e->getMessage());
                    }
                }

                $menuImagePath = $storeMenuImage('menu_image', '');

                // Try INSERT
                try {
                    if ($hasIsFree) {
                        if ($hasImageUrl) {
                            $stmt = $pdo->prepare("INSERT INTO breakfast_menus (menu_name, description, category, price, is_free, is_available, image_url) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $result = $stmt->execute([
                                $_POST['menu_name'],
                                $_POST['description'] ?? null,
                                $_POST['category'],
                                $_POST['price'] ?? 0,
                                isset($_POST['is_free']) ? 1 : 0,
                                isset($_POST['is_available']) ? 1 : 0,
                                $menuImagePath ?: null
                            ]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO breakfast_menus (menu_name, description, category, price, is_free, is_available) 
                                                 VALUES (?, ?, ?, ?, ?, ?)");
                            $result = $stmt->execute([
                                $_POST['menu_name'],
                                $_POST['description'] ?? null,
                                $_POST['category'],
                                $_POST['price'] ?? 0,
                                isset($_POST['is_free']) ? 1 : 0,
                                isset($_POST['is_available']) ? 1 : 0
                            ]);
                        }
                    } else {
                        // Fallback: INSERT without is_free
                        error_log("FALLBACK: INSERT tanpa kolom is_free");
                        if ($hasImageUrl) {
                            $stmt = $pdo->prepare("INSERT INTO breakfast_menus (menu_name, description, category, price, is_available, image_url) 
                                                 VALUES (?, ?, ?, ?, ?, ?)");
                            $result = $stmt->execute([
                                $_POST['menu_name'],
                                $_POST['description'] ?? null,
                                $_POST['category'],
                                $_POST['price'] ?? 0,
                                isset($_POST['is_available']) ? 1 : 0,
                                $menuImagePath ?: null
                            ]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO breakfast_menus (menu_name, description, category, price, is_available) 
                                                 VALUES (?, ?, ?, ?, ?)");
                            $result = $stmt->execute([
                                $_POST['menu_name'],
                                $_POST['description'] ?? null,
                                $_POST['category'],
                                $_POST['price'] ?? 0,
                                isset($_POST['is_available']) ? 1 : 0
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    error_log("INSERT FAILED: " . $e->getMessage());
                    throw $e;
                }

                error_log("INSERT RESULT: " . ($result ? 'SUCCESS' : 'FAILED'));
                error_log("LAST INSERT ID: " . $pdo->lastInsertId());

                $message = "✓ Menu breakfast berhasil ditambahkan!";

                // Refresh page to show new menu
                header("Location: " . $_SERVER['PHP_SELF'] . "?tab=breakfast_menu");
                exit;
            } elseif ($_POST['action'] === 'edit_menu') {
                $menuId = (int)($_POST['menu_id'] ?? 0);
                $existingMenu = $db->fetchOne("SELECT image_url FROM breakfast_menus WHERE id = ? LIMIT 1", [$menuId]);
                $existingImage = $existingMenu['image_url'] ?? '';

                $newImagePath = $storeMenuImage('menu_image_edit', $existingImage);
                if (!empty($_POST['remove_image'])) {
                    $deleteLocalImage($newImagePath);
                    $newImagePath = '';
                }

                $editColumns = $pdo->query("SHOW COLUMNS FROM breakfast_menus")->fetchAll(PDO::FETCH_COLUMN);
                $hasImageUrlEdit = in_array('image_url', $editColumns);

                if ($hasImageUrlEdit) {
                    $stmt = $pdo->prepare("UPDATE breakfast_menus SET menu_name=?, description=?, category=?, price=?, is_free=?, is_available=?, image_url=? WHERE id=?");
                    $stmt->execute([
                        $_POST['menu_name'],
                        $_POST['description'],
                        $_POST['category'],
                        $_POST['price'],
                        isset($_POST['is_free']) ? 1 : 0,
                        isset($_POST['is_available']) ? 1 : 0,
                        $newImagePath ?: null,
                        $menuId
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE breakfast_menus SET menu_name=?, description=?, category=?, price=?, is_free=?, is_available=? WHERE id=?");
                    $stmt->execute([
                        $_POST['menu_name'],
                        $_POST['description'],
                        $_POST['category'],
                        $_POST['price'],
                        isset($_POST['is_free']) ? 1 : 0,
                        isset($_POST['is_available']) ? 1 : 0,
                        $menuId
                    ]);
                }
                $message = "✓ Menu breakfast berhasil diupdate!";
            } elseif ($_POST['action'] === 'delete_menu') {
                $menuId = (int)($_POST['menu_id'] ?? 0);
                $existingMenu = $db->fetchOne("SELECT image_url FROM breakfast_menus WHERE id = ? LIMIT 1", [$menuId]);
                if (!empty($existingMenu['image_url'])) {
                    $deleteLocalImage($existingMenu['image_url']);
                }
                $stmt = $pdo->prepare("DELETE FROM breakfast_menus WHERE id=?");
                $stmt->execute([$menuId]);
                $message = "✓ Menu breakfast berhasil dihapus!";
            } elseif ($_POST['action'] === 'toggle_availability') {
                $stmt = $pdo->prepare("UPDATE breakfast_menus SET is_available = NOT is_available WHERE id=?");
                $stmt->execute([$_POST['menu_id']]);
                $message = "✓ Status ketersediaan menu berhasil diupdate!";
            }
        } catch (Exception $e) {
            error_log("ERROR BREAKFAST MENU: " . $e->getMessage());
            error_log("ERROR TRACE: " . $e->getTraceAsString());
            $error = "❌ Error: " . $e->getMessage();
        }
    }

    // Get all breakfast menus
    try {
        $stmt = $pdo->query("SELECT 
            id, 
            menu_name, 
            description, 
            category, 
            price, 
            COALESCE(is_free, IF(price = 0, TRUE, FALSE)) as is_free,
            is_available, 
            image_url, 
            created_at, 
            updated_at 
            FROM breakfast_menus 
            ORDER BY category, menu_name");
        $breakfastMenus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $breakfastMenus = [];
    }
}

// ==================== OTA FEES & BOOKING SOURCES MANAGEMENT ====================
elseif ($activeTab === 'ota_fees') {
    // Auto-create booking_sources table if not exists
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS booking_sources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_key VARCHAR(50) NOT NULL UNIQUE,
                source_name VARCHAR(100) NOT NULL,
                source_type ENUM('direct','ota','biro') NOT NULL DEFAULT 'ota',
                fee_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
                icon VARCHAR(10) DEFAULT '🌐',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Seed defaults if table is empty
        $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM booking_sources");
        $count = $countStmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        if ($count == 0) {
            $defaults = [
                ['walk_in', 'Walk-in', 'direct', 0, '🚶', 1],
                ['phone', 'Phone Booking', 'direct', 0, '📞', 2],
                ['online', 'Direct Online', 'direct', 0, '💻', 3],
                ['agoda', 'Agoda', 'ota', 15, '🏨', 10],
                ['booking', 'Booking.com', 'ota', 12, '📱', 11],
                ['tiket', 'Tiket.com', 'ota', 10, '✈️', 12],
                ['traveloka', 'Traveloka', 'ota', 15, '🎫', 13],
                ['airbnb', 'Airbnb', 'ota', 3, '🏠', 14],
                ['expedia', 'Expedia', 'ota', 15, '🗺️', 15],
                ['pegipegi', 'PegiPegi', 'ota', 10, '🧳', 16],
                ['ota', 'OTA Lainnya', 'ota', 10, '🌐', 99],
            ];
            $seedStmt = $pdo->prepare("INSERT INTO booking_sources (source_key, source_name, source_type, fee_percent, icon, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($defaults as $d) {
                $seedStmt->execute($d);
            }
            // Also sync fee % to settings table
            $feeStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'number') ON DUPLICATE KEY UPDATE setting_value=?");
            foreach ($defaults as $d) {
                $sk = 'ota_fee_' . str_replace(['.', '-', ' '], '_', strtolower($d[1]));
                // Use the known mapping for standard keys
                $keyMap = [
                    'agoda' => 'ota_fee_agoda',
                    'booking' => 'ota_fee_booking_com',
                    'tiket' => 'ota_fee_tiket_com',
                    'traveloka' => 'ota_fee_traveloka',
                    'airbnb' => 'ota_fee_airbnb',
                    'expedia' => 'ota_fee_expedia',
                    'pegipegi' => 'ota_fee_pegipegi',
                    'ota' => 'ota_fee_other_ota'
                ];
                if (isset($keyMap[$d[0]])) $sk = $keyMap[$d[0]];
                $feeStmt->execute([$sk, $d[3], $d[3]]);
            }
        }
    } catch (Exception $e) {
        error_log("booking_sources table creation error: " . $e->getMessage());
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'update_fee') {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                $feePercent = (float)($_POST['fee_percentage'] ?? 0);
                if ($feePercent < 0 || $feePercent > 100) throw new Exception("Fee harus antara 0-100%");

                $stmt = $pdo->prepare("UPDATE booking_sources SET fee_percent = ? WHERE id = ?");
                $stmt->execute([$feePercent, $sourceId]);

                // Also sync to settings table
                $src = $pdo->prepare("SELECT source_key FROM booking_sources WHERE id = ?");
                $src->execute([$sourceId]);
                $srcRow = $src->fetch(PDO::FETCH_ASSOC);
                if ($srcRow) {
                    $keyMap = [
                        'agoda' => 'ota_fee_agoda',
                        'booking' => 'ota_fee_booking_com',
                        'bookingcom' => 'ota_fee_booking_com',
                        'tiket' => 'ota_fee_tiket_com',
                        'tiketcom' => 'ota_fee_tiket_com',
                        'traveloka' => 'ota_fee_traveloka',
                        'airbnb' => 'ota_fee_airbnb',
                        'expedia' => 'ota_fee_expedia',
                        'pegipegi' => 'ota_fee_pegipegi',
                        'ota' => 'ota_fee_other_ota'
                    ];
                    $sk = $keyMap[$srcRow['source_key']] ?? ('ota_fee_' . $srcRow['source_key']);
                    $syncStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'number') ON DUPLICATE KEY UPDATE setting_value=?");
                    $syncStmt->execute([$sk, $feePercent, $feePercent]);
                }
                $message = "✓ Fee berhasil diupdate!";
            } elseif ($_POST['action'] === 'add_source') {
                $sourceName = trim($_POST['source_name'] ?? '');
                $sourceKey = trim($_POST['source_key'] ?? '');
                $sourceType = $_POST['source_type'] ?? 'ota';
                $feePercent = (float)($_POST['fee_percentage'] ?? 0);
                $icon = trim($_POST['icon'] ?? '🌐');

                if (empty($sourceName)) throw new Exception("Nama sumber tidak boleh kosong");
                if (empty($sourceKey)) {
                    $sourceKey = strtolower(preg_replace('/[^a-z0-9]/', '_', strtolower($sourceName)));
                    $sourceKey = preg_replace('/_+/', '_', trim($sourceKey, '_'));
                }
                if ($feePercent < 0 || $feePercent > 100) throw new Exception("Fee harus antara 0-100%");
                if (!in_array($sourceType, ['direct', 'ota', 'biro'])) throw new Exception("Tipe tidak valid");

                // Check duplicate
                $chk = $pdo->prepare("SELECT id FROM booking_sources WHERE source_key = ?");
                $chk->execute([$sourceKey]);
                if ($chk->fetch()) throw new Exception("Source key '$sourceKey' sudah ada");

                $maxOrder = $pdo->query("SELECT MAX(sort_order) as mx FROM booking_sources")->fetch(PDO::FETCH_ASSOC)['mx'] ?? 0;

                $stmt = $pdo->prepare("INSERT INTO booking_sources (source_key, source_name, source_type, fee_percent, icon, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sourceKey, $sourceName, $sourceType, $feePercent, $icon, $maxOrder + 1]);

                // Sync to settings table
                $sk = 'ota_fee_' . $sourceKey;
                $syncStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'number') ON DUPLICATE KEY UPDATE setting_value=?");
                $syncStmt->execute([$sk, $feePercent, $feePercent]);

                $message = "✓ Sumber booking '" . htmlspecialchars($sourceName) . "' berhasil ditambahkan!";
            } elseif ($_POST['action'] === 'edit_source') {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                $sourceName = trim($_POST['source_name'] ?? '');
                $sourceType = $_POST['source_type'] ?? 'ota';
                $feePercent = (float)($_POST['fee_percentage'] ?? 0);
                $icon = trim($_POST['icon'] ?? '🌐');

                if (empty($sourceName)) throw new Exception("Nama sumber tidak boleh kosong");
                if ($feePercent < 0 || $feePercent > 100) throw new Exception("Fee harus antara 0-100%");

                $stmt = $pdo->prepare("UPDATE booking_sources SET source_name = ?, source_type = ?, fee_percent = ?, icon = ? WHERE id = ?");
                $stmt->execute([$sourceName, $sourceType, $feePercent, $icon, $sourceId]);

                // Sync fee to settings
                $src = $pdo->prepare("SELECT source_key FROM booking_sources WHERE id = ?");
                $src->execute([$sourceId]);
                $srcRow = $src->fetch(PDO::FETCH_ASSOC);
                if ($srcRow) {
                    $keyMap = [
                        'agoda' => 'ota_fee_agoda',
                        'booking' => 'ota_fee_booking_com',
                        'tiket' => 'ota_fee_tiket_com',
                        'traveloka' => 'ota_fee_traveloka',
                        'airbnb' => 'ota_fee_airbnb',
                        'expedia' => 'ota_fee_expedia',
                        'pegipegi' => 'ota_fee_pegipegi',
                        'ota' => 'ota_fee_other_ota'
                    ];
                    $sk = $keyMap[$srcRow['source_key']] ?? ('ota_fee_' . $srcRow['source_key']);
                    $syncStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'number') ON DUPLICATE KEY UPDATE setting_value=?");
                    $syncStmt->execute([$sk, $feePercent, $feePercent]);
                }
                $message = "✓ Sumber booking berhasil diupdate!";
            } elseif ($_POST['action'] === 'delete_source') {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                // Don't allow deleting core sources
                $src = $pdo->prepare("SELECT source_key FROM booking_sources WHERE id = ?");
                $src->execute([$sourceId]);
                $srcRow = $src->fetch(PDO::FETCH_ASSOC);
                $coreSources = ['walk_in', 'phone', 'online', 'agoda', 'booking', 'tiket', 'airbnb', 'ota'];
                if ($srcRow && in_array($srcRow['source_key'], $coreSources)) {
                    throw new Exception("Sumber bawaan tidak bisa dihapus, tapi bisa dinonaktifkan");
                }
                $pdo->prepare("DELETE FROM booking_sources WHERE id = ?")->execute([$sourceId]);
                $message = "✓ Sumber booking berhasil dihapus!";
            } elseif ($_POST['action'] === 'toggle_source') {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                $pdo->prepare("UPDATE booking_sources SET is_active = NOT is_active WHERE id = ?")->execute([$sourceId]);
                $message = "✓ Status sumber booking berhasil diubah!";
            }
        } catch (Exception $e) {
            $error = "❌ Error: " . $e->getMessage();
        }
    }

    // Load all booking sources
    $bookingSources = [];
    try {
        $stmt = $pdo->query("SELECT * FROM booking_sources ORDER BY source_type ASC, sort_order ASC, source_name ASC");
        $bookingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Load booking sources error: " . $e->getMessage());
    }
}

// ==================== INVOICE SETTINGS & QRIS MANAGEMENT ====================
elseif ($activeTab === 'invoice_settings') {
    // Handle save accounting info
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_accounting') {
        try {
            $accName = trim($_POST['accounting_name'] ?? '');
            $accTitle = trim($_POST['accounting_title'] ?? 'Accounting Staff');

            $stmt1 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'string') 
                                  ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt1->execute(['invoice_accounting_name_' . ACTIVE_BUSINESS_ID, $accName, $accName]);

            $stmt2 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'string') 
                                  ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt2->execute(['invoice_accounting_title_' . ACTIVE_BUSINESS_ID, $accTitle, $accTitle]);

            $message = "✓ Informasi accounting berhasil disimpan!";
        } catch (Exception $e) {
            $error = "❌ Error: " . $e->getMessage();
        }
    }

    // Handle save bank info (Account Number, Account Name, Swift Code)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_bank_info') {
        try {
            $bankAccNumber = trim($_POST['bank_account_number'] ?? '');
            $bankAccName = trim($_POST['bank_account_name'] ?? '');
            $swiftCode = trim($_POST['swift_code'] ?? '');

            $stmt1 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'string') 
                                  ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt1->execute(['invoice_bank_account_number_' . ACTIVE_BUSINESS_ID, $bankAccNumber, $bankAccNumber]);

            $stmt2 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'string') 
                                  ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt2->execute(['invoice_bank_account_name_' . ACTIVE_BUSINESS_ID, $bankAccName, $bankAccName]);

            $stmt3 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'string') 
                                  ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt3->execute(['invoice_swift_code_' . ACTIVE_BUSINESS_ID, $swiftCode, $swiftCode]);

            $message = "✓ Bank info berhasil disimpan!";
        } catch (Exception $e) {
            $error = "❌ Error: " . $e->getMessage();
        }
    }

    // Handle QRIS upload (DEPRECATED - keeping for backward compat but hiding from UI)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_qris') {
        header('Content-Type: application/json');

        try {
            if (!isset($_FILES['qris_image']) || $_FILES['qris_image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload failed');
            }

            $file = $_FILES['qris_image'];
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 2 * 1024 * 1024; // 2MB

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedMimes)) {
                throw new Exception('Invalid file type. Only JPG, PNG, GIF, WebP allowed');
            }

            if ($file['size'] > $maxSize) {
                throw new Exception('File too large. Max 2MB');
            }

            // Create uploads/qris directory if not exists
            $qrisDir = '../../uploads/qris';
            if (!is_dir($qrisDir)) {
                mkdir($qrisDir, 0755, true);
            }

            // Generate filename
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = 'qris_' . ACTIVE_BUSINESS_ID . '_' . time() . '.' . $ext;
            $filepath = $qrisDir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('Failed to save file');
            }

            // Save to settings
            $settingKey = 'invoice_qris_' . ACTIVE_BUSINESS_ID;
            $settingValue = 'uploads/qris/' . $filename;

            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'string') 
                                 ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$settingKey, $settingValue, $settingValue]);

            echo json_encode(['success' => true, 'message' => 'QRIS berhasil diupload']);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Handle QRIS delete
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_qris') {
        header('Content-Type: application/json');

        try {
            $settingKey = 'invoice_qris_' . ACTIVE_BUSINESS_ID;

            // Get current file to delete
            $getStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $getStmt->execute([$settingKey]);
            $fileData = $getStmt->fetch(PDO::FETCH_ASSOC);

            if ($fileData && !empty($fileData['setting_value'])) {
                $filePath = '../../' . $fileData['setting_value'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Delete from settings
            $delStmt = $pdo->prepare("DELETE FROM settings WHERE setting_key = ?");
            $delStmt->execute([$settingKey]);

            echo json_encode(['success' => true, 'message' => 'QRIS deleted']);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Get current QRIS
    $qrisRow = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $qrisRow->execute(['invoice_qris_' . ACTIVE_BUSINESS_ID]);
    $qrisData = $qrisRow->fetch(PDO::FETCH_ASSOC);
    $qrisUrl = $qrisData['setting_value'] ?? null;

    if (!empty($qrisUrl) && strpos($qrisUrl, 'http') !== 0) {
        $qrisUrl = BASE_URL . '/' . $qrisUrl;
    }
}

include '../../includes/header.php';
?>

<style>
    :root {
        --primary: #6366f1;
        --primary-dark: #4f46e5;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
        --bg-secondary: rgba(255, 255, 255, 0.08);
        --border-color: rgba(255, 255, 255, 0.15);
        --text-primary: var(--text-color);
        --text-secondary: rgba(255, 255, 255, 0.7);
    }

    .settings-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 2rem;
    }

    .settings-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        gap: 2rem;
    }

    .settings-header h1 {
        font-size: 2.5rem;
        font-weight: 950;
        background: linear-gradient(135deg, var(--primary), #8b5cf6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin: 0;
    }

    .settings-nav {
        display: flex;
        gap: 1rem;
        margin-bottom: 2rem;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 0;
    }

    .tab-btn {
        padding: 1rem 1.5rem;
        background: transparent;
        border: none;
        color: var(--text-secondary);
        font-weight: 700;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.3s ease;
        font-size: 1rem;
    }

    .tab-btn.active {
        color: var(--primary);
        border-bottom-color: var(--primary);
    }

    .tab-btn:hover {
        color: var(--text-primary);
    }

    .message {
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 2rem;
        font-weight: 600;
        animation: slideIn 0.3s ease;
    }

    .message.success {
        background: rgba(16, 185, 129, 0.2);
        border: 1px solid rgba(16, 185, 129, 0.5);
        color: #6ee7b7;
    }

    .message.error {
        background: rgba(239, 68, 68, 0.2);
        border: 1px solid rgba(239, 68, 68, 0.5);
        color: #fca5a5;
    }

    @keyframes slideIn {
        from {
            transform: translateY(-10px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 700;
        color: var(--text-primary);
        font-size: 0.95rem;
    }

    .form-input,
    .form-select,
    .form-textarea {
        width: 100%;
        padding: 0.75rem 1rem;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        color: var(--text-primary);
        font-family: inherit;
        font-size: 1rem;
        transition: all 0.3s ease;
    }

    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: var(--primary);
        background: rgba(99, 102, 241, 0.1);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 1rem;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary), #8b5cf6);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(99, 102, 241, 0.3);
    }

    .btn-success {
        background: linear-gradient(135deg, var(--success), #34d399);
        color: white;
    }

    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3);
    }

    .btn-danger {
        background: linear-gradient(135deg, var(--danger), #f87171);
        color: white;
    }

    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(239, 68, 68, 0.3);
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
    }

    .table-wrapper {
        background: var(--bg-secondary);
        backdrop-filter: blur(30px);
        border: 1px solid var(--border-color);
        border-radius: 15px;
        overflow: hidden;
        margin-top: 2rem;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
    }

    .table thead {
        background: rgba(99, 102, 241, 0.1);
        border-bottom: 2px solid var(--border-color);
    }

    .table th {
        padding: 1rem;
        text-align: left;
        font-weight: 700;
        color: var(--primary);
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }

    .table td {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .table tbody tr:hover {
        background: rgba(99, 102, 241, 0.05);
    }

    .badge {
        display: inline-block;
        padding: 0.4rem 0.8rem;
        border-radius: 6px;
        font-weight: 700;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .badge-primary {
        background: rgba(99, 102, 241, 0.2);
        color: #a5b4fc;
    }

    .badge-success {
        background: rgba(16, 185, 129, 0.2);
        color: #6ee7b7;
    }

    .badge-danger {
        background: rgba(239, 68, 68, 0.2);
        color: #fca5a5;
    }

    .form-card {
        background: var(--bg-secondary);
        backdrop-filter: blur(30px);
        border: 1px solid var(--border-color);
        border-radius: 15px;
        padding: 2rem;
        margin-bottom: 2rem;
    }

    .color-picker-wrapper {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .color-picker {
        width: 60px;
        height: 60px;
        border: 2px solid var(--border-color);
        border-radius: 8px;
        cursor: pointer;
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
    }

    .modal-form {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .modal-form.show {
        display: flex;
    }

    .modal-content {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 15px;
        padding: 2rem;
        max-width: 500px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .modal-close {
        background: none;
        border: none;
        color: var(--text-secondary);
        font-size: 1.5rem;
        cursor: pointer;
    }

    .modal-close:hover {
        color: var(--text-primary);
    }

    .responsive-table {
        overflow-x: auto;
    }

    @media (max-width: 768px) {
        .settings-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .settings-header h1 {
            font-size: 1.75rem;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .settings-nav {
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
        }

        .table {
            font-size: 0.85rem;
        }

        .table th,
        .table td {
            padding: 0.75rem;
        }
    }
</style>

<div class="settings-container">
    <!-- Header -->
    <div class="settings-header">
        <div>
            <h1>⚙️ Settings & Configuration</h1>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                Manage rooms, room types, and OTA fees
            </p>
        </div>
        <div style="display: flex; gap: 1rem;">
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/index.php" class="btn btn-primary" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                🏁 Back to FrontDesk
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/dashboard.php" class="btn btn-primary">
                📊 Full Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Messages -->
<?php if ($message): ?>
    <div class="message success"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="message error"><?php echo $error; ?></div>
<?php endif; ?>

<?php if (!$tablesExist): ?>
    <div style="background: rgba(245, 158, 11, 0.1); border: 2px solid #f59e0b; border-radius: 12px; padding: 2rem; margin-bottom: 2rem; text-align: center;">
        <h3 style="color: #d97706; margin: 0 0 1rem 0;">⚠️ Database Setup Required</h3>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">FrontDesk tables belum diinisialisasi. Silakan setup database terlebih dahulu.</p>
        <a href="<?php echo BASE_URL; ?>/setup-frontdesk-tables.php" style="background: linear-gradient(135deg, #f59e0b, #fbbf24); color: white; padding: 0.75rem 1.5rem; border-radius: 10px; text-decoration: none; display: inline-block; font-weight: 600;">
            🔧 Setup Database Now
        </a>
    </div>
<?php else: ?>

    <!-- Tabs Navigation -->
    <div class="settings-nav">
        <button class="tab-btn <?php echo $activeTab === 'rooms' ? 'active' : ''; ?>"
            onclick="location.href='?tab=rooms'">
            🚪 Manage Rooms
        </button>
        <button class="tab-btn <?php echo $activeTab === 'room_types' ? 'active' : ''; ?>"
            onclick="location.href='?tab=room_types'">
            🏢 Room Types
        </button>
        <button class="tab-btn <?php echo $activeTab === 'breakfast_menu' ? 'active' : ''; ?>"
            onclick="location.href='?tab=breakfast_menu'">
            🍳 Breakfast Menu
        </button>
        <button class="tab-btn <?php echo $activeTab === 'ota_fees' ? 'active' : ''; ?>"
            onclick="location.href='?tab=ota_fees'">
            💰 OTA Fees
        </button>
        <button class="tab-btn <?php echo $activeTab === 'invoice_settings' ? 'active' : ''; ?>"
            onclick="location.href='?tab=invoice_settings'">
            🧾 Invoice Settings
        </button>
    </div>

    <!-- ==================== ROOMS TAB ==================== -->
    <?php if ($activeTab === 'rooms'): ?>

        <div class="form-card">
            <h2 style="margin-top: 0; color: var(--primary);">➕ Add New Room</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_room">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Room Number</label>
                        <input type="text" name="room_number" class="form-input" placeholder="e.g., 101" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Room Type</label>
                        <select name="room_type_id" class="form-select" required>
                            <option value="">-- Select Type --</option>
                            <?php foreach ($roomTypes as $type): ?>
                                <option value="<?php echo $type['id']; ?>">
                                    <?php echo $type['type_name']; ?> (Rp <?php echo number_format($type['base_price']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Floor Number</label>
                        <input type="number" name="floor_number" class="form-input" placeholder="e.g., 1" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">✓ Add Room</button>
            </form>
        </div>

        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Room Number</th>
                        <th>Type</th>
                        <th>Floor</th>
                        <th>Base Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $room): ?>
                        <tr>
                            <td><strong>🚪 <?php echo htmlspecialchars($room['room_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($room['type_name']); ?></td>
                            <td><span class="badge badge-primary">Floor <?php echo $room['floor_number']; ?></span></td>
                            <td>Rp <?php echo number_format($room['base_price']); ?></td>
                            <td>
                                <span class="badge <?php echo $room['status'] === 'available' ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo ucfirst($room['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" class="btn btn-primary btn-sm"
                                        onclick="editRoom(<?php echo htmlspecialchars(json_encode($room)); ?>)">
                                        ✏️ Edit
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_room">
                                        <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Hapus kamar ini?')">
                                            🗑️ Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

    <!-- ==================== ROOM TYPES TAB ==================== -->
    <?php if ($activeTab === 'room_types'): ?>

        <div class="form-card">
            <h2 style="margin-top: 0; color: var(--primary);">➕ Add New Room Type</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_type">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Type Name</label>
                        <input type="text" name="type_name" class="form-input" placeholder="e.g., Deluxe Suite" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Base Price (IDR)</label>
                        <input type="number" name="base_price" class="form-input" placeholder="e.g., 500000" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Occupancy</label>
                        <input type="number" name="max_occupancy" class="form-input" placeholder="e.g., 2" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Amenities</label>
                    <textarea name="amenities" class="form-textarea" placeholder="e.g., King Bed, AC, WiFi, TV" required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Color Code</label>
                    <div class="color-picker-wrapper">
                        <input type="color" name="color_code" class="color-picker" value="#6366f1" required>
                        <input type="text" name="color_code" class="form-input" placeholder="#6366f1" value="#6366f1" style="max-width: 150px;">
                    </div>
                </div>

                <button type="submit" class="btn btn-success">✓ Add Room Type</button>
            </form>
        </div>

        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Type Name</th>
                        <th>Base Price</th>
                        <th>Occupancy</th>
                        <th>Amenities</th>
                        <th>Color</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roomTypes as $type): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($type['type_name']); ?></strong></td>
                            <td>Rp <?php echo number_format($type['base_price']); ?></td>
                            <td><span class="badge badge-primary"><?php echo $type['max_occupancy']; ?> pax</span></td>
                            <td style="font-size: 0.85rem;">
                                <?php
                                $amenities = explode(',', $type['amenities']);
                                foreach (array_slice($amenities, 0, 2) as $amenity) {
                                    echo trim($amenity) . '<br>';
                                }
                                if (count($amenities) > 2) {
                                    echo '...dan ' . (count($amenities) - 2) . ' lagi<br>';
                                }
                                ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <div style="width: 30px; height: 30px; background: <?php echo $type['color_code']; ?>; border-radius: 6px; border: 1px solid var(--border-color);"></div>
                                    <span style="font-size: 0.85rem;"><?php echo $type['color_code']; ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" class="btn btn-primary btn-sm"
                                        onclick="editRoomType(<?php echo htmlspecialchars(json_encode($type)); ?>)">
                                        ✏️ Edit
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_type">
                                        <input type="hidden" name="type_id" value="<?php echo $type['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Hapus tipe kamar ini?\n\n⚠️ PERHATIAN: Semua kamar dengan tipe ini akan ikut terhapus!')">
                                            🗑️ Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

    <!-- ==================== BREAKFAST MENU TAB ==================== -->
    <?php if ($activeTab === 'breakfast_menu'): ?>

        <div class="form-card">
            <h2 style="margin-top: 0; color: var(--primary);">💬 Guest Portal Text</h2>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                Use this section to edit the text shown on the portal and the WhatsApp message sent from Front Desk. Placeholders: <strong>{guest_name}</strong>, <strong>{room_label}</strong>, <strong>{room_line}</strong>, <strong>{portal_link}</strong>.
            </p>
            <form method="POST" enctype="multipart/form-data" style="margin-top: 1rem;">
                <input type="hidden" name="action" value="save_portal_templates">
                <div class="form-group">
                    <label class="form-label">Portal Info Text</label>
                    <textarea name="portal_info_text" class="form-textarea" rows="6" style="white-space: pre-wrap; font-family: inherit;"><?php echo htmlspecialchars($portalInfoText); ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">WhatsApp Link Template</label>
                    <textarea name="portal_link_template" class="form-textarea" rows="8" style="white-space: pre-wrap; font-family: inherit;"><?php echo htmlspecialchars($portalLinkTemplate); ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Portal Header Logo (Narayana)</label>
                    <input type="file" name="portal_logo" class="form-input" accept=".jpg,.jpeg,.png,.webp">
                    <small style="color: var(--text-secondary); display:block; margin-top:.35rem;">Format: JPG/JPEG/PNG/WEBP, max 3MB. Recommended transparent PNG.</small>
                    <?php if ($portalLogoUrl !== ''): ?>
                        <div style="margin-top:.6rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                            <img src="<?php echo htmlspecialchars($portalLogoUrl); ?>" alt="Portal logo" style="height:48px;max-width:200px;object-fit:contain;background:rgba(15,23,42,.08);padding:6px 8px;border-radius:8px;border:1px solid var(--border-color);">
                            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                                <input type="checkbox" name="remove_portal_logo" value="1">
                                <span>Remove current logo</span>
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-success">💾 Save Templates</button>
            </form>
        </div>

        <div class="form-card">
            <h2 style="margin-top: 0; color: var(--primary);">➕ Add New Breakfast Menu</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_menu">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Menu Name</label>
                        <input type="text" name="menu_name" class="form-input" placeholder="e.g., American Breakfast" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select" required>
                            <option value="western">🍳 Western</option>
                            <option value="indonesian">🍛 Indonesian</option>
                            <option value="asian">🍜 Asian</option>
                            <option value="drinks">🥤 Drinks</option>
                            <option value="extras">➕ Extra (Berbayar)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Price (Rp) - Kosongkan jika gratis</label>
                        <input type="number" name="price" class="form-input" placeholder="e.g., 35000 (0 untuk gratis)" step="0.01" min="0" value="0" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea" placeholder="e.g., Eggs, bacon, sausage, toast, hash browns"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Gambar Menu (opsional)</label>
                    <input type="file" name="menu_image" class="form-input" accept=".jpg,.jpeg,.png,.webp,.gif">
                    <small style="color: var(--text-secondary); display:block; margin-top:.35rem;">Format: JPG/JPEG/PNG/WEBP/GIF, max 5MB</small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin-bottom: 0.5rem;">
                        <input type="checkbox" name="is_free" checked style="width: 20px; height: 20px;">
                        <span style="font-weight: 700;">🆓 Free Breakfast (Included in room rate)</span>
                    </label>
                    <small style="color: var(--text-secondary); display: block; margin-left: 28px;">
                        Unchecked = Extra Breakfast (Paid/Berbayar)
                    </small>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="is_available" checked style="width: 20px; height: 20px;">
                        <span>Available for ordering</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-success">✓ Add Menu</button>
            </form>
        </div>

        <div class="table-wrapper">
            <h3 style="color: var(--text-primary); margin-bottom: 1rem;">📋 Breakfast Menu List</h3>

            <?php
            $categories = [
                'western' => '🍳 Western (Free)',
                'indonesian' => '🍛 Indonesian (Free)',
                'asian' => '🍜 Asian (Free)',
                'drinks' => '🥤 Drinks (Free)',
                'extras' => '➕ Extra Breakfast (Berbayar)'
            ];

            foreach ($categories as $catKey => $catLabel):
                $categoryMenus = array_filter($breakfastMenus, fn($m) => $m['category'] === $catKey);
                if (empty($categoryMenus)) continue;
            ?>

                <div style="margin-bottom: 2rem;">
                    <h4 style="color: var(--primary); margin: 1rem 0 0.5rem 0;"><?php echo $catLabel; ?></h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Menu Name</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categoryMenus as $menu): ?>
                                <?php
                                // Ensure is_free has a value (default based on price)
                                $isFree = isset($menu['is_free']) ? (bool)$menu['is_free'] : ($menu['price'] == 0);
                                $imageUrl = trim((string)($menu['image_url'] ?? ''));
                                $imageSrc = $imageUrl !== '' ? ((strpos($imageUrl, 'http') === 0) ? $imageUrl : BASE_URL . '/' . ltrim($imageUrl, '/')) : '';
                                ?>
                                <tr style="<?php echo $menu['is_available'] ? '' : 'opacity: 0.5;'; ?>">
                                    <td>
                                        <?php if ($imageSrc !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($menu['menu_name']); ?>" style="width:54px;height:54px;object-fit:cover;border-radius:8px;border:1px solid var(--border-color);">
                                        <?php else: ?>
                                            <span style="font-size:.75rem;color:var(--text-secondary);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($menu['menu_name']); ?></strong></td>
                                    <td style="font-size: 0.85rem; color: var(--text-secondary);">
                                        <?php echo htmlspecialchars($menu['description'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="<?php echo $isFree ? 'background: rgba(16, 185, 129, 0.2); color: #6ee7b7;' : 'background: rgba(245, 158, 11, 0.2); color: #fbbf24;'; ?>">
                                            <?php echo $isFree ? '🆓 Free' : '💰 Paid'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isFree || $menu['price'] == 0): ?>
                                            <span style="color: var(--text-secondary);">-</span>
                                        <?php else: ?>
                                            <strong>Rp <?php echo number_format($menu['price'], 0, ',', '.'); ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_availability">
                                            <input type="hidden" name="menu_id" value="<?php echo $menu['id']; ?>">
                                            <button type="submit" class="badge" style="border: none; cursor: pointer; padding: 0.5rem 1rem; <?php echo $menu['is_available'] ? 'background: rgba(16, 185, 129, 0.2); color: #6ee7b7;' : 'background: rgba(239, 68, 68, 0.2); color: #fca5a5;'; ?>">
                                                <?php echo $menu['is_available'] ? '✓ Available' : '✗ Unavailable'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-primary btn-sm"
                                                onclick="editBreakfastMenu(<?php echo htmlspecialchars(json_encode($menu)); ?>)">
                                                ✏️ Edit
                                            </button>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_menu">
                                                <input type="hidden" name="menu_id" value="<?php echo $menu['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Hapus menu ini?')">
                                                    🗑️ Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <!-- ==================== OTA FEES & BOOKING SOURCES TAB ==================== -->
    <?php if ($activeTab === 'ota_fees'): ?>

        <!-- ADD NEW SOURCE FORM -->
        <div class="form-card">
            <h2 style="margin-top: 0; color: var(--primary);">➕ Tambah Sumber Booking Baru</h2>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                Tambahkan OTA baru, biro perjalanan, atau sumber booking lain beserta persentase komisi/fee-nya.
            </p>
            <form method="POST" style="margin-top: 1rem;">
                <input type="hidden" name="action" value="add_source">
                <div style="display: grid; grid-template-columns: auto 1fr 1fr auto auto auto; gap: 1rem; align-items: end;">
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Icon</label>
                        <input type="text" name="icon" value="🌐" class="form-input" style="width: 60px; text-align: center; font-size: 1.3rem;" maxlength="5">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Nama Sumber</label>
                        <input type="text" name="source_name" class="form-input" placeholder="Contoh: Traveloka, Biro XYZ" required>
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Tipe</label>
                        <select name="source_type" class="form-input">
                            <option value="ota">OTA</option>
                            <option value="biro">Biro / Agen</option>
                            <option value="direct">Direct (tanpa fee)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Fee %</label>
                        <input type="number" name="fee_percentage" value="0" min="0" max="100" step="0.5" class="form-input" style="width: 80px; text-align: center;">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">Key (opsional)</label>
                        <input type="text" name="source_key" class="form-input" placeholder="auto" style="width: 120px;" pattern="[a-z0-9_]*" title="Huruf kecil, angka, underscore saja">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-success" style="white-space: nowrap;">➕ Tambah</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- EXISTING BOOKING SOURCES -->
        <div class="form-card">
            <h2 style="margin-top: 0; color: var(--primary);">💰 Booking Sources & OTA Fees</h2>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                Atur sumber booking dan persentase komisi. Fee otomatis dikurangi dari harga booking di buku kas.
            </p>

            <?php
            $grouped = ['direct' => [], 'ota' => [], 'biro' => []];
            foreach ($bookingSources as $src) {
                $grouped[$src['source_type']][] = $src;
            }
            $typeLabels = ['direct' => '🚶 Direct (Tanpa Fee)', 'ota' => '🌐 Online Travel Agency (OTA)', 'biro' => '🏢 Biro / Agen Perjalanan'];
            ?>

            <?php foreach ($typeLabels as $type => $label): ?>
                <?php if (!empty($grouped[$type])): ?>
                    <h3 style="margin: 2rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem; border-bottom: 2px solid var(--border-color); padding-bottom: 0.5rem;">
                        <?php echo $label; ?>
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
                        <?php foreach ($grouped[$type] as $src): ?>
                            <div id="source-card-<?php echo $src['id']; ?>" style="background: <?php echo $src['is_active'] ? 'rgba(99, 102, 241, 0.1)' : 'rgba(150, 150, 150, 0.1)'; ?>; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem; opacity: <?php echo $src['is_active'] ? '1' : '0.6'; ?>; transition: all 0.3s;">

                                <!-- View Mode -->
                                <div id="view-<?php echo $src['id']; ?>">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                        <h3 style="margin: 0; color: var(--text-primary); font-size: 1.05rem;">
                                            <?php echo htmlspecialchars($src['icon'] . ' ' . $src['source_name']); ?>
                                        </h3>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <?php if (!$src['is_active']): ?>
                                                <span style="background: rgba(239,68,68,0.2); color: #ef4444; padding: 2px 8px; border-radius: 8px; font-size: 0.75rem;">Nonaktif</span>
                                            <?php endif; ?>
                                            <span style="background: rgba(99,102,241,0.2); color: var(--primary); padding: 2px 8px; border-radius: 8px; font-size: 0.75rem;">
                                                <?php echo strtoupper($src['source_type']); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                                        <span style="font-size: 2rem; font-weight: 800; color: <?php echo $src['fee_percent'] > 0 ? '#ef4444' : '#10b981'; ?>;">
                                            <?php echo number_format($src['fee_percent'], 1); ?>%
                                        </span>
                                        <span style="color: var(--text-secondary); font-size: 0.85rem;">
                                            komisi/fee
                                        </span>
                                    </div>

                                    <!-- Quick Fee Update -->
                                    <form method="POST" style="display: flex; gap: 0.5rem; margin-bottom: 0.75rem;">
                                        <input type="hidden" name="action" value="update_fee">
                                        <input type="hidden" name="source_id" value="<?php echo $src['id']; ?>">
                                        <input type="range" min="0" max="30" step="0.5"
                                            value="<?php echo $src['fee_percent']; ?>"
                                            oninput="document.getElementById('feeNum<?php echo $src['id']; ?>').value=this.value"
                                            style="flex: 1;">
                                        <input type="number" id="feeNum<?php echo $src['id']; ?>" name="fee_percentage" min="0" max="100" step="0.5"
                                            value="<?php echo $src['fee_percent']; ?>"
                                            oninput="this.previousElementSibling.value=this.value"
                                            style="width: 65px; text-align: center; padding: 4px; border: 1px solid var(--border-color); border-radius: 6px; background: transparent; color: var(--text-primary);">
                                        <span style="display: flex; align-items: center; color: var(--text-secondary); font-weight: 700;">%</span>
                                        <button type="submit" class="btn btn-success" style="padding: 4px 12px; font-size: 0.85rem;">✓</button>
                                    </form>

                                    <!-- Action Buttons -->
                                    <div style="display: flex; gap: 0.5rem; border-top: 1px solid var(--border-color); padding-top: 0.75rem;">
                                        <button type="button" onclick="showEditMode(<?php echo $src['id']; ?>)" class="btn" style="flex: 1; padding: 6px; font-size: 0.8rem; background: rgba(99,102,241,0.2); color: var(--primary); border: none; border-radius: 6px; cursor: pointer;">
                                            ✏️ Edit
                                        </button>
                                        <form method="POST" style="flex: 0;">
                                            <input type="hidden" name="action" value="toggle_source">
                                            <input type="hidden" name="source_id" value="<?php echo $src['id']; ?>">
                                            <button type="submit" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: rgba(245,158,11,0.2); color: #f59e0b; border: none; border-radius: 6px; cursor: pointer;" title="<?php echo $src['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>">
                                                <?php echo $src['is_active'] ? '🔒' : '🔓'; ?>
                                            </button>
                                        </form>
                                        <?php
                                        $coreSources = ['walk_in', 'phone', 'online', 'agoda', 'booking', 'tiket', 'airbnb', 'ota'];
                                        if (!in_array($src['source_key'], $coreSources)):
                                        ?>
                                            <form method="POST" style="flex: 0;" onsubmit="return confirm('Hapus sumber booking <?php echo htmlspecialchars($src['source_name']); ?>?')">
                                                <input type="hidden" name="action" value="delete_source">
                                                <input type="hidden" name="source_id" value="<?php echo $src['id']; ?>">
                                                <button type="submit" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: rgba(239,68,68,0.2); color: #ef4444; border: none; border-radius: 6px; cursor: pointer;">🗑️</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Edit Mode (hidden by default) -->
                                <div id="edit-<?php echo $src['id']; ?>" style="display: none;">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="edit_source">
                                        <input type="hidden" name="source_id" value="<?php echo $src['id']; ?>">
                                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                            <div style="display: flex; gap: 0.5rem;">
                                                <div style="flex: 0 0 60px;">
                                                    <label style="font-size: 0.75rem; color: var(--text-secondary);">Icon</label>
                                                    <input type="text" name="icon" value="<?php echo htmlspecialchars($src['icon']); ?>" class="form-input" style="text-align: center; font-size: 1.2rem;" maxlength="5">
                                                </div>
                                                <div style="flex: 1;">
                                                    <label style="font-size: 0.75rem; color: var(--text-secondary);">Nama</label>
                                                    <input type="text" name="source_name" value="<?php echo htmlspecialchars($src['source_name']); ?>" class="form-input" required>
                                                </div>
                                            </div>
                                            <div style="display: flex; gap: 0.5rem;">
                                                <div style="flex: 1;">
                                                    <label style="font-size: 0.75rem; color: var(--text-secondary);">Tipe</label>
                                                    <select name="source_type" class="form-input">
                                                        <option value="direct" <?php echo $src['source_type'] === 'direct' ? 'selected' : ''; ?>>Direct</option>
                                                        <option value="ota" <?php echo $src['source_type'] === 'ota' ? 'selected' : ''; ?>>OTA</option>
                                                        <option value="biro" <?php echo $src['source_type'] === 'biro' ? 'selected' : ''; ?>>Biro / Agen</option>
                                                    </select>
                                                </div>
                                                <div style="flex: 0 0 100px;">
                                                    <label style="font-size: 0.75rem; color: var(--text-secondary);">Fee %</label>
                                                    <input type="number" name="fee_percentage" value="<?php echo $src['fee_percent']; ?>" min="0" max="100" step="0.5" class="form-input" style="text-align: center;">
                                                </div>
                                            </div>
                                            <div style="display: flex; gap: 0.5rem;">
                                                <button type="submit" class="btn btn-success" style="flex: 1;">✓ Simpan</button>
                                                <button type="button" onclick="hideEditMode(<?php echo $src['id']; ?>)" class="btn" style="padding: 8px 16px; background: rgba(150,150,150,0.2); color: var(--text-secondary); border: none; border-radius: 6px; cursor: pointer;">Batal</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Fee Calculation Info -->
        <div class="form-card" style="background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3);">
            <h3 style="margin-top: 0; color: #6ee7b7;">📊 Cara Kerja Fee</h3>
            <ul style="color: var(--text-secondary); line-height: 2; margin: 0.5rem 0 0 0; padding-left: 1.2rem;">
                <li><strong>OTA & Biro</strong>: Fee otomatis dikurangi dari total harga saat masuk buku kas. Contoh: Harga Rp 500.000, Fee 15% = Net Income Rp 425.000</li>
                <li><strong>Direct</strong>: Tidak ada potongan fee. 100% masuk buku kas.</li>
                <li><strong>Booking OTA</strong>: Pembayaran baru masuk buku kas saat tamu <strong>check-in</strong>.</li>
                <li><strong>Tambah Sumber</strong>: Anda bisa tambahkan biro perjalanan lokal dengan fee tetap (contoh: Biro ABC fee 8%).</li>
            </ul>
        </div>

        <script>
            function showEditMode(id) {
                document.getElementById('view-' + id).style.display = 'none';
                document.getElementById('edit-' + id).style.display = 'block';
            }

            function hideEditMode(id) {
                document.getElementById('edit-' + id).style.display = 'none';
                document.getElementById('view-' + id).style.display = 'block';
            }
        </script>

    <?php endif; ?>

    <!-- ==================== INVOICE SETTINGS TAB ==================== -->
    <?php if ($activeTab === 'invoice_settings'): ?>

        <div class="form-card">
            <h2 style="margin-top: 0; color: var(--primary);">🏦 Bank Transfer Info</h2>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                Setup informasi rekening bank untuk ditampilkan di invoice
            </p>

            <form method="POST" style="margin-top: 1.5rem;">
                <div class="form-group">
                    <label class="form-label">Nomor Rekening</label>
                    <input type="text" name="bank_account_number" class="form-input"
                        value="<?php
                                $bankAccRow = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                                $bankAccRow->execute(['invoice_bank_account_number_' . ACTIVE_BUSINESS_ID]);
                                $bankAccData = $bankAccRow->fetch(PDO::FETCH_ASSOC);
                                echo htmlspecialchars($bankAccData['setting_value'] ?? '');
                                ?>"
                        placeholder="Contoh: 1926663992">
                    <small style="color: var(--text-secondary); display: block; margin-top: 0.3rem;">
                        Nomor rekening BNI (tanpa spasi)
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label">Atas Nama (a.n)</label>
                    <input type="text" name="bank_account_name" class="form-input"
                        value="<?php
                                $bankNameRow = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                                $bankNameRow->execute(['invoice_bank_account_name_' . ACTIVE_BUSINESS_ID]);
                                $bankNameData = $bankNameRow->fetch(PDO::FETCH_ASSOC);
                                echo htmlspecialchars($bankNameData['setting_value'] ?? '');
                                ?>"
                        placeholder="Contoh: PT. NARAYANA KARIMUNJAWA">
                    <small style="color: var(--text-secondary); display: block; margin-top: 0.3rem;">
                        Nama pemilik rekening
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label">Swift Code (BIC)</label>
                    <input type="text" name="swift_code" class="form-input"
                        value="<?php
                                $swiftRow = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                                $swiftRow->execute(['invoice_swift_code_' . ACTIVE_BUSINESS_ID]);
                                $swiftData = $swiftRow->fetch(PDO::FETCH_ASSOC);
                                echo htmlspecialchars($swiftData['setting_value'] ?? '');
                                ?>"
                        placeholder="Contoh: BNIAIDJA">
                    <small style="color: var(--text-secondary); display: block; margin-top: 0.3rem;">
                        Kode Swift untuk transfer internasional (BIC Code)
                    </small>
                </div>

                <button type="submit" name="action" value="save_bank_info" class="btn btn-success" style="width: 100%;">✓ Simpan Bank Info</button>
            </form>
        </div>

        <div class="form-card">
            <h2 style="margin-top: 0; color: var(--primary);">📝 Invoice Signature</h2>
            <p style="color: var(--text-secondary); margin-top: 0.5rem;">
                Setup tanda tangan Accounting yang akan tampil di invoice
            </p>

            <form method="POST" style="margin-top: 1.5rem;">
                <div class="form-group">
                    <label class="form-label">Nama Accounting / Finance Staff</label>
                    <input type="text" name="accounting_name" class="form-input" readonly
                        value="<?php
                                // Try to get saved name first
                                $accRow = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                                $accRow->execute(['invoice_accounting_name_' . ACTIVE_BUSINESS_ID]);
                                $accData = $accRow->fetch(PDO::FETCH_ASSOC);
                                $savedName = $accData['setting_value'] ?? '';

                                // If no saved name, use current user's full name
                                if (empty($savedName)) {
                                    $savedName = $currentUser['full_name'] ?? '';
                                }

                                echo htmlspecialchars($savedName);
                                ?>"
                        placeholder="Auto-populated from your login">
                    <small style="color: var(--text-secondary); display: block; margin-top: 0.3rem;">
                        Auto-filled dengan nama user login Anda
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label">Judul / Posisi</label>
                    <input type="text" name="accounting_title" class="form-input"
                        value="<?php
                                $titleRow = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                                $titleRow->execute(['invoice_accounting_title_' . ACTIVE_BUSINESS_ID]);
                                $titleData = $titleRow->fetch(PDO::FETCH_ASSOC);
                                echo htmlspecialchars($titleData['setting_value'] ?? 'Accounting Staff');
                                ?>"
                        placeholder="Contoh: Accounting Staff">
                </div>

                <button type="submit" name="action" value="save_accounting" class="btn btn-success" style="width: 100%;">✓ Simpan Info Accounting</button>
            </form>
        </div>

        <script>
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('qrisFile');

            dropZone.addEventListener('click', () => fileInput.click());
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.style.borderColor = '#8b5cf6';
                dropZone.style.background = 'rgba(139, 92, 246, 0.05)';
            });
            dropZone.addEventListener('dragleave', () => {
                dropZone.style.borderColor = '#6366f1';
                dropZone.style.background = '';
            });
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.style.borderColor = '#6366f1';
                dropZone.style.background = '';
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    handleFileSelect();
                }
            });

            function handleFileSelect() {
                const file = fileInput.files[0];
                if (!file) return;

                const status = document.getElementById('uploadStatus');
                status.innerHTML = '⏳ Uploading: ' + file.name;
                status.style.color = '#f59e0b';

                document.getElementById('qrisUploadForm').submit();
            }

            function deleteQRIS() {
                if (!confirm('Hapus QRIS saat ini?')) return;

                const fd = new FormData();
                fd.append('action', 'delete_qris');

                fetch(window.location.href, {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            alert('✓ QRIS deleted');
                            location.reload();
                        } else {
                            alert('Error: ' + d.message);
                        }
                    })
                    .catch(e => alert('Error: ' + e.message));
            }
        </script>

    <?php endif; ?>

<?php endif; // Close if $tablesExist 
?>

</div>

<!-- Modal Edit Room Type -->
<div id="editRoomTypeModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--card-bg); padding: 2rem; border-radius: 12px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h2 style="margin-top: 0; color: var(--primary);">✏️ Edit Room Type</h2>
        <form method="POST" id="editRoomTypeForm">
            <input type="hidden" name="action" value="edit_type">
            <input type="hidden" name="type_id" id="edit_type_id">

            <div class="form-group">
                <label class="form-label">Type Name</label>
                <input type="text" name="type_name" id="edit_type_name" class="form-input" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Base Price (IDR)</label>
                    <input type="number" name="base_price" id="edit_base_price" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Max Occupancy</label>
                    <input type="number" name="max_occupancy" id="edit_max_occupancy" class="form-input" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Amenities</label>
                <textarea name="amenities" id="edit_amenities" class="form-textarea" required></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Color Code</label>
                <div class="color-picker-wrapper">
                    <input type="color" id="edit_color_code_picker" class="color-picker" required onchange="document.getElementById('edit_color_code').value = this.value">
                    <input type="text" name="color_code" id="edit_color_code" class="form-input" placeholder="#6366f1" required style="max-width: 150px;">
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-success" style="flex: 1;">✓ Update</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Room -->
<div id="editRoomModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--card-bg); padding: 2rem; border-radius: 12px; max-width: 500px; width: 90%;">
        <h2 style="margin-top: 0; color: var(--primary);">✏️ Edit Room</h2>
        <form method="POST" id="editRoomForm">
            <input type="hidden" name="action" value="edit_room">
            <input type="hidden" name="room_id" id="edit_room_id">

            <div class="form-group">
                <label class="form-label">Room Number</label>
                <input type="text" name="room_number" id="edit_room_number" class="form-input" placeholder="e.g., 101" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Room Type</label>
                    <select name="room_type_id" id="edit_room_type_id" class="form-input" required>
                        <option value="">-- Select Type --</option>
                        <?php foreach ($roomTypes as $type): ?>
                            <option value="<?php echo $type['id']; ?>">
                                <?php echo $type['type_name']; ?> (Rp <?php echo number_format($type['base_price']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Floor Number</label>
                    <input type="number" name="floor_number" id="edit_floor_number" class="form-input" placeholder="e.g., 1" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" id="edit_status" class="form-input" required>
                    <option value="available">Available</option>
                    <option value="occupied">Occupied</option>
                    <option value="cleaning">Cleaning</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="blocked">Blocked</option>
                </select>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-success" style="flex: 1;">✓ Update</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditRoomModal()" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Breakfast Menu -->
<div id="editBreakfastModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--card-bg); padding: 2rem; border-radius: 12px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h2 style="margin-top: 0; color: var(--primary);">✏️ Edit Breakfast Menu</h2>
        <form method="POST" id="editBreakfastForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_menu">
            <input type="hidden" name="menu_id" id="edit_menu_id">
            <input type="hidden" id="edit_image_url" value="">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Menu Name</label>
                    <input type="text" name="menu_name" id="edit_menu_name" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" id="edit_category" class="form-select" required>
                        <option value="western">🍳 Western</option>
                        <option value="indonesian">🍛 Indonesian</option>
                        <option value="asian">🍜 Asian</option>
                        <option value="drinks">🥤 Drinks</option>
                        <option value="extras">➕ Extra (Berbayar)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Price (Rp)</label>
                    <input type="number" name="price" id="edit_price" class="form-input" step="0.01" min="0" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" id="edit_description" class="form-textarea"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Gambar Menu (opsional)</label>
                <div id="edit_image_preview" style="margin-bottom:.5rem;color:var(--text-secondary);font-size:.85rem;">Belum ada gambar</div>
                <input type="file" name="menu_image_edit" id="edit_menu_image" class="form-input" accept=".jpg,.jpeg,.png,.webp,.gif">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin-top:.45rem;">
                    <input type="checkbox" name="remove_image" id="edit_remove_image" style="width:16px;height:16px;">
                    <span>Hapus gambar saat ini</span>
                </label>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin-bottom: 0.5rem;">
                    <input type="checkbox" name="is_free" id="edit_is_free" style="width: 20px; height: 20px;">
                    <span style="font-weight: 700;">🆓 Free Breakfast</span>
                </label>
                <small style="color: var(--text-secondary); display: block; margin-left: 28px;">
                    Unchecked = Extra Breakfast (Paid)
                </small>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="is_available" id="edit_is_available" style="width: 20px; height: 20px;">
                    <span>Available for ordering</span>
                </label>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-success" style="flex: 1;">✓ Update</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditBreakfastModal()" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function editRoom(room) {
        document.getElementById('edit_room_id').value = room.id;
        document.getElementById('edit_room_number').value = room.room_number;
        document.getElementById('edit_room_type_id').value = room.room_type_id;
        document.getElementById('edit_floor_number').value = room.floor_number;
        document.getElementById('edit_status').value = room.status;

        const modal = document.getElementById('editRoomModal');
        modal.style.display = 'flex';
    }

    function closeEditRoomModal() {
        document.getElementById('editRoomModal').style.display = 'none';
    }

    function editRoomType(type) {
        document.getElementById('edit_type_id').value = type.id;
        document.getElementById('edit_type_name').value = type.type_name;
        document.getElementById('edit_base_price').value = type.base_price;
        document.getElementById('edit_max_occupancy').value = type.max_occupancy;
        document.getElementById('edit_amenities').value = type.amenities;
        document.getElementById('edit_color_code').value = type.color_code;
        document.getElementById('edit_color_code_picker').value = type.color_code;

        const modal = document.getElementById('editRoomTypeModal');
        modal.style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editRoomTypeModal').style.display = 'none';
    }

    function editBreakfastMenu(menu) {
        document.getElementById('edit_menu_id').value = menu.id;
        document.getElementById('edit_menu_name').value = menu.menu_name;
        document.getElementById('edit_category').value = menu.category;
        document.getElementById('edit_price').value = menu.price;
        document.getElementById('edit_description').value = menu.description || '';
        document.getElementById('edit_is_free').checked = menu.is_free == 1;
        document.getElementById('edit_is_available').checked = menu.is_available == 1;
        document.getElementById('edit_remove_image').checked = false;
        document.getElementById('edit_menu_image').value = '';

        var imageUrl = (menu.image_url || '').trim();
        document.getElementById('edit_image_url').value = imageUrl;
        var preview = document.getElementById('edit_image_preview');
        if (imageUrl) {
            var src = imageUrl.indexOf('http') === 0 ? imageUrl : '<?php echo rtrim(BASE_URL, '/'); ?>/' + imageUrl.replace(/^\/+/, '');
            preview.innerHTML = '<img src="' + src + '" alt="Menu image" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--border-color);">';
        } else {
            preview.textContent = 'Belum ada gambar';
        }

        const modal = document.getElementById('editBreakfastModal');
        modal.style.display = 'flex';
    }

    function closeEditBreakfastModal() {
        document.getElementById('editBreakfastModal').style.display = 'none';
    }

    // Close modal when clicking outside
    document.getElementById('editRoomTypeModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditModal();
        }
    });

    document.getElementById('editRoomModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditRoomModal();
        }
    });

    document.getElementById('editBreakfastModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditBreakfastModal();
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>