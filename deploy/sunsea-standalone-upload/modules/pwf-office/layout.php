<?php
function pwfOfficeHeader(string $title, string $active = ''): void
{
    $menuItems = [
        'dashboard' => ['label' => 'Dashboard',        'icon' => 'bi-speedometer2',     'url' => 'dashboard.php'],
        'orders'    => ['label' => 'Orders',            'icon' => 'bi-clipboard2-check', 'url' => 'orders.php'],
        'progress'  => ['label' => 'Progress Tracking', 'icon' => 'bi-bar-chart-line',   'url' => 'progress.php'],
        'warehouse' => ['label' => 'Warehouse / Stock',  'icon' => 'bi-building',        'url' => 'warehouse.php'],
        'shipping'  => ['label' => 'Shipping & Export', 'icon' => 'bi-box-seam',        'url' => 'shipping.php'],
        'rekap-order' => ['label' => 'Rekap Order',    'icon' => 'bi-box2-heart',      'url' => 'rekap-order.php'],
        'database'  => [
            'label' => 'Database',
            'icon' => 'bi-database',
            'children' => [
                'buyers'     => ['label' => 'Buyers',      'icon' => 'bi-person-badge', 'url' => 'buyers.php'],
                'customers'  => ['label' => 'Customers',   'icon' => 'bi-people',       'url' => 'customers.php'],
                'craftsmen'  => ['label' => 'Craftsmen',   'icon' => 'bi-hammer',       'url' => 'craftsmen.php'],
                'containers' => ['label' => 'Containers',  'icon' => 'bi-box-seam',     'url' => 'db-containers.php'],
            ]
        ],
        'settings'  => [
            'label' => 'Settings',
            'icon' => 'bi-gear',
            'children' => [
                'settings-main' => ['label' => 'General Settings', 'icon' => 'bi-sliders', 'url' => 'settings.php'],
                'manage'        => ['label' => 'PWF Manage',       'icon' => 'bi-tools',   'url' => 'manage/index.php'],
            ]
        ],
    ];

    // Filter menu berdasarkan permission user
    $menu = $menuItems;
    $userId = $_SESSION['user_id'] ?? null;

    // Hanya filter jika ada user login
    if ($userId) {
        try {
            $masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            // Get user's PWF business
            $pwfBiz = $masterPdo->query("SELECT id FROM businesses WHERE business_name LIKE '%PWF%' OR business_name LIKE '%Prapen%' LIMIT 1")->fetch();
            $pwfBizId = $pwfBiz['id'] ?? null;

            if ($pwfBizId) {
                // Get user's allowed menu codes (format: pwf_<key>)
                $stmt = $masterPdo->prepare("
                    SELECT DISTINCT mi.menu_code 
                    FROM user_menu_permissions ump
                    INNER JOIN menu_items mi ON ump.menu_id = mi.id
                    WHERE ump.user_id = ? AND ump.business_id = ? AND ump.can_view = 1
                ");
                $stmt->execute([$userId, $pwfBizId]);
                $allowedCodes = [];
                foreach ($stmt->fetchAll() as $row) {
                    // Strip pwf_ prefix to get sidebar key
                    $allowedCodes[] = preg_replace('/^pwf_/', '', strtolower($row['menu_code']));
                }

                // Map rekap code to sidebar key
                $codeMap = ['rekap' => 'rekap-order'];
                $mapped = [];
                foreach ($allowedCodes as $code) {
                    $mapped[] = $codeMap[$code] ?? $code;
                }
                $allowedCodes = $mapped;

                // Filter menu jika ada permission entries
                if (!empty($allowedCodes)) {
                    $filteredMenu = [];

                    foreach ($menuItems as $key => $item) {
                        if (!empty($item['children'])) {
                            $filteredChildren = [];
                            foreach ($item['children'] as $ck => $child) {
                                if (in_array(strtolower($ck), $allowedCodes)) {
                                    $filteredChildren[$ck] = $child;
                                }
                            }
                            if (!empty($filteredChildren)) {
                                $item['children'] = $filteredChildren;
                                $filteredMenu[$key] = $item;
                            }
                        } else {
                            if (in_array(strtolower($key), $allowedCodes)) {
                                $filteredMenu[$key] = $item;
                            }
                        }
                    }

                    $menu = $filteredMenu;
                }
            }
        } catch (Exception $e) {
            // Jika ada error, tampilkan menu default
            $menu = $menuItems;
        }
    }
    // determine if any group child is active
    $dbChildren       = ['buyers', 'customers', 'craftsmen', 'containers'];
    $settingsChildren = ['settings-main', 'manage'];
    $dbActive         = in_array($active, $dbChildren);
    $settingsActive   = in_array($active, $settingsChildren) || $active === 'settings';
    $fullName = htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
    $initials = strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 2));


    // Load logo + theme from DB
    $logoUrl = '';
    $pwfTheme = 'light';
    $faviconUrl = '';
    $faviconType = 'image/x-icon';
    try {
        $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
        $pwfDb = $isProduction ? 'adfb2574_pwf' : 'adf_pwf';
        $pdo2 = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $pwfDb . ";charset=utf8mb4", DB_USER, DB_PASS);
        $dbSettings = $pdo2->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('pwf_login_logo','pwf_ui_theme','pwf_favicon')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($dbSettings['pwf_login_logo'])) $logoUrl = htmlspecialchars($dbSettings['pwf_login_logo']);
        if (!empty($dbSettings['pwf_ui_theme']) && in_array($dbSettings['pwf_ui_theme'], ['light', 'dark'])) $pwfTheme = $dbSettings['pwf_ui_theme'];
        if (!empty($dbSettings['pwf_favicon'])) {
            $faviconUrl = ltrim($dbSettings['pwf_favicon'], '/');
            $ext = strtolower(pathinfo(parse_url($faviconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            $mimeMap = [
                'ico' => 'image/x-icon',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml'
            ];
            $faviconType = $mimeMap[$ext] ?? 'image/x-icon';
        }
    } catch (Exception $e) {
    }
?>
    <!doctype html>
    <html lang="en" data-theme="<?= $pwfTheme ?>">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($title) ?> · PWF Office</title>
        <?php if (!empty($faviconUrl)): ?>
            <link rel="icon" type="<?= htmlspecialchars($faviconType) ?>" href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . $faviconUrl) ?>">
            <link rel="shortcut icon" type="<?= htmlspecialchars($faviconType) ?>" href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . $faviconUrl) ?>">
        <?php else: ?>
            <link rel="icon" href="<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>/favicon.ico">
        <?php endif; ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <style>
            :root {
                --gold: #B8860B;
                --gold-light: #D4A017;
                --gold-bg: #FEF9EC;
                --gold-border: #EDD07A;
                --sidebar-w: 260px;
                --bg: #F8F6F3;
                --card: #FFFFFF;
                --text: #1C1917;
                --muted: #78716C;
                --border: #E7E5E4;
                --brand-dark: #1C1511;
                --success: #16A34A;
                --danger: #DC2626;
                --nav-bg: #fff;
                --nav-hover: #F5F3F0;
                --sidebar-logo-bg: #fff;
                --topbar-bg: #fff;
                --input-bg: #fff;
                --status-blue-bg: #EFF6FF;
                --status-blue-text: #1D4ED8;
                --status-orange-bg: #FFF7ED;
                --status-orange-text: #C2410C;
                --status-purple-bg: #F5F3FF;
                --status-purple-text: #6D28D9;
                --status-green-bg: #F0FDF4;
                --status-green-text: #15803D;
                --status-red-bg: #FEF2F2;
                --status-red-text: #991B1B;
                --alert-success-bg: #F0FDF4;
                --alert-success-border: #BBF7D0;
                --alert-danger-bg: #FEF2F2;
                --alert-danger-border: #FECACA;
            }

            [data-theme="dark"] {
                --bg: #111113;
                --card: #1C1C1F;
                --text: #ECECEC;
                --muted: #8A8A90;
                --border: #2C2C30;
                --brand-dark: #111113;
                --nav-bg: #161618;
                --nav-hover: #222226;
                --sidebar-logo-bg: #1C1C1F;
                --topbar-bg: #161618;
                --input-bg: #222226;
                --gold-bg: rgba(184, 134, 11, .18);
                --gold-border: rgba(212, 160, 23, .35);
                --status-blue-bg: rgba(29, 78, 216, .25);
                --status-blue-text: #60A5FA;
                --status-orange-bg: rgba(194, 65, 12, .25);
                --status-orange-text: #FB923C;
                --status-purple-bg: rgba(109, 40, 217, .25);
                --status-purple-text: #D8B4FE;
                --status-green-bg: rgba(21, 128, 61, .25);
                --status-green-text: #4EEE90;
                --status-red-bg: rgba(153, 27, 27, .25);
                --status-red-text: #F87171;
                --alert-success-bg: rgba(16, 185, 129, .15);
                --alert-success-border: rgba(16, 185, 129, .35);
                --alert-danger-bg: rgba(239, 68, 68, .15);
                --alert-danger-border: rgba(239, 68, 68, .35);
            }

            *,
            *::before,
            *::after {
                box-sizing: border-box
            }

            body {
                margin: 0;
                font-family: 'Inter', sans-serif;
                background: var(--bg);
                color: var(--text);
                font-size: 13px
            }

            /* ── SIDEBAR ─────────────────────────────────────────── */
            .pwf-sidebar {
                background: var(--nav-bg);
                border-right: 1px solid var(--border);
                position: fixed;
                top: 0;
                left: 0;
                width: var(--sidebar-w);
                height: 100vh;
                display: flex;
                flex-direction: column;
                z-index: 100;
                box-shadow: 2px 0 12px rgba(0, 0, 0, .06)
            }

            .pwf-sidebar-logo {
                padding: 16px 16px 12px;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 7px;
                background: var(--sidebar-logo-bg);
                border-bottom: 1px solid var(--border);
                flex-shrink: 0;
                text-align: center
            }

            .pwf-logo-box {
                width: 160px;
                height: 72px;
                border-radius: 10px;
                background: var(--card);
                border: 1px solid var(--border);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 30px;
                overflow: hidden;
                flex-shrink: 0;
                box-shadow: 0 2px 10px rgba(0, 0, 0, .07)
            }

            .pwf-logo-box img {
                width: 100%;
                height: 100%;
                object-fit: contain;
                padding: 8px
            }

            .pwf-brand-text .pwf-brand-name {
                font-size: 11.5px;
                font-weight: 700;
                color: var(--text);
                line-height: 1.3;
                letter-spacing: .3px;
                text-transform: uppercase
            }

            .pwf-brand-text .pwf-brand-sub {
                font-size: 10px;
                color: var(--muted);
                margin-top: 1px;
                font-weight: 400
            }

            .pwf-nav {
                flex: 1;
                padding: 8px 6px;
                overflow-y: auto;
                overflow-x: hidden;
                scrollbar-width: thin;
                scrollbar-color: var(--border) transparent
            }

            .pwf-nav a {
                display: flex;
                align-items: center;
                gap: 9px;
                padding: 6px 10px;
                border-radius: 8px;
                margin-bottom: 1px;
                color: var(--muted);
                text-decoration: none;
                font-size: 12px;
                font-weight: 500;
                transition: background .15s, color .15s;
                border-left: 3px solid transparent
            }

            .pwf-nav a:hover {
                background: var(--nav-hover);
                color: var(--text)
            }

            .pwf-nav a.active {
                background: var(--gold-bg);
                color: #92600A;
                border-left-color: var(--gold);
                font-weight: 600
            }

            .pwf-nav a i {
                font-size: 15px;
                flex-shrink: 0
            }

            .pwf-nav .nav-section {
                font-size: 9px;
                font-weight: 700;
                letter-spacing: .8px;
                text-transform: uppercase;
                color: #C4B8B0;
                padding: 8px 10px 3px;
                margin-top: 2px
            }

            /* Database dropdown nav */
            .nav-group-btn {
                display: flex;
                align-items: center;
                gap: 9px;
                padding: 6px 10px;
                border-radius: 8px;
                margin-bottom: 1px;
                color: var(--muted);
                cursor: pointer;
                font-size: 12px;
                font-weight: 500;
                transition: background .15s, color .15s;
                border-left: 3px solid transparent;
                user-select: none
            }

            .nav-group-btn:hover,
            .nav-group-btn.open {
                background: var(--nav-hover);
                color: var(--text)
            }

            .nav-group-btn.db-active {
                color: #92600A;
                font-weight: 600
            }

            .nav-group-btn .ng-chevron {
                margin-left: auto;
                font-size: 11px;
                transition: transform .2s
            }

            .nav-group-btn.open .ng-chevron {
                transform: rotate(90deg)
            }

            .nav-sub {
                display: none;
                padding-left: 14px;
                margin-bottom: 2px
            }

            .nav-sub.open {
                display: block
            }

            .nav-sub a {
                font-size: 12px;
                padding: 7px 10px;
                border-left: 2px solid var(--border)
            }

            .nav-sub a.active {
                border-left-color: var(--gold);
                background: var(--gold-bg);
                color: #92600A;
                font-weight: 600
            }

            .pwf-sidebar-footer {
                padding: 12px 14px;
                border-top: 1px solid var(--border);
                display: flex;
                align-items: center;
                gap: 9px;
                flex-shrink: 0
            }

            .pwf-avatar {
                width: 30px;
                height: 30px;
                border-radius: 50%;
                background: linear-gradient(135deg, var(--gold-light), #8A6000);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 11px;
                font-weight: 700;
                color: #fff;
                flex-shrink: 0
            }

            .pwf-user-name {
                font-size: 12px;
                font-weight: 600;
                color: var(--text);
                line-height: 1.2
            }

            .pwf-user-role {
                font-size: 10.5px;
                color: var(--muted)
            }

            .pwf-logout {
                margin-left: auto;
                color: var(--muted);
                font-size: 15px;
                text-decoration: none;
                transition: color .15s
            }

            .pwf-logout:hover {
                color: var(--danger)
            }

            .pwf-sidebar-version {
                padding: 10px 14px;
                border-top: 1px dashed var(--border);
                text-align: center;
                font-size: 10px;
                color: var(--muted);
                margin-top: auto
            }

            .pwf-sidebar-version strong {
                color: #92600A;
                font-weight: 700
            }

            /* ── MAIN WRAPPER ────────────────────────────────────── */
            .pwf-main {
                margin-left: var(--sidebar-w);
                min-height: 100vh;
                display: flex;
                flex-direction: column
            }

            .pwf-topbar {
                height: 56px;
                background: var(--topbar-bg);
                border-bottom: 1px solid var(--border);
                padding: 0 24px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                position: sticky;
                top: 0;
                z-index: 50
            }

            .pwf-topbar-title {
                font-size: 15.5px;
                font-weight: 700;
                color: var(--text)
            }

            .pwf-topbar-sub {
                font-size: 11.5px;
                color: var(--muted);
                margin-top: 1px
            }

            .pwf-topbar-right {
                display: flex;
                align-items: center;
                gap: 8px
            }

            .pwf-date-badge {
                font-size: 11.5px;
                color: var(--muted);
                background: var(--bg);
                border: 1px solid var(--border);
                padding: 4px 11px;
                border-radius: 20px
            }

            .pwf-content {
                padding: 24px 28px;
                flex: 1
            }

            /* ── CARDS ────────────────────────────────────────────── */
            .pwf-card,
            .card {
                background: var(--card);
                border-radius: 12px;
                border: 1px solid var(--border);
                box-shadow: 0 1px 4px rgba(0, 0, 0, .05)
            }

            .pwf-card-header,
            .card-header {
                padding: 13px 18px;
                border-bottom: 1px solid var(--border);
                font-weight: 600;
                font-size: 13.5px;
                display: flex;
                align-items: center;
                justify-content: space-between
            }

            .pwf-card-body,
            .card-body {
                padding: 18px
            }

            /* ── STAT CARDS ──────────────────────────────────────── */
            .stat-cards {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 16px;
                margin-bottom: 20px
            }

            .stat-card {
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 12px;
                padding: 20px;
                display: flex;
                align-items: center;
                gap: 16px;
                box-shadow: 0 1px 4px rgba(0, 0, 0, .05)
            }

            .stat-icon {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 22px;
                flex-shrink: 0
            }

            .stat-val {
                font-size: 26px;
                font-weight: 800;
                color: var(--text);
                line-height: 1
            }

            .stat-lbl {
                font-size: 12px;
                color: var(--muted);
                margin-top: 3px
            }

            /* ── TABLE ───────────────────────────────────────────── */
            .pwf-table {
                width: 100%;
                border-collapse: collapse
            }

            .pwf-table thead th {
                padding: 8px 12px;
                font-size: 10.5px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .5px;
                color: var(--muted);
                background: var(--nav-hover);
                border-bottom: 1px solid var(--border);
                border-top: 1px solid var(--border)
            }

            .pwf-table tbody td {
                padding: 9px 12px;
                border-bottom: 1px solid var(--border);
                font-size: 12.5px;
                color: var(--text)
            }

            .pwf-table tbody tr:last-child td {
                border-bottom: none
            }

            .pwf-table tbody tr:hover td {
                background: var(--nav-hover)
            }

            /* ── FORM ELEMENTS ───────────────────────────────────── */
            .pwf-form-group {
                margin-bottom: 12px
            }

            .pwf-form-group label,
            .form-label {
                display: block;
                margin-bottom: 4px;
                font-size: 11px;
                font-weight: 600;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: .4px
            }

            .input,
            .select,
            .pwf-input,
            .pwf-select,
            input[type=text],
            input[type=date],
            input[type=number],
            input[type=email],
            input[type=file],
            select,
            textarea {
                width: 100%;
                padding: 7px 11px;
                border: 1px solid var(--border);
                border-radius: 7px;
                background: var(--input-bg);
                color: var(--text);
                font-size: 13px;
                font-family: 'Inter', sans-serif;
                outline: none;
                transition: border-color .15s, box-shadow .15s
            }

            .input:focus,
            .pwf-input:focus,
            input[type=text]:focus,
            input[type=date]:focus,
            input[type=number]:focus,
            input[type=email]:focus,
            select:focus,
            textarea:focus {
                border-color: var(--gold);
                box-shadow: 0 0 0 3px rgba(184, 134, 11, .12)
            }

            textarea {
                resize: vertical;
                min-height: 80px
            }

            /* ── INPUT PLACEHOLDER & SELECTION ── */
            .input::placeholder,
            input[type=text]::placeholder,
            input[type=date]::placeholder,
            input[type=number]::placeholder,
            input[type=email]::placeholder,
            textarea::placeholder {
                color: var(--muted);
                opacity: 0.8
            }

            /* Dark mode: improve select options visibility */
            [data-theme="dark"] select option {
                background: #222226;
                color: #ECECEC;
            }

            select option {
                background: #fff;
                color: #1C1917;
            }

            /* ── BUTTONS ─────────────────────────────────────────── */
            .btn,
            .pwf-btn {
                padding: 7px 16px;
                border-radius: 7px;
                border: none;
                cursor: pointer;
                font-size: 12.5px;
                font-weight: 600;
                font-family: 'Inter', sans-serif;
                transition: opacity .15s, transform .1s;
                display: inline-flex;
                align-items: center;
                gap: 6px
            }

            .btn,
            .btn-primary {
                background: var(--gold);
                color: #fff
            }

            .btn:hover,
            .btn-primary:hover {
                opacity: .88;
                transform: translateY(-1px)
            }

            .btn.warn,
            .btn-danger,
            .btn-outline-danger {
                background: transparent;
                border: 1px solid #FECACA;
                color: var(--danger)
            }

            .btn-outline-danger:hover {
                background: #FEF2F2
            }

            .btn-danger {
                background: var(--danger) !important;
                color: #fff !important;
                border: none !important
            }

            .btn-success {
                background: var(--success) !important;
                color: #fff !important;
                border: none !important
            }

            .btn-outline-secondary {
                background: transparent;
                border: 1px solid var(--border);
                color: var(--muted)
            }

            .btn-outline-secondary:hover {
                background: var(--nav-hover);
                color: var(--text)
            }

            .btn-sm {
                padding: 5px 10px;
                font-size: 11.5px
            }

            .btn-outline-light {
                background: transparent;
                border: 1px solid rgba(255, 255, 255, .3);
                color: #fff;
                font-size: 12px
            }

            .btn-outline-light:hover {
                background: rgba(255, 255, 255, .1)
            }

            .btn-export {
                background: transparent;
                border: 1px solid var(--border);
                color: var(--muted);
                padding: 5px 11px;
                font-size: 11.5px;
                border-radius: 6px;
                gap: 5px
            }

            .btn-export:hover {
                background: #F0FDF4;
                border-color: #BBF7D0;
                color: var(--success)
            }

            [data-theme="dark"] .btn-export:hover {
                background: var(--status-green-bg);
                border-color: rgba(16, 185, 129, .5);
                color: var(--status-green-text)
            }

            /* ── BADGES & ALERTS ─────────────────────────────────── */
            .badge {
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600
            }

            .bg-secondary {
                background: #E7E5E4 !important;
                color: var(--muted) !important
            }

            [data-theme="dark"] .bg-secondary {
                background: #2C2C30 !important;
                color: var(--muted) !important
            }

            .alert {
                padding: 12px 16px;
                border-radius: 10px;
                font-size: 13.5px;
                margin-bottom: 16px
            }

            .alert-success {
                background: #F0FDF4;
                border: 1px solid #BBF7D0;
                color: #166534
            }

            [data-theme="dark"] .alert-success {
                background: var(--alert-success-bg);
                border: 1px solid var(--alert-success-border);
                color: #4EEE90
            }

            .alert-danger {
                background: #FEF2F2;
                border: 1px solid #FECACA;
                color: #991B1B
            }

            [data-theme="dark"] .alert-danger {
                background: var(--alert-danger-bg);
                border: 1px solid var(--alert-danger-border);
                color: #F87171
            }

            /* ── STATUS BADGE ────────────────────────────────────── */
            .status-badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 20px;
                font-size: 11.5px;
                font-weight: 600;
                text-transform: capitalize
            }

            .status-new,
            .status-pending,
            .status-partial_ship,
            .status-booked {
                background: #EFF6FF;
                color: #1D4ED8
            }

            [data-theme="dark"] .status-new,
            [data-theme="dark"] .status-pending,
            [data-theme="dark"] .status-partial_ship,
            [data-theme="dark"] .status-booked {
                background: var(--status-blue-bg);
                color: var(--status-blue-text)
            }

            .status-on_progress,
            .status-onboard {
                background: #FFF7ED;
                color: #C2410C
            }

            [data-theme="dark"] .status-on_progress,
            [data-theme="dark"] .status-onboard {
                background: var(--status-orange-bg);
                color: var(--status-orange-text)
            }

            .status-qc {
                background: #F5F3FF;
                color: #6D28D9
            }

            [data-theme="dark"] .status-qc {
                background: var(--status-purple-bg);
                color: var(--status-purple-text)
            }

            .status-ready_ship,
            .status-shipped,
            .status-completed,
            .status-arrived {
                background: #F0FDF4;
                color: #166534
            }

            [data-theme="dark"] .status-ready_ship,
            [data-theme="dark"] .status-shipped,
            [data-theme="dark"] .status-completed,
            [data-theme="dark"] .status-arrived {
                background: var(--status-green-bg);
                color: var(--status-green-text)
            }

            .status-cancelled {
                background: #FEF2F2;
                color: #991B1B
            }

            [data-theme="dark"] .status-cancelled {
                background: var(--status-red-bg);
                color: var(--status-red-text)
            }

            .status-closed {
                background: #F5F5F4;
                color: #6B7280
            }

            [data-theme="dark"] .status-closed {
                background: rgba(255, 255, 255, .08);
                color: var(--muted)
            }

            /* ── HELPER BADGE CLASSES ─────────────────────────────── */
            .badge-orange {
                background: #FFF7ED;
                color: #C2410C;
                padding: 3px 10px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 11px;
            }

            [data-theme="dark"] .badge-orange {
                background: var(--status-orange-bg);
                color: var(--status-orange-text);
            }

            .badge-green {
                background: #F0FDF4;
                color: #15803D;
                padding: 3px 10px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 11px;
            }

            [data-theme="dark"] .badge-green {
                background: var(--status-green-bg);
                color: var(--status-green-text);
            }

            .badge-blue {
                background: #EFF6FF;
                color: #1D4ED8;
                padding: 3px 10px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 11px;
            }

            [data-theme="dark"] .badge-blue {
                background: var(--status-blue-bg);
                color: var(--status-blue-text);
            }

            .badge-red {
                background: #FEF2F2;
                color: #DC2626;
                padding: 3px 10px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 11px;
            }

            [data-theme="dark"] .badge-red {
                background: var(--status-red-bg);
                color: var(--status-red-text);
            }

            /* ── BADGE VARIANTS ──────────────────────────────────────── */
            .badge-orange-sm {
                background: #FFF7ED;
                color: #C2410C;
                padding: 2px 8px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 10px;
            }

            [data-theme="dark"] .badge-orange-sm {
                background: var(--status-orange-bg);
                color: var(--status-orange-text);
            }

            .badge-green-sm {
                background: #F0FDF4;
                color: #15803D;
                padding: 2px 8px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 10px;
            }

            [data-theme="dark"] .badge-green-sm {
                background: var(--status-green-bg);
                color: var(--status-green-text);
            }

            .badge-blue-sm {
                background: #EFF6FF;
                color: #1D4ED8;
                padding: 2px 8px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 10px;
            }

            [data-theme="dark"] .badge-blue-sm {
                background: var(--status-blue-bg);
                color: var(--status-blue-text);
            }

            /* ── LEGACY COMPAT ───────────────────────────────────── */
            .row {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 16px;
                margin-bottom: 16px
            }

            .grid2 {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px
            }

            .mt {
                margin-top: 12px
            }

            .card.stat {
                padding: 20px
            }

            .card.stat h3 {
                margin: 0;
                font-size: 26px;
                font-weight: 800
            }

            .card.stat p {
                margin: 4px 0 0;
                color: var(--muted);
                font-size: 12px
            }

            .small {
                font-size: 12px;
                color: var(--muted)
            }

            table {
                width: 100%;
                border-collapse: collapse
            }

            table th,
            table td {
                padding: 10px 12px;
                text-align: left;
                font-size: 13.5px;
                border-bottom: 1px solid var(--border)
            }

            table thead th {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .5px;
                color: var(--muted);
                background: #FAFAF9
            }

            [data-theme="dark"] table thead th {
                background: #222226
            }

            .text-muted {
                color: var(--muted) !important
            }

            .pwf-content-header {
                margin-bottom: 20px
            }

            .pwf-page-title {
                font-size: 20px;
                font-weight: 700;
                color: var(--text);
                margin: 0 0 4px
            }

            .pwf-page-sub {
                font-size: 13px;
                color: var(--muted);
                margin: 0
            }

            .mb-4 {
                margin-bottom: 16px !important
            }

            .mb-3 {
                margin-bottom: 12px !important
            }

            .mt-2 {
                margin-top: 8px !important
            }

            .d-flex {
                display: flex !important
            }

            .gap-2 {
                gap: 8px !important
            }

            .flex-wrap {
                flex-wrap: wrap !important
            }

            .col-12 {
                width: 100%
            }

            .col-md-6 {
                width: 50%
            }

            .col-md-8 {
                width: 66.66%
            }

            .col-md-4 {
                width: 33.33%
            }

            .col-lg-6 {
                width: 50%
            }

            .row.g-4 {
                gap: 16px;
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                margin: 0
            }

            .col-12.col-lg-6:last-child,
            .col-12 {
                grid-column: 1/-1
            }

            .col-lg-6 {
                grid-column: span 1
            }

            .justify-content-between {
                justify-content: space-between !important
            }

            .align-items-center {
                align-items: center !important
            }

            .text-center {
                text-align: center !important
            }

            .form-control {
                width: 100%;
                padding: 9px 12px;
                border: 1px solid var(--border);
                border-radius: 8px;
                background: var(--input-bg);
                color: var(--text);
                font-size: 14px;
                font-family: 'Inter', sans-serif;
                outline: none
            }

            .form-control:focus {
                border-color: var(--gold);
                box-shadow: 0 0 0 3px rgba(184, 134, 11, .12)
            }

            @media(max-width:900px) {
                .pwf-sidebar {
                    transform: translateX(-100%);
                    transition: transform .25s
                }

                .pwf-sidebar.open {
                    transform: none
                }

                .pwf-main {
                    margin-left: 0
                }

                .row,
                .stat-cards {
                    grid-template-columns: repeat(2, 1fr)
                }

                .grid2 {
                    grid-template-columns: 1fr
                }

                .row.g-4 {
                    grid-template-columns: 1fr
                }

                .col-md-6,
                .col-md-8,
                .col-md-4,
                .col-lg-6 {
                    width: 100%
                }
            }

            @media(max-width:600px) {

                .row,
                .stat-cards {
                    grid-template-columns: 1fr
                }

                .pwf-content {
                    padding: 16px
                }

                .pwf-topbar {
                    padding: 0 16px
                }
            }
        </style>
    </head>

    <body>

        <!-- SIDEBAR -->
        <aside class="pwf-sidebar">
            <div class="pwf-sidebar-logo">
                <div class="pwf-logo-box">
                    <?php if ($logoUrl): ?><img src="<?= $logoUrl ?>" alt="PWF"><?php else: ?>🪵<?php endif; ?>
                </div>
                <div class="pwf-brand-text">
                    <div class="pwf-brand-name">PWF OFFICE</div>
                    <div class="pwf-brand-sub">Prapen Wood Furniture</div>
                </div>
            </div>


            <nav class="pwf-nav">
                <div class="nav-section">Main Menu</div>
                <?php foreach ($menu as $key => $m): ?>
                    <?php if (!empty($m['children'])):
                        $grpActive = ($key === 'database') ? $dbActive : (($key === 'settings') ? $settingsActive : false);
                    ?>
                        <!-- Dropdown group -->
                        <div class="nav-group-btn <?= $grpActive ? 'db-active open' : '' ?>" onclick="toggleNavGroup(this,'nav-sub-<?= $key ?>')">
                            <i class="bi <?= $m['icon'] ?>" style="font-size:15px;flex-shrink:0"></i>
                            <?= htmlspecialchars($m['label']) ?>
                            <i class="bi bi-chevron-right ng-chevron"></i>
                        </div>
                        <div class="nav-sub <?= $grpActive ? 'open' : '' ?>" id="nav-sub-<?= $key ?>">
                            <?php foreach ($m['children'] as $ck => $cm): ?>
                                <a href="<?= BASE_URL ?>/modules/pwf-office/<?= $cm['url'] ?>"
                                    class="<?= ($active === $ck || ($ck === 'settings-main' && $active === 'settings')) ? 'active' : '' ?>">
                                    <i class="bi <?= $cm['icon'] ?>"></i>
                                    <?= htmlspecialchars($cm['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <a href="<?= BASE_URL ?>/modules/pwf-office/<?= $m['url'] ?>"
                            class="<?= $active === $key ? 'active' : '' ?>">
                            <i class="bi <?= $m['icon'] ?>"></i>
                            <?= htmlspecialchars($m['label']) ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <div class="pwf-sidebar-footer">
                <div class="pwf-avatar"><?= $initials ?></div>
                <div>
                    <div class="pwf-user-name"><?= $fullName ?></div>
                    <div class="pwf-user-role"><?= htmlspecialchars($_SESSION['role'] ?? 'Staff') ?></div>
                </div>
                <a href="<?= BASE_URL ?>/modules/pwf-office/logout.php" class="pwf-logout" title="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>

            <div class="pwf-sidebar-version">
                <div>Version: <strong>v1.0.0</strong></div>
                <div>powerby <strong>AdFsystem</strong></div>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="pwf-main">
            <div class="pwf-topbar">
                <div>
                    <div class="pwf-topbar-title"><?= htmlspecialchars($title) ?></div>
                    <div class="pwf-topbar-sub">PWF Office Management System</div>
                </div>
                <div class="pwf-topbar-right">
                    <span class="pwf-date-badge"><i class="bi bi-calendar3 me-1"></i><?= date('D, d M Y') ?></span>
                </div>
            </div>
            <div class="pwf-content">
            <?php
        }

        function pwfOfficeFooter(): void
        {
            echo '  </div></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleNavGroup(btn, subId) {
    const sub = document.getElementById(subId);
    const open = btn.classList.toggle("open");
    sub.classList.toggle("open", open);
}

// Klik area gelap (backdrop) di luar modal box akan menutup modal.
// Ini mencegah modal-overlay yang lupa/gagal ditutup memblokir seluruh
// klik di halaman (termasuk menu sidebar) karena backdrop-nya menutupi
// seluruh layar (position:fixed;inset:0) dengan z-index tinggi.
document.addEventListener("click", function(e) {
    if (e.target.classList && e.target.classList.contains("modal-overlay") && e.target.classList.contains("open")) {
        e.target.classList.remove("open");
    }
});

(function cleanupBuyerPortalServiceWorkerOnInternalPages() {
                    if (!("serviceWorker" in navigator)) return;

                    const path = (window.location.pathname || "").toLowerCase();
    const isBuyerPortalPage =
                        path.indexOf("/modules/pwf-office/customer-portal.php") !== -1 ||
                        path.indexOf("/modules/pwf-office/customer-manifest.php") !== -1;

    if (isBuyerPortalPage) return;

    navigator.serviceWorker.getRegistrations().then(function(registrations) {
        registrations.forEach(function(reg) {
                            const scriptUrl = ((reg.active && reg.active.scriptURL) || (reg.waiting && reg.waiting.scriptURL) || "").toLowerCase();
                            if (scriptUrl.indexOf("/modules/pwf-office/customer-sw.js") !== -1) {
                reg.unregister();
            }
        });
    }).catch(function() {});
})();
</script></body></html>';
        }
