<?php

/**
 * Breakfast Guest Self-Pick Portal API
 * - create_link: authenticated frontdesk action
 * - get_link: public read by token
 * - submit_link: public submit by token with quota enforcement
 */
define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$pdo = $db->getConnection();

function hotel_date()
{
    return (int)date('H') < 10 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
}

function ensure_breakfast_orders_table($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
        id INT PRIMARY KEY AUTO_INCREMENT,
        booking_id INT NULL,
        guest_name VARCHAR(500) NOT NULL,
        room_number TEXT,
        total_pax INT DEFAULT 1,
        breakfast_time TIME,
        breakfast_date DATE,
        location VARCHAR(20) DEFAULT 'restaurant',
        breakfast_location VARCHAR(120) NULL,
        on_the_spot TINYINT(1) NOT NULL DEFAULT 0,
        menu_items TEXT,
        special_requests TEXT,
        total_price DECIMAL(10,2) DEFAULT 0.00,
        order_status VARCHAR(20) DEFAULT 'pending',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_booking_date (booking_id, breakfast_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_portal_links_table($pdo, $runAlter = true)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_guest_links (
        id INT PRIMARY KEY AUTO_INCREMENT,
        token VARCHAR(80) NOT NULL,
        short_code VARCHAR(16) NULL,
        booking_id INT NULL,
        guest_id INT NULL,
        guest_name VARCHAR(500) NOT NULL,
        guest_phone VARCHAR(60) NULL,
        room_number TEXT,
        breakfast_date DATE NOT NULL,
        max_main INT NOT NULL DEFAULT 2,
        max_drink INT NOT NULL DEFAULT 2,
        max_child INT NOT NULL DEFAULT 2,
        child_menu_ids TEXT,
        link_status VARCHAR(20) NOT NULL DEFAULT 'open',
        selected_menu_ids TEXT,
        selected_menu_notes TEXT,
        selected_menu_qty TEXT,
        selected_drink_ids TEXT,
        selected_drink_notes TEXT,
        selected_drink_qty TEXT,
        selected_child_ids TEXT,
        selected_child_notes TEXT,
        selected_child_qty TEXT,
        breakfast_time TIME NULL,
        breakfast_service VARCHAR(20) NULL,
        breakfast_location VARCHAR(120) NULL,
        on_the_spot TINYINT(1) NOT NULL DEFAULT 0,
        special_requests TEXT,
        expires_at DATETIME NULL,
        submitted_at DATETIME NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_token (token),
        UNIQUE KEY uk_short_code (short_code),
        INDEX idx_guest_date (guest_name(191), breakfast_date),
        INDEX idx_status (link_status),
        INDEX idx_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$runAlter) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN short_code VARCHAR(16) NULL AFTER token");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD UNIQUE INDEX uk_short_code (short_code)");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN max_drink INT NOT NULL DEFAULT 2 AFTER max_main");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_drink_ids TEXT AFTER selected_menu_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_menu_notes TEXT AFTER selected_menu_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_drink_notes TEXT AFTER selected_drink_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_menu_qty TEXT AFTER selected_menu_notes");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_drink_qty TEXT AFTER selected_drink_notes");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_child_notes TEXT AFTER selected_child_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_child_qty TEXT AFTER selected_child_notes");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN guest_composition TEXT AFTER child_menu_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN breakfast_time TIME NULL AFTER selected_child_notes");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN breakfast_service VARCHAR(20) NULL AFTER breakfast_time");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN breakfast_location VARCHAR(120) NULL AFTER breakfast_service");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN on_the_spot TINYINT(1) NOT NULL DEFAULT 0 AFTER breakfast_location");
    } catch (Exception $e) {
    }
}

