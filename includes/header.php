<?php
// Load helper functions
require_once __DIR__ . '/functions.php';

// Load language system
require_once __DIR__ . '/language.php';

// Load motor notification system
require_once __DIR__ . '/MotorNotificationHelper.php';

// Load unpaid checked-in guest notification system
require_once __DIR__ . '/UnpaidGuestNotificationHelper.php';

// Sunsea must use its own custom UI/module stack.
// If a generic module tries to render with this global header, redirect to Sunsea dashboard.
if (defined('ACTIVE_BUSINESS_ID') && ACTIVE_BUSINESS_ID === 'sunsea') {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $isSunseaModule = (strpos($requestUri, '/modules/sunsea/') !== false);
    $isAllowedPath =
        (strpos($requestUri, '/logout.php') !== false) ||
        (strpos($requestUri, '/select-business.php') !== false) ||
        (strpos($requestUri, '/developer/') !== false) ||
        (strpos($requestUri, '/api/') !== false);

    if (!$isSunseaModule && !$isAllowedPath) {
        header('Location: ' . BASE_URL . '/modules/sunsea/dashboard.php');
        exit;
    }
}

/**
 * Get relative avatar path for a user if one exists.
 */
if (!function_exists('adfGetUserAvatarRelativePath')) {
    function adfGetUserAvatarRelativePath($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }

        $avatarDir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/uploads/avatars';
        $patterns = [
            $avatarDir . '/user_' . $userId . '.jpg',
            $avatarDir . '/user_' . $userId . '.jpeg',
            $avatarDir . '/user_' . $userId . '.png',
            $avatarDir . '/user_' . $userId . '.webp',
            $avatarDir . '/user_' . $userId . '.gif',
        ];

        foreach ($patterns as $candidate) {
            if (is_file($candidate)) {
                return 'uploads/avatars/' . basename($candidate);
            }
        }

        return null;
    }
}

/**
 * Build avatar URL with cache busting.
 */
if (!function_exists('adfGetUserAvatarUrl')) {
    function adfGetUserAvatarUrl($userId)
    {
        $relative = adfGetUserAvatarRelativePath($userId);
        if (!$relative) {
            return null;
        }

        $absolute = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/' . $relative;
        $version = is_file($absolute) ? filemtime($absolute) : time();
        return BASE_URL . '/' . $relative . '?v=' . $version;
    }
}

// Handle topbar avatar upload before any HTML output.
if (isset($_POST['__upload_topbar_avatar']) && $_POST['__upload_topbar_avatar'] === '1' && isset($_SESSION['user_id'])) {
    error_log('AVATAR_UPLOAD_START: User=' . $_SESSION['user_id'] . ' POST keys=' . json_encode(array_keys($_POST)) . ' FILES keys=' . json_encode(array_keys($_FILES)));
    $redirectBack = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/index.php');

    try {
        if (!isset($_FILES['avatar_file']) || !is_array($_FILES['avatar_file'])) {
            throw new Exception('File avatar tidak ditemukan.');
        }

        $file = $_FILES['avatar_file'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $errorMap = [
                UPLOAD_ERR_INI_SIZE => 'Ukuran foto melebihi batas server. Coba file lebih kecil (disarankan <= 2MB).',
                UPLOAD_ERR_FORM_SIZE => 'Ukuran foto terlalu besar untuk form upload.',
                UPLOAD_ERR_PARTIAL => 'Upload terputus. Silakan ulangi upload.',
                UPLOAD_ERR_NO_FILE => 'Belum ada file yang dipilih.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server upload error: folder sementara tidak tersedia. Debug: tmp_name=' . ($file['tmp_name'] ?? 'NONE'),
                UPLOAD_ERR_CANT_WRITE => 'Server upload error: gagal menulis file.',
                UPLOAD_ERR_EXTENSION => 'Upload diblokir oleh ekstensi server.',
            ];
            $msg = $errorMap[$uploadError] ?? ('Upload gagal (kode: ' . $uploadError . ').');
            error_log('AVATAR_UPLOAD_ERR: ' . $msg . ' | FILES: ' . json_encode($file));
            throw new Exception($msg);
        }

        $tmpPath = $file['tmp_name'] ?? '';
        if ($tmpPath === '' || (!is_uploaded_file($tmpPath) && !is_file($tmpPath))) {
            throw new Exception('File upload tidak valid.');
        }

        $maxSize = 3 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxSize) {
            throw new Exception('Ukuran foto maksimal 3MB.');
        }

        $mimeType = function_exists('mime_content_type') ? mime_content_type($tmpPath) : null;
        if (!$mimeType && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $tmpPath) ?: null;
                finfo_close($finfo);
            }
        }
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        $imageInfo = @getimagesize($tmpPath);
        if (!$imageInfo) {
            throw new Exception('File bukan gambar yang valid.');
        }

        $imageType = $imageInfo[2] ?? null;
        $imageTypeToExt = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF => 'gif',
        ];

        $detectedExt = $imageType !== null && isset($imageTypeToExt[$imageType])
            ? $imageTypeToExt[$imageType]
            : null;

        $finalExt = $allowed[$mimeType] ?? $detectedExt;
        if (!$finalExt) {
            throw new Exception('Format foto harus JPG, PNG, WEBP, atau GIF.');
        }

        $userId = (int)$_SESSION['user_id'];
        $avatarDir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/uploads/avatars';
        if (!is_dir($avatarDir)) {
            if (!@mkdir($avatarDir, 0777, true)) {
                throw new Exception('Server tidak bisa membuat folder avatar. Hubungi admin server.');
            }
            @chmod($avatarDir, 0777);
        }
        if (!is_writable($avatarDir)) {
            throw new Exception('Folder avatar tidak bisa ditulis. Hubungi admin server untuk fix permission.');
        }

        foreach (glob($avatarDir . '/user_' . $userId . '.*') ?: [] as $oldAvatar) {
            @unlink($oldAvatar);
        }

        $targetName = 'user_' . $userId . '.' . $finalExt;
        $targetPath = $avatarDir . '/' . $targetName;
        $fileRelativePath = '/uploads/avatars/' . $targetName;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new Exception('Gagal menyimpan foto profil.');
        }

        @chmod($targetPath, 0644);

        // Simpan metadata avatar ke database jika tersedia
        if (function_exists('getDBConnection')) {
            try {
                $db = getDBConnection();
                $fileSize = filesize($targetPath);
                $stmt = $db->prepare('
                    INSERT INTO user_avatars (user_id, file_name, file_type, file_size, file_path)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        file_name = VALUES(file_name),
                        file_type = VALUES(file_type),
                        file_size = VALUES(file_size),
                        file_path = VALUES(file_path),
                        updated_at = CURRENT_TIMESTAMP
                ');
                if ($stmt) {
                    $stmt->bind_param('issss', $userId, $targetName, $finalExt, $fileSize, $fileRelativePath);
                    $stmt->execute();
                    $stmt->close();
                }
            } catch (Exception $dbEx) {
                error_log('AVATAR_DB_INSERT: ' . $dbEx->getMessage());
                // Tidak critical jika database insert gagal, file sudah tersimpan
            }
        }

        if (function_exists('setFlash')) {
            setFlash('success', 'Foto profil berhasil diperbarui.');
        }
    } catch (Exception $e) {
        if (function_exists('setFlash')) {
            setFlash('error', $e->getMessage());
        }
    }

    header('Location: ' . $redirectBack);
    exit;
}

