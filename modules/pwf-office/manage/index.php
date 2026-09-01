<?php

/**
 * PWF Office Management Console
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Domain restriction - allow the PWF addon domain (with/without www) and the
// shared ADF system domain (used while pwfoffice.com addon isn't the only entry point)
$allowedHosts = ['www.pwfoffice.com', 'pwfoffice.com', 'adfsystem.online', 'www.adfsystem.online'];
$currentHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isLocal = (strpos($currentHost, 'localhost') !== false || strpos($currentHost, '127.0.0.1') !== false);
if (!$isLocal && !in_array($currentHost, $allowedHosts, true)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403 Forbidden</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0f172a;color:#94a3b8;flex-direction:column;gap:12px}h1{color:#fff;font-size:28px}code{background:#1e293b;padding:4px 10px;border-radius:6px;color:#f59e0b;font-size:14px}</style></head><body><h1>403 Forbidden</h1><p>Halaman ini tidak dapat diakses dari domain ini.</p></body></html>';
    exit;
}

$isOwner = isset($_SESSION['role']) && in_array($_SESSION['role'], ['owner', 'developer']);
if (!$isOwner) {
    http_response_code(403);
    echo '<h1>Access Denied</h1><p>Hanya Owner/Developer yang bisa akses PWF Management.</p>';
    exit;
}

require_once __DIR__ . '/../layout.php';
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PWF Management Console</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: #f8f7f5;
            color: #1c1511;
        }

        .navbar {
            background: white;
            border-bottom: 1px solid #ece9e4;
            padding: 13px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .05);
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14px;
            font-weight: 700;
            color: #1c1511;
            text-decoration: none;
        }

        .brand-icon {
            width: 30px;
            height: 30px;
            background: linear-gradient(135deg, #B8860B, #D4A017);
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }

        .nav-btn {
            padding: 7px 14px;
            border: 1px solid #ece9e4;
            border-radius: 7px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s;
            background: white;
            color: #555;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }

        .nav-btn:hover {
            background: #f5f3f0;
            border-color: #B8860B;
            color: #B8860B;
        }

        .page-wrap {
            max-width: 900px;
            margin: 0 auto;
            padding: 36px 24px 60px;
        }

        .page-header {
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-header-icon {
            width: 46px;
            height: 46px;
            background: linear-gradient(135deg, #B8860B, #D4A017);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(184, 134, 11, .25);
        }

        .page-header-text h1 {
            font-size: 20px;
            font-weight: 800;
            color: #1c1511;
        }

        .page-header-text p {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }

        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            border: 1px solid #ece9e4;
            border-radius: 10px;
            padding: 8px 14px;
            margin-bottom: 28px;
            font-size: 12px;
            color: #666;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .04);
        }

        .user-badge i {
            color: #B8860B;
            font-size: 14px;
        }

        .user-badge strong {
            color: #1c1511;
        }

        .role-tag {
            background: linear-gradient(135deg, #B8860B, #D4A017);
            color: white;
            border-radius: 5px;
            padding: 1px 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .section-label {
            font-size: 10px;
            font-weight: 700;
            color: #aaa;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0 0 12px 2px;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 14px;
            margin-bottom: 32px;
        }

        .menu-card {
            background: white;
            border: 1px solid #ece9e4;
            border-radius: 14px;
            padding: 20px 22px;
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all .2s cubic-bezier(.4, 0, .2, 1);
            box-shadow: 0 1px 4px rgba(0, 0, 0, .04);
        }

        .menu-card:hover {
            transform: translateY(-2px);
            border-color: #D4A017;
            box-shadow: 0 8px 20px rgba(184, 134, 11, .12);
            background: #fffbf2;
        }

        .menu-card-icon {
            width: 44px;
            height: 44px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .menu-card-text {
            flex: 1;
            min-width: 0;
        }

        .menu-card-text h3 {
            font-size: 13.5px;
            font-weight: 700;
            color: #1c1511;
            margin-bottom: 3px;
        }

        .menu-card-text p {
            font-size: 11.5px;
            color: #999;
            line-height: 1.45;
        }

        .menu-card-arrow {
            color: #ccc;
            font-size: 14px;
            flex-shrink: 0;
            transition: all .2s;
        }

        .menu-card:hover .menu-card-arrow {
            color: #B8860B;
            transform: translateX(3px);
        }

        .ic-gold {
            background: #FEF9E7;
            color: #B8860B;
        }

        .ic-blue {
            background: #EFF6FF;
            color: #3B82F6;
        }

        .ic-purple {
            background: #F5F3FF;
            color: #7C3AED;
        }

        .ic-orange {
            background: #FFF7ED;
            color: #EA580C;
        }

        .ic-slate {
            background: #F1F5F9;
            color: #475569;
        }

        .ic-cyan {
            background: #ECFEFF;
            color: #0891B2;
        }

        .ic-green {
            background: #F0FDF4;
            color: #16A34A;
        }

        .ic-rose {
            background: #FFF1F2;
            color: #E11D48;
        }

        @media (max-width: 600px) {
            .menu-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <div class="navbar">
        <a href="index.php" class="navbar-brand">
            <div class="brand-icon"><i class="bi bi-gear-fill"></i></div>
            PWF Management
        </a>
        <a href="../dashboard.php" class="nav-btn">
            <i class="bi bi-house"></i> Dashboard
        </a>
    </div>
</head>

<body>
    <div class="page-wrap">

        <div class="page-header">
            <div class="page-header-icon"><i class="bi bi-sliders2"></i></div>
            <div class="page-header-text">
                <h1>Management Console</h1>
                <p>Kelola user, akses, branding, dan pengaturan sistem PWF Office</p>
            </div>
        </div>

        <div class="user-badge">
            <i class="bi bi-person-circle"></i>
            <strong><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'N/A') ?></strong>
            <span style="color:#ccc">•</span>
            <span><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
            <span class="role-tag"><?= ucfirst($_SESSION['role'] ?? 'N/A') ?></span>
        </div>

        <div class="section-label">Menu Manajemen</div>
        <div class="menu-grid">
            <a href="password.php" class="menu-card">
                <div class="menu-card-icon ic-gold"><i class="bi bi-key-fill"></i></div>
                <div class="menu-card-text">
                    <h3>Ganti Password</h3>
                    <p>Ubah password login Anda</p>
                </div>
                <i class="bi bi-chevron-right menu-card-arrow"></i>
            </a>
            <a href="users.php" class="menu-card">
                <div class="menu-card-icon ic-blue"><i class="bi bi-people-fill"></i></div>
                <div class="menu-card-text">
                    <h3>Kelola User</h3>
                    <p>Buat, edit, hapus &amp; atur role user</p>
                </div>
                <i class="bi bi-chevron-right menu-card-arrow"></i>
            </a>
            <a href="permissions.php" class="menu-card">
                <div class="menu-card-icon ic-purple"><i class="bi bi-shield-lock-fill"></i></div>
                <div class="menu-card-text">
                    <h3>Manajemen Akses</h3>
                    <p>Atur permission &amp; akses menu user</p>
                </div>
                <i class="bi bi-chevron-right menu-card-arrow"></i>
            </a>
            <a href="settings.php" class="menu-card">
                <div class="menu-card-icon ic-orange"><i class="bi bi-palette-fill"></i></div>
                <div class="menu-card-text">
                    <h3>Pengaturan Branding</h3>
                    <p>Logo, wallpaper, nama perusahaan</p>
                </div>
                <i class="bi bi-chevron-right menu-card-arrow"></i>
            </a>

            <a href="settings.php#favicon" class="menu-card">
                <div class="menu-card-icon ic-rose"><i class="bi bi-star-fill"></i></div>
                <div class="menu-card-text">
                    <h3>Setup Favicon</h3>
                    <p>Upload ikon tab browser (favicon)</p>
                </div>
                <i class="bi bi-chevron-right menu-card-arrow"></i>
            </a>
            <a href="menus.php" class="menu-card">
                <div class="menu-card-icon ic-slate"><i class="bi bi-list-ul"></i></div>
                <div class="menu-card-text">
                    <h3>Kelola Menu</h3>
                    <p>Tambah atau edit menu yang tersedia</p>
                </div>
                <i class="bi bi-chevron-right menu-card-arrow"></i>
            </a>
            <a href="audit.php" class="menu-card">
                <div class="menu-card-icon ic-cyan"><i class="bi bi-clock-history"></i></div>
                <div class="menu-card-text">
                    <h3>Audit Log</h3>
                    <p>Riwayat login &amp; perubahan data</p>
                </div>
                <i class="bi bi-chevron-right menu-card-arrow"></i>
            </a>
            <a href="backup.php" class="menu-card">
                <div class="menu-card-icon ic-green"><i class="bi bi-cloud-arrow-down-fill"></i></div>
                <div class="menu-card-text">
                    <h3>Backup &amp; Export</h3>
                    <p>Backup atau export data PWF Office</p>
                </div>
                <i class="bi bi-chevron-right menu-card-arrow"></i>
            </a>
        </div>

    </div>
</body>

</html>