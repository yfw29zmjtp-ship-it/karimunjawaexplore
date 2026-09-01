<?php

/**
 * Staff Portal - Login/Register + Dashboard
 * PWA single-page app for staff: Absen, Monitoring, Occupancy, Breakfast
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';

// ── Resolve Business ──
$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizFile = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
if (!$bizSlug || !file_exists($bizFile)) {
    $avail = array_map(fn($f) => basename($f, '.php'), glob(__DIR__ . '/../../config/businesses/*.php') ?: []);
    die('<div style="font-family:sans-serif;padding:40px;max-width:400px;margin:60px auto;background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center"><h2 style="color:#dc2626">❌ Link Tidak Valid</h2><p style="color:#64748b">Gunakan link dari admin. Contoh: <code>?b=narayana-hotel</code></p></div>');
}
$bizConfig = require $bizFile;
if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $bizConfig['business_id']);
$db = Database::switchDatabase($bizConfig['database']);
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$apiUrl = 'staff-api.php?b=' . urlencode($bizSlug);
$bizName = htmlspecialchars($bizConfig['name'] ?? 'Staff Portal');
$bizType = $bizConfig['business_type'] ?? 'general';
$isHotel = ($bizType === 'hotel');
$isCafe = in_array($bizType, ['cafe', 'restaurant']);
$themeColor = $bizConfig['theme']['color_primary'] ?? '#0d1f3c';
$themeSecondary = $bizConfig['theme']['color_secondary'] ?? ($isCafe ? '#2563eb' : '#1a3a5c');
$bizIcon = $bizConfig['theme']['icon'] ?? '🏢';

// Logo
$absenConfig = $db->fetchOne("SELECT app_logo FROM payroll_attendance_config WHERE id=1") ?: [];
$appLogo = null;
if (!empty($absenConfig['app_logo'])) {
    $appLogo = (str_starts_with($absenConfig['app_logo'], 'http')) ? $absenConfig['app_logo'] : $baseUrl . '/' . ltrim($absenConfig['app_logo'], '/');
}

// Invoice/PDF logo (same as report-settings) for slip gaji — from MASTER DB
$slipLogo = null;
try {
    $masterDb = Database::getInstance();
    $invoiceLogoRow = $masterDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = :key", ['key' => 'invoice_logo_' . ACTIVE_BUSINESS_ID]);
    if ($invoiceLogoRow && !empty($invoiceLogoRow['setting_value'])) {
        $val = $invoiceLogoRow['setting_value'];
        $slipLogo = (strpos($val, 'http') === 0) ? $val : $baseUrl . '/uploads/logos/' . $val;
    }
} catch (Exception $e) {
}

// PWA Icon — use login_logo from settings (same DB as login.php & developer-settings.php)
$pwaIconUrl = 'absen-icon.php?size=192'; // fallback
try {
    // developer-settings.php stores into getInstance() DB, login.php reads from same
    $settingsDb = Database::getInstance();
    $iconKeys = [
        'pwa_app_icon' => 'uploads/icons/',
        'login_logo'   => 'uploads/logos/',
    ];
    foreach ($iconKeys as $iconKey => $localPrefix) {
        $iconRow = $settingsDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$iconKey]);
        $iconVal = $iconRow['setting_value'] ?? null;
        if (!$iconVal) continue;
        if (strpos($iconVal, 'http') === 0) {
            $pwaIconUrl = $iconVal;
            break;
        } else {
            $localPath = __DIR__ . '/../../' . $localPrefix . $iconVal;
            if (file_exists($localPath)) {
                $pwaIconUrl = $baseUrl . '/' . $localPrefix . $iconVal;
                break;
            }
        }
    }
} catch (Exception $e) {
}

// ── Cegah browser/PWA cache HTML staff portal (selalu fetch latest) ──
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="<?php echo htmlspecialchars($themeColor); ?>">
    <title>Staff Portal - <?php echo $bizName; ?></title>
    <link rel="manifest" href="staff-manifest.php?b=<?php echo urlencode($bizSlug); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($pwaIconUrl); ?>">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="preload" href="https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/dist/face-api.min.js" as="script" crossorigin>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --navy: <?php echo htmlspecialchars($themeColor); ?>;
            --navy2: <?php echo htmlspecialchars($themeSecondary); ?>;
            --gold: #f0b429;
            --green: #059669;
            --red: #dc2626;
            --orange: #ea580c;
            --blue: #2563eb;
            --purple: #7c3aed;
            --bg: #f1f5f9;
            --card: #fff;
            --border: #e2e8f0;
            --muted: #64748b;
            --text: #1e293b;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Auth Screen — Ocean Blue ── */
        .auth-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(160deg, #0a1628 0%, #0c2d48 30%, #145374 60%, #1a7fa0 100%);
            position: relative;
            overflow: hidden;
        }

        .auth-wrap::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -30%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(20, 140, 200, .12), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .auth-wrap::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -20%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(6, 182, 212, .08), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .auth-card {
            background: rgba(255, 255, 255, .97);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 36px 28px 28px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, .35), 0 0 0 1px rgba(255, 255, 255, .1);
            position: relative;
            z-index: 1;
        }

        .auth-logo {
            text-align: center;
            margin-bottom: 22px;
        }

        .auth-logo img {
            height: 56px;
            max-width: 180px;
            object-fit: contain;
            filter: drop-shadow(0 2px 8px rgba(0, 0, 0, .1));
        }

        .auth-logo h1 {
            font-size: 19px;
            color: #0c2d48;
            margin-top: 10px;
            font-weight: 800;
            letter-spacing: .3px;
        }

        .auth-logo p {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
            letter-spacing: .2px;
        }

        .auth-tabs {
            display: flex;
            background: #f0f7ff;
            border-radius: 12px;
            padding: 3px;
            margin-bottom: 20px;
            gap: 2px;
        }

        .auth-tab {
            flex: 1;
            padding: 9px 6px;
            border: none;
            background: transparent;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all .2s;
        }

        .auth-tab:hover {
            color: #0c2d48;
        }

        .auth-tab.active {
            background: linear-gradient(135deg, #0ea5e9, #06b6d4);
            color: #fff;
            font-weight: 700;
            box-shadow: 0 2px 10px rgba(14, 165, 233, .3);
        }

        .auth-form {
            display: none;
        }

        .auth-form.active {
            display: block;
        }

        .fg {
            margin-bottom: 14px;
        }

        .fl {
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 4px;
            display: block;
        }

        .fi {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text);
            background: #fff;
            transition: .15s;
        }

        .fi:focus {
            border-color: #0ea5e9;
            outline: none;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, .15);
        }

        .btn-auth {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #0c2d48, #145374);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 4px 16px rgba(12, 45, 72, .25);
        }

        .btn-auth:hover {
            box-shadow: 0 6px 24px rgba(12, 45, 72, .35);
            transform: translateY(-1px);
        }

        .btn-auth:hover {
            opacity: .9;
        }

        .btn-auth:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .auth-msg {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            margin-bottom: 12px;
            display: none;
        }

        .auth-msg.err {
            display: block;
            background: #fef2f2;
            color: var(--red);
            border: 1px solid #fca5a5;
        }

        .auth-msg.ok {
            display: block;
            background: #f0fdf4;
            color: var(--green);
            border: 1px solid #86efac;
        }

        .fi-hint {
            font-size: 10px;
            color: var(--muted);
            margin-top: 3px;
        }

        /* Password field with eye toggle */
        .pw-wrap {
            position: relative;
        }

        .pw-wrap .fi {
            padding-right: 40px;
        }

        .pw-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            color: var(--muted);
            padding: 4px;
            line-height: 1;
        }

        .pw-toggle:hover {
            color: var(--navy);
        }

        .remember-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 14px;
            margin-top: -4px;
        }

        .remember-row input[type=checkbox] {
            width: 16px;
            height: 16px;
            accent-color: #0ea5e9;
            cursor: pointer;
        }

        .remember-row label {
            font-size: 11px;
            color: var(--muted);
            cursor: pointer;
            user-select: none;
        }

        /* ── App Shell ── */
        .app-wrap {
            display: none;
            min-height: 100vh;
            padding-bottom: 70px;
            background: var(--bg);
        }

        .app-header {
            background: linear-gradient(135deg, var(--navy), var(--navy2));
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .app-header .logo {
            height: 36px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
        }

        .app-header .title {
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            flex: 1;
        }

        .app-header .user-badge {
            background: rgba(255, 255, 255, .15);
            color: var(--gold);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .app-header .logout-btn {
            background: none;
            border: 1px solid rgba(255, 255, 255, .2);
            color: #fff;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            cursor: pointer;
        }

        /* Bottom Nav - 5 tabs */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            border-top: 1px solid var(--border);
            display: flex;
            z-index: 100;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, .08);
        }

        .nav-item {
            flex: 1;
            padding: 6px 2px 5px;
            text-align: center;
            cursor: pointer;
            transition: .15s;
            border-top: 2px solid transparent;
        }

        .nav-item.active {
            border-top-color: var(--gold);
        }

        .nav-item .nav-icon {
            font-size: 16px;
            display: block;
        }

        .nav-item .nav-label {
            font-size: 8px;
            font-weight: 600;
            color: var(--muted);
            margin-top: 1px;
        }

        .nav-item.active .nav-label {
            color: var(--navy);
            font-weight: 700;
        }

        /* Notification bell */
        .notif-bell {
            position: relative;
            cursor: pointer;
            font-size: 18px;
            padding: 4px 8px;
        }

        .notif-dot {
            position: absolute;
            top: 2px;
            right: 4px;
            width: 8px;
            height: 8px;
            background: var(--red);
            border-radius: 50%;
            display: none;
        }

        .notif-dot.show {
            display: block;
        }

        .notif-bell.shake {
            animation: bellShake 0.8s ease-in-out;
        }

        @keyframes bellShake {
            0% {
                transform: rotate(0);
            }

            15% {
                transform: rotate(14deg);
            }

            30% {
                transform: rotate(-14deg);
            }

            45% {
                transform: rotate(10deg);
            }

            60% {
                transform: rotate(-8deg);
            }

            75% {
                transform: rotate(4deg);
            }

            85% {
                transform: rotate(-2deg);
            }

            100% {
                transform: rotate(0);
            }
        }

        /* Install banner — fixed bottom, visible everywhere */
        .install-banner {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 900;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy2) 100%);
            color: #fff;
            padding: 16px 16px calc(16px + env(safe-area-inset-bottom, 0px));
            display: none;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            border-top: 1px solid rgba(240, 180, 41, .3);
            box-shadow: 0 -4px 30px rgba(0, 0, 0, .3);
        }

        .install-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 120px;
            height: 120px;
            background: radial-gradient(circle, rgba(240, 180, 41, .15), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .install-banner.show {
            display: flex;
            animation: ibSlideUp .5s cubic-bezier(.16, 1, .3, 1);
        }

        @keyframes ibSlideUp {
            from {
                opacity: 0;
                transform: translateY(100%);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .install-banner .ib-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--gold), #e09800);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .install-banner .ib-text {
            flex: 1;
        }

        .install-banner .ib-title {
            font-weight: 700;
            font-size: 14px;
            color: #fff;
        }

        .install-banner .ib-sub {
            font-size: 11px;
            color: rgba(255, 255, 255, .6);
            margin-top: 2px;
        }

        .install-banner .ib-action {
            background: var(--gold);
            color: var(--navy);
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .install-banner .ib-close {
            background: none;
            border: none;
            font-size: 16px;
            cursor: pointer;
            padding: 4px;
            color: rgba(255, 255, 255, .4);
            position: absolute;
            top: 8px;
            right: 8px;
        }

        /* Install progress overlay */
        .install-progress {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5, 10, 24, .95);
            z-index: 2000;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .install-progress.show {
            display: flex;
            animation: faceIn .3s ease;
        }

        .ip-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            margin-bottom: 20px;
            object-fit: cover;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .4);
        }

        .ip-title {
            color: #fff;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .ip-sub {
            color: rgba(255, 255, 255, .5);
            font-size: 12px;
            margin-bottom: 24px;
            text-align: center;
            padding: 0 40px;
        }

        .ip-bar {
            width: 200px;
            height: 4px;
            background: rgba(255, 255, 255, .1);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .ip-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--gold), #34d399);
            border-radius: 2px;
            width: 0%;
            transition: width .5s cubic-bezier(.4, 0, .2, 1);
        }

        .ip-step {
            color: rgba(255, 255, 255, .4);
            font-size: 11px;
            min-height: 16px;
        }

        .ip-done {
            display: none;
            flex-direction: column;
            align-items: center;
        }

        .ip-check {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #34d399, #059669);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #fff;
            margin-bottom: 16px;
            animation: popIn .4s cubic-bezier(.16, 1, .3, 1);
        }

        @keyframes popIn {
            from {
                transform: scale(0);
            }

            to {
                transform: scale(1);
            }
        }

        .ip-done-text {
            color: #fff;
            font-size: 16px;
            font-weight: 700;
        }

        .ip-done-sub {
            color: rgba(255, 255, 255, .5);
            font-size: 11px;
            margin-top: 4px;
        }

        /* iOS install guide */
        .install-guide {
            background: #fff;
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            border-left: 4px solid #a855f7;
        }

        /* Cuti form */
        .cuti-type-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }

        .cuti-type {
            background: var(--bg);
            border: 2px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            cursor: pointer;
            text-align: center;
            transition: .15s;
        }

        .cuti-type:hover {
            border-color: var(--gold);
        }

        .cuti-type.selected {
            border-color: var(--gold);
            background: #fffbeb;
        }

        .cuti-type .ct-icon {
            font-size: 20px;
        }

        .cuti-type .ct-label {
            font-size: 11px;
            font-weight: 600;
            margin-top: 2px;
        }

        .leave-status {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .ls-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .ls-approved {
            background: #dcfce7;
            color: #166534;
        }

        .ls-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Riwayat toggle */
        .btn-riwayat {
            width: 100%;
            padding: 12px 16px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            color: var(--navy);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: .15s;
        }

        .btn-riwayat:hover {
            background: var(--bg);
        }

        .btn-riwayat:active {
            transform: scale(.98);
        }

        .btn-riwayat .riwayat-arrow {
            margin-left: auto;
            font-size: 10px;
            transition: transform .2s;
        }

        .btn-riwayat .riwayat-arrow.open {
            transform: rotate(180deg);
        }

        .riwayat-badge {
            background: var(--gold);
            color: var(--navy);
            font-size: 9px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 10px;
        }

        .riwayat-panel {
            display: none;
            background: #fff;
            border: 1px solid var(--border);
            border-top: none;
            border-radius: 0 0 12px 12px;
            padding: 14px 16px;
            margin-top: -1px;
            animation: fadeIn .2s ease;
        }

        .riwayat-panel.open {
            display: block;
        }

        /* Notif popup */
        .notif-popup {
            position: fixed;
            top: 50px;
            right: 10px;
            left: 10px;
            max-width: 360px;
            margin: auto;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .2);
            z-index: 200;
            display: none;
            max-height: 70vh;
            overflow-y: auto;
            border: 1px solid var(--border);
        }

        .notif-popup.open {
            display: block;
        }

        .notif-popup .np-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            font-size: 14px;
            color: var(--navy);
        }

        .notif-popup .np-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
        }

        .notif-popup .np-empty {
            padding: 30px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }

        /* Pages */
        .page {
            display: none;
            padding: 16px;
            animation: fadeIn .2s ease;
        }

        .page.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px)
            }

            to {
                opacity: 1;
                transform: none
            }
        }

        /* Stat Cards */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }

        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 14px;
            border: 1px solid var(--border);
        }

        .stat-card .sl {
            font-size: 10px;
            color: var(--muted);
            font-weight: 600;
            text-transform: uppercase;
        }

        .stat-card .sv {
            font-size: 22px;
            font-weight: 800;
            margin-top: 2px;
        }

        .stat-card .ss {
            font-size: 10px;
            color: var(--muted);
            margin-top: 1px;
        }

        /* Cards */
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid var(--border);
            margin-bottom: 12px;
        }

        .card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 10px;
        }

        /* Slip Gaji rows */
        .slip-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 12px;
            border-bottom: 1px dashed #e2e8f0;
        }

        .slip-row:last-child {
            border-bottom: none;
        }

        .slip-val {
            font-weight: 600;
            font-family: 'SF Mono', Monaco, monospace;
            color: var(--text);
            font-size: 11px;
        }

        .slip-deduct {
            color: #dc2626;
        }

        /* Table */
        .tbl {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            line-height: 1.45;
        }

        .tbl th {
            background: var(--bg);
            padding: 10px 12px;
            text-align: left;
            font-weight: 700;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
        }

        .tbl td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .tbl tr:last-child td {
            border: none;
        }

        /* Badges */
        .badge {
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .b-hadir {
            background: #dcfce7;
            color: #166534;
        }

        .b-late {
            background: #ffedd5;
            color: #9a3412;
        }

        .b-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .b-available {
            background: #dcfce7;
            color: #166534;
        }

        .b-occupied {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Room Grid */
        .room-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
            gap: 6px;
        }

        .room-box {
            padding: 10px 6px;
            border-radius: 8px;
            text-align: center;
            font-weight: 700;
            font-size: 12px;
            border: 1px solid var(--border);
        }

        .room-box.avail {
            background: #f0fdf4;
            color: var(--green);
            border-color: #bbf7d0;
        }

        .room-box.occ {
            background: #fef2f2;
            color: var(--red);
            border-color: #fca5a5;
        }

        .room-box.b2b {
            background: #fef2f2;
            color: var(--red);
            border-color: #fca5a5;
            position: relative;
        }

        .room-box.b2b::after {
            content: 'B2B';
            position: absolute;
            top: -6px;
            right: -6px;
            background: #16a34a;
            color: #fff;
            font-size: 7px;
            font-weight: 700;
            padding: 1px 4px;
            border-radius: 6px;
            line-height: 1.2;
        }

        /* DIRTY (cleaning) — kamar baru selesai checkout, perlu dibersihkan */
        .room-box.dirty {
            background: linear-gradient(135deg, #fef9c3 0%, #fde68a 100%);
            color: #854d0e;
            border-color: #fbbf24;
            position: relative;
            box-shadow: 0 2px 8px rgba(251, 191, 36, 0.25);
        }

        .room-box.dirty::before {
            content: 'DIRTY';
            position: absolute;
            top: -7px;
            left: 50%;
            transform: translateX(-50%);
            background: #d97706;
            color: #fff;
            font-size: 8px;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 2px 8px;
            border-radius: 8px;
            line-height: 1;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
        }

        .room-box.dirty .btn-clean {
            display: inline-block;
            margin-top: 6px;
            background: #16a34a;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 4px 10px;
            font-size: 9px;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            box-shadow: 0 1px 3px rgba(22, 163, 74, 0.35);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .room-box.dirty .btn-clean:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(22, 163, 74, 0.45);
        }

        .room-box.dirty .btn-clean:active {
            transform: translateY(0);
        }

        .room-box .room-type {
            font-size: 8px;
            color: var(--muted);
            font-weight: 400;
            margin-top: 1px;
        }

        .room-box .room-guest {
            font-size: 8px;
            color: var(--red);
            font-weight: 500;
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .room-box .room-next {
            font-size: 7px;
            color: #16a34a;
            font-weight: 600;
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Booking Calendar - Frontdesk Style */
        .cal-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            gap: 6px;
        }

        .cal-nav button {
            background: var(--navy);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: .15s;
        }

        .cal-nav button:active {
            opacity: .7;
            transform: scale(.96);
        }

        .cal-nav .cal-period {
            font-size: 12px;
            font-weight: 700;
            color: var(--navy);
        }

        .cal-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .06);
        }

        .cal-grid {
            display: grid;
            gap: 0;
            width: fit-content;
            min-width: fit-content;
        }

        .cal-grid-header {
            display: contents;
        }

        .cal-grid-footer {
            display: contents;
        }

        /* Header cells */
        .cg-hdr-room {
            background: linear-gradient(135deg, #f1f5f9, #fff);
            border-right: 2px solid #e2e8f0;
            border-bottom: 2px solid #cbd5e1;
            padding: 4px;
            font-weight: 800;
            text-align: center;
            position: sticky;
            left: 0;
            z-index: 40;
            font-size: 9px;
            color: #475569;
            letter-spacing: .8px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 95px;
            max-width: 95px;
            box-shadow: 2px 0 6px rgba(0, 0, 0, .04);
            min-height: 28px;
        }

        .cg-hdr-date {
            background: linear-gradient(180deg, #f8fafc, #f1f5f9);
            border-right: 1px solid #e2e8f0;
            border-bottom: 2px solid #cbd5e1;
            padding: 3px 2px;
            text-align: center;
            font-weight: 700;
            font-size: 9px;
            color: #334155;
            min-width: 130px;
            min-height: 28px;
        }

        .cg-hdr-date.today {
            background: rgba(99, 102, 241, .12) !important;
        }

        .cg-hdr-day {
            font-size: 9px;
            text-transform: uppercase;
            font-weight: 600;
            color: #64748b;
            letter-spacing: .3px;
        }

        .cg-hdr-num {
            font-size: 13px;
            font-weight: 900;
            color: #1e293b;
            margin-left: 2px;
        }

        .cg-hdr-date.today .cg-hdr-num {
            color: #6366f1;
        }

        /* Footer cells */
        .cg-ftr-room {
            background: linear-gradient(135deg, #f1f5f9, #fff);
            border-right: 2px solid #e2e8f0;
            border-top: 2px solid #cbd5e1;
            padding: 4px;
            font-weight: 800;
            text-align: center;
            position: sticky;
            left: 0;
            z-index: 40;
            font-size: 9px;
            color: #475569;
            letter-spacing: .8px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 95px;
            max-width: 95px;
            box-shadow: 2px 0 6px rgba(0, 0, 0, .04);
            min-height: 28px;
        }

        .cg-ftr-date {
            background: linear-gradient(180deg, #f8fafc, #f1f5f9);
            border-right: 1px solid #e2e8f0;
            border-top: 2px solid #cbd5e1;
            padding: 3px 2px;
            text-align: center;
            font-weight: 700;
            font-size: 9px;
            color: #334155;
            min-height: 28px;
        }

        .cg-ftr-date.today {
            background: rgba(99, 102, 241, .12) !important;
        }

        /* Type header row */
        .cg-type-hdr {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            border-right: 2px solid #a5b4fc;
            border-bottom: 1px solid #c7d2fe;
            padding: 3px 6px;
            font-weight: 800;
            color: #4338ca;
            position: sticky;
            left: 0;
            z-index: 30;
            display: flex;
            align-items: center;
            font-size: 10px;
            gap: 4px;
            min-width: 95px;
            max-width: 95px;
            box-shadow: 2px 0 6px rgba(0, 0, 0, .04);
            min-height: 24px;
        }

        .cg-type-price {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            border-right: 1px solid #c7d2fe;
            border-bottom: 1px solid #a5b4fc;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            font-weight: 800;
            color: #4338ca;
            letter-spacing: .3px;
            min-height: 24px;
        }

        /* Room labels */
        .cg-room {
            background: linear-gradient(135deg, #f8fafc, #fff);
            border-right: 2px solid #e2e8f0;
            border-bottom: 1px solid #f1f5f9;
            padding: 2px 4px;
            font-weight: 700;
            color: #334155;
            position: sticky;
            left: 0;
            z-index: 30;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            min-width: 95px;
            max-width: 95px;
            box-shadow: 2px 0 6px rgba(0, 0, 0, .04);
            transition: .15s;
            min-height: 28px;
        }

        .cg-room:hover {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            border-right-color: #a5b4fc;
        }

        .cg-room-type {
            font-size: 8px;
            font-weight: 600;
            color: #6366f1;
            text-transform: uppercase;
            letter-spacing: .5px;
            line-height: 1;
        }

        .cg-room-num {
            font-size: 14px;
            color: #1e293b;
            font-weight: 900;
            line-height: 1;
            letter-spacing: .3px;
        }

        /* Date cells */
        .cg-cell {
            border-right: 1px solid rgba(51, 65, 85, .12);
            border-bottom: 1px solid rgba(51, 65, 85, .12);
            min-width: 130px;
            min-height: 28px;
            position: relative;
            background: transparent;
            cursor: pointer;
        }

        .cg-cell.today {
            background: rgba(99, 102, 241, .05) !important;
        }

        .cg-cell:hover {
            background: rgba(99, 102, 241, .04);
        }

        /* Booking bars - Skewed CloudBed style */
        .bbar-wrap {
            position: absolute;
            top: 2px;
            left: 50%;
            height: 24px;
            display: flex;
            align-items: center;
            overflow: visible;
            z-index: 10;
            margin-left: 4px;
            cursor: pointer;
        }

        .bbar {
            width: 100%;
            height: 22px;
            padding: 0 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .15), 0 1px 2px rgba(0, 0, 0, .1);
            font-weight: 700;
            font-size: 10px;
            line-height: 1.1;
            position: relative;
            border-radius: 3px;
            white-space: nowrap;
            transform: skewX(-20deg);
            color: #fff !important;
            transition: all .2s;
            overflow: hidden;
        }

        .bbar>span {
            transform: skewX(20deg);
            color: #fff !important;
            text-shadow: 0 1px 3px rgba(0, 0, 0, .6);
            font-weight: 800;
            font-size: 10px;
            display: block;
        }

        .bbar::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-top: 10px solid transparent;
            border-bottom: 10px solid transparent;
            border-right: 5px solid;
            border-right-color: inherit;
        }

        .bbar::after {
            content: '';
            position: absolute;
            right: -8px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-top: 10px solid transparent;
            border-bottom: 10px solid transparent;
            border-left: 5px solid;
            border-left-color: inherit;
        }

        .bbar:hover {
            transform: skewX(-20deg) scaleY(1.15);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .3);
            z-index: 20;
        }

        .bbar.s-confirmed {
            background: linear-gradient(135deg, #06b6d4, #22d3ee) !important;
            border-color: #06b6d4;
        }

        .bbar.s-pending {
            background: linear-gradient(135deg, #0ea5e9, #38bdf8) !important;
            border-color: #0ea5e9;
        }

        .bbar.s-checked-in {
            background: linear-gradient(135deg, #16a34a, #22c55e) !important;
            border-color: #16a34a;
        }

        .bbar.s-checked-out {
            background: linear-gradient(135deg, #9ca3af, #d1d5db) !important;
            border-color: #9ca3af;
            opacity: .4;
        }

        .bbar.s-checked-out>span {
            color: #6b7280 !important;
            text-shadow: 0 1px 2px rgba(0, 0, 0, .1) !important;
        }

        .bbar.s-checked-out:hover {
            opacity: .6;
        }

        .bbar::after {
            content: '';
            position: absolute;
            right: -8px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-top: 10px solid transparent;
            border-bottom: 10px solid transparent;
            border-left: 5px solid;
            border-left-color: inherit;
        }

        .bbar:hover {
            transform: skewX(-20deg) scaleY(1.15);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .3);
            z-index: 20;
        }

        .bbar.s-confirmed {
            background: linear-gradient(135deg, #06b6d4, #22d3ee) !important;
            border-color: #06b6d4;
        }

        .bbar.s-pending {
            background: linear-gradient(135deg, #0ea5e9, #38bdf8) !important;
            border-color: #0ea5e9;
        }

        .bbar.s-checked-in {
            background: linear-gradient(135deg, #16a34a, #22c55e) !important;
            border-color: #16a34a;
        }

        .bbar.s-checked-out {
            background: linear-gradient(135deg, #9ca3af, #d1d5db) !important;
            border-color: #9ca3af;
            opacity: .4;
        }

        .bbar.s-checked-out>span {
            color: #6b7280 !important;
            text-shadow: 0 1px 2px rgba(0, 0, 0, .1) !important;
        }

        .bbar.s-checked-out:hover {
            opacity: .6;
        }

        /* Legend */
        .cal-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
            justify-content: center;
        }

        .cal-legend-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 9px;
            color: var(--muted);
            font-weight: 500;
        }

        .cal-legend-dot {
            width: 14px;
            height: 8px;
            border-radius: 3px;
            transform: skewX(-20deg);
        }

        /* Popup */
        .cal-popup {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .3);
            z-index: 1000;
            width: 290px;
            max-width: 90vw;
        }

        .cal-popup-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .4);
            z-index: 999;
        }

        /* Breakfast */
        .bf-order {
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            margin-bottom: 10px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 0 6px 16px rgba(15, 23, 42, .05);
            transition: transform .15s, box-shadow .15s;
            position: relative;
            overflow: hidden;
        }

        .bf-order::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #94a3b8;
        }

        .bf-order:last-child {
            margin-bottom: 0;
        }

        .bf-order:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(15, 23, 42, .08);
        }

        .bf-order.status-pending::before {
            background: #f59e0b;
        }

        .bf-order.status-preparing::before {
            background: #6366f1;
        }

        .bf-order.status-served::before {
            background: #10b981;
        }

        .bf-order.status-completed::before {
            background: #64748b;
        }

        .bf-order-hdr {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
        }

        .bf-head-left {
            min-width: 0;
        }

        .bf-guest {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
            margin-bottom: 3px;
            word-break: break-word;
        }

        .bf-subline {
            font-size: 10px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .bf-subdot {
            width: 4px;
            height: 4px;
            border-radius: 999px;
            background: #94a3b8;
        }

        .bf-status {
            font-size: 10px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: .3px;
            white-space: nowrap;
        }

        .bf-st-pending {
            background: rgba(245, 158, 11, .14);
            color: #b45309;
        }

        .bf-st-prep {
            background: rgba(99, 102, 241, .14);
            color: #4338ca;
        }

        .bf-st-served {
            background: rgba(16, 185, 129, .14);
            color: #047857;
        }

        .bf-st-done {
            background: rgba(100, 116, 139, .16);
            color: #475569;
        }

        .bf-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 8px;
        }

        .bf-chip {
            font-size: 10px;
            font-weight: 700;
            color: #334155;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 3px 8px;
        }

        .bf-menus {
            display: grid;
            gap: 4px;
            margin-bottom: 8px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px;
        }

        .bf-menu-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            font-size: 10px;
        }

        .bf-menu-name {
            color: #1e293b;
            font-weight: 700;
            line-height: 1.25;
            word-break: break-word;
        }

        .bf-menu-qty {
            font-size: 10px;
            font-weight: 600;
            color: #4f46e5;
            background: #eef2ff;
            border-radius: 999px;
            padding: 2px 7px;
            white-space: nowrap;
        }

        .bf-menu-note {
            font-size: 9.5px;
            color: #92400e;
            font-style: italic;
            line-height: 1.3;
            margin-top: 1px;
            word-break: break-word;
        }

        .bf-special {
            font-size: 10px;
            color: #7c3aed;
            background: #f5f3ff;
            border: 1px solid #ddd6fe;
            border-radius: 8px;
            padding: 6px 8px;
            margin-bottom: 8px;
        }

        .bf-foot {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bf-price {
            font-size: 12px;
            font-weight: 800;
            color: #059669;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .bf-empty {
            text-align: center;
            padding: 26px 12px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            background: #f8fafc;
        }

        .bf-empty-emoji {
            font-size: 36px;
            margin-bottom: 6px;
        }

        .bf-empty-text {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
        }

        .bf-complete-btn {
            margin-left: auto;
            border: none;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .2px;
            color: #fff;
            background: linear-gradient(135deg, #16a34a, #15803d);
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(22, 163, 74, .22);
        }

        .bf-complete-btn:disabled {
            opacity: .55;
            cursor: not-allowed;
            background: #9ca3af;
        }

        /* Absen buttons — Face Scan & Absen Manual side by side, same size */
        .absen-btns-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 14px;
        }

        .absen-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 128px;
            background: linear-gradient(135deg, var(--navy), var(--navy2));
            color: #fff;
            text-decoration: none;
            border: none;
            border-radius: 16px;
            padding: 18px 10px;
            text-align: center;
            cursor: pointer;
            font-family: inherit;
            position: relative;
            overflow: hidden;
            transition: transform .15s;
        }

        .absen-link:active {
            transform: scale(.98);
        }

        .absen-link::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(255, 255, 255, .1), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .absen-link .al-icon {
            margin-bottom: 8px;
        }

        .absen-link .al-icon svg {
            width: 38px;
            height: 38px;
        }

        .absen-link .al-title {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .2px;
        }

        .absen-link .al-sub {
            font-size: 10px;
            color: rgba(255, 255, 255, .7);
            margin-top: 4px;
            line-height: 1.3;
        }

        /* Absen Manual — same size as Face Scan, distinct accent color so staff
           immediately see this is also an attendance action */
        .absen-link-manual {
            background: linear-gradient(135deg, var(--orange), #f97316);
        }

        /* Manual Attendance popup */
        .manual-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 999;
        }

        .manual-popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .3);
            z-index: 1000;
            width: 300px;
            max-width: 90vw;
            text-align: center;
        }

        .manual-popup h3 {
            margin: 0 0 6px;
            font-size: 15px;
            color: var(--navy);
        }

        .manual-popup p {
            margin: 0 0 14px;
            font-size: 11.5px;
            color: var(--muted);
            line-height: 1.5;
        }

        .manual-popup .mp-status {
            font-size: 12px;
            font-weight: 700;
            padding: 10px;
            border-radius: 10px;
            background: var(--bg);
            color: var(--text);
            margin-bottom: 14px;
            min-height: 36px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            line-height: 1.4;
        }

        .manual-popup .mp-actions {
            display: flex;
            gap: 8px;
        }

        .manual-popup .mp-actions button {
            flex: 1;
            padding: 10px;
            border-radius: 10px;
            border: none;
            font-weight: 700;
            font-size: 12.5px;
            cursor: pointer;
        }

        .manual-popup .mp-cancel {
            background: var(--bg);
            color: var(--text);
        }

        .manual-popup .mp-confirm {
            background: var(--navy);
            color: #fff;
        }

        .manual-popup .mp-confirm:disabled {
            background: #cbd5e1;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .manual-popup .mp-confirm.mp-confirm-retry {
            background: var(--gold);
            color: #1e293b;
        }

        /* ── Face Scan Overlay — Full-Screen Responsive (from absen.php) ── */
        .face-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: #000;
            z-index: 1000;
            overflow: hidden;
        }

        .face-overlay.show {
            display: block;
            animation: faceIn .3s ease;
        }

        @keyframes faceIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .face-topbar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            background: linear-gradient(to bottom, rgba(0, 0, 0, 0.8) 0%, rgba(0, 0, 0, 0.4) 60%, transparent 100%);
        }

        .btn-face-back {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #fff;
            padding: 8px 16px;
            border-radius: 24px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-face-back:active {
            transform: scale(0.95);
            background: rgba(255, 255, 255, 0.2);
        }

        .face-emp-badge {
            background: rgba(0, 200, 150, 0.12);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 200, 150, 0.25);
            border-radius: 24px;
            padding: 6px 14px;
            color: #00c896;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        #faceVideo {
            width: 100%;
            height: 100vh;
            object-fit: cover;
            transform: scaleX(-1);
        }

        #faceCanvas {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 5;
            display: none;
        }

        /* Face Scan Overlay */
        .face-scan-overlay {
            position: absolute;
            inset: 0;
            z-index: 6;
            pointer-events: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        /* Animated scanning ring */
        .face-ring-container {
            position: relative;
            width: 240px;
            height: 240px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .face-ring-outer {
            position: absolute;
            inset: -12px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.06);
            animation: faceRingPulse 3s ease-in-out infinite;
        }

        .face-ring-main {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            overflow: hidden;
        }

        .face-ring-main svg {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }

        .face-ring-main svg circle {
            fill: none;
            stroke-width: 3;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.3s ease, stroke 0.3s ease;
        }

        .face-ring-track {
            stroke: rgba(255, 255, 255, 0.1);
        }

        .face-ring-progress {
            stroke: url(#ringGrad);
            stroke-dasharray: 754;
            stroke-dashoffset: 754;
            filter: drop-shadow(0 0 6px rgba(0, 200, 150, 0.4));
        }

        .face-ring-inner {
            position: absolute;
            inset: 12px;
            border-radius: 50%;
            border: 1.5px dashed rgba(255, 255, 255, 0.12);
            animation: faceRingSpin 12s linear infinite;
        }

        /* Scanning beam */
        .face-scan-beam {
            position: absolute;
            width: 200px;
            height: 3px;
            background: linear-gradient(90deg, transparent, rgba(0, 200, 150, 0.6), transparent);
            border-radius: 2px;
            filter: blur(1px);
            animation: scanBeam 2s ease-in-out infinite;
            opacity: 0;
        }

        .face-scan-beam.active {
            opacity: 1;
        }

        /* Corner markers */
        .face-corners {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }

        .face-corner {
            position: absolute;
            width: 28px;
            height: 28px;
            border-color: rgba(255, 255, 255, 0.4);
            border-style: solid;
            border-width: 0;
            transition: border-color 0.3s;
        }

        .face-corner.tl {
            top: 0;
            left: 0;
            border-top-width: 3px;
            border-left-width: 3px;
            border-top-left-radius: 12px;
        }

        .face-corner.tr {
            top: 0;
            right: 0;
            border-top-width: 3px;
            border-right-width: 3px;
            border-top-right-radius: 12px;
        }

        .face-corner.bl {
            bottom: 0;
            left: 0;
            border-bottom-width: 3px;
            border-left-width: 3px;
            border-bottom-left-radius: 12px;
        }

        .face-corner.br {
            bottom: 0;
            right: 0;
            border-bottom-width: 3px;
            border-right-width: 3px;
            border-bottom-right-radius: 12px;
        }

        .face-corner.detected {
            border-color: #00c896;
        }

        .face-corner.matched {
            border-color: #00c896;
            filter: drop-shadow(0 0 4px rgba(0, 200, 150, 0.5));
        }

        /* Status HUD */
        .face-hud {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.95) 0%, rgba(0, 0, 0, 0.7) 60%, transparent 100%);
            padding: 0 20px 36px;
            text-align: center;
            z-index: 8;
        }

        .face-hud-status {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 6px;
            min-height: 24px;
            letter-spacing: 0.2px;
        }

        .face-hud-sub {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.45);
            margin-bottom: 16px;
            min-height: 16px;
        }

        /* Confidence arc meter */
        .confidence-arc-wrap {
            width: 200px;
            height: 28px;
            margin: 0 auto 6px;
            position: relative;
        }

        .confidence-arc-wrap svg {
            width: 100%;
            height: 100%;
        }

        .conf-track {
            fill: none;
            stroke: rgba(255, 255, 255, 0.08);
            stroke-width: 5;
            stroke-linecap: round;
        }

        .conf-fill {
            fill: none;
            stroke-width: 5;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.25s ease, stroke 0.25s ease;
            filter: drop-shadow(0 0 4px rgba(0, 200, 150, 0.3));
        }

        .confidence-label {
            color: rgba(255, 255, 255, 0.6);
            font-size: 12px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 14px;
            min-height: 16px;
        }

        /* Multi-frame indicator dots */
        .frame-dots {
            display: flex;
            gap: 6px;
            justify-content: center;
            margin-bottom: 16px;
        }

        .frame-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            transition: all 0.3s;
        }

        .frame-dot.filled {
            background: #00c896;
            box-shadow: 0 0 8px rgba(0, 200, 150, 0.5);
        }

        /* Register mode card */
        .face-register-card {
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 16px 20px;
            margin: 0 auto;
            max-width: 300px;
        }

        .face-register-card p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 12px;
            line-height: 1.5;
            margin-bottom: 12px;
        }

        .btn-register-face {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #00c896, #00a67a);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.15s;
            letter-spacing: 0.3px;
        }

        .btn-register-face:active {
            transform: scale(0.97);
        }

        .btn-register-face:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Re-register button */
        .btn-face-reregister {
            pointer-events: auto;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: rgba(255, 255, 255, 0.6);
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: none;
            margin: 0 auto;
        }

        .btn-face-reregister:active {
            transform: scale(0.95);
            background: rgba(255, 255, 255, 0.15);
        }

        /* Verified checkmark overlay */
        .face-verified-overlay {
            position: absolute;
            inset: 0;
            z-index: 20;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .face-verified-overlay.show {
            display: flex;
            animation: fadeInUp 0.4s ease;
        }

        .verified-ring {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(0, 200, 150, 0.15);
            border: 3px solid #00c896;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: verifiedPop 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .verified-check {
            width: 44px;
            height: 44px;
            fill: none;
            stroke: #00c896;
            stroke-width: 3;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .verified-check path {
            stroke-dasharray: 48;
            stroke-dashoffset: 48;
            animation: drawCheck 0.5s 0.3s ease forwards;
        }

        .verified-name {
            color: #fff;
            font-size: 18px;
            font-weight: 800;
            margin-top: 16px;
            letter-spacing: 0.3px;
        }

        .verified-sub {
            color: rgba(255, 255, 255, 0.5);
            font-size: 13px;
            margin-top: 4px;
        }

        .face-gps-info {
            color: rgba(255, 255, 255, 0.4);
            font-size: 10px;
            text-align: center;
            min-height: 14px;
            margin-top: 8px;
        }

        @keyframes faceRingPulse {

            0%,
            100% {
                transform: scale(1);
                opacity: 0.5;
            }

            50% {
                transform: scale(1.04);
                opacity: 0.8;
            }
        }

        @keyframes faceRingSpin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes scanBeam {
            0% {
                top: 30px;
                opacity: 0;
            }

            10% {
                opacity: 1;
            }

            90% {
                opacity: 1;
            }

            100% {
                top: calc(100% - 30px);
                opacity: 0;
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes verifiedPop {
            from {
                transform: scale(0.5);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        @keyframes drawCheck {
            to {
                stroke-dashoffset: 0;
            }
        }

        /* Loading */
        .loading {
            text-align: center;
            padding: 30px;
            color: var(--muted);
            font-size: 12px;
        }

        .spin {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-top-color: var(--gold);
            border-radius: 50%;
            animation: spin .6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Progress bar */
        .progress {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }

        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width .3s;
        }

        @media(min-width:500px) {
            .stat-row {
                grid-template-columns: repeat(4, 1fr);
            }

            .room-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            }
        }
    </style>
</head>

<body>

    <!-- ═══════════════════════════════════════ -->
    <!-- AUTH SCREEN                            -->
    <!-- ═══════════════════════════════════════ -->
    <div class="auth-wrap" id="authScreen">
        <div class="auth-card">
            <div class="auth-logo">
                <?php
                $displayLogo = $appLogo ?: (strpos($pwaIconUrl, 'absen-icon.php') === false ? $pwaIconUrl : null);
                if ($displayLogo): ?><img src="<?php echo htmlspecialchars($displayLogo); ?>" alt="Logo"><?php endif; ?>
                <h1><?php echo $bizName; ?></h1>
                <p>Staff Portal</p>
            </div>

            <div class="auth-tabs">
                <button class="auth-tab active" onclick="switchAuth('login')">Login</button>
                <button class="auth-tab" onclick="switchAuth('register')">Daftar</button>
                <button class="auth-tab" onclick="switchAuth('changepw')">Ubah Password</button>
            </div>

            <div id="authMsg" class="auth-msg"></div>

            <!-- Login Form -->
            <form class="auth-form active" id="loginForm" onsubmit="return handleLogin(event)">
                <div class="fg">
                    <label class="fl">Username / Email</label>
                    <input type="text" class="fi" name="email" placeholder="nama atau email" required id="loginEmail">
                </div>
                <div class="fg">
                    <label class="fl">Password</label>
                    <div class="pw-wrap">
                        <input type="password" class="fi" name="password" placeholder="••••••" required id="loginPass">
                        <button type="button" class="pw-toggle" onclick="togglePw('loginPass',this)">👁️</button>
                    </div>
                </div>
                <div class="remember-row">
                    <input type="checkbox" id="rememberMe" checked>
                    <label for="rememberMe">Simpan login</label>
                </div>
                <button type="submit" class="btn-auth" id="loginBtn">🔐 Login</button>
            </form>

            <!-- Register Form -->
            <form class="auth-form" id="registerForm" onsubmit="return handleRegister(event)">
                <div class="fg">
                    <label class="fl">Nomor Karyawan</label>
                    <input type="number" class="fi" name="employee_code" placeholder="1" min="1" required style="font-size:18px; text-align:center; letter-spacing:2px;">
                    <div class="fi-hint">Masukkan angka saja, misal: 1, 2, 3 (lihat di slip gaji)</div>
                </div>
                <div class="fg">
                    <label class="fl">Username / Email</label>
                    <input type="text" class="fi" name="email" placeholder="nama atau email" required>
                </div>
                <div class="fg">
                    <label class="fl">Password</label>
                    <div class="pw-wrap">
                        <input type="password" class="fi" name="password" placeholder="Min 6 karakter" minlength="6" required id="regPass">
                        <button type="button" class="pw-toggle" onclick="togglePw('regPass',this)">👁️</button>
                    </div>
                </div>
                <div class="remember-row">
                    <input type="checkbox" id="rememberReg" checked>
                    <label for="rememberReg">Simpan password</label>
                </div>
                <button type="submit" class="btn-auth" id="regBtn">📝 Daftar</button>
            </form>

            <!-- Change Password Form -->
            <form class="auth-form" id="changepwForm" onsubmit="return handleChangePw(event)">
                <div style="text-align:center;margin-bottom:16px;">
                    <div style="font-size:32px;margin-bottom:6px;">🔑</div>
                    <div style="font-size:12px;color:#64748b;">Masukkan username dan password lama, lalu buat password baru</div>
                </div>
                <div class="fg">
                    <label class="fl">Username / Email</label>
                    <input type="text" class="fi" name="email" placeholder="nama atau email" required id="cpEmail">
                </div>
                <div class="fg">
                    <label class="fl">Password Lama</label>
                    <div class="pw-wrap">
                        <input type="password" class="fi" name="old_password" placeholder="Password saat ini" required id="cpOldPass">
                        <button type="button" class="pw-toggle" onclick="togglePw('cpOldPass',this)">👁️</button>
                    </div>
                </div>
                <div class="fg">
                    <label class="fl">Password Baru</label>
                    <div class="pw-wrap">
                        <input type="password" class="fi" name="new_password" placeholder="Min 6 karakter" minlength="6" required id="cpNewPass">
                        <button type="button" class="pw-toggle" onclick="togglePw('cpNewPass',this)">👁️</button>
                    </div>
                </div>
                <div class="fg">
                    <label class="fl">Konfirmasi Password Baru</label>
                    <div class="pw-wrap">
                        <input type="password" class="fi" name="confirm_password" placeholder="Ulangi password baru" minlength="6" required id="cpConfirmPass">
                        <button type="button" class="pw-toggle" onclick="togglePw('cpConfirmPass',this)">👁️</button>
                    </div>
                </div>
                <button type="submit" class="btn-auth" id="cpBtn">🔑 Ubah Password</button>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!-- APP SHELL (after login)               -->
    <!-- ═══════════════════════════════════════ -->
    <div class="app-wrap" id="appShell">
        <!-- Header -->
        <div class="app-header">
            <?php
            $headerLogo = $appLogo ?: (strpos($pwaIconUrl, 'absen-icon.php') === false ? $pwaIconUrl : null);
            if ($headerLogo): ?><img src="<?php echo htmlspecialchars($headerLogo); ?>" class="logo"><?php endif; ?>
            <span class="title"><?php echo $bizName; ?></span>
            <div class="notif-bell" onclick="toggleNotifs()">
                🔔
                <div class="notif-dot" id="notifDot"></div>
            </div>
            <span class="user-badge" id="headerName">Staff</span>
            <button class="logout-btn" onclick="doLogout()">Keluar</button>
        </div>

        <!-- Notification Popup -->
        <div class="notif-popup" id="notifPopup">
            <div class="np-head">🔔 Notifikasi</div>
            <div id="notifList">
                <div class="np-empty">Memuat...</div>
            </div>
        </div>

        <!-- ═══ PAGE: HOME (Absen + Monitoring + Cuti) ═══ -->
        <div class="page active" id="page-home">

            <!-- iPhone Guide -->
            <div class="install-guide" id="iosGuide" style="display:none;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <span style="font-size:20px;">🍎</span>
                    <div style="font-weight:700;font-size:13px;color:var(--navy);">Install di iPhone / iPad</div>
                    <button style="margin-left:auto;background:none;border:none;font-size:16px;cursor:pointer;color:var(--muted);" onclick="this.parentElement.parentElement.style.display='none';localStorage.setItem('ios_guide_dismissed','1');">✕</button>
                </div>
                <div style="font-size:11px;color:var(--text);line-height:1.6;">
                    <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px;">
                        <span style="background:var(--bg);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">1</span>
                        <span>Tap tombol <strong style="background:#e5e7eb;padding:1px 6px;border-radius:4px;">⬆️ Share</strong> di bagian bawah Safari</span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px;">
                        <span style="background:var(--bg);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">2</span>
                        <span>Scroll ke bawah, pilih <strong style="background:#e5e7eb;padding:1px 6px;border-radius:4px;">➕ Add to Home Screen</strong></span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:8px;">
                        <span style="background:var(--bg);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">3</span>
                        <span>Tap <strong style="background:var(--gold);color:var(--navy);padding:1px 6px;border-radius:4px;">Add</strong> — icon akan muncul di home screen</span>
                    </div>
                </div>
            </div>

            <!-- Absen: Face Scan & Absen Manual side by side, same size, different colors -->
            <div class="absen-btns-row">
                <!-- Scan Wajah -->
                <div class="absen-link" onclick="openFaceScan()">
                    <div class="al-icon"><svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6 20v-7a7 7 0 0 1 7-7h7" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M58 20v-7a7 7 0 0 0-7-7h-7" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M6 44v7a7 7 0 0 0 7 7h7" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M58 44v7a7 7 0 0 1-7 7h-7" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                            <circle cx="24" cy="28" r="2.8" fill="white" />
                            <circle cx="40" cy="28" r="2.8" fill="white" />
                            <path d="M32 25v7.5a2 2 0 0 1-2 2" stroke="white" stroke-width="2.2" stroke-linecap="round" />
                            <path d="M22.5 40.5c2.6 2.3 5.9 3.5 9.5 3.5s6.9-1.2 9.5-3.5" stroke="white" stroke-width="2.4" stroke-linecap="round" />
                        </svg></div>
                    <div class="al-title">Face Scan</div>
                    <div class="al-sub">Absen otomatis</div>
                </div>

                <!-- Absen Manual (fallback jika Face ID lambat/gagal) -->
                <button type="button" class="absen-link absen-link-manual" onclick="openManualAttendance()">
                    <div class="al-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="38" height="38">
                            <path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"></path>
                            <path d="M9.25 11.75l1.9 1.9 3.6-3.9"></path>
                        </svg>
                    </div>
                    <div class="al-title">Absen Manual</div>
                    <div class="al-sub">Fallback / GPS</div>
                </button>
            </div>

            <!-- Status Hari Ini -->
            <div class="card">
                <div class="card-title">📋 Status Absen Hari Ini</div>
                <div id="todayStatus">
                    <div class="loading"><span class="spin"></span> Memuat...</div>
                </div>
            </div>

            <!-- Target Jam - Donut Chart -->
            <div class="card">
                <div class="card-title">📊 Target Jam Bulan Ini</div>
                <div id="monthlySummary">
                    <div class="loading"><span class="spin"></span> Memuat...</div>
                </div>
            </div>

            <!-- Ajukan Lembur -->
            <div class="card">
                <div class="card-title">⏰ Ajukan Lembur</div>
                <form id="lemburForm" onsubmit="return submitLembur(event)">
                    <div style="margin-bottom:10px;">
                        <label class="fl">Tanggal Lembur</label>
                        <input type="date" class="fi" name="overtime_date" required id="lemburDate" max="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div style="margin-bottom:12px;">
                        <label class="fl">Keterangan (Alasan Lembur)</label>
                        <textarea class="fi" name="reason" rows="2" placeholder="Jelaskan alasan dan pekerjaan lembur..." required style="resize:vertical;" id="lemburReason"></textarea>
                    </div>
                    <button type="submit" class="btn-auth" id="lemburBtn" style="border-radius:10px;background:linear-gradient(135deg,#f59e0b,#d97706);">⏰ Ajukan Lembur</button>
                </form>
            </div>

            <!-- Riwayat Lembur (collapsed) -->
            <div style="margin-bottom:12px;">
                <button onclick="toggleRiwayat('lembur')" class="btn-riwayat" id="btnRiwayatLembur">📋 Riwayat Lembur <span id="lemburBadge" style="display:none;" class="riwayat-badge"></span> <span class="riwayat-arrow" id="arrowLembur">▼</span></button>
                <div id="panelRiwayatLembur" class="riwayat-panel">
                    <div id="lemburStats" style="margin-bottom:10px;"></div>
                    <div id="lemburHistory">
                        <div class="loading"><span class="spin"></span> Memuat...</div>
                    </div>
                </div>
            </div>

            <!-- Monitoring Detail -->
            <div class="card">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; flex-wrap:wrap; gap:6px;">
                    <div class="card-title" style="margin:0;">📅 Detail Absensi & Lembur</div>
                    <input type="month" id="monitorMonth" class="fi" style="width:140px;padding:5px 8px;font-size:11px;" value="<?php echo date('Y-m'); ?>" onchange="onMonitorMonthChange()">
                </div>
                <div style="display:flex; gap:6px; margin-bottom:10px; flex-wrap:wrap;">
                    <button type="button" onclick="shiftMonitorMonth(-1)" style="flex:1;min-width:90px;background:linear-gradient(135deg,#475569,#334155);color:#fff;border:none;padding:8px 10px;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer;">← Bulan Lalu</button>
                    <button type="button" onclick="shiftMonitorMonth(0)" style="background:#e2e8f0;color:#0f172a;border:none;padding:8px 12px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;">Bulan Ini</button>
                    <button type="button" onclick="shiftMonitorMonth(1)" style="flex:1;min-width:90px;background:linear-gradient(135deg,#475569,#334155);color:#fff;border:none;padding:8px 10px;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer;">Bulan Depan →</button>
                </div>
                <div id="monitorStats"></div>
                <div id="monitorTable">
                    <div class="loading"><span class="spin"></span> Memuat...</div>
                </div>
                <div style="margin-top:10px; padding-top:10px; border-top:1px dashed var(--border);">
                    <div style="font-size:11px; color:var(--muted); font-weight:600; margin-bottom:6px;">📋 Pengajuan Lembur Bulan Ini</div>
                    <div id="monitorLemburStats" style="margin-bottom:8px;"></div>
                    <div id="monitorLemburHistory">
                        <div class="loading"><span class="spin"></span> Memuat...</div>
                    </div>
                </div>
                <button type="button" onclick="openSlipForMonitorMonth()" style="margin-top:10px;width:100%;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;padding:10px 12px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;">💰 Lihat Slip Gaji Bulan Ini</button>
            </div>

            <!-- Ajukan Cuti -->
            <div class="card">
                <div class="card-title">🏖️ Ajukan Cuti / Izin</div>
                <form id="cutiForm" onsubmit="return submitCuti(event)">
                    <div style="margin-bottom:10px;">
                        <div class="cuti-type-grid">
                            <div class="cuti-type selected" data-type="cuti" onclick="selectCutiType(this)">
                                <div class="ct-icon">🏖️</div>
                                <div class="ct-label">Cuti</div>
                            </div>
                            <div class="cuti-type" data-type="sakit" onclick="selectCutiType(this)">
                                <div class="ct-icon">🩺</div>
                                <div class="ct-label">Sakit</div>
                            </div>
                            <div class="cuti-type" data-type="izin" onclick="selectCutiType(this)">
                                <div class="ct-icon">📋</div>
                                <div class="ct-label">Izin</div>
                            </div>
                            <div class="cuti-type" data-type="cuti_khusus" onclick="selectCutiType(this)">
                                <div class="ct-icon">⭐</div>
                                <div class="ct-label">Khusus</div>
                            </div>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:10px;">
                        <div>
                            <label class="fl">Tanggal Mulai</label>
                            <input type="date" class="fi" name="start_date" required id="cutiStart" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label class="fl">Tanggal Selesai</label>
                            <input type="date" class="fi" name="end_date" required id="cutiEnd" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label class="fl">Alasan</label>
                        <textarea class="fi" name="reason" rows="2" placeholder="Jelaskan alasan cuti/izin..." required style="resize:vertical;"></textarea>
                    </div>
                    <button type="submit" class="btn-auth" id="cutiBtn" style="border-radius:10px;">📨 Kirim Pengajuan</button>
                </form>
            </div>

            <!-- Riwayat Cuti (collapsed) -->
            <div style="margin-bottom:12px;">
                <button onclick="toggleRiwayat('cuti')" class="btn-riwayat" id="btnRiwayatCuti">📅 Riwayat Cuti <span id="cutiBadge" style="display:none;" class="riwayat-badge"></span> <span class="riwayat-arrow" id="arrowCuti">▼</span></button>
                <div id="panelRiwayatCuti" class="riwayat-panel">
                    <div id="cutiStats" style="margin-bottom:10px;"></div>
                    <div id="cutiHistory">
                        <div class="loading"><span class="spin"></span> Memuat...</div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ═══ PAGE: ROOM (Hotel only) ═══ -->
        <?php if ($isHotel): ?>
            <div class="page" id="page-occupancy">
                <div id="occStats">
                    <div class="loading"><span class="spin"></span> Memuat...</div>
                </div>
                <div class="card">
                    <div class="card-title">🏨 Status Kamar</div>
                    <div id="roomGrid">
                        <div class="loading"><span class="spin"></span> Memuat...</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title">📅 Booking Calendar</div>
                    <div class="cal-nav">
                        <button onclick="calNav(-14)">◀ Prev</button>
                        <span class="cal-period" id="calPeriod"></span>
                        <button onclick="calNav(14)">Next ▶</button>
                    </div>
                    <div class="cal-scroll" id="calScroll">
                        <div id="calGrid">
                            <div class="loading"><span class="spin"></span> Memuat...</div>
                        </div>
                    </div>
                    <div class="cal-legend">
                        <div class="cal-legend-item">
                            <div class="cal-legend-dot" style="background:#06b6d4;"></div>Confirmed
                        </div>
                        <div class="cal-legend-item">
                            <div class="cal-legend-dot" style="background:#0ea5e9;"></div>Pending
                        </div>
                        <div class="cal-legend-item">
                            <div class="cal-legend-dot" style="background:#16a34a;"></div>Checked In
                        </div>
                        <div class="cal-legend-item">
                            <div class="cal-legend-dot" style="background:#9ca3af;opacity:.4;"></div>Checked Out
                        </div>
                    </div>
                </div>
            </div>
            <div id="calPopupOverlay" class="cal-popup-overlay" style="display:none;" onclick="closeCalPopup()"></div>
            <div id="calPopup" class="cal-popup" style="display:none;"></div>

            <!-- ═══ PAGE: BREAKFAST (Hotel only) ═══ -->
            <div class="page" id="page-breakfast">
                <div id="bfStats">
                    <div class="loading"><span class="spin"></span> Memuat...</div>
                </div>
                <div class="card" id="bfRecapCard" style="display:none;">
                    <div class="card-title" style="margin:0;">📋 Rekap Total per Menu <span style="font-weight:400;color:var(--muted,#888);font-size:11px;">(untuk kitchen)</span></div>
                    <div id="bfMenuRecap" style="margin-top:8px;"></div>
                </div>
                <div class="card">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div class="card-title" style="margin:0;">☕ Today's Breakfast Orders</div>
                        <button onclick="loadBreakfast()" style="background:none;border:none;font-size:14px;cursor:pointer;" title="Refresh">🔄</button>
                    </div>
                    <div id="bfOrderList" style="margin-top:10px;">
                        <div class="loading"><span class="spin"></span></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isCafe): ?>
            <!-- ═══ PAGE: JADWAL (Cafe only) ═══ -->
            <div class="page" id="page-schedule">
                <div class="card">
                    <div class="card-title">⏰ Jadwal Kerja Saya</div>
                    <div id="scheduleInfo">
                        <div class="loading"><span class="spin"></span> Memuat...</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title">📊 Ketepatan Waktu Bulan Ini</div>
                    <div id="punctualityStats">
                        <div class="loading"><span class="spin"></span> Memuat...</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ═══ SLIP GAJI PAGE ═══ -->
        <div class="page" id="page-slipgaji">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding:0 2px;">
                <select id="slipPeriod" class="fi" style="width:auto;padding:6px 10px;font-size:11px;border-radius:8px;" onchange="loadSlipGaji()">
                    <option value="">Memuat...</option>
                </select>
                <button id="btnDownloadSlip" onclick="downloadSlipGaji()" style="display:none;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;padding:7px 14px;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer;display:none;align-items:center;gap:5px;">📥 Download</button>
            </div>
            <div id="slipGajiContent">
                <div class="loading"><span class="spin"></span> Memuat...</div>
            </div>
        </div>

        <!-- Bottom Navigation -->
        <div class="bottom-nav">
            <div class="nav-item active" data-page="home"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></div>
            <?php if ($isHotel): ?>
                <div class="nav-item" data-page="occupancy"><span class="nav-icon">🏨</span><span class="nav-label">Room Monitor</span></div>
                <div class="nav-item" data-page="breakfast"><span class="nav-icon">☕</span><span class="nav-label">Breakfast</span></div>
            <?php elseif ($isCafe): ?>
                <div class="nav-item" data-page="schedule"><span class="nav-icon">⏰</span><span class="nav-label">Jadwal</span></div>
            <?php endif; ?>
            <div class="nav-item" data-page="slipgaji"><span class="nav-icon">💰</span><span class="nav-label">Slip Gaji</span></div>
        </div>
    </div>

    <!-- Face Scan Overlay — Full-Screen Responsive -->
    <div class="face-overlay" id="faceOverlay">
        <div class="face-topbar">
            <button class="btn-face-back" onclick="closeFaceScan()">← Kembali</button>
            <div class="face-emp-badge" id="faceEmpBadge">...</div>
        </div>
        <video id="faceVideo" autoplay muted playsinline></video>
        <canvas id="faceCanvas"></canvas>

        <!-- Scanning overlay -->
        <div class="face-scan-overlay">
            <div class="face-ring-container" id="faceRingContainer">
                <div class="face-ring-outer"></div>
                <div class="face-ring-main">
                    <svg viewBox="0 0 240 240">
                        <defs>
                            <linearGradient id="ringGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#00c896" />
                                <stop offset="50%" stop-color="#00e5a0" />
                                <stop offset="100%" stop-color="#00c896" />
                            </linearGradient>
                            <linearGradient id="ringFail" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#ef4444" />
                                <stop offset="100%" stop-color="#f97316" />
                            </linearGradient>
                        </defs>
                        <circle class="face-ring-track" cx="120" cy="120" r="116" />
                        <circle class="face-ring-progress" id="ringProgress" cx="120" cy="120" r="116" />
                    </svg>
                </div>
                <div class="face-ring-inner"></div>
                <div class="face-corners">
                    <div class="face-corner tl" id="fc_tl"></div>
                    <div class="face-corner tr" id="fc_tr"></div>
                    <div class="face-corner bl" id="fc_bl"></div>
                    <div class="face-corner br" id="fc_br"></div>
                </div>
                <div class="face-scan-beam" id="scanBeam"></div>
            </div>
        </div>

        <!-- Status HUD -->
        <div class="face-hud">
            <div class="face-hud-status" id="faceStatus">Mendeteksi wajah...</div>
            <div class="face-hud-sub" id="faceStatusSub"></div>

            <!-- Confidence arc (verify mode) -->
            <div id="confidenceWrap" style="display:none;">
                <div class="confidence-arc-wrap">
                    <svg viewBox="0 0 200 28">
                        <path class="conf-track" d="M 10 24 Q 100 -4 190 24" />
                        <path class="conf-fill" id="confFill" d="M 10 24 Q 100 -4 190 24" stroke-dasharray="210" stroke-dashoffset="210" stroke="#00c896" />
                    </svg>
                </div>
                <div class="confidence-label" id="confLabel"></div>
                <div class="frame-dots" id="frameDots">
                    <div class="frame-dot" id="fd0"></div>
                    <div class="frame-dot" id="fd1"></div>
                    <div class="frame-dot" id="fd2"></div>
                </div>
            </div>

            <!-- Register mode card -->
            <div id="registerCard" style="display:none;">
                <div class="face-register-card">
                    <p>Wajah belum terdaftar. Arahkan wajah ke kamera lalu tekan tombol di bawah.</p>
                    <button class="btn-register-face" id="btnFaceRegister" onclick="registerFace()">Daftarkan Wajah</button>
                </div>
            </div>

            <!-- Re-register button -->
            <button class="btn-face-reregister" id="btnFaceReregister" onclick="reregisterFace()">🔄 Daftar Ulang Wajah</button>

            <div class="face-gps-info" id="faceGpsInfo"></div>
        </div>

        <!-- Verified overlay -->
        <div class="face-verified-overlay" id="verifiedOverlay">
            <div class="verified-ring">
                <svg class="verified-check" viewBox="0 0 44 44">
                    <path d="M12 22 L19 29 L32 15" />
                </svg>
            </div>
            <div class="verified-name" id="verifiedName"></div>
            <div class="verified-sub" id="verifiedSub">Identitas Terverifikasi</div>
        </div>
    </div>

    <!-- Absen Manual popup -->
    <div class="manual-overlay" id="manualOverlay" onclick="closeManualAttendance()"></div>
    <div class="manual-popup" id="manualPopup">
        <h3>📍 Absen Manual</h3>
        <p>Fallback jika Face ID lambat/gagal. Anda tetap wajib berada dalam radius lokasi kerja yang sudah diatur.</p>
        <div class="mp-status" id="manualStatus">Mencari lokasi GPS...</div>
        <div class="mp-actions">
            <button class="mp-cancel" onclick="closeManualAttendance()">Batal</button>
            <button class="mp-confirm" id="manualConfirmBtn" disabled onclick="confirmManualAttendance()">Konfirmasi Absen</button>
        </div>
    </div>

    <!-- Install Progress Overlay -->
    <div class="install-progress" id="installProgress">
        <img class="ip-icon" id="ipIcon" src="<?php echo htmlspecialchars($pwaIconUrl); ?>" alt="App">
        <div class="ip-title"><?php echo $bizName; ?></div>
        <div class="ip-sub" id="ipSub">Installing Staff Portal...</div>
        <div class="ip-bar">
            <div class="ip-bar-fill" id="ipBarFill"></div>
        </div>
        <div class="ip-step" id="ipStep">Preparing...</div>
        <div class="ip-done" id="ipDone">
            <div class="ip-check">✓</div>
            <div class="ip-done-text">Terinstall!</div>
            <div class="ip-done-sub">Cek home screen atau daftar aplikasi (app drawer)</div>
            <div class="ip-done-sub" style="margin-top:6px;font-size:10px;opacity:.5;">Jika tidak muncul di home screen, swipe up → cari "Staff Portal"</div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/dist/face-api.min.js"></script>
    <script>
        const API = '<?php echo $apiUrl; ?>';
        const CRED_KEY = 'staff_saved_cred_<?php echo md5($bizSlug); ?>';
        const FACE_MODEL_URL = '<?php echo $baseUrl; ?>/assets/face-weights';
        const FACE_MODEL_CDN = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
        const BIZ_TYPE = '<?php echo $bizType; ?>';
        const IS_HOTEL = <?php echo $isHotel ? 'true' : 'false'; ?>;
        const IS_CAFE = <?php echo $isCafe ? 'true' : 'false'; ?>;
        const LOGO_URL = '<?php echo $appLogo ?: ''; ?>';
        const SLIP_LOGO_URL = '<?php echo $slipLogo ?: ($appLogo ?: ''); ?>';
        const BIZ_NAME = '<?php echo $bizName; ?>';

        // ═══ PASSWORD TOGGLE ═══
        function togglePw(inputId, btn) {
            const inp = document.getElementById(inputId);
            if (inp.type === 'password') {
                inp.type = 'text';
                btn.textContent = '🙈';
            } else {
                inp.type = 'password';
                btn.textContent = '👁️';
            }
        }

        // ═══ SAVE / LOAD CREDENTIALS ═══
        function saveCredentials(email, password) {
            try {
                localStorage.setItem(CRED_KEY, JSON.stringify({
                    email,
                    password
                }));
            } catch (e) {}
        }

        function loadCredentials() {
            try {
                const saved = JSON.parse(localStorage.getItem(CRED_KEY) || 'null');
                if (saved && saved.email) {
                    document.getElementById('loginEmail').value = saved.email;
                    document.getElementById('loginPass').value = saved.password || '';
                }
            } catch (e) {}
        }

        function clearCredentials() {
            try {
                localStorage.removeItem(CRED_KEY);
            } catch (e) {}
        }

        // ═══ AUTH ═══
        function switchAuth(tab) {
            document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
            const tabs = document.querySelectorAll('.auth-tab');
            if (tab === 'login') {
                tabs[0].classList.add('active');
                document.getElementById('loginForm').classList.add('active');
            } else if (tab === 'register') {
                tabs[1].classList.add('active');
                document.getElementById('registerForm').classList.add('active');
            } else if (tab === 'changepw') {
                tabs[2].classList.add('active');
                document.getElementById('changepwForm').classList.add('active');
            }
            document.getElementById('authMsg').className = 'auth-msg';
        }

        function showMsg(msg, type) {
            const el = document.getElementById('authMsg');
            el.textContent = msg;
            el.className = 'auth-msg ' + (type === 'error' ? 'err' : 'ok');
        }

        async function handleLogin(e) {
            e.preventDefault();
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Loading...';
            const fd = new FormData(e.target);
            fd.append('action', 'login');
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    body: fd
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (pe) {
                    showMsg('Server error: ' + text.substring(0, 100), 'error');
                    btn.disabled = false;
                    btn.textContent = '🔐 Login';
                    return false;
                }
                if (data.success) {
                    localStorage.setItem('staff_name', data.name);
                    localStorage.setItem('staff_employee_id', data.employee_id);
                    if (document.getElementById('rememberMe').checked) {
                        saveCredentials(fd.get('email'), fd.get('password'));
                    } else {
                        clearCredentials();
                    }
                    showApp(data.name);
                } else {
                    showMsg(data.message, 'error');
                }
            } catch (err) {
                showMsg('Koneksi gagal: ' + err.message, 'error');
            }
            btn.disabled = false;
            btn.textContent = '🔐 Login';
            return false;
        }

        async function handleRegister(e) {
            e.preventDefault();
            const btn = document.getElementById('regBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Loading...';
            const fd = new FormData(e.target);
            fd.append('action', 'register');
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    body: fd
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (pe) {
                    showMsg('Server error: ' + text.substring(0, 100), 'error');
                    btn.disabled = false;
                    btn.textContent = '📝 Daftar';
                    return false;
                }
                showMsg(data.message, data.success ? 'ok' : 'error');
                if (data.success) {
                    if (document.getElementById('rememberReg').checked) {
                        saveCredentials(fd.get('email'), fd.get('password'));
                    }
                    setTimeout(() => switchAuth('login'), 1500);
                }
            } catch (err) {
                showMsg('Koneksi gagal: ' + err.message, 'error');
            }
            btn.disabled = false;
            btn.textContent = '📝 Daftar';
            return false;
        }

        async function handleChangePw(e) {
            e.preventDefault();
            const btn = document.getElementById('cpBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Loading...';
            const np = document.getElementById('cpNewPass').value;
            const cp = document.getElementById('cpConfirmPass').value;
            if (np !== cp) {
                showMsg('Password baru tidak cocok!', 'error');
                btn.disabled = false;
                btn.textContent = '🔑 Ubah Password';
                return false;
            }
            if (np.length < 6) {
                showMsg('Password baru minimal 6 karakter!', 'error');
                btn.disabled = false;
                btn.textContent = '🔑 Ubah Password';
                return false;
            }
            const fd = new FormData(e.target);
            fd.append('action', 'change_password');
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    body: fd
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (pe) {
                    showMsg('Server error: ' + text.substring(0, 100), 'error');
                    btn.disabled = false;
                    btn.textContent = '🔑 Ubah Password';
                    return false;
                }
                showMsg(data.message, data.success ? 'ok' : 'error');
                if (data.success) {
                    e.target.reset();
                    setTimeout(() => switchAuth('login'), 2000);
                }
            } catch (err) {
                showMsg('Koneksi gagal: ' + err.message, 'error');
            }
            btn.disabled = false;
            btn.textContent = '🔑 Ubah Password';
            return false;
        }

        function doLogout() {
            fetch(API, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'logout'
                })
            });
            localStorage.removeItem('staff_name');
            document.getElementById('authScreen').style.display = 'flex';
            document.getElementById('appShell').style.display = 'none';
        }

        // ═══ APP ═══
        function showApp(name) {
            document.getElementById('authScreen').style.display = 'none';
            document.getElementById('appShell').style.display = 'block';
            document.getElementById('headerName').textContent = name || 'Staff';
            loadHome();
        }

        function loadHome() {
            loadAbsen();
            loadMonitoring();
            loadMonitorLembur();
            if (IS_CAFE) loadSchedule();
            // Preload face models in background so Face Scan opens instantly
            preloadFaceModels();
        }

        // Background preload — non-blocking, loads AI models silently
        let _preloadStarted = false;
        async function preloadFaceModels() {
            if (_preloadStarted || faceModelsLoaded) return;
            _preloadStarted = true;
            try {
                let url = FACE_MODEL_URL;
                try {
                    const t = await fetch(url + '/tiny_face_detector_model-weights_manifest.json', {
                        method: 'HEAD'
                    });
                    if (!t.ok) throw new Error();
                } catch (e) {
                    url = FACE_MODEL_CDN;
                }
                await faceapi.nets.tinyFaceDetector.loadFromUri(url);
                await faceapi.nets.faceLandmark68TinyNet.loadFromUri(url);
                await faceapi.nets.faceRecognitionNet.loadFromUri(url);
                faceModelsLoaded = true;
                // Warm up WebGL shaders
                try {
                    const c = document.createElement('canvas');
                    c.width = c.height = 128;
                    await faceapi.detectSingleFace(c, new faceapi.TinyFaceDetectorOptions({
                        inputSize: 128
                    }));
                } catch (e) {}
                console.log('[FaceID] Models preloaded in background');
            } catch (e) {
                _preloadStarted = false;
                console.warn('[FaceID] Background preload failed:', e);
            }
        }

        // ── Navigation ──
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', () => {
                document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
                item.classList.add('active');
                const page = item.dataset.page;
                document.getElementById('page-' + page).classList.add('active');
                if (page === 'home') loadHome();
                if (page === 'slipgaji') loadSlipGaji();
                if (page === 'occupancy' && IS_HOTEL) loadOccupancy();
                if (page === 'breakfast' && IS_HOTEL) loadBreakfast();
                if (page === 'schedule' && IS_CAFE) loadSchedule();
            });
        });

        // ═══ ABSEN PAGE ═══
        async function loadAbsen() {
            // Today status
            try {
                const res = await fetch(API + '&action=attendance_today');
                const data = await res.json();
                if (!data.success && data.auth === false) {
                    doLogout();
                    return;
                }
                const a = data.data;
                if (a) {
                    const s1 = a.check_in_time ? a.check_in_time.substring(0, 5) : '—';
                    const s2 = a.check_out_time ? a.check_out_time.substring(0, 5) : '—';
                    const wh = parseFloat(a.work_hours) || 0;
                    const ot = parseFloat(a.overtime_hours) || 0;
                    const statusMap = {
                        present: '✅ Hadir',
                        late: '⏰ Terlambat',
                        absent: '❌ Absen',
                        leave: '📝 Izin'
                    };

                    let scanGrid;
                    if (IS_CAFE) {
                        // Cafe: 2 scan (Masuk / Pulang) with schedule info
                        const scheduleInfo = a.schedule_start && a.schedule_end ?
                            `<div style="text-align:center;font-size:10px;color:var(--muted);margin-bottom:8px;">Jadwal: ${a.schedule_start?.substring(0,5) || '—'} — ${a.schedule_end?.substring(0,5) || '—'}</div>` : '';
                        const lateInfo = a.late_minutes && a.late_minutes > 0 ?
                            `<div style="text-align:center;font-size:10px;color:var(--red);margin-top:4px;">Terlambat ${a.late_minutes} menit</div>` : '';
                        const earlyInfo = a.early_leave_minutes && a.early_leave_minutes > 0 ?
                            `<div style="text-align:center;font-size:10px;color:var(--orange);margin-top:4px;">Pulang awal ${a.early_leave_minutes} menit</div>` : '';
                        scanGrid = `
                    ${scheduleInfo}
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;text-align:center;">
                        <div style="background:var(--bg);border-radius:10px;padding:14px;">
                            <div style="font-size:20px;margin-bottom:4px;">🟢</div>
                            <div style="font-size:10px;color:var(--muted);font-weight:600;">MASUK</div>
                            <div style="font-size:22px;font-weight:800;color:var(--green);margin-top:2px;">${s1}</div>
                        </div>
                        <div style="background:var(--bg);border-radius:10px;padding:14px;">
                            <div style="font-size:20px;margin-bottom:4px;">🔴</div>
                            <div style="font-size:10px;color:var(--muted);font-weight:600;">PULANG</div>
                            <div style="font-size:22px;font-weight:800;color:var(--navy);margin-top:2px;">${s2}</div>
                        </div>
                    </div>
                    ${lateInfo}${earlyInfo}`;
                    } else {
                        // Hotel: 4 scan split-shift
                        const s3 = a.scan_3 ? a.scan_3.substring(0, 5) : '—';
                        const s4 = a.scan_4 ? a.scan_4.substring(0, 5) : '—';
                        scanGrid = `
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;text-align:center;">
                    <div style="background:var(--bg);border-radius:8px;padding:8px;">
                        <div style="font-size:9px;color:var(--muted);">Scan 1</div>
                        <div style="font-size:14px;font-weight:700;color:var(--green);">${s1}</div>
                    </div>
                    <div style="background:var(--bg);border-radius:8px;padding:8px;">
                        <div style="font-size:9px;color:var(--muted);">Scan 2</div>
                        <div style="font-size:14px;font-weight:700;color:var(--navy);">${s2}</div>
                    </div>
                    <div style="background:var(--bg);border-radius:8px;padding:8px;">
                        <div style="font-size:9px;color:var(--muted);">Scan 3</div>
                        <div style="font-size:14px;font-weight:700;color:var(--green);">${s3}</div>
                    </div>
                    <div style="background:var(--bg);border-radius:8px;padding:8px;">
                        <div style="font-size:9px;color:var(--muted);">Scan 4</div>
                        <div style="font-size:14px;font-weight:700;color:var(--navy);">${s4}</div>
                    </div>
                </div>`;
                    }

                    // Jam Kerja: tampilkan 8 jam (standar harian) jika sudah hadir; OT hanya jika sudah di-approve
                    const baseHourTxt = (wh > 0 || a.status === 'present' || a.status === 'late') ? '8 jam' : '';
                    const otTxt = ot > 0 ? `<span style="color:var(--orange);font-weight:700;">· OT ${ot.toFixed(1)} jam</span>` : (baseHourTxt ? '<span style="color:var(--muted);">· tanpa OT</span>' : '');
                    document.getElementById('todayStatus').innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
                    <span class="badge ${a.status==='present'?'b-hadir':a.status==='late'?'b-late':'b-absent'}">${statusMap[a.status]||a.status}</span>
                    <span style="font-size:11px;color:var(--muted);display:inline-flex;gap:6px;align-items:center;">${baseHourTxt}${otTxt}</span>
                </div>
                ${scanGrid}`;
                } else {
                    document.getElementById('todayStatus').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">⏳ Belum absen hari ini. Tap "Scan Wajah" di atas untuk absen.</div>';
                }
            } catch (e) {
                document.getElementById('todayStatus').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat data</div>';
            }

            // Monthly donut chart
            try {
                const m = new Date().toISOString().substring(0, 7);
                const res = await fetch(API + '&action=attendance_history&month=' + m);
                const data = await res.json();
                const s = data.summary || {};
                const totalHours = s.total_hours || 0;
                const target = s.target || 200;
                const pct = target > 0 ? Math.min(Math.round(totalHours / target * 100), 100) : 0;
                const remaining = Math.max(0, target - totalHours);
                const daysPresent = s.days_present || 0;
                const daysLate = s.days_late || 0;

                // Donut chart using SVG conic gradient simulation
                const radius = 70,
                    cx = 85,
                    cy = 85,
                    stroke = 14;
                const circumference = 2 * Math.PI * radius;
                const dashOffset = circumference - (pct / 100) * circumference;
                const gradColor1 = pct >= 90 ? '#10b981' : pct >= 60 ? '#f59e0b' : '#ef4444';
                const gradColor2 = pct >= 90 ? '#059669' : pct >= 60 ? '#d97706' : '#dc2626';
                const gradId = 'donutGrad';

                document.getElementById('monthlySummary').innerHTML = `
            <div style="display:flex;align-items:center;gap:20px;justify-content:center;">
                <div style="position:relative;width:170px;height:170px;flex-shrink:0;">
                    <svg width="170" height="170" viewBox="0 0 170 170" style="transform:rotate(-90deg);">
                        <defs>
                            <linearGradient id="${gradId}" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="${gradColor1}"/>
                                <stop offset="100%" stop-color="${gradColor2}"/>
                            </linearGradient>
                            <filter id="donutShadow"><feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="${gradColor1}" flood-opacity="0.3"/></filter>
                        </defs>
                        <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="#e2e8f0" stroke-width="${stroke}" />
                        <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="url(#${gradId})" stroke-width="${stroke}" 
                            stroke-linecap="round" stroke-dasharray="${circumference}" stroke-dashoffset="${circumference}" filter="url(#donutShadow)">
                            <animate attributeName="stroke-dashoffset" from="${circumference}" to="${dashOffset}" dur="1.2s" fill="freeze" calcMode="spline" keySplines="0.4 0 0.2 1" keyTimes="0;1"/>
                        </circle>
                    </svg>
                    <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                        <div style="font-size:28px;font-weight:800;color:${gradColor1};line-height:1;" id="donutPctNum">0</div>
                        <div style="font-size:10px;font-weight:700;color:${gradColor1};margin-top:1px;">%</div>
                        <div style="font-size:9px;color:var(--muted);margin-top:3px;">dari ${target}j</div>
                    </div>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="display:grid;gap:8px;">
                        <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                            <div style="width:32px;height:32px;background:#10b981;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;">📅</div>
                            <div><div style="font-size:9px;color:#059669;font-weight:600;text-transform:uppercase;">Hadir</div><div style="font-size:18px;font-weight:800;color:#065f46;">${daysPresent} <span style="font-size:10px;font-weight:400;">hari</span></div></div>
                        </div>
                        <div style="background:linear-gradient(135deg,#fefce8,#fef9c3);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                            <div style="width:32px;height:32px;background:#eab308;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;">🎯</div>
                            <div><div style="font-size:9px;color:#a16207;font-weight:600;text-transform:uppercase;">Sisa Target</div><div style="font-size:18px;font-weight:800;color:#854d0e;">${remaining.toFixed(1)} <span style="font-size:10px;font-weight:400;">jam</span></div></div>
                        </div>
                    </div>
                </div>
            </div>`;
                // Animate percentage number
                let cur = 0;
                const tgt = pct;
                const animPct = () => {
                    if (cur < tgt) {
                        cur += Math.max(1, Math.round((tgt - cur) / 10));
                        if (cur > tgt) cur = tgt;
                        document.getElementById('donutPctNum').textContent = cur;
                        requestAnimationFrame(animPct);
                    }
                };
                requestAnimationFrame(animPct);
            } catch (e) {}
        }

        // ═══ MONITORING PAGE ═══
        async function loadMonitoring() {
            const month = document.getElementById('monitorMonth').value || new Date().toISOString().substring(0, 7);
            try {
                const res = await fetch(API + '&action=attendance_history&month=' + month);
                const data = await res.json();
                const s = data.summary || {};
                const pct = s.target > 0 ? Math.min(Math.round(s.total_hours / s.target * 100), 100) : 0;
                const barColor = pct >= 90 ? 'var(--green)' : pct >= 60 ? 'var(--orange)' : 'var(--red)';

                document.getElementById('monitorStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">Hadir</div><div class="sv" style="color:var(--green);">${s.days_present||0}</div></div>
                <div class="stat-card"><div class="sl">Terlambat</div><div class="sv" style="color:var(--orange);">${s.days_late||0}</div></div>
            </div>
            <div class="card" style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px;">
                    <span style="color:var(--muted);">Target ${s.target||200} jam</span>
                    <span style="font-weight:700;color:${barColor};">${pct}%</span>
                </div>
                <div class="progress"><div class="progress-bar" style="width:${pct}%;background:${barColor};"></div></div>
            </div>`;

                const rows = data.data || [];
                if (rows.length === 0) {
                    document.getElementById('monitorTable').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Belum ada data absensi.</div>';
                    return;
                }

                let html;
                if (IS_CAFE) {
                    html = '<div style="overflow-x:auto;font-size:13px;line-height:1.5;"><table class="tbl"><thead><tr><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Jam Kerja</th><th>Status</th></tr></thead><tbody>';
                } else {
                    html = '<div style="overflow-x:auto;font-size:13px;line-height:1.5;"><table class="tbl"><thead><tr><th>Tanggal</th><th>Scan 1</th><th>Scan 2</th><th>Scan 3</th><th>Scan 4</th><th>Total</th><th>Status</th></tr></thead><tbody>';
                }
                const statusMap = {
                    present: 'Hadir',
                    late: 'Terlambat',
                    absent: 'Absen',
                    leave: 'Izin',
                    holiday: 'Libur',
                    half_day: '½ Hari'
                };
                rows.forEach(r => {
                    const dt = new Date(r.attendance_date);
                    const day = dt.toLocaleDateString('id-ID', {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'short'
                    });
                    const s1 = r.check_in_time ? r.check_in_time.substring(0, 5) : '—';
                    const s2 = r.check_out_time ? r.check_out_time.substring(0, 5) : '—';
                    const wh = parseFloat(r.work_hours) || 0;
                    const ot = parseFloat(r.overtime_hours) || 0;
                    const isOver200 = r.is_over_200 || (parseFloat(r.auto_overtime_over_200) || 0) > 0;
                    const otLabel = isOver200 ? 'OT&gt;200j' : 'OT';
                    const otColor = isOver200 ? 'var(--red,#dc2626)' : 'var(--orange)';
                    const bc = r.status === 'present' ? 'b-hadir' : r.status === 'late' ? 'b-late' : 'b-absent';

                    // Jam Kerja: tampilkan 8 jam (standar harian) jika hadir/terlambat; OT hanya bila sudah di-approve
                    const hadir = (wh > 0) || r.status === 'present' || r.status === 'late';
                    const whTxt = hadir ? '8j' : '—';
                    const otBlock = ot > 0 ?
                        `<div style="font-size:10px;color:${otColor};font-weight:700;">${otLabel} +${ot.toFixed(1)}j</div>` :
                        (hadir ? `<div style="font-size:9px;color:var(--muted);">tanpa OT</div>` : '');

                    if (IS_CAFE) {
                        html += `<tr><td style="white-space:nowrap;">${day}</td><td style="font-weight:600;color:var(--green);">${s1}</td><td style="font-weight:600;color:var(--navy);">${s2}</td><td style="font-weight:700;">${whTxt}${otBlock}</td><td><span class="badge ${bc}">${statusMap[r.status]||r.status}</span></td></tr>`;
                    } else {
                        const s3 = r.scan_3 ? r.scan_3.substring(0, 5) : '—';
                        const s4 = r.scan_4 ? r.scan_4.substring(0, 5) : '—';
                        html += `<tr><td style="white-space:nowrap;">${day}</td><td style="font-weight:600;color:var(--green);">${s1}</td><td>${s2}</td><td style="color:var(--green);">${s3}</td><td>${s4}</td><td style="font-weight:700;">${whTxt}${otBlock}</td><td><span class="badge ${bc}">${statusMap[r.status]||r.status}</span></td></tr>`;
                    }
                });
                html += '</tbody></table></div>';
                document.getElementById('monitorTable').innerHTML = html;
            } catch (e) {
                document.getElementById('monitorTable').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>';
            }
        }

        // ── Month navigator (mempengaruhi Detail Absensi + Lembur Bulan Ini + tombol Slip) ──
        function shiftMonitorMonth(delta) {
            const inp = document.getElementById('monitorMonth');
            if (!inp) return;
            let base;
            if (delta === 0) {
                base = new Date();
            } else {
                const cur = inp.value || new Date().toISOString().substring(0, 7);
                const [y, m] = cur.split('-').map(Number);
                base = new Date(y, (m - 1) + delta, 1);
            }
            const yy = base.getFullYear();
            const mm = String(base.getMonth() + 1).padStart(2, '0');
            inp.value = `${yy}-${mm}`;
            onMonitorMonthChange();
        }

        function onMonitorMonthChange() {
            loadMonitoring();
            loadMonitorLembur();
        }

        async function loadMonitorLembur() {
            const month = document.getElementById('monitorMonth').value || new Date().toISOString().substring(0, 7);
            const statsEl = document.getElementById('monitorLemburStats');
            const histEl = document.getElementById('monitorLemburHistory');
            if (!statsEl || !histEl) return;
            try {
                const res = await fetch(API + '&action=overtime_history&month=' + month);
                const data = await res.json();
                const stats = data.stats || {};
                const rows = data.data || [];
                statsEl.innerHTML = `
                <div class="stat-row">
                    <div class="stat-card"><div class="sl">⏳ Pending</div><div class="sv" style="color:var(--orange);">${stats.pending||0}</div></div>
                    <div class="stat-card"><div class="sl">✅ Disetujui</div><div class="sv" style="color:var(--green);">${stats.approved||0}</div></div>
                    <div class="stat-card"><div class="sl">❌ Ditolak</div><div class="sv" style="color:var(--red);">${stats.rejected||0}</div></div>
                </div>`;
                if (rows.length === 0) {
                    histEl.innerHTML = '<div style="text-align:center;padding:12px;color:var(--muted);font-size:11px;">Tidak ada pengajuan lembur di bulan ini.</div>';
                    return;
                }
                const statusCls = {
                    pending: 'ls-pending',
                    approved: 'ls-approved',
                    rejected: 'ls-rejected'
                };
                const statusLabel = {
                    pending: '⏳ Pending',
                    approved: '✅ Disetujui',
                    rejected: '❌ Ditolak'
                };
                let html = '';
                rows.forEach(r => {
                    const d = new Date(r.overtime_date).toLocaleDateString('id-ID', {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'short'
                    });
                    html += `<div style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">
                            <span style="font-weight:700;font-size:11px;">⏰ ${d}</span>
                            <span class="leave-status ${statusCls[r.status]||''}">${statusLabel[r.status]||r.status}</span>
                        </div>
                        <div style="font-size:10px;color:var(--text);">${r.reason||''}</div>
                        ${r.admin_notes ? `<div style="font-size:10px;color:var(--blue);margin-top:2px;background:#eff6ff;padding:3px 6px;border-radius:4px;">💬 ${r.admin_notes}</div>` : ''}
                    </div>`;
                });
                histEl.innerHTML = html;
            } catch (e) {
                histEl.innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>';
            }
        }

        // Tombol "Lihat Slip Gaji Bulan Ini" — pindah ke page Slip & auto-pilih period
        function openSlipForMonitorMonth() {
            const month = document.getElementById('monitorMonth').value || new Date().toISOString().substring(0, 7);
            const [y, m] = month.split('-').map(Number);
            // Aktifkan nav slipgaji
            const navItem = document.querySelector('.nav-item[data-page="slipgaji"]');
            if (navItem) navItem.click();
            // Setelah loadSlipGaji selesai populate dropdown, coba pilih period yg cocok
            const trySelect = (attempt = 0) => {
                const sel = document.getElementById('slipPeriod');
                if (!sel || sel.options.length === 0 || (sel.options.length === 1 && !sel.value)) {
                    if (attempt < 20) return setTimeout(() => trySelect(attempt + 1), 150);
                    return;
                }
                // Cari option dgn label memuat tahun dan nama bulan ID
                const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                const want = monthNames[m - 1] + ' ' + y;
                let matched = null;
                for (const opt of sel.options) {
                    if ((opt.text || '').toLowerCase().includes(want.toLowerCase())) {
                        matched = opt.value;
                        break;
                    }
                }
                if (matched && sel.value !== matched) {
                    sel.value = matched;
                    if (typeof loadSlipGaji === 'function') loadSlipGaji();
                } else if (!matched) {
                    const content = document.getElementById('slipGajiContent');
                    if (content) content.innerHTML = `<div style="text-align:center;padding:30px 16px;"><div style="font-size:42px;margin-bottom:10px;">📋</div><div style="font-size:12px;color:var(--muted);">Slip gaji untuk <b>${want}</b> belum tersedia.</div><div style="font-size:10px;color:var(--muted);margin-top:4px;">Slip baru muncul setelah payroll bulan tsb diproses & dibayar admin.</div></div>`;
                }
            };
            trySelect();
        }

        // ═══ SCHEDULE PAGE (Cafe) ═══
        async function loadSchedule() {
            if (!IS_CAFE) return;
            try {
                const res = await fetch(API + '&action=work_schedule');
                const data = await res.json();
                if (!data.success) {
                    document.getElementById('scheduleInfo').innerHTML = '<div style="color:var(--muted);text-align:center;padding:16px;font-size:12px;">Jadwal belum dikonfigurasi admin.</div>';
                    return;
                }
                const s = data.data;
                const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                const todayIdx = new Date().getDay();

                let html = '<div style="margin-bottom:12px;">';
                // Today highlight
                html += `<div style="background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;border-radius:12px;padding:16px;margin-bottom:12px;text-align:center;">
            <div style="font-size:11px;opacity:.7;">Jadwal Hari Ini — ${dayNames[todayIdx]}</div>
            <div style="font-size:28px;font-weight:800;margin:6px 0;">${s.start_time?.substring(0,5) || '—'} — ${s.end_time?.substring(0,5) || '—'}</div>
            <div style="font-size:12px;opacity:.8;">${s.total_hours || 8} jam kerja${s.break_minutes ? ' · istirahat ' + s.break_minutes + ' menit' : ''}</div>
        </div>`;

                // Weekly schedule
                if (s.weekly && s.weekly.length > 0) {
                    html += '<div style="font-size:12px;font-weight:700;color:var(--navy);margin-bottom:8px;">📅 Jadwal Mingguan</div>';
                    s.weekly.forEach(d => {
                        const isToday = d.day_index == todayIdx;
                        const isOff = d.is_off;
                        html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:8px;margin-bottom:4px;${isToday?'background:var(--bg);border:1.5px solid var(--gold);':'border:1px solid var(--border);'}">
                    <div style="font-weight:${isToday?'700':'500'};font-size:13px;">${isToday?'▶ ':''}${dayNames[d.day_index]}</div>
                    <div style="font-size:12px;color:${isOff?'var(--red)':'var(--green)'};font-weight:600;">${isOff ? 'LIBUR' : (d.start_time?.substring(0,5)+' — '+d.end_time?.substring(0,5))}</div>
                </div>`;
                    });
                }
                html += '</div>';
                document.getElementById('scheduleInfo').innerHTML = html;

                // Punctuality stats
                const m = new Date().toISOString().substring(0, 7);
                const hRes = await fetch(API + '&action=attendance_history&month=' + m);
                const hData = await hRes.json();
                const rows = hData.data || [];
                let onTime = 0,
                    lateCount = 0,
                    totalLateMin = 0;
                rows.forEach(r => {
                    if (r.status === 'present') onTime++;
                    if (r.status === 'late') {
                        lateCount++;
                        totalLateMin += (parseInt(r.late_minutes) || 0);
                    }
                });
                const totalDays = onTime + lateCount;
                const pctOnTime = totalDays > 0 ? Math.round(onTime / totalDays * 100) : 100;
                const barColor = pctOnTime >= 90 ? 'var(--green)' : pctOnTime >= 70 ? 'var(--orange)' : 'var(--red)';

                document.getElementById('punctualityStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">Tepat Waktu</div><div class="sv" style="color:var(--green);">${onTime}</div><div class="ss">hari</div></div>
                <div class="stat-card"><div class="sl">Terlambat</div><div class="sv" style="color:var(--orange);">${lateCount}</div><div class="ss">${totalLateMin > 0 ? 'total '+totalLateMin+' menit' : 'hari'}</div></div>
            </div>
            <div style="margin-top:4px;">
                <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px;">
                    <span style="color:var(--muted);">Ketepatan Waktu</span>
                    <span style="font-weight:700;color:${barColor};">${pctOnTime}%</span>
                </div>
                <div class="progress"><div class="progress-bar" style="width:${pctOnTime}%;background:${barColor};"></div></div>
            </div>`;
            } catch (e) {
                document.getElementById('scheduleInfo').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat jadwal</div>';
            }
        }

        // ═══ OCCUPANCY PAGE ═══
        let calStartDate = new Date().toISOString().split('T')[0];

        // Tandai kamar dirty menjadi bersih (dari denah staff portal)
        async function markRoomClean(roomId, roomNumber) {
            if (!confirm(`Tandai Room ${roomNumber||roomId} sudah BERSIH?`)) return;
            try {
                const fd = new FormData();
                fd.append('room_id', roomId);
                const res = await fetch(API + '&action=mark_room_clean', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (data && data.success) {
                    if (typeof loadOccupancy === 'function') loadOccupancy();
                } else {
                    alert(data && data.message ? data.message : 'Gagal menandai bersih');
                }
            } catch (e) {
                alert('Gagal menghubungi server');
            }
        }

        function calNav(days) {
            const d = new Date(calStartDate);
            d.setDate(d.getDate() + days);
            calStartDate = d.toISOString().split('T')[0];
            loadOccupancy();
        }

        function closeCalPopup() {
            document.getElementById('calPopup').style.display = 'none';
            document.getElementById('calPopupOverlay').style.display = 'none';
        }

        function showBookingPopup(b) {
            const statusMap = {
                'pending': '⏳ Pending',
                'confirmed': '✅ Confirmed',
                'checked_in': '🏨 Checked In',
                'checked_out': '� Checked Out'
            };
            const sourceMap = {
                'walk_in': '🚶 Walk In',
                'agoda': '🟠 Agoda',
                'booking': '🔵 Booking.com',
                'traveloka': '🔷 Traveloka',
                'airbnb': '🏠 Airbnb',
                'tiket': '🎫 Tiket.com',
                'phone': '📞 Phone',
                'whatsapp': '💬 WhatsApp'
            };
            const payMap = {
                'unpaid': '❌ Belum Bayar',
                'partial': '⚠️ Sebagian',
                'paid': '✅ Lunas'
            };
            const statusColor = {
                'pending': '#0ea5e9',
                'confirmed': '#06b6d4',
                'checked_in': '#16a34a',
                'checked_out': '#9ca3af'
            };
            const cin = b.check_in_date ? new Date(b.check_in_date + 'T00:00:00') : null;
            const cout = b.check_out_date ? new Date(b.check_out_date + 'T00:00:00') : null;
            const nights = cin && cout ? Math.round((cout - cin) / 86400000) : '-';
            const fmtDate = d => d ? d.getDate() + ' ' + ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][d.getMonth()] + ' ' + d.getFullYear() : '-';
            document.getElementById('calPopup').innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div style="font-weight:800;font-size:14px;color:var(--navy);">📋 Detail Booking</div>
            <button onclick="closeCalPopup()" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--muted);">✕</button>
        </div>
        <div style="background:linear-gradient(135deg,${statusColor[b.status]||'#64748b'}20,${statusColor[b.status]||'#64748b'}10);border-left:3px solid ${statusColor[b.status]||'#64748b'};border-radius:0 8px 8px 0;padding:8px 10px;margin-bottom:10px;">
            <div style="font-size:15px;font-weight:800;color:var(--navy);">${b.guest_name||'-'}</div>
            <div style="font-size:10px;color:var(--muted);margin-top:2px;">Kode: <strong>${b.booking_code||'-'}</strong></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px;">
            <div style="background:#f0fdf4;border-radius:8px;padding:8px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:#16a34a;text-transform:uppercase;">Check-in</div>
                <div style="font-size:11px;font-weight:800;color:#166534;">${fmtDate(cin)}</div>
            </div>
            <div style="background:#fef2f2;border-radius:8px;padding:8px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:#dc2626;text-transform:uppercase;">Check-out</div>
                <div style="font-size:11px;font-weight:800;color:#991b1b;">${fmtDate(cout)}</div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:10px;">
            <div style="background:#f8fafc;border-radius:8px;padding:6px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:var(--muted);text-transform:uppercase;">Malam</div>
                <div style="font-size:16px;font-weight:900;color:var(--navy);">${nights}</div>
            </div>
            <div style="background:#f8fafc;border-radius:8px;padding:6px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:var(--muted);text-transform:uppercase;">Status</div>
                <div style="font-size:10px;font-weight:700;color:${statusColor[b.status]||'#64748b'};">${statusMap[b.status]||b.status}</div>
            </div>
            <div style="background:#f8fafc;border-radius:8px;padding:6px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:var(--muted);text-transform:uppercase;">Bayar</div>
                <div style="font-size:10px;font-weight:700;">${payMap[b.payment_status]||b.payment_status||'-'}</div>
            </div>
        </div>
        <div style="font-size:11px;color:var(--muted);text-align:center;">Sumber: ${sourceMap[b.booking_source]||b.booking_source||'-'}</div>`;
            document.getElementById('calPopup').style.display = 'block';
            document.getElementById('calPopupOverlay').style.display = 'block';
        }

        async function loadOccupancy() {
            try {
                const res = await fetch(API + '&action=occupancy&start=' + calStartDate);
                const data = await res.json();
                const d = data.data || {};

                // Stats with Pie Chart
                const occ = parseInt(d.occupied) || 0;
                const avail = parseInt(d.available) || 0;
                const total = parseInt(d.total_rooms) || 0;
                const rate = parseFloat(d.occupancy_rate) || 0;
                const arrivals = parseInt(d.arrivals_today) || 0;
                const departures = parseInt(d.departures_today) || 0;

                // SVG donut chart
                const radius = 54,
                    cx = 65,
                    cy = 65,
                    stroke = 14;
                const circ = 2 * Math.PI * radius;
                const occPct = total > 0 ? occ / total : 0;
                const occLen = circ * occPct;
                const availLen = circ - occLen;

                document.getElementById('occStats').innerHTML = `
            <div class="card" style="margin-bottom:12px;">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:center;">
                    <!-- Donut Chart -->
                    <div style="position:relative;width:130px;height:130px;flex-shrink:0;">
                        <svg width="130" height="130" viewBox="0 0 130 130">
                            <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="#e5e7eb" stroke-width="${stroke}"/>
                            <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="#0ea5e9" stroke-width="${stroke}"
                                stroke-dasharray="${occLen} ${availLen}"
                                stroke-dashoffset="${circ * 0.25}"
                                stroke-linecap="round"
                                style="transition:stroke-dasharray .8s ease;"/>
                            <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="#22c55e" stroke-width="${stroke}"
                                stroke-dasharray="${availLen} ${occLen}"
                                stroke-dashoffset="${circ * 0.25 - occLen}"
                                stroke-linecap="round"
                                style="transition:stroke-dasharray .8s ease;"/>
                        </svg>
                        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                            <div style="font-size:24px;font-weight:900;color:var(--navy);line-height:1;">${rate}%</div>
                            <div style="font-size:8px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:2px;">Occupancy</div>
                        </div>
                    </div>
                    <!-- Right Stats -->
                    <div style="flex:1;min-width:160px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div style="background:#f0fdf4;border-radius:10px;padding:10px;text-align:center;">
                                <div style="font-size:8px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.3px;">Available</div>
                                <div style="font-size:22px;font-weight:900;color:#16a34a;">${avail}</div>
                            </div>
                            <div style="background:#fef2f2;border-radius:10px;padding:10px;text-align:center;">
                                <div style="font-size:8px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.3px;">Occupied</div>
                                <div style="font-size:22px;font-weight:900;color:#dc2626;">${occ}</div>
                            </div>
                            <div style="background:#eff6ff;border-radius:10px;padding:10px;text-align:center;">
                                <div style="font-size:8px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.3px;">Total Rooms</div>
                                <div style="font-size:22px;font-weight:900;color:#2563eb;">${total}</div>
                            </div>
                            <div style="background:#fefce8;border-radius:10px;padding:10px;text-align:center;">
                                <div style="font-size:8px;font-weight:700;color:#ca8a04;text-transform:uppercase;letter-spacing:.3px;">Occ. Rate</div>
                                <div style="font-size:22px;font-weight:900;color:#ca8a04;">${rate}%</div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Arrivals / Departures -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;">
                    <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                        <div style="width:36px;height:36px;background:#16a34a;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">✈️</div>
                        <div><div style="font-size:8px;font-weight:700;color:#16a34a;text-transform:uppercase;">Cekin Hari Ini</div><div style="font-size:20px;font-weight:900;color:#16a34a;">${arrivals}</div></div>
                    </div>
                    <div style="background:linear-gradient(135deg,#fff7ed,#fed7aa);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                        <div style="width:36px;height:36px;background:#ea580c;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">🚪</div>
                        <div><div style="font-size:8px;font-weight:700;color:#ea580c;text-transform:uppercase;">Cekout Hari Ini</div><div style="font-size:20px;font-weight:900;color:#ea580c;">${departures}</div></div>
                    </div>
                </div>
            </div>`;

                // Room grid
                const rooms = d.rooms || [];
                if (rooms.length === 0) {
                    document.getElementById('roomGrid').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Tidak ada data kamar.</div>';
                } else {
                    let rh = '<div class="room-grid">';
                    rooms.forEach(r => {
                        const isOcc = r.status === 'occupied';
                        const isDirty = r.status === 'cleaning';
                        const hasB2B = isOcc && r.next_guest;
                        const boxClass = isDirty ? 'dirty' : (hasB2B ? 'b2b' : (isOcc ? 'occ' : 'avail'));
                        rh += `<div class="room-box ${boxClass}">
                    ${r.room_number}
                    <div class="room-type">${r.room_type||''}</div>
                    ${isOcc ? `<div class="room-guest">${r.guest_name||''}</div>` : ''}
                    ${hasB2B ? `<div class="room-next">→ ${r.next_guest}</div>` : ''}
                    ${isDirty ? `<button class="btn-clean" onclick="markRoomClean(${r.id}, '${(r.room_number||'').replace(/'/g,'')}')">✓ Clean</button>` : ''}
                </div>`;
                    });
                    rh += '</div>';
                    document.getElementById('roomGrid').innerHTML = rh;
                }

                // ── Calendar (Frontdesk Style) ──
                const COL_W = 130; // pixels per day column (match frontdesk)
                const bookings = d.bookings || [];
                const start = new Date(d.calendar_start || calStartDate);
                const days = 14;
                const dates = [];
                const today = new Date().toISOString().split('T')[0];
                for (let i = 0; i < days; i++) {
                    const dt = new Date(start);
                    dt.setDate(dt.getDate() + i);
                    dates.push(dt.toISOString().split('T')[0]);
                }

                const startM = new Date(dates[0] + 'T00:00:00');
                const endM = new Date(dates[dates.length - 1] + 'T00:00:00');
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                document.getElementById('calPeriod').textContent =
                    startM.getDate() + ' ' + months[startM.getMonth()] + ' - ' + endM.getDate() + ' ' + months[endM.getMonth()] + ' ' + endM.getFullYear();

                // Group rooms by type
                const roomsByType = {};
                rooms.forEach(r => {
                    const t = r.room_type || 'Standard';
                    if (!roomsByType[t]) roomsByType[t] = [];
                    roomsByType[t].push(r);
                });

                // Build booking map
                const bookingMap = {};
                bookings.forEach(b => {
                    if (!bookingMap[b.room_id]) bookingMap[b.room_id] = [];
                    const bStart = b.check_in_date,
                        bEnd = b.check_out_date;
                    let startCol = -1,
                        endCol = -1;
                    for (let i = 0; i < dates.length; i++) {
                        if (dates[i] >= bStart && startCol < 0) startCol = i;
                        if (dates[i] < bEnd) endCol = i;
                    }
                    if (bStart < dates[0]) startCol = 0;
                    if (endCol < 0 && bEnd > dates[0]) endCol = dates.length - 1;
                    if (startCol >= 0 && endCol >= startCol) {
                        bookingMap[b.room_id].push({
                            ...b,
                            startCol,
                            span: endCol - startCol + 1
                        });
                    }
                });

                const dayNames = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
                let g = `<div class="cal-grid" style="grid-template-columns:95px repeat(${days},${COL_W}px);">`;

                // Header row
                g += `<div class="cal-grid-header">`;
                g += `<div class="cg-hdr-room">ROOMS</div>`;
                dates.forEach(dt => {
                    const dd = new Date(dt + 'T00:00:00');
                    const isTd = dt === today;
                    g += `<div class="cg-hdr-date${isTd?' today':''}"><span class="cg-hdr-day">${dayNames[dd.getDay()]}</span> <span class="cg-hdr-num">${dd.getDate()}</span></div>`;
                });
                g += `</div>`;

                // Room rows
                const typeNames = Object.keys(roomsByType);
                typeNames.forEach(typeName => {
                    // Type header
                    g += `<div class="cg-type-hdr">📂 ${typeName}</div>`;
                    for (let i = 0; i < days; i++) g += `<div class="cg-type-price"></div>`;

                    roomsByType[typeName].forEach(room => {
                        // Room label
                        const tShort = (room.room_type || '').toUpperCase().substring(0, 6);
                        g += `<div class="cg-room"><span class="cg-room-type">${tShort}</span><span class="cg-room-num">${room.room_number}</span></div>`;

                        const roomBookings = bookingMap[room.id] || [];
                        // Date cells
                        for (let i = 0; i < days; i++) {
                            const isTd = dates[i] === today;
                            g += `<div class="cg-cell${isTd?' today':''}">`;
                            // Render bars starting on this cell
                            roomBookings.forEach(rb => {
                                if (rb.startCol === i) {
                                    const barW = (rb.span * COL_W) - 10;
                                    let cls = 's-' + (rb.status || '').replace('_', '-');
                                    const isCheckedIn = rb.status === 'checked_in';
                                    const isCheckedOut = rb.status === 'checked_out';
                                    const isPast = rb.check_out_date < today;
                                    const icon = isCheckedIn ? '✓ ' : (isCheckedOut || isPast ? '📭 ' : '');
                                    const name = (rb.guest_name || 'Guest').substring(0, 12);
                                    const code = (rb.booking_code || '').substring(0, 8);
                                    const bData = JSON.stringify({
                                        booking_code: rb.booking_code,
                                        guest_name: rb.guest_name,
                                        check_in_date: rb.check_in_date,
                                        check_out_date: rb.check_out_date,
                                        status: rb.status,
                                        booking_source: rb.booking_source,
                                        payment_status: rb.payment_status
                                    }).replace(/'/g, '&#39;');
                                    g += `<div class="bbar-wrap" style="width:${barW}px;" onclick='showBookingPopup(${bData})'>`;
                                    g += `<div class="bbar ${cls}"><span>${icon}${name} • ${code}</span></div></div>`;
                                }
                            });
                            g += `</div>`;
                        }
                    });
                });

                // Footer row
                g += `<div class="cal-grid-footer">`;
                g += `<div class="cg-ftr-room">ROOMS</div>`;
                dates.forEach(dt => {
                    const dd = new Date(dt + 'T00:00:00');
                    const isTd = dt === today;
                    g += `<div class="cg-ftr-date${isTd?' today':''}"><span class="cg-hdr-day">${dayNames[dd.getDay()]}</span> <span class="cg-hdr-num">${dd.getDate()}</span></div>`;
                });
                g += `</div>`;
                g += '</div>';
                document.getElementById('calGrid').innerHTML = g;

                // Scroll to today
                const todayIdx = dates.indexOf(today);
                if (todayIdx > 1) {
                    const scrollEl = document.getElementById('calScroll');
                    setTimeout(() => {
                        scrollEl.scrollLeft = Math.max(0, (todayIdx - 1) * COL_W);
                    }, 100);
                }
            } catch (e) {
                console.error(e);
                document.getElementById('roomGrid').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>';
            }
        }

        // ═══ BREAKFAST PAGE ═══
        async function loadBreakfast() {
            try {
                const res = await fetch(API + '&action=breakfast_orders');
                const data = await res.json();
                const d = data.data || {};
                const orders = d.orders || [];
                const menuRecap = d.menu_recap || [];
                const stats = d.stats || {};
                const sc = stats.status || {};

                // Stats bar
                document.getElementById('bfStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">🍽️ ORDERS</div><div class="sv" style="color:var(--navy);">${stats.total_orders||0}</div></div>
                <div class="stat-card"><div class="sl">👥 TOTAL PAX</div><div class="sv" style="color:var(--blue);">${stats.total_pax||0}</div></div>
                <div class="stat-card"><div class="sl">⏳ PENDING</div><div class="sv" style="color:#f59e0b;">${sc.pending||0}</div></div>
                <div class="stat-card"><div class="sl">✅ SERVED</div><div class="sv" style="color:var(--green);">${(sc.served||0)+(sc.completed||0)}</div></div>
            </div>`;

                // Menu recap (kitchen prep)
                const recapCard = document.getElementById('bfRecapCard');
                if (menuRecap.length > 0) {
                    recapCard.style.display = '';
                    document.getElementById('bfMenuRecap').innerHTML = menuRecap.map(m =>
                        `<div class="bf-menu-row"><span class="bf-menu-name">${m.menu_name}</span><span class="bf-menu-qty">×${m.qty}</span></div>`
                    ).join('');
                } else {
                    recapCard.style.display = 'none';
                }

                // Order list
                if (orders.length === 0) {
                    document.getElementById('bfOrderList').innerHTML = `
                <div class="bf-empty">
                    <div class="bf-empty-emoji">🍳</div>
                    <div class="bf-empty-text">Belum ada pesanan breakfast hari ini</div>
                </div>`;
                    return;
                }

                let html = '';
                orders.forEach((o, idx) => {
                    const time = o.breakfast_time ? o.breakfast_time.substring(0, 5) : '--:--';
                    const paxRaw = parseInt(o.total_pax, 10);
                    const pax = Number.isFinite(paxRaw) && paxRaw >= 0 ? paxRaw : 0;
                    const room = o.room_display || '-';
                    const orderId = parseInt(o.id || 0, 10) || 0;
                    const loc = {
                        'restaurant': '🍽️ Restaurant',
                        'room_service': '🚪 Room Service',
                        'take_away': '🎁 Take Away'
                    } [o.location] || o.location || '';
                    const statusCls = {
                        'pending': 'bf-st-pending',
                        'preparing': 'bf-st-prep',
                        'served': 'bf-st-served',
                        'completed': 'bf-st-done'
                    } [o.order_status] || 'bf-st-pending';
                    const statusTxt = {
                        'pending': 'Pending',
                        'preparing': 'Preparing',
                        'served': 'Served',
                        'completed': 'Done'
                    } [o.order_status] || o.order_status;
                    const statusClass = (o.order_status || 'pending').toString().toLowerCase();

                    // Menu list
                    let menuRows = '';
                    const items = o.menu_items || [];
                    if (items.length > 0) {
                        items.forEach(m => {
                            const qty = parseInt(m.quantity || 1, 10) || 1;
                            const noteHtml = m.note ? `<div class="bf-menu-note">↳ ${m.note}</div>` : '';
                            menuRows += `<div class="bf-menu-row"><span class="bf-menu-name">${m.menu_name||'Menu'}${noteHtml}</span><span class="bf-menu-qty">x${qty}</span></div>`;
                        });
                    } else {
                        menuRows = `<div class="bf-menu-row"><span class="bf-menu-name">${o.menu_name || 'Menu belum diisi'}</span><span class="bf-menu-qty">x1</span></div>`;
                    }

                    const price = parseFloat(o.total_price || 0);
                    const priceStr = price > 0 ? 'Rp ' + price.toLocaleString('id-ID') : 'Free';
                    const req = o.special_requests ? `<div class="bf-special">💬 ${o.special_requests}</div>` : '';
                    const canComplete = orderId > 0 && o.order_status !== 'completed';
                    const completeBtn = canComplete ?
                        `<button class="bf-complete-btn" onclick="markBreakfastCompleted(${orderId}, this)">✔ Complete</button>` :
                        `<button class="bf-complete-btn" disabled>Completed</button>`;

                    html += `
            <div class="bf-order status-${statusClass}">
                <div class="bf-order-hdr">
                    <div class="bf-head-left">
                        <div class="bf-guest">${o.guest_name||'Guest'}</div>
                        <div class="bf-subline">
                            <span>#${orderId}</span>
                            <span class="bf-subdot"></span>
                            <span>${time}</span>
                        </div>
                    </div>
                    <span class="bf-status ${statusCls}">${statusTxt}</span>
                </div>

                <div class="bf-meta">
                    <span class="bf-chip">🛏️ Room ${room}</span>
                    <span class="bf-chip">👥 ${pax} pax</span>
                    ${loc ? `<span class="bf-chip">${loc}</span>` : ''}
                </div>

                <div class="bf-menus">${menuRows}</div>
                <div class="bf-foot">
                    <span class="bf-price">💳 ${priceStr}</span>
                    ${completeBtn}
                </div>
                ${req}
            </div>`;
                });
                document.getElementById('bfOrderList').innerHTML = html;
            } catch (e) {
                console.error(e);
                document.getElementById('bfOrderList').innerHTML = '<div style="color:var(--red);font-size:11px;padding:10px;">Gagal memuat data breakfast</div>';
            }
        }

        async function markBreakfastCompleted(orderId, btn) {
            if (!orderId) return;
            if (!confirm('Tandai pesanan ini sebagai completed?')) return;

            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Updating...';
            }

            try {
                const fd = new FormData();
                fd.append('action', 'breakfast_mark_completed');
                fd.append('order_id', String(orderId));

                const res = await fetch(API, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();

                if (!data.success) {
                    throw new Error(data.message || 'Gagal update status');
                }

                await loadBreakfast();
            } catch (e) {
                alert(e.message || 'Gagal update status breakfast');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '✔ Complete';
                }
            }
        }

        // ═══ AUTO-LOGIN CHECK ═══
        loadCredentials();
        (async function checkSession() {
            try {
                const res = await fetch(API + '&action=profile');
                const data = await res.json();
                if (data.success && data.data) {
                    showApp(data.data.full_name);
                }
            } catch (e) {
                /* stay on auth screen */
            }
        })();

        // ═══ CUTI PAGE ═══
        let selectedCutiType = 'cuti';

        function selectCutiType(el) {
            document.querySelectorAll('.cuti-type').forEach(i => i.classList.remove('selected'));
            el.classList.add('selected');
            selectedCutiType = el.dataset.type;
        }

        async function submitCuti(e) {
            e.preventDefault();
            const btn = document.getElementById('cutiBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Mengirim...';
            const fd = new FormData(e.target);
            fd.append('action', 'leave_submit');
            fd.append('leave_type', selectedCutiType);
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    body: fd
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (pe) {
                    btn.textContent = '❌ Server error';
                    setTimeout(() => {
                        btn.textContent = '📨 Kirim Pengajuan';
                        btn.disabled = false;
                    }, 2000);
                    return false;
                }
                if (data.success) {
                    btn.textContent = '✅ ' + data.message;
                    e.target.reset();
                    document.querySelectorAll('.cuti-type').forEach(i => i.classList.remove('selected'));
                    document.querySelector('.cuti-type[data-type="cuti"]').classList.add('selected');
                    selectedCutiType = 'cuti';
                    setTimeout(() => {
                        btn.textContent = '📨 Kirim Pengajuan';
                        btn.disabled = false;
                        loadCuti();
                    }, 2000);
                } else {
                    btn.textContent = '❌ ' + data.message;
                    setTimeout(() => {
                        btn.textContent = '📨 Kirim Pengajuan';
                        btn.disabled = false;
                    }, 2500);
                }
            } catch (err) {
                btn.textContent = '❌ Koneksi gagal';
                setTimeout(() => {
                    btn.textContent = '📨 Kirim Pengajuan';
                    btn.disabled = false;
                }, 2000);
            }
            return false;
        }

        async function loadCuti() {
            try {
                const res = await fetch(API + '&action=leave_history');
                const data = await res.json();
                const stats = data.stats || {};
                const rows = data.data || [];

                document.getElementById('cutiStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">⏳ Pending</div><div class="sv" style="color:var(--orange);">${stats.pending||0}</div></div>
                <div class="stat-card"><div class="sl">✅ Disetujui</div><div class="sv" style="color:var(--green);">${stats.approved||0}</div></div>
                <div class="stat-card"><div class="sl">❌ Ditolak</div><div class="sv" style="color:var(--red);">${stats.rejected||0}</div></div>
                <div class="stat-card"><div class="sl">🏖️ Cuti Tahun Ini</div><div class="sv" style="color:var(--blue);">${stats.cuti_used||0}</div></div>
            </div>`;

                // Update badge on toggle button
                const totalCuti = rows.length;
                const pendingCuti = parseInt(stats.pending) || 0;
                const ctBadge = document.getElementById('cutiBadge');
                if (pendingCuti > 0) {
                    ctBadge.textContent = pendingCuti + ' pending';
                    ctBadge.style.display = '';
                } else if (totalCuti > 0) {
                    ctBadge.textContent = totalCuti;
                    ctBadge.style.display = '';
                } else {
                    ctBadge.style.display = 'none';
                }

                if (rows.length === 0) {
                    document.getElementById('cutiHistory').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Belum ada riwayat pengajuan cuti.</div>';
                    return;
                }

                const typeLabel = {
                    cuti: '🏖️ Cuti',
                    sakit: '🩺 Sakit',
                    izin: '📋 Izin',
                    cuti_khusus: '⭐ Khusus'
                };
                const statusCls = {
                    pending: 'ls-pending',
                    approved: 'ls-approved',
                    rejected: 'ls-rejected'
                };
                const statusLabel = {
                    pending: '⏳ Pending',
                    approved: '✅ Disetujui',
                    rejected: '❌ Ditolak'
                };
                let html = '';
                rows.forEach(r => {
                    const s = new Date(r.start_date).toLocaleDateString('id-ID', {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric'
                    });
                    const e = new Date(r.end_date).toLocaleDateString('id-ID', {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric'
                    });
                    const days = Math.ceil((new Date(r.end_date) - new Date(r.start_date)) / 86400000) + 1;
                    html += `<div style="padding:12px 0;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                    <span style="font-weight:700;font-size:12px;">${typeLabel[r.leave_type]||r.leave_type}</span>
                    <span class="leave-status ${statusCls[r.status]||''}">${statusLabel[r.status]||r.status}</span>
                </div>
                <div style="font-size:11px;color:var(--muted);">📅 ${s} — ${e} (${days} hari)</div>
                <div style="font-size:11px;color:var(--text);margin-top:3px;">${r.reason||''}</div>
                ${r.admin_notes ? `<div style="font-size:10px;color:var(--blue);margin-top:3px;background:#eff6ff;padding:4px 8px;border-radius:4px;">💬 ${r.admin_notes}</div>` : ''}
            </div>`;
                });
                document.getElementById('cutiHistory').innerHTML = html;
            } catch (e) {
                document.getElementById('cutiHistory').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>';
            }
        }

        // ═══ RIWAYAT TOGGLE ═══
        function toggleRiwayat(type) {
            const panel = document.getElementById('panelRiwayat' + type.charAt(0).toUpperCase() + type.slice(1));
            const arrow = document.getElementById('arrow' + type.charAt(0).toUpperCase() + type.slice(1));
            const btn = document.getElementById('btnRiwayat' + type.charAt(0).toUpperCase() + type.slice(1));
            const isOpen = panel.classList.toggle('open');
            arrow.classList.toggle('open', isOpen);
            if (isOpen) {
                btn.style.borderRadius = '12px 12px 0 0';
                if (type === 'lembur') loadLembur();
                if (type === 'cuti') loadCuti();
            } else {
                btn.style.borderRadius = '12px';
            }
        }

        // ═══ LEMBUR / OVERTIME ═══
        async function submitLembur(e) {
            e.preventDefault();
            const btn = document.getElementById('lemburBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Mengirim...';
            const fd = new FormData(e.target);
            fd.append('action', 'overtime_submit');
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    body: fd
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (pe) {
                    btn.textContent = '❌ Server error';
                    setTimeout(() => {
                        btn.textContent = '⏰ Ajukan Lembur';
                        btn.disabled = false;
                    }, 2000);
                    return false;
                }
                if (data.success) {
                    btn.textContent = '✅ ' + data.message;
                    document.getElementById('lemburReason').value = '';
                    setTimeout(() => {
                        btn.textContent = '⏰ Ajukan Lembur';
                        btn.disabled = false;
                        loadLembur();
                    }, 2000);
                } else {
                    btn.textContent = '❌ ' + data.message;
                    setTimeout(() => {
                        btn.textContent = '⏰ Ajukan Lembur';
                        btn.disabled = false;
                    }, 2500);
                }
            } catch (err) {
                btn.textContent = '❌ Koneksi gagal';
                setTimeout(() => {
                    btn.textContent = '⏰ Ajukan Lembur';
                    btn.disabled = false;
                }, 2000);
            }
            return false;
        }

        async function loadLembur() {
            try {
                const res = await fetch(API + '&action=overtime_history');
                const data = await res.json();
                const stats = data.stats || {};
                const rows = data.data || [];

                document.getElementById('lemburStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">⏳ Pending</div><div class="sv" style="color:var(--orange);">${stats.pending||0}</div></div>
                <div class="stat-card"><div class="sl">✅ Disetujui</div><div class="sv" style="color:var(--green);">${stats.approved||0}</div></div>
                <div class="stat-card"><div class="sl">❌ Ditolak</div><div class="sv" style="color:var(--red);">${stats.rejected||0}</div></div>
            </div>`;

                // Update badge on toggle button
                const totalLembur = rows.length;
                const pendingLembur = parseInt(stats.pending) || 0;
                const lbBadge = document.getElementById('lemburBadge');
                if (pendingLembur > 0) {
                    lbBadge.textContent = pendingLembur + ' pending';
                    lbBadge.style.display = '';
                } else if (totalLembur > 0) {
                    lbBadge.textContent = totalLembur;
                    lbBadge.style.display = '';
                } else {
                    lbBadge.style.display = 'none';
                }

                if (rows.length === 0) {
                    document.getElementById('lemburHistory').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Belum ada riwayat pengajuan lembur.</div>';
                    return;
                }

                const statusCls = {
                    pending: 'ls-pending',
                    approved: 'ls-approved',
                    rejected: 'ls-rejected'
                };
                const statusLabel = {
                    pending: '⏳ Pending',
                    approved: '✅ Disetujui',
                    rejected: '❌ Ditolak'
                };
                let html = '';
                rows.forEach(r => {
                    const d = new Date(r.overtime_date).toLocaleDateString('id-ID', {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric'
                    });
                    html += `<div style="padding:12px 0;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                    <span style="font-weight:700;font-size:12px;">⏰ Lembur</span>
                    <span class="leave-status ${statusCls[r.status]||''}">${statusLabel[r.status]||r.status}</span>
                </div>
                <div style="font-size:11px;color:var(--muted);">📅 ${d}</div>
                <div style="font-size:11px;color:var(--text);margin-top:3px;">${r.reason||''}</div>
                ${r.admin_notes ? `<div style="font-size:10px;color:var(--blue);margin-top:3px;background:#eff6ff;padding:4px 8px;border-radius:4px;">💬 ${r.admin_notes}</div>` : ''}
            </div>`;
                });
                document.getElementById('lemburHistory').innerHTML = html;
            } catch (e) {
                document.getElementById('lemburHistory').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>';
            }
        }

        // ═══ NOTIFICATIONS ═══
        let notifOpen = false;

        function toggleNotifs() {
            notifOpen = !notifOpen;
            const popup = document.getElementById('notifPopup');
            if (notifOpen) {
                popup.classList.add('open');
                loadNotifs();
            } else {
                popup.classList.remove('open');
            }
        }

        // Close notif popup when clicking outside
        document.addEventListener('click', function(e) {
            if (notifOpen && !e.target.closest('.notif-bell') && !e.target.closest('.notif-popup')) {
                notifOpen = false;
                document.getElementById('notifPopup').classList.remove('open');
            }
        });

        async function loadNotifs() {
            try {
                const res = await fetch(API + '&action=notifications');
                const data = await res.json();
                const notifs = data.data || [];
                const source = data.source || 'legacy';
                if (notifs.length === 0) {
                    document.getElementById('notifList').innerHTML = '<div class="np-empty">🔔 Belum ada notifikasi</div>';
                    return;
                }
                let html = '';
                if (source === 'notifications') {
                    // New format from notifications table
                    notifs.forEach(n => {
                        const d = n.data || {};
                        const status = d.status || '';
                        const icon = status === 'approved' ? '✅' : (status === 'rejected' ? '❌' : '🔔');
                        const color = status === 'approved' ? 'var(--green)' : (status === 'rejected' ? 'var(--red)' : 'var(--navy)');
                        const time = n.created_at ? new Date(n.created_at).toLocaleDateString('id-ID', {
                            day: 'numeric',
                            month: 'short',
                            hour: '2-digit',
                            minute: '2-digit'
                        }) : '';
                        const unreadStyle = n.is_read == 0 ? 'background:#f0f7ff;' : '';
                        html += `<div class="np-item" style="${unreadStyle}">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;">
                        <span style="font-size:14px;">${icon}</span>
                        <span style="font-weight:700;font-size:12px;color:${color};">${n.title||''}</span>
                        <span style="font-size:10px;color:var(--muted);margin-left:auto;">${time}</span>
                    </div>
                    <div style="font-size:11px;color:#555;">${n.message||''}</div>
                </div>`;
                    });
                } else {
                    // Legacy format from leave_requests table
                    const typeLabel = {
                        cuti: '🏖️ Cuti',
                        sakit: '🩺 Sakit',
                        izin: '📋 Izin',
                        cuti_khusus: '⭐ Khusus'
                    };
                    notifs.forEach(n => {
                        const icon = n.status === 'approved' ? '✅' : '❌';
                        const label = n.status === 'approved' ? 'DISETUJUI' : 'DITOLAK';
                        const color = n.status === 'approved' ? 'var(--green)' : 'var(--red)';
                        const s = new Date(n.start_date).toLocaleDateString('id-ID', {
                            day: 'numeric',
                            month: 'short'
                        });
                        const e = new Date(n.end_date).toLocaleDateString('id-ID', {
                            day: 'numeric',
                            month: 'short'
                        });
                        const time = n.approved_at ? new Date(n.approved_at).toLocaleDateString('id-ID', {
                            day: 'numeric',
                            month: 'short',
                            hour: '2-digit',
                            minute: '2-digit'
                        }) : '';
                        html += `<div class="np-item">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;">
                        <span style="font-size:14px;">${icon}</span>
                        <span style="font-weight:700;font-size:12px;color:${color};">${label}</span>
                        <span style="font-size:10px;color:var(--muted);margin-left:auto;">${time}</span>
                    </div>
                    <div style="font-size:11px;">${typeLabel[n.leave_type]||n.leave_type}: ${s} — ${e}</div>
                    ${n.admin_notes ? `<div style="font-size:10px;color:var(--blue);margin-top:2px;">💬 ${n.admin_notes}</div>` : ''}
                </div>`;
                    });
                }
                document.getElementById('notifList').innerHTML = html;
                // Mark as read when opened
                if (source === 'notifications') {
                    fetch(API + '&action=notif_mark_read');
                }
            } catch (e) {
                document.getElementById('notifList').innerHTML = '<div class="np-empty">Gagal memuat</div>';
            }
        }

        async function checkNotifs() {
            try {
                const res = await fetch(API + '&action=notifications');
                const data = await res.json();
                const source = data.source || 'legacy';
                let hasNew = false;
                if (source === 'notifications') {
                    hasNew = (data.unread_count || 0) > 0;
                } else {
                    const notifs = data.data || [];
                    const lastSeen = localStorage.getItem('notif_last_seen') || '';
                    hasNew = notifs.length > 0 && (!lastSeen || notifs[0].approved_at > lastSeen);
                    if (notifOpen && notifs.length > 0) {
                        localStorage.setItem('notif_last_seen', notifs[0].approved_at);
                    }
                }
                const dot = document.getElementById('notifDot');
                const bell = document.querySelector('.notif-bell');
                const wasShowing = dot.classList.contains('show');
                dot.classList.toggle('show', hasNew);
                // Shake bell + vibrate when new notification detected
                if (hasNew && !wasShowing) {
                    bell.classList.add('shake');
                    setTimeout(() => bell.classList.remove('shake'), 1000);
                    if ('vibrate' in navigator) navigator.vibrate([200, 100, 200]);
                }
            } catch (e) {}
        }

        // ═══ FACE SCAN — Full-Screen Responsive (from absen.php) ═══
        let faceModelsLoaded = false;
        let faceStream = null;
        let faceRAF = null;
        let faceStoredDescriptor = null;
        let faceVerifyMode = false;
        let faceGps = null;
        let faceGpsWatcher = null;
        let faceConfig = null;
        let faceDetected = false;
        let faceProcessing = false;
        let faceScanActive = false;
        let faceMatchCount = 0;
        let lastRecognitionTime = 0;
        let nativeFaceDetector = null;

        const MATCH_THRESHOLD = 0.45;
        const WEAK_THRESHOLD = 0.6;
        const REQUIRED_FRAMES = 3;

        try {
            if ('FaceDetector' in window) nativeFaceDetector = new FaceDetector({
                fastMode: true,
                maxDetectedFaces: 1
            });
        } catch (e) {}

        async function loadFaceModels() {
            if (faceModelsLoaded) return true;
            setFaceStatus('Memuat AI model...', 'Neural network initialization');
            let url = FACE_MODEL_URL;
            try {
                const t = await fetch(url + '/tiny_face_detector_model-weights_manifest.json', {
                    method: 'HEAD'
                });
                if (!t.ok) throw new Error();
            } catch (e) {
                url = FACE_MODEL_CDN;
            }
            try {
                setFaceStatus('Loading detector...', '1/3 modules');
                await faceapi.nets.tinyFaceDetector.loadFromUri(url);
                setFaceStatus('Loading landmarks...', '2/3 modules');
                await faceapi.nets.faceLandmark68TinyNet.loadFromUri(url);
                setFaceStatus('Loading recognizer...', '3/3 modules');
                await faceapi.nets.faceRecognitionNet.loadFromUri(url);
                faceModelsLoaded = true;
                try {
                    const wu = document.createElement('canvas');
                    wu.width = wu.height = 128;
                    await faceapi.detectSingleFace(wu, new faceapi.TinyFaceDetectorOptions({
                        inputSize: 128
                    }));
                } catch (e) {}
                return true;
            } catch (e) {
                setFaceStatus('Gagal memuat model', e.message);
                return false;
            }
        }

        function setFaceStatus(main, sub) {
            document.getElementById('faceStatus').textContent = main || '';
            document.getElementById('faceStatusSub').textContent = sub || '';
        }

        function setCorners(state) {
            ['tl', 'tr', 'bl', 'br'].forEach(c => {
                const el = document.getElementById('fc_' + c);
                if (el) {
                    el.classList.remove('detected', 'matched');
                    if (state) el.classList.add(state);
                }
            });
        }

        function setRingProgress(pct) {
            const circle = document.getElementById('ringProgress');
            if (!circle) return;
            const circumference = 2 * Math.PI * 116;
            const offset = circumference - (pct / 100) * circumference;
            circle.style.strokeDashoffset = offset;
            circle.style.stroke = pct > 70 ? 'url(#ringGrad)' : pct > 40 ? '#f0b429' : 'rgba(255,255,255,0.2)';
        }

        function setRingFail() {
            const circle = document.getElementById('ringProgress');
            if (circle) circle.style.stroke = 'url(#ringFail)';
        }

        function updateConfidence(score) {
            const fill = document.getElementById('confFill');
            const label = document.getElementById('confLabel');
            if (!fill || !label) return;
            const arcLength = 210;
            const offset = arcLength - (score / 100) * arcLength;
            fill.style.strokeDashoffset = offset;
            fill.style.stroke = score > 70 ? '#00c896' : score > 40 ? '#f0b429' : '#ef4444';
            label.textContent = score + '% confidence';
        }

        function updateFrameDots(count) {
            for (let i = 0; i < REQUIRED_FRAMES; i++) {
                const el = document.getElementById('fd' + i);
                if (el) el.classList.toggle('filled', i < count);
            }
        }

        function showVerifiedOverlay(name) {
            document.getElementById('verifiedName').textContent = name;
            document.getElementById('verifiedOverlay').classList.add('show');
        }

        async function openFaceScan() {
            const overlay = document.getElementById('faceOverlay');
            overlay.classList.add('show');
            faceScanActive = true;
            faceMatchCount = 0;
            updateFrameDots(0);

            // Reset UI
            document.getElementById('verifiedOverlay').classList.remove('show');
            document.getElementById('registerCard').style.display = 'none';
            document.getElementById('confidenceWrap').style.display = 'none';
            document.getElementById('btnFaceReregister').style.display = 'none';
            setRingProgress(0);
            setCorners('');

            // Parallel loading: data + models + camera
            setFaceStatus('Mempersiapkan...', 'Memuat data, model AI & kamera');

            const [dataResult, modelResult, cameraResult] = await Promise.allSettled([
                (async () => {
                    const res = await fetch(API + '&action=face_data');
                    return res.json();
                })(),
                loadFaceModels(),
                (async () => {
                    try {
                        return await navigator.mediaDevices.getUserMedia({
                            video: {
                                facingMode: 'user',
                                width: {
                                    ideal: 480
                                },
                                height: {
                                    ideal: 480
                                },
                                frameRate: {
                                    ideal: 30
                                }
                            }
                        });
                    } catch (e) {
                        return null;
                    }
                })()
            ]);

            // Process face data
            if (dataResult.status === 'fulfilled' && dataResult.value) {
                const data = dataResult.value;
                if (!data.success) {
                    if (data.auth === false) {
                        doLogout();
                        return;
                    }
                    setFaceStatus('Error', data.message);
                    if (cameraResult.status === 'fulfilled' && cameraResult.value) cameraResult.value.getTracks().forEach(t => t.stop());
                    return;
                }
                faceConfig = data.config;
                const emp = data.employee;
                document.getElementById('faceEmpBadge').textContent = emp.name || '...';
                if (emp.has_face && emp.face_descriptor) {
                    faceStoredDescriptor = new Float32Array(emp.face_descriptor);
                    faceVerifyMode = true;
                } else {
                    faceStoredDescriptor = null;
                    faceVerifyMode = false;
                }
                const att = data.today;
                if (att && att.check_in_time && att.check_out_time && att.scan_3 && att.scan_4) {
                    setFaceStatus('Scan lengkap hari ini', '4/4 scan tercatat');
                    setCorners('matched');
                    setRingProgress(100);
                    if (cameraResult.status === 'fulfilled' && cameraResult.value) cameraResult.value.getTracks().forEach(t => t.stop());
                    return;
                }
            } else {
                setFaceStatus('Jaringan error', 'Tidak dapat mengambil data');
                if (cameraResult.status === 'fulfilled' && cameraResult.value) cameraResult.value.getTracks().forEach(t => t.stop());
                return;
            }

            if (modelResult.status !== 'fulfilled' || !modelResult.value) {
                setFaceStatus('Gagal memuat model AI', 'Coba tutup dan buka kembali');
                if (cameraResult.status === 'fulfilled' && cameraResult.value) cameraResult.value.getTracks().forEach(t => t.stop());
                return;
            }

            startFaceGps();

            // Camera
            try {
                let stream = (cameraResult.status === 'fulfilled') ? cameraResult.value : null;
                if (!stream) {
                    stream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: 'user',
                            width: {
                                ideal: 480
                            },
                            height: {
                                ideal: 480
                            },
                            frameRate: {
                                ideal: 30
                            }
                        }
                    });
                }
                faceStream = stream;
                const video = document.getElementById('faceVideo');
                video.srcObject = faceStream;
                video.setAttribute('playsinline', '');
                await new Promise(r => {
                    video.onloadedmetadata = r;
                });
                await video.play().catch(() => {});

                if (faceVerifyMode) {
                    setFaceStatus('Arahkan wajah ke kamera', 'Neural network aktif — siap memindai');
                    document.getElementById('confidenceWrap').style.display = '';
                    document.getElementById('scanBeam').classList.add('active');
                    document.getElementById('btnFaceReregister').style.display = 'block';
                } else {
                    setFaceStatus('Posisikan wajah dalam bingkai', 'Daftarkan biometrik wajah Anda');
                    document.getElementById('registerCard').style.display = '';
                }
                faceProcessing = false;
                faceDetectRAF();
            } catch (e) {
                setFaceStatus('Kamera gagal', e.message);
            }
        }

        function faceDetectRAF() {
            if (!faceScanActive) return;
            faceRAF = requestAnimationFrame(async () => {
                if (!faceProcessing) {
                    faceProcessing = true;
                    await faceDetectLoop();
                    faceProcessing = false;
                }
                faceDetectRAF();
            });
        }

        function closeFaceScan() {
            faceScanActive = false;
            if (faceRAF) {
                cancelAnimationFrame(faceRAF);
                faceRAF = null;
            }
            if (faceStream) {
                faceStream.getTracks().forEach(t => t.stop());
                faceStream = null;
            }
            if (faceGpsWatcher) {
                navigator.geolocation.clearWatch(faceGpsWatcher);
                faceGpsWatcher = null;
            }
            document.getElementById('scanBeam').classList.remove('active');
            document.getElementById('faceOverlay').classList.remove('show');
            document.getElementById('verifiedOverlay').classList.remove('show');
            document.getElementById('confidenceWrap').style.display = 'none';
            document.getElementById('registerCard').style.display = 'none';
            document.getElementById('btnFaceReregister').style.display = 'none';
            document.getElementById('faceGpsInfo').textContent = '';
            setRingProgress(0);
            setCorners('');
            updateFrameDots(0);
            faceMatchCount = 0;
        }

        function startFaceGps() {
            if (!navigator.geolocation) return;
            faceGpsWatcher = navigator.geolocation.watchPosition(
                pos => {
                    faceGps = pos;
                    const acc = Math.round(pos.coords.accuracy);
                    let info = '📍 ±' + acc + 'm';
                    const locs = faceConfig?.locations || [];
                    if (locs.length > 0) {
                        let nearest = null,
                            nDist = Infinity;
                        locs.forEach(l => {
                            const d = haversineDist(pos.coords.latitude, pos.coords.longitude, l.lat, l.lng);
                            if (d < nDist) {
                                nDist = d;
                                nearest = l;
                            }
                        });
                        info += ' · ' + nDist + 'm dari ' + nearest.name;
                        if (nDist <= nearest.radius) info += ' ✅';
                        else info += ' (maks ' + nearest.radius + 'm)';
                    }
                    document.getElementById('faceGpsInfo').textContent = info;
                },
                () => {
                    document.getElementById('faceGpsInfo').textContent = '📍 GPS tidak tersedia';
                }, {
                    enableHighAccuracy: true,
                    maximumAge: 5000
                }
            );
        }

        function haversineDist(lat1, lng1, lat2, lng2) {
            const R = 6371000,
                dLat = (lat2 - lat1) * Math.PI / 180,
                dLng = (lng2 - lng1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
            return Math.round(2 * R * Math.asin(Math.sqrt(a)));
        }

        async function faceDetectLoop() {
            const video = document.getElementById('faceVideo');
            if (!video || video.readyState < 2) return;

            // ═══ Phase 1: Ultra-fast face PRESENCE detection ═══
            let facePresent = false;

            if (nativeFaceDetector) {
                // Hardware-accelerated browser FaceDetector API (~1-5ms)
                try {
                    const faces = await nativeFaceDetector.detect(video);
                    facePresent = faces.length > 0;
                } catch (e) {
                    nativeFaceDetector = null; // Fallback if API fails
                }
            }

            if (!nativeFaceDetector) {
                // Fallback: face-api.js minimal detect (inputSize 128, no landmarks/descriptor)
                const quickOpts = new faceapi.TinyFaceDetectorOptions({
                    inputSize: 128,
                    scoreThreshold: 0.25
                });
                const quickDet = await faceapi.detectSingleFace(video, quickOpts);
                facePresent = !!quickDet;
            }

            faceDetected = facePresent;

            if (!facePresent) {
                setFaceStatus('Wajah tidak terdeteksi', 'Hadapkan wajah ke kamera');
                setCorners('');
                setRingProgress(0);
                faceMatchCount = 0;
                updateFrameDots(0);
                return;
            }

            setCorners('detected');

            // Face found! Immediate UI feedback
            if (!faceVerifyMode) {
                setFaceStatus('Wajah terdeteksi', 'Tekan tombol untuk mendaftarkan');
                setRingProgress(50);
                return;
            }

            // ═══ Phase 2: Face RECOGNITION (throttled) ═══
            const now = Date.now();
            if (now - lastRecognitionTime < 80) return;
            lastRecognitionTime = now;

            const options = new faceapi.TinyFaceDetectorOptions({
                inputSize: 160,
                scoreThreshold: 0.3
            });
            const detection = await faceapi.detectSingleFace(video, options)
                .withFaceLandmarks(true)
                .withFaceDescriptor();

            if (!detection) {
                faceMatchCount = 0;
                updateFrameDots(0);
                return;
            }

            const dist = faceapi.euclideanDistance(faceStoredDescriptor, detection.descriptor);
            const score = Math.max(0, Math.min(100, Math.round((1 - dist / WEAK_THRESHOLD) * 100)));
            updateConfidence(score);
            setRingProgress(score);

            if (dist < MATCH_THRESHOLD) {
                faceMatchCount++;
                updateFrameDots(faceMatchCount);
                setCorners('matched');
                if (faceMatchCount >= REQUIRED_FRAMES) {
                    // VERIFIED
                    faceScanActive = false;
                    setFaceStatus('Terverifikasi', '');
                    showVerifiedOverlay(document.getElementById('faceEmpBadge').textContent);
                    setTimeout(doFaceClock, 1400);
                    return;
                }
                setFaceStatus('Memverifikasi...', 'Frame ' + faceMatchCount + '/' + REQUIRED_FRAMES);
            } else if (dist < WEAK_THRESHOLD) {
                faceMatchCount = Math.max(0, faceMatchCount - 1);
                updateFrameDots(faceMatchCount);
                setFaceStatus('Hampir cocok ' + score + '%', 'Dekatkan dan stabilkan wajah');
            } else {
                faceMatchCount = 0;
                updateFrameDots(0);
                setFaceStatus('Wajah tidak cocok', 'Pastikan Anda adalah pemilik akun');
                setRingFail();
            }
        }

        async function registerFace() {
            if (!faceDetected) {
                setFaceStatus('Deteksi gagal', 'Pastikan wajah terlihat di kamera');
                return;
            }
            const btn = document.getElementById('btnFaceRegister');
            btn.disabled = true;
            btn.textContent = 'Memproses...';
            faceScanActive = false;
            if (faceRAF) {
                cancelAnimationFrame(faceRAF);
                faceRAF = null;
            }

            const video = document.getElementById('faceVideo');
            const options = new faceapi.TinyFaceDetectorOptions({
                inputSize: 160,
                scoreThreshold: 0.3
            });
            const detection = await faceapi.detectSingleFace(video, options).withFaceLandmarks(true).withFaceDescriptor();
            if (!detection) {
                setFaceStatus('Gagal mendeteksi wajah', 'Coba lagi');
                btn.disabled = false;
                btn.textContent = 'Daftarkan Wajah';
                faceScanActive = true;
                faceDetectRAF();
                return;
            }

            const descriptorArr = Array.from(detection.descriptor);
            const fd = new FormData();
            fd.append('action', 'face_register');
            fd.append('face_descriptor', JSON.stringify(descriptorArr));
            try {
                const res = await fetch(API, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (data.success) {
                    faceStoredDescriptor = new Float32Array(descriptorArr);
                    faceVerifyMode = true;
                    document.getElementById('registerCard').style.display = 'none';
                    document.getElementById('confidenceWrap').style.display = '';
                    document.getElementById('scanBeam').classList.add('active');
                    document.getElementById('btnFaceReregister').style.display = 'block';
                    showVerifiedOverlay(document.getElementById('faceEmpBadge').textContent);
                    document.getElementById('verifiedSub').textContent = 'Biometrik Terdaftar';
                    setTimeout(() => {
                        document.getElementById('verifiedOverlay').classList.remove('show');
                        setFaceStatus('Arahkan wajah untuk verifikasi', 'Biometrik siap digunakan');
                        faceScanActive = true;
                        faceDetectRAF();
                    }, 2000);
                } else {
                    setFaceStatus('Gagal mendaftar', data.message);
                    btn.disabled = false;
                    btn.textContent = 'Daftarkan Wajah';
                    faceScanActive = true;
                    faceDetectRAF();
                }
            } catch (e) {
                setFaceStatus('Jaringan error', e.message);
                btn.disabled = false;
                btn.textContent = 'Daftarkan Wajah';
                faceScanActive = true;
                faceDetectRAF();
            }
        }

        function reregisterFace() {
            faceVerifyMode = false;
            faceStoredDescriptor = null;
            faceMatchCount = 0;
            lastRecognitionTime = 0;
            updateFrameDots(0);
            setRingProgress(0);
            setCorners('');
            document.getElementById('confidenceWrap').style.display = 'none';
            document.getElementById('btnFaceReregister').style.display = 'none';
            document.getElementById('registerCard').style.display = '';
            const btn = document.getElementById('btnFaceRegister');
            btn.disabled = false;
            btn.textContent = 'Daftarkan Wajah Baru';
            setFaceStatus('Posisikan wajah baru', 'Tap tombol daftar setelah wajah terdeteksi');
        }

        async function doFaceClock() {
            setFaceStatus('Menyimpan absensi...', 'Mengirim ke server');

            let address = '';
            if (faceGps) {
                try {
                    const r = await fetch('https://nominatim.openstreetmap.org/reverse?lat=' + faceGps.coords.latitude + '&lon=' + faceGps.coords.longitude + '&format=json');
                    const g = await r.json();
                    address = g.display_name || '';
                } catch (e) {}
            }

            const fd = new FormData();
            fd.append('action', 'face_clock');
            fd.append('lat', faceGps ? faceGps.coords.latitude : 0);
            fd.append('lng', faceGps ? faceGps.coords.longitude : 0);
            fd.append('address', address);

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (data.success) {
                    setFaceStatus('Absen berhasil!', data.message);
                    document.getElementById('verifiedSub').textContent = data.message;
                } else {
                    setFaceStatus('Gagal', data.message);
                }
                setTimeout(() => {
                    closeFaceScan();
                    loadAbsen();
                }, 2000);
            } catch (e) {
                setFaceStatus('Jaringan error', e.message);
                setTimeout(closeFaceScan, 2000);
            }
        }

        // ═══ Absen Manual (fallback ketika Face ID lambat/gagal) ═══
        // Catatan: server (staff-api.php action=face_clock) tetap WAJIB memvalidasi
        // radius GPS untuk mode ini juga — pengecekan di sini hanya untuk UX,
        // bukan satu-satunya pengaman.
        let manualGps = null;
        let manualGpsWatcher = null;

        async function openManualAttendance() {
            document.getElementById('manualOverlay').style.display = 'block';
            document.getElementById('manualPopup').style.display = 'block';
            const statusEl = document.getElementById('manualStatus');
            const btn = document.getElementById('manualConfirmBtn');
            btn.classList.remove('mp-confirm-retry');
            btn.onclick = confirmManualAttendance;
            statusEl.style.color = '';
            statusEl.innerHTML = '📍 Mencari lokasi GPS...';
            btn.disabled = true;
            btn.textContent = 'Konfirmasi Absen';
            manualGps = null;

            // Reuse face config (locations/radius) if not loaded yet
            if (!faceConfig) {
                try {
                    const res = await fetch(API + '&action=face_data');
                    const data = await res.json();
                    if (data.success) faceConfig = data.config;
                } catch (e) {}
            }

            if (!navigator.geolocation) {
                statusEl.textContent = '❌ GPS tidak didukung perangkat/browser ini.';
                statusEl.style.color = 'var(--red)';
                return;
            }

            // Cek status izin lokasi lebih dulu (Chrome/Android). Safari/iOS belum
            // mendukung Permissions API untuk geolocation - akan langsung lanjut
            // ke watchPosition dan biarkan browser yang munculkan prompt izin.
            if (navigator.permissions && navigator.permissions.query) {
                try {
                    const perm = await navigator.permissions.query({
                        name: 'geolocation'
                    });
                    if (perm.state === 'denied') {
                        showManualLocationBlocked();
                        return;
                    }
                } catch (e) {}
            }

            if (manualGpsWatcher) navigator.geolocation.clearWatch(manualGpsWatcher);
            manualGpsWatcher = navigator.geolocation.watchPosition(
                pos => {
                    manualGps = pos;
                    updateManualGpsStatus();
                },
                err => handleManualGeoError(err), {
                    enableHighAccuracy: true,
                    maximumAge: 5000,
                    timeout: 15000
                }
            );
        }

        // Izin lokasi belum aktif / ditolak — tampilkan panduan cara mengaktifkan
        // dan ubah tombol jadi "Coba Lagi" alih-alih terkunci selamanya.
        function showManualLocationBlocked() {
            const statusEl = document.getElementById('manualStatus');
            const btn = document.getElementById('manualConfirmBtn');
            statusEl.style.color = 'var(--red)';
            statusEl.innerHTML = '🔒 Izin lokasi belum diaktifkan.<br>' +
                '<span style="font-weight:500;font-size:10px;display:block;margin-top:4px;">' +
                'Aktifkan lewat: ikon 🔒/ⓘ di address bar → Izin/Permission → Lokasi → Izinkan. ' +
                'Atau: Pengaturan HP → Aplikasi → Browser → Izin → Lokasi.</span>';
            btn.disabled = false;
            btn.textContent = 'Coba Lagi';
            btn.classList.add('mp-confirm-retry');
            btn.onclick = openManualAttendance;
        }

        function handleManualGeoError(err) {
            const statusEl = document.getElementById('manualStatus');
            const btn = document.getElementById('manualConfirmBtn');
            if (err && err.code === 1) { // PERMISSION_DENIED
                showManualLocationBlocked();
                return;
            }
            statusEl.style.color = 'var(--red)';
            if (err && err.code === 3) { // TIMEOUT
                statusEl.textContent = '⏱️ Waktu habis mencari sinyal GPS. Pastikan GPS/Lokasi HP aktif.';
            } else {
                statusEl.textContent = '❌ Lokasi tidak tersedia. Pastikan GPS/Lokasi HP aktif.';
            }
            btn.disabled = false;
            btn.textContent = 'Coba Lagi';
            btn.classList.add('mp-confirm-retry');
            btn.onclick = openManualAttendance;
        }

        function updateManualGpsStatus() {
            const statusEl = document.getElementById('manualStatus');
            const btn = document.getElementById('manualConfirmBtn');
            if (!manualGps) return;
            btn.classList.remove('mp-confirm-retry');
            btn.onclick = confirmManualAttendance;
            btn.textContent = 'Konfirmasi Absen';
            const acc = Math.round(manualGps.coords.accuracy);
            const locs = faceConfig?.locations || [];
            if (locs.length === 0) {
                statusEl.textContent = '📍 GPS aktif (±' + acc + 'm)';
                statusEl.style.color = '';
                btn.disabled = false;
                return;
            }
            let nearest = null,
                nDist = Infinity;
            locs.forEach(l => {
                const d = haversineDist(manualGps.coords.latitude, manualGps.coords.longitude, l.lat, l.lng);
                if (d < nDist) {
                    nDist = d;
                    nearest = l;
                }
            });
            if (nDist <= nearest.radius) {
                statusEl.textContent = '✅ ' + nDist + 'm dari ' + nearest.name + ' (dalam radius ' + nearest.radius + 'm)';
                statusEl.style.color = 'var(--green)';
                btn.disabled = false;
            } else {
                statusEl.textContent = '❌ ' + nDist + 'm dari ' + nearest.name + ' — di luar radius (maks ' + nearest.radius + 'm)';
                statusEl.style.color = 'var(--red)';
                btn.disabled = true;
            }
        }

        function closeManualAttendance() {
            document.getElementById('manualOverlay').style.display = 'none';
            document.getElementById('manualPopup').style.display = 'none';
            if (manualGpsWatcher) {
                navigator.geolocation.clearWatch(manualGpsWatcher);
                manualGpsWatcher = null;
            }
        }

        async function confirmManualAttendance() {
            if (!manualGps) return;
            const statusEl = document.getElementById('manualStatus');
            const btn = document.getElementById('manualConfirmBtn');
            btn.disabled = true;
            btn.textContent = 'Menyimpan...';

            let address = '';
            try {
                const r = await fetch('https://nominatim.openstreetmap.org/reverse?lat=' + manualGps.coords.latitude + '&lon=' + manualGps.coords.longitude + '&format=json');
                const g = await r.json();
                address = g.display_name || '';
            } catch (e) {}

            const fd = new FormData();
            fd.append('action', 'face_clock');
            fd.append('mode', 'manual');
            fd.append('lat', manualGps.coords.latitude);
            fd.append('lng', manualGps.coords.longitude);
            fd.append('address', address);

            try {
                const res = await fetch(API, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                statusEl.textContent = (data.success ? '✅ ' : '❌ ') + data.message;
                statusEl.style.color = data.success ? 'var(--green)' : 'var(--red)';
                if (data.success) {
                    setTimeout(() => {
                        closeManualAttendance();
                        loadAbsen();
                    }, 1500);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Konfirmasi Absen';
                }
            } catch (e) {
                statusEl.textContent = '❌ Jaringan error: ' + e.message;
                statusEl.style.color = 'var(--red)';
                btn.disabled = false;
                btn.textContent = 'Konfirmasi Absen';
            }
        }

        // Check notifications every 60s
        setInterval(checkNotifs, 60000);
        setTimeout(checkNotifs, 3000);
    </script>

    <!-- Install Banner — fixed bottom, works on auth + app -->
    <div class="install-banner" id="installBanner">
        <div class="ib-icon">📲</div>
        <div class="ib-text">
            <div class="ib-title">Install Staff Portal</div>
            <div class="ib-sub">Akses lebih cepat dari home screen</div>
        </div>
        <button class="ib-action" id="ibAction">Install</button>
        <button class="ib-close" onclick="event.stopPropagation();this.parentElement.classList.remove('show');localStorage.setItem('ib_dismissed','1');">✕</button>
    </div>

    <!-- PWA Install Logic — MUST be after banner HTML -->
    <script>
        (function() {
            // Register SW immediately
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('sw.js', {
                        scope: './'
                    })
                    .then(reg => console.log('[PWA] SW registered, scope:', reg.scope))
                    .catch(err => console.error('[PWA] SW failed:', err));
            }

            let deferredPrompt = null;
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
            const wasDismissed = localStorage.getItem('ib_dismissed') === '1';
            const banner = document.getElementById('installBanner');
            const ibBtn = document.getElementById('ibAction');

            console.log('[PWA] standalone:', isStandalone, 'iOS:', isIOS, 'dismissed:', wasDismissed);

            // Already installed as PWA — hide everything
            if (isStandalone) return;

            function showBanner(mode) {
                if (wasDismissed || !banner) return;
                if (banner.classList.contains('show')) return;
                if (mode === 'manual') {
                    banner.querySelector('.ib-title').textContent = 'Install Staff Portal';
                    banner.querySelector('.ib-sub').textContent = 'Tap ⋮ menu Chrome → "Install app"';
                    ibBtn.textContent = 'Cara Install';
                    ibBtn.dataset.mode = 'manual';
                } else {
                    banner.querySelector('.ib-title').textContent = 'Install Staff Portal';
                    banner.querySelector('.ib-sub').textContent = 'Buka langsung dari home screen';
                    ibBtn.textContent = 'Install';
                    ibBtn.dataset.mode = 'native';
                }
                banner.classList.add('show');
                console.log('[PWA] Banner shown:', mode);
            }

            // Catch beforeinstallprompt
            window.addEventListener('beforeinstallprompt', (e) => {
                console.log('[PWA] beforeinstallprompt fired!');
                e.preventDefault();
                deferredPrompt = e;
                showBanner('native');
            });

            // Fallback timers for Android Chrome if prompt doesn't fire
            if (!isIOS && !wasDismissed) {
                [4000, 10000, 20000].forEach(ms => {
                    setTimeout(() => {
                        if (!deferredPrompt && !isStandalone) showBanner('manual');
                    }, ms);
                });
            }

            // iOS guide
            if (isIOS && !localStorage.getItem('ios_guide_dismissed')) {
                const guide = document.getElementById('iosGuide');
                if (guide) guide.style.display = 'block';
            }

            // Install button click
            ibBtn.addEventListener('click', async (e) => {
                e.stopPropagation();

                // Manual mode — show guide
                if (!deferredPrompt || ibBtn.dataset.mode === 'manual') {
                    showManualGuide();
                    return;
                }

                // Native mode — show progress + trigger Chrome prompt
                const prog = document.getElementById('installProgress');
                const bar = document.getElementById('ipBarFill');
                const step = document.getElementById('ipStep');

                // Reset progress UI
                document.getElementById('ipSub').style.display = '';
                document.querySelector('.ip-bar').style.display = '';
                step.style.display = '';
                document.getElementById('ipDone').style.display = 'none';
                bar.style.width = '0%';

                prog.classList.add('show');

                bar.style.width = '20%';
                step.textContent = 'Menyiapkan manifest...';
                await sleep(400);
                bar.style.width = '40%';
                step.textContent = 'Mengunduh icon...';
                await sleep(400);
                bar.style.width = '60%';
                step.textContent = 'Mempersiapkan app...';

                try {
                    deferredPrompt.prompt();
                    const result = await deferredPrompt.userChoice;

                    if (result.outcome === 'accepted') {
                        bar.style.width = '80%';
                        step.textContent = 'Installing...';
                        await sleep(500);
                        bar.style.width = '100%';
                        step.textContent = '';
                        await sleep(400);

                        // Show success
                        document.getElementById('ipSub').style.display = 'none';
                        document.querySelector('.ip-bar').style.display = 'none';
                        step.style.display = 'none';
                        document.getElementById('ipDone').style.display = 'flex';
                        await sleep(4000);
                    }
                } catch (err) {
                    console.error('[PWA] Install error:', err);
                    step.textContent = 'Gagal install, coba manual...';
                    await sleep(1500);
                }

                prog.classList.remove('show');
                banner.classList.remove('show');
                deferredPrompt = null;
            });

            // Banner body click
            banner.addEventListener('click', (e) => {
                if (e.target.closest('.ib-close') || e.target.closest('.ib-action')) return;
                ibBtn.click();
            });

            // App installed event
            window.addEventListener('appinstalled', () => {
                console.log('[PWA] App installed!');
                banner.classList.remove('show');
                localStorage.removeItem('ib_dismissed');
                deferredPrompt = null;

                const prog = document.getElementById('installProgress');
                if (!prog.classList.contains('show')) {
                    prog.classList.add('show');
                    document.getElementById('ipSub').style.display = 'none';
                    document.querySelector('.ip-bar').style.display = 'none';
                    document.getElementById('ipStep').style.display = 'none';
                    document.getElementById('ipDone').style.display = 'flex';
                    setTimeout(() => prog.classList.remove('show'), 4000);
                }
            });

            function showManualGuide() {
                // Create fullscreen guide overlay
                const ov = document.createElement('div');
                ov.style.cssText = 'position:fixed;inset:0;z-index:2000;background:rgba(5,10,24,.96);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;animation:faceIn .3s ease;';
                ov.innerHTML = `
            <div style="text-align:center;max-width:320px;">
                <div style="font-size:56px;margin-bottom:16px;">📲</div>
                <h3 style="color:#fff;font-size:18px;font-weight:700;margin:0 0 8px;">Install Staff Portal</h3>
                <p style="color:rgba(255,255,255,.5);font-size:12px;margin:0 0 28px;">Ikuti langkah berikut di browser Chrome:</p>
                <div style="text-align:left;">
                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:20px;">
                        <div style="width:32px;height:32px;background:linear-gradient(135deg,#f0b429,#e09800);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#0d1f3c;flex-shrink:0;">1</div>
                        <div>
                            <div style="color:#fff;font-size:14px;font-weight:600;">Tap menu ⋮</div>
                            <div style="color:rgba(255,255,255,.4);font-size:11px;margin-top:2px;">3 titik di kanan atas Chrome</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:20px;">
                        <div style="width:32px;height:32px;background:linear-gradient(135deg,#f0b429,#e09800);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#0d1f3c;flex-shrink:0;">2</div>
                        <div>
                            <div style="color:#fff;font-size:14px;font-weight:600;">Pilih "Install app"</div>
                            <div style="color:rgba(255,255,255,.4);font-size:11px;margin-top:2px;">Atau "Add to Home screen"</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:28px;">
                        <div style="width:32px;height:32px;background:linear-gradient(135deg,#34d399,#059669);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#fff;flex-shrink:0;">3</div>
                        <div>
                            <div style="color:#fff;font-size:14px;font-weight:600;">Tap "Install"</div>
                            <div style="color:rgba(255,255,255,.4);font-size:11px;margin-top:2px;">App muncul di home screen!</div>
                        </div>
                    </div>
                </div>
                <button onclick="this.closest('div[style]').parentElement.remove();" style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:#fff;padding:12px 32px;border-radius:12px;font-size:13px;font-weight:600;cursor:pointer;width:100%;">Mengerti</button>
            </div>
        `;
                document.body.appendChild(ov);
                ov.addEventListener('click', (e) => {
                    if (e.target === ov) ov.remove();
                });
            }

            function sleep(ms) {
                return new Promise(r => setTimeout(r, ms));
            }

        })(); // end PWA IIFE

        // ═══ SLIP GAJI PAGE ═══
        let slipPeriodsLoaded = false;
        let currentSlipData = null;

        async function loadSlipGaji() {
            const sel = document.getElementById('slipPeriod');
            const content = document.getElementById('slipGajiContent');
            const dlBtn = document.getElementById('btnDownloadSlip');
            if (dlBtn) dlBtn.style.display = 'none';

            // Load periods dropdown once
            if (!slipPeriodsLoaded) {
                try {
                    const res = await fetch(API + '&action=salary_periods');
                    const data = await res.json();
                    if (!data.success && data.auth === false) {
                        doLogout();
                        return;
                    }
                    const periods = data.data || [];
                    if (periods.length === 0) {
                        sel.innerHTML = '<option value="">Belum ada data</option>';
                        content.innerHTML = '<div style="text-align:center;padding:40px 16px;"><div style="font-size:48px;margin-bottom:12px;">📋</div><div style="font-size:13px;color:var(--muted);">Belum ada slip gaji yang tersedia.</div><div style="font-size:11px;color:var(--muted);margin-top:4px;">Slip gaji akan muncul setelah payroll diproses admin.</div></div>';
                        slipPeriodsLoaded = true;
                        return;
                    }
                    sel.innerHTML = periods.map(p => `<option value="${p.id}" ${p.is_latest ? 'selected' : ''}>${p.period_label} — ${p.status_label}</option>`).join('');
                    slipPeriodsLoaded = true;
                } catch (e) {
                    sel.innerHTML = '<option value="">Gagal memuat</option>';
                    content.innerHTML = '<div style="color:var(--red);font-size:11px;text-align:center;">Gagal memuat data periode</div>';
                    return;
                }
            }

            const periodId = sel.value;
            if (!periodId) return;

            content.innerHTML = '<div class="loading"><span class="spin"></span> Memuat slip gaji...</div>';

            try {
                const res = await fetch(API + '&action=salary_slip&period_id=' + periodId);
                const data = await res.json();
                if (!data.success) {
                    content.innerHTML = `<div style="text-align:center;padding:40px 16px;"><div style="font-size:48px;margin-bottom:12px;">${data.pending ? '⏳' : '📋'}</div><div style="font-size:13px;color:var(--muted);">${data.message || 'Slip gaji tidak ditemukan'}</div></div>`;
                    return;
                }
                currentSlipData = data.data;
                renderSlipGaji(data.data);
                if (dlBtn) dlBtn.style.display = 'flex';
            } catch (e) {
                content.innerHTML = '<div style="color:var(--red);font-size:11px;text-align:center;padding:20px;">Gagal memuat slip gaji</div>';
            }
        }

        function renderSlipGaji(slip) {
            const content = document.getElementById('slipGajiContent');
            const fmt = (n) => new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
            const overtimeHours = parseFloat(slip.overtime_total_hours ?? slip.overtime_hours) || 0;
            const baseSalary = parseFloat(slip.base_salary) || 0;
            const overtimeRegularAmount = parseFloat(slip.overtime_regular_amount ?? slip.overtime_amount) || 0;
            const overtimeExtraHours = parseFloat(slip.extra_hours) || 0;
            const overtimeExtraAmount = parseFloat(slip.extra_overtime_amount) || 0;
            const overtimeAmount = parseFloat(slip.overtime_total_amount ?? (overtimeRegularAmount + overtimeExtraAmount)) || 0;
            const incentive = parseFloat(slip.incentive) || 0;
            const allowance = parseFloat(slip.allowance) || 0;
            const uangMakan = parseFloat(slip.uang_makan) || 0;
            const bonus = parseFloat(slip.bonus) || 0;
            const otherIncome = parseFloat(slip.other_income) || 0;
            const totalEarnings = parseFloat(slip.total_earnings) || 0;
            const dLoan = parseFloat(slip.deduction_loan) || 0;
            const dAbsence = parseFloat(slip.deduction_absence) || 0;
            const dTax = parseFloat(slip.deduction_tax) || 0;
            const dBpjs = parseFloat(slip.deduction_bpjs) || 0;
            const dOther = parseFloat(slip.deduction_other) || 0;
            const totalDeductions = parseFloat(slip.total_deductions) || 0;
            const netSalary = totalEarnings - totalDeductions;

            const logoHtml = SLIP_LOGO_URL ? `<img src="${SLIP_LOGO_URL}" style="height:72px;max-width:180px;object-fit:contain;" crossorigin="anonymous">` : `<span style="font-size:34px;">🏨</span>`;

            const monthNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            const periodText = monthNames[parseInt(slip.period_month)] + ' ' + slip.period_year;

            const slipRow = (label, value, isDeduct, isBold) => {
                const color = isDeduct ? '#dc2626' : (isBold ? '#059669' : '#1e293b');
                const weight = isBold ? '700' : '400';
                const prefix = isDeduct ? '-' : '';
                return `<tr><td style="padding:5px 0;font-size:11px;color:#475569;border-bottom:1px solid #f1f5f9;">${label}</td><td style="padding:5px 0;font-size:11px;color:${color};font-weight:${weight};text-align:right;border-bottom:1px solid #f1f5f9;font-family:'SF Mono',Monaco,Consolas,monospace;">${prefix}Rp ${fmt(Math.abs(value))}</td></tr>`;
            };

            const totalRow = (label, value, bgColor, textColor) => {
                return `<tr><td style="padding:7px 0;font-size:11.5px;font-weight:700;color:${textColor};">${label}</td><td style="padding:7px 0;font-size:11.5px;font-weight:800;color:${textColor};text-align:right;font-family:'SF Mono',Monaco,Consolas,monospace;">Rp ${fmt(value)}</td></tr>`;
            };

            content.innerHTML = `
    <div id="slipGajiPrintArea" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,.08);border:1px solid #e2e8f0;">
        
        <!-- Header -->
        <div style="background:#fff;padding:20px 16px 16px;border-bottom:2px solid #0f172a;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                ${logoHtml}
                <div style="flex:1;">
                    <div style="font-size:15px;font-weight:800;color:#0f172a;letter-spacing:.3px;line-height:1.2;">${BIZ_NAME}</div>
                    <div style="font-size:9px;color:#64748b;letter-spacing:.3px;margin-top:2px;">Karimunjawa, Jepara • Indonesia</div>
                </div>
            </div>
            <div style="background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:10px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <div style="font-size:8px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:1.5px;font-weight:600;">Slip Gaji Karyawan</div>
                    <div style="font-size:16px;font-weight:700;color:#fff;margin-top:3px;">Periode ${periodText}</div>
                </div>
                <div style="width:40px;height:40px;background:rgba(255,255,255,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;">💰</div>
            </div>
        </div>

        <!-- Employee Info -->
        <div style="padding:14px 16px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid #e2e8f0;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Nama Karyawan</div>
                    <div style="font-size:12px;font-weight:700;color:#0f172a;margin-top:1px;">${slip.employee_name}</div>
                </div>
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Jabatan</div>
                    <div style="font-size:12px;font-weight:600;color:#334155;margin-top:1px;">${slip.position || '-'}</div>
                </div>
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">NIK / Kode</div>
                    <div style="font-size:12px;font-weight:600;color:#334155;margin-top:1px;font-family:monospace;">${slip.employee_code || '-'}</div>
                </div>
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Departemen</div>
                    <div style="font-size:12px;font-weight:600;color:#334155;margin-top:1px;">${slip.department || '-'}</div>
                </div>
            </div>
        </div>

        <!-- Salary Summary -->
        <div style="padding:10px 12px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;border-bottom:1px solid #e2e8f0;">
            <div style="text-align:center;background:linear-gradient(135deg,#f0fdf4,#bbf7d0);border-radius:9px;padding:10px 6px;min-width:0;">
                <div style="font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:3px;">Gaji Pokok</div>
                <div style="font-size:14px;font-weight:800;color:#059669;line-height:1.05;">Rp ${fmt(baseSalary)}</div>
            </div>
            <div style="text-align:center;background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:9px;padding:10px 6px;min-width:0;">
                <div style="font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:3px;">Lembur OT</div>
                <div style="font-size:14px;font-weight:800;color:#b45309;line-height:1.05;">Rp ${fmt(overtimeRegularAmount)}</div>
                <div style="font-size:7px;color:#92400e;margin-top:2px;">OT harian</div>
            </div>
            <div style="text-align:center;background:linear-gradient(135deg,#fff7ed,#fed7aa);border-radius:9px;padding:10px 6px;border:1px solid rgba(234,88,12,.12);min-width:0;">
                <div style="font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:3px;">Lembur Extra</div>
                <div style="font-size:14px;font-weight:800;color:#ea580c;line-height:1.05;">Rp ${fmt(overtimeExtraAmount)}</div>
                <div style="font-size:7px;color:#9a3412;margin-top:2px;">${overtimeExtraHours} jam</div>
            </div>
        </div>

        <!-- Earnings Table -->
        <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;">
                <div style="width:6px;height:6px;background:#10b981;border-radius:50%;"></div>
                <div style="font-size:10px;font-weight:700;color:#059669;text-transform:uppercase;letter-spacing:.8px;">Pendapatan</div>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                ${slipRow('Gaji Pokok', baseSalary, false, false)}
                ${slipRow('Uang Lembur Harian', overtimeRegularAmount, false, false)}
                ${slipRow('Lembur Extra (>26 hari)', overtimeExtraAmount, false, false)}
                <tr><td colspan="2" style="padding-top:4px;border-top:1px solid #cbd5e1;"></td></tr>
                ${slipRow('Total Uang Lembur', overtimeAmount, false, true)}
                ${slipRow('Service', incentive, false, false)}
                ${slipRow('Tunjangan', allowance, false, false)}
                ${slipRow('Uang Makan', uangMakan, false, false)}
                ${slipRow('Bonus', bonus, false, false)}
                ${slipRow('Pendapatan Lainnya', otherIncome, false, false)}
                ${totalRow('Total Pendapatan', totalEarnings, '#f0fdf4', '#059669')}
            </table>
        </div>

        <!-- Deductions Table -->
        <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;">
                <div style="width:6px;height:6px;background:#ef4444;border-radius:50%;"></div>
                <div style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.8px;">Potongan</div>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                ${slipRow('Pinjaman / Kasbon', dLoan, true, false)}
                ${slipRow('Potongan Absensi', dAbsence, true, false)}
                ${slipRow('Pajak (PPh 21)', dTax, true, false)}
                ${slipRow('BPJS', dBpjs, true, false)}
                ${slipRow('Potongan Lainnya', dOther, true, false)}
                ${totalRow('Total Potongan', totalDeductions, '#fef2f2', '#dc2626')}
            </table>
        </div>

        <!-- Net Salary -->
        <div style="padding:16px;background:linear-gradient(135deg,#059669,#10b981);">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:9px;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:1px;">Total Gaji (Take Home Pay)</div>
                    <div style="font-size:22px;font-weight:800;color:#fff;margin-top:3px;font-family:'SF Mono',Monaco,Consolas,monospace;">Rp ${fmt(netSalary)}</div>
                </div>
                <div style="font-size:28px;">💰</div>
            </div>
        </div>

        ${slip.bank_name ? `
        <!-- Bank Transfer -->
        <div style="padding:14px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                <div style="width:6px;height:6px;background:#2563eb;border-radius:50%;"></div>
                <div style="font-size:10px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.8px;">Transfer Bank</div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;">Bank</div>
                    <div style="font-size:12px;font-weight:700;color:#1e3a8a;">${slip.bank_name}</div>
                </div>
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;">No. Rekening</div>
                    <div style="font-size:12px;font-weight:700;color:#1e3a8a;font-family:monospace;">${slip.bank_account || '-'}</div>
                </div>
            </div>
        </div>` : ''}

        <!-- Footer -->
        <div style="padding:10px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
            <div style="font-size:8px;color:#94a3b8;">Dokumen resmi — digenerate otomatis oleh ${BIZ_NAME} Payroll System</div>
            <div style="font-size:8px;color:#cbd5e1;margin-top:2px;">Slip ID: #${slip.id} • ${new Date().toLocaleDateString('id-ID', {day:'numeric',month:'long',year:'numeric'})}</div>
        </div>
    </div>
    `;
        }

        // Download slip gaji as image
        async function downloadSlipGaji() {
            if (!currentSlipData) return;
            const btn = document.getElementById('btnDownloadSlip');
            const origText = btn.innerHTML;
            btn.innerHTML = '⏳ Proses...';
            btn.disabled = true;

            try {
                // Load html2canvas dynamically
                if (typeof html2canvas === 'undefined') {
                    await new Promise((resolve, reject) => {
                        const sc = document.createElement('script');
                        sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                        sc.onload = resolve;
                        sc.onerror = reject;
                        document.head.appendChild(sc);
                    });
                }

                const el = document.getElementById('slipGajiPrintArea');
                const canvas = await html2canvas(el, {
                    scale: 2,
                    backgroundColor: '#ffffff',
                    useCORS: true,
                    logging: false
                });

                const link = document.createElement('a');
                const monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
                const fname = 'SlipGaji_' + currentSlipData.employee_name.replace(/\s+/g, '_') + '_' + monthNames[parseInt(currentSlipData.period_month)] + currentSlipData.period_year + '.png';
                link.download = fname;
                link.href = canvas.toDataURL('image/png');
                link.click();
            } catch (e) {
                alert('Gagal download slip gaji. Coba lagi.');
                console.error(e);
            } finally {
                btn.innerHTML = origText;
                btn.disabled = false;
            }
        }
    </script>

    <!-- Staff Push Notification -->
    <script>
        (function() {
            const VAPID_ENDPOINT = '<?php echo rtrim(BASE_URL, "/"); ?>/api/push-subscription.php';
            const PUSH_API = VAPID_ENDPOINT;

            async function initStaffPush() {
                if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

                const empId = localStorage.getItem('staff_employee_id');
                if (!empId) return;

                try {
                    const reg = await navigator.serviceWorker.ready;

                    // Fetch VAPID key
                    const resp = await fetch(PUSH_API + '?action=vapid-public-key');
                    const kd = await resp.json();
                    if (!kd.success || !kd.publicKey) return;

                    const vapidKey = urlBase64ToUint8Array(kd.publicKey);

                    // Check permission
                    if (Notification.permission === 'granted') {
                        await subscribePush(reg, vapidKey, empId);
                    } else if (Notification.permission === 'default') {
                        // Show prompt after 5 seconds 
                        setTimeout(() => showPushPrompt(reg, vapidKey, empId), 5000);
                    }
                } catch (e) {
                    console.warn('[StaffPush] Init error:', e);
                }
            }

            async function subscribePush(reg, vapidKey, empId) {
                try {
                    let sub = await reg.pushManager.getSubscription();
                    if (!sub) {
                        sub = await reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: vapidKey
                        });
                    }
                    await fetch(PUSH_API, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'subscribe',
                            subscription: sub.toJSON(),
                            employee_id: parseInt(empId)
                        })
                    });
                    console.log('[StaffPush] Subscribed OK');
                } catch (e) {
                    console.warn('[StaffPush] Subscribe failed:', e);
                }
            }

            function showPushPrompt(reg, vapidKey, empId) {
                if (localStorage.getItem('staff_push_prompted')) return;
                const el = document.createElement('div');
                el.id = 'staffPushPrompt';
                el.innerHTML = `
                <div style="position:fixed;bottom:80px;left:50%;transform:translateX(-50%);z-index:10000;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:14px 18px;border-radius:14px;box-shadow:0 8px 32px rgba(102,126,234,.45);max-width:320px;width:90%;font-size:0.85rem;animation:spSlideUp .4s ease;">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <span style="font-size:1.3rem;">🔔</span>
                        <div style="flex:1;">
                            <div style="font-weight:700;margin-bottom:3px;">Aktifkan Notifikasi?</div>
                            <div style="font-size:0.75rem;opacity:.9;margin-bottom:10px;">Terima info persetujuan cuti & lembur secara real-time.</div>
                            <div style="display:flex;gap:8px;">
                                <button onclick="window._spActivate()" style="padding:5px 14px;background:#fff;color:#764ba2;border:none;border-radius:8px;font-weight:700;font-size:0.78rem;cursor:pointer;">Aktifkan</button>
                                <button onclick="window._spDismiss()" style="padding:5px 10px;background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:8px;font-size:0.78rem;cursor:pointer;">Nanti</button>
                            </div>
                        </div>
                        <span onclick="window._spDismiss()" style="cursor:pointer;opacity:.7;font-size:1.1rem;">&times;</span>
                    </div>
                </div>`;
                document.body.appendChild(el);

                window._spActivate = async function() {
                    const perm = await Notification.requestPermission();
                    if (perm === 'granted') {
                        await subscribePush(reg, vapidKey, empId);
                        el.querySelector('div > div').innerHTML = '<div style="display:flex;align-items:center;gap:8px;padding:4px;"><span style="font-size:1.3rem;">✅</span><span style="font-weight:600;">Notifikasi aktif!</span></div>';
                        setTimeout(() => el.remove(), 2500);
                    } else {
                        el.querySelector('div > div').innerHTML = '<div style="display:flex;align-items:center;gap:8px;padding:4px;"><span style="font-size:1.3rem;">⚠️</span><span style="font-size:0.8rem;">Izin ditolak. Aktifkan di Settings browser.</span></div>';
                        setTimeout(() => el.remove(), 3000);
                    }
                    localStorage.setItem('staff_push_prompted', '1');
                };
                window._spDismiss = function() {
                    el.remove();
                    localStorage.setItem('staff_push_prompted', '1');
                };
            }

            function urlBase64ToUint8Array(base64String) {
                const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
                const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                const raw = atob(base64);
                const arr = new Uint8Array(raw.length);
                for (let i = 0; i < raw.length; ++i) arr[i] = raw.charCodeAt(i);
                return arr;
            }

            // Init push after app loads (wait for SW to be ready)
            const origShowApp = window.showApp || function() {};
            const _origShowApp = showApp;
            window.showApp = function(name) {
                _origShowApp(name);
                setTimeout(initStaffPush, 2000);
            };

            // Also init on page load if already logged in
            if (localStorage.getItem('staff_employee_id')) {
                setTimeout(initStaffPush, 3000);
            }
        })();
    </script>
    <style>
        @keyframes spSlideUp {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }
    </style>

</body>

</html>