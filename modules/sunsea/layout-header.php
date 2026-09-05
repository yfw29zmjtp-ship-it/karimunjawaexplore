<?php

/**
 * Sunsea Module - Shared Layout Header
 * Ocean blue design, different from all other ADF System businesses.
 *
 * Usage: include at top of every Sunsea module page.
 * Required vars before include:
 *   $pageTitle  (string)
 *   $activePage (string) matching nav keys
 */
if (!defined('APP_ACCESS')) define('APP_ACCESS', true);

$sunseaNavItems = [
    'dashboard'     => ['icon' => 'home',       'label' => 'Dashboard',         'url' => 'dashboard.php'],
    'database'      => ['icon' => 'database',   'label' => 'Database',          'url' => 'database.php'],
    'bookings'      => ['icon' => 'briefcase',  'label' => 'Booking',           'url' => 'bookings.php'],
    'calendar'      => ['icon' => 'calendar',   'label' => 'Kalender Booking',  'url' => 'calendar.php'],
    'coordinators'  => ['icon' => 'user-check', 'label' => 'Koordinator',       'url' => 'coordinators.php'],
    'packages'      => ['icon' => 'package',    'label' => 'Paket Wisata',      'url' => 'packages.php'],
    'rab'           => ['icon' => 'file-minus', 'label' => 'Cetak RAB',         'url' => 'rab.php'],
    'quotations'    => ['icon' => 'file-text',  'label' => 'Penawaran',         'url' => 'quotations.php'],
    'invoices'      => ['icon' => 'credit-card', 'label' => 'Invoice',          'url' => 'invoices.php'],
    'finance'       => ['icon' => 'dollar-sign', 'label' => 'Finance',          'url' => 'finance.php'],
    'settings'      => ['icon' => 'settings',   'label' => 'Pengaturan',        'url' => 'settings.php'],
];

// Sub-menu grouping: parent key => list of child keys shown in a collapsible dropdown
$sunseaNavGroups = [
    'bookings' => ['calendar', 'packages', 'rab'],
    'settings' => ['database', 'coordinators'],
];

$activePage = $activePage ?? '';
$currentUser = isset($auth) ? $auth->getCurrentUser() : [];
$userName    = $currentUser['full_name'] ?? $currentUser['username'] ?? 'User';

$visibleMenuKeys = array_keys($sunseaNavItems);

// Load company settings for sidebar
$_sidebarLogoSrc = '';
$_sidebarCompanyName = 'Explore Karimunjawa';
if (isset($pdo)) {
    try {
        $__s = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_logo','company_name')");
        foreach ($__s->fetchAll() as $__row) {
            if ($__row['setting_key'] === 'company_logo' && $__row['setting_value']) {
                $__proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $_sidebarLogoSrc = $__proto . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($__row['setting_value'], '/');
            }
            if ($__row['setting_key'] === 'company_name' && $__row['setting_value']) {
                $_sidebarCompanyName = $__row['setting_value'];
            }
        }

        $__menuRaw = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='sidebar_visible_menu_keys' LIMIT 1");
        $__menuRaw->execute();
        $__menuJson = $__menuRaw->fetchColumn();
        if ($__menuJson) {
            $__selected = json_decode((string)$__menuJson, true);
            if (is_array($__selected) && !empty($__selected)) {
                $visibleMenuKeys = array_values(array_intersect(array_keys($sunseaNavItems), $__selected));
            }
        }
    } catch (Exception $__e) { /* settings table may not exist yet */
    }
}

if (empty($visibleMenuKeys)) {
    $visibleMenuKeys = ['bookings'];
}

$sunseaNavItemsVisible = [];
foreach ($visibleMenuKeys as $__k) {
    if (isset($sunseaNavItems[$__k])) {
        $sunseaNavItemsVisible[$__k] = $sunseaNavItems[$__k];
    }
}

if (!isset($sunseaNavItemsVisible[$activePage]) && isset($sunseaNavItems[$activePage])) {
    $sunseaNavItemsVisible[$activePage] = $sunseaNavItems[$activePage];
}

