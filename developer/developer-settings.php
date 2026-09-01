<?php

/**
 * Developer Panel - Developer Settings
 * Configure developer name, logo, login background, WhatsApp, footer text
 */

require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/functions.php';
require_once dirname(dirname(__FILE__)) . '/includes/CloudinaryHelper.php';

$devAuth = new DevAuth();
$devAuth->requireLogin();

$db = Database::getInstance();
$pageTitle = 'Developer Settings';
$currentPage = 'developer-settings';

$error = '';
$success = '';

// Get settings from database
$loginBgSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_background'");
$currentLoginBg = $loginBgSetting['setting_value'] ?? null;

$loginLogoSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_logo'");
$currentLoginLogo = $loginLogoSetting['setting_value'] ?? null;

$faviconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
$currentFavicon = $faviconSetting['setting_value'] ?? null;

$pwaIconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'pwa_app_icon'");
$currentPwaIcon = $pwaIconSetting['setting_value'] ?? null;

// Per-business Staff Portal login background (used by modules/payroll/staff-portal.php)
$staffBusinesses = [];
foreach (glob(BASE_PATH . '/config/businesses/*.php') ?: [] as $bizFilePath) {
    $bizCfg = require $bizFilePath;
    if (!empty($bizCfg['business_id'])) {
        $staffBusinesses[$bizCfg['business_id']] = $bizCfg['name'] ?? $bizCfg['business_id'];
    }
}
$staffLoginBgSettings = [];
if ($staffBusinesses) {
    $staffBgRows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'staff_login_bg_%'");
    foreach ($staffBgRows as $row) {
        $bizIdFromKey = substr($row['setting_key'], strlen('staff_login_bg_'));
        $staffLoginBgSettings[$bizIdFromKey] = $row['setting_value'];
    }
}

$waSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'developer_whatsapp'");
$currentWA = $waSetting['setting_value'] ?? '';

$footerCopyrightSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_copyright'");
$currentFooterCopyright = $footerCopyrightSetting['setting_value'] ?? '';

$footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
$currentFooterVersion = $footerVersionSetting['setting_value'] ?? '';

// Get demo credentials
$demoUsernameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_username'");
$currentDemoUsername = $demoUsernameSetting['setting_value'] ?? 'admin';

$demoPasswordSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_password'");
$currentDemoPassword = $demoPasswordSetting['setting_value'] ?? 'admin';

// Read current config
$configFile = BASE_PATH . '/config/config.php';
$configContent = file_get_contents($configFile);

// Extract current values
preg_match("/define\('DEVELOPER_NAME',\s*'([^']*)'\);/", $configContent, $nameMatch);
preg_match("/define\('DEVELOPER_LOGO',\s*'([^']*)'\);/", $configContent, $logoMatch);

$currentDevName = $nameMatch[1] ?? 'DevTeam Studio';
$currentDevLogo = $logoMatch[1] ?? 'assets/img/developer-logo.png';

// Handle form submission for name
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['developer_name'])) {
    $newName = trim($_POST['developer_name']);

    if (!empty($newName)) {
        $newConfigContent = preg_replace(
            "/define\('DEVELOPER_NAME',\s*'[^']*'\);/",
            "define('DEVELOPER_NAME', '" . addslashes($newName) . "');",
            $configContent
        );

        if (file_put_contents($configFile, $newConfigContent)) {
            $success = 'Nama developer berhasil diupdate!';
            $currentDevName = $newName;
            $configContent = $newConfigContent;
        } else {
            $error = 'Gagal update config file. Periksa permission folder.';
        }
    } else {
        $error = 'Nama developer tidak boleh kosong.';
    }
}

