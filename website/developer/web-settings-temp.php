<?php
/**
 * Developer Panel - Web Settings
 * Configure the Narayana Karimunjawa booking website
 * Manage hero content, room descriptions, SEO, contact info, and website toggle
 */

require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();

$pageTitle = 'Web Settings';
$currentPage = 'web-settings';

$error = '';
$success = '';

// Define all web setting keys with defaults
$webSettings = [
    // General
    'web_enabled'           => '1',
    'web_site_name'         => 'Narayana Karimunjawa',
    'web_tagline'           => 'Island Paradise Resort',
    'web_description'       => 'Luxury beachfront resort in the heart of Karimunjawa Islands. Premium accommodations with stunning ocean views.',
    
    // Hero Section
    'web_hero_accent'       => 'Welcome to Paradise',
    'web_hero_title'        => 'Experience Karimunjawa<br>Like Never Before',
    'web_hero_subtitle'     => 'An exclusive island retreat where tropical luxury meets the pristine beauty of the Java Sea',
    
    // Contact & Social
    'web_whatsapp'          => '6281222228590',
    'web_instagram'         => 'narayanakarimunjawa',
    'web_email'             => 'narayanahotelkarimunjawa@gmail.com',
    'web_phone'             => '+62 812-2222-8590',
    'web_address'           => 'Karimunjawa, Jepara, Central Java, Indonesia 59455',
    
    // Operations
    'web_checkin_time'      => '14:00',
    'web_checkout_time'     => '12:00',
    
    // Room Descriptions (for website display, not in DB)
    'web_room_desc_king'    => 'Our most prestigious accommodation featuring a luxurious king-sized bed, premium ocean-view balcony, and elegant tropical décor. Experience the pinnacle of island luxury.',
    'web_room_desc_queen'   => 'Beautifully appointed rooms with a comfortable queen-sized bed, modern amenities, and a private balcony with garden or partial ocean views. Perfect for couples.',
    'web_room_desc_twin'    => 'Spacious rooms with two single beds, ideal for friends or family. Features modern furnishings, ample storage, and a cozy balcony with tropical garden views.',
    
    // SEO
    'web_meta_title'        => 'Narayana Karimunjawa | Luxury Island Resort',
    'web_meta_description'  => 'Book your tropical paradise getaway at Narayana Karimunjawa. Premium beachfront resort with King, Queen & Twin rooms on Karimunjawa Island.',
    'web_meta_keywords'     => 'karimunjawa hotel, karimunjawa resort, narayana karimunjawa, island resort jepara, karimunjawa accommodation',
    
    // Appearance
    'web_primary_color'     => '#0c2340',
    'web_accent_color'      => '#c8a45e',
    
    // Booking Settings
    'web_max_advance_days'  => '365',
    'web_min_stay_nights'   => '1',
    'web_booking_notice'    => 'Payment is due upon arrival at the hotel. Free cancellation up to 24 hours before check-in.',
];

// Load current values from database
$currentValues = [];
$keys = array_keys($webSettings);
$placeholders = implode(',', array_fill(0, count($keys), '?'));
$rows = \$stmt = \$pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)", $keys);
foreach ($rows as $row) {
    $currentValues[$row['setting_key']] = $row['setting_value'];
}

