<?php

/**
 * Developer Panel - Web Settings
 * Configure the Narayana Karimunjawa booking website
 * Manage hero content, room descriptions, SEO, contact info, and website toggle
 */

// Increase limits for image uploads (only runtime-settable directives)
// NOTE: upload_max_filesize & post_max_size require .user.ini or php.ini
@ini_set('max_execution_time', '300');
@ini_set('max_input_time', '300');
@ini_set('memory_limit', '256M');

require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/functions.php';
require_once dirname(dirname(__FILE__)) . '/includes/CloudinaryHelper.php';

$devAuth = new DevAuth();
$devAuth->requireLogin();

$db = Database::getInstance();
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

    'web_favicon'            => '', // Path to favicon icon
    'web_logo'               => '', // Path to logo image

    // Destinations (JSON array of destination objects)
    'web_destinations'       => '[]',

    // Activities (JSON array of activity objects)
    'web_activities'         => '[]',

    // Hero — Home
    'web_hero_accent'              => 'Welcome to Paradise',
    'web_hero_title'               => 'Experience Karimunjawa<br>Like Never Before',
    'web_hero_subtitle'            => 'An exclusive island retreat where tropical luxury meets the pristine beauty of the Java Sea',
    'web_hero_background'          => '',
    // Hero Cards (JSON array of room cards displayed on hero)
    'web_hero_cards'               => '[]',
    // Hero — Rooms
    'web_hero_rooms_background'    => '',
    'web_hero_rooms_eyebrow'       => 'Accommodations',
    'web_hero_rooms_title'         => 'Our Rooms',
    'web_hero_rooms_subtitle'      => 'Thoughtfully designed spaces where island comfort meets refined elegance.',
    // Hero — Activities
    'web_hero_act_background'      => 'https://images.unsplash.com/photo-1589308078059-be1415eab4c3?w=1920&q=80',
    'web_hero_act_eyebrow'         => 'Karimunjawa Islands',
    'web_hero_act_title'           => 'Things to Do During Your Island Stay',
    'web_hero_act_subtitle'        => 'Karimunjawa is more than a destination — it\'s a world of its own.',
    // Hero — Destinations
    'web_hero_dest_background'     => '',
    'web_hero_dest_eyebrow'        => 'Explore the Archipelago',
    'web_hero_dest_title'          => 'Discover Karimunjawa',
    'web_hero_dest_subtitle'       => 'Explore the islands, reefs, and shores that make Karimunjawa unforgettable.',
    // Hero — Contact
    'web_hero_contact_background'  => '',
    'web_hero_contact_eyebrow'     => 'Get in Touch',
    'web_hero_contact_title'       => 'Contact Us',
    'web_hero_contact_subtitle'    => 'We\'d love to hear from you. Reach out and let us help plan your stay.',

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

    // Room Gallery Images
    'web_room_gallery_king'  => '', // JSON array of image paths
    'web_room_gallery_queen' => '',
    'web_room_gallery_twin'  => '',
    'web_room_gallery_deluxe_king' => '',
    'web_room_gallery_deluxe_queen' => '',

    // Room Primary (cover) Image
    'web_room_primary_king'  => '',
    'web_room_primary_queen' => '',
    'web_room_primary_twin'  => '',
    'web_room_primary_deluxe_king' => '',
    'web_room_primary_deluxe_queen' => '',

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

    // AI Agent (n8n)
    'agent_api_key'         => '',
    'agent_system_prompt'   => '',
    'agent_enabled'         => '0',
];

// Connect to hotel database for website settings
$webDb = null;
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
$webDbName = $isProduction ? 'adfb2574_narayana_hotel' : 'adf_narayana_hotel';
$webDbUser = $isProduction ? 'adfb2574_adfsystem' : 'root';
$webDbPass = $isProduction ? '@Nnoc2025' : '';