// Handle login background upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['login_background']) && $_FILES['login_background']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['login_background'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $maxSize = 2 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG atau PNG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 2MB.';
    } else {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $localFilename = 'login-bg.' . $extension;

        $cloudinary = CloudinaryHelper::getInstance();
        $uploadResult = $cloudinary->smartUpload($file, 'uploads/backgrounds', $localFilename, 'backgrounds', 'login_background');

        if ($uploadResult['success']) {
            $storedValue = $uploadResult['path'];
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('login_background', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$storedValue, $storedValue]);
            $success = 'Background login berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
            $currentLoginBg = $storedValue;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle login logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['login_logo']) && $_FILES['login_logo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['login_logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/svg+xml'];
    $maxSize = 1 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG, PNG, atau SVG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 1MB.';
    } else {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $localFilename = 'login-logo.' . $extension;

        $cloudinary = CloudinaryHelper::getInstance();
        $uploadResult = $cloudinary->smartUpload($file, 'uploads/logos', $localFilename, 'logos', 'login_logo');

        if ($uploadResult['success']) {
            $storedValue = $uploadResult['path'];
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('login_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$storedValue, $storedValue]);
            $success = 'Logo login berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
            $currentLoginLogo = $storedValue;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle delete login logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_login_logo'])) {
    // Delete from Cloudinary if it's a cloud URL
    if ($currentLoginLogo && strpos($currentLoginLogo, 'http') === 0) {
        $cl = CloudinaryHelper::getInstance();
        $cl->delete('adf_system/logos/login_logo');
    }
    // Also remove local files
    $uploadDir = BASE_PATH . '/uploads/logos/';
    foreach (glob($uploadDir . 'login-logo.*') as $oldFile) {
        unlink($oldFile);
    }
    $db->query("DELETE FROM settings WHERE setting_key = 'login_logo'");
    $success = 'Logo login berhasil dihapus!';
    $currentLoginLogo = null;
}

// Handle favicon upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['site_favicon'];
    $allowedTypes = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/svg+xml'];
    $maxSize = 500 * 1024; // 500KB

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan ICO, PNG, atau SVG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 500KB.';
    } else {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $localFilename = 'favicon.' . $extension;

        $cloudinary = CloudinaryHelper::getInstance();
        $uploadResult = $cloudinary->smartUpload($file, 'uploads/icons', $localFilename, 'icons', 'site_favicon');

        if ($uploadResult['success']) {
            $storedValue = $uploadResult['path'];
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('site_favicon', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$storedValue, $storedValue]);
            $success = 'Favicon berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
            $currentFavicon = $storedValue;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle delete favicon
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_favicon'])) {
    // Delete from Cloudinary if it's a cloud URL
    if ($currentFavicon && strpos($currentFavicon, 'http') === 0) {
        $cl = CloudinaryHelper::getInstance();
        $cl->delete('adf_system/icons/site_favicon');
    }
    // Also remove local files
    $uploadDir = BASE_PATH . '/uploads/icons/';
    foreach (glob($uploadDir . 'favicon.*') as $oldFile) {
        unlink($oldFile);
    }
    $db->query("DELETE FROM settings WHERE setting_key = 'site_favicon'");
    $success = 'Favicon berhasil dihapus!';
    $currentFavicon = null;
}

// Handle PWA App Icon upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pwa_app_icon']) && $_FILES['pwa_app_icon']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['pwa_app_icon'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $maxSize = 2 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG atau PNG.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 2MB.';
    } else {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $localFilename = 'pwa-app-icon.' . $extension;

        $cloudinary = CloudinaryHelper::getInstance();
        $uploadResult = $cloudinary->smartUpload($file, 'uploads/icons', $localFilename, 'icons', 'pwa_app_icon');

        if ($uploadResult['success']) {
            $storedValue = $uploadResult['path'];
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('pwa_app_icon', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$storedValue, $storedValue]);
            $success = 'App icon PWA berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
            $currentPwaIcon = $storedValue;
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

// Handle delete PWA icon
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pwa_icon'])) {
    if ($currentPwaIcon && strpos($currentPwaIcon, 'http') === 0) {
        $cl = CloudinaryHelper::getInstance();
        $cl->delete('adf_system/icons/pwa_app_icon');
    }
    $uploadDir = BASE_PATH . '/uploads/icons/';
    foreach (glob($uploadDir . 'pwa-app-icon.*') as $oldFile) {
        @unlink($oldFile);
    }
    $db->query("DELETE FROM settings WHERE setting_key = 'pwa_app_icon'");
    $success = 'App icon PWA berhasil dihapus! (akan kembali ke icon default)';
    $currentPwaIcon = null;
}

// Handle Staff Portal login background upload (per business)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['staff_login_bg']) && $_FILES['staff_login_bg']['error'] === UPLOAD_ERR_OK) {
    $staffBizId = trim($_POST['staff_biz_id'] ?? '');
    if (!isset($staffBusinesses[$staffBizId])) {
        $error = 'Bisnis tidak valid.';
    } else {
        $file = $_FILES['staff_login_bg'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $maxSize = 3 * 1024 * 1024;

        if (!in_array($file['type'], $allowedTypes)) {
            $error = 'Tipe file tidak diizinkan. Gunakan JPG, PNG, atau WEBP.';
        } elseif ($file['size'] > $maxSize) {
            $error = 'Ukuran file terlalu besar. Maksimal 3MB.';
        } else {
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $localFilename = 'staff-login-bg-' . $staffBizId . '.' . $extension;
            $settingKey = 'staff_login_bg_' . $staffBizId;

            $cloudinary = CloudinaryHelper::getInstance();
            $uploadResult = $cloudinary->smartUpload($file, 'uploads/backgrounds', $localFilename, 'backgrounds', $settingKey);

            if ($uploadResult['success']) {
                $storedValue = $uploadResult['path'];
                $db->query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$settingKey, $storedValue, $storedValue]);
                $success = 'Background login staff portal (' . htmlspecialchars($staffBusinesses[$staffBizId]) . ') berhasil diupload!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : '');
                $staffLoginBgSettings[$staffBizId] = $storedValue;
            } else {
                $error = 'Gagal upload file.';
            }
        }
    }
}

