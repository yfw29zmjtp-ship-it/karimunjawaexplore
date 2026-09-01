<?php

/**
 * PWF Management - Settings/Branding
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Check access
$isOwner = isset($_SESSION['role']) && in_array($_SESSION['role'], ['owner', 'developer']);
if (!$isOwner) {
    http_response_code(403);
    echo 'Access Denied';
    exit;
}

$pdo = getPwfOfficePdo();
$msg = '';
$msgType = 'success';

// Get current settings
$settings = [];
$settingRows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_company_phone','pwf_company_address','pwf_login_logo','pwf_login_wallpaper','pwf_office_logo','pwf_favicon')")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($settingRows as $k => $v) {
    $settings[$k] = $v;
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_info') {
        $companyName = trim($_POST['company_name'] ?? '');
        $companyPhone = trim($_POST['company_phone'] ?? '');
        $companyAddress = trim($_POST['company_address'] ?? '');

        if (empty($companyName)) {
            $msg = 'Nama perusahaan tidak boleh kosong';
            $msgType = 'error';
        } else {
            $saveSetting = function ($key, $value) use ($pdo) {
                $check = $pdo->prepare('SELECT id FROM settings WHERE setting_key=?');
                $check->execute([$key]);
                if ($check->fetch()) {
                    $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
                } else {
                    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$key, $value]);
                }
            };

            $saveSetting('pwf_company_name', $companyName);
            $saveSetting('pwf_company_phone', $companyPhone);
            $saveSetting('pwf_company_address', $companyAddress);

            $settings['pwf_company_name'] = $companyName;
            $settings['pwf_company_phone'] = $companyPhone;
            $settings['pwf_company_address'] = $companyAddress;

            $msg = 'Informasi perusahaan berhasil disimpan!';
            $msgType = 'success';
        }
    } elseif ($action === 'upload_logo' && isset($_FILES['logo'])) {
        $file = $_FILES['logo'];
        if ($file['size'] > 0) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $msg = 'Tipe file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP';
                $msgType = 'error';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $msg = 'Ukuran file terlalu besar (max 5MB)';
                $msgType = 'error';
            } else {
                $dir = BASE_PATH . '/uploads/logos/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'pwf-logo-' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    $saveSetting = function ($key, $value) use ($pdo) {
                        $check = $pdo->prepare('SELECT id FROM settings WHERE setting_key=?');
                        $check->execute([$key]);
                        if ($check->fetch()) {
                            $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
                        } else {
                            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$key, $value]);
                        }
                    };
                    $saveSetting('pwf_office_logo', 'uploads/logos/' . $filename);
                    $settings['pwf_office_logo'] = 'uploads/logos/' . $filename;
                    $msg = 'Logo berhasil diupload!';
                    $msgType = 'success';
                } else {
                    $msg = 'Gagal mengupload file';
                    $msgType = 'error';
                }
            }
        }
    } elseif ($action === 'upload_favicon' && isset($_FILES['favicon'])) {
        $file = $_FILES['favicon'];
        if ($file['size'] > 0) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['ico', 'png', 'jpg', 'jpeg', 'webp', 'svg'])) {
                $msg = 'Tipe file tidak didukung. Gunakan ICO, PNG, SVG, atau JPG';
                $msgType = 'error';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $msg = 'Ukuran file terlalu besar (max 2MB)';
                $msgType = 'error';
            } else {
                $dir = BASE_PATH . '/uploads/icons/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'pwf-favicon-' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    $saveSetting = function ($key, $value) use ($pdo) {
                        $check = $pdo->prepare('SELECT id FROM settings WHERE setting_key=?');
                        $check->execute([$key]);
                        if ($check->fetch()) {
                            $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
                        } else {
                            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$key, $value]);
                        }
                    };
                    $saveSetting('pwf_favicon', 'uploads/icons/' . $filename);
                    $settings['pwf_favicon'] = 'uploads/icons/' . $filename;
                    $msg = 'Favicon berhasil diupload!';
                    $msgType = 'success';
                } else {
                    $msg = 'Gagal mengupload file';
                    $msgType = 'error';
                }
            }
        }
    } elseif ($action === 'upload_wallpaper' && isset($_FILES['wallpaper'])) {
        $file = $_FILES['wallpaper'];
        if ($file['size'] > 0) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $msg = 'Tipe file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP';
                $msgType = 'error';
            } elseif ($file['size'] > 10 * 1024 * 1024) {
                $msg = 'Ukuran file terlalu besar (max 10MB)';
                $msgType = 'error';
            } else {
                $dir = BASE_PATH . '/uploads/backgrounds/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'pwf-wallpaper-' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    $saveSetting = function ($key, $value) use ($pdo) {
                        $check = $pdo->prepare('SELECT id FROM settings WHERE setting_key=?');
                        $check->execute([$key]);
                        if ($check->fetch()) {
                            $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
                        } else {
                            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$key, $value]);
                        }
                    };
                    $saveSetting('pwf_login_wallpaper', 'uploads/backgrounds/' . $filename);
                    $settings['pwf_login_wallpaper'] = 'uploads/backgrounds/' . $filename;
                    $msg = 'Wallpaper login berhasil diupload!';
                    $msgType = 'success';
                } else {
                    $msg = 'Gagal mengupload file';
                    $msgType = 'error';
                }
            }
        }
    }
}

require_once __DIR__ . '/../layout.php';
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Pengaturan Branding - PWF Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #f5f3f0 0%, #faf9f7 100%);
            color: #1c1511;
        }

        .navbar {
            background: white;
            border-bottom: 1px solid #E7E5E4;
            padding: 14px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .04);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #1c1511;
            text-decoration: none;
        }

        .navbar-brand i {
            color: #B8860B;
            font-size: 20px;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .nav-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-btn-secondary {
            background: #F5F3F0;
            color: #1c1511;
        }

        .nav-btn-secondary:hover {
            background: #EAE8E5;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 24px;
        }

        .header {
            margin-bottom: 32px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #1c1511;
        }

        .header h1 i {
            color: #B8860B;
            font-size: 32px;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .06);
            border: 1px solid #E7E5E4;
        }

        .card h2 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #1c1511;
            border-bottom: 2px solid #F5F3F0;
            padding-bottom: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        input[type="text"],
        textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #E7E5E4;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all .2s;
        }

        input[type="text"]:focus,
        textarea:focus {
            outline: none;
            border-color: #B8860B;
            box-shadow: 0 0 0 3px rgba(184, 134, 11, .1);
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .preview {
            margin-top: 16px;
            max-width: 320px;
        }

        .preview strong {
            font-size: 12px;
            font-weight: 700;
            color: #666;
            display: block;
            margin-bottom: 8px;
        }

        .preview img {
            max-width: 100%;
            border-radius: 8px;
            border: 1px solid #E7E5E4;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .08);
        }

        input[type="file"] {
            padding: 8px 0;
            font-size: 13px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 13px;
        }

        .alert.success {
            background: #F0FDF4;
            color: #166534;
            border: 1px solid #DCFCE7;
        }

        .alert.error {
            background: #FEF2F2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        button {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all .2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #B8860B 0%, #D4A017 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(184, 134, 11, .2);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(184, 134, 11, .3);
        }

        .btn-secondary {
            background: #F5F3F0;
            color: #1c1511;
            border: 1px solid #E7E5E4;
        }

        .btn-secondary:hover {
            background: #EAE8E5;
        }
    </style>
    <div class="navbar">
        <div class="navbar-left">
            <a href="index.php" class="navbar-brand">
                <i class="bi bi-gear-fill"></i>
                PWF Management
            </a>
        </div>
        <div class="navbar-right">
            <button class="nav-btn nav-btn-secondary" onclick="window.location.href='index.php'" title="Back to Menu">
                <i class="bi bi-arrow-left"></i> Kembali
            </button>
            <button class="nav-btn nav-btn-secondary" onclick="window.location.href='../dashboard.php'" title="Back to Dashboard">
                <i class="bi bi-house"></i> Dashboard
            </button>
        </div>
    </div>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><i class="bi bi-palette-fill"></i>Pengaturan Branding</h1>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?= $msgType ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <!-- Informasi Perusahaan -->
        <div class="card">
            <h2>📋 Informasi Perusahaan</h2>
            <form method="post">
                <input type="hidden" name="_action" value="save_info">

                <div class="form-group">
                    <label>Nama Perusahaan</label>
                    <input type="text" name="company_name" value="<?= htmlspecialchars($settings['pwf_company_name'] ?? 'Prapen Wood Furniture') ?>" required>
                </div>

                <div class="form-group">
                    <label>Nomor Telepon</label>
                    <input type="text" name="company_phone" value="<?= htmlspecialchars($settings['pwf_company_phone'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Alamat Kantor</label>
                    <textarea name="company_address"><?= htmlspecialchars($settings['pwf_company_address'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn-primary">Simpan Informasi</button>
            </form>
        </div>

        <!-- Logo Perusahaan -->
        <div class="card">
            <h2>🏢 Logo Perusahaan</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_action" value="upload_logo">

                <div class="form-group">
                    <label>Upload Logo</label>
                    <input type="file" name="logo" accept="image/*" required>
                    <div style="font-size: 12px; color: #999; margin-top: 4px;">Format: JPG, PNG, GIF, WebP | Max: 5MB</div>
                </div>

                <?php if (!empty($settings['pwf_office_logo'])): ?>
                    <div class="preview">
                        <strong style="font-size: 12px;">Logo Saat Ini:</strong><br>
                        <img src="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . $settings['pwf_office_logo']) ?>" alt="Logo">
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary" style="margin-top: 16px;">Upload Logo</button>
            </form>
        </div>

        <!-- Wallpaper Login -->
        <div class="card">
            <h2>🎨 Wallpaper Halaman Login</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_action" value="upload_wallpaper">

                <div class="form-group">
                    <label>Upload Wallpaper</label>
                    <input type="file" name="wallpaper" accept="image/*" required>
                    <div style="font-size: 12px; color: #999; margin-top: 4px;">Format: JPG, PNG, GIF, WebP | Max: 10MB | Rekomendasi: Landscape 1920×1080 atau lebih</div>
                </div>

                <?php if (!empty($settings['pwf_login_wallpaper'])): ?>
                    <div class="preview">
                        <strong style="font-size: 12px;">Wallpaper Saat Ini:</strong><br>
                        <img src="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . $settings['pwf_login_wallpaper']) ?>" alt="Wallpaper">
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary" style="margin-top: 16px;">Upload Wallpaper</button>
            </form>
        </div>

        <!-- Favicon / Tab Icon -->
        <div class="card" id="favicon">
            <h2>⭐ Favicon (Ikon Tab Browser)</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_action" value="upload_favicon">

                <div class="form-group">
                    <label>Upload Favicon</label>
                    <input type="file" name="favicon" accept=".ico,.png,.jpg,.jpeg,.webp,.svg" required>
                    <div style="font-size: 12px; color: #999; margin-top: 4px;">Format: ICO, PNG, SVG, JPG | Max: 2MB | Rekomendasi: ukuran 32×32 atau 64×64 px</div>
                </div>

                <?php if (!empty($settings['pwf_favicon'])): ?>
                    <div class="preview" style="display:flex;align-items:center;gap:12px;margin-top:12px;padding:12px;background:#f8f7f5;border-radius:8px;border:1px solid #e7e5e4">
                        <img src="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . $settings['pwf_favicon']) ?>" alt="Favicon" style="width:32px;height:32px;object-fit:contain;">
                        <div>
                            <strong style="font-size: 12px;display:block;margin-bottom:2px;">Favicon Saat Ini:</strong>
                            <span style="font-size:11px;color:#999"><?= htmlspecialchars(basename($settings['pwf_favicon'])) ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary" style="margin-top: 16px;">Upload Favicon</button>
            </form>
        </div>
    </div>
</body>

</html>