function ensure_breakfast_quota_table($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_guest_quota (
        id INT PRIMARY KEY AUTO_INCREMENT,
        booking_id INT NOT NULL,
        guest_id INT NULL,
        guest_name VARCHAR(255) NULL,
        breakfast_date DATE NULL,
        adult_count INT NOT NULL DEFAULT 1,
        child_young_count INT NOT NULL DEFAULT 0,
        child_old_count INT NOT NULL DEFAULT 0,
        total_pax INT NOT NULL DEFAULT 1,
        max_main INT NOT NULL DEFAULT 2,
        max_drink INT NOT NULL DEFAULT 2,
        max_child INT NOT NULL DEFAULT 2,
        child_menu_ids TEXT,
        extra_main_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        extra_drink_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        extra_child_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_booking_id (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_booking_extras_table($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_extras (
        id INT PRIMARY KEY AUTO_INCREMENT,
        booking_id INT NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        notes TEXT,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function parse_json_body()
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function get_setting($db, $key)
{
    $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $row['setting_value'] ?? '';
}

function default_portal_info_text()
{
    return implode("\n\n", [
        'Hello, here is your breakfast menu selection portal.',
        'Each room has a fixed breakfast allowance.',
        'Please confirm your selection through this link only. If you need to make changes, please contact Front Office.'
    ]);
}

function looks_like_old_indonesian_portal_text($text)
{
    $text = strtolower(trim((string)$text));
    if ($text === '') return false;
    return strpos($text, 'hai kak') !== false
        || strpos($text, 'pilihan menu breakfast') !== false
        || strpos($text, 'mohon konfirmasi menu') !== false
        || strpos($text, 'front office') !== false;
}

function to_float($value, $default = 0)
{
    if ($value === null || $value === '') return (float)$default;
    return (float)$value;
}

function create_short_code()
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len = 8;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function auto_submit_on_the_spot_after_midnight($db, $pdo, $link)
{
    $linkStatus = (string)($link['link_status'] ?? 'open');
    if (!in_array($linkStatus, ['open', 'expired'], true) || !empty($link['submitted_at'])) {
        return false;
    }

    $breakfastDate = (string)($link['breakfast_date'] ?? '');
    if ($breakfastDate === '') {
        return false;
    }

    $tz = new DateTimeZone('Asia/Jakarta');
    $now = new DateTime('now', $tz);
    $breakfastDateTime = DateTime::createFromFormat('Y-m-d', $breakfastDate, $tz);
    $today = new DateTime('today', $tz);

    // Hanya auto-ON-THE-SPOT kalau breakfast_date adalah tanggal Kemarin atau lebih lama
    // (berarti sudah melewati tengah malam dari hari sebelumnya)
    // Jika breakfast_date adalah hari ini atau masa depan, jangan auto-ON-THE-SPOT
    if ($breakfastDateTime >= $today) {
        return false;
    }

    $guestName = trim((string)($link['guest_name'] ?? ''));
    if ($guestName === '') {
        return false;
    }

    $bookingId = !empty($link['booking_id']) ? (int)$link['booking_id'] : null;
    $roomJson = $link['room_number'] ?: json_encode([]);
    $guestComposition = json_decode($link['guest_composition'] ?? '{}', true);
    if (!is_array($guestComposition)) $guestComposition = [];
    $totalPax = max(1, (int)($guestComposition['total_pax'] ?? (($guestComposition['adults'] ?? 1) + ($guestComposition['children_young'] ?? 0) + ($guestComposition['children_old'] ?? 0))));
    $createdBy = isset($link['created_by']) ? (int)$link['created_by'] : 0;

    $breakfastTime = '07:00:00';
    $serviceType = 'restaurant';
    $breakfastLocation = 'Main Restaurant';
    $specialReason = '[AUTO ON THE SPOT MIDNIGHT] Guest did not submit menu before 00:00';

    $menuItems = [[
        'menu_id' => 0,
        'menu_name' => 'ON THE SPOT (Guest will choose at restaurant)',
        'quantity' => 1,
        'price' => 0,
        'is_free' => 1,
        'group' => 'on_the_spot',
        'is_on_the_spot' => 1,
        'auto_set' => 1
    ]];
    $menuJson = json_encode($menuItems);

    $existing = $db->fetchOne(
        "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND FIND_IN_SET(?, REPLACE(guest_name, ', ', ',')) > 0 LIMIT 1",
        [$breakfastDate, $guestName]
    );

    if ($existing) {
        $pdo->prepare("UPDATE breakfast_orders SET
            booking_id = ?, guest_name = ?, room_number = ?, total_pax = ?, breakfast_time = ?,
            breakfast_date = ?, location = ?, breakfast_location = ?, menu_items = ?, special_requests = ?, total_price = ?,
            on_the_spot = 1, order_status = 'submitted', updated_at = NOW()
            WHERE id = ?")
            ->execute([
                $bookingId,
                $guestName,
                $roomJson,
                $totalPax,
                $breakfastTime,
                $breakfastDate,
                $serviceType,
                $breakfastLocation,
                $menuJson,
                $specialReason,
                0,
                (int)$existing['id']
            ]);
    } else {
        $pdo->prepare("INSERT INTO breakfast_orders
            (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date,
             location, breakfast_location, on_the_spot, menu_items, special_requests, total_price, order_status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 0, 'submitted', ?)")
            ->execute([
                $bookingId,
                $guestName,
                $roomJson,
                $totalPax,
                $breakfastTime,
                $breakfastDate,
                $serviceType,
                $breakfastLocation,
                $menuJson,
                $specialReason,
                $createdBy
            ]);
    }

    $pdo->prepare("UPDATE breakfast_guest_links
        SET link_status = 'submitted', selected_menu_ids = '[]', selected_menu_notes = '{}', selected_menu_qty = '{}',
            selected_drink_ids = '[]', selected_drink_notes = '{}', selected_drink_qty = '{}',
            selected_child_ids = '[]', selected_child_notes = '{}', selected_child_qty = '{}',
            breakfast_time = ?, breakfast_service = ?, breakfast_location = ?, on_the_spot = 1,
            special_requests = ?, submitted_at = NOW(), updated_at = NOW()
        WHERE id = ?")
        ->execute([
            $breakfastTime,
            $serviceType,
            $breakfastLocation,
            $specialReason,
            (int)$link['id']
        ]);

    return true;
}

function detect_guest_preferred_language($db, $link)
{
    $nationality = '';

    try {
        if (!empty($link['booking_id'])) {
            $row = $db->fetchOne(
                "SELECT g.nationality
                 FROM bookings b
                 LEFT JOIN guests g ON b.guest_id = g.id
                 WHERE b.id = ?
                 LIMIT 1",
                [(int)$link['booking_id']]
            );
            $nationality = trim((string)($row['nationality'] ?? ''));
        }

        if ($nationality === '' && !empty($link['guest_name'])) {
            $rowByName = $db->fetchOne(
                "SELECT nationality
                 FROM guests
                 WHERE LOWER(TRIM(guest_name)) = LOWER(TRIM(?))
                 ORDER BY id DESC
                 LIMIT 1",
                [$link['guest_name']]
            );
            $nationality = trim((string)($rowByName['nationality'] ?? ''));
        }
    } catch (Exception $e) {
        $nationality = '';
    }

    $n = strtolower($nationality);
    $isLocal = false;
    foreach (['indonesia', 'indonesian', 'wni', 'id'] as $kw) {
        if ($n !== '' && strpos($n, $kw) !== false) {
            $isLocal = true;
            break;
        }
    }

    return [
        'preferred_lang' => $isLocal ? 'id' : 'en',
        'nationality' => $nationality
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = parse_json_body();
if (!$action && !empty($body['action'])) {
    $action = $body['action'];
}

try {
    if ($action === 'create_link') {
        ensure_breakfast_orders_table($pdo);
        ensure_portal_links_table($pdo, true);
        ensure_breakfast_quota_table($pdo);
        ensure_booking_extras_table($pdo);

        // Ensure unique key on breakfast_orders
        try {
            $pdo->exec("ALTER TABLE breakfast_orders ADD UNIQUE KEY uk_booking_date (booking_id, breakfast_date)");
        } catch (Exception $e) {
        }

        // Add new columns if not exist
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN adult_count INT NOT NULL DEFAULT 1 AFTER breakfast_date");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN child_young_count INT NOT NULL DEFAULT 0 AFTER adult_count");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN child_old_count INT NOT NULL DEFAULT 0 AFTER child_young_count");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN total_pax INT NOT NULL DEFAULT 1 AFTER child_old_count");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN max_drink INT NOT NULL DEFAULT 2 AFTER max_main");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN extra_drink_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER extra_main_price");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("UPDATE breakfast_guest_quota SET extra_drink_price = 20000 WHERE extra_drink_price = 75000 OR extra_drink_price = 0");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_orders ADD COLUMN breakfast_location VARCHAR(120) NULL AFTER location");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_orders ADD COLUMN on_the_spot TINYINT(1) NOT NULL DEFAULT 0 AFTER breakfast_location");
        } catch (Exception $e) {
        }
    } elseif ($action === 'submit_link') {
        // Submit may write new portal columns (notes/qty), ensure they exist.
        ensure_portal_links_table($pdo, true);
        ensure_breakfast_orders_table($pdo);
        ensure_booking_extras_table($pdo);
    } elseif ($action === 'get_link') {
        // Public portal read endpoint should stay lightweight.
        ensure_portal_links_table($pdo, false);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal inisialisasi tabel: ' . $e->getMessage()]);
    exit;
}

if ($action === 'create_link') {
    require_once '../includes/auth.php';
    $auth = new Auth();
    $auth->requireLogin();
    if (!$auth->hasPermission('frontdesk')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    $guestName = trim((string)($body['guest_name'] ?? ''));
    $guestPhone = trim((string)($body['guest_phone'] ?? ''));
    $guestId = !empty($body['guest_id']) ? (int)$body['guest_id'] : null;
    $bookingId = !empty($body['booking_id']) ? (int)$body['booking_id'] : null;
    $rooms = $body['room_number'] ?? [];
    if (!is_array($rooms)) $rooms = [$rooms];
    $rooms = array_values(array_unique(array_filter(array_map('trim', $rooms))));

    $breakfastDate = !empty($body['breakfast_date']) ? $body['breakfast_date'] : hotel_date();

    // New quota structure based on guest composition
    $adultCount = max(0, (int)($body['adult_count'] ?? 1));
    $childYoung = max(0, (int)($body['child_young_count'] ?? 0)); // < 7 years old
    $childOld = 0; // kids >=7 are not configured separately in this flow

    $maxMain = max(0, (int)($body['max_main'] ?? 2));
    $maxDrink = max(0, (int)($body['max_drink'] ?? 2));
    $maxChild = max(0, (int)($body['max_child'] ?? 2)); // for young children
    $expireHours = max(1, min(72, (int)($body['expire_hours'] ?? 24)));

    // Apply exact setup quotas per guest (do not multiply by pax)
    $totalPax = max(1, (int)($body['total_pax'] ?? ($adultCount + $childYoung + $childOld)));
    $totalMainQuota = $maxMain;
    $totalDrinkQuota = $maxDrink;
    $totalChildQuota = $maxChild;

    $extraMainPrice = max(0, to_float($body['extra_main_price'] ?? 75000, 75000));
    $extraDrinkPrice = max(0, to_float($body['extra_drink_price'] ?? 20000, 20000));
    if ((int)round($extraDrinkPrice) === 75000) $extraDrinkPrice = 20000.0;
    $extraChildPrice = max(0, to_float($body['extra_child_price'] ?? 75000, 75000));

    $childMenuIds = $body['child_menu_ids'] ?? [];
    if (!is_array($childMenuIds)) $childMenuIds = [];
    $childMenuIds = array_values(array_unique(array_map('intval', $childMenuIds)));
    $childMenuIds = array_values(array_filter($childMenuIds, function ($v) {
        return $v > 0;
    }));

    if ($maxChild > 0 && count($childMenuIds) === 0) {
        $fallbackKids = $db->fetchAll("SELECT id FROM breakfast_menus WHERE is_available = 1 AND LOWER(TRIM(menu_name)) IN ('pancake','waffle') ORDER BY menu_name") ?: [];
        foreach ($fallbackKids as $fk) {
            $id = (int)($fk['id'] ?? 0);
            if ($id > 0) $childMenuIds[] = $id;
        }
        $childMenuIds = array_values(array_unique($childMenuIds));
    }

    if ($guestName === '') {
        echo json_encode(['success' => false, 'message' => 'Nama tamu wajib diisi']);
        exit;
    }
    if (($maxMain + $maxDrink + $maxChild) <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jatah menu minimal 1']);
        exit;
    }

    $token = bin2hex(random_bytes(24));
    $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $roomJson = json_encode($rooms);
    $childJson = json_encode($childMenuIds);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $expireHours . ' hours'));

    // Store guest composition for quota calculation
    $totalPax = $adultCount + $childYoung + $childOld;
    $guestCompositionJson = json_encode([
        'adults' => $adultCount,
        'children_young' => $childYoung,  // < 7 years
        'children_old' => $childOld,      // >= 7 years
        'total_pax' => $totalPax
    ]);

    try {
        // Expire previous open links scoped by booking (room) when available,
        // so group bookings with identical guest names don't cancel each other's link.
        if ($bookingId) {
            $pdo->prepare("UPDATE breakfast_guest_links
                SET link_status = 'expired'
                WHERE breakfast_date = ? AND booking_id = ? AND link_status = 'open'")
                ->execute([$breakfastDate, $bookingId]);
        } else {
            $pdo->prepare("UPDATE breakfast_guest_links
                SET link_status = 'expired'
                WHERE breakfast_date = ? AND LOWER(TRIM(guest_name)) = LOWER(TRIM(?)) AND link_status = 'open'")
                ->execute([$breakfastDate, $guestName]);
        }

        if ($bookingId) {
            $pdo->prepare("INSERT INTO breakfast_guest_quota
                (booking_id, guest_id, guest_name, breakfast_date, adult_count, child_young_count, child_old_count, total_pax, max_main, max_drink, max_child, child_menu_ids, extra_main_price, extra_drink_price, extra_child_price, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    guest_id = VALUES(guest_id),
                    guest_name = VALUES(guest_name),
                    breakfast_date = VALUES(breakfast_date),
                    adult_count = VALUES(adult_count),
                    child_young_count = VALUES(child_young_count),
                    child_old_count = VALUES(child_old_count),
                    total_pax = VALUES(total_pax),
                    max_main = VALUES(max_main),
                    max_drink = VALUES(max_drink),
                    max_child = VALUES(max_child),
                    child_menu_ids = VALUES(child_menu_ids),
                    extra_main_price = VALUES(extra_main_price),
                    extra_drink_price = VALUES(extra_drink_price),
                    extra_child_price = VALUES(extra_child_price),
                    updated_at = NOW()")
                ->execute([
                    $bookingId,
                    $guestId,
                    $guestName,
                    $breakfastDate,
                    $adultCount,
                    $childYoung,
                    $childOld,
                    $totalPax,
                    $maxMain,
                    $maxDrink,
                    $maxChild,
                    $childJson,
                    $extraMainPrice,
                    $extraDrinkPrice,
                    $extraChildPrice,
                    $userId
                ]);
        }

        $shortCode = null;
        $inserted = false;
        for ($i = 0; $i < 5; $i++) {
            $shortCode = create_short_code();
            try {
                $pdo->prepare("INSERT INTO breakfast_guest_links
                    (token, short_code, booking_id, guest_id, guest_name, guest_phone, room_number, breakfast_date,
                     max_main, max_drink, max_child, child_menu_ids, guest_composition, expires_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $token,
                        $shortCode,
                        $bookingId,
                        $guestId,
                        $guestName,
                        $guestPhone,
                        $roomJson,
                        $breakfastDate,
                        $maxMain,
                        $maxDrink,
                        $maxChild,
                        $childJson,
                        $guestCompositionJson,
                        $expiresAt,
                        $userId
                    ]);
                $inserted = true;
                break;
            } catch (Exception $innerEx) {
                if ($i === 4) {
                    throw $innerEx;
                }
            }
        }
        if (!$inserted) {
            throw new Exception('Tidak dapat membuat short link');
        }

        $linkUrl = rtrim(BASE_URL, '/') . '/modules/frontdesk/breakfast-guest.php?t=' . urlencode($token);
        $shortLink = rtrim(BASE_URL, '/') . '/go-breakfast.php?k=' . urlencode($shortCode);
        echo json_encode([
            'success' => true,
            'message' => 'Link berhasil dibuat',
            'data' => [
                'token' => $token,
                'short_code' => $shortCode,
                'link_url' => $linkUrl,
                'short_link' => $shortLink,
                'expires_at' => $expiresAt
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal membuat link: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_link') {
    $token = trim((string)($_GET['token'] ?? $body['token'] ?? ''));
    $shortCode = trim((string)($_GET['k'] ?? $body['k'] ?? ''));
    if ($token === '' && $shortCode === '') {
        echo json_encode(['success' => false, 'message' => 'Token wajib']);
        exit;
    }

    if ($token !== '') {
        $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE token = ? LIMIT 1", [$token]);
    } else {
        $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE short_code = ? LIMIT 1", [$shortCode]);
    }
    if (!$link) {
        echo json_encode(['success' => false, 'message' => 'Link tidak ditemukan']);
        exit;
    }

    try {
        $autoOnSpot = auto_submit_on_the_spot_after_midnight($db, $pdo, $link);
        if ($autoOnSpot) {
            $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE id = ? LIMIT 1", [(int)$link['id']]);
        }
    } catch (Exception $e) {
        // keep portal accessible even if auto-update fails
    }

    $linkStatus = (string)($link['link_status'] ?? 'open');
    $isLocked = in_array($linkStatus, ['submitted', 'closed', 'locked'], true) || !empty($link['submitted_at']);

    if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
        $pdo->prepare("UPDATE breakfast_guest_links SET link_status = 'expired' WHERE id = ?")->execute([(int)$link['id']]);
        echo json_encode(['success' => false, 'message' => 'Link sudah kedaluwarsa']);
        exit;
    }

    $menus = $db->fetchAll("SELECT id, menu_name, category, is_free, price, image_url, description FROM breakfast_menus WHERE is_available = 1 ORDER BY category, menu_name") ?: [];
    $menuMap = [];
    foreach ($menus as $m) {
        $menuMap[(int)$m['id']] = $m;
    }

    $childIds = json_decode($link['child_menu_ids'] ?? '[]', true);
    if (!is_array($childIds)) $childIds = [];
    $childIds = array_values(array_unique(array_map('intval', $childIds)));

    if (count($childIds) === 0 && (int)($link['max_child'] ?? 0) > 0) {
        $fallbackKids = $db->fetchAll("SELECT id FROM breakfast_menus WHERE is_available = 1 AND LOWER(TRIM(menu_name)) IN ('pancake','waffle') ORDER BY menu_name") ?: [];
        foreach ($fallbackKids as $fk) {
            $id = (int)($fk['id'] ?? 0);
            if ($id > 0) $childIds[] = $id;
        }
        $childIds = array_values(array_unique($childIds));
    }

    $childMenus = [];
    foreach ($childIds as $id) {
        if (isset($menuMap[$id])) $childMenus[] = $menuMap[$id];
    }

    $selectedMainIds = json_decode($link['selected_menu_ids'] ?? '[]', true);
    $selectedDrinkIds = json_decode($link['selected_drink_ids'] ?? '[]', true);
    $selectedChildIds = json_decode($link['selected_child_ids'] ?? '[]', true);
    $selectedMainNotes = json_decode($link['selected_menu_notes'] ?? '{}', true);
    $selectedDrinkNotes = json_decode($link['selected_drink_notes'] ?? '{}', true);
    $selectedChildNotes = json_decode($link['selected_child_notes'] ?? '{}', true);
    $selectedMainQty = json_decode($link['selected_menu_qty'] ?? '{}', true);
    $selectedDrinkQty = json_decode($link['selected_drink_qty'] ?? '{}', true);
    $selectedChildQty = json_decode($link['selected_child_qty'] ?? '{}', true);
    if (!is_array($selectedMainIds)) $selectedMainIds = [];
    if (!is_array($selectedDrinkIds)) $selectedDrinkIds = [];
    if (!is_array($selectedChildIds)) $selectedChildIds = [];
    if (!is_array($selectedMainNotes)) $selectedMainNotes = [];
    if (!is_array($selectedDrinkNotes)) $selectedDrinkNotes = [];
    if (!is_array($selectedChildNotes)) $selectedChildNotes = [];
    if (!is_array($selectedMainQty)) $selectedMainQty = [];
    if (!is_array($selectedDrinkQty)) $selectedDrinkQty = [];
    if (!is_array($selectedChildQty)) $selectedChildQty = [];
    $selectedMainIds = array_values(array_unique(array_map('intval', $selectedMainIds)));
    $selectedDrinkIds = array_values(array_unique(array_map('intval', $selectedDrinkIds)));
    $selectedChildIds = array_values(array_unique(array_map('intval', $selectedChildIds)));
    foreach ($selectedMainIds as $id) {
        $v = (int)($selectedMainQty[(string)$id] ?? 1);
        $selectedMainQty[(string)$id] = max(1, $v);
    }
    foreach ($selectedDrinkIds as $id) {
        $v = (int)($selectedDrinkQty[(string)$id] ?? 1);
        $selectedDrinkQty[(string)$id] = max(1, $v);
    }
    foreach ($selectedChildIds as $id) {
        $v = (int)($selectedChildQty[(string)$id] ?? 1);
        $selectedChildQty[(string)$id] = max(1, $v);
    }

    // Separate drinks from main courses
    $drinkCategories = ['drinks', 'beverages'];
    $alwaysMainNames = ['pancake', 'waffle'];
    $drinkMenus = [];
    $mainMenus = [];
    foreach ($menus as $m) {
        $menuId = (int)$m['id'];
        $menuNameLower = strtolower(trim((string)($m['menu_name'] ?? '')));
        $m['pre_selected'] = false;
        if ($isLocked) {
            $m['pre_selected'] = in_array($menuId, $selectedMainIds, true) || in_array($menuId, $selectedDrinkIds, true) || in_array($menuId, $selectedChildIds, true);
        }
        if (in_array($menuId, $childIds, true) && !in_array($menuNameLower, $alwaysMainNames, true)) continue;
        if (in_array(strtolower($m['category'] ?? ''), $drinkCategories, true)) {
            $drinkMenus[] = $m;
        } else {
            $mainMenus[] = $m;
        }
    }

    $rooms = json_decode($link['room_number'] ?? '[]', true);
    if (!is_array($rooms)) {
        $rooms = !empty($link['room_number']) ? [$link['room_number']] : [];
    }

    $guestComposition = json_decode($link['guest_composition'] ?? '{}', true);
    if (!is_array($guestComposition)) $guestComposition = [];
    $totalPax = max(1, (int)($guestComposition['total_pax'] ?? (($guestComposition['adults'] ?? 1) + ($guestComposition['children_young'] ?? 0) + ($guestComposition['children_old'] ?? 0))));

    $waInfo = get_setting($db, 'breakfast_wa_info_text');
    if ($waInfo === '' || looks_like_old_indonesian_portal_text($waInfo)) {
        $waInfo = default_portal_info_text();
    }
    $waMediaPath = get_setting($db, 'breakfast_wa_media_path');
    $portalLogoPath = get_setting($db, 'breakfast_portal_logo_path');
    $extraMainPrice = 75000.0;
    $extraDrinkPrice = 20000.0;
    $extraChildPrice = 75000.0;

    $quotaRow = null;
    if (!empty($link['booking_id'])) {
        $quotaRow = $db->fetchOne("SELECT extra_main_price, extra_drink_price, extra_child_price FROM breakfast_guest_quota WHERE booking_id = ? LIMIT 1", [(int)$link['booking_id']]);
    }
    if (!$quotaRow) {
        $quotaRow = $db->fetchOne(
            "SELECT extra_main_price, extra_drink_price, extra_child_price
             FROM breakfast_guest_quota
             WHERE breakfast_date = ? AND LOWER(TRIM(guest_name)) = LOWER(TRIM(?))
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            [$link['breakfast_date'], $link['guest_name']]
        );
    }
    if ($quotaRow) {
        $extraMainPrice = max(0, to_float($quotaRow['extra_main_price'] ?? 75000, 75000));
        $extraDrinkPrice = max(0, to_float($quotaRow['extra_drink_price'] ?? 20000, 20000));
        if ((int)round($extraDrinkPrice) === 75000) $extraDrinkPrice = 20000.0;
        $extraChildPrice = max(0, to_float($quotaRow['extra_child_price'] ?? 75000, 75000));
    }
    // Kids / child portion (fruit) is ALWAYS free - never charged.
    $extraChildPrice = 0.0;
    $waMediaUrl = '';
    if ($waMediaPath) {
        $waMediaUrl = (strpos($waMediaPath, 'http') === 0)
            ? $waMediaPath
            : rtrim(BASE_URL, '/') . '/' . ltrim($waMediaPath, '/');
    }

    $portalLogoUrl = '';
    if ($portalLogoPath) {
        $portalLogoUrl = (strpos($portalLogoPath, 'http') === 0)
            ? $portalLogoPath
            : rtrim(BASE_URL, '/') . '/' . ltrim($portalLogoPath, '/');
    }

    $specialRequestsLink = (string)($link['special_requests'] ?? '');
    $isAutoOnSpotMidnight = strpos($specialRequestsLink, '[AUTO ON THE SPOT MIDNIGHT]') !== false;
    $autoOnSpotMessageEn = "We are sorry, because you did not select your breakfast menu before midnight, tomorrow you can order directly at the restaurant. Please be patient. If not, you can contact Front Desk to order manually. Thank you.";
    $autoOnSpotMessageId = "Mohon maaf, karena Anda belum memilih menu sarapan sebelum tengah malam, besok Anda bisa langsung memesan di restoran. Mohon bersabar ya. Jika tidak, Anda bisa menghubungi Front Desk untuk memesan secara manual. Terima kasih.";
    $langInfo = detect_guest_preferred_language($db, $link);

    echo json_encode([
        'success' => true,
        'data' => [
            'token' => $token,
            'short_code' => $link['short_code'] ?? null,
            'guest_name' => $link['guest_name'],
            'room_number' => $rooms,
            'breakfast_date' => $link['breakfast_date'],
            'total_pax' => $totalPax,
            'max_main' => (int)$link['max_main'],
            'max_drink' => (int)($link['max_drink'] ?? 2),
            'max_child' => (int)$link['max_child'],
            'extra_main_price' => (float)$extraMainPrice,
            'extra_drink_price' => (float)$extraDrinkPrice,
            'extra_child_price' => (float)$extraChildPrice,
            'main_menus' => $mainMenus,
            'drink_menus' => $drinkMenus,
            'child_menus' => $childMenus,
            'wa_info_text' => $waInfo,
            'wa_media_url' => $waMediaUrl,
            'portal_logo_url' => $portalLogoUrl,
            'expires_at' => $link['expires_at'],
            'submitted_at' => $link['submitted_at'] ?? null,
            'is_locked' => $isLocked,
            'breakfast_time' => $link['breakfast_time'] ?? null,
            'breakfast_service' => $link['breakfast_service'] ?? null,
            'breakfast_location' => $link['breakfast_location'] ?? null,
            'on_the_spot' => (int)($link['on_the_spot'] ?? 0),
            'auto_on_the_spot_midnight' => $isAutoOnSpotMidnight,
            'auto_on_the_spot_message' => $isAutoOnSpotMidnight ? $autoOnSpotMessageEn : '',
            'auto_on_the_spot_message_en' => $isAutoOnSpotMidnight ? $autoOnSpotMessageEn : '',
            'auto_on_the_spot_message_id' => $isAutoOnSpotMidnight ? $autoOnSpotMessageId : '',
            'preferred_lang' => $langInfo['preferred_lang'],
            'guest_nationality' => $langInfo['nationality'],
            'selected_main_ids' => $selectedMainIds,
            'selected_drink_ids' => $selectedDrinkIds,
            'selected_child_ids' => $selectedChildIds,
            'selected_main_notes' => $selectedMainNotes,
            'selected_drink_notes' => $selectedDrinkNotes,
            'selected_child_notes' => $selectedChildNotes,
            'selected_main_qty' => $selectedMainQty,
            'selected_drink_qty' => $selectedDrinkQty,
            'selected_child_qty' => $selectedChildQty
        ]
    ]);
    exit;
}

if ($action === 'submit_link') {
    $token = trim((string)($body['token'] ?? ''));
    $reqLang = strtolower(trim((string)($body['lang'] ?? 'en')));
    if (!in_array($reqLang, ['en', 'id'], true)) $reqLang = 'en';
    $msg = function ($idText, $enText) use ($reqLang) {
        return $reqLang === 'id' ? $idText : $enText;
    };
    if ($token === '') {
        echo json_encode(['success' => false, 'message' => $msg('Token wajib', 'Token is required')]);
        exit;
    }

    $selectedMain = $body['selected_main'] ?? [];
    $selectedDrink = $body['selected_drink'] ?? [];
    $selectedChild = $body['selected_child'] ?? [];
    $selectedMainQtyRaw = $body['selected_main_qty'] ?? [];
    $selectedDrinkQtyRaw = $body['selected_drink_qty'] ?? [];
    $selectedChildQtyRaw = $body['selected_child_qty'] ?? [];
    if (!is_array($selectedMain)) $selectedMain = [];
    if (!is_array($selectedDrink)) $selectedDrink = [];
    if (!is_array($selectedChild)) $selectedChild = [];
    $selectedMain = array_values(array_unique(array_map('intval', $selectedMain)));
    $selectedDrink = array_values(array_unique(array_map('intval', $selectedDrink)));
    $selectedChild = array_values(array_unique(array_map('intval', $selectedChild)));
    $selectedMain = array_values(array_filter($selectedMain, function ($v) {
        return $v > 0;
    }));
    $selectedDrink = array_values(array_filter($selectedDrink, function ($v) {
        return $v > 0;
    }));
    $selectedChild = array_values(array_filter($selectedChild, function ($v) {
        return $v > 0;
    }));
    $onTheSpot = !empty($body['on_the_spot']) ? 1 : 0;

    $normalizeNotesMap = function ($raw, $allowedIds) {
        if (!is_array($raw)) return [];
        $allowed = [];
        foreach ($allowedIds as $id) {
            $allowed[(int)$id] = true;
        }
        $clean = [];
        foreach ($raw as $k => $v) {
            $id = (int)$k;
            if ($id <= 0 || empty($allowed[$id])) continue;
            $note = trim((string)$v);
            if ($note === '') continue;
            if (mb_strlen($note) > 160) {
                $note = mb_substr($note, 0, 160);
            }
            $clean[(string)$id] = $note;
        }
        return $clean;
    };

    $normalizeQtyMap = function ($raw, $allowedIds) {
        $out = [];
        if (!is_array($raw)) $raw = [];
        $allowed = [];
        foreach ($allowedIds as $id) {
            $allowed[(int)$id] = true;
        }
        foreach ($allowedIds as $id) {
            $sid = (string)(int)$id;
            $qty = (int)($raw[$sid] ?? $raw[(int)$id] ?? 1);
            if ($qty < 1) $qty = 1;
            if ($qty > 50) $qty = 50;
            if (!empty($allowed[(int)$id])) {
                $out[$sid] = $qty;
            }
        }
        return $out;
    };

    $selectedMainNotes = $normalizeNotesMap($body['selected_main_notes'] ?? [], $selectedMain);
    $selectedDrinkNotes = $normalizeNotesMap($body['selected_drink_notes'] ?? [], $selectedDrink);
    $selectedChildNotes = $normalizeNotesMap($body['selected_child_notes'] ?? [], $selectedChild);
    $selectedMainQty = $normalizeQtyMap($selectedMainQtyRaw, $selectedMain);
    $selectedDrinkQty = $normalizeQtyMap($selectedDrinkQtyRaw, $selectedDrink);
    $selectedChildQty = $normalizeQtyMap($selectedChildQtyRaw, $selectedChild);

    $specialRequests = trim((string)($body['special_requests'] ?? ''));
    $serviceType = trim((string)($body['service_type'] ?? $body['location'] ?? ''));
    if (!in_array($serviceType, ['restaurant', 'room_service', 'take_away'], true)) {
        echo json_encode(['success' => false, 'message' => $msg('Pilih layanan breakfast: Restaurant / Room Service / Take Away', 'Please choose a breakfast service: Restaurant / Room Service / Take Away')]);
        exit;
    }

    $breakfastLocation = trim((string)($body['breakfast_location'] ?? ''));
    if ($breakfastLocation === '') {
        echo json_encode(['success' => false, 'message' => $msg('Lokasi breakfast wajib diisi', 'Breakfast location is required')]);
        exit;
    }
    if (mb_strlen($breakfastLocation) > 120) {
        $breakfastLocation = mb_substr($breakfastLocation, 0, 120);
    }

    $breakfastTimeRaw = trim((string)($body['breakfast_time'] ?? ''));
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $breakfastTimeRaw, $mt)) {
        echo json_encode(['success' => false, 'message' => $msg('Waktu breakfast wajib diisi (format HH:MM)', 'Breakfast time is required (format HH:MM)')]);
        exit;
    }
    $breakfastTime = sprintf('%02d:%02d:00', (int)$mt[1], (int)$mt[2]);

    $pdo->beginTransaction();
    try {
        $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE token = ? LIMIT 1", [$token]);
        if (!$link) {
            throw new Exception('Link tidak ditemukan');
        }
        if (!empty($link['submitted_at']) || in_array((string)($link['link_status'] ?? 'open'), ['submitted', 'closed', 'locked'], true)) {
            throw new Exception('Menu sudah dikirim. Untuk perubahan, silakan hubungi Front Office.');
        }
        if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
            $pdo->prepare("UPDATE breakfast_guest_links SET link_status = 'expired' WHERE id = ?")->execute([(int)$link['id']]);
            throw new Exception('Link sudah kedaluwarsa');
        }

        $maxMain = max(0, (int)$link['max_main']);
        $maxDrink = max(0, (int)($link['max_drink'] ?? 2));
        $maxChild = max(0, (int)$link['max_child']);

        $sumMainQty = array_sum(array_map('intval', $selectedMainQty));
        $sumDrinkQty = array_sum(array_map('intval', $selectedDrinkQty));
        $sumChildQty = array_sum(array_map('intval', $selectedChildQty));
        $extraMainCount = max(0, $sumMainQty - $maxMain);
        $extraDrinkCount = max(0, $sumDrinkQty - $maxDrink);
        $extraChildCount = max(0, $sumChildQty - $maxChild);

        $allowedChild = json_decode($link['child_menu_ids'] ?? '[]', true);
        if (!is_array($allowedChild)) $allowedChild = [];
        $allowedChild = array_values(array_unique(array_map('intval', $allowedChild)));

        foreach ($selectedChild as $id) {
            if (!in_array($id, $allowedChild, true)) {
                throw new Exception($msg('Ada menu anak yang tidak diizinkan', 'Some kids menu items are not allowed'));
            }
        }

        $alwaysMainNames = ['pancake', 'waffle'];
        $allowedChildAlsoMain = [];
        if (count($allowedChild) > 0) {
            $childPlaceholders = implode(',', array_fill(0, count($allowedChild), '?'));
            $childMenus = $db->fetchAll("SELECT id, menu_name FROM breakfast_menus WHERE id IN ($childPlaceholders)", $allowedChild) ?: [];
            foreach ($childMenus as $cm) {
                $nm = strtolower(trim((string)($cm['menu_name'] ?? '')));
                if (in_array($nm, $alwaysMainNames, true)) {
                    $allowedChildAlsoMain[(int)$cm['id']] = true;
                }
            }
        }

        foreach ($selectedMain as $id) {
            if (in_array($id, $allowedChild, true) && empty($allowedChildAlsoMain[(int)$id])) {
                throw new Exception($msg('Menu anak tidak boleh dipilih di menu utama', 'Kids menu cannot be selected in Main Course'));
            }
        }

        $extraMainPrice = 75000.0;
        $extraDrinkPrice = 20000.0;
        $extraChildPrice = 75000.0;

        $quotaRow = null;
        if (!empty($link['booking_id'])) {
            $quotaRow = $db->fetchOne("SELECT extra_main_price, extra_drink_price, extra_child_price FROM breakfast_guest_quota WHERE booking_id = ? LIMIT 1", [(int)$link['booking_id']]);
        }
        if (!$quotaRow) {
            $quotaRow = $db->fetchOne(
                "SELECT extra_main_price, extra_drink_price, extra_child_price
                 FROM breakfast_guest_quota
                 WHERE breakfast_date = ? AND LOWER(TRIM(guest_name)) = LOWER(TRIM(?))
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                [$link['breakfast_date'], $link['guest_name']]
            );
        }
        if ($quotaRow) {
            $extraMainPrice = max(0, to_float($quotaRow['extra_main_price'] ?? 75000, 75000));
            $extraDrinkPrice = max(0, to_float($quotaRow['extra_drink_price'] ?? 20000, 20000));
            if ((int)round($extraDrinkPrice) === 75000) $extraDrinkPrice = 20000.0;
            $extraChildPrice = max(0, to_float($quotaRow['extra_child_price'] ?? 75000, 75000));
        }
        // Kids / child portion (fruit) is ALWAYS free - never charged.
        $extraChildPrice = 0.0;

        $menuItems = [];
        $totalPrice = 0;
        $extraChargeTotal = 0;
        $allSelected = array_values(array_unique(array_merge($selectedMain, $selectedDrink, $selectedChild)));
        if (!$onTheSpot && count($allSelected) === 0) {
            throw new Exception($msg('Pilih minimal 1 menu', 'Please select at least 1 menu item'));
        }

        if ($onTheSpot) {
            $menuItems[] = [
                'menu_id' => 0,
                'menu_name' => 'ON THE SPOT (Guest will choose at restaurant)',
                'quantity' => 1,
                'price' => 0,
                'is_free' => 1,
                'group' => 'on_the_spot',
                'is_on_the_spot' => 1
            ];
        } else {
            $placeholders = implode(',', array_fill(0, count($allSelected), '?'));
            $menus = $db->fetchAll("SELECT id, menu_name, price, is_free FROM breakfast_menus WHERE is_available = 1 AND id IN ($placeholders)", $allSelected) ?: [];
            $menuMap = [];
            foreach ($menus as $m) {
                $menuMap[(int)$m['id']] = $m;
            }

            $usedMainQty = 0;
            foreach ($selectedMain as $id) {
                if (empty($menuMap[$id])) continue;
                $m = $menuMap[$id];
                $itemNote = trim((string)($selectedMainNotes[(string)$id] ?? ''));
                $qty = max(1, (int)($selectedMainQty[(string)$id] ?? 1));
                $extraBefore = max(0, $usedMainQty - $maxMain);
                $extraAfter = max(0, ($usedMainQty + $qty) - $maxMain);
                $extraQty = max(0, $extraAfter - $extraBefore);
                $item = [
                    'menu_id' => (int)$m['id'],
                    'menu_name' => $m['menu_name'],
                    'quantity' => $qty,
                    'price' => (float)$m['price'],
                    'is_free' => (int)$m['is_free'],
                    'group' => 'main'
                ];
                if ($itemNote !== '') {
                    $item['note'] = $itemNote;
                }
                if ($extraQty > 0) {
                    $item['is_extra'] = 1;
                    $item['extra_base_price'] = $extraMainPrice;
                }
                $menuItems[] = $item;
                if ($extraQty > 0) {
                    $charge = (float)$extraMainPrice * $extraQty;
                    $totalPrice += $charge;
                    $extraChargeTotal += $charge;
                } elseif (!(int)$m['is_free']) {
                    $totalPrice += (float)$m['price'] * $qty;
                }
                $usedMainQty += $qty;
            }

            $usedDrinkQty = 0;
            foreach ($selectedDrink as $id) {
                if (empty($menuMap[$id])) continue;
                $m = $menuMap[$id];
                $itemNote = trim((string)($selectedDrinkNotes[(string)$id] ?? ''));
                $qty = max(1, (int)($selectedDrinkQty[(string)$id] ?? 1));
                $extraBefore = max(0, $usedDrinkQty - $maxDrink);
                $extraAfter = max(0, ($usedDrinkQty + $qty) - $maxDrink);
                $extraQty = max(0, $extraAfter - $extraBefore);
                $item = [
                    'menu_id' => (int)$m['id'],
                    'menu_name' => $m['menu_name'],
                    'quantity' => $qty,
                    'price' => (float)$m['price'],
                    'is_free' => (int)$m['is_free'],
                    'group' => 'drink'
                ];
                if ($itemNote !== '') {
                    $item['note'] = $itemNote;
                }
                if ($extraQty > 0) {
                    $item['is_extra'] = 1;
                    $item['extra_base_price'] = $extraDrinkPrice;
                }
                $menuItems[] = $item;
                if ($extraQty > 0) {
                    $charge = (float)$extraDrinkPrice * $extraQty;
                    $totalPrice += $charge;
                    $extraChargeTotal += $charge;
                } elseif (!(int)$m['is_free']) {
                    $totalPrice += (float)$m['price'] * $qty;
                }
                $usedDrinkQty += $qty;
            }

            $usedChildQty = 0;
            foreach ($selectedChild as $id) {
                if (empty($menuMap[$id])) continue;
                $m = $menuMap[$id];
                $itemNote = trim((string)($selectedChildNotes[(string)$id] ?? ''));
                $qty = max(1, (int)($selectedChildQty[(string)$id] ?? 1));
                $extraBefore = max(0, $usedChildQty - $maxChild);
                $extraAfter = max(0, ($usedChildQty + $qty) - $maxChild);
                $extraQty = max(0, $extraAfter - $extraBefore);
                $item = [
                    'menu_id' => (int)$m['id'],
                    'menu_name' => $m['menu_name'],
                    'quantity' => $qty,
                    'price' => (float)$m['price'],
                    'is_free' => (int)$m['is_free'],
                    'group' => 'child'
                ];
                if ($itemNote !== '') {
                    $item['note'] = $itemNote;
                }
                if ($extraQty > 0) {
                    $item['is_extra'] = 1;
                    $item['extra_base_price'] = $extraChildPrice;
                }
                $menuItems[] = $item;
                if ($extraQty > 0) {
                    $charge = (float)$extraChildPrice * $extraQty;
                    $totalPrice += $charge;
                    $extraChargeTotal += $charge;
                } elseif (!(int)$m['is_free']) {
                    $totalPrice += (float)$m['price'] * $qty;
                }
                $usedChildQty += $qty;
            }

            if (count($menuItems) === 0) {
                throw new Exception('Menu tidak valid');
            }
        }

        $guestName = $link['guest_name'];
        $breakfastDate = $link['breakfast_date'];
        $bookingId = !empty($link['booking_id']) ? (int)$link['booking_id'] : null;
        $roomJson = $link['room_number'] ?: json_encode([]);
        $menuJson = json_encode($menuItems);
        $guestComposition = json_decode($link['guest_composition'] ?? '{}', true);
        if (!is_array($guestComposition)) $guestComposition = [];
        $totalPax = max(1, (int)($guestComposition['total_pax'] ?? (($guestComposition['adults'] ?? 1) + ($guestComposition['children_young'] ?? 0) + ($guestComposition['children_old'] ?? 0))));
        $createdBy = isset($link['created_by']) ? (int)$link['created_by'] : 0;

        $portalNote = '[Guest Portal]';
        if ($onTheSpot) {
            $portalNote .= ' ON THE SPOT';
        }
        if ($extraMainCount > 0 || $extraDrinkCount > 0 || $extraChildCount > 0) {
            $portalNote .= ' Extra: main=' . $extraMainCount . ', drink=' . $extraDrinkCount . ', child=' . $extraChildCount;
        }
        if ($specialRequests !== '') {
            $portalNote .= ' ' . $specialRequests;
        }

        $existing = $db->fetchOne(
            "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND FIND_IN_SET(?, REPLACE(guest_name, ', ', ',')) > 0 LIMIT 1",
            [$breakfastDate, $guestName]
        );

        if ($existing) {
            $pdo->prepare("UPDATE breakfast_orders SET
                booking_id = ?, guest_name = ?, room_number = ?, total_pax = ?, breakfast_time = ?,
                breakfast_date = ?, location = ?, breakfast_location = ?, menu_items = ?, special_requests = ?, total_price = ?,
                on_the_spot = ?, order_status = 'submitted'
                WHERE id = ?")
                ->execute([
                    $bookingId,
                    $guestName,
                    $roomJson,
                    $totalPax,
                    $breakfastTime,
                    $breakfastDate,
                    $serviceType,
                    $breakfastLocation,
                    $menuJson,
                    $portalNote,
                    $totalPrice,
                    (int)$onTheSpot,
                    (int)$existing['id']
                ]);
            $orderId = (int)$existing['id'];
        } else {
            $pdo->prepare("INSERT INTO breakfast_orders
                (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date,
                 location, breakfast_location, on_the_spot, menu_items, special_requests, total_price, order_status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?)")
                ->execute([
                    $bookingId,
                    $guestName,
                    $roomJson,
                    $totalPax,
                    $breakfastTime,
                    $breakfastDate,
                    $serviceType,
                    $breakfastLocation,
                    (int)$onTheSpot,
                    $menuJson,
                    $portalNote,
                    $totalPrice,
                    $createdBy
                ]);
            $orderId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("UPDATE breakfast_guest_links
            SET link_status = 'submitted', selected_menu_ids = ?, selected_menu_notes = ?, selected_menu_qty = ?, selected_drink_ids = ?, selected_drink_notes = ?, selected_drink_qty = ?, selected_child_ids = ?, selected_child_notes = ?, selected_child_qty = ?,
                breakfast_time = ?, breakfast_service = ?, breakfast_location = ?, on_the_spot = ?, special_requests = ?, submitted_at = NOW()
            WHERE id = ?")
            ->execute([
                json_encode($selectedMain),
                json_encode($selectedMainNotes),
                json_encode($selectedMainQty),
                json_encode($selectedDrink),
                json_encode($selectedDrinkNotes),
                json_encode($selectedDrinkQty),
                json_encode($selectedChild),
                json_encode($selectedChildNotes),
                json_encode($selectedChildQty),
                $breakfastTime,
                $serviceType,
                $breakfastLocation,
                (int)$onTheSpot,
                $specialRequests,
                (int)$link['id']
            ]);

        $targetBookingId = !empty($bookingId) ? (int)$bookingId : 0;
        if ($targetBookingId <= 0) {
            // Fallback: try to resolve booking from guest + breakfast date so extras still land in invoice.
            $resolved = $db->fetchOne(
                "SELECT b.id
                 FROM bookings b
                 LEFT JOIN guests g ON b.guest_id = g.id
                 WHERE LOWER(TRIM(g.guest_name)) = LOWER(TRIM(?))
                   AND DATE(?) BETWEEN DATE(b.check_in_date) AND DATE(b.check_out_date)
                 ORDER BY b.id DESC
                 LIMIT 1",
                [$guestName, $breakfastDate]
            );
            if (!empty($resolved['id'])) {
                $targetBookingId = (int)$resolved['id'];
            }
        }

        if (($extraMainCount > 0 || $extraDrinkCount > 0 || $extraChildCount > 0) && $targetBookingId > 0 && $extraChargeTotal > 0) {
            $extraLabel = [];
            if ($extraMainCount > 0) $extraLabel[] = 'main x' . $extraMainCount;
            if ($extraDrinkCount > 0) $extraLabel[] = 'drink x' . $extraDrinkCount;
            // Kids/child portion is free - not added to the charge label.
            $extraNotes = 'Auto extra from guest portal [' . implode(', ', $extraLabel) . '] token=' . ($link['short_code'] ?? $token) . ' date=' . $breakfastDate;

            // Prevent duplicate extra rows for the same link token: update if exists, else insert.
            $existingExtra = $db->fetchOne(
                "SELECT id FROM booking_extras WHERE booking_id = ? AND item_name = 'Extra Breakfast' AND notes LIKE ? LIMIT 1",
                [$targetBookingId, '%token=' . ($link['short_code'] ?? $token) . '%']
            );

            if (!empty($existingExtra['id'])) {
                $pdo->prepare("UPDATE booking_extras SET quantity = 1, unit_price = ?, total_price = ?, notes = ? WHERE id = ?")
                    ->execute([
                        (float)$extraChargeTotal,
                        (float)$extraChargeTotal,
                        $extraNotes,
                        (int)$existingExtra['id']
                    ]);
            } else {
                $pdo->prepare("INSERT INTO booking_extras (booking_id, item_name, quantity, unit_price, total_price, notes, created_by)
                    VALUES (?, 'Extra Breakfast', 1, ?, ?, ?, NULL)")
                    ->execute([
                        $targetBookingId,
                        (float)$extraChargeTotal,
                        (float)$extraChargeTotal,
                        $extraNotes
                    ]);
            }
        }

        // ============================================================
        // CREATE INVOICE IN CASH_BOOK FOR PAID MENU ITEMS
        // Division: RESTO (id=2), Category: Moka
        // ============================================================
        $paidMenuTotal = 0;
        $paidMenuNames = [];
        foreach ($menuItems as $mi) {
            if (empty($mi['is_free']) && empty($mi['is_on_the_spot'])) {
                $itemTotal = (float)$mi['price'] * (int)$mi['quantity'];
                $paidMenuTotal += $itemTotal;
                $paidMenuNames[] = $mi['menu_name'] . ' x' . $mi['quantity'];
            }
        }

        if ($paidMenuTotal > 0 && $targetBookingId > 0) {
            // Ensure booking_id column exists in cash_book
            try {
                $pdo->exec("ALTER TABLE cash_book ADD COLUMN booking_id INT NULL AFTER payment_method");
            } catch (Exception $e) {
                // Column may already exist
            }

            // Get or create category "Moka" for division RESTO (id=2)
            $mokaCategory = $db->fetchOne("SELECT id FROM categories WHERE division_id = 2 AND LOWER(TRIM(category_name)) = 'moka' LIMIT 1");
            if (empty($mokaCategory['id'])) {
                // Create category Moka if not exists
                $pdo->prepare("INSERT INTO categories (division_id, category_name, category_type, description) VALUES (2, 'Moka', 'income', 'Pembayaran breakfast via guest portal')")->execute();
                $mokaCategoryId = (int)$pdo->lastInsertId();
            } else {
                $mokaCategoryId = (int)$mokaCategory['id'];
            }

            // Build description with menu details
            $roomNumbers = is_array($roomJson) ? implode(', ', $roomJson) : trim($roomJson, '[]"');
            $cashbookDesc = 'Breakfast: ' . implode(', ', $paidMenuNames) . ' - ' . $guestName . ' (Room: ' . $roomNumbers . ') - ' . $breakfastDate;

            // Check if already exists for this booking+date to avoid duplicates
            $existingCashbook = $db->fetchOne(
                "SELECT id FROM cash_book WHERE booking_id = ? AND description LIKE ? AND transaction_date = ? LIMIT 1",
                [$targetBookingId, '%' . $guestName . '%', $breakfastDate]
            );

            if (!empty($existingCashbook['id'])) {
                // Update existing entry
                $pdo->prepare("UPDATE cash_book SET amount = ?, description = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([(float)$paidMenuTotal, $cashbookDesc, (int)$existingCashbook['id']]);
            } else {
                // Insert new income entry
                $pdo->prepare("INSERT INTO cash_book 
                    (transaction_date, transaction_time, division_id, category_id, transaction_type, amount, description, payment_method, booking_id, created_by)
                    VALUES (?, TIME(NOW()), 2, ?, 'income', ?, ?, 'cash', ?, ?)")
                    ->execute([
                        $breakfastDate,
                        $mokaCategoryId,
                        (float)$paidMenuTotal,
                        $cashbookDesc,
                        $targetBookingId,
                        $createdBy
                    ]);
            }
        }

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => $msg('Pilihan sarapan berhasil dikirim', 'Breakfast selection submitted successfully'),
            'service_note' => $msg(
                'Sarapan ini sudah termasuk buah & orange juice - silakan beri tahu waiters.',
                'This breakfast already includes fruit & orange juice - please inform the waiters.'
            ),
            'data' => [
                'order_id' => $orderId,
                'extra_main_count' => $extraMainCount,
                'extra_child_count' => $extraChildCount,
                'extra_total_price' => (float)$extraChargeTotal,
                'paid_menu_total' => (float)$paidMenuTotal
            ]
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal']);