// Favicon: always use the ADF System logo (business icon must never override it)
$faviconUrl = BASE_URL . '/assets/img/developer-logo.png';
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['user_language'] ?? 'id'; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="500x500" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <link rel="apple-touch-icon" sizes="500x500" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Main CSS with Cache Busting -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=<?php echo time(); ?>">

    <!-- Icons (Feather Icons) -->
    <script src="https://unpkg.com/feather-icons"></script>

    <!-- Additional CSS -->
    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL . '/' . $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Inline Styles -->
    <?php if (isset($inlineStyles)): ?>
        <?php echo $inlineStyles; ?>
    <?php endif; ?>

    <!-- Business Theme CSS -->
    <style>
        <?php echo getBusinessThemeCSS(); ?> :root {
            --system-font-base: 0.875rem;
            --system-font-sm: 0.813rem;
            --system-font-xs: 0.75rem;
            --system-heading-lg: 1rem;
            --system-heading-md: 0.9375rem;
            --system-heading-sm: 0.875rem;
        }

        body[data-business] .main-content,
        body[data-business] .main-content .card,
        body[data-business] .main-content .top-bar,
        body[data-business] .main-content .table-container,
        body[data-business] .main-content .modal-content,
        body[data-business] .main-content .page-header,
        body[data-business] .main-content .content-grid {
            font-size: var(--system-font-base) !important;
        }

        body[data-business] .main-content :is(p,
            label,
            input,
            select,
            textarea,
            button,
            li,
            td,
            th,
            a,
            .btn,
            .form-label,
            .form-control,
            .table,
            .table td,
            .table th,
            .alert,
            .page-subtitle,
            .empty-state-text,
            .filter-chip,
            .stat-lbl) {
            font-size: var(--system-font-base) !important;
        }

        body[data-business] .main-content :is(small,
            .small,
            .text-muted,
            .card-title,
            .badge,
            .helper-text,
            .form-text,
            .table small,
            .meta-text,
            .stat-note) {
            font-size: var(--system-font-sm) !important;
        }

        body[data-business] .main-content :is(h1, .page-title) {
            font-size: var(--system-heading-lg) !important;
            line-height: 1.35;
        }

        body[data-business] .main-content :is(h2, h3, .section-title, .card h2, .card h3) {
            font-size: var(--system-heading-md) !important;
            line-height: 1.4;
        }

        body[data-business] .main-content :is(h4, h5, h6) {
            font-size: var(--system-heading-sm) !important;
            line-height: 1.4;
        }

        body[data-business] .main-content .btn-sm,
        body[data-business] .main-content .table .btn-sm {
            font-size: var(--system-font-sm) !important;
        }

        body[data-business] .main-content input::placeholder,
        body[data-business] .main-content textarea::placeholder,
        body[data-business] .main-content select,
        body[data-business] .main-content option {
            font-size: var(--system-font-base) !important;
        }

        .user-avatar-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .user-avatar-button {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 2px solid #bfdbfe;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.25);
            overflow: hidden;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            color: #1e3a8a;
            font-weight: 700;
            font-size: 0.95rem;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .user-avatar-button:hover {
            transform: translateY(-1px) scale(1.02);
            box-shadow: 0 6px 16px rgba(30, 58, 138, 0.32);
        }

        .user-avatar-button:focus {
            outline: 2px solid #60a5fa;
            outline-offset: 2px;
        }

        .user-avatar-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .user-avatar-edit-indicator {
            position: absolute;
            right: -2px;
            bottom: -2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #1d4ed8;
            color: #ffffff;
            border: 2px solid #ffffff;
            font-size: 0.72rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        body[data-theme="dark"] .user-avatar-button {
            border-color: #1e40af;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.45);
        }

        body[data-theme="dark"] .user-avatar-edit-indicator {
            border-color: #0f172a;
        }

        @keyframes bellShake {
            0% {
                transform: rotate(0)
            }

            15% {
                transform: rotate(14deg)
            }

            30% {
                transform: rotate(-14deg)
            }

            45% {
                transform: rotate(10deg)
            }

            60% {
                transform: rotate(-6deg)
            }

            75% {
                transform: rotate(2deg)
            }

            100% {
                transform: rotate(0)
            }
        }
    </style>
</head>
<?php
// Load user theme from database per business (reliable method)
// Warehouse/gudang businesses default to light; others default to dark
$userTheme = (defined('BUSINESS_TYPE') && BUSINESS_TYPE === 'warehouse') ? 'light' : 'dark';
$themeError = null;

if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $db = Database::getInstance();

        // Load theme for current business and user
        $themeResult = $db->fetchOne(
            "SELECT theme FROM user_preferences WHERE user_id = ? AND branch_id = ? LIMIT 1",
            [$_SESSION['user_id'], ACTIVE_BUSINESS_ID]
        );

        if ($themeResult && !empty($themeResult['theme'])) {
            $userTheme = $themeResult['theme'];
        }
        // For non-warehouse: fallback to any saved preference
        // For warehouse: keep the light default (don't inherit dark from other businesses)
        elseif (!defined('BUSINESS_TYPE') || BUSINESS_TYPE !== 'warehouse') {
            $fallbackTheme = $db->fetchOne(
                "SELECT theme FROM user_preferences WHERE user_id = ? LIMIT 1",
                [$_SESSION['user_id']]
            );

            if ($fallbackTheme && !empty($fallbackTheme['theme'])) {
                $userTheme = $fallbackTheme['theme'];
            }
        }
    } catch (Exception $e) {
        $themeError = $e->getMessage();
        // keep the default set above
    }
}

if (isset($forceTheme) && is_string($forceTheme)) {
    $forceTheme = strtolower(trim($forceTheme));
    if (in_array($forceTheme, ['light', 'dark'], true)) {
        $userTheme = $forceTheme;
    }
}
?>