// Handle delete Staff Portal login background (per business)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_staff_login_bg'])) {
    $staffBizId = trim($_POST['delete_staff_login_bg']);
    if (isset($staffBusinesses[$staffBizId])) {
        $existingVal = $staffLoginBgSettings[$staffBizId] ?? null;
        if ($existingVal && strpos($existingVal, 'http') === 0) {
            $cl = CloudinaryHelper::getInstance();
            $cl->delete('adf_system/backgrounds/staff_login_bg_' . $staffBizId);
        }
        $uploadDir = BASE_PATH . '/uploads/backgrounds/';
        foreach (glob($uploadDir . 'staff-login-bg-' . $staffBizId . '.*') as $oldFile) {
            @unlink($oldFile);
        }
        $db->query("DELETE FROM settings WHERE setting_key = ?", ['staff_login_bg_' . $staffBizId]);
        $success = 'Background login staff portal (' . htmlspecialchars($staffBusinesses[$staffBizId]) . ') berhasil dihapus!';
        unset($staffLoginBgSettings[$staffBizId]);
    }
}

// Handle WhatsApp number update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['whatsapp_number'])) {
    $waNumber = trim($_POST['whatsapp_number']);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('developer_whatsapp', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$waNumber, $waNumber]);
    $success = 'Nomor WhatsApp berhasil disimpan!';
    $currentWA = $waNumber;
}

// Handle footer text update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['footer_copyright'])) {
    $copyright = trim($_POST['footer_copyright']);
    $version = trim($_POST['footer_version']);

    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_copyright', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$copyright, $copyright]);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_version', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$version, $version]);

    $success = 'Teks footer berhasil diupdate!';
    $currentFooterCopyright = $copyright;
    $currentFooterVersion = $version;
}