// Merge: use DB value if exists, otherwise default
foreach ($webSettings as $key => $default) {
    $webSettings[$key] = $currentValues[$key] ?? $default;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_general') {
        $fields = ['web_enabled', 'web_site_name', 'web_tagline', 'web_description'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                \$stmt = \$pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?", 
                            [$key, $val, 'Website: ' . str_replace('web_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        // Handle checkbox (unchecked = not sent)
        if (!isset($_POST['web_enabled'])) {
            \$stmt = \$pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('web_enabled', '0') ON DUPLICATE KEY UPDATE setting_value = '0'");
            $webSettings['web_enabled'] = '0';
        }
        $success = 'General settings saved successfully!';
    }
    
    elseif ($action === 'save_hero') {
        $fields = ['web_hero_accent', 'web_hero_title', 'web_hero_subtitle'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                \$stmt = \$pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?", 
                            [$key, $val, 'Website Hero: ' . str_replace('web_hero_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Hero section saved successfully!';
    }
    
    elseif ($action === 'save_contact') {
        $fields = ['web_whatsapp', 'web_instagram', 'web_email', 'web_phone', 'web_address', 'web_checkin_time', 'web_checkout_time'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                \$stmt = \$pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?", 
                            [$key, $val, 'Website: ' . str_replace('web_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Contact & operations settings saved!';
    }
    
    elseif ($action === 'save_rooms') {
        $fields = ['web_room_desc_king', 'web_room_desc_queen', 'web_room_desc_twin'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                \$stmt = \$pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'textarea', ?) ON DUPLICATE KEY UPDATE setting_value = ?", 
                            [$key, $val, 'Website Room Description', $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Room descriptions saved!';
    }
    
    elseif ($action === 'save_seo') {
        $fields = ['web_meta_title', 'web_meta_description', 'web_meta_keywords'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                \$stmt = \$pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?", 
                            [$key, $val, 'Website SEO: ' . str_replace('web_meta_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'SEO settings saved!';
    }
    
    elseif ($action === 'save_appearance') {
        $fields = ['web_primary_color', 'web_accent_color'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                \$stmt = \$pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?", 
                            [$key, $val, 'Website Color', $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Appearance settings saved!';
    }
    
    elseif ($action === 'save_booking') {
        $fields = ['web_max_advance_days', 'web_min_stay_nights', 'web_booking_notice'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                \$stmt = \$pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?", 
                            [$key, $val, 'Website Booking', $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Booking settings saved!';
    }
}

// Get live stats from hotel database
$hotelDb = null;
try {
    $hotelDb = new PDO(
        'mysql:host=localhost;dbname=adf_narayana_hotel;charset=utf8mb4',
        'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $totalRooms = $hotelDb->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    $availableRooms = $hotelDb->query("SELECT COUNT(*) FROM rooms WHERE status = 'available'")->fetchColumn();
    $totalBookings = $hotelDb->query("SELECT COUNT(*) FROM bookings WHERE booking_source = 'online'")->fetchColumn();
    $todayBookings = $hotelDb->query("SELECT COUNT(*) FROM bookings WHERE booking_source = 'online' AND DATE(created_at) = CURDATE()")->fetchColumn();
    $roomTypes = $hotelDb->query("SELECT rt.type_name, rt.base_price, COUNT(r.id) as room_count 
                                   FROM room_types rt LEFT JOIN rooms r ON r.room_type_id = rt.id 
                                   GROUP BY rt.id ORDER BY rt.base_price DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $totalRooms = $availableRooms = $totalBookings = $todayBookings = 0;
    $roomTypes = [];
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .web-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 28px;
    }
    .web-stat-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .web-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
    }
    .web-stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1a1a2e;
    }
    .web-stat-label {
        font-size: 0.8rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .settings-tabs {
        display: flex;
        gap: 4px;
        background: #f1f3f5;
        border-radius: 12px;
        padding: 4px;
        margin-bottom: 24px;
        overflow-x: auto;
        flex-wrap: nowrap;
    }
    .settings-tab {
        padding: 10px 18px;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #6c757d;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
        border: none;
        background: transparent;
    }
    .settings-tab:hover {
        color: #1a1a2e;
        background: rgba(255,255,255,0.6);
    }
    .settings-tab.active {
        background: white;
        color: var(--dev-primary, #6f42c1);
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }
    
    .settings-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        margin-bottom: 20px;
        overflow: hidden;
    }
    .settings-card-header {
        padding: 20px 24px;
        border-bottom: 1px solid #f1f3f5;
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .settings-card-header .icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
    }
    .settings-card-header h5 {
        margin: 0;
        font-weight: 600;
        font-size: 1rem;
    }
    .settings-card-header small {
        color: #6c757d;
        display: block;
        margin-top: 2px;
    }
    .settings-card-body {
        padding: 24px;
    }
    
    .form-label {
        font-weight: 500;
        font-size: 0.9rem;
        margin-bottom: 4px;
    }
    .form-text {
        font-size: 0.8rem;
        color: #6c757d;
    }
    .form-control, .form-select {
        border-radius: 8px;
    }
    
    .toggle-switch {
        position: relative;
        width: 52px;
        height: 28px;
    }
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0; left: 0; right: 0; bottom: 0;
        background: #dee2e6;
        border-radius: 28px;
        transition: 0.3s;
    }
    .toggle-slider::before {
        content: '';
        position: absolute;
        height: 22px;
        width: 22px;
        left: 3px;
        bottom: 3px;
        background: white;
        border-radius: 50%;
        transition: 0.3s;
    }
    .toggle-switch input:checked + .toggle-slider {
        background: #28a745;
    }
    .toggle-switch input:checked + .toggle-slider::before {
        transform: translateX(24px);
    }
    
    .website-status {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-radius: 10px;
        margin-bottom: 20px;
    }
    .website-status.online {
        background: #d4edda;
        color: #155724;
    }
    .website-status.offline {
        background: #f8d7da;
        color: #721c24;
    }
    .website-status .status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }
    .website-status.online .status-dot { background: #28a745; }
    .website-status.offline .status-dot { background: #dc3545; }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    
    .color-preview {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .color-swatch {
        width: 42px;
        height: 42px;
        border-radius: 8px;
        border: 2px solid #dee2e6;
        cursor: pointer;
    }
    
    .room-type-pills {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .room-type-pill {
        padding: 6px 14px;
        background: #f1f3f5;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    .room-type-pill .count {
        color: var(--dev-primary, #6f42c1);
        font-weight: 700;
    }
    
    .website-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        background: rgba(111, 66, 193, 0.1);
        color: var(--dev-primary, #6f42c1);
        border-radius: 8px;
        font-weight: 500;
        text-decoration: none;
        font-size: 0.85rem;
    }
    .website-link:hover {
        background: rgba(111, 66, 193, 0.2);
        color: var(--dev-primary, #6f42c1);
    }
    
    .preview-hero {
        background: linear-gradient(135deg, var(--preview-primary, #0c2340), #1a3a5c);
        color: white;
        padding: 40px;
        border-radius: 12px;
        text-align: center;
        margin-top: 16px;
    }
    .preview-hero .accent { color: var(--preview-accent, #c8a45e); font-style: italic; font-size: 0.9rem; }
    .preview-hero h3 { font-size: 1.4rem; margin: 8px 0; }
    .preview-hero p { opacity: 0.8; font-size: 0.85rem; }
</style>

<div class="container-fluid py-4">
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-globe me-2"></i>Web Settings</h4>
            <p class="text-muted mb-0">Configure the Narayana Karimunjawa booking website</p>
        </div>
        <div class="d-flex gap-2">
            <a href="http://localhost:8081/narayanakarimunjawa/public/" target="_blank" class="website-link">
                <i class="bi bi-box-arrow-up-right"></i> Visit Website
            </a>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>
    
    <!-- Alerts -->
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Website Status -->
    <div class="website-status <?= $webSettings['web_enabled'] === '1' ? 'online' : 'offline' ?>">
        <div class="status-dot"></div>
        <strong>Website is <?= $webSettings['web_enabled'] === '1' ? 'Online' : 'Offline' ?></strong>
        <span class="ms-auto">
            <?php if ($webSettings['web_enabled'] === '1'): ?>
                Accepting reservations
            <?php else: ?>
                Visitors will see a maintenance page
            <?php endif; ?>
        </span>
    </div>
    
    <!-- Live Stats from Hotel DB -->
    <div class="web-stats-grid">
        <div class="web-stat-card">
            <div class="web-stat-icon" style="background: rgba(111,66,193,0.12); color: #6f42c1;">
                <i class="bi bi-door-open"></i>
            </div>
            <div>
                <div class="web-stat-value"><?= $totalRooms ?></div>
                <div class="web-stat-label">Total Rooms</div>
            </div>
        </div>
        <div class="web-stat-card">
            <div class="web-stat-icon" style="background: rgba(40,167,69,0.12); color: #28a745;">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <div class="web-stat-value"><?= $availableRooms ?></div>
                <div class="web-stat-label">Available Now</div>
            </div>
        </div>
        <div class="web-stat-card">
            <div class="web-stat-icon" style="background: rgba(0,123,255,0.12); color: #007bff;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div class="web-stat-value"><?= $totalBookings ?></div>
                <div class="web-stat-label">Online Bookings</div>
            </div>
        </div>
        <div class="web-stat-card">
            <div class="web-stat-icon" style="background: rgba(255,193,7,0.12); color: #ffc107;">
                <i class="bi bi-lightning"></i>
            </div>
            <div>
                <div class="web-stat-value"><?= $todayBookings ?></div>
                <div class="web-stat-label">Today's Web Bookings</div>
            </div>
        </div>
    </div>
    
    <!-- Room Types Info -->
    <?php if (!empty($roomTypes)): ?>
    <div class="room-type-pills">
        <?php foreach ($roomTypes as $rt): ?>
        <span class="room-type-pill">
            <?= htmlspecialchars($rt['type_name']) ?> — <span class="count"><?= $rt['room_count'] ?> rooms</span>
            — Rp <?= number_format($rt['base_price'], 0, ',', '.') ?>/night
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Settings Tabs -->
    <div class="settings-tabs">
        <button class="settings-tab active" data-tab="general"><i class="bi bi-gear me-1"></i>General</button>
        <button class="settings-tab" data-tab="hero"><i class="bi bi-image me-1"></i>Hero Section</button>
        <button class="settings-tab" data-tab="contact"><i class="bi bi-telephone me-1"></i>Contact & Operations</button>
        <button class="settings-tab" data-tab="rooms"><i class="bi bi-door-open me-1"></i>Room Descriptions</button>
        <button class="settings-tab" data-tab="seo"><i class="bi bi-search me-1"></i>SEO</button>
        <button class="settings-tab" data-tab="appearance"><i class="bi bi-palette me-1"></i>Appearance</button>
        <button class="settings-tab" data-tab="booking"><i class="bi bi-calendar-check me-1"></i>Booking</button>
    </div>
    
    <!-- ============== GENERAL TAB ============== -->
    <div class="tab-content active" id="tab-general">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(111,66,193,0.15); color: #6f42c1;">
                    <i class="bi bi-gear-fill"></i>
                </div>
                <div>
                    <h5>General Settings</h5>
                    <small>Basic website configuration and status</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_general">
                    
                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Website Status</label>
                                <div class="form-text">Enable or disable the public booking website</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="web_enabled" value="1" <?= $webSettings['web_enabled'] === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label">Site Name</label>
                        <input type="text" name="web_site_name" class="form-control" value="<?= htmlspecialchars($webSettings['web_site_name']) ?>" required>
                        <div class="form-text">Displayed in browser tab and footer</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tagline</label>
                        <input type="text" name="web_tagline" class="form-control" value="<?= htmlspecialchars($webSettings['web_tagline']) ?>">
                        <div class="form-text">Short phrase under the site name</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Site Description</label>
                        <textarea name="web_description" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_description']) ?></textarea>
                        <div class="form-text">Used in about sections and meta tags</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save General Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== HERO TAB ============== -->
    <div class="tab-content" id="tab-hero">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(0,123,255,0.15); color: #007bff;">
                    <i class="bi bi-image-fill"></i>
                </div>
                <div>
                    <h5>Hero Section</h5>
                    <small>Customize the main banner on the homepage</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_hero">
                    
                    <div class="mb-3">
                        <label class="form-label">Accent Text</label>
                        <input type="text" name="web_hero_accent" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_accent']) ?>" id="heroAccent">
                        <div class="form-text">Small italic text above the title</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Hero Title</label>
                        <input type="text" name="web_hero_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_title']) ?>" id="heroTitle">
                        <div class="form-text">Main heading. Use &lt;br&gt; for line breaks.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Hero Subtitle</label>
                        <textarea name="web_hero_subtitle" class="form-control" rows="2" id="heroSubtitle"><?= htmlspecialchars($webSettings['web_hero_subtitle']) ?></textarea>
                        <div class="form-text">Description paragraph below the title</div>
                    </div>
                    
                    <!-- Live Preview -->
                    <div class="preview-hero" id="heroPreview" style="--preview-primary: <?= htmlspecialchars($webSettings['web_primary_color']) ?>; --preview-accent: <?= htmlspecialchars($webSettings['web_accent_color']) ?>;">
                        <p class="accent" id="previewAccent"><i><?= htmlspecialchars($webSettings['web_hero_accent']) ?></i></p>
                        <h3 id="previewTitle"><?= $webSettings['web_hero_title'] ?></h3>
                        <p id="previewSubtitle"><?= htmlspecialchars($webSettings['web_hero_subtitle']) ?></p>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 mt-3">
                        <i class="bi bi-check-lg me-1"></i>Save Hero Section
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== CONTACT TAB ============== -->
    <div class="tab-content" id="tab-contact">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(40,167,69,0.15); color: #28a745;">
                    <i class="bi bi-telephone-fill"></i>
                </div>
                <div>
                    <h5>Contact & Operations</h5>
                    <small>Contact information and hotel operation hours</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_contact">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">WhatsApp Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-whatsapp"></i></span>
                                <input type="text" name="web_whatsapp" class="form-control" value="<?= htmlspecialchars($webSettings['web_whatsapp']) ?>" placeholder="628xxxx">
                            </div>
                            <div class="form-text">International format without +</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Instagram Handle</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-instagram"></i></span>
                                <input type="text" name="web_instagram" class="form-control" value="<?= htmlspecialchars($webSettings['web_instagram']) ?>" placeholder="username">
                            </div>
                            <div class="form-text">Without @</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="web_email" class="form-control" value="<?= htmlspecialchars($webSettings['web_email']) ?>">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                <input type="text" name="web_phone" class="form-control" value="<?= htmlspecialchars($webSettings['web_phone']) ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="web_address" class="form-control" rows="2"><?= htmlspecialchars($webSettings['web_address']) ?></textarea>
                    </div>
                    
                    <hr>
                    <h6 class="text-muted mb-3"><i class="bi bi-clock me-1"></i>Operation Hours</h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Check-in Time</label>
                            <input type="time" name="web_checkin_time" class="form-control" value="<?= htmlspecialchars($webSettings['web_checkin_time']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Check-out Time</label>
                            <input type="time" name="web_checkout_time" class="form-control" value="<?= htmlspecialchars($webSettings['web_checkout_time']) ?>">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save Contact & Operations
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== ROOMS TAB ============== -->
    <div class="tab-content" id="tab-rooms">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(255,193,7,0.15); color: #ffc107;">
                    <i class="bi bi-door-open-fill"></i>
                </div>
                <div>
                    <h5>Room Descriptions</h5>
                    <small>Website text for each room type (room data syncs from the hotel system automatically)</small>
                </div>
            </div>
            <div class="settings-card-body">
                <div class="alert alert-info mb-4">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Note:</strong> Room names, prices, and availability are synced in real-time from your hotel management system (frontdesk). 
                    Only the descriptions below are managed here for the website display.
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="save_rooms">
                    
                    <div class="mb-4">
                        <label class="form-label"><span class="badge bg-primary me-2">👑</span>King Room Description</label>
                        <textarea name="web_room_desc_king" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_room_desc_king']) ?></textarea>
                        <div class="form-text">Shown on the rooms page for King-type rooms</div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label"><span class="badge bg-success me-2">🌙</span>Queen Room Description</label>
                        <textarea name="web_room_desc_queen" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_room_desc_queen']) ?></textarea>
                        <div class="form-text">Shown on the rooms page for Queen-type rooms</div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label"><span class="badge bg-info me-2">🛏️</span>Twin Room Description</label>
                        <textarea name="web_room_desc_twin" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_room_desc_twin']) ?></textarea>
                        <div class="form-text">Shown on the rooms page for Twin-type rooms</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save Room Descriptions
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== SEO TAB ============== -->
    <div class="tab-content" id="tab-seo">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(255,87,51,0.15); color: #ff5733;">
                    <i class="bi bi-search"></i>
                </div>
                <div>
                    <h5>SEO Settings</h5>
                    <small>Search engine optimization for better visibility</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_seo">
                    
                    <div class="mb-3">
                        <label class="form-label">Meta Title</label>
                        <input type="text" name="web_meta_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_meta_title']) ?>" maxlength="70">
                        <div class="form-text">
                            <span id="titleCharCount"><?= strlen($webSettings['web_meta_title']) ?></span>/70 characters — 
                            Recommended: 50-60 characters
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Meta Description</label>
                        <textarea name="web_meta_description" class="form-control" rows="3" maxlength="160"><?= htmlspecialchars($webSettings['web_meta_description']) ?></textarea>
                        <div class="form-text">
                            <span id="descCharCount"><?= strlen($webSettings['web_meta_description']) ?></span>/160 characters — 
                            Recommended: 120-155 characters
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Keywords</label>
                        <textarea name="web_meta_keywords" class="form-control" rows="2"><?= htmlspecialchars($webSettings['web_meta_keywords']) ?></textarea>
                        <div class="form-text">Comma-separated keywords. Less important for modern SEO but still useful.</div>
                    </div>
                    
                    <!-- Google Preview -->
                    <div class="card bg-light p-3 mb-3">
                        <small class="text-muted mb-1">Google Search Preview:</small>
                        <div style="font-family: Arial, sans-serif;">
                            <div style="color: #1a0dab; font-size: 18px;"><?= htmlspecialchars($webSettings['web_meta_title']) ?></div>
                            <div style="color: #006621; font-size: 14px;">narayanakarimunjawa.com</div>
                            <div style="color: #545454; font-size: 13px;"><?= htmlspecialchars($webSettings['web_meta_description']) ?></div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save SEO Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== APPEARANCE TAB ============== -->
    <div class="tab-content" id="tab-appearance">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(232,62,140,0.15); color: #e83e8c;">
                    <i class="bi bi-palette-fill"></i>
                </div>
                <div>
                    <h5>Appearance</h5>
                    <small>Website color scheme and visual customization</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_appearance">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Primary Color (Navy)</label>
                            <div class="color-preview">
                                <input type="color" name="web_primary_color" class="color-swatch" value="<?= htmlspecialchars($webSettings['web_primary_color']) ?>" id="primaryColor">
                                <input type="text" class="form-control" value="<?= htmlspecialchars($webSettings['web_primary_color']) ?>" id="primaryColorText" style="max-width: 120px;">
                            </div>
                            <div class="form-text">Main background and text color</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Accent Color (Gold)</label>
                            <div class="color-preview">
                                <input type="color" name="web_accent_color" class="color-swatch" value="<?= htmlspecialchars($webSettings['web_accent_color']) ?>" id="accentColor">
                                <input type="text" class="form-control" value="<?= htmlspecialchars($webSettings['web_accent_color']) ?>" id="accentColorText" style="max-width: 120px;">
                            </div>
                            <div class="form-text">Buttons, highlights, and accents</div>
                        </div>
                    </div>
                    
                    <!-- Color Preview -->
                    <div class="card p-3 mb-3" id="colorPreviewCard">
                        <small class="text-muted mb-2">Preview:</small>
                        <div class="d-flex gap-3 align-items-center">
                            <div style="background: <?= htmlspecialchars($webSettings['web_primary_color']) ?>; color: white; padding: 12px 24px; border-radius: 8px; font-weight: 600;" id="previewPrimary">
                                Primary Button
                            </div>
                            <div style="background: <?= htmlspecialchars($webSettings['web_accent_color']) ?>; color: #1a1a2e; padding: 12px 24px; border-radius: 8px; font-weight: 600;" id="previewAccentBtn">
                                Accent Button
                            </div>
                            <div style="background: #faf8f4; padding: 12px 24px; border-radius: 8px; border: 2px solid <?= htmlspecialchars($webSettings['web_accent_color']) ?>; color: <?= htmlspecialchars($webSettings['web_primary_color']) ?>; font-weight: 600;" id="previewOutline">
                                Outline
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save Appearance
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- ============== BOOKING TAB ============== -->
    <div class="tab-content" id="tab-booking">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(23,162,184,0.15); color: #17a2b8;">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
                <div>
                    <h5>Booking Settings</h5>
                    <small>Configure online booking rules and policies</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_booking">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Max Advance Booking (Days)</label>
                            <input type="number" name="web_max_advance_days" class="form-control" value="<?= htmlspecialchars($webSettings['web_max_advance_days']) ?>" min="30" max="730">
                            <div class="form-text">How far in advance guests can book</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Minimum Stay (Nights)</label>
                            <input type="number" name="web_min_stay_nights" class="form-control" value="<?= htmlspecialchars($webSettings['web_min_stay_nights']) ?>" min="1" max="30">
                            <div class="form-text">Minimum nights per booking</div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Booking Notice / Policy</label>
                        <textarea name="web_booking_notice" class="form-control" rows="3"><?= htmlspecialchars($webSettings['web_booking_notice']) ?></textarea>
                        <div class="form-text">Displayed on the confirmation page for guests</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save Booking Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
    
</div>

<script>
// Tab switching
document.querySelectorAll('.settings-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
});

// Hero live preview
['heroAccent', 'heroTitle', 'heroSubtitle'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', function() {
            if (id === 'heroAccent') document.getElementById('previewAccent').innerHTML = '<i>' + this.value + '</i>';
            if (id === 'heroTitle') document.getElementById('previewTitle').innerHTML = this.value;
            if (id === 'heroSubtitle') document.getElementById('previewSubtitle').textContent = this.value;
        });
    }
});

// Color sync
function syncColors() {
    const primary = document.getElementById('primaryColor');
    const accent = document.getElementById('accentColor');
    const primaryText = document.getElementById('primaryColorText');
    const accentText = document.getElementById('accentColorText');
    
    if (primary && primaryText) {
        primary.addEventListener('input', () => {
            primaryText.value = primary.value;
            updateColorPreview();
        });
        primaryText.addEventListener('input', () => {
            primary.value = primaryText.value;
            updateColorPreview();
        });
    }
    if (accent && accentText) {
        accent.addEventListener('input', () => {
            accentText.value = accent.value;
            updateColorPreview();
        });
        accentText.addEventListener('input', () => {
            accent.value = accentText.value;
            updateColorPreview();
        });
    }
}

function updateColorPreview() {
    const p = document.getElementById('primaryColor')?.value || '#0c2340';
    const a = document.getElementById('accentColor')?.value || '#c8a45e';
    const previewPrimary = document.getElementById('previewPrimary');
    const previewAccent = document.getElementById('previewAccentBtn');
    const previewOutline = document.getElementById('previewOutline');
    if (previewPrimary) previewPrimary.style.background = p;
    if (previewAccent) previewAccent.style.background = a;
    if (previewOutline) {
        previewOutline.style.borderColor = a;
        previewOutline.style.color = p;
    }
    // Update hero preview too
    const heroPreview = document.getElementById('heroPreview');
    if (heroPreview) {
        heroPreview.style.setProperty('--preview-primary', p);
        heroPreview.style.setProperty('--preview-accent', a);
    }
}

syncColors();

// SEO character counters
document.querySelector('[name="web_meta_title"]')?.addEventListener('input', function() {
    document.getElementById('titleCharCount').textContent = this.value.length;
});
document.querySelector('[name="web_meta_description"]')?.addEventListener('input', function() {
    document.getElementById('descCharCount').textContent = this.value.length;
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