<body data-theme="<?php echo htmlspecialchars($userTheme); ?>" data-business="<?php echo ACTIVE_BUSINESS_ID; ?>" data-business-type="<?php echo BUSINESS_TYPE; ?>">
    <?php if ($themeError): ?>
        <!-- Theme Load Warning: <?php echo htmlspecialchars($themeError); ?> -->
    <?php endif; ?>

    <!-- Motor Overdue / Unpaid Guest / Hotel Service Notification Banner -->
    <?php
    $unpaidGuestsCount = 0;
    try {
        $businessId = $_SESSION['business_id'] ?? 1;
        $overdueMotors = getOverdueMotorsForNotification($db->getConnection(), $businessId);
        $unpaidGuests = getUnpaidCheckedInGuests($db->getConnection());
        $unpaidGuestsCount = count($unpaidGuests);
        $unpaidHotelServices = getUnpaidHotelServiceInvoices($db->getConnection(), $businessId);

        // Pesan room/motor pakai warna default (putih), pesan hotel service diberi warna beda (kuning keemasan)
        $plainMessages = array_merge(formatOverdueMotorMessages($overdueMotors), formatUnpaidGuestMessages($unpaidGuests));
        $hsMessages = formatUnpaidHotelServiceMessages($unpaidHotelServices);
        $bannerMessages = array_merge(
            array_map(fn($m) => htmlspecialchars($m), $plainMessages),
            array_map(fn($m) => '<span style="color:#fde047;">' . htmlspecialchars($m) . '</span>', $hsMessages)
        );
        if (!empty($bannerMessages)):
            $count = count($bannerMessages);
            $notificationText = implode('&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', $bannerMessages);
            // Ticker speed - lower duration = faster scroll.
            $scrollDuration = max(4, $count * 2);
            $bannerClickTarget = !empty($unpaidGuests) ? (BASE_URL . '/modules/frontdesk/in-house.php') : (!empty($unpaidHotelServices) ? (BASE_URL . '/modules/frontdesk/hotel-services.php') : (BASE_URL . '/modules/frontdesk/rental-motor.php'));
    ?>
            <style>
                .motor-overdue-banner {
                    background: linear-gradient(90deg, var(--primary-dark), var(--primary-color), var(--primary-dark));
                    background-size: 200% 100%;
                    animation: banner-bg 4s linear infinite;
                    color: #ffffff !important;
                    -webkit-text-fill-color: #ffffff !important;
                    text-fill-color: #ffffff !important;
                    padding: 0.5rem 0;
                    overflow: hidden;
                    position: relative;
                    font-weight: 700;
                    font-size: 0.84rem;
                    letter-spacing: 0.01em;
                    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45);
                    box-shadow: var(--shadow-glow);
                    border-bottom: 2px solid var(--primary-dark);
                    z-index: 999;
                    cursor: pointer;
                }

                .motor-overdue-banner,
                .motor-overdue-banner * {
                    -webkit-text-fill-color: unset;
                    text-fill-color: unset;
                    opacity: 1 !important;
                    mix-blend-mode: normal !important;
                }

                @keyframes banner-bg {
                    0% {
                        background-position: 0% 50%;
                    }

                    100% {
                        background-position: 200% 50%;
                    }
                }

                .motor-overdue-banner .ob-label {
                    position: absolute;
                    left: 210px;
                    top: 0;
                    bottom: 0;
                    display: flex;
                    align-items: center;
                    padding: 0 0.75rem;
                    background: rgba(0, 0, 0, 0.35);
                    white-space: nowrap;
                    font-size: 0.78rem;
                    gap: 0.3rem;
                    z-index: 2;
                    border-right: 1px solid rgba(255, 255, 255, 0.2);
                    color: #ffffff;
                }

                @media (max-width: 768px) {
                    .motor-overdue-banner .ob-label {
                        left: 0;
                    }
                }

                .motor-overdue-banner .ob-label .notif-dot {
                    width: 9px;
                    height: 9px;
                    border-radius: 50%;
                    background: #ef4444;
                    box-shadow: 0 0 0 rgba(239, 68, 68, 0.7);
                    animation: notif-dot-pulse 1.4s ease-out infinite;
                    flex-shrink: 0;
                }

                @keyframes notif-dot-pulse {
                    0% {
                        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
                    }

                    70% {
                        box-shadow: 0 0 0 6px rgba(239, 68, 68, 0);
                    }

                    100% {
                        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
                    }
                }

                .motor-overdue-banner .ob-ticker {
                    display: block;
                    white-space: nowrap;
                    padding-left: 370px;
                    color: #ffffff;
                    animation: ticker-scroll <?php echo $scrollDuration; ?>s linear infinite;
                }

                @media (max-width: 768px) {
                    .motor-overdue-banner .ob-ticker {
                        padding-left: 160px;
                    }
                }

                @keyframes ticker-scroll {
                    0% {
                        transform: translateX(0);
                    }

                    100% {
                        transform: translateX(-100%);
                    }
                }

                .motor-overdue-banner:hover .ob-ticker {
                    animation-play-state: paused;
                }
            </style>
            <div class="motor-overdue-banner" onclick="window.location.href='<?php echo $bannerClickTarget; ?>'" title="Klik untuk lihat detail">
                <span class="ob-label">
                    <span class="notif-dot"></span>
                    PERHATIAN (<?php echo $count; ?>)
                </span>
                <span class="ob-ticker">
                    <?php echo $notificationText; ?>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                    <?php echo $notificationText; ?>
                </span>
            </div>
        <?php endif; ?>
    <?php } catch (\Throwable $e) {
        // Silent fail if notification fails
    } ?>

    <div class="main-wrapper">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <?php
                // Get business logo
                $logoPath = getBusinessLogo();
                if (defined('ACTIVE_BUSINESS_ID') && ACTIVE_BUSINESS_ID === 'gudang-nasita') {
                    $logoPath = BASE_URL . '/assets/img/gudang-nasita-logo.svg';
                }

                // Get company name from settings, fallback to BUSINESS_NAME
                $companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
                $displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value'])
                    ? $companyNameSetting['setting_value']
                    : BUSINESS_NAME;
                ?>
                <div style="display: flex; align-items: center; gap: 0.875rem;">
                    <?php if ($logoPath): ?>
                        <?php if (ACTIVE_BUSINESS_ID === 'cqc'): ?>
                            <!-- CQC: rectangular logo, no company name -->
                            <div style="width: 100%; border-radius: var(--radius-md); background: var(--bg-secondary, #fff); padding: 8px 10px; display: flex; align-items: center; justify-content: center;">
                                <img src="<?php echo $logoPath; ?>" alt="CQC" style="width: 100%; max-height: 48px; border-radius: 4px; object-fit: contain;">
                            </div>
                        <?php else: ?>
                            <div style="width: 76px; height: 76px; border-radius: 50%; overflow: hidden; flex-shrink: 0;">
                                <img src="<?php echo $logoPath; ?>" alt="<?php echo htmlspecialchars($displayCompanyName); ?>" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="width: 76px; height: 76px; border-radius: 50%; background: linear-gradient(135deg, <?php echo BUSINESS_COLOR; ?>, <?php echo BUSINESS_COLOR; ?>dd); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <span style="font-size: 2rem; font-weight: 800; color: white;"><?php echo BUSINESS_ICON; ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (ACTIVE_BUSINESS_ID !== 'cqc'): ?>
                        <div style="flex: 1;">
                            <h1 class="logo" style="margin: 0; font-size: 1rem;"><?php echo htmlspecialchars($displayCompanyName); ?></h1>
                            <p style="color: var(--text-muted); font-size: 0.75rem; margin: 0; margin-top: 0.25rem;"><?php echo ucfirst(BUSINESS_TYPE); ?> System</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Business Switcher Dropdown (Only show if user has multiple business access) -->
                <?php
                require_once __DIR__ . '/business_access.php';
                $userBusinesses = getUserAvailableBusinesses();
                if (count($userBusinesses) > 1):
                ?>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                        <label style="font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.5rem; display: block;">Switch Business</label>
                        <select onchange="switchBusiness(this.value)" style="width: 100%; padding: 0.5rem; background: var(--bg-tertiary); border: 1px solid var(--bg-quaternary); border-radius: var(--radius-md); color: var(--text-primary); font-size: 0.875rem; cursor: pointer;">
                            <?php
                            foreach ($userBusinesses as $bizId => $bizConfig):
                                $selected = ($bizId === ACTIVE_BUSINESS_ID) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($bizId); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($bizConfig['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>

            <nav style="flex: 1; overflow-y: auto; overflow-x: hidden;">
                <ul class="nav-menu">
                    <?php $isGudangNasitaContext = (defined('ACTIVE_BUSINESS_ID') && ACTIVE_BUSINESS_ID === 'gudang-nasita'); ?>
                    <?php if ($isGudangNasitaContext): ?>
                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/gudang/dashboard.php" class="nav-link <?php echo activeMenu('dashboard.php'); ?>">
                                <i data-feather="grid" class="nav-icon"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-nasita.php" class="nav-link <?php echo (activeMenu('gudang-nasita.php') || activeMenu('stock.php')) ? 'active' : ''; ?>">
                                <i data-feather="archive" class="nav-icon"></i>
                                <span>Stock Gudang</span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-produk.php" class="nav-link <?php echo activeMenu('gudang-produk.php'); ?>">
                                <i data-feather="database" class="nav-icon"></i>
                                <span>Database Produk</span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-po-supplier.php" class="nav-link <?php echo activeMenu('gudang-po-supplier.php'); ?>">
                                <i data-feather="shopping-cart" class="nav-icon"></i>
                                <span>PO Supplier</span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/procurement/purchase-orders.php" class="nav-link <?php echo activeMenu('purchase-orders.php'); ?>">
                                <i data-feather="send" class="nav-icon"></i>
                                <span>History Terkirim</span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-transfer.php" class="nav-link <?php echo activeMenu('gudang-transfer.php'); ?>">
                                <i data-feather="repeat" class="nav-icon"></i>
                                <span>Transfer ke Bisnis</span>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-tagihan.php" class="nav-link <?php echo activeMenu('gudang-tagihan.php'); ?>">
                                <i data-feather="file-text" class="nav-icon"></i>
                                <span>Tagihan</span>
                            </a>
                        </li>
                    <?php else: ?>
                        <?php if ($auth->hasPermission('dashboard')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/index.php" class="nav-link <?php echo activeMenu('index.php'); ?>">
                                    <i data-feather="home" class="nav-icon"></i>
                                    <span><?php echo __('dashboard.title'); ?></span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- CQC Projects Menu (Solar Panel) -->
                        <?php if ($auth->hasPermission('cqc-projects')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/cqc-projects/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('cqc-projects'); ?>">
                                    <i data-feather="sun" class="nav-icon"></i>
                                    <span>CQC Projects</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/cqc-projects/dashboard.php" class="submenu-link <?php echo activeMenu('dashboard.php'); ?>">
                                            <i data-feather="bar-chart-2" class="submenu-icon"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/cqc-projects/add.php" class="submenu-link <?php echo activeMenu('add.php'); ?>">
                                            <i data-feather="plus-circle" class="submenu-icon"></i>
                                            <span>Tambah Proyek</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- PO Menu (Manufacture/PWF) -->
                        <?php if ($auth->hasPermission('production')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/production/orders.php" class="nav-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/production/orders') !== false) ? 'active' : ''; ?>">
                                    <i data-feather="clipboard" class="nav-icon"></i>
                                    <span>PO</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($auth->hasPermission('cashbook')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/cashbook/index.php" class="nav-link <?php echo activeMenu('cashbook'); ?>">
                                    <i data-feather="book" class="nav-icon"></i>
                                    <span><?php echo __('cashbook.title'); ?></span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($auth->hasPermission('divisions')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/divisions/index.php" class="nav-link <?php echo activeMenu('divisions'); ?>">
                                    <i data-feather="grid" class="nav-icon"></i>
                                    <span><?php echo __('settings.divisions'); ?></span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($auth->hasPermission('frontdesk') && isModuleEnabled('frontdesk')): ?>
                            <style>
                                .fd-unpaid-dot {
                                    display: inline-block;
                                    width: 9px;
                                    height: 9px;
                                    margin-right: 6px;
                                    background: #ef4444;
                                    border-radius: 50%;
                                    animation: fd-unpaid-blink 1.1s ease-in-out infinite;
                                }

                                @keyframes fd-unpaid-blink {

                                    0%,
                                    100% {
                                        opacity: 1;
                                        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6);
                                    }

                                    50% {
                                        opacity: 0.35;
                                        box-shadow: 0 0 6px 3px rgba(239, 68, 68, 0.6);
                                    }
                                }
                            </style>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/frontdesk/') !== false && strpos($_SERVER['REQUEST_URI'], 'hotel-services.php') === false && strpos($_SERVER['REQUEST_URI'], 'rental-motor.php') === false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (strpos($_SERVER['REQUEST_URI'], 'hotel-services.php') === false && strpos($_SERVER['REQUEST_URI'], 'rental-motor.php') === false) ? activeMenu('frontdesk') : ''; ?>">
                                    <?php if (!empty($unpaidGuestsCount)): ?>
                                        <span class="fd-unpaid-dot" title="<?php echo $unpaidGuestsCount; ?> tamu belum lunas"></span>
                                    <?php endif; ?>
                                    <i data-feather="home" class="nav-icon"></i>
                                    <span><?php echo __('menu.frontdesk'); ?></span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/dashboard.php" class="submenu-link <?php echo activeMenu('dashboard.php'); ?>">
                                            <i data-feather="layout" class="submenu-icon"></i>
                                            <span><?php echo __('dashboard.title'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/reservasi.php" class="submenu-link <?php echo activeMenu('reservasi.php'); ?>">
                                            <i data-feather="calendar" class="submenu-icon"></i>
                                            <span><?php echo __('menu.reservations'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/calendar.php" class="submenu-link <?php echo activeMenu('calendar.php'); ?>">
                                            <i data-feather="grid" class="submenu-icon"></i>
                                            <span><?php echo __('menu.calendar'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/in-house.php" class="submenu-link <?php echo activeMenu('in-house.php'); ?>">
                                            <i data-feather="users" class="submenu-icon"></i>
                                            <span><?php echo __('menu.in_house'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/hk-allocation.php" class="submenu-link <?php echo activeMenu('hk-allocation.php'); ?>">
                                            <i data-feather="check-square" class="submenu-icon"></i>
                                            <span>Pembagian HK</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/breakfast.php"
                                            class="submenu-link <?php echo activeMenu('breakfast.php'); ?>"
                                            onclick="console.log('Breakfast link clicked!'); return true;">
                                            <i data-feather="coffee" class="submenu-icon"></i>
                                            <span><?php echo __('menu.breakfast'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/laporan.php" class="submenu-link <?php echo activeMenu('laporan.php'); ?>">
                                            <i data-feather="file-text" class="submenu-icon"></i>
                                            <span><?php echo __('menu.reports'); ?></span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/settings.php" class="submenu-link <?php echo activeMenu('settings.php'); ?>">
                                            <i data-feather="settings" class="submenu-icon"></i>
                                            <span><?php echo __('settings.title'); ?></span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Hotel Services Menu (hotel only) -->
                        <?php if (defined('BUSINESS_TYPE') && BUSINESS_TYPE === 'hotel' && $auth->hasPermission('frontdesk')): ?>
                            <li class="nav-item has-submenu <?php echo (activeMenu('hotel-services.php') || activeMenu('rental-motor.php') || activeMenu('rental-motor-dashboard.php')) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (activeMenu('hotel-services.php') || activeMenu('rental-motor.php') || activeMenu('rental-motor-dashboard.php')) ? 'active' : ''; ?>">
                                    <i data-feather="briefcase" class="nav-icon"></i>
                                    <span>Hotel Services</span>
                                    <?php if (!empty($unpaidHotelServices)): ?>
                                        <span class="nav-menu-dot" title="Ada tagihan belum lunas"></span>
                                    <?php endif; ?>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/hotel-services.php" class="submenu-link <?php echo activeMenu('hotel-services.php'); ?>">
                                            <i data-feather="file-text" class="submenu-icon"></i>
                                            <span>Invoice & Layanan</span>
                                            <?php if (!empty($unpaidHotelServices)): ?>
                                                <span class="nav-menu-dot" title="Ada tagihan belum lunas"></span>
                                            <?php endif; ?>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/frontdesk/rental-motor-dashboard.php" class="submenu-link <?php echo (activeMenu('rental-motor.php') || activeMenu('rental-motor-dashboard.php')) ? 'active' : ''; ?>">
                                            <i data-feather="truck" class="submenu-icon"></i>
                                            <span>Rental Motor</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Sales Invoice Menu (CQC only, not for hotel) -->
                        <?php if ($auth->hasPermission('sales_invoice') && isModuleEnabled('sales') && (!defined('BUSINESS_TYPE') || BUSINESS_TYPE !== 'hotel')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/sales/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('sales'); ?>">
                                    <i data-feather="file-text" class="nav-icon"></i>
                                    <span><?php echo __('menu.sales_invoice'); ?></span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/sales/index-cqc.php?tab=termin" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'index-cqc') !== false && ($_GET['tab'] ?? '') === 'termin') ? 'active' : ''; ?>">
                                            <i data-feather="file" class="submenu-icon"></i>
                                            <span>Invoice Termin</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/sales/index-cqc.php?tab=general" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'index-cqc') !== false && ($_GET['tab'] ?? '') === 'general') ? 'active' : ''; ?>">
                                            <i data-feather="file-text" class="submenu-icon"></i>
                                            <span>Invoice Umum</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/sales/index-cqc.php?tab=quotation" class="submenu-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'index-cqc') !== false && ($_GET['tab'] ?? '') === 'quotation') ? 'active' : ''; ?>">
                                            <i data-feather="clipboard" class="submenu-icon"></i>
                                            <span>Quotation</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Bills / Tagihan Menu -->
                        <?php if ($auth->hasPermission('bills')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/bills/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('bills'); ?>">
                                    <i data-feather="credit-card" class="nav-icon"></i>
                                    <span>Tagihan</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/bills/index.php" class="submenu-link <?php echo activeMenu('bills/index'); ?>">
                                            <i data-feather="list" class="submenu-icon"></i>
                                            <span>Daftar Tagihan</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/bills/templates.php" class="submenu-link <?php echo activeMenu('templates.php'); ?>">
                                            <i data-feather="layers" class="submenu-icon"></i>
                                            <span>Template Rutin</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/bills/business-warehouse.php" class="submenu-link <?php echo activeMenu('business-warehouse'); ?>">
                                            <i data-feather="repeat" class="submenu-icon"></i>
                                            <span>Tagihan Bisnis & Gudang</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Cafe Invoice Menu (Bens Cafe only) -->
                        <?php if (isModuleEnabled('cafe-invoice') && $auth->hasPermission('cafe_invoice')): ?>
                            <li class="nav-item <?php echo (strpos($_SERVER['REQUEST_URI'], '/cafe-invoice/') !== false) ? 'open' : ''; ?>">
                                <a href="<?php echo BASE_URL; ?>/modules/cafe-invoice/index.php" class="nav-link <?php echo activeMenu('cafe-invoice'); ?>">
                                    <i data-feather="file-text" class="nav-icon"></i>
                                    <span>☕ Invoice</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- Payroll Menu -->
                        <?php if ($auth->hasPermission('payroll') && isModuleEnabled('payroll')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/payroll/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('payroll'); ?>">
                                    <i data-feather="dollar-sign" class="nav-icon"></i>
                                    <span>Payroll</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/payroll/employees.php" class="submenu-link <?php echo activeMenu('employees.php'); ?>">
                                            <i data-feather="users" class="submenu-icon"></i>
                                            <span>Employee Data</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/payroll/process.php" class="submenu-link <?php echo activeMenu('process.php'); ?>">
                                            <i data-feather="monitor" class="submenu-icon"></i>
                                            <span>Process Salary</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/payroll/attendance.php" class="submenu-link <?php echo activeMenu('attendance.php'); ?>">
                                            <i data-feather="map-pin" class="submenu-icon"></i>
                                            <span>Absensi GPS</span>
                                        </a>
                                    </li>

                                </ul>
                            </li>
                        <?php endif; ?>

                        <?php $showPurchaseMenu = (!$isGudangNasitaContext && ($auth->hasPermission('procurement_po') || $auth->hasPermission('procurement_stock'))); ?>
                        <?php if ($showPurchaseMenu): ?>
                            <li class="nav-item has-submenu <?php echo (activeMenu('purchase-orders.php') || activeMenu('business-stock-incoming.php')) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (activeMenu('purchase-orders.php') || activeMenu('business-stock-incoming.php')) ? 'active' : ''; ?>">
                                    <i data-feather="shopping-bag" class="nav-icon"></i>
                                    <span>Purchase</span>
                                </a>
                                <ul class="submenu">
                                    <?php if ($auth->hasPermission('procurement_po')): ?>
                                        <li class="submenu-item">
                                            <a href="<?php echo BASE_URL; ?>/modules/procurement/purchase-orders.php" class="submenu-link <?php echo activeMenu('purchase-orders.php'); ?>">
                                                <i data-feather="clipboard" class="submenu-icon"></i>
                                                <span>PO Gudang</span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($auth->hasPermission('procurement_stock')): ?>
                                        <li class="submenu-item">
                                            <a href="<?php echo BASE_URL; ?>/modules/procurement/business-stock-incoming.php" class="submenu-link <?php echo activeMenu('business-stock-incoming.php'); ?>">
                                                <i data-feather="inbox" class="submenu-icon"></i>
                                                <span>Stock Gudang</span>
                                            </a>
                                        </li>
                                        <li class="submenu-item">
                                            <a href="<?php echo BASE_URL; ?>/modules/procurement/staff-stock-access.php" class="submenu-link <?php echo activeMenu('staff-stock-access.php'); ?>">
                                                <i data-feather="user-check" class="submenu-icon"></i>
                                                <span>Akses Stock Staff</span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Gudang Nasita Menu (Warehouse) -->
                        <?php if (ACTIVE_BUSINESS_ID === 'gudang-nasita' && ($auth->hasPermission('gudang_view') || $auth->hasPermission('warehouse'))): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/modules/gudang/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo (strpos($_SERVER['REQUEST_URI'], '/modules/gudang/') !== false) ? 'active' : ''; ?>">
                                    <i data-feather="archive" class="nav-icon"></i>
                                    <span>Gudang Nasita</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/dashboard.php" class="submenu-link <?php echo activeMenu('dashboard.php'); ?>">
                                            <i data-feather="home" class="submenu-icon"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/barang.php" class="submenu-link <?php echo activeMenu('barang.php'); ?>">
                                            <i data-feather="package" class="submenu-icon"></i>
                                            <span>Master Barang</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/stock.php" class="submenu-link <?php echo activeMenu('stock.php'); ?>">
                                            <i data-feather="layers" class="submenu-icon"></i>
                                            <span>Kelola Stok</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/po-supplier.php" class="submenu-link <?php echo activeMenu('po-supplier.php'); ?>">
                                            <i data-feather="file-text" class="submenu-icon"></i>
                                            <span>PO Supplier</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/transfer.php" class="submenu-link <?php echo activeMenu('transfer.php'); ?>">
                                            <i data-feather="arrow-right" class="submenu-icon"></i>
                                            <span>Transfer ke Bisnis</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/minimum-stock.php" class="submenu-link <?php echo activeMenu('minimum-stock.php'); ?>">
                                            <i data-feather="alert-circle" class="submenu-icon"></i>
                                            <span>Minimum Stock Alert</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/gudang/reports.php" class="submenu-link <?php echo activeMenu('reports.php'); ?>">
                                            <i data-feather="bar-chart-2" class="submenu-icon"></i>
                                            <span>Laporan Gudang</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Laporan Dropdown Menu -->
                        <?php if ($auth->hasPermission('reports') && isModuleEnabled('reports')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/reports/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('reports'); ?>">
                                    <i data-feather="bar-chart-2" class="nav-icon"></i>
                                    <span><?php echo __('menu.reports'); ?></span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/reports/daily.php" class="submenu-link <?php echo activeMenu('daily.php'); ?>">
                                            <i data-feather="calendar" class="submenu-icon"></i>
                                            <span>Laporan Harian</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/reports/monthly.php" class="submenu-link <?php echo activeMenu('monthly.php'); ?>">
                                            <i data-feather="trending-up" class="submenu-icon"></i>
                                            <span>Laporan Bulanan</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/reports/yearly.php" class="submenu-link <?php echo activeMenu('yearly.php'); ?>">
                                            <i data-feather="activity" class="submenu-icon"></i>
                                            <span>Laporan Tahunan</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/reports/by-division.php" class="submenu-link <?php echo activeMenu('by-division.php'); ?>">
                                            <i data-feather="grid" class="submenu-icon"></i>
                                            <span>Laporan Per Divisi</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Project Menu -->
                        <?php if ($auth->hasPermission('project')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/project/" class="nav-link <?php echo activeMenu('project'); ?>">
                                    <i data-feather="folder" class="nav-icon"></i>
                                    <span>Project</span>
                                </a>
                            </li>
                        <?php endif; ?>


                        <!-- Finance Menu -->
                        <?php if ($auth->hasPermission('finance')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/finance/" class="nav-link <?php echo activeMenu('finance'); ?>">
                                    <i data-feather="trending-up" class="nav-icon"></i>
                                    <span>Manajemen Keuangan</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- Owner Monitoring Menu -->
                        <?php if ($auth->hasPermission('owner')): ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/owner/" class="nav-link <?php echo activeMenu('owner'); ?>">
                                    <i data-feather="eye" class="nav-icon"></i>
                                    <span>Owner Monitoring</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- Database Master Menu (CQC) -->
                        <?php if ($auth->hasPermission('database')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/database/') !== false) ? 'open' : ''; ?>">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('database'); ?>">
                                    <i data-feather="database" class="nav-icon"></i>
                                    <span>Database</span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/database/" class="submenu-link">
                                            <i data-feather="home" class="submenu-icon"></i>
                                            <span>Dashboard</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/database/suppliers.php" class="submenu-link">
                                            <i data-feather="truck" class="submenu-icon"></i>
                                            <span>Supplier</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/database/customers.php" class="submenu-link">
                                            <i data-feather="users" class="submenu-icon"></i>
                                            <span>Customer</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/database/staff.php" class="submenu-link">
                                            <i data-feather="user-check" class="submenu-icon"></i>
                                            <span>Staf</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <?php if ($auth->hasPermission('settings')): ?>
                            <li class="nav-item has-submenu <?php echo (strpos($_SERVER['REQUEST_URI'], '/settings/') !== false) ? 'open' : ''; ?>" style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                                <a href="javascript:void(0)" class="nav-link dropdown-toggle <?php echo activeMenu('settings'); ?>">
                                    <i data-feather="settings" class="nav-icon"></i>
                                    <span><?php echo __('settings.title'); ?></span>
                                </a>
                                <ul class="submenu">
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/settings/" class="submenu-link <?php echo activeMenu('settings-index'); ?>">
                                            <i data-feather="home" class="submenu-icon"></i>
                                            <span>Beranda Settings</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/settings/change-password.php" class="submenu-link <?php echo activeMenu('change-password.php'); ?>">
                                            <i data-feather="lock" class="submenu-icon"></i>
                                            <span>Ganti Password</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/settings/company.php" class="submenu-link <?php echo activeMenu('company.php'); ?>">
                                            <i data-feather="briefcase" class="submenu-icon"></i>
                                            <span>Setup Perusahaan</span>
                                        </a>
                                    </li>
                                    <li class="submenu-item">
                                        <a href="<?php echo BASE_URL; ?>/modules/settings/display.php" class="submenu-link <?php echo activeMenu('display.php'); ?>">
                                            <i data-feather="eye" class="submenu-icon"></i>
                                            <span>Display & Theme</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <?php
                        $activeBizRaw = (string)($_SESSION['active_business_id'] ?? (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : ''));
                        $activeBizNorm = strtolower((string)preg_replace('/[^a-z0-9]/', '', $activeBizRaw));
                        $menuBookBizNorm = ['narayanahotel', 'benscafe', 'eaatmeet', 'eatmeet'];
                        $isMenuBookBiz = in_array($activeBizNorm, $menuBookBizNorm, true);
                        $isDeveloperRole = (($_SESSION['role'] ?? '') === 'developer');
                        if ($isMenuBookBiz && ($isDeveloperRole || $auth->hasPermission('menu_book'))):
                        ?>
                            <li class="nav-item">
                                <a href="<?php echo BASE_URL; ?>/modules/menu-book/index.php" class="nav-link <?php echo (strpos($_SERVER['REQUEST_URI'], '/menu-book/') !== false) ? 'active' : ''; ?>">
                                    <i data-feather="book-open" class="nav-icon"></i>
                                    <span>Buku Menu</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <li class="nav-item" style="margin-top: 2rem;">
                        <a href="<?php echo BASE_URL; ?>/logout.php" class="nav-link">
                            <i data-feather="log-out" class="nav-icon"></i>
                            <span><?php echo __('menu.logout'); ?></span>
                        </a>
                    </li>
                </ul>
            </nav>

            <!-- Sidebar Footer -->
            <?php
            // Get custom footer version from settings
            $footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
            $sidebarFooterVersion = $footerVersionSetting['setting_value'] ?? ('Version ' . APP_VERSION);
            ?>
            <div class="sidebar-footer" style="padding: 0.5rem 0.9rem; border-top: 1px solid var(--bg-tertiary); margin-top: auto; text-align: center;">
                <div style="font-size: 0.72rem; color: var(--text-muted); line-height: 1.6;">
                    <span style="font-weight: 600; color: var(--text-primary); font-size: 0.75rem;"><?php echo DEVELOPER_NAME; ?></span><br>
                    <?php echo htmlspecialchars($sidebarFooterVersion); ?> &bull; <?php echo APP_YEAR; ?>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div>
                    <h1 class="page-title" style="color:#1e3a8a;-webkit-text-fill-color:#1e3a8a;background:none;"><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
                    <?php if (isset($pageSubtitle)): ?>
                        <p style="color: var(--text-muted); margin-top: 0.5rem;"><?php echo $pageSubtitle; ?></p>
                    <?php endif; ?>
                </div>

                <div style="display: flex; align-items: center; gap: 1.5rem;">
                    <!-- End Shift Button -->
                    <a id="endShiftButton" href="<?php echo BASE_URL; ?>/print-end-shift-report.php" target="_blank" rel="noopener"
                        style="padding: 0.5rem 1rem; background: #1e3a8a; color: #eff6ff; border: 1px solid #1e40af; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: all 0.2s; text-decoration: none;">
                        <i data-feather="power" style="width: 18px; height: 18px;"></i>
                        <span>End Shift</span>
                    </a>

                    <!-- Notification Bell -->
                    <div id="adminNotifBell" style="position:relative;cursor:pointer;" onclick="toggleAdminNotif()">
                        <i data-feather="bell" style="width:22px;height:22px;color:var(--text-muted);transition:color .2s;"></i>
                        <span id="adminNotifBadge" style="display:none;position:absolute;top:-4px;right:-6px;background:#ef4444;color:#fff;font-size:0.6rem;font-weight:800;min-width:16px;height:16px;border-radius:8px;align-items:center;justify-content:center;padding:0 4px;"></span>
                    </div>

                    <!-- Notification Panel -->
                    <div id="adminNotifPanel" style="display:none;position:absolute;top:60px;right:120px;width:400px;max-height:500px;background:#fff;border-radius:12px;box-shadow:0 10px 50px rgba(0,0,0,.15);border:1px solid #e5e7eb;z-index:999;overflow:hidden;">
                        <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-weight:700;font-size:0.9rem;color:#1e293b;">📋 Pengajuan Staff</span>
                            <span id="adminNotifCount" style="background:#ef4444;color:#fff;font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:10px;display:none;">0</span>
                        </div>
                        <div id="adminNotifList" style="max-height:420px;overflow-y:auto;padding:4px 0;">
                            <div style="padding:30px;text-align:center;color:#94a3b8;font-size:0.8rem;">Memuat...</div>
                        </div>
                    </div>

                    <!-- Date & Time Display -->
                    <div style="text-align: right; padding-right: 1.5rem; border-right: 1px solid var(--bg-tertiary);">
                        <div style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary);" id="currentDate">
                            <?php echo date('d/m/Y'); ?>
                        </div>
                        <div style="font-size: 0.875rem; font-weight: 700; color: var(--primary-color); font-variant-numeric: tabular-nums;" id="currentTime">
                            <?php echo date('H:i:s'); ?>
                        </div>
                    </div>

                    <!-- User Info -->
                    <div class="user-info">
                        <div style="text-align: right; margin-right: 1rem;">
                            <div style="font-weight: 600; color: #1e3a8a; -webkit-text-fill-color: #1e3a8a;">
                                <?php echo $_SESSION['full_name'] ?? 'User'; ?>
                            </div>
                            <div style="font-size: 0.875rem; color: #2563eb; -webkit-text-fill-color: #2563eb; opacity: 0.95;">
                                <?php echo ucfirst($_SESSION['role'] ?? 'staff'); ?>
                            </div>
                        </div>
                        <?php
                        $avatarUrl = isset($_SESSION['user_id']) ? adfGetUserAvatarUrl((int)$_SESSION['user_id']) : null;
                        $userInitial = strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1));
                        ?>
                        <div class="user-avatar-wrap" title="Klik untuk ganti foto profil">
                            <form id="topbarAvatarUploadForm" method="post" enctype="multipart/form-data" style="display:none;">
                                <input type="hidden" name="__upload_topbar_avatar" value="1">
                                <input id="topbarAvatarInput" type="file" name="avatar_file" accept="image/png,image/jpeg,image/webp,image/gif" onchange="console.log('Avatar file selected, submitting form...'); document.getElementById('topbarAvatarUploadForm').submit();">
                            </form>
                            <button type="button" class="user-avatar user-avatar-button" onclick="console.log('Avatar button clicked'); document.getElementById('topbarAvatarInput').click();" aria-label="Upload foto profil">
                                <?php if ($avatarUrl): ?>
                                    <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Foto Profil" class="user-avatar-image">
                                <?php else: ?>
                                    <?php echo $userInitial; ?>
                                <?php endif; ?>
                            </button>
                            <span class="user-avatar-edit-indicator">+</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($success = getFlash('success')): ?>
                <div class="alert alert-success fade-in" style="background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); padding: 1rem; border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                    <i data-feather="check-circle" style="width: 20px; height: 20px; vertical-align: middle;"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if ($error = getFlash('error')): ?>
                <div class="alert alert-danger fade-in" style="background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 1rem; border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                    <i data-feather="alert-circle" style="width: 20px; height: 20px; vertical-align: middle;"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Page Content -->
            <div class="page-content">

                <script>
                    // Business Switcher Function
                    function switchBusiness(businessId) {
                        if (confirm('Switch to selected business? Current page will reload.')) {
                            // Send AJAX request to switch business
                            fetch('<?php echo BASE_URL; ?>/api/switch-business.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: 'business_id=' + encodeURIComponent(businessId)
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        // Redirect to business landing page for clearer workflow
                                        if (businessId === 'gudang-nasita') {
                                            window.location.href = '<?php echo BASE_URL; ?>/modules/procurement/gudang-nasita.php';
                                        } else {
                                            window.location.href = '<?php echo BASE_URL; ?>/index.php';
                                        }
                                    } else {
                                        alert('Failed to switch business: ' + (data.message || 'Unknown error'));
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    alert('Failed to switch business. Please try again.');
                                });
                        } else {
                            // Reset select to current value
                            document.querySelector('select[onchange*="switchBusiness"]').value = '<?php echo ACTIVE_BUSINESS_ID; ?>';
                        }
                    }

                    // ═══ Admin Notification System ═══
                    let adminNotifOpen = false;
                    const NOTIF_BASE = '<?php echo BASE_URL; ?>';

                    function toggleAdminNotif() {
                        adminNotifOpen = !adminNotifOpen;
                        const panel = document.getElementById('adminNotifPanel');
                        if (adminNotifOpen) {
                            panel.style.display = 'block';
                            loadAdminNotifs();
                        } else {
                            panel.style.display = 'none';
                        }
                    }

                    document.addEventListener('click', function(e) {
                        if (adminNotifOpen && !e.target.closest('#adminNotifBell') && !e.target.closest('#adminNotifPanel')) {
                            adminNotifOpen = false;
                            document.getElementById('adminNotifPanel').style.display = 'none';
                        }
                    });

                    async function loadAdminNotifs() {
                        try {
                            const res = await fetch(NOTIF_BASE + '/api/get-notifications.php?type=admin_pending');
                            const data = await res.json();
                            const leaves = data.pending_leaves || [];
                            const overtimes = data.pending_overtimes || [];
                            const total = leaves.length + overtimes.length;

                            if (total === 0) {
                                document.getElementById('adminNotifList').innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:0.8rem;">✅ Tidak ada pengajuan pending</div>';
                                return;
                            }

                            let html = '';
                            overtimes.forEach(o => {
                                html += `<div style="padding:12px 18px;border-bottom:1px solid #f8fafc;" id="notif-ot-${o.id}">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                        <span style="font-size:14px;">⏰</span>
                                        <span style="font-weight:700;font-size:0.78rem;color:#1e293b;">${o.full_name}</span>
                                        <span style="margin-left:auto;background:#fef3c7;color:#92400e;font-size:0.6rem;font-weight:700;padding:2px 8px;border-radius:8px;">LEMBUR</span>
                                    </div>
                                    <div style="font-size:0.75rem;color:#64748b;margin-bottom:8px;">📅 ${o.overtime_date} — ${o.reason||'Tidak ada keterangan'}</div>
                                    <div style="display:flex;gap:6px;">
                                        <button onclick="approveReject('overtime','approve',${o.id})" style="flex:1;padding:6px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:0.7rem;font-weight:700;cursor:pointer;">✅ Setujui</button>
                                        <button onclick="approveReject('overtime','reject',${o.id})" style="flex:1;padding:6px;background:#ef4444;color:#fff;border:none;border-radius:6px;font-size:0.7rem;font-weight:700;cursor:pointer;">❌ Tolak</button>
                                    </div>
                                </div>`;
                            });
                            leaves.forEach(l => {
                                const tl = {
                                    cuti: '🏖️ Cuti',
                                    sakit: '🩺 Sakit',
                                    izin: '📋 Izin',
                                    cuti_khusus: '⭐ Cuti Khusus'
                                } [l.leave_type] || l.leave_type;
                                html += `<div style="padding:12px 18px;border-bottom:1px solid #f8fafc;" id="notif-lv-${l.id}">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                        <span style="font-size:14px;">📝</span>
                                        <span style="font-weight:700;font-size:0.78rem;color:#1e293b;">${l.full_name}</span>
                                        <span style="margin-left:auto;background:#dbeafe;color:#1e40af;font-size:0.6rem;font-weight:700;padding:2px 8px;border-radius:8px;">${tl}</span>
                                    </div>
                                    <div style="font-size:0.75rem;color:#64748b;margin-bottom:4px;">📅 ${l.start_date} s/d ${l.end_date}</div>
                                    ${l.reason ? `<div style="font-size:0.72rem;color:#64748b;margin-bottom:8px;">💬 ${l.reason}</div>` : ''}
                                    <div style="display:flex;gap:6px;">
                                        <button onclick="approveReject('leave','approve',${l.id})" style="flex:1;padding:6px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:0.7rem;font-weight:700;cursor:pointer;">✅ Setujui</button>
                                        <button onclick="approveReject('leave','reject',${l.id})" style="flex:1;padding:6px;background:#ef4444;color:#fff;border:none;border-radius:6px;font-size:0.7rem;font-weight:700;cursor:pointer;">❌ Tolak</button>
                                    </div>
                                </div>`;
                            });
                            document.getElementById('adminNotifList').innerHTML = html;
                        } catch (e) {
                            document.getElementById('adminNotifList').innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;font-size:0.8rem;">Gagal memuat</div>';
                        }
                    }

                    async function approveReject(type, action, id) {
                        const notes = prompt(action === 'reject' ? 'Alasan penolakan (opsional):' : 'Catatan (opsional):');
                        if (notes === null) return;
                        const fd = new FormData();
                        if (type === 'overtime') {
                            fd.append('action', action === 'approve' ? 'approve_overtime' : 'reject_overtime');
                            fd.append('overtime_id', id);
                        } else {
                            fd.append('action', action === 'approve' ? 'approve_leave' : 'reject_leave');
                            fd.append('leave_id', id);
                        }
                        fd.append('admin_notes', notes);
                        try {
                            const res = await fetch(NOTIF_BASE + '/api/get-notifications.php?type=admin_action', {
                                method: 'POST',
                                body: fd
                            });
                            const data = await res.json();
                            if (data.success) {
                                const el = document.getElementById('notif-' + (type === 'overtime' ? 'ot' : 'lv') + '-' + id);
                                if (el) {
                                    el.innerHTML = '<div style="padding:8px;text-align:center;color:' + (action === 'approve' ? '#16a34a' : '#ef4444') + ';font-size:0.78rem;font-weight:700;">' + (action === 'approve' ? '✅ Disetujui' : '❌ Ditolak') + '</div>';
                                    setTimeout(() => {
                                        el.style.display = 'none';
                                        checkAdminNotifs();
                                    }, 1500);
                                }
                            } else {
                                alert(data.message || 'Gagal memproses');
                            }
                        } catch (e) {
                            alert('Error: ' + e.message);
                        }
                    }

                    let _lastAdminCount = 0;
                    async function checkAdminNotifs() {
                        try {
                            const res = await fetch(NOTIF_BASE + '/api/get-notifications.php?type=admin_count');
                            const data = await res.json();
                            const count = data.pending_count || 0;
                            const badge = document.getElementById('adminNotifBadge');
                            const bell = document.getElementById('adminNotifBell');
                            if (badge) {
                                if (count > 0) {
                                    badge.textContent = count;
                                    badge.style.display = 'flex';
                                    if (count > _lastAdminCount && bell) {
                                        bell.style.animation = 'none';
                                        void bell.offsetWidth;
                                        bell.style.animation = 'bellShake .6s ease';
                                    }
                                } else {
                                    badge.style.display = 'none';
                                }
                            }
                            const cntEl = document.getElementById('adminNotifCount');
                            if (cntEl) {
                                if (count > 0) {
                                    cntEl.textContent = count;
                                    cntEl.style.display = 'inline';
                                } else {
                                    cntEl.style.display = 'none';
                                }
                            }
                            _lastAdminCount = count;
                            if (adminNotifOpen) loadAdminNotifs();
                        } catch (e) {
                            console.log('notif check err', e);
                        }
                    }
                    checkAdminNotifs();
                    setInterval(checkAdminNotifs, 15000);
                </script>