// Handle demo credentials update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo_username'])) {
    $demoUsername = trim($_POST['demo_username']);
    $demoPassword = trim($_POST['demo_password']);

    if (!empty($demoUsername) && !empty($demoPassword)) {
        $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('demo_username', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$demoUsername, $demoUsername]);
        $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('demo_password', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$demoPassword, $demoPassword]);

        $success = 'Demo credentials berhasil diupdate!';
        $currentDemoUsername = $demoUsername;
        $currentDemoPassword = $demoPassword;
    } else {
        $error = 'Username dan password tidak boleh kosong.';
    }
}

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['developer_logo']) && $_FILES['developer_logo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['developer_logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/svg+xml'];
    $maxSize = 1 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        $error = 'Tipe file tidak diizinkan. Gunakan JPG, PNG, SVG, atau GIF.';
    } elseif ($file['size'] > $maxSize) {
        $error = 'Ukuran file terlalu besar. Maksimal 1MB.';
    } else {
        $uploadDir = BASE_PATH . '/assets/img/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'developer-logo.' . $extension;
        $uploadPath = $uploadDir . $filename;

        foreach (glob($uploadDir . 'developer-logo.*') as $oldFile) {
            unlink($oldFile);
        }

        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            $newLogoPath = 'assets/img/' . $filename;
            $newConfigContent = preg_replace(
                "/define\('DEVELOPER_LOGO',\s*'[^']*'\);/",
                "define('DEVELOPER_LOGO', '" . $newLogoPath . "');",
                $configContent
            );

            if (file_put_contents($configFile, $newConfigContent)) {
                $success = 'Logo developer berhasil diupload!';
                $currentDevLogo = $newLogoPath;
            } else {
                $error = 'File uploaded tapi gagal update config.';
            }
        } else {
            $error = 'Gagal upload file.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* COMPACT & READABLE CSS */
    .container-fluid {
        padding: 1rem 1.5rem !important;
        max-width: 90% !important;
    }

    .row {
        margin: 0 -0.5rem !important;
    }

    .col-lg-6 {
        padding: 0 0.5rem !important;
    }

    .py-4 {
        padding-top: 1rem !important;
        padding-bottom: 1rem !important;
    }

    .d-flex.justify-content-between {
        margin-bottom: 1rem !important;
    }

    /* Compact Cards */
    .settings-card {
        background: white !important;
        border-radius: 8px !important;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08) !important;
        margin-bottom: 1rem !important;
        overflow: hidden !important;
    }

    .settings-card-header {
        padding: 0.75rem 1rem !important;
        border-bottom: 1px solid #e5e7eb !important;
        display: flex !important;
        align-items: center !important;
        gap: 0.75rem !important;
    }

    .settings-card-header .icon {
        width: 32px !important;
        height: 32px !important;
        border-radius: 6px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 1rem !important;
    }

    .settings-card-header h5 {
        margin: 0 !important;
        font-weight: 600 !important;
        font-size: 0.95rem !important;
        line-height: 1.3 !important;
    }

    .settings-card-header small {
        color: #6b7280 !important;
        font-weight: 400 !important;
        font-size: 0.813rem !important;
    }

    .settings-card-body {
        padding: 1rem !important;
    }

    /* Preview Box */
    .preview-box {
        background: #f9fafb !important;
        border: 1px solid #e5e7eb !important;
        border-radius: 6px !important;
        padding: 0.75rem !important;
        text-align: center !important;
        margin-bottom: 0.75rem !important;
    }

    .preview-box img {
        max-width: 80px !important;
        max-height: 80px !important;
        border-radius: 4px !important;
    }

    /* Forms */
    .form-control,
    .form-select {
        padding: 0.5rem 0.75rem !important;
        font-size: 0.875rem !important;
        border-radius: 6px !important;
        border: 1px solid #d1d5db !important;
        height: auto !important;
        min-height: 38px !important;
        line-height: 1.5 !important;
    }

    .form-label {
        font-size: 0.875rem !important;
        font-weight: 600 !important;
        margin-bottom: 0.5rem !important;
        color: #374151 !important;
    }

    .form-text {
        font-size: 0.813rem !important;
        margin-top: 0.25rem !important;
        color: #6b7280 !important;
    }

    .btn {
        padding: 0.5rem 1rem !important;
        font-size: 0.875rem !important;
        border-radius: 6px !important;
        line-height: 1.5 !important;
        font-weight: 500 !important;
    }

    .btn-sm {
        padding: 0.375rem 0.75rem !important;
        font-size: 0.813rem !important;
    }

    /* Spacing */
    .mb-3 {
        margin-bottom: 1rem !important;
    }

    .mb-4 {
        margin-bottom: 1.5rem !important;
    }

    .mb-1 {
        margin-bottom: 0.25rem !important;
    }

    .me-1 {
        margin-right: 0.25rem !important;
    }

    .me-2 {
        margin-right: 0.5rem !important;
    }

    .mt-1 {
        margin-top: 0.25rem !important;
    }

    .mt-2 {
        margin-top: 0.5rem !important;
    }

    /* Alerts */
    .alert {
        padding: 0.75rem 1rem !important;
        font-size: 0.875rem !important;
        margin-bottom: 1rem !important;
        border-radius: 6px !important;
    }

    /* Typography */
    h4 {
        font-size: 1.125rem !important;
        margin-bottom: 0.5rem !important;
        font-weight: 600 !important;
    }

    h5 {
        font-size: 0.95rem !important;
    }

    h6 {
        font-size: 0.875rem !important;
    }

    .text-muted {
        font-size: 0.813rem !important;
        color: #6b7280 !important;
    }

    /* Checkboxes */
    .form-check {
        margin-bottom: 0.5rem !important;
        padding-left: 1.5rem !important;
    }

    .form-check-label {
        font-size: 0.875rem !important;
        padding-left: 0.25rem !important;
    }

    .form-check-input {
        margin-top: 0.125rem !important;
        width: 1.125rem !important;
        height: 1.125rem !important;
    }

    /* Current Value Display */
    .current-value {
        background: #f9fafb !important;
        border-left: 3px solid var(--dev-primary) !important;
        padding: 0.75rem !important;
        border-radius: 0 6px 6px 0 !important;
        margin-top: 0.5rem !important;
    }

    .current-value small {
        color: #6b7280 !important;
        font-size: 0.813rem !important;
    }

    .current-value strong {
        display: block !important;
        color: #111827 !important;
        margin-top: 0.25rem !important;
        font-size: 0.875rem !important;
    }

    /* Icons */
    .bi {
        font-size: 0.875rem !important;
    }

    .settings-card-header .bi {
        font-size: 1rem !important;
    }

    /* Grid */
    @media (min-width: 992px) {
        .col-lg-6 {
            max-width: 50% !important;
            flex: 0 0 50% !important;
        }
    }

    /* Page Header */
    .justify-content-between h4 {
        font-size: 1.5rem !important;
    }

    .justify-content-between .btn {
        font-size: 0.875rem !important;
        padding: 0.5rem 1rem !important;
    }
</style>