// Website public directory for auto-sync of uploaded images
if ($isProduction) {
    $websitePublicDir = '/home/adfb2574/public_html/narayanakarimunjawa.com';
    $websiteUrl = 'https://narayanakarimunjawa.com';
} else {
    $websitePublicDir = dirname(dirname(__FILE__)) . '/../narayanakarimunjawa/public';
    $websiteUrl = 'http://localhost:8081/narayanakarimunjawa/public/';
}
try {
    $webDb = new PDO(
        'mysql:host=localhost;dbname=' . $webDbName . ';charset=utf8mb4',
        $webDbUser,
        $webDbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Load current values from database
$currentValues = [];
$keys = array_keys($webSettings);
$placeholders = implode(',', array_fill(0, count($keys), '?'));
$stmt = $webDb->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
$stmt->execute($keys);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $currentValues[$row['setting_key']] = $row['setting_value'];
}

// Merge: use DB value if exists, otherwise default
foreach ($webSettings as $key => $default) {
    $webSettings[$key] = $currentValues[$key] ?? $default;
}

$webEnabledRaw = strtolower(trim((string)($webSettings['web_enabled'] ?? '0')));
$isWebEnabled = in_array($webEnabledRaw, ['1', 'true', 'on', 'yes'], true);

// Handle flash messages from redirect
$activeTab = $_GET['tab'] ?? 'general';

// Handle AJAX success redirect
if (isset($_GET['msg']) && $_GET['msg'] === 'success') {
    $success = 'Gallery updated successfully!';
}
if (isset($_SESSION['web_settings_success'])) {
    $success = $_SESSION['web_settings_success'];
    unset($_SESSION['web_settings_success']);
}
if (isset($_SESSION['web_settings_error'])) {
    $error = $_SESSION['web_settings_error'];
    unset($_SESSION['web_settings_error']);
}

// Detect AJAX requests
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Detect if POST data was lost (post_max_size exceeded)
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $maxSize = ini_get('post_max_size');
        $error = "Upload gagal! Ukuran file melebihi batas server ({$maxSize}). Coba upload lebih sedikit gambar atau gambar yang lebih kecil.";
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    }

    $action = $_POST['action'] ?? '';
    $redirectTab = 'general'; // default tab after save

    if ($action === 'save_general') {
        $redirectTab = 'general';
        $fields = ['web_enabled', 'web_site_name', 'web_tagline', 'web_description'];

        // Handle logo upload
        if (isset($_FILES['web_logo']) && $_FILES['web_logo']['error'] === UPLOAD_ERR_OK) {
            $fileInfo = pathinfo($_FILES['web_logo']['name']);
            $allowedExts = ['png', 'svg', 'jpg', 'jpeg', 'webp', 'gif'];
            if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                $localFilename = 'logo-' . time() . '.' . $fileInfo['extension'];
                $cloudinary = CloudinaryHelper::getInstance();
                $uploadResult = $cloudinary->smartUpload($_FILES['web_logo'], 'uploads/logo', $localFilename, 'website', 'web_logo');
                if ($uploadResult['success']) {
                    $relativePath = $uploadResult['path'];
                    $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES ('web_logo', ?, 'text', 'Website Logo') ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$relativePath, $relativePath]);
                    // Sync to website public dir if local
                    if (!$uploadResult['is_cloud']) {
                        $websiteLogoDir = $websitePublicDir . '/uploads/logo/';
                        if (!is_dir($websiteLogoDir)) @mkdir($websiteLogoDir, 0755, true);
                        @copy(dirname(dirname(__FILE__)) . '/' . $relativePath, $websiteLogoDir . $localFilename);
                    }
                    $oldLogo = $webSettings['web_logo'] ?? '';
                    if ($oldLogo && strpos($oldLogo, 'http') !== 0) {
                        $old1 = dirname(dirname(__FILE__)) . '/' . $oldLogo;
                        $old2 = $websitePublicDir . '/' . $oldLogo;
                        if (file_exists($old1)) @unlink($old1);
                        if (file_exists($old2)) @unlink($old2);
                    }
                    $webSettings['web_logo'] = $relativePath;
                }
            }
        }
        // Handle remove logo
        if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            $oldLogo = $webSettings['web_logo'] ?? '';
            if ($oldLogo) {
                $f1 = dirname(dirname(__FILE__)) . '/' . $oldLogo;
                $f2 = $websitePublicDir . '/' . $oldLogo;
                if (file_exists($f1)) @unlink($f1);
                if (file_exists($f2)) @unlink($f2);
            }
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('web_logo', '') ON DUPLICATE KEY UPDATE setting_value = ''");
            $stmt->execute();
            $webSettings['web_logo'] = '';
        }
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = $key === 'web_enabled' ? '1' : trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website: ' . str_replace('web_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        // Handle checkbox (unchecked = not sent)
        if (!isset($_POST['web_enabled'])) {
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('web_enabled', '0') ON DUPLICATE KEY UPDATE setting_value = '0'");
            $stmt->execute();
            $webSettings['web_enabled'] = '0';
        }
        $webEnabledRaw = strtolower(trim((string)($webSettings['web_enabled'] ?? '0')));
        $isWebEnabled = in_array($webEnabledRaw, ['1', 'true', 'on', 'yes'], true);
        // Handle favicon upload
        if (isset($_FILES['web_favicon']) && $_FILES['web_favicon']['error'] === UPLOAD_ERR_OK) {
            $fileInfo = pathinfo($_FILES['web_favicon']['name']);
            $allowedExts = ['ico', 'png', 'svg', 'jpg', 'jpeg', 'webp'];

            if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                $localFilename = 'favicon-' . time() . '.' . $fileInfo['extension'];
                $cloudinary = CloudinaryHelper::getInstance();
                $uploadResult = $cloudinary->smartUpload($_FILES['web_favicon'], 'uploads/favicon', $localFilename, 'website', 'web_favicon');

                if ($uploadResult['success']) {
                    $relativePath = $uploadResult['path'];
                    $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description)
                                VALUES ('web_favicon', ?, 'text', 'Website Favicon Icon')
                                ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$relativePath, $relativePath]);

                    // Sync to website public dir if local
                    if (!$uploadResult['is_cloud']) {
                        $websiteFaviconDir = $websitePublicDir . '/uploads/favicon/';
                        if (!is_dir($websiteFaviconDir)) @mkdir($websiteFaviconDir, 0755, true);
                        @copy(dirname(dirname(__FILE__)) . '/' . $relativePath, $websiteFaviconDir . $localFilename);
                    }

                    // Delete old favicon
                    $oldFav = $webSettings['web_favicon'] ?? '';
                    if ($oldFav && strpos($oldFav, 'http') !== 0) {
                        $old1 = dirname(dirname(__FILE__)) . '/' . $oldFav;
                        $old2 = $websitePublicDir . '/' . $oldFav;
                        if (file_exists($old1)) @unlink($old1);
                        if (file_exists($old2)) @unlink($old2);
                    }
                    $webSettings['web_favicon'] = $relativePath;
                }
            }
        }

        // Handle remove favicon
        if (isset($_POST['remove_favicon']) && $_POST['remove_favicon'] === '1') {
            $oldFav = $webSettings['web_favicon'] ?? '';
            if ($oldFav) {
                $f1 = dirname(dirname(__FILE__)) . '/' . $oldFav;
                $f2 = $websitePublicDir . '/' . $oldFav;
                if (file_exists($f1)) @unlink($f1);
                if (file_exists($f2)) @unlink($f2);
            }
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('web_favicon', '') ON DUPLICATE KEY UPDATE setting_value = ''");
            $stmt->execute();
            $webSettings['web_favicon'] = '';
        }

        $success = 'General settings saved successfully!';
    } elseif ($action === 'save_hero') {
        $redirectTab = 'hero';

        // Save all text fields that start with web_hero_ and exist in $webSettings
        foreach ($_POST as $key => $rawVal) {
            if (strpos($key, 'web_hero_') === 0 && array_key_exists($key, $webSettings)) {
                $val = trim($rawVal);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description)
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website Hero: ' . str_replace('web_hero_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }

        $success = 'Hero berhasil disimpan! ✅';

        // Handle home hero background image upload
        if (isset($_FILES['web_hero_background']) && $_FILES['web_hero_background']['error'] === UPLOAD_ERR_OK) {
            $fileInfo = pathinfo($_FILES['web_hero_background']['name']);
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

            if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                $localFilename = 'hero-bg-' . time() . '.' . $fileInfo['extension'];

                // Smart upload: Cloudinary → local fallback
                $cloudinary = CloudinaryHelper::getInstance();
                $uploadResult = $cloudinary->smartUpload(
                    $_FILES['web_hero_background'],
                    'uploads/hero',
                    $localFilename,
                    'hero',
                    'hero_background'
                );

                if ($uploadResult['success']) {
                    $storedValue = $uploadResult['is_cloud'] ? $uploadResult['url'] : 'uploads/hero/' . $localFilename;

                    $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                                VALUES ('web_hero_background', ?, 'text', 'Website Hero Background Image') 
                                ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$storedValue, $storedValue]);
                    $webSettings['web_hero_background'] = $storedValue;

                    // Auto-sync to narayanakarimunjawa if local
                    if (!$uploadResult['is_cloud']) {
                        $uploadDir = dirname(dirname(__FILE__)) . '/uploads/hero/';
                        $websiteUploadDir = $websitePublicDir . '/uploads/hero/';
                        if (!is_dir($websiteUploadDir)) {
                            mkdir($websiteUploadDir, 0755, true);
                        }
                        @copy($uploadDir . $localFilename, $websiteUploadDir . $localFilename);
                    }

                    // Delete old local image if exists
                    $oldBg = $currentValues['web_hero_background'] ?? '';
                    if ($oldBg && strpos($oldBg, 'http') !== 0) {
                        $oldFile1 = dirname(dirname(__FILE__)) . '/' . $oldBg;
                        $oldFile2 = $websitePublicDir . '/' . $oldBg;
                        if (file_exists($oldFile1)) unlink($oldFile1);
                        if (file_exists($oldFile2)) @unlink($oldFile2);
                    }
                }
            }
        }

        // Handle remove background
        if (isset($_POST['remove_background']) && $_POST['remove_background'] === '1') {
            $oldBg = $webSettings['web_hero_background'] ?? '';
            if ($oldBg) {
                // Delete from both locations
                $file1 = dirname(dirname(__FILE__)) . '/' . $oldBg;
                $file2 = $websitePublicDir . '/' . $oldBg;
                if (file_exists($file1)) unlink($file1);
                if (file_exists($file2)) @unlink($file2);
            }
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value) 
                        VALUES ('web_hero_background', '') ON DUPLICATE KEY UPDATE setting_value = ''");
            $stmt->execute();
            $webSettings['web_hero_background'] = '';
        }

        // Handle per-page hero background uploads (rooms, act, dest, contact)
        $heroPageUploads = [
            'rooms'   => 'web_hero_rooms_background',
            'act'     => 'web_hero_act_background',
            'dest'    => 'web_hero_dest_background',
            'contact' => 'web_hero_contact_background',
        ];
        foreach ($heroPageUploads as $pageSlug => $settingKey) {
            if (isset($_FILES[$settingKey]) && $_FILES[$settingKey]['error'] === UPLOAD_ERR_OK) {
                $fileInfo = pathinfo($_FILES[$settingKey]['name']);
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                    $localFilename = 'hero-' . $pageSlug . '-bg-' . time() . '.' . $fileInfo['extension'];
                    $cloudinary = CloudinaryHelper::getInstance();
                    $uploadResult = $cloudinary->smartUpload(
                        $_FILES[$settingKey],
                        'uploads/hero',
                        $localFilename,
                        'hero',
                        'hero_' . $pageSlug . '_background'
                    );
                    if ($uploadResult['success']) {
                        $storedValue = $uploadResult['is_cloud'] ? $uploadResult['url'] : 'uploads/hero/' . $localFilename;
                        $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description)
                                    VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                        $stmt->execute([$settingKey, $storedValue, 'Website Hero Background: ' . $pageSlug, $storedValue]);
                        $webSettings[$settingKey] = $storedValue;
                        if (!$uploadResult['is_cloud']) {
                            $websiteUploadDir = $websitePublicDir . '/uploads/hero/';
                            if (!is_dir($websiteUploadDir)) mkdir($websiteUploadDir, 0755, true);
                            $srcDir = dirname(dirname(__FILE__)) . '/uploads/hero/';
                            @copy($srcDir . $localFilename, $websiteUploadDir . $localFilename);
                        }
                    }
                }
            }
            // Handle remove
            $removeKey = 'remove_' . $pageSlug . '_background';
            if (isset($_POST[$removeKey]) && $_POST[$removeKey] === '1') {
                $oldBg = $webSettings[$settingKey] ?? '';
                if ($oldBg && strpos($oldBg, 'http') !== 0) {
                    $f1 = dirname(dirname(__FILE__)) . '/' . $oldBg;
                    $f2 = $websitePublicDir . '/' . $oldBg;
                    if (file_exists($f1)) unlink($f1);
                    if (file_exists($f2)) @unlink($f2);
                }
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, '') ON DUPLICATE KEY UPDATE setting_value = ''");
                $stmt->execute([$settingKey]);
                $webSettings[$settingKey] = '';
            }
        }
    } elseif ($action === 'save_hero_cards') {
        $redirectTab = 'hero';
        $cards = [];
        $cardTitles = $_POST['hero_card_title'] ?? [];
        $cardSubtitles = $_POST['hero_card_subtitle'] ?? [];
        $cardImages = $_POST['hero_card_image'] ?? []; // existing image URLs

        for ($i = 0; $i < count($cardTitles); $i++) {
            $title = trim($cardTitles[$i] ?? '');
            $subtitle = trim($cardSubtitles[$i] ?? '');
            $image = trim($cardImages[$i] ?? '');

            // Handle new image upload for this card
            if (isset($_FILES['hero_card_image_file']['name'][$i]) && $_FILES['hero_card_image_file']['error'][$i] === UPLOAD_ERR_OK) {
                $fileInfo = pathinfo($_FILES['hero_card_image_file']['name'][$i]);
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                    $localFilename = 'hero-card-' . $i . '-' . time() . '.' . $fileInfo['extension'];
                    $tmpFile = [
                        'name' => $_FILES['hero_card_image_file']['name'][$i],
                        'type' => $_FILES['hero_card_image_file']['type'][$i],
                        'tmp_name' => $_FILES['hero_card_image_file']['tmp_name'][$i],
                        'error' => $_FILES['hero_card_image_file']['error'][$i],
                        'size' => $_FILES['hero_card_image_file']['size'][$i],
                    ];
                    $cloudinary = CloudinaryHelper::getInstance();
                    $uploadResult = $cloudinary->smartUpload(
                        $tmpFile,
                        'uploads/hero',
                        $localFilename,
                        'hero',
                        'hero_card_' . $i
                    );
                    if ($uploadResult['success']) {
                        $image = $uploadResult['is_cloud'] ? $uploadResult['url'] : 'uploads/hero/' . $localFilename;
                    }
                }
            }

            if ($title) {
                $cards[] = ['title' => $title, 'subtitle' => $subtitle, 'image' => $image];
            }
        }

        $cardsJson = json_encode($cards);
        $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                    VALUES ('web_hero_cards', ?, 'text', 'Hero Room Cards') ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$cardsJson, $cardsJson]);
        $webSettings['web_hero_cards'] = $cardsJson;
        $success = 'Hero room cards saved! ✅';
    } elseif ($action === 'save_contact') {
        $redirectTab = 'contact';
        $fields = ['web_whatsapp', 'web_instagram', 'web_email', 'web_phone', 'web_address', 'web_checkin_time', 'web_checkout_time'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website: ' . str_replace('web_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Contact & operations settings saved!';
    } elseif ($action === 'save_rooms') {
        $redirectTab = 'rooms';
        $fields = ['web_room_desc_king', 'web_room_desc_queen', 'web_room_desc_twin'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'textarea', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website Room Description', $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Room descriptions saved!';
    } elseif ($action === 'save_seo') {
        $redirectTab = 'seo';
        $fields = ['web_meta_title', 'web_meta_description', 'web_meta_keywords'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website SEO: ' . str_replace('web_meta_', '', $key), $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'SEO settings saved!';
    } elseif ($action === 'save_appearance') {
        $redirectTab = 'appearance';
        $fields = ['web_primary_color', 'web_accent_color'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website Color', $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Appearance settings saved!';
    } elseif ($action === 'save_agent') {
        $redirectTab = 'agent';
        // Regenerate key jika diminta
        if (!empty($_POST['regenerate_key'])) {
            $newKey = 'nryn-' . bin2hex(random_bytes(20));
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description)
                        VALUES ('agent_api_key', ?, 'text', 'AI Agent API Key') ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$newKey, $newKey]);
            $webSettings['agent_api_key'] = $newKey;
        }
        // Simpan system prompt & toggle
        foreach (['agent_system_prompt', 'agent_enabled'] as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description)
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'AI Agent: ' . $key, $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'AI Agent settings disimpan! ✅';
    } elseif ($action === 'save_booking') {
        $redirectTab = 'booking';
        $fields = ['web_max_advance_days', 'web_min_stay_nights', 'web_booking_notice'];
        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, 'Website Booking', $val]);
                $webSettings[$key] = $val;
            }
        }
        $success = 'Booking settings saved!';
    } elseif ($action === 'save_room_gallery') {
        $redirectTab = 'gallery';
        $roomType = $_POST['room_type'] ?? '';
        $allowedRoomTypes = ['king', 'queen', 'twin', 'deluxe_king', 'deluxe_queen'];
        if (!in_array($roomType, $allowedRoomTypes, true)) {
            $error = 'Invalid room type!';
        } else {
            $galleryKey = 'web_room_gallery_' . $roomType;

            // Load existing gallery
            $existingGallery = json_decode($webSettings[$galleryKey] ?? '[]', true) ?: [];

            // Handle file uploads
            if (isset($_FILES['room_images']) && !empty($_FILES['room_images']['name'][0])) {
                $uploadDir = dirname(dirname(__FILE__)) . '/uploads/rooms/' . $roomType . '/';
                $websiteUploadDir = $websitePublicDir . '/uploads/rooms/' . $roomType . '/';

                // Create directories if not exist
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                if (!is_dir($websiteUploadDir)) {
                    mkdir($websiteUploadDir, 0755, true);
                }

                $cloudinary = CloudinaryHelper::getInstance();
                $uploaded = 0;
                foreach ($_FILES['room_images']['tmp_name'] as $key => $tmpName) {
                    if ($_FILES['room_images']['error'][$key] === UPLOAD_ERR_OK) {
                        $fileInfo = pathinfo($_FILES['room_images']['name'][$key]);
                        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

                        if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                            $newFileName = $roomType . '-' . time() . '-' . $uploaded . '.' . $fileInfo['extension'];

                            // Try Cloudinary first
                            if ($cloudinary->isEnabled()) {
                                $cloudResult = $cloudinary->upload($tmpName, 'rooms/' . $roomType);
                                if ($cloudResult) {
                                    $existingGallery[] = $cloudResult['secure_url'];
                                    $uploaded++;
                                    continue;
                                }
                            }

                            // Fallback: local storage
                            $uploadPath = $uploadDir . $newFileName;
                            if (move_uploaded_file($tmpName, $uploadPath)) {
                                // Auto-sync to narayanakarimunjawa
                                @copy($uploadPath, $websiteUploadDir . $newFileName);

                                $relativePath = 'uploads/rooms/' . $roomType . '/' . $newFileName;
                                $existingGallery[] = $relativePath;
                                $uploaded++;
                            }
                        }
                    }
                }
            }

            // Handle image deletion
            if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
                foreach ($_POST['delete_images'] as $imgPath) {
                    // Delete from both locations
                    $file1 = dirname(dirname(__FILE__)) . '/' . $imgPath;
                    $file2 = $websitePublicDir . '/' . $imgPath;
                    if (file_exists($file1)) unlink($file1);
                    if (file_exists($file2)) @unlink($file2);

                    $existingGallery = array_diff($existingGallery, [$imgPath]);
                }
                $existingGallery = array_values($existingGallery); // Re-index
            }

            // Handle primary image selection
            $primaryKey = 'web_room_primary_' . $roomType;
            if (isset($_POST['primary_image']) && in_array($_POST['primary_image'], $existingGallery)) {
                $primaryVal = $_POST['primary_image'];

                // Keep primary image at index 0 so all website render variants show it first.
                $existingGallery = array_values(array_diff($existingGallery, [$primaryVal]));
                array_unshift($existingGallery, $primaryVal);

                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$primaryKey, $primaryVal, 'Room Primary Image', $primaryVal]);
                $webSettings[$primaryKey] = $primaryVal;
            }
            // If primary was deleted, clear it
            $currentPrimary = $webSettings[$primaryKey] ?? '';
            if ($currentPrimary && !in_array($currentPrimary, $existingGallery)) {
                $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                            VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$primaryKey, '', 'Room Primary Image', '']);
                $webSettings[$primaryKey] = '';
            }

            // Save to database
            $galleryJson = json_encode($existingGallery);
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                        VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$galleryKey, $galleryJson, 'Website Room Gallery', $galleryJson]);
            $webSettings[$galleryKey] = $galleryJson;

            $roomTypeLabels = [
                'king' => 'King Room',
                'queen' => 'Queen Room',
                'twin' => 'Twin Room',
                'deluxe_king' => 'Deluxe King Room',
                'deluxe_queen' => 'Deluxe Queen Room',
            ];
            $success = ($roomTypeLabels[$roomType] ?? ucfirst(str_replace('_', ' ', $roomType))) . ' gallery updated!';
        }
    } elseif ($action === 'save_destinations') {
        $redirectTab = 'destinations';

        // Load existing destinations
        $existingDest = json_decode($webSettings['web_destinations'] ?? '[]', true) ?: [];

        // Handle adding new destination
        if (!empty($_POST['dest_title'])) {
            $newDest = [
                'id' => uniqid('dest_'),
                'title' => trim($_POST['dest_title']),
                'subtitle' => trim($_POST['dest_subtitle'] ?? ''),
                'content' => trim($_POST['dest_content'] ?? ''),
                'image' => '',
                'order' => count($existingDest) + 1,
                'active' => true,
            ];

            // Handle image upload for new destination
            if (isset($_FILES['dest_image']) && $_FILES['dest_image']['error'] === UPLOAD_ERR_OK) {
                $fileInfo = pathinfo($_FILES['dest_image']['name']);
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                    $localFilename = 'dest-' . time() . '.' . $fileInfo['extension'];
                    $cloudinary = CloudinaryHelper::getInstance();
                    $uploadResult = $cloudinary->smartUpload($_FILES['dest_image'], 'uploads/destinations', $localFilename, 'destinations', 'dest_' . $newDest['id']);
                    if ($uploadResult['success']) {
                        $newDest['image'] = $uploadResult['path'];
                        if (!$uploadResult['is_cloud']) {
                            $websiteDestDir = $websitePublicDir . '/uploads/destinations/';
                            if (!is_dir($websiteDestDir)) @mkdir($websiteDestDir, 0755, true);
                            @copy(dirname(dirname(__FILE__)) . '/' . $uploadResult['path'], $websiteDestDir . $localFilename);
                        }
                    }
                }
            }

            $existingDest[] = $newDest;
            $success = 'Destination "' . $newDest['title'] . '" added!';
        }

        // Handle update existing destinations
        if (isset($_POST['dest_update_id']) && is_array($_POST['dest_update_id'])) {
            foreach ($_POST['dest_update_id'] as $idx => $destId) {
                foreach ($existingDest as &$d) {
                    if ($d['id'] === $destId) {
                        $d['title'] = trim($_POST['dest_update_title'][$idx] ?? $d['title']);
                        $d['subtitle'] = trim($_POST['dest_update_subtitle'][$idx] ?? $d['subtitle']);
                        $d['content'] = trim($_POST['dest_update_content'][$idx] ?? $d['content']);
                        $d['order'] = (int)($_POST['dest_update_order'][$idx] ?? $d['order']);
                        $d['active'] = isset($_POST['dest_update_active']) && in_array($destId, $_POST['dest_update_active']);

                        // Handle image upload for update
                        $fileKey = 'dest_update_image_' . $destId;
                        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                            $fileInfo = pathinfo($_FILES[$fileKey]['name']);
                            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                            if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                                $localFilename = 'dest-' . time() . '-' . $idx . '.' . $fileInfo['extension'];
                                $cloudinary = CloudinaryHelper::getInstance();
                                $uploadResult = $cloudinary->smartUpload($_FILES[$fileKey], 'uploads/destinations', $localFilename, 'destinations', 'dest_' . $destId);
                                if ($uploadResult['success']) {
                                    // Delete old image (local only)
                                    if (!empty($d['image']) && strpos($d['image'], 'http') !== 0) {
                                        $old1 = dirname(dirname(__FILE__)) . '/' . $d['image'];
                                        $old2 = $websitePublicDir . '/' . $d['image'];
                                        if (file_exists($old1)) @unlink($old1);
                                        if (file_exists($old2)) @unlink($old2);
                                    }
                                    $d['image'] = $uploadResult['path'];
                                    if (!$uploadResult['is_cloud']) {
                                        $websiteDestDir = $websitePublicDir . '/uploads/destinations/';
                                        if (!is_dir($websiteDestDir)) @mkdir($websiteDestDir, 0755, true);
                                        @copy(dirname(dirname(__FILE__)) . '/' . $uploadResult['path'], $websiteDestDir . $localFilename);
                                    }
                                }
                            }
                        }
                        break;
                    }
                }
            }
            unset($d);
            if (!$success) $success = 'Destinations updated!';
        }

        // Handle delete destinations
        if (isset($_POST['delete_dest']) && is_array($_POST['delete_dest'])) {
            foreach ($_POST['delete_dest'] as $destId) {
                foreach ($existingDest as $dk => $d) {
                    if ($d['id'] === $destId) {
                        if (!empty($d['image'])) {
                            $f1 = dirname(dirname(__FILE__)) . '/' . $d['image'];
                            $f2 = $websitePublicDir . '/' . $d['image'];
                            if (file_exists($f1)) @unlink($f1);
                            if (file_exists($f2)) @unlink($f2);
                        }
                        unset($existingDest[$dk]);
                        break;
                    }
                }
            }
            $existingDest = array_values($existingDest);
            $success = 'Destination(s) deleted!';
        }

        // Sort by order
        usort($existingDest, function ($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });

        // Save to database
        $destJson = json_encode($existingDest);
        $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                    VALUES ('web_destinations', ?, 'text', 'Website Destinations') 
                    ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$destJson, $destJson]);
        $webSettings['web_destinations'] = $destJson;
    } elseif ($action === 'save_activities') {
        $redirectTab = 'activities';

        // Load existing activities
        $existingAct = json_decode($webSettings['web_activities'] ?? '[]', true) ?: [];

        // Handle adding new activity
        if (!empty($_POST['act_title'])) {
            $newAct = [
                'id' => uniqid('act_'),
                'eyebrow' => trim($_POST['act_eyebrow'] ?? ''),
                'title' => trim($_POST['act_title']),
                'image' => trim($_POST['act_image'] ?? ''),
                'body' => trim($_POST['act_body'] ?? ''),
                'details' => array_filter(array_map('trim', explode("\n", $_POST['act_details'] ?? ''))),
                'order' => count($existingAct) + 1,
                'active' => true,
            ];

            // Handle image upload for new activity
            if (isset($_FILES['act_image_file']) && $_FILES['act_image_file']['error'] === UPLOAD_ERR_OK) {
                $fileInfo = pathinfo($_FILES['act_image_file']['name']);
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                    $localFilename = 'act-' . time() . '.' . $fileInfo['extension'];
                    $cloudinary = CloudinaryHelper::getInstance();
                    $uploadResult = $cloudinary->smartUpload($_FILES['act_image_file'], 'uploads/activities', $localFilename, 'activities', 'act_' . $newAct['id']);
                    if ($uploadResult['success']) {
                        $newAct['image'] = $uploadResult['path'];
                        if (!$uploadResult['is_cloud']) {
                            $websiteActDir = $websitePublicDir . '/uploads/activities/';
                            if (!is_dir($websiteActDir)) @mkdir($websiteActDir, 0755, true);
                            @copy(dirname(dirname(__FILE__)) . '/' . $uploadResult['path'], $websiteActDir . $localFilename);
                        }
                    }
                }
            }

            $existingAct[] = $newAct;
            $success = 'Activity "' . $newAct['title'] . '" added!';
        }

        // Handle update existing activities
        if (isset($_POST['act_update_id']) && is_array($_POST['act_update_id'])) {
            foreach ($_POST['act_update_id'] as $idx => $actId) {
                foreach ($existingAct as &$a) {
                    if ($a['id'] === $actId) {
                        $a['eyebrow'] = trim($_POST['act_update_eyebrow'][$idx] ?? $a['eyebrow']);
                        $a['title'] = trim($_POST['act_update_title'][$idx] ?? $a['title']);
                        $a['body'] = trim($_POST['act_update_body'][$idx] ?? $a['body']);
                        $a['order'] = (int)($_POST['act_update_order'][$idx] ?? $a['order']);
                        $a['active'] = isset($_POST['act_update_active']) && in_array($actId, $_POST['act_update_active']);

                        // Details: one per line
                        $rawDetails = $_POST['act_update_details'][$idx] ?? '';
                        $a['details'] = array_values(array_filter(array_map('trim', explode("\n", $rawDetails))));

                        // Image URL (text field)
                        if (isset($_POST['act_update_image'][$idx]) && trim($_POST['act_update_image'][$idx]) !== '') {
                            $a['image'] = trim($_POST['act_update_image'][$idx]);
                        }

                        // Handle image upload for update
                        $fileKey = 'act_update_image_file_' . $actId;
                        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                            $fileInfo = pathinfo($_FILES[$fileKey]['name']);
                            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                            if (in_array(strtolower($fileInfo['extension']), $allowedExts)) {
                                $localFilename = 'act-' . time() . '-' . $idx . '.' . $fileInfo['extension'];
                                $cloudinary = CloudinaryHelper::getInstance();
                                $uploadResult = $cloudinary->smartUpload($_FILES[$fileKey], 'uploads/activities', $localFilename, 'activities', 'act_' . $actId);
                                if ($uploadResult['success']) {
                                    if (!empty($a['image']) && strpos($a['image'], 'http') !== 0) {
                                        $old1 = dirname(dirname(__FILE__)) . '/' . $a['image'];
                                        $old2 = $websitePublicDir . '/' . $a['image'];
                                        if (file_exists($old1)) @unlink($old1);
                                        if (file_exists($old2)) @unlink($old2);
                                    }
                                    $a['image'] = $uploadResult['path'];
                                    if (!$uploadResult['is_cloud']) {
                                        $websiteActDir = $websitePublicDir . '/uploads/activities/';
                                        if (!is_dir($websiteActDir)) @mkdir($websiteActDir, 0755, true);
                                        @copy(dirname(dirname(__FILE__)) . '/' . $uploadResult['path'], $websiteActDir . $localFilename);
                                    }
                                }
                            }
                        }
                        break;
                    }
                }
            }
            unset($a);
            if (!$success) $success = 'Activities updated!';
        }

        // Handle delete activities
        if (isset($_POST['delete_act']) && is_array($_POST['delete_act'])) {
            foreach ($_POST['delete_act'] as $actId) {
                foreach ($existingAct as $ak => $a) {
                    if ($a['id'] === $actId) {
                        if (!empty($a['image']) && strpos($a['image'], 'http') !== 0) {
                            $f1 = dirname(dirname(__FILE__)) . '/' . $a['image'];
                            $f2 = $websitePublicDir . '/' . $a['image'];
                            if (file_exists($f1)) @unlink($f1);
                            if (file_exists($f2)) @unlink($f2);
                        }
                        unset($existingAct[$ak]);
                        break;
                    }
                }
            }
            $existingAct = array_values($existingAct);
            $success = 'Activity deleted!';
        }

        // Sort by order
        usort($existingAct, function ($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });

        // Save to database
        $actJson = json_encode($existingAct);
        $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                    VALUES ('web_activities', ?, 'text', 'Website Activities') 
                    ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$actJson, $actJson]);
        $webSettings['web_activities'] = $actJson;
    }

    // For AJAX requests: return JSON response
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => !empty($success),
            'message' => $success ?: $error,
            'tab' => $redirectTab
        ]);
        exit;
    }

    // POST-Redirect-GET: store message in session and redirect to preserve active tab
    if ($success || $error) {
        if ($success) $_SESSION['web_settings_success'] = $success;
        if ($error) $_SESSION['web_settings_error'] = $error;
        header('Location: web-settings.php?tab=' . urlencode($redirectTab));
        exit;
    }
}