if (empty($sunseaNavItemsVisible)) {
    $sunseaNavItemsVisible = ['bookings' => $sunseaNavItems['bookings']];
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Explore Karimunjawa'); ?> — Explore Karimunjawa</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/feather-icons"></script>

    <style>
        /* ================================================
           SUNSEA DESIGN SYSTEM — Elegant Orange Theme
           Colors:
             --ss-ocean   : #C2410C  (primary terracotta/burnt orange)
             --ss-deep    : #7C2D12  (deep chestnut, sidebar bg)
             --ss-cyan    : #F59E0B  (warm gold accent)
             --ss-sky     : #FFF7ED  (light warm cream bg)
             --ss-white   : #FFFFFF
             --ss-gray-1  : #F8FAFC
             --ss-gray-2  : #E2E8F0
             --ss-text    : #0F172A  (dark navy text)
             --ss-muted   : #64748B  (muted gray)
             --ss-success : #10B981
             --ss-warning : #F59E0B
             --ss-danger  : #EF4444
        ================================================ */
        :root {
            --ss-ocean: #C2410C;
            --ss-deep: #7C2D12;
            --ss-cyan: #F59E0B;
            --ss-sky: #FFF7ED;
            --ss-white: #FFFFFF;
            --ss-gray-1: #F8FAFC;
            --ss-gray-2: #E2E8F0;
            --ss-gray-3: #CBD5E1;
            --ss-text: #0F172A;
            --ss-muted: #64748B;
            --ss-success: #10B981;
            --ss-warning: #F59E0B;
            --ss-danger: #EF4444;
            --ss-radius: 12px;
            --ss-shadow: 0 1px 3px rgba(194, 65, 12, .08), 0 4px 16px rgba(194, 65, 12, .06);
            --ss-shadow-md: 0 4px 24px rgba(194, 65, 12, .12), 0 1px 4px rgba(0, 0, 0, .04);
            --sidebar-w: 240px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background: var(--ss-sky);
            color: var(--ss-text);
            font-size: 13px;
            min-height: 100vh;
            display: flex;
        }

        /* ---- SIDEBAR ---- */
        .ss-sidebar {
            width: var(--sidebar-w);
            min-height: 100vh;
            background: var(--ss-white);
            border-right: 1px solid var(--ss-gray-2);
            box-shadow: var(--ss-shadow);
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            z-index: 100;
            overflow-y: auto;
        }

        .ss-brand {
            padding: 24px 20px 20px;
            border-bottom: 1px solid var(--ss-gray-2);
        }

        .ss-brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .ss-brand-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--ss-ocean), var(--ss-cyan));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .ss-brand-logo-img {
            display: block;
            width: 100%;
            max-height: 90px;
            object-fit: contain;
            margin: 0 auto;
        }

        .ss-brand-logo-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            width: 100%;
            gap: 8px;
        }

        .ss-brand-name {
            font-size: 20px;
            font-weight: 800;
            color: var(--ss-text);
            letter-spacing: -0.5px;
        }

        .ss-brand-sub {
            font-size: 10px;
            color: var(--ss-muted);
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-top: 1px;
        }

        .ss-nav {
            padding: 16px 12px;
            flex: 1;
        }

        .ss-nav-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--ss-gray-3);
            padding: 8px 8px 4px;
            margin-bottom: 4px;
        }

        .ss-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--ss-muted);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 2px;
            transition: all .2s;
        }

        .ss-nav-item:hover {
            background: var(--ss-sky);
            color: var(--ss-ocean);
        }

        .ss-nav-item.active {
            background: linear-gradient(135deg, var(--ss-ocean), var(--ss-cyan));
            color: var(--ss-white);
            box-shadow: 0 4px 12px rgba(194, 65, 12, .35);
        }

        .ss-nav-item svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .ss-nav-group-row {
            display: flex;
            align-items: center;
            border-radius: 8px;
            margin-bottom: 2px;
        }

        .ss-nav-group-row .ss-nav-item {
            flex: 1;
            margin-bottom: 0;
        }

        .ss-nav-group-row:hover .ss-nav-item:not(.active) {
            background: var(--ss-sky);
            color: var(--ss-ocean);
        }

        .ss-nav-caret-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px 10px;
            color: var(--ss-muted);
            display: flex;
            align-items: center;
        }

        .ss-nav-caret-btn svg {
            width: 14px;
            height: 14px;
            transition: transform .2s;
        }

        .ss-nav-group.open .ss-nav-caret-btn svg {
            transform: rotate(180deg);
        }

        .ss-nav-submenu {
            display: none;
            padding-left: 14px;
        }

        .ss-nav-group.open .ss-nav-submenu {
            display: block;
        }

        .ss-nav-submenu .ss-nav-item {
            font-size: 13px;
            padding: 8px 12px;
        }

        .ss-sidebar-footer {
            padding: 16px;
            border-top: 1px solid var(--ss-gray-2);
        }

        .ss-user-block {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 8px;
            background: var(--ss-gray-1);
        }

        .ss-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--ss-ocean), var(--ss-cyan));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            color: white;
            flex-shrink: 0;
        }

        .ss-user-name {
            font-size: 12px;
            font-weight: 600;
            color: var(--ss-text);
        }

        .ss-user-role {
            font-size: 10px;
            color: var(--ss-muted);
        }

        .ss-logout-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            padding: 8px 10px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--ss-muted);
            font-size: 12px;
            transition: .2s;
        }

        .ss-logout-btn:hover {
            color: #EF4444;
            background: rgba(239, 68, 68, .08);
        }

        .ss-logout-btn svg {
            width: 14px;
            height: 14px;
        }

        /* ---- MAIN CONTENT ---- */
        .ss-main {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .ss-topbar {
            background: var(--ss-white);
            border-bottom: 1px solid var(--ss-gray-2);
            padding: 0 28px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .ss-page-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--ss-text);
        }

        .ss-topbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .ss-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
        }

        .ss-badge-ocean {
            background: #E0F2FE;
            color: var(--ss-ocean);
        }

        .ss-content {
            flex: 1;
            padding: 28px;
        }

        /* ---- CARDS ---- */
        .ss-card {
            background: var(--ss-white);
            border-radius: var(--ss-radius);
            box-shadow: var(--ss-shadow);
            padding: 24px;
        }

        .ss-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .ss-card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--ss-text);
        }

        .ss-card-sub {
            font-size: 12px;
            color: var(--ss-muted);
            margin-top: 2px;
        }

        /* ---- STAT CARDS ---- */
        .ss-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .ss-stat-card {
            background: var(--ss-white);
            border-radius: var(--ss-radius);
            box-shadow: var(--ss-shadow);
            padding: 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .ss-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .ss-stat-icon svg {
            width: 20px;
            height: 20px;
        }

        .ss-stat-icon.ocean {
            background: #E0F2FE;
            color: var(--ss-ocean);
        }

        .ss-stat-icon.cyan {
            background: #ECFEFF;
            color: var(--ss-cyan);
        }

        .ss-stat-icon.success {
            background: #D1FAE5;
            color: var(--ss-success);
        }

        .ss-stat-icon.warning {
            background: #FEF3C7;
            color: var(--ss-warning);
        }

        .ss-stat-icon.danger {
            background: #FEE2E2;
            color: var(--ss-danger);
        }

        .ss-stat-value {
            font-size: 22px;
            font-weight: 800;
            color: var(--ss-text);
            line-height: 1;
        }

        .ss-stat-label {
            font-size: 12px;
            color: var(--ss-muted);
            margin-top: 4px;
        }

        /* ---- TABLE ---- */
        .ss-table-wrap {
            overflow-x: auto;
        }

        .ss-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .ss-table th {
            background: var(--ss-gray-1);
            padding: 10px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: var(--ss-muted);
            border-bottom: 1px solid var(--ss-gray-2);
        }

        .ss-table td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--ss-gray-2);
            color: var(--ss-text);
            vertical-align: middle;
        }

        .ss-table tr:last-child td {
            border-bottom: none;
        }

        .ss-table tr:hover td {
            background: var(--ss-sky);
        }

        /* ---- BUTTONS ---- */
        .ss-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: .2s;
        }

        .ss-btn svg {
            width: 14px;
            height: 14px;
        }

        .ss-btn-primary {
            background: linear-gradient(135deg, var(--ss-ocean), var(--ss-cyan));
            color: white;
            box-shadow: 0 4px 12px rgba(194, 65, 12, .3);
        }

        .ss-btn-primary:hover {
            opacity: .9;
            transform: translateY(-1px);
        }

        .ss-btn-outline {
            background: transparent;
            border: 1.5px solid var(--ss-gray-2);
            color: var(--ss-text);
        }

        .ss-btn-outline:hover {
            border-color: var(--ss-ocean);
            color: var(--ss-ocean);
        }

        .ss-btn-danger {
            background: #FEE2E2;
            color: var(--ss-danger);
        }

        .ss-btn-success {
            background: #D1FAE5;
            color: var(--ss-success);
        }

        .ss-btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        /* ---- QUICK ACTION GRID ---- */
        .ss-quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 16px;
            padding: 0;
        }

        .ss-quick-action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px 12px;
            border-radius: 12px;
            background: var(--ss-gray-1);
            border: 1.5px solid var(--ss-gray-2);
            color: var(--ss-text);
            text-decoration: none;
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            min-height: 110px;
        }

        .ss-quick-action-btn:hover {
            border-color: var(--ss-ocean);
            background: var(--ss-sky);
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(194, 65, 12, .15);
        }

        .ss-quick-action-btn.ss-qa-primary {
            background: linear-gradient(135deg, var(--ss-ocean), var(--ss-cyan));
            border-color: transparent;
            color: white;
            box-shadow: 0 4px 12px rgba(194, 65, 12, .25);
        }

        .ss-quick-action-btn.ss-qa-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(194, 65, 12, .35);
        }

        .ss-qa-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .1);
        }

        .ss-quick-action-btn.ss-qa-primary .ss-qa-icon {
            background: rgba(255, 255, 255, .2);
        }

        .ss-quick-action-btn .ss-qa-icon svg {
            width: 20px;
            height: 20px;
        }

        .ss-qa-label {
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            word-break: break-word;
        }

        @media (max-width: 768px) {
            .ss-quick-actions-grid {
                grid-template-columns: repeat(auto-fit, minmax(85px, 1fr));
                gap: 12px;
            }

            .ss-quick-action-btn {
                padding: 12px 8px;
                min-height: 100px;
            }

            .ss-qa-icon {
                width: 36px;
                height: 36px;
            }

            .ss-qa-label {
                font-size: 11px;
            }
        }

        /* ---- STATUS BADGES ---- */
        .ss-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
        }

        .ss-status::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .ss-status-draft {
            background: #F1F5F9;
            color: #64748B;
        }

        .ss-status-sent {
            background: #E0F2FE;
            color: var(--ss-ocean);
        }

        .ss-status-approved {
            background: #D1FAE5;
            color: var(--ss-success);
        }

        .ss-status-rejected {
            background: #FEE2E2;
            color: var(--ss-danger);
        }

        .ss-status-paid {
            background: #D1FAE5;
            color: var(--ss-success);
        }

        .ss-status-partial {
            background: #FEF3C7;
            color: var(--ss-warning);
        }

        .ss-status-overdue {
            background: #FEE2E2;
            color: var(--ss-danger);
        }

        .ss-status-issued {
            background: #E0F2FE;
            color: var(--ss-ocean);
        }

        .ss-status-expired {
            background: #F1F5F9;
            color: #94A3B8;
        }

        .ss-status-converted {
            background: #EDE9FE;
            color: #7C3AED;
        }

        /* ---- FORM ---- */
        .ss-form-group {
            margin-bottom: 16px;
        }

        .ss-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--ss-text);
            margin-bottom: 6px;
            display: block;
        }

        .ss-input,
        .ss-select,
        .ss-textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--ss-gray-2);
            border-radius: 8px;
            font-size: 13px;
            color: var(--ss-text);
            background: var(--ss-white);
            transition: .2s;
            font-family: inherit;
        }

        .ss-input:focus,
        .ss-select:focus,
        .ss-textarea:focus {
            outline: none;
            border-color: var(--ss-ocean);
            box-shadow: 0 0 0 3px rgba(194, 65, 12, .12);
        }

        .ss-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .ss-form-grid {
            display: grid;
            gap: 16px;
        }

        .ss-form-grid.cols-2 {
            grid-template-columns: 1fr 1fr;
        }

        .ss-form-grid.cols-3 {
            grid-template-columns: 1fr 1fr 1fr;
        }

        /* ---- FLASH MESSAGES ---- */
        .ss-alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ss-alert svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .ss-alert-success {
            background: #D1FAE5;
            color: #065F46;
        }

        .ss-alert-error {
            background: #FEE2E2;
            color: #991B1B;
        }

        .ss-alert-info {
            background: #E0F2FE;
            color: #7C2D12;
        }

        /* ---- EMPTY STATE ---- */
        .ss-empty {
            text-align: center;
            padding: 48px 20px;
            color: var(--ss-muted);
        }

        .ss-empty-icon {
            font-size: 40px;
            margin-bottom: 12px;
        }

        .ss-empty h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--ss-text);
            margin-bottom: 6px;
        }

        .ss-empty p {
            font-size: 13px;
        }

        /* ---- MOBILE ---- */
        @media (max-width: 768px) {
            .ss-sidebar {
                transform: translateX(-100%);
                transition: transform .3s;
            }

            .ss-sidebar.open {
                transform: translateX(0);
            }

            .ss-main {
                margin-left: 0;
            }

            .ss-content {
                padding: 16px;
            }

            .ss-form-grid.cols-2,
            .ss-form-grid.cols-3 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <!-- ==================== SIDEBAR ==================== -->
    <aside class="ss-sidebar" id="sunseaSidebar">
        <div class="ss-brand">
            <?php if ($_sidebarLogoSrc): ?>
                <a href="dashboard.php" class="ss-brand-logo-wrap">
                    <img src="<?php echo htmlspecialchars($_sidebarLogoSrc); ?>" alt="Logo" class="ss-brand-logo-img">
                    <div style="text-align:center;">
                        <div class="ss-brand-sub"><?php echo htmlspecialchars($_sidebarCompanyName); ?></div>
                    </div>
                </a>
            <?php else: ?>
                <a href="dashboard.php" class="ss-brand-logo">
                    <div class="ss-brand-icon">🌊</div>
                    <div>
                        <div class="ss-brand-name"><?php echo htmlspecialchars($_sidebarCompanyName); ?></div>
                        <div class="ss-brand-sub">Explore Karimunjawa</div>
                    </div>
                </a>
            <?php endif; ?>
        </div>

        <nav class="ss-nav">
            <div class="ss-nav-label">Menu Utama</div>
            <?php
            $__childOfGroup = [];
            foreach ($sunseaNavGroups as $__gk => $__children) {
                foreach ($__children as $__c) $__childOfGroup[$__c] = $__gk;
            }
            foreach ($sunseaNavItemsVisible as $key => $item):
                if (isset($__childOfGroup[$key])) continue; // rendered nested under its parent group below

                if (isset($sunseaNavGroups[$key])) {
                    $__childKeys = array_values(array_intersect($sunseaNavGroups[$key], array_keys($sunseaNavItemsVisible)));
                    if (empty($__childKeys)) {
                        // No visible children for this user -> render as a plain link
            ?>
                        <a href="<?php echo $item['url']; ?>"
                            class="ss-nav-item <?php echo ($activePage === $key) ? 'active' : ''; ?>">
                            <i data-feather="<?php echo $item['icon']; ?>"></i>
                            <?php echo $item['label']; ?>
                        </a>
            <?php
                    } else {
                        $__isOpen = ($activePage === $key) || in_array($activePage, $__childKeys);
            ?>
                        <div class="ss-nav-group <?php echo $__isOpen ? 'open' : ''; ?>">
                            <div class="ss-nav-group-row">
                                <a href="<?php echo $item['url']; ?>"
                                    class="ss-nav-item <?php echo ($activePage === $key) ? 'active' : ''; ?>">
                                    <i data-feather="<?php echo $item['icon']; ?>"></i>
                                    <?php echo $item['label']; ?>
                                </a>
                                <button type="button" class="ss-nav-caret-btn" onclick="this.closest('.ss-nav-group').classList.toggle('open')">
                                    <i data-feather="chevron-down"></i>
                                </button>
                            </div>
                            <div class="ss-nav-submenu">
                                <?php foreach ($__childKeys as $__ck): $__child = $sunseaNavItemsVisible[$__ck]; ?>
                                    <a href="<?php echo $__child['url']; ?>"
                                        class="ss-nav-item <?php echo ($activePage === $__ck) ? 'active' : ''; ?>">
                                        <i data-feather="<?php echo $__child['icon']; ?>"></i>
                                        <?php echo $__child['label']; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
            <?php
                    }
                } else {
            ?>
                    <a href="<?php echo $item['url']; ?>"
                        class="ss-nav-item <?php echo ($activePage === $key) ? 'active' : ''; ?>">
                        <i data-feather="<?php echo $item['icon']; ?>"></i>
                        <?php echo $item['label']; ?>
                    </a>
            <?php
                }
            endforeach; ?>

        </nav>

        <div class="ss-sidebar-footer">
            <div class="ss-user-block">
                <div class="ss-user-avatar"><?php echo strtoupper(substr($userName, 0, 1)); ?></div>
                <div>
                    <div class="ss-user-name"><?php echo htmlspecialchars($userName); ?></div>
                    <div class="ss-user-role">Explore Karimunjawa</div>
                </div>
            </div>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="ss-logout-btn">
                <i data-feather="log-out"></i> Keluar
            </a>
        </div>
    </aside>

    <!-- ==================== MAIN ==================== -->
    <div class="ss-main">
        <header class="ss-topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button onclick="document.getElementById('sunseaSidebar').classList.toggle('open')"
                    style="display:none;background:none;border:none;cursor:pointer;padding:4px;"
                    id="sidebarToggle">
                    <i data-feather="menu" style="width:20px;height:20px;"></i>
                </button>
                <span class="ss-page-title"><?php echo htmlspecialchars($pageTitle ?? 'Explore Karimunjawa'); ?></span>
            </div>
            <div class="ss-topbar-actions">
                <span class="ss-badge ss-badge-ocean">🌊 Explore Karimunjawa</span>
                <a href="<?php echo BASE_URL; ?>/logout.php" style="color:var(--ss-muted);text-decoration:none;font-size:12px;">
                    <i data-feather="log-out" style="width:15px;height:15px;vertical-align:middle;"></i>
                </a>
            </div>
        </header>

        <div class="ss-content">
            <?php
            // Flash messages from session
            if (!empty($_SESSION['flash_message'])):
                $fType = $_SESSION['flash_type'] ?? 'info';
                $fClass = ['success' => 'ss-alert-success', 'error' => 'ss-alert-error', 'info' => 'ss-alert-info'][$fType] ?? 'ss-alert-info';
                $fIcon  = ['success' => 'check-circle', 'error' => 'x-circle', 'info' => 'info'][$fType] ?? 'info';
            ?>
                <div class="ss-alert <?php echo $fClass; ?>">
                    <i data-feather="<?php echo $fIcon; ?>"></i>
                    <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
                </div>
            <?php
                unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            endif;
            ?>