<div class="container-fluid py-4">



















































    </style>

    <div class="container-fluid py-4">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="bi bi-sliders me-2"></i>Developer Settings</h4>
                <p class="text-muted mb-0" style="font-size: 0.875rem;">Konfigurasi developer name, logo, background login, WhatsApp, dan footer</p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Kembali
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Warning Notice -->
        <div class="alert alert-warning mb-4" style="border-left: 4px solid #f59e0b;">
            <div class="d-flex align-items-start">
                <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
                <div>
                    <strong>Perhatian:</strong> Beberapa perubahan akan mengupdate file <code>config/config.php</code>.
                    Pastikan file memiliki permission writable pada hosting.
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Column 1: Developer Name & Logo -->
            <div class="col-lg-6">

                <!-- Developer Name -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="icon" style="background: rgba(111,66,193,0.15); color: var(--dev-primary);">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <div>
                            <h5>Nama Developer</h5>
                            <small>Tampil di footer sidebar</small>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Nama Developer</label>
                                <input type="text" name="developer_name" class="form-control"
                                    value="<?php echo htmlspecialchars($currentDevName); ?>"
                                    required maxlength="50" placeholder="DevTeam Studio">
                                <div class="form-text">Maksimal 50 karakter</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-lg me-1"></i>Simpan Nama
                            </button>
                        </form>
                        <div class="current-value">
                            <small>Nama saat ini:</small>
                            <strong><?php echo htmlspecialchars($currentDevName); ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Developer Logo -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="icon" style="background: rgba(239,68,68,0.15); color: var(--dev-danger);">
                            <i class="bi bi-image"></i>
                        </div>
                        <div>
                            <h5>Logo Developer</h5>
                            <small>Ukuran rekomendasi 100x100px</small>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="preview-box mb-3">
                            <?php
                            $logoFullPath = BASE_PATH . '/' . $currentDevLogo;
                            if (file_exists($logoFullPath)):
                            ?>
                                <img src="<?php echo BASE_URL . '/' . $currentDevLogo; ?>?v=<?php echo filemtime($logoFullPath); ?>" alt="Developer Logo">
                            <?php else: ?>
                                <div style="width:80px;height:80px;background:var(--dev-primary);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:white;font-size:1.5rem;font-weight:700;">&lt;/&gt;</div>
                            <?php endif; ?>
                        </div>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Upload Logo Baru</label>
                                <input type="file" name="developer_logo" class="form-control" accept="image/*" required>
                                <div class="form-text">Format: JPG, PNG, SVG, GIF • Maksimal 1MB</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-upload me-1"></i>Upload Logo
                            </button>
                        </form>
                    </div>
                </div>

            </div>

            <!-- Column 2: Login Background & WhatsApp -->
            <div class="col-lg-6">

                <!-- Login Background -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="icon" style="background: rgba(16,185,129,0.15); color: var(--dev-success);">
                            <i class="bi bi-card-image"></i>
                        </div>
                        <div>
                            <h5>Background Login</h5>
                            <small>Custom background halaman login</small>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <?php
                        $loginBgUrl = null;
                        if ($currentLoginBg) {
                            $cl = CloudinaryHelper::getInstance();
                            $loginBgUrl = $cl->getDisplayUrl($currentLoginBg, 'uploads/backgrounds/');
                        }
                        ?>
                        <?php if ($loginBgUrl): ?>
                            <div class="preview-box mb-3" style="padding:0;overflow:hidden;border:0;">
                                <img src="<?php echo $loginBgUrl; ?>?v=<?php echo time(); ?>"
                                    alt="Login Background" style="width:100%;height:150px;object-fit:cover;border-radius:10px;">
                            </div>
                        <?php else: ?>
                            <div class="preview-box mb-3">
                                <i class="bi bi-image text-muted" style="font-size:2rem;"></i>
                                <div class="text-muted mt-2" style="font-size:0.85rem;">Belum ada background</div>
                            </div>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Upload Background</label>
                                <input type="file" name="login_background" class="form-control" accept="image/*" required>
                                <div class="form-text">Format: JPG, PNG • Maksimal 2MB • Rekomendasi: 1920x1080px</div>
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-upload me-1"></i>Upload Background
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Login Background per Bisnis (Staff Portal) -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="icon" style="background: rgba(13,31,60,0.15); color: var(--dev-primary, #0d1f3c);">
                            <i class="bi bi-images"></i>
                        </div>
                        <div>
                            <h5>Background Login Staff Portal (Per Bisnis)</h5>
                            <small>Background berbeda untuk tiap bisnis di halaman login Staff Portal</small>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <?php if (!$staffBusinesses): ?>
                            <div class="text-muted" style="font-size:0.85rem;">Belum ada bisnis terdaftar di <code>config/businesses/</code>.</div>
                        <?php else: ?>
                            <?php foreach ($staffBusinesses as $bizId => $bizName): ?>
                                <?php
                                $bizBgStored = $staffLoginBgSettings[$bizId] ?? null;
                                $bizBgUrl = null;
                                if ($bizBgStored) {
                                    $cl = CloudinaryHelper::getInstance();
                                    $bizBgUrl = $cl->getDisplayUrl($bizBgStored, 'uploads/backgrounds/');
                                }
                                ?>
                                <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(0,0,0,.06);">
                                    <div style="width:64px;height:44px;border-radius:8px;overflow:hidden;flex-shrink:0;background:var(--dev-primary,#0d1f3c);display:flex;align-items:center;justify-content:center;">
                                        <?php if ($bizBgUrl): ?>
                                            <img src="<?php echo htmlspecialchars($bizBgUrl); ?>&v=<?php echo time(); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                                        <?php else: ?>
                                            <i class="bi bi-image text-white-50"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:12.5px;font-weight:600;" class="text-truncate"><?php echo htmlspecialchars($bizName); ?></div>
                                        <div style="font-size:10.5px;color:var(--dev-muted,#94a3b8);"><?php echo $bizBgUrl ? 'Custom background aktif' : 'Pakai gradient default'; ?></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#staffBg_<?php echo htmlspecialchars($bizId); ?>">
                                        <i class="bi bi-upload"></i>
                                    </button>
                                    <?php if ($bizBgUrl): ?>
                                        <form method="POST" onsubmit="return confirm('Hapus background staff portal untuk <?php echo htmlspecialchars(addslashes($bizName)); ?>?');" style="margin:0;">
                                            <input type="hidden" name="delete_staff_login_bg" value="<?php echo htmlspecialchars($bizId); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <div class="collapse" id="staffBg_<?php echo htmlspecialchars($bizId); ?>">
                                    <form method="POST" enctype="multipart/form-data" style="padding:10px 0 4px;">
                                        <input type="hidden" name="staff_biz_id" value="<?php echo htmlspecialchars($bizId); ?>">
                                        <div class="mb-2">
                                            <input type="file" name="staff_login_bg" class="form-control form-control-sm" accept="image/*" required>
                                            <div class="form-text">JPG/PNG/WEBP • Maksimal 3MB • Rekomendasi: 1080x1920px (potrait/mobile)</div>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary w-100">
                                            <i class="bi bi-upload me-1"></i>Upload untuk <?php echo htmlspecialchars($bizName); ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Login Logo -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="icon" style="background: rgba(59,130,246,0.15); color: #3b82f6;">
                            <i class="bi bi-building"></i>
                        </div>
                        <div>
                            <h5>Logo Login Page</h5>
                            <small>Logo tampil di halaman login (mengganti emoji)</small>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <?php
                        $loginLogoUrl = null;
                        if ($currentLoginLogo) {
                            $cl = CloudinaryHelper::getInstance();
                            $loginLogoUrl = $cl->getDisplayUrl($currentLoginLogo, 'uploads/logos/');
                        }
                        ?>
                        <?php if ($loginLogoUrl): ?>
                            <div class="preview-box mb-3">
                                <img src="<?php echo $loginLogoUrl; ?>?v=<?php echo time(); ?>"
                                    alt="Login Logo" style="max-width:100px;max-height:100px;border-radius:8px;">
                                <div class="mt-2">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="delete_login_logo" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash me-1"></i>Hapus Logo
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="preview-box mb-3">
                                <div style="width:80px;height:80px;background:#e5e7eb;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:2.5rem;">🏢</div>
                                <div class="text-muted mt-2" style="font-size:0.85rem;">Default: Emoji icon</div>
                            </div>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Upload Logo</label>
                                <input type="file" name="login_logo" class="form-control" accept="image/*" required>
                                <div class="form-text">Format: JPG, PNG, SVG • Maksimal 1MB • Rekomendasi: 100x100px</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-upload me-1"></i>Upload Logo
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Favicon Browser -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="icon" style="background: rgba(245,158,11,0.15); color: #f59e0b;">
                            <i class="bi bi-window"></i>
                        </div>
                        <div>
                            <h5>Favicon Browser</h5>
                            <small>Icon di tab browser (favicon)</small>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <?php
                        $faviconUrl = null;
                        if ($currentFavicon) {
                            $cl = CloudinaryHelper::getInstance();
                            $faviconUrl = $cl->getDisplayUrl($currentFavicon, 'uploads/icons/');
                        }
                        ?>
                        <?php if ($faviconUrl): ?>
                            <div class="preview-box mb-3">
                                <img src="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>"
                                    alt="Favicon" style="width:48px;height:48px;border-radius:4px;">
                                <div class="mt-2" style="font-size:0.75rem;color:#888;">
                                    Preview di tab:
                                    <span style="display:inline-flex;align-items:center;background:#f1f5f9;padding:4px 10px;border-radius:6px;margin-left:5px;">
                                        <img src="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>" style="width:16px;height:16px;margin-right:6px;">
                                        <span style="font-size:0.7rem;color:#333;">ADF System</span>
                                    </span>
                                </div>
                                <div class="mt-2">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="delete_favicon" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash me-1"></i>Hapus Favicon
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="preview-box mb-3">
                                <div style="width:48px;height:48px;background:#e5e7eb;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                                    <i class="bi bi-globe text-muted" style="font-size:1.5rem;"></i>
                                </div>
                                <div class="text-muted mt-2" style="font-size:0.85rem;">Belum ada favicon custom</div>
                            </div>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Upload Favicon</label>
                                <input type="file" name="site_favicon" class="form-control" accept=".ico,.png,.svg,image/x-icon,image/png,image/svg+xml" required>
                                <div class="form-text">Format: ICO, PNG, SVG • Maksimal 500KB • Rekomendasi: 32x32px atau 64x64px</div>
                            </div>
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bi bi-upload me-1"></i>Upload Favicon
                            </button>
                        </form>
                    </div>
                </div>

                <!-- PWA App Icon -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="icon" style="background: rgba(99,102,241,0.15); color: #6366f1;">
                            <i class="bi bi-phone"></i>
                        </div>
                        <div>
                            <h5>App Icon (PWA)</h5>
                            <small>Icon untuk install app di Android/iOS</small>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <?php if ($currentPwaIcon): ?>
                            <?php
                            $cl = CloudinaryHelper::getInstance();
                            $pwaIconUrl = $cl->getDisplayUrl($currentPwaIcon, 'uploads/icons/');
                            ?>
                            <div class="preview-box mb-3">
                                <img src="<?php echo $pwaIconUrl; ?>?v=<?php echo time(); ?>"
                                    alt="PWA Icon" style="width:96px;height:96px;border-radius:20px;box-shadow:0 4px 12px rgba(0,0,0,.15);">
                                <div class="mt-2" style="font-size:0.75rem;color:#888;">
                                    Preview di home screen:
                                    <div style="display:inline-flex;flex-direction:column;align-items:center;background:#f1f5f9;padding:8px 14px;border-radius:10px;margin-left:5px;margin-top:5px;">
                                        <img src="<?php echo $pwaIconUrl; ?>?v=<?php echo time(); ?>" style="width:48px;height:48px;border-radius:10px;margin-bottom:4px;">
                                        <span style="font-size:0.6rem;color:#333;">Staff Portal</span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="delete_pwa_icon" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash me-1"></i>Hapus Icon (kembali default)
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="preview-box mb-3">
                                <img src="../modules/payroll/absen-icon.php?size=192"
                                    alt="Default Icon" style="width:96px;height:96px;border-radius:20px;box-shadow:0 4px 12px rgba(0,0,0,.15);opacity:.5;">
                                <div class="text-muted mt-2" style="font-size:0.85rem;">Menggunakan icon default (auto-generated)</div>
                            </div>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Upload App Icon</label>
                                <input type="file" name="pwa_app_icon" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                                <div class="form-text">Format: PNG atau JPG &bull; Maksimal 2MB &bull; Rekomendasi: <strong>512x512px</strong> (persegi)</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-upload me-1"></i>Upload App Icon
                            </button>
                        </form>
                    </div>
                </div>

                <!-- WhatsApp Developer -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="icon" style="background: rgba(37,211,102,0.15); color: #25D366;">
                            <i class="bi bi-whatsapp"></i>
                        </div>
                        <div>
                            <h5>WhatsApp Developer</h5>
                            <small>Notifikasi trial expired</small>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Nomor WhatsApp</label>
                                <input type="text" name="whatsapp_number" class="form-control"
                                    value="<?php echo htmlspecialchars($currentWA); ?>"
                                    placeholder="628123456789" required>
                                <div class="form-text">Format: 628xxx (tanpa +, tanpa spasi)</div>
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-check-lg me-1"></i>Simpan WhatsApp
                            </button>
                        </form>
                        <div class="current-value">
                            <small>Nomor saat ini:</small>
                            <strong><?php echo $currentWA ? htmlspecialchars($currentWA) : '-'; ?></strong>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Footer Text - Full Width -->
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(139,92,246,0.15); color: #8b5cf6;">
                    <i class="bi bi-card-text"></i>
                </div>
                <div>
                    <h5>Teks Footer</h5>
                    <small>Edit copyright dan versi di footer halaman sistem</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Copyright Text</label>
                            <input type="text" name="footer_copyright" class="form-control"
                                value="<?php echo htmlspecialchars($currentFooterCopyright ?: '© ' . APP_YEAR . ' ' . APP_NAME . '. All rights reserved.'); ?>"
                                placeholder="© 2026 ADF System. All rights reserved." maxlength="100">
                            <div class="form-text">Teks copyright di footer. Kosongkan untuk default.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Version Text</label>
                            <input type="text" name="footer_version" class="form-control"
                                value="<?php echo htmlspecialchars($currentFooterVersion ?: 'Version ' . APP_VERSION); ?>"
                                placeholder="Version 1.0.0" maxlength="50">
                            <div class="form-text">Teks versi aplikasi. Kosongkan untuk default.</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Simpan Footer Text
                    </button>
                </form>

                <!-- Preview -->
                <div class="mt-4 pt-3 border-top">
                    <small class="text-muted d-block mb-2"><i class="bi bi-eye me-1"></i>Preview Footer:</small>
                    <div class="text-center p-3 rounded" style="background:#f8f9fa;">
                        <div class="text-muted" style="font-size:0.875rem;">
                            <?php echo htmlspecialchars($currentFooterCopyright ?: '© ' . APP_YEAR . ' ' . APP_NAME . '. All rights reserved.'); ?>
                        </div>
                        <div class="text-muted" style="font-size:0.8rem;">
                            <?php echo htmlspecialchars($currentFooterVersion ?: 'Version ' . APP_VERSION); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Demo Credentials Settings -->
        <div class="settings-card">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(236,72,153,0.15); color: #ec4899;">
                    <i class="bi bi-key-fill"></i>
                </div>
                <div>
                    <h5>🎯 Demo Credentials</h5>
                    <small>Konfigurasi username dan password yang tampil di login page</small>
                </div>
            </div>
            <div class="settings-card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Demo Username</label>
                            <input type="text" name="demo_username" class="form-control"
                                value="<?php echo htmlspecialchars($currentDemoUsername); ?>"
                                placeholder="admin" required maxlength="50">
                            <div class="form-text">Username yang tampil di halaman login</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Demo Password</label>
                            <input type="text" name="demo_password" class="form-control"
                                value="<?php echo htmlspecialchars($currentDemoPassword); ?>"
                                placeholder="admin" required maxlength="50">
                            <div class="form-text">Password yang tampil di halaman login</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Simpan Demo Credentials
                    </button>
                </form>

                <!-- Preview -->
                <div class="mt-4 pt-3 border-top">
                    <small class="text-muted d-block mb-2"><i class="bi bi-eye me-1"></i>Preview di Login Page:</small>
                    <div class="p-3 rounded" style="background:#1e293b; color: #cbd5e1; font-size: 0.85rem;">
                        <div style="text-align: center; margin-bottom: 0.5rem;"><strong>🎯 Demo Credentials (Click to Fill)</strong></div>
                        <div>👤 Username: <strong style="color: #818cf8;"><?php echo htmlspecialchars($currentDemoUsername); ?></strong></div>
                        <div>🔑 Password: <strong style="color: #818cf8;"><?php echo htmlspecialchars($currentDemoPassword); ?></strong></div>
                    </div>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle me-1"></i>User bisa klik box ini untuk auto-fill username & password
                    </small>
                </div>
            </div>
        </div>

        <!-- Technical Info -->
        <div class="settings-card" style="background: linear-gradient(135deg, rgba(111,66,193,0.05), rgba(139,92,246,0.05));">
            <div class="settings-card-header">
                <div class="icon" style="background: rgba(111,66,193,0.15); color: var(--dev-primary);">
                    <i class="bi bi-code-slash"></i>
                </div>
                <div>
                    <h5>Informasi Teknis</h5>
                    <small>Path dan konstanta yang digunakan</small>
                </div>
            </div>
            <div class="settings-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <small class="text-muted d-block">File Konfigurasi</small>
                        <code class="text-primary">config/config.php</code>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Konstanta Nama</small>
                        <code class="text-success">DEVELOPER_NAME</code>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Konstanta Logo</small>
                        <code class="text-success">DEVELOPER_LOGO</code>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Path Logo Saat Ini</small>
                        <code class="text-warning"><?php echo htmlspecialchars($currentDevLogo); ?></code>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">File Exists</small>
                        <?php if (file_exists($logoFullPath)): ?>
                            <span class="text-success"><i class="bi bi-check-circle me-1"></i>Yes</span>
                        <?php else: ?>
                            <span class="text-danger"><i class="bi bi-x-circle me-1"></i>No (using fallback)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>