// Get live stats from hotel database (using same $webDb connection)
try {
    $totalRooms = $webDb->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    $availableRooms = $webDb->query("SELECT COUNT(*) FROM rooms WHERE status = 'available'")->fetchColumn();
    $totalBookings = $webDb->query("SELECT COUNT(*) FROM bookings WHERE booking_source = 'online'")->fetchColumn();
    $todayBookings = $webDb->query("SELECT COUNT(*) FROM bookings WHERE booking_source = 'online' AND DATE(created_at) = CURDATE()")->fetchColumn();
    $roomTypes = $webDb->query("SELECT rt.type_name, rt.base_price, COUNT(r.id) as room_count 
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
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
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
        background: rgba(255, 255, 255, 0.6);
    }

    .settings-tab.active {
        background: white;
        color: var(--dev-primary, #6f42c1);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
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
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
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

    .form-control,
    .form-select {
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
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
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

    .toggle-switch input:checked+.toggle-slider {
        background: #28a745;
    }

    .toggle-switch input:checked+.toggle-slider::before {
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

    .website-status.online .status-dot {
        background: #28a745;
    }

    .website-status.offline .status-dot {
        background: #dc3545;
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }
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
        position: relative;
        background-size: cover;
        background-position: center;
        overflow: hidden;
    }

    .preview-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(12, 35, 64, 0.85), rgba(26, 58, 92, 0.85));
        z-index: 1;
    }

    .preview-hero>* {
        position: relative;
        z-index: 2;
    }

    .preview-hero .accent {
        color: var(--preview-accent, #c8a45e);
        font-style: italic;
        font-size: 0.9rem;
    }

    .preview-hero h3 {
        font-size: 1.4rem;
        margin: 8px 0;
    }

    .preview-hero p {
        opacity: 0.9;
        font-size: 0.85rem;
    }

    .current-bg-preview {
        border: 2px solid #e9ecef;
        border-radius: 8px;
        padding: 8px;
        background: #f8f9fa;
    }

    .hero-page-tab {
        padding: 7px 18px;
        border: 1.5px solid #dee2e6;
        background: white;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        color: #6c757d;
        transition: all 0.18s;
    }

    .hero-page-tab:hover {
        border-color: #6f42c1;
        color: #6f42c1;
    }

    .hero-page-tab.active {
        background: #6f42c1;
        border-color: #6f42c1;
        color: white;
    }

    .hero-page-panel {
        display: none;
    }

    .hero-page-panel.active {
        display: block;
    }
</style>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-globe me-2"></i>Web Settings</h4>
            <p class="text-muted mb-0">Configure the Narayana Karimunjawa booking website</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= $websiteUrl ?>" target="_blank" class="website-link">
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
    <div class="website-status <?= $isWebEnabled ? 'online' : 'offline' ?>">
        <div class="status-dot"></div>
        <strong>Website is <?= $isWebEnabled ? 'Online' : 'Offline' ?></strong>
        <span class="ms-auto">
            <?php if ($isWebEnabled): ?>
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
        <button class="settings-tab <?= $activeTab === 'general' ? 'active' : '' ?>" data-tab="general"><i class="bi bi-gear me-1"></i>General</button>
        <button class="settings-tab <?= $activeTab === 'hero' ? 'active' : '' ?>" data-tab="hero"><i class="bi bi-image me-1"></i>Hero Section</button>
        <button class="settings-tab <?= $activeTab === 'contact' ? 'active' : '' ?>" data-tab="contact"><i class="bi bi-telephone me-1"></i>Contact & Operations</button>
        <button class="settings-tab <?= $activeTab === 'rooms' ? 'active' : '' ?>" data-tab="rooms"><i class="bi bi-door-open me-1"></i>Room Descriptions</button>
        <button class="settings-tab <?= $activeTab === 'gallery' ? 'active' : '' ?>" data-tab="gallery"><i class="bi bi-images me-1"></i>Room Gallery</button>
        <button class="settings-tab <?= $activeTab === 'seo' ? 'active' : '' ?>" data-tab="seo"><i class="bi bi-search me-1"></i>SEO</button>
        <button class="settings-tab <?= $activeTab === 'appearance' ? 'active' : '' ?>" data-tab="appearance"><i class="bi bi-palette me-1"></i>Appearance</button>
        <button class="settings-tab <?= $activeTab === 'booking' ? 'active' : '' ?>" data-tab="booking"><i class="bi bi-calendar-check me-1"></i>Booking</button>
        <button class="settings-tab <?= $activeTab === 'destinations' ? 'active' : '' ?>" data-tab="destinations"><i class="bi bi-geo-alt me-1"></i>Destinations</button>
        <button class="settings-tab <?= $activeTab === 'activities' ? 'active' : '' ?>" data-tab="activities"><i class="bi bi-compass me-1"></i>Activities</button>
        <button class="settings-tab <?= $activeTab === 'agent' ? 'active' : '' ?>" data-tab="agent"><i class="bi bi-robot me-1"></i>AI Agent</button>
    </div>

    <!-- ============== GENERAL TAB ============== -->
    <div class="tab-content <?= $activeTab === 'general' ? 'active' : '' ?>" id="tab-general">
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
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_general">

                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Website Status</label>
                                <div class="form-text">Enable or disable the public booking website</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="web_enabled" value="1" <?= $isWebEnabled ? 'checked' : '' ?>>
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

                    <hr>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-globe2 me-1"></i>Favicon (Browser Tab Icon)</label>
                        <?php if (!empty($webSettings['web_favicon'])): ?>
                            <div class="mb-2 p-3 rounded" style="background: #f8f9fa; display: flex; align-items: center; gap: 16px;">
                                <div style="width: 48px; height: 48px; border: 2px solid #dee2e6; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #fff;">
                                    <img src="<?= (strpos($webSettings['web_favicon'], 'http') === 0) ? htmlspecialchars($webSettings['web_favicon']) : '../' . htmlspecialchars($webSettings['web_favicon']) ?>" style="max-width: 32px; max-height: 32px;" alt="Favicon">
                                </div>
                                <div>
                                    <div style="font-size: 13px; color: #333; font-weight: 500;">Current Favicon</div>
                                    <div style="font-size: 11px; color: #888;"><?= htmlspecialchars($webSettings['web_favicon']) ?></div>
                                </div>
                                <label style="margin-left: auto; font-size: 12px; color: #dc3545; cursor: pointer;">
                                    <input type="checkbox" name="remove_favicon" value="1" style="margin-right: 4px;">Remove
                                </label>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="web_favicon" class="form-control" accept="image/x-icon,image/png,image/svg+xml,image/jpeg,image/webp">
                        <div class="form-text">Upload an icon for the browser tab. Recommended: 32×32px or 64×64px PNG/ICO file.</div>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-image me-1"></i>Website Logo (Navbar)</label>
                        <?php if (!empty($webSettings['web_logo'])): ?>
                            <div class="mb-2 p-3 rounded" style="background: #f8f9fa; display: flex; align-items: center; gap: 16px;">
                                <div style="width: 80px; height: 48px; border: 2px solid #dee2e6; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #2d2926;">
                                    <img src="<?= (strpos($webSettings['web_logo'], 'http') === 0) ? htmlspecialchars($webSettings['web_logo']) : '../' . htmlspecialchars($webSettings['web_logo']) ?>" style="max-width: 72px; max-height: 40px; object-fit: contain;" alt="Logo">
                                </div>
                                <div>
                                    <div style="font-size: 13px; color: #333; font-weight: 500;">Current Logo</div>
                                    <div style="font-size: 11px; color: #888;"><?= htmlspecialchars($webSettings['web_logo']) ?></div>
                                </div>
                                <label style="margin-left: auto; font-size: 12px; color: #dc3545; cursor: pointer;">
                                    <input type="checkbox" name="remove_logo" value="1" style="margin-right: 4px;">Remove
                                </label>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="web_logo" class="form-control" accept="image/png,image/svg+xml,image/jpeg,image/webp,image/gif">
                        <div class="form-text">Upload a logo to display before the hotel name in the navbar. Recommended: transparent PNG, max 150×50px.</div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Save General Settings
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ============== HERO TAB ============== -->
    <div class="tab-content <?= $activeTab === 'hero' ? 'active' : '' ?>" id="tab-hero">

        <!-- Per-page sub-tabs -->
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;">
            <button class="hero-page-tab active" data-page="home"><i class="bi bi-house me-1"></i>Home</button>
            <button class="hero-page-tab" data-page="rooms"><i class="bi bi-door-open me-1"></i>Rooms</button>
            <button class="hero-page-tab" data-page="activities"><i class="bi bi-water me-1"></i>Activities</button>
            <button class="hero-page-tab" data-page="destinations"><i class="bi bi-geo-alt me-1"></i>Destinations</button>
            <button class="hero-page-tab" data-page="contact"><i class="bi bi-telephone me-1"></i>Contact</button>
        </div>

        <!-- ---- HOME ---- -->
        <div class="hero-page-panel active" id="hero-panel-home">
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background:rgba(0,123,255,0.15);color:#007bff;"><i class="bi bi-house-fill"></i></div>
                    <div>
                        <h5>Home Page Hero</h5><small>Banner utama yang dilihat tamu pertama kali</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_hero">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Accent / Eyebrow</label>
                                <input type="text" name="web_hero_accent" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_accent']) ?>" id="heroAccent">
                                <div class="form-text">Teks kecil di atas judul</div>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Title <small class="text-muted">(gunakan &lt;br&gt; untuk baris baru, &lt;em&gt; untuk miring)</small></label>
                                <input type="text" name="web_hero_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_title']) ?>" id="heroTitle">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subtitle</label>
                            <textarea name="web_hero_subtitle" class="form-control" rows="2" id="heroSubtitle"><?= htmlspecialchars($webSettings['web_hero_subtitle']) ?></textarea>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-image me-1"></i>Background Image</label>
                            <?php if (!empty($webSettings['web_hero_background'])): ?>
                                <div class="current-bg-preview mb-3">
                                    <img src="<?= (strpos($webSettings['web_hero_background'], 'http') === 0) ? htmlspecialchars($webSettings['web_hero_background']) : '../' . htmlspecialchars($webSettings['web_hero_background']) ?>" style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;" alt="">
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeBackground()"><i class="bi bi-trash me-1"></i>Remove</button>
                                        <input type="hidden" name="remove_background" id="removeBackgroundInput" value="0">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="web_hero_background" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp">
                            <div class="form-text">Upload foto (JPG/PNG/WEBP). Recommended: 1920×1080px. <?= !empty($webSettings['web_hero_background']) ? 'Biarkan kosong untuk mempertahankan gambar saat ini.' : '' ?></div>
                        </div>
                        <div class="preview-hero mb-3" id="heroPreview" style="--preview-primary:<?= htmlspecialchars($webSettings['web_primary_color']) ?>;--preview-accent:<?= htmlspecialchars($webSettings['web_accent_color']) ?>;<?php if (!empty($webSettings['web_hero_background'])): ?>background-image:url('<?= (strpos($webSettings['web_hero_background'], 'http') === 0) ? htmlspecialchars($webSettings['web_hero_background']) : '../' . htmlspecialchars($webSettings['web_hero_background']) ?>');<?php endif; ?>">
                            <p class="accent" id="previewAccent"><i><?= htmlspecialchars($webSettings['web_hero_accent']) ?></i></p>
                            <h3 id="previewTitle"><?= $webSettings['web_hero_title'] ?></h3>
                            <p id="previewSubtitle"><?= htmlspecialchars($webSettings['web_hero_subtitle']) ?></p>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Save Home Hero</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ---- HERO ROOM CARDS ---- -->
        <div class="hero-page-panel" id="hero-panel-home">
            <div class="settings-card mt-3">
                <div class="settings-card-header">
                    <div class="icon" style="background:rgba(13,202,240,0.15);color:#0dcaf0;"><i class="bi bi-grid-3x3-gap-fill"></i></div>
                    <div>
                        <h5>Hero Room Cards</h5><small>Kartu room type yang muncul di sebelah kanan hero (max 3)</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <?php $heroCards = json_decode($webSettings['web_hero_cards'] ?? '[]', true) ?: []; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_hero_cards">
                        <div id="heroCardsContainer">
                            <?php
                            // Ensure at least 3 card slots
                            while (count($heroCards) < 3) {
                                $heroCards[] = ['title' => '', 'subtitle' => '', 'image' => ''];
                            }
                            foreach ($heroCards as $ci => $card):
                            ?>
                                <div class="card mb-3 border" style="border-radius:10px;">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <strong>Card <?= $ci + 1 ?></strong>
                                            <?php if (!empty($card['image'])): ?>
                                                <img src="<?= (strpos($card['image'], 'http') === 0) ? htmlspecialchars($card['image']) : '../' . htmlspecialchars($card['image']) ?>" style="width:60px;height:40px;object-fit:cover;border-radius:6px;" alt="">
                                            <?php endif; ?>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-5">
                                                <label class="form-label" style="font-size:12px;">Title</label>
                                                <input type="text" name="hero_card_title[]" class="form-control form-control-sm" value="<?= htmlspecialchars($card['title'] ?? '') ?>" placeholder="e.g. Deluxe Ocean Suite">
                                            </div>
                                            <div class="col-md-7">
                                                <label class="form-label" style="font-size:12px;">Subtitle</label>
                                                <input type="text" name="hero_card_subtitle[]" class="form-control form-control-sm" value="<?= htmlspecialchars($card['subtitle'] ?? '') ?>" placeholder="e.g. Karimunjawa, Indonesia">
                                            </div>
                                        </div>
                                        <input type="hidden" name="hero_card_image[]" value="<?= htmlspecialchars($card['image'] ?? '') ?>">
                                        <label class="form-label" style="font-size:12px;">Image</label>
                                        <input type="file" name="hero_card_image_file[]" class="form-control form-control-sm" accept="image/jpeg,image/jpg,image/png,image/webp">
                                        <div class="form-text">JPG/PNG/WEBP. Recommended: 400×500px portrait.</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Save Hero Cards</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ---- ROOMS ---- -->
        <div class="hero-page-panel" id="hero-panel-rooms">
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background:rgba(255,193,7,0.15);color:#ffc107;"><i class="bi bi-door-open-fill"></i></div>
                    <div>
                        <h5>Rooms Page Hero</h5><small>Banner halaman Rooms & Accommodations</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_hero">
                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-image me-1"></i>Background Image</label>
                            <?php if (!empty($webSettings['web_hero_rooms_background'])): ?>
                                <div class="current-bg-preview mb-3">
                                    <img src="<?= (strpos($webSettings['web_hero_rooms_background'], 'http') === 0) ? htmlspecialchars($webSettings['web_hero_rooms_background']) : '../' . htmlspecialchars($webSettings['web_hero_rooms_background']) ?>" style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;" alt="">
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="this.nextElementSibling.value='1';this.closest('.current-bg-preview').style.opacity='0.4';"><i class="bi bi-trash me-1"></i>Remove</button>
                                        <input type="hidden" name="remove_rooms_background" value="0">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="web_hero_rooms_background" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp">
                            <div class="form-text">Upload foto (JPG/PNG/WEBP). Recommended: 1920×1080px.<?= !empty($webSettings['web_hero_rooms_background']) ? ' Biarkan kosong untuk mempertahankan gambar saat ini.' : '' ?></div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Eyebrow</label>
                                <input type="text" name="web_hero_rooms_eyebrow" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_rooms_eyebrow']) ?>">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Title</label>
                                <input type="text" name="web_hero_rooms_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_rooms_title']) ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subtitle</label>
                            <textarea name="web_hero_rooms_subtitle" class="form-control" rows="2"><?= htmlspecialchars($webSettings['web_hero_rooms_subtitle']) ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Save Rooms Hero</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ---- ACTIVITIES ---- -->
        <div class="hero-page-panel" id="hero-panel-activities">
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background:rgba(23,162,184,0.15);color:#17a2b8;"><i class="bi bi-water"></i></div>
                    <div>
                        <h5>Activities Page Hero</h5><small>Banner halaman Activities & Experiences</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_hero">
                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-image me-1"></i>Background Image</label>
                            <?php if (!empty($webSettings['web_hero_act_background'])): ?>
                                <div class="current-bg-preview mb-3">
                                    <img src="<?= (strpos($webSettings['web_hero_act_background'], 'http') === 0) ? htmlspecialchars($webSettings['web_hero_act_background']) : '../' . htmlspecialchars($webSettings['web_hero_act_background']) ?>" style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;" alt="">
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="this.nextElementSibling.value='1';this.closest('.current-bg-preview').style.opacity='0.4';"><i class="bi bi-trash me-1"></i>Remove</button>
                                        <input type="hidden" name="remove_act_background" value="0">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="web_hero_act_background" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp">
                            <div class="form-text">Upload foto (JPG/PNG/WEBP). Recommended: 1920×1080px.<?= !empty($webSettings['web_hero_act_background']) ? ' Biarkan kosong untuk mempertahankan gambar saat ini.' : '' ?></div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Eyebrow</label>
                                <input type="text" name="web_hero_act_eyebrow" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_act_eyebrow']) ?>">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Title <small class="text-muted">(&lt;br&gt; untuk baris baru)</small></label>
                                <input type="text" name="web_hero_act_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_act_title']) ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subtitle</label>
                            <textarea name="web_hero_act_subtitle" class="form-control" rows="2"><?= htmlspecialchars($webSettings['web_hero_act_subtitle']) ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Save Activities Hero</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ---- DESTINATIONS ---- -->
        <div class="hero-page-panel" id="hero-panel-destinations">
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background:rgba(40,167,69,0.15);color:#28a745;"><i class="bi bi-geo-alt-fill"></i></div>
                    <div>
                        <h5>Destinations Page Hero</h5><small>Banner halaman Destinations</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_hero">
                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-image me-1"></i>Background Image</label>
                            <?php if (!empty($webSettings['web_hero_dest_background'])): ?>
                                <div class="current-bg-preview mb-3">
                                    <img src="<?= (strpos($webSettings['web_hero_dest_background'], 'http') === 0) ? htmlspecialchars($webSettings['web_hero_dest_background']) : '../' . htmlspecialchars($webSettings['web_hero_dest_background']) ?>" style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;" alt="">
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="this.nextElementSibling.value='1';this.closest('.current-bg-preview').style.opacity='0.4';"><i class="bi bi-trash me-1"></i>Remove</button>
                                        <input type="hidden" name="remove_dest_background" value="0">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="web_hero_dest_background" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp">
                            <div class="form-text">Upload foto (JPG/PNG/WEBP). Recommended: 1920×1080px.<?= !empty($webSettings['web_hero_dest_background']) ? ' Biarkan kosong untuk mempertahankan gambar saat ini.' : '' ?></div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Eyebrow</label>
                                <input type="text" name="web_hero_dest_eyebrow" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_dest_eyebrow']) ?>">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Title</label>
                                <input type="text" name="web_hero_dest_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_dest_title']) ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subtitle</label>
                            <textarea name="web_hero_dest_subtitle" class="form-control" rows="2"><?= htmlspecialchars($webSettings['web_hero_dest_subtitle']) ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Save Destinations Hero</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ---- CONTACT ---- -->
        <div class="hero-page-panel" id="hero-panel-contact">
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="icon" style="background:rgba(232,62,140,0.15);color:#e83e8c;"><i class="bi bi-telephone-fill"></i></div>
                    <div>
                        <h5>Contact Page Hero</h5><small>Banner halaman Contact</small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_hero">
                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-image me-1"></i>Background Image</label>
                            <?php if (!empty($webSettings['web_hero_contact_background'])): ?>
                                <div class="current-bg-preview mb-3">
                                    <img src="<?= (strpos($webSettings['web_hero_contact_background'], 'http') === 0) ? htmlspecialchars($webSettings['web_hero_contact_background']) : '../' . htmlspecialchars($webSettings['web_hero_contact_background']) ?>" style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;" alt="">
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="this.nextElementSibling.value='1';this.closest('.current-bg-preview').style.opacity='0.4';"><i class="bi bi-trash me-1"></i>Remove</button>
                                        <input type="hidden" name="remove_contact_background" value="0">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="web_hero_contact_background" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp">
                            <div class="form-text">Upload foto (JPG/PNG/WEBP). Recommended: 1920×1080px.<?= !empty($webSettings['web_hero_contact_background']) ? ' Biarkan kosong untuk mempertahankan gambar saat ini.' : '' ?></div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Eyebrow</label>
                                <input type="text" name="web_hero_contact_eyebrow" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_contact_eyebrow']) ?>">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Title</label>
                                <input type="text" name="web_hero_contact_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_contact_title']) ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subtitle</label>
                            <textarea name="web_hero_contact_subtitle" class="form-control" rows="2"><?= htmlspecialchars($webSettings['web_hero_contact_subtitle']) ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Save Contact Hero</button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <!-- ============== CONTACT TAB ============== -->
    <div class="tab-content <?= $activeTab === 'contact' ? 'active' : '' ?>" id="tab-contact">
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
    <div class="tab-content <?= $activeTab === 'rooms' ? 'active' : '' ?>" id="tab-rooms">
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

    <!-- ============== ROOM GALLERY TAB ============== -->
    <div class="tab-content <?= $activeTab === 'gallery' ? 'active' : '' ?>" id="tab-gallery">
        <?php
        $roomGalleries = [
            'king' => json_decode($webSettings['web_room_gallery_king'] ?? '[]', true) ?: [],
            'queen' => json_decode($webSettings['web_room_gallery_queen'] ?? '[]', true) ?: [],
            'twin' => json_decode($webSettings['web_room_gallery_twin'] ?? '[]', true) ?: [],
            'deluxe_king' => json_decode($webSettings['web_room_gallery_deluxe_king'] ?? '[]', true) ?: [],
            'deluxe_queen' => json_decode($webSettings['web_room_gallery_deluxe_queen'] ?? '[]', true) ?: []
        ];
        $roomPrimaries = [
            'king' => $webSettings['web_room_primary_king'] ?? '',
            'queen' => $webSettings['web_room_primary_queen'] ?? '',
            'twin' => $webSettings['web_room_primary_twin'] ?? '',
            'deluxe_king' => $webSettings['web_room_primary_deluxe_king'] ?? '',
            'deluxe_queen' => $webSettings['web_room_primary_deluxe_queen'] ?? ''
        ];
        $roomIcons = ['king' => '👑', 'queen' => '🌙', 'twin' => '🛏️', 'deluxe_king' => '🏨', 'deluxe_queen' => '🏖️'];
        $roomNames = [
            'king' => 'King Room',
            'queen' => 'Queen Room',
            'twin' => 'Twin Room',
            'deluxe_king' => 'Deluxe King Room',
            'deluxe_queen' => 'Deluxe Queen Room'
        ];
        foreach ($roomGalleries as $roomType => $gallery):
        ?>
            <div class="settings-card mb-4">
                <div class="settings-card-header">
                    <div class="icon" style="background: rgba(111,66,193,0.15); color: #6f42c1;">
                        <span style="font-size: 1.4rem;"><?= $roomIcons[$roomType] ?></span>
                    </div>
                    <div>
                        <h5><?= $roomNames[$roomType] ?> Gallery</h5>
                        <small>Upload and manage photos for <?= $roomNames[$roomType] ?></small>
                    </div>
                </div>
                <div class="settings-card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_room_gallery">
                        <input type="hidden" name="room_type" value="<?= $roomType ?>">

                        <?php
                        $currentPrimary = $roomPrimaries[$roomType] ?? '';
                        if (!empty($gallery)): ?>
                            <p class="text-muted mb-2" style="font-size:0.85rem;"><i class="bi bi-star me-1"></i>Pilih <strong>Tampilan Utama</strong> (gambar yang ditampilkan pertama di website)</p>
                            <div class="row g-3 mb-4">
                                <?php foreach ($gallery as $imgPath):
                                    $isPrimary = ($imgPath === $currentPrimary);
                                ?>
                                    <div class="col-md-3 col-6">
                                        <div class="gallery-item" style="border: 2px solid <?= $isPrimary ? '#c8a45e' : 'transparent' ?>; border-radius: 10px; padding: 6px; position: relative;">
                                            <?php if ($isPrimary): ?>
                                                <span style="position:absolute;top:10px;right:10px;background:#c8a45e;color:#fff;font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600;z-index:1;">UTAMA</span>
                                            <?php endif; ?>
                                            <img src="<?= (strpos($imgPath, 'http') === 0) ? htmlspecialchars($imgPath) : '../' . htmlspecialchars($imgPath) ?>" alt="<?= $roomNames[$roomType] ?>" style="width: 100%; height: 150px; object-fit: cover; border-radius: 8px;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top: 8px; font-size: 0.85rem;">
                                                <label class="text-primary" style="cursor:pointer;">
                                                    <input type="radio" name="primary_image" value="<?= htmlspecialchars($imgPath) ?>" <?= $isPrimary ? 'checked' : '' ?>>
                                                    <i class="bi bi-star-fill ms-1"></i> Utama
                                                </label>
                                                <label class="text-danger" style="cursor:pointer;">
                                                    <input type="checkbox" name="delete_images[]" value="<?= htmlspecialchars($imgPath) ?>">
                                                    <i class="bi bi-trash ms-1"></i> Hapus
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle me-2"></i>Belum ada gambar untuk tipe kamar ini.
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-upload me-1"></i>Upload New Images</label>
                            <input type="file" name="room_images[]" class="form-control gallery-file-input" accept="image/jpeg,image/jpg,image/png,image/webp" multiple>
                            <div class="form-text">Pilih beberapa gambar sekaligus (JPG, PNG, WEBP). Recommended: 800x600px. Max 10MB per file.</div>
                            <div class="gallery-preview" style="display:none; margin-top:10px;"></div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 gallery-submit-btn">
                            <i class="bi bi-check-lg me-1"></i>Save <?= $roomNames[$roomType] ?> Gallery
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ============== SEO TAB ============== -->
    <div class="tab-content <?= $activeTab === 'seo' ? 'active' : '' ?>" id="tab-seo">
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
    <div class="tab-content <?= $activeTab === 'appearance' ? 'active' : '' ?>" id="tab-appearance">
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
    <div class="tab-content <?= $activeTab === 'booking' ? 'active' : '' ?>" id="tab-booking">
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

    <!-- ============== DESTINATIONS TAB ============== -->
    <?php $destinations = json_decode($webSettings['web_destinations'] ?? '[]', true) ?: []; ?>
    <div class="tab-content <?= $activeTab === 'destinations' ? 'active' : '' ?>" id="tab-destinations">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(32,201,151,0.15); color: #20c997;">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
                <div>
                    <h5>Destinations & Blog</h5>
                    <small>Manage Karimunjawa destination guides shown on the website</small>
                </div>
            </div>
            <div class="settings-card-body">
                <!-- Add New Destination -->
                <div class="p-3 mb-4 rounded" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
                    <h6 class="mb-3"><i class="bi bi-plus-circle me-1"></i>Add New Destination</h6>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_destinations">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Title</label>
                                <input type="text" name="dest_title" class="form-control" placeholder="e.g. Pantai Ujung Gelam" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Subtitle</label>
                                <input type="text" name="dest_subtitle" class="form-control" placeholder="e.g. The Most Beautiful Sunset Beach">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Content / Description</label>
                            <textarea name="dest_content" class="form-control" rows="4" placeholder="Write a detailed description about this destination..."></textarea>
                            <div class="form-text">Support HTML tags for formatting. Write engaging content about the destination.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cover Image</label>
                            <input type="file" name="dest_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">Recommended: 800×500px landscape photo</div>
                        </div>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-plus-lg me-1"></i>Add Destination
                        </button>
                    </form>
                </div>

                <?php if (empty($destinations)): ?>
                    <div class="text-center p-4" style="color: #999;">
                        <i class="bi bi-geo-alt" style="font-size: 48px; opacity: 0.3;"></i>
                        <p class="mt-2">No destinations added yet. Add your first Karimunjawa destination guide above.</p>
                    </div>
                <?php else: ?>
                    <!-- Existing Destinations -->
                    <h6 class="mb-3"><i class="bi bi-list-ul me-1"></i>Current Destinations (<?= count($destinations) ?>)</h6>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_destinations">
                        <?php foreach ($destinations as $di => $dest): ?>
                            <div class="p-3 mb-3 rounded" style="background: #f8f9fa; border: 1px solid #dee2e6;">
                                <div class="d-flex align-items-start gap-3">
                                    <?php if (!empty($dest['image'])): ?>
                                        <div style="width: 120px; min-width: 120px; height: 80px; border-radius: 8px; overflow: hidden;">
                                            <img src="<?= (strpos($dest['image'], 'http') === 0) ? htmlspecialchars($dest['image']) : '../' . htmlspecialchars($dest['image']) ?>" style="width: 100%; height: 100%; object-fit: cover;" alt="">
                                        </div>
                                    <?php endif; ?>
                                    <div style="flex: 1;">
                                        <input type="hidden" name="dest_update_id[]" value="<?= htmlspecialchars($dest['id']) ?>">
                                        <div class="row mb-2">
                                            <div class="col-md-5">
                                                <input type="text" name="dest_update_title[]" class="form-control form-control-sm" value="<?= htmlspecialchars($dest['title']) ?>" placeholder="Title">
                                            </div>
                                            <div class="col-md-4">
                                                <input type="text" name="dest_update_subtitle[]" class="form-control form-control-sm" value="<?= htmlspecialchars($dest['subtitle'] ?? '') ?>" placeholder="Subtitle">
                                            </div>
                                            <div class="col-md-2">
                                                <input type="number" name="dest_update_order[]" class="form-control form-control-sm" value="<?= (int)($dest['order'] ?? $di + 1) ?>" min="1" title="Display order">
                                            </div>
                                            <div class="col-md-1 d-flex align-items-center">
                                                <label title="Active" style="cursor: pointer;">
                                                    <input type="checkbox" name="dest_update_active[]" value="<?= htmlspecialchars($dest['id']) ?>" <?= ($dest['active'] ?? true) ? 'checked' : '' ?>>
                                                    <i class="bi bi-eye ms-1"></i>
                                                </label>
                                            </div>
                                        </div>
                                        <textarea name="dest_update_content[]" class="form-control form-control-sm mb-2" rows="2" placeholder="Description"><?= htmlspecialchars($dest['content'] ?? '') ?></textarea>
                                        <div class="d-flex align-items-center gap-2">
                                            <input type="file" name="dest_update_image_<?= htmlspecialchars($dest['id']) ?>" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" style="max-width: 250px;">
                                            <label style="font-size: 12px; color: #dc3545; cursor: pointer; white-space: nowrap;">
                                                <input type="checkbox" name="delete_dest[]" value="<?= htmlspecialchars($dest['id']) ?>" style="margin-right: 3px;">
                                                <i class="bi bi-trash"></i> Delete
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check-lg me-1"></i>Save All Destinations
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============== ACTIVITIES TAB ============== -->
    <?php
    $activitiesList = json_decode($webSettings['web_activities'] ?? '[]', true) ?: [];

    // Auto-seed default activities if empty
    if (empty($activitiesList)) {
        $activitiesList = [
            ['id' => 'snorkeling', 'eyebrow' => 'Underwater World', 'title' => 'Snorkelling in Crystal-Clear Waters', 'image' => 'https://images.unsplash.com/photo-1534258936925-c58bed479fcb?w=900&q=80', 'body' => 'Karimunjawa\'s waters are among the clearest in the entire Java Sea, with visibility regularly reaching <strong>15 to 20 metres</strong> on calm days. Just a short boat ride from Narayana, you\'ll be floating above vibrant coral gardens teeming with life — clownfish darting between anemones, parrotfish grazing on coral, giant clams wedged into reef crevices, and green sea turtles gliding through the blue. The archipelago\'s most celebrated snorkelling spots include <strong>Menjangan Kecil</strong>, <strong>Gosong Cemara</strong>, and <strong>Taka Menyawakan</strong>. The marine biodiversity here is extraordinary — over 240 species of coral and more than 240 species of reef fish have been recorded within Karimunjawa National Marine Park.', 'details' => ['Best time: 7:00–11:00 (calmest water, best visibility)', 'Top spots: Menjangan Kecil, Gosong Cemara, Taka Menyawakan', 'Distance from hotel: 10–30 min by boat', 'Suitable for all ages and skill levels', 'Equipment rental available on most boat trips', 'Over 240 coral species and 240+ fish species recorded'], 'order' => 1, 'active' => true],
            ['id' => 'island-hopping', 'eyebrow' => 'Island Exploration', 'title' => 'Hopping Between 27 Islands', 'image' => 'https://images.unsplash.com/photo-1559128010-7c1ad6e1b6a5?w=900&q=80', 'body' => 'The Karimunjawa archipelago comprises <strong>27 islands</strong>, and only five of them are inhabited — the rest remain wild, forested, and fringed by powdery white sand. An island-hopping trip is the quintessential Karimunjawa experience. <strong>Cemara Kecil</strong> is famous for its iconic leaning coconut palm. <strong>Menjangan Besar</strong> has a hilltop viewpoint and a shark conservation area. Most tours last a full day and include stops at 3–5 islands, a freshly grilled seafood lunch on the beach, and plenty of time to snorkel, swim, or simply sit on a deserted shore.', 'details' => ['Full-day excursion (08:00–16:00)', 'Visit 3–5 islands per trip', 'Popular stops: Cemara Kecil, Cemara Besar, Menjangan Besar, Geleang', 'Grilled seafood lunch included on most trips', 'Customisable routes available for private charters', 'Bring sunscreen, hat, and waterproof bag'], 'order' => 2, 'active' => true],
            ['id' => 'diving', 'eyebrow' => 'Deep Exploration', 'title' => 'Scuba Diving the Marine National Park', 'image' => 'https://images.unsplash.com/photo-1682687220742-aba13b6e50ba?w=900&q=80', 'body' => 'Karimunjawa has been a <strong>National Marine Park</strong> since 2001. Below the surface you\'ll find towering sea fans, dense staghorn coral, barrel sponges, and astonishing marine life. Divers regularly encounter <strong>blacktip and whitetip reef sharks</strong>, Napoleon wrasse, schools of barracuda, and on lucky days — <strong>whale sharks</strong>. Top sites include wall dives at <strong>Taka Malang</strong>, the wreck at <strong>Kapal Tenggelam</strong> (patrol vessel at 25m), and the coral gardens of <strong>Pulau Sintok</strong>.', 'details' => ['Over 30 mapped dive sites', 'Depth range: 5–40+ metres', 'Marine life: reef sharks, Napoleon wrasse, sea turtles, whale sharks (seasonal)', 'Top sites: Taka Malang, Kapal Tenggelam wreck, Pulau Sintok', 'PADI courses: DSD, Open Water, Advanced', 'Best visibility: April–September', 'Water temperature: 27–30°C year-round'], 'order' => 3, 'active' => true],
            ['id' => 'shark-encounter', 'eyebrow' => 'Wildlife Encounter', 'title' => 'Swimming with Sharks & Rays', 'image' => 'https://images.unsplash.com/photo-1560275619-4662e36fa65c?w=900&q=80', 'body' => 'At <strong>Menjangan Besar Island</strong>, a natural ocean enclosure allows visitors to swim alongside <strong>blacktip reef sharks and giant stingrays</strong>. This community-run conservation initiative has local fishermen partnering with the national park to protect juvenile sharks. You wade into chest-deep water while reef sharks circle with calm, elegant movements. The experience is both exhilarating and surprisingly peaceful. Guides are always present, making it safe even for children.', 'details' => ['Location: Menjangan Besar Island (15 min by boat)', 'Species: Blacktip reef sharks & giant stingrays', 'Safe for children under adult supervision', 'Community-run conservation initiative', 'Entrance fee supports local shark protection', 'Waterproof camera highly recommended'], 'order' => 4, 'active' => true],
            ['id' => 'sunset', 'eyebrow' => 'Golden Hour', 'title' => 'Watching the Sunset from Bukit Love', 'image' => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=900&q=80', 'body' => 'Karimunjawa faces west across the open Java Sea — <strong>the sunsets are extraordinary</strong>. The most famous viewpoint is <strong>Bukit Love</strong> (Love Hill) with its heart-shaped sign framing the horizon. The walk up takes about 10 minutes. Another popular spot is <strong>Legon Lele</strong> beach. For something special, consider a <strong>sunset boat cruise</strong> on a traditional wooden boat.', 'details' => ['Bukit Love: 10 min walk/ride from most hotels', 'Sunset time: ~17:15–17:45 depending on season', 'Alternative spots: Legon Lele beach, Sunset Pier, boat cruise', 'Best from April–October (dry season)', 'Free access to all hilltop viewpoints'], 'order' => 5, 'active' => true],
            ['id' => 'mangrove', 'eyebrow' => 'Nature & Conservation', 'title' => 'Exploring the Mangrove Forest Trail', 'image' => 'https://images.unsplash.com/photo-1504681869696-d977211a5f4c?w=900&q=80', 'body' => 'The <strong>Mangrove Tracking Area</strong> near Kemujan features a well-maintained wooden boardwalk through dense mangrove forest. The canopy is alive with <strong>kingfishers</strong>, <strong>white-bellied sea eagles</strong>, and the rare mangrove pitta. For a more immersive experience, explore the channels <strong>by kayak</strong>, paddling silently through shaded waterways. 15+ mangrove species have been identified in the park.', 'details' => ['Location: Mangrove Tracking Area, near Kemujan', 'Duration: 1–2 hours (walk) or 2–3 hours (kayak)', 'Wooden boardwalk suitable for all fitness levels', 'Best visited early morning (cooler, more wildlife)', 'Kayak rentals available on-site', 'Birdlife: kingfishers, sea eagles, herons, mangrove pittas'], 'order' => 6, 'active' => true],
            ['id' => 'motorbike', 'eyebrow' => 'Freedom to Roam', 'title' => 'Exploring the Island by Motorbike', 'image' => 'https://images.unsplash.com/photo-1558981806-ec527fa84c39?w=900&q=80', 'body' => 'The best way to discover Karimunjawa at your own pace is on a <strong>motorbike</strong>. Ride through <strong>traditional fishing villages</strong>, stop at a roadside <strong>warung</strong> for grilled fish and sambal, or follow a dirt track to a hidden viewpoint. The <strong>west coast</strong> road passes a string of secluded beaches. The <strong>eastern road to Kemujan</strong> crosses a narrow bridge between islands through mangrove-lined channels.', 'details' => ['Scooter rental: ~IDR 75,000–100,000 per day', 'Main roads paved and well-maintained', 'Helmets provided with rental', 'Recommended: West coast beach road + Kemujan bridge', 'Full island circuit: ~2–3 hours with stops'], 'order' => 7, 'active' => true],
            ['id' => 'fishing', 'eyebrow' => 'Ocean Tradition', 'title' => 'Traditional & Sport Fishing', 'image' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=900&q=80', 'body' => 'Join a <strong>traditional fishing trip</strong> on a wooden boat alongside experienced local fishermen. The waters are rich with <strong>snapper, grouper, trevally, barracuda, and tuna</strong>. For something exciting, try <strong>night fishing (mancing malam)</strong>: head out after sunset, fish by lamplight under the Milky Way while the crew grills your earlier catch over charcoal on the back of the boat.', 'details' => ['Traditional fishing: half-day trips from harbour', 'Species: snapper, grouper, trevally, barracuda, tuna, GT', 'Night fishing: depart ~18:00, return by 22:00', 'No experience necessary — local guides teach you', 'Catch can be grilled on the boat', 'Best fishing months: March–October'], 'order' => 8, 'active' => true],
            ['id' => 'kayaking', 'eyebrow' => 'Coastal Adventure', 'title' => 'Kayaking & Stand-Up Paddleboarding', 'image' => 'https://images.unsplash.com/photo-1472745433479-4556f22e32c2?w=900&q=80', 'body' => 'Karimunjawa\'s calm, sheltered bays are ideal for <strong>kayaking and SUP</strong>. Paddle along the coastline over coral gardens visible through transparent water. The <strong>mangrove channels</strong> on the eastern side offer a quiet, meditative journey. For SUP, the <strong>shallow lagoon near Cemara Kecil</strong> is a dream — paddle over white sand and turquoise water so clear it feels like floating on air.', 'details' => ['Best conditions: morning before 11:00', 'Kayak rental: ~IDR 50,000–75,000/hour', 'SUP rental: ~IDR 75,000–100,000/hour', 'Top routes: mangrove channels, Cemara Kecil lagoon', 'No experience needed for calm-water paddling', 'Suitable for ages 8 and up'], 'order' => 9, 'active' => true],
            ['id' => 'trekking', 'eyebrow' => 'Highland Adventure', 'title' => 'Jungle Trekking & Hill Walks', 'image' => 'https://images.unsplash.com/photo-1551632811-561732d1e306?w=900&q=80', 'body' => 'The interior of the main island is covered in dense <strong>tropical lowland forest</strong>. The <strong>Bukit Gajah trail</strong> passes the rare <em>Dewadaru</em> tree (sacred, found almost nowhere else) and endemic <strong>Karimunjawa white-eye</strong> bird (critically endangered). The summit offers a <strong>360-degree panorama</strong> over the entire archipelago.', 'details' => ['Trail options: 30 min easy to 3–4 hour challenging', 'Highlights: Dewadaru sacred tree, endemic bird species', 'Bukit Gajah: moderate difficulty, panoramic summit views', 'Guides available through National Park office', 'Bring: water, closed shoes, insect repellent'], 'order' => 10, 'active' => true],
            ['id' => 'beach-camping', 'eyebrow' => 'Under the Stars', 'title' => 'Camping on Uninhabited Islands', 'image' => 'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?w=900&q=80', 'body' => 'Spend a night <strong>camping on an uninhabited island</strong>. Popular islands include <strong>Cemara Besar</strong> and <strong>Geleang</strong>. Local operators arrange everything: boat transfer, tents, sleeping mats, and a crew who grills fresh fish over an open fire. The <strong>Milky Way</strong> arcs overhead with astonishing clarity — zero light pollution.', 'details' => ['Popular islands: Cemara Besar, Geleang, Menjangan Kecil', 'Duration: overnight or extended (2 nights)', 'All equipment provided: tents, mats, cooking gear', 'Fresh seafood dinner by local crew', 'Zero light pollution — exceptional stargazing', 'Best during dry season: April–October'], 'order' => 11, 'active' => true],
            ['id' => 'village-culture', 'eyebrow' => 'Local Heritage', 'title' => 'Village Tours & Local Culture', 'image' => 'https://images.unsplash.com/photo-1590523741831-ab7e8b8f9c7f?w=900&q=80', 'body' => 'Visit <strong>Karimunjawa Village</strong> — walk through narrow lanes past traditional wooden houses, visit the <strong>historic cemetery of Sunan Nyamplungan</strong> (15th century Islamic missionary), and watch traditional boatbuilders at work. The <strong>Bugis community in Kemujan</strong> maintains distinct cultural traditions. Try local cuisine: <strong>nasi goreng ikan</strong>, <strong>sate kelapa</strong>, and <strong>bakso ikan</strong>.', 'details' => ['Karimunjawa Village: walking distance from harbour', 'Sunan Nyamplungan: historic Islamic heritage site', 'Bugis community in Kemujan', 'Traditional boatbuilding at main harbour', 'Local cuisine: nasi goreng ikan, sate kelapa, bakso ikan', 'Best with a local guide (arranged via hotel)'], 'order' => 12, 'active' => true],
        ];
        // Save seed to database
        try {
            $seedJson = json_encode($activitiesList);
            $stmt = $webDb->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                        VALUES ('web_activities', ?, 'text', 'Website Activities') 
                        ON DUPLICATE KEY UPDATE setting_value = CASE WHEN setting_value = '[]' OR setting_value = '' OR setting_value IS NULL THEN VALUES(setting_value) ELSE setting_value END");
            $stmt->execute([$seedJson]);
            $webSettings['web_activities'] = $seedJson;
        } catch (Exception $e) { /* silently fail */
        }
    }
    ?>
    <div class="tab-content <?= $activeTab === 'activities' ? 'active' : '' ?>" id="tab-activities">

        <!-- Activities Hero Section -->
        <div class="settings-card mb-4">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(23,162,184,0.15); color: #17a2b8;">
                    <i class="bi bi-image-fill"></i>
                </div>
                <div>
                    <h5>Activities Page — Hero Banner</h5>
                    <small>Background image, title, and subtitle for the Activities hero section</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_hero">

                    <!-- Current Hero Preview -->
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="bi bi-image me-1"></i>Hero Background Image</label>
                        <?php if (!empty($webSettings['web_hero_act_background'])): ?>
                            <div class="current-bg-preview mb-2" style="position: relative; border-radius: 12px; overflow: hidden;">
                                <img src="<?= (strpos($webSettings['web_hero_act_background'], 'http') === 0) ? htmlspecialchars($webSettings['web_hero_act_background']) : '../' . htmlspecialchars($webSettings['web_hero_act_background']) ?>"
                                    style="width:100%; max-height:220px; object-fit:cover; border-radius:12px;" alt="Activities Hero">
                                <div style="position:absolute; bottom:10px; right:10px;">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.current-bg-preview').querySelector('.rm-input').value='1';this.closest('.current-bg-preview').style.opacity='0.3';">
                                        <i class="bi bi-trash me-1"></i>Remove
                                    </button>
                                    <input type="hidden" class="rm-input" name="remove_act_background" value="0">
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="mb-2 p-4 text-center rounded" style="border: 2px dashed #dee2e6; color: #adb5bd;">
                                <i class="bi bi-image" style="font-size: 2.5rem;"></i>
                                <p class="mt-2 mb-0" style="font-size: 0.85rem;">No hero background set — upload one below</p>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="web_hero_act_background" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp">
                        <div class="form-text">Upload: JPG/PNG/WEBP, recommended 1920×1080px.<?= !empty($webSettings['web_hero_act_background']) ? ' Leave empty to keep current image.' : '' ?></div>
                    </div>

                    <!-- Hero Text -->
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Eyebrow</label>
                            <input type="text" name="web_hero_act_eyebrow" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_act_eyebrow']) ?>" placeholder="e.g. Karimunjawa Islands">
                        </div>
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Title <small class="text-muted">(use &lt;br&gt; for line break, &lt;em&gt; for italic)</small></label>
                            <input type="text" name="web_hero_act_title" class="form-control" value="<?= htmlspecialchars($webSettings['web_hero_act_title']) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subtitle</label>
                        <textarea name="web_hero_act_subtitle" class="form-control" rows="2"><?= htmlspecialchars($webSettings['web_hero_act_subtitle']) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-info text-white w-100">
                        <i class="bi bi-check-lg me-1"></i>Save Hero Banner
                    </button>
                </form>
            </div>
        </div>

        <!-- Activities Management -->
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(13,110,253,0.15); color: #0d6efd;">
                    <i class="bi bi-compass-fill"></i>
                </div>
                <div>
                    <h5>Activities & Experiences</h5>
                    <small>Manage activities shown on the website's Activities page</small>
                </div>
            </div>
            <div class="settings-card-body">
                <!-- Add New Activity -->
                <div class="p-3 mb-4 rounded" style="background: #eff6ff; border: 1px solid #bfdbfe;">
                    <h6 class="mb-3"><i class="bi bi-plus-circle me-1"></i>Add New Activity</h6>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_activities">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" name="act_title" class="form-control" placeholder="e.g. Snorkelling in Crystal Waters" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Eyebrow / Category</label>
                                <input type="text" name="act_eyebrow" class="form-control" placeholder="e.g. Underwater World">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Body / Description</label>
                            <textarea name="act_body" class="form-control" rows="4" placeholder="Write a detailed description about this activity... HTML tags allowed."></textarea>
                            <div class="form-text">Supports HTML tags for formatting (e.g. &lt;strong&gt;, &lt;a&gt;, &lt;em&gt;)</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Details (one per line)</label>
                            <textarea name="act_details" class="form-control" rows="3" placeholder="Best time: 7am–11am&#10;Distance from hotel: ~15 min&#10;Suitable for all ages"></textarea>
                            <div class="form-text">Each line becomes a bullet point on the website</div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Image URL</label>
                                <input type="text" name="act_image" class="form-control" placeholder="https://images.unsplash.com/...">
                                <div class="form-text">Paste an external image URL, or upload below</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Or Upload Image</label>
                                <input type="file" name="act_image_file" class="form-control" accept="image/jpeg,image/png,image/webp">
                                <div class="form-text">Recommended: 900×600px landscape photo</div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg me-1"></i>Add Activity
                        </button>
                    </form>
                </div>

                <?php if (empty($activitiesList)): ?>
                    <div class="text-center p-4" style="color: #999;">
                        <i class="bi bi-compass" style="font-size: 48px; opacity: 0.3;"></i>
                        <p class="mt-2">No activities added yet. Add your first activity above.</p>
                    </div>
                <?php else: ?>
                    <!-- Existing Activities -->
                    <h6 class="mb-3"><i class="bi bi-list-ul me-1"></i>Current Activities (<?= count($activitiesList) ?>)</h6>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_activities">
                        <?php foreach ($activitiesList as $ai => $act): ?>
                            <div class="p-3 mb-3 rounded" style="background: #f8f9fa; border: 1px solid #dee2e6;">
                                <input type="hidden" name="act_update_id[]" value="<?= htmlspecialchars($act['id']) ?>">

                                <!-- Header: Number + Title + Order + Active -->
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="badge bg-primary" style="font-size: 0.9rem; padding: 6px 10px;"><?= str_pad($ai + 1, 2, '0', STR_PAD_LEFT) ?></span>
                                    <input type="text" name="act_update_title[]" class="form-control form-control-sm fw-bold" value="<?= htmlspecialchars($act['title']) ?>" placeholder="Activity Title" style="flex: 1;">
                                    <input type="text" name="act_update_eyebrow[]" class="form-control form-control-sm" value="<?= htmlspecialchars($act['eyebrow'] ?? '') ?>" placeholder="Category" style="max-width: 160px;">
                                    <input type="number" name="act_update_order[]" class="form-control form-control-sm text-center" value="<?= (int)($act['order'] ?? $ai + 1) ?>" min="1" title="Display order" style="max-width: 60px;">
                                    <label title="Active" style="cursor: pointer; white-space: nowrap;">
                                        <input type="checkbox" name="act_update_active[]" value="<?= htmlspecialchars($act['id']) ?>" <?= ($act['active'] ?? true) ? 'checked' : '' ?>>
                                        <i class="bi bi-eye ms-1"></i>
                                    </label>
                                </div>

                                <!-- Image Section -->
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label" style="font-size: 0.8rem; font-weight: 600;"><i class="bi bi-image me-1"></i>Current Image</label>
                                        <?php if (!empty($act['image'])): ?>
                                            <div style="width: 100%; height: 160px; border-radius: 10px; overflow: hidden; border: 2px solid #dee2e6; margin-bottom: 8px;">
                                                <img src="<?= (strpos($act['image'], 'http') === 0) ? htmlspecialchars($act['image']) : '../' . htmlspecialchars($act['image']) ?>" style="width: 100%; height: 100%; object-fit: cover;" alt="<?= htmlspecialchars($act['title']) ?>">
                                            </div>
                                        <?php else: ?>
                                            <div style="width: 100%; height: 160px; border-radius: 10px; border: 2px dashed #dee2e6; display: flex; align-items: center; justify-content: center; color: #adb5bd; margin-bottom: 8px;">
                                                <div class="text-center">
                                                    <i class="bi bi-image" style="font-size: 2rem;"></i>
                                                    <div style="font-size: 0.75rem;">No image</div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label" style="font-size: 0.8rem; font-weight: 600;"><i class="bi bi-pencil-square me-1"></i>Change Image</label>
                                        <div class="mb-2">
                                            <input type="text" name="act_update_image[]" class="form-control form-control-sm" value="<?= htmlspecialchars($act['image'] ?? '') ?>" placeholder="Image URL (e.g. https://images.unsplash.com/...)">
                                            <div class="form-text">Paste any image URL — or upload a file below</div>
                                        </div>
                                        <div class="mb-2">
                                            <input type="file" name="act_update_image_file_<?= htmlspecialchars($act['id']) ?>" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp">
                                            <div class="form-text">Upload replaces the URL above · Recommended: 900×600px landscape</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Body -->
                                <div class="mb-2">
                                    <label class="form-label" style="font-size: 0.8rem; font-weight: 600;"><i class="bi bi-text-paragraph me-1"></i>Description</label>
                                    <textarea name="act_update_body[]" class="form-control form-control-sm" rows="3" placeholder="Body / Description (HTML allowed)"><?= htmlspecialchars($act['body'] ?? '') ?></textarea>
                                </div>

                                <!-- Details -->
                                <div class="mb-2">
                                    <label class="form-label" style="font-size: 0.8rem; font-weight: 600;"><i class="bi bi-list-check me-1"></i>Details (one per line)</label>
                                    <textarea name="act_update_details[]" class="form-control form-control-sm" rows="3" placeholder="Details (one per line)"><?= htmlspecialchars(implode("\n", $act['details'] ?? [])) ?></textarea>
                                </div>

                                <!-- Delete -->
                                <div class="text-end">
                                    <label style="font-size: 12px; color: #dc3545; cursor: pointer;">
                                        <input type="checkbox" name="delete_act[]" value="<?= htmlspecialchars($act['id']) ?>" style="margin-right: 3px;">
                                        <i class="bi bi-trash"></i> Delete this activity
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check-lg me-1"></i>Save All Activities
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============== AI AGENT TAB ============== -->
    <div class="tab-content <?= $activeTab === 'agent' ? 'active' : '' ?>" id="tab-agent">
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background:rgba(111,66,193,0.15);color:#6f42c1;"><i class="bi bi-robot"></i></div>
                <div>
                    <h5>AI Agent — n8n Integration</h5><small>Konfigurasi API untuk WhatsApp AI agent via n8n</small>
                </div>
            </div>
            <div class="settings-card-body">

                <!-- API Key -->
                <div class="mb-4 p-3" style="background:#f8f9fa;border-radius:10px;border:1.5px solid #e9ecef;">
                    <label class="form-label fw-bold"><i class="bi bi-key me-1"></i>API Key</label>
                    <?php if (!empty($webSettings['agent_api_key'])): ?>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control font-monospace" value="<?= htmlspecialchars($webSettings['agent_api_key']) ?>" id="agentKeyDisplay" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('agentKeyDisplay').value);this.innerHTML='<i class=\'bi bi-check\'>  </i>Copied';setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i> Copy',2000);"><i class="bi bi-clipboard"></i> Copy</button>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Belum ada API key. Generate key terlebih dahulu.</div>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_agent">
                        <input type="hidden" name="regenerate_key" value="1">
                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirm('Generate API key baru? Key lama tidak bisa dipakai lagi.')">
                            <i class="bi bi-arrow-clockwise me-1"></i><?= empty($webSettings['agent_api_key']) ? 'Generate API Key' : 'Regenerate Key' ?>
                        </button>
                    </form>
                </div>

                <!-- Endpoints Info -->
                <?php
                $baseUrl = ($isProduction ? 'https://adfsystem.online' : 'http://localhost/narayanakarimunjawa/adf_system') . '/api/agent';
                ?>
                <div class="mb-4">
                    <label class="form-label fw-bold"><i class="bi bi-plug me-1"></i>Endpoint URL untuk n8n Tools</label>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered" style="font-size:0.82rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Tool Name</th>
                                    <th>Method</th>
                                    <th>URL</th>
                                    <th>Fungsi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>hotel_context</code></td>
                                    <td><span class="badge bg-primary">GET</span></td>
                                    <td><code><?= $baseUrl ?>/hotel-context.php</code></td>
                                    <td>Info hotel, harga kamar, jam CI/CO</td>
                                </tr>
                                <tr>
                                    <td><code>check_availability</code></td>
                                    <td><span class="badge bg-primary">GET</span></td>
                                    <td><code><?= $baseUrl ?>/check-availability.php</code></td>
                                    <td>Cek kamar tersedia by tanggal</td>
                                </tr>
                                <tr>
                                    <td><code>get_booking</code></td>
                                    <td><span class="badge bg-primary">GET</span></td>
                                    <td><code><?= $baseUrl ?>/get-booking.php</code></td>
                                    <td>Status booking by kode/HP tamu</td>
                                </tr>
                                <tr>
                                    <td><code>create_booking</code></td>
                                    <td><span class="badge bg-success">POST</span></td>
                                    <td><code><?= $baseUrl ?>/create-booking.php</code></td>
                                    <td>Buat reservasi baru</td>
                                </tr>
                                <tr>
                                    <td><code>notify_frontdesk</code></td>
                                    <td><span class="badge bg-success">POST</span></td>
                                    <td><code><?= $baseUrl ?>/notify-frontdesk.php</code></td>
                                    <td>Kirim notif ke dashboard</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info mt-2" style="font-size:0.82rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        Semua request wajib sertakan header: <code>X-Agent-Key: &lt;api_key&gt;</code>
                    </div>
                </div>

                <!-- System Prompt & Toggle -->
                <form method="POST">
                    <input type="hidden" name="action" value="save_agent">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><i class="bi bi-chat-text me-1"></i>System Prompt</label>
                        <textarea name="agent_system_prompt" class="form-control font-monospace" rows="8" placeholder="Contoh: Kamu adalah asisten hotel Narayana Karimunjawa. Jawab pertanyaan tamu dengan ramah dalam Bahasa Indonesia. Jangan pernah menjanjikan hal yang tidak ada di data hotel..."><?= htmlspecialchars($webSettings['agent_system_prompt']) ?></textarea>
                        <div class="form-text">Prompt ini bisa di-copy ke n8n AI Agent node sebagai System Message.</div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="agent_enabled" value="1" id="agentEnabled" <?= $webSettings['agent_enabled'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="agentEnabled">Agent Aktif</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Save AI Agent Settings</button>
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

    // Hero per-page sub-tab switching
    document.querySelectorAll('.hero-page-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.hero-page-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.hero-page-panel').forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('hero-panel-' + this.dataset.page).classList.add('active');
        });
    });

    // Home hero live preview
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

    // Remove background image handler
    function removeBackground() {
        if (confirm('Are you sure you want to remove the hero background image?')) {
            document.getElementById('removeBackgroundInput').value = '1';
            // Find the hero form (the one with action=save_hero)
            document.querySelector('input[name="action"][value="save_hero"]').closest('form').submit();
        }
    }

    // ============ GALLERY UPLOAD LOADING & PREVIEW ============

    // Loading overlay with progress
    const overlay = document.createElement('div');
    overlay.id = 'uploadOverlay';
    overlay.innerHTML = `
    <div style="background:rgba(0,0,0,0.8);position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:16px;padding:20px;">
        <div style="width:50px;height:50px;border:4px solid rgba(255,255,255,0.3);border-top:4px solid #c8a45e;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
        <div style="color:#fff;font-size:16px;font-weight:500;" id="uploadText">Uploading images...</div>
        <div style="width:280px;height:6px;background:rgba(255,255,255,0.2);border-radius:3px;overflow:hidden;">
            <div id="uploadProgress" style="height:100%;background:#c8a45e;width:0%;transition:width 0.3s;border-radius:3px;"></div>
        </div>
        <div style="color:rgba(255,255,255,0.6);font-size:13px;" id="uploadPercent">0%</div>
        <div style="color:rgba(255,255,255,0.4);font-size:12px;">Jangan tutup halaman ini</div>
    </div>
`;
    overlay.style.display = 'none';
    document.body.appendChild(overlay);

    // Spinner + preview CSS
    const spinStyle = document.createElement('style');
    spinStyle.textContent = `
    @keyframes spin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
    .gallery-preview{display:flex;flex-wrap:wrap;gap:8px;padding:10px;background:#f8f9fa;border-radius:8px;}
    .gallery-preview img{width:80px;height:60px;object-fit:cover;border-radius:6px;border:2px solid #e0e0e0;}
    .upload-result{padding:12px;border-radius:8px;margin-top:10px;font-size:14px;}
    .upload-result.success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
    .upload-result.error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
`;
    document.head.appendChild(spinStyle);

    // File preview on select
    document.querySelectorAll('.gallery-file-input').forEach(input => {
        input.addEventListener('change', function() {
            const preview = this.closest('.mb-3').querySelector('.gallery-preview');
            preview.innerHTML = '';
            if (this.files.length > 0) {
                preview.style.display = 'flex';
                const label = document.createElement('div');
                label.style.cssText = 'width:100%;font-size:13px;color:#666;margin-bottom:4px;';
                label.innerHTML = '<i class="bi bi-images"></i> <strong>' + this.files.length + ' file</strong> dipilih:';
                preview.appendChild(label);

                let totalSize = 0;
                Array.from(this.files).forEach(file => {
                    totalSize += file.size;
                    if (file.type.startsWith('image/')) {
                        const img = document.createElement('img');
                        img.src = URL.createObjectURL(file);
                        img.title = file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + 'MB)';
                        preview.appendChild(img);
                    }
                });
                const sizeLabel = document.createElement('div');
                sizeLabel.style.cssText = 'width:100%;font-size:12px;color:#999;';
                sizeLabel.textContent = 'Total: ' + (totalSize / 1024 / 1024).toFixed(1) + 'MB';
                preview.appendChild(sizeLabel);
            } else {
                preview.style.display = 'none';
            }
        });
    });

    // AJAX upload with progress
    document.querySelectorAll('.gallery-submit-btn').forEach(btn => {
        btn.closest('form').addEventListener('submit', function(e) {
            e.preventDefault();

            const form = this;
            const fileInput = form.querySelector('.gallery-file-input');
            const hasFiles = fileInput && fileInput.files.length > 0;
            const hasDelete = form.querySelectorAll('input[name="delete_images[]"]:checked').length > 0;
            const hasPrimary = form.querySelector('input[name="primary_image"]:checked');

            if (!hasFiles && !hasDelete && !hasPrimary) {
                alert('Pilih file untuk upload, atau pilih gambar untuk dihapus.');
                return;
            }

            // Show overlay
            overlay.style.display = 'block';
            const uploadText = document.getElementById('uploadText');
            const uploadProgress = document.getElementById('uploadProgress');
            const uploadPercent = document.getElementById('uploadPercent');

            if (hasFiles) {
                uploadText.textContent = 'Uploading ' + fileInput.files.length + ' image(s)...';
            } else {
                uploadText.textContent = 'Processing...';
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading...';

            // Send via AJAX
            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    uploadProgress.style.width = pct + '%';
                    uploadPercent.textContent = pct + '%';
                    if (pct >= 100) {
                        uploadText.textContent = 'Processing on server...';
                    }
                }
            });

            xhr.addEventListener('load', function() {
                overlay.style.display = 'none';
                uploadProgress.style.width = '0%';
                uploadPercent.textContent = '0%';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Gallery';

                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            // Show inline success before reload
                            const result = document.createElement('div');
                            result.className = 'upload-result success';
                            result.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + (response.message || 'Gallery updated!');
                            form.appendChild(result);
                            // Reload to gallery tab after brief delay
                            setTimeout(() => {
                                window.location.href = window.location.pathname + '?tab=gallery';
                            }, 800);
                        } else {
                            const result = document.createElement('div');
                            result.className = 'upload-result error';
                            result.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + (response.message || response.error || 'Upload gagal.');
                            form.appendChild(result);
                            setTimeout(() => result.remove(), 8000);
                        }
                    } catch (e) {
                        // Non-JSON response (server error or redirect page HTML)
                        console.error('Upload response parse error:', e, xhr.responseText.substring(0, 200));
                        const result = document.createElement('div');
                        result.className = 'upload-result error';
                        result.innerHTML = '<i class="bi bi-x-circle me-1"></i>Server response tidak valid. Coba refresh halaman dan cek apakah gambar tersimpan.';
                        form.appendChild(result);
                        setTimeout(() => result.remove(), 8000);
                    }
                } else {
                    const result = document.createElement('div');
                    result.className = 'upload-result error';
                    result.innerHTML = '<i class="bi bi-x-circle me-1"></i>Upload gagal (HTTP ' + xhr.status + '). Coba lagi.';
                    form.appendChild(result);
                    setTimeout(() => result.remove(), 5000);
                }
            });

            xhr.addEventListener('error', function() {
                overlay.style.display = 'none';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Gallery';

                const result = document.createElement('div');
                result.className = 'upload-result error';
                result.innerHTML = '<i class="bi bi-x-circle me-1"></i>Koneksi gagal. Periksa internet dan coba lagi.';
                form.appendChild(result);
                setTimeout(() => result.remove(), 5000);
            });

            xhr.addEventListener('timeout', function() {
                overlay.style.display = 'none';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Gallery';

                const result = document.createElement('div');
                result.className = 'upload-result error';
                result.innerHTML = '<i class="bi bi-x-circle me-1"></i>Timeout. Coba upload lebih sedikit gambar.';
                form.appendChild(result);
                setTimeout(() => result.remove(), 5000);
            });

            xhr.open('POST', window.location.pathname);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.timeout = 300000; // 5 menit timeout
            xhr.send(formData);
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>