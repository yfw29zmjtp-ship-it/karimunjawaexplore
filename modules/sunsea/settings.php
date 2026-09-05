<?php

/**
 * Sunsea - Pengaturan Sistem
 * Pengaturan Perusahaan & Invoice
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();
sunseaEnsureUserSchema($pdo);
$currentUser = $auth->getCurrentUser();

// Auto-create settings table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` TEXT,
        `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_key (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) { /* table exists */
}

// Helper: get setting
function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v !== false && $v !== null) ? (string)$v : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Helper: set setting
function setSetting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
        ON DUPLICATE KEY UPDATE setting_value=?, updated_at=NOW()")
        ->execute([$key, $value, $value]);
}

$tab = $_GET['tab'] ?? 'company';
$flashMsg = '';
$flashType = '';

$sidebarMenuOptions = [
    'dashboard'    => 'Dashboard',
    'database'     => 'Database',
    'bookings'     => 'Booking',
    'calendar'     => 'Kalender Booking',
    'coordinators' => 'Koordinator',
    'packages'     => 'Paket Wisata',
    'rab'          => 'Cetak RAB',
    'quotations'   => 'Penawaran',
    'invoices'     => 'Invoice',
    'finance'      => 'Finance (Kas Operasional)',
    'settings'     => 'Pengaturan',
];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postTab = $_POST['tab'] ?? 'company';

    if ($postTab === 'company') {
        $fields = [
            'company_name',
            'company_tagline',
            'company_address',
            'company_phone',
            'company_email',
            'company_website',
            'company_npwp'
        ];
        foreach ($fields as $f) {
            setSetting($pdo, $f, trim($_POST[$f] ?? ''));
        }

        // Logo upload
        $logoUploadError = '';
        if (!empty($_FILES['company_logo']['tmp_name'])) {
            if ((int)($_FILES['company_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $logoUploadError = 'Upload logo gagal (kode error: ' . $_FILES['company_logo']['error'] . ').';
            } else {
                $uploadDir = __DIR__ . '/../../uploads/sunsea/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                    $logoUploadError = 'Format logo harus PNG, JPG, JPEG, WEBP, atau GIF.';
                } else {
                    // Remove old logo files with a different extension so stale files don't linger.
                    foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $oldExt) {
                        $oldFile = $uploadDir . 'company_logo.' . $oldExt;
                        if ($oldExt !== $ext && file_exists($oldFile)) unlink($oldFile);
                    }
                    $fname = 'company_logo.' . $ext;
                    if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $uploadDir . $fname)) {
                        setSetting($pdo, 'company_logo', 'uploads/sunsea/' . $fname);
                    } else {
                        $logoUploadError = 'Gagal menyimpan file logo ke server (cek permission folder uploads/sunsea).';
                    }
                }
            }
        }

        if ($logoUploadError) {
            $flashMsg = 'Data perusahaan disimpan, tetapi logo gagal diupload: ' . $logoUploadError;
            $flashType = 'error';
        } else {
            $flashMsg = 'Pengaturan perusahaan berhasil disimpan.';
            $flashType = 'success';
        }
        $tab = 'company';
    }

    if ($postTab === 'invoice') {
        $fields = [
            'invoice_prefix',
            'invoice_footer',
            'invoice_notes',
            'bank_name',
            'bank_account',
            'bank_holder',
            'bank_name2',
            'bank_account2',
            'bank_holder2',
            'default_tax_pct',
            'invoice_valid_days',
            'invoice_show_tax'
        ];
        foreach ($fields as $f) {
            setSetting($pdo, $f, trim($_POST[$f] ?? ''));
        }

        // Upload a named image setting (invoice_logo / invoice_stamp), keeping filename fixed per setting.
        $uploadImageSetting = function (string $fileField, string $settingKey) use ($pdo): string {
            if (empty($_FILES[$fileField]['tmp_name'])) return '';
            if ((int)($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return "Upload $fileField gagal (kode error: " . $_FILES[$fileField]['error'] . ").";
            }
            $uploadDir = __DIR__ . '/../../uploads/sunsea/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES[$fileField]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                return "Format $fileField harus PNG, JPG, JPEG, WEBP, atau GIF.";
            }
            foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $oldExt) {
                $oldFile = $uploadDir . $settingKey . '.' . $oldExt;
                if ($oldExt !== $ext && file_exists($oldFile)) unlink($oldFile);
            }
            $fname = $settingKey . '.' . $ext;
            if (!move_uploaded_file($_FILES[$fileField]['tmp_name'], $uploadDir . $fname)) {
                return "Gagal menyimpan file $fileField ke server (cek permission folder uploads/sunsea).";
            }
            setSetting($pdo, $settingKey, 'uploads/sunsea/' . $fname);
            return '';
        };

        $logoErr = $uploadImageSetting('invoice_logo', 'invoice_logo');
        $stampErr = $uploadImageSetting('invoice_stamp', 'invoice_stamp');
        if ($logoErr || $stampErr) {
            $flashMsg = trim($logoErr . ' ' . $stampErr);
            $flashType = 'error';
        }

        if (empty($flashMsg)) {
            $flashMsg = 'Pengaturan invoice berhasil disimpan.';
            $flashType = 'success';
        } else {
            $flashMsg = 'Data invoice disimpan, tetapi: ' . $flashMsg;
        }
        $tab = 'invoice';
    }

    if ($postTab === 'sidebar') {
        $selected = $_POST['sidebar_menu'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }

        $selected = array_values(array_intersect(array_keys($sidebarMenuOptions), $selected));
        if (empty($selected)) {
            $selected = ['bookings'];
        }

        setSetting($pdo, 'sidebar_visible_menu_keys', json_encode($selected));

        $flashMsg = 'Pengaturan sidebar berhasil disimpan.';
        $flashType = 'success';
        $tab = 'sidebar';
    }

    if ($postTab === 'reset') {
        $confirmText = trim($_POST['confirm_text'] ?? '');
        $categories = $_POST['reset_categories'] ?? [];
        if (!is_array($categories)) {
            $categories = [];
        }
        $categories = array_values(array_intersect(['guests', 'bookings', 'finance'], $categories));

        if ($confirmText !== 'RESET') {
            $flashMsg = 'Konfirmasi gagal. Ketik RESET untuk melanjutkan.';
            $flashType = 'error';
        } elseif (empty($categories)) {
            $flashMsg = 'Pilih minimal satu jenis data yang ingin direset.';
            $flashType = 'error';
        } else {
            $categoryTables = [
                'guests'   => ['customers'],
                'bookings' => ['quotation_items', 'quotations', 'booking_order_items', 'booking_orders', 'booking_schedule'],
                'finance'  => ['payments', 'invoice_items', 'invoices', 'cash_book', 'bill_payments', 'bills'],
            ];
            $categorySequences = [
                'bookings' => ['quotation', 'booking'],
                'finance'  => ['invoice'],
            ];
            $categoryLabels = [
                'guests'   => 'Data Tamu',
                'bookings' => 'Data Booking',
                'finance'  => 'Data Keuangan',
            ];

            $truncated = [];
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

                foreach ($categories as $cat) {
                    foreach (($categoryTables[$cat] ?? []) as $t) {
                        try {
                            $check = $pdo->query("SHOW TABLES LIKE '$t'");
                            if ($check && $check->rowCount() > 0) {
                                $pdo->exec("TRUNCATE TABLE `$t`");
                                $truncated[] = $t;
                            }
                        } catch (Exception $e) {
                            @error_log("Sunsea reset: could not truncate $t - " . $e->getMessage());
                        }
                    }
                    foreach (($categorySequences[$cat] ?? []) as $seq) {
                        try {
                            $pdo->prepare("UPDATE sequences SET last_value = 0 WHERE seq_name = ?")->execute([$seq]);
                        } catch (Exception $e) {
                        }
                    }
                }

                if (in_array('finance', $categories, true)) {
                    try {
                        $pdo->exec("UPDATE cash_accounts SET current_balance = 0");
                    } catch (Exception $e) {
                    }
                }

                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

                $doneLabels = [];
                foreach ($categories as $cat) {
                    $doneLabels[] = $categoryLabels[$cat] ?? $cat;
                }
                $flashMsg = 'Berhasil reset: ' . implode(', ', $doneLabels) . ' (' . count($truncated) . ' tabel dikosongkan).';
                $flashType = 'success';
            } catch (Exception $e) {
                $flashMsg = 'Terjadi error saat reset: ' . htmlspecialchars($e->getMessage());
                $flashType = 'error';
            }
        }
        $tab = 'reset';
    }

    if ($postTab === 'users') {
        $userAction = $_POST['user_action'] ?? 'add';

        if ($userAction === 'add') {
            $newUsername = trim($_POST['new_username'] ?? '');
            $newFullName = trim($_POST['new_full_name'] ?? '');
            $newEmail    = trim($_POST['new_email'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $newRoleId   = (int)($_POST['new_role_id'] ?? 3);

            if ($newUsername === '' || $newFullName === '' || $newPassword === '') {
                $flashMsg = 'Username, Nama Lengkap, dan Password wajib diisi.';
                $flashType = 'error';
            } elseif (strlen($newPassword) < 6) {
                $flashMsg = 'Password minimal 6 karakter.';
                $flashType = 'error';
            } else {
                $existing = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $existing->execute([$newUsername]);
                if ($existing->fetch()) {
                    $flashMsg = 'Username sudah digunakan, pilih username lain.';
                    $flashType = 'error';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $pdo->prepare("INSERT INTO users (username, password, full_name, email, role_id, business_access, is_active, created_at, updated_at)
                        VALUES (?,?,?,?,?, 'all', 1, NOW(), NOW())")
                        ->execute([$newUsername, $hash, $newFullName, $newEmail ?: null, $newRoleId]);
                    $flashMsg = 'User baru "' . $newUsername . '" berhasil ditambahkan.';
                    $flashType = 'success';
                }
            }
        } elseif ($userAction === 'reset_password') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $newPass = (string)($_POST['reset_password'] ?? '');
            if ($uid > 0 && strlen($newPass) >= 6) {
                $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([password_hash($newPass, PASSWORD_BCRYPT), $uid]);
                $flashMsg = 'Password user berhasil direset.';
                $flashType = 'success';
            } else {
                $flashMsg = 'Password baru minimal 6 karakter.';
                $flashType = 'error';
            }
        } elseif ($userAction === 'toggle_active') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid > 0 && $uid === (int)($currentUser['id'] ?? 0)) {
                $flashMsg = 'Anda tidak bisa menonaktifkan akun Anda sendiri.';
                $flashType = 'error';
            } elseif ($uid > 0) {
                $pdo->prepare("UPDATE users SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?")->execute([$uid]);
                $flashMsg = 'Status user berhasil diubah.';
                $flashType = 'success';
            }
        }
        $tab = 'users';
    }

    if ($postTab === 'login_page') {
        $loginPageAction = $_POST['login_page_action'] ?? 'save';
        $bgUploadDir = __DIR__ . '/../../uploads/backgrounds/';
        if (!is_dir($bgUploadDir)) mkdir($bgUploadDir, 0755, true);

        if ($loginPageAction === 'remove') {
            foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $oldExt) {
                $oldFile = $bgUploadDir . 'sunsea-login-bg.' . $oldExt;
                if (file_exists($oldFile)) unlink($oldFile);
            }
            setSetting($pdo, 'login_background', '');
            $flashMsg = 'Background login dihapus, kembali ke default.';
            $flashType = 'success';
        } elseif (!empty($_FILES['login_background']['tmp_name'])) {
            if ((int)($_FILES['login_background']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $flashMsg = 'Upload background gagal (kode error: ' . $_FILES['login_background']['error'] . ').';
                $flashType = 'error';
            } else {
                $ext = strtolower(pathinfo($_FILES['login_background']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                    $flashMsg = 'Format background harus PNG, JPG, JPEG, WEBP, atau GIF.';
                    $flashType = 'error';
                } else {
                    foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $oldExt) {
                        $oldFile = $bgUploadDir . 'sunsea-login-bg.' . $oldExt;
                        if ($oldExt !== $ext && file_exists($oldFile)) unlink($oldFile);
                    }
                    $fname = 'sunsea-login-bg.' . $ext;
                    if (move_uploaded_file($_FILES['login_background']['tmp_name'], $bgUploadDir . $fname)) {
                        setSetting($pdo, 'login_background', $fname);
                        $flashMsg = 'Background halaman login berhasil disimpan.';
                        $flashType = 'success';
                    } else {
                        $flashMsg = 'Gagal menyimpan file background ke server (cek permission folder uploads/backgrounds).';
                        $flashType = 'error';
                    }
                }
            }
        } else {
            $flashMsg = 'Pilih file gambar terlebih dahulu.';
            $flashType = 'error';
        }
        $tab = 'login_page';
    }
}

// Load semua settings
$cfg = [];
$keys = [
    'company_name',
    'company_tagline',
    'company_address',
    'company_phone',
    'company_email',
    'company_website',
    'company_npwp',
    'company_logo',
    'invoice_prefix',
    'invoice_footer',
    'invoice_notes',
    'invoice_logo',
    'invoice_stamp',
    'bank_name',
    'bank_account',
    'bank_holder',
    'bank_name2',
    'bank_account2',
    'bank_holder2',
    'default_tax_pct',
    'invoice_valid_days',
    'invoice_show_tax',
    'sidebar_visible_menu_keys',
    'login_background',
];
foreach ($keys as $k) {
    $cfg[$k] = getSetting($pdo, $k);
}

// Defaults
$cfg['invoice_prefix']     = $cfg['invoice_prefix']     ?: 'SS-INV';
$cfg['default_tax_pct']    = $cfg['default_tax_pct']    ?: '11';
$cfg['invoice_valid_days'] = $cfg['invoice_valid_days'] ?: '7';
$cfg['company_name']       = $cfg['company_name']       ?: 'Explore Karimunjawa';
$cfg['company_tagline']    = $cfg['company_tagline']    ?: 'Your Trusted Travel Partner in Karimunjawa';

$visibleSidebarMenus = json_decode($cfg['sidebar_visible_menu_keys'] ?? '[]', true);
if (!is_array($visibleSidebarMenus) || empty($visibleSidebarMenus)) {
    $visibleSidebarMenus = array_keys($sidebarMenuOptions);
}
$visibleSidebarMenus = array_values(array_intersect(array_keys($sidebarMenuOptions), $visibleSidebarMenus));
if (empty($visibleSidebarMenus)) {
    $visibleSidebarMenus = ['bookings'];
}

// Load users + roles for the "User" tab
$roles = $pdo->query("SELECT id, role_code, role_name FROM roles ORDER BY id")->fetchAll();
if (empty($roles)) {
    $roles = [
        ['id' => 1, 'role_code' => 'developer', 'role_name' => 'Developer / Owner'],
        ['id' => 2, 'role_code' => 'manager', 'role_name' => 'Manager'],
        ['id' => 3, 'role_code' => 'staff', 'role_name' => 'Staff'],
    ];
}
$roleNameById = [];
foreach ($roles as $r) $roleNameById[$r['id']] = $r['role_name'];

$sunseaUsers = $pdo->query("SELECT id, username, full_name, email, role_id, is_active, last_login FROM users ORDER BY id")->fetchAll();

$pageTitle = 'Pengaturan';
$activePage = 'settings';
include 'layout-header.php';

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
?>

<?php if ($flashMsg): ?>
    <div style="padding:12px 16px;margin-bottom:16px;border-radius:6px;font-weight:500;
    background:<?php echo $flashType === 'success' ? '#e6f7f0' : '#fee'; ?>;
    border:1px solid <?php echo $flashType === 'success' ? '#34d399' : '#f88'; ?>;
    color:<?php echo $flashType === 'success' ? '#065f46' : '#c33'; ?>;">
        <?php echo htmlspecialchars($flashMsg); ?>
    </div>
<?php endif; ?>

<!-- Tab Navigation -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #e0e7ef;">
    <a href="?tab=company" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'company' ? 'border-bottom-color:#C2410C;color:#C2410C;' : 'color:#666;'; ?>">
        🏢 Perusahaan
    </a>
    <a href="?tab=invoice" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'invoice' ? 'border-bottom-color:#C2410C;color:#C2410C;' : 'color:#666;'; ?>">
        🧾 Invoice & Pembayaran
    </a>
    <a href="?tab=sidebar" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'sidebar' ? 'border-bottom-color:#C2410C;color:#C2410C;' : 'color:#666;'; ?>">
        🧭 Setup Sidebar
    </a>
    <a href="?tab=users" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'users' ? 'border-bottom-color:#C2410C;color:#C2410C;' : 'color:#666;'; ?>">
        👤 User
    </a>
    <a href="?tab=login_page" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'login_page' ? 'border-bottom-color:#C2410C;color:#C2410C;' : 'color:#666;'; ?>">
        🖼️ Background Login
    </a>
    <a href="?tab=reset" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'reset' ? 'border-bottom-color:#b91c1c;color:#b91c1c;' : 'color:#666;'; ?>">
        🗑️ Reset Data
    </a>
</div>

<!-- TAB: PERUSAHAAN -->
<?php if ($tab === 'company'): ?>
    <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;">
        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:16px;font-weight:700;color:#7C2D12;margin-bottom:16px;">⚙️ Pengaturan Perusahaan</div>
            <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px;">
                <input type="hidden" name="tab" value="company">

                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Nama Perusahaan *</label>
                    <input type="text" name="company_name" value="<?php echo htmlspecialchars($cfg['company_name']); ?>"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                </div>

                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Tagline / Slogan</label>
                    <input type="text" name="company_tagline" value="<?php echo htmlspecialchars($cfg['company_tagline']); ?>"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                </div>

                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Alamat</label>
                    <textarea name="company_address" rows="3"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;resize:vertical;"><?php echo htmlspecialchars($cfg['company_address']); ?></textarea>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Telepon / WA</label>
                        <input type="text" name="company_phone" value="<?php echo htmlspecialchars($cfg['company_phone']); ?>"
                            style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Email</label>
                        <input type="email" name="company_email" value="<?php echo htmlspecialchars($cfg['company_email']); ?>"
                            style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Website</label>
                        <input type="text" name="company_website" value="<?php echo htmlspecialchars($cfg['company_website']); ?>"
                            style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">NPWP</label>
                        <input type="text" name="company_npwp" value="<?php echo htmlspecialchars($cfg['company_npwp']); ?>"
                            style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                    </div>
                </div>

                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Logo Perusahaan</label>
                    <input type="file" name="company_logo" accept="image/*"
                        style="width:100%;padding:6px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:13px;box-sizing:border-box;">
                    <small style="color:#888;">Format: PNG/JPG/WEBP. Maks 2MB. Digunakan di header, sidebar, dokumen.</small>
                </div>

                <div style="padding-top:6px;">
                    <button type="submit" style="padding:10px 24px;background:#C2410C;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">
                        💾 Simpan Pengaturan Perusahaan
                    </button>
                </div>
            </form>
        </div>

        <!-- Preview -->
        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:14px;font-weight:700;color:#7C2D12;margin-bottom:12px;">👁️ Preview</div>
            <?php if ($cfg['company_logo']): ?>
                <?php $companyLogoPath = __DIR__ . '/../../' . trim($cfg['company_logo'], '/'); ?>
                <div style="text-align:center;margin-bottom:12px;padding:12px;background:#f8fbff;border-radius:6px;">
                    <img src="<?php echo htmlspecialchars($baseUrl . '/' . trim($cfg['company_logo'], '/')) . '?v=' . (file_exists($companyLogoPath) ? filemtime($companyLogoPath) : time()); ?>"
                        alt="Logo" style="max-height:80px;max-width:100%;object-fit:contain;">
                </div>
            <?php else: ?>
                <div style="text-align:center;padding:24px 12px;background:#f8fbff;border-radius:6px;color:#999;margin-bottom:12px;">
                    <div style="font-size:32px;">🌊</div>
                    <small>Logo belum diupload</small>
                </div>
            <?php endif; ?>
            <div style="font-size:18px;font-weight:700;color:#7C2D12;"><?php echo htmlspecialchars($cfg['company_name']); ?></div>
            <div style="font-size:12px;color:#666;margin-top:4px;"><?php echo htmlspecialchars($cfg['company_tagline']); ?></div>
            <?php if ($cfg['company_address']): ?>
                <div style="font-size:12px;color:#888;margin-top:8px;border-top:1px solid #eee;padding-top:8px;"><?php echo nl2br(htmlspecialchars($cfg['company_address'])); ?></div>
            <?php endif; ?>
            <?php if ($cfg['company_phone']): ?>
                <div style="font-size:12px;color:#888;margin-top:4px;">📞 <?php echo htmlspecialchars($cfg['company_phone']); ?></div>
            <?php endif; ?>
            <?php if ($cfg['company_email']): ?>
                <div style="font-size:12px;color:#888;margin-top:2px;">✉️ <?php echo htmlspecialchars($cfg['company_email']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB: INVOICE -->
<?php elseif ($tab === 'invoice'): ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="tab" value="invoice">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start;">

            <!-- Logo & Identitas Invoice -->
            <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
                <div style="font-size:15px;font-weight:700;color:#7C2D12;margin-bottom:14px;">🧾 Identitas Invoice</div>
                <div style="display:flex;flex-direction:column;gap:12px;">

                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Logo Invoice</label>
                        <input type="file" name="invoice_logo" accept="image/*"
                            style="width:100%;padding:6px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:13px;box-sizing:border-box;">
                        <?php if ($cfg['invoice_logo']): ?>
                            <?php $invoiceLogoPath = __DIR__ . '/../../' . trim($cfg['invoice_logo'], '/'); ?>
                            <div style="margin-top:8px;padding:8px;background:#f8fbff;border-radius:4px;text-align:center;">
                                <img src="<?php echo htmlspecialchars($baseUrl . '/' . trim($cfg['invoice_logo'], '/')) . '?v=' . (file_exists($invoiceLogoPath) ? filemtime($invoiceLogoPath) : time()); ?>"
                                    alt="Invoice Logo" style="max-height:50px;max-width:100%;object-fit:contain;">
                                <div style="font-size:11px;color:#888;margin-top:4px;">Logo saat ini</div>
                            </div>
                        <?php endif; ?>
                        <small style="color:#888;">Tampil di kop surat invoice. Gunakan logo dengan background transparan (PNG).</small>
                    </div>

                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Stempel / Cap Perusahaan</label>
                        <input type="file" name="invoice_stamp" accept="image/*"
                            style="width:100%;padding:6px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:13px;box-sizing:border-box;">
                        <?php if ($cfg['invoice_stamp']): ?>
                            <?php $invoiceStampPath = __DIR__ . '/../../' . trim($cfg['invoice_stamp'], '/'); ?>
                            <div style="margin-top:8px;padding:8px;background:#f8fbff;border-radius:4px;text-align:center;">
                                <img src="<?php echo htmlspecialchars($baseUrl . '/' . trim($cfg['invoice_stamp'], '/')) . '?v=' . (file_exists($invoiceStampPath) ? filemtime($invoiceStampPath) : time()); ?>"
                                    alt="Stempel" style="max-height:70px;max-width:100%;object-fit:contain;">
                                <div style="font-size:11px;color:#888;margin-top:4px;">Stempel saat ini</div>
                            </div>
                        <?php endif; ?>
                        <small style="color:#888;">Muncul di area tanda tangan invoice. Gunakan PNG background transparan.</small>
                    </div>

                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Prefix Nomor Invoice</label>
                        <input type="text" name="invoice_prefix" value="<?php echo htmlspecialchars($cfg['invoice_prefix']); ?>"
                            style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;"
                            placeholder="SS-INV">
                        <small style="color:#888;">Contoh: SS-INV-2026-001</small>
                    </div>

                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">% Pajak Default</label>
                        <input type="number" name="default_tax_pct" value="<?php echo htmlspecialchars($cfg['default_tax_pct']); ?>"
                            min="0" max="100" step="0.01"
                            style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;"
                            placeholder="11">
                        <small style="color:#888;">Isi 0 jika tidak menggunakan pajak.</small>
                    </div>

                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Validitas Invoice (hari)</label>
                        <input type="number" name="invoice_valid_days" value="<?php echo htmlspecialchars($cfg['invoice_valid_days']); ?>"
                            min="1"
                            style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;"
                            placeholder="7">
                    </div>

                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Catatan Bawah Invoice</label>
                        <textarea name="invoice_footer" rows="3"
                            style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;resize:vertical;"
                            placeholder="Terima kasih telah mempercayakan perjalanan Anda kepada kami."><?php echo htmlspecialchars($cfg['invoice_footer']); ?></textarea>
                    </div>

                    <div>
                        <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Syarat & Ketentuan</label>
                        <textarea name="invoice_notes" rows="4"
                            style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;resize:vertical;"
                            placeholder="1. Pembayaran dilakukan paling lambat H-3 keberangkatan.&#10;2. Booking fee tidak dapat dikembalikan."><?php echo htmlspecialchars($cfg['invoice_notes']); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Rekening Pembayaran -->
            <div>
                <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;margin-bottom:16px;">
                    <div style="font-size:15px;font-weight:700;color:#7C2D12;margin-bottom:14px;">🏦 Rekening Pembayaran Utama</div>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div>
                            <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Nama Bank</label>
                            <input type="text" name="bank_name" value="<?php echo htmlspecialchars($cfg['bank_name']); ?>"
                                style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;"
                                placeholder="BCA / BRI / BNI / Mandiri">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Nomor Rekening</label>
                            <input type="text" name="bank_account" value="<?php echo htmlspecialchars($cfg['bank_account']); ?>"
                                style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;"
                                placeholder="1234567890">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Atas Nama</label>
                            <input type="text" name="bank_holder" value="<?php echo htmlspecialchars($cfg['bank_holder']); ?>"
                                style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;"
                                placeholder="Nama Pemilik Rekening">
                        </div>
                    </div>
                </div>

                <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;margin-bottom:16px;">
                    <div style="font-size:15px;font-weight:700;color:#7C2D12;margin-bottom:14px;">🏦 Rekening Pembayaran Kedua (opsional)</div>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div>
                            <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Nama Bank</label>
                            <input type="text" name="bank_name2" value="<?php echo htmlspecialchars($cfg['bank_name2']); ?>"
                                style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;"
                                placeholder="BCA / BRI / BNI / Mandiri">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Nomor Rekening</label>
                            <input type="text" name="bank_account2" value="<?php echo htmlspecialchars($cfg['bank_account2']); ?>"
                                style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;"
                                placeholder="1234567890">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Atas Nama</label>
                            <input type="text" name="bank_holder2" value="<?php echo htmlspecialchars($cfg['bank_holder2']); ?>"
                                style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;"
                                placeholder="Nama Pemilik Rekening">
                        </div>
                    </div>
                </div>

                <!-- Preview rekening -->
                <?php if ($cfg['bank_name'] || $cfg['bank_account']): ?>
                    <div style="background:#FFF7ED;border:1px solid #bae6fd;border-radius:8px;padding:14px;">
                        <div style="font-size:13px;font-weight:700;color:#0369a1;margin-bottom:8px;">Preview di Invoice:</div>
                        <div style="font-size:13px;color:#7C2D12;">
                            <strong><?php echo htmlspecialchars($cfg['bank_name']); ?></strong><br>
                            <?php echo htmlspecialchars($cfg['bank_account']); ?><br>
                            a.n. <?php echo htmlspecialchars($cfg['bank_holder']); ?>
                            <?php if ($cfg['bank_name2']): ?>
                                <hr style="border:none;border-top:1px solid #bae6fd;margin:8px 0;">
                                <strong><?php echo htmlspecialchars($cfg['bank_name2']); ?></strong><br>
                                <?php echo htmlspecialchars($cfg['bank_account2']); ?><br>
                                a.n. <?php echo htmlspecialchars($cfg['bank_holder2']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top:16px;padding:16px;background:#fff;border:1px solid #dde5ef;border-radius:8px;display:flex;justify-content:flex-end;gap:10px;">
            <a href="invoices.php" style="padding:10px 20px;background:#FFF7ED;color:#C2410C;border:1px solid #C2410C;border-radius:5px;text-decoration:none;font-weight:600;font-size:14px;">
                📄 Lihat Invoice
            </a>
            <button type="submit" style="padding:10px 24px;background:#C2410C;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">
                💾 Simpan Pengaturan Invoice
            </button>
        </div>
    </form>

    <!-- TAB: SIDEBAR -->
<?php elseif ($tab === 'sidebar'): ?>
    <form method="POST">
        <input type="hidden" name="tab" value="sidebar">
        <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;">
            <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
                <div style="font-size:16px;font-weight:700;color:#7C2D12;margin-bottom:8px;">🧭 Setup Sidebar</div>
                <div style="font-size:13px;color:#666;margin-bottom:16px;">Centang menu yang ingin ditampilkan di sidebar Explore Karimunjawa.</div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <?php foreach ($sidebarMenuOptions as $key => $label): ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #d9e2ec;border-radius:6px;cursor:pointer;background:#fafcff;">
                            <input type="checkbox" name="sidebar_menu[]" value="<?php echo htmlspecialchars($key); ?>"
                                <?php echo in_array($key, $visibleSidebarMenus, true) ? 'checked' : ''; ?>>
                            <span style="font-size:13px;font-weight:600;color:#334155;"><?php echo htmlspecialchars($label); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:14px;padding:10px 12px;border-radius:6px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:12px;">
                    Jika semua checkbox tidak dipilih, sistem otomatis menampilkan menu Booking agar sidebar tidak kosong.
                </div>

                <div style="padding-top:14px;">
                    <button type="submit" style="padding:10px 24px;background:#C2410C;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">
                        💾 Simpan Setup Sidebar
                    </button>
                </div>
            </div>

            <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
                <div style="font-size:14px;font-weight:700;color:#7C2D12;margin-bottom:12px;">👁️ Preview Menu Aktif</div>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($visibleSidebarMenus as $mKey): ?>
                        <div style="padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;color:#334155;background:#f8fafc;">
                            <?php echo htmlspecialchars($sidebarMenuOptions[$mKey] ?? $mKey); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </form>

    <!-- TAB: USER -->
<?php elseif ($tab === 'users'): ?>
    <div style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start;">
        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:16px;font-weight:700;color:#7C2D12;margin-bottom:16px;">👤 Daftar User</div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;">
                            <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Username</th>
                            <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Nama</th>
                            <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Role</th>
                            <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Status</th>
                            <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sunseaUsers as $u): ?>
                            <tr>
                                <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-weight:600;color:#334155;">
                                    <?php echo htmlspecialchars($u['username']); ?>
                                    <?php if ((int)$u['id'] === (int)($currentUser['id'] ?? 0)): ?>
                                        <span style="font-size:10px;color:#0369a1;">(Anda)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;">
                                    <?php echo htmlspecialchars($u['full_name']); ?><br>
                                    <small style="color:#888;"><?php echo htmlspecialchars($u['email'] ?? ''); ?></small>
                                </td>
                                <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars($roleNameById[$u['role_id']] ?? '-'); ?></td>
                                <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;">
                                    <?php if ((int)$u['is_active'] === 1): ?>
                                        <span style="padding:2px 8px;border-radius:99px;background:#d1fae5;color:#065f46;font-size:11px;font-weight:600;">Aktif</span>
                                    <?php else: ?>
                                        <span style="padding:2px 8px;border-radius:99px;background:#fee2e2;color:#991b1b;font-size:11px;font-weight:600;">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;white-space:nowrap;">
                                    <button type="button" onclick="toggleResetPasswordRow(<?php echo (int)$u['id']; ?>)"
                                        style="padding:4px 8px;font-size:11px;border:1px solid #C2410C;color:#C2410C;background:#fff;border-radius:4px;cursor:pointer;">Reset Password</button>
                                    <?php if ((int)$u['id'] !== (int)($currentUser['id'] ?? 0)): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Ubah status user ini?')">
                                            <input type="hidden" name="tab" value="users">
                                            <input type="hidden" name="user_action" value="toggle_active">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <button type="submit" style="padding:4px 8px;font-size:11px;border:1px solid #94a3b8;color:#475569;background:#fff;border-radius:4px;cursor:pointer;">
                                                <?php echo ((int)$u['is_active'] === 1) ? 'Nonaktifkan' : 'Aktifkan'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" id="resetPassForm<?php echo (int)$u['id']; ?>" style="display:none;margin-top:6px;">
                                        <input type="hidden" name="tab" value="users">
                                        <input type="hidden" name="user_action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <input type="password" name="reset_password" placeholder="Password baru (min 6)" minlength="6" required
                                            style="padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:12px;">
                                        <button type="submit" style="padding:5px 8px;font-size:11px;background:#C2410C;color:#fff;border:none;border-radius:4px;cursor:pointer;">Simpan</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sunseaUsers)): ?>
                            <tr>
                                <td colspan="5" style="padding:16px;text-align:center;color:#888;">Belum ada user.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:14px;font-weight:700;color:#7C2D12;margin-bottom:14px;">➕ Tambah User Baru</div>
            <form method="POST" style="display:flex;flex-direction:column;gap:12px;">
                <input type="hidden" name="tab" value="users">
                <input type="hidden" name="user_action" value="add">
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Username *</label>
                    <input type="text" name="new_username" required
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Nama Lengkap *</label>
                    <input type="text" name="new_full_name" required
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Email</label>
                    <input type="email" name="new_email"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Password *</label>
                    <input type="password" name="new_password" minlength="6" required
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                    <small style="color:#888;">Minimal 6 karakter.</small>
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Role *</label>
                    <select name="new_role_id"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>" <?php echo ($r['role_code'] === 'staff') ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="padding-top:6px;">
                    <button type="submit" style="padding:10px 24px;background:#C2410C;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;width:100%;">
                        💾 Tambah User
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function toggleResetPasswordRow(id) {
            var f = document.getElementById('resetPassForm' + id);
            f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
        }
    </script>

    <!-- TAB: BACKGROUND LOGIN -->
<?php elseif ($tab === 'login_page'): ?>
    <div style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start;">
        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:16px;font-weight:700;color:#7C2D12;margin-bottom:8px;">🖼️ Background Halaman Login</div>
            <div style="font-size:13px;color:#666;margin-bottom:16px;">Upload gambar background yang akan tampil di halaman login Explore Karimunjawa.</div>
            <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px;">
                <input type="hidden" name="tab" value="login_page">
                <input type="hidden" name="login_page_action" value="save">
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Pilih Gambar</label>
                    <input type="file" name="login_background" accept="image/*"
                        style="width:100%;padding:6px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:13px;box-sizing:border-box;">
                    <small style="color:#888;">Format: PNG/JPG/WEBP. Disarankan ukuran landscape (mis. 1920x1080).</small>
                </div>
                <div style="padding-top:6px;display:flex;gap:10px;">
                    <button type="submit" style="padding:10px 24px;background:#C2410C;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">
                        💾 Simpan Background
                    </button>
                </div>
            </form>
            <?php if ($cfg['login_background']): ?>
                <form method="POST" style="margin-top:12px;" onsubmit="return confirm('Hapus background login dan kembali ke default?')">
                    <input type="hidden" name="tab" value="login_page">
                    <input type="hidden" name="login_page_action" value="remove">
                    <button type="submit" style="padding:8px 16px;background:#fff;color:#b91c1c;border:1px solid #b91c1c;border-radius:5px;font-weight:600;cursor:pointer;font-size:13px;">
                        🗑️ Hapus Background (kembali ke default)
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:14px;font-weight:700;color:#7C2D12;margin-bottom:12px;">👁️ Preview</div>
            <?php if ($cfg['login_background']): ?>
                <div style="border-radius:6px;overflow:hidden;border:1px solid #e2e8f0;">
                    <img src="<?php echo htmlspecialchars($baseUrl . '/uploads/backgrounds/' . $cfg['login_background']); ?>"
                        alt="Login Background" style="width:100%;height:180px;object-fit:cover;display:block;">
                </div>
            <?php else: ?>
                <div style="text-align:center;padding:24px 12px;background:#f8fbff;border-radius:6px;color:#999;">
                    <div style="font-size:32px;">🌊</div>
                    <small>Belum ada background custom, memakai gambar default.</small>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB: RESET DATA -->
<?php elseif ($tab === 'reset'): ?>
    <form method="POST" onsubmit="return confirmSunseaReset(this)">
        <input type="hidden" name="tab" value="reset">
        <div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:20px;max-width:640px;">
            <div style="font-size:16px;font-weight:700;color:#b91c1c;margin-bottom:6px;">🗑️ Reset Data</div>
            <div style="font-size:13px;color:#666;margin-bottom:16px;">Pilih jenis data yang ingin dikosongkan. Tindakan ini <strong>tidak bisa dibatalkan</strong>.</div>

            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px;">
                <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #fecaca;border-radius:6px;cursor:pointer;background:#fff5f5;">
                    <input type="checkbox" name="reset_categories[]" value="guests" style="margin-top:3px;">
                    <span>
                        <span style="display:block;font-weight:700;font-size:13px;color:#334155;">👤 Data Tamu</span>
                        <span style="display:block;font-size:12px;color:#888;">Menghapus semua data pelanggan (customers).</span>
                    </span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #fecaca;border-radius:6px;cursor:pointer;background:#fff5f5;">
                    <input type="checkbox" name="reset_categories[]" value="bookings" style="margin-top:3px;">
                    <span>
                        <span style="display:block;font-weight:700;font-size:13px;color:#334155;">🗓️ Data Booking</span>
                        <span style="display:block;font-size:12px;color:#888;">Menghapus semua penawaran (quotation) &amp; pemesanan (booking), termasuk item &amp; jadwalnya. Nomor urut penawaran/booking direset.</span>
                    </span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #fecaca;border-radius:6px;cursor:pointer;background:#fff5f5;">
                    <input type="checkbox" name="reset_categories[]" value="finance" style="margin-top:3px;">
                    <span>
                        <span style="display:block;font-weight:700;font-size:13px;color:#334155;">💰 Data Keuangan</span>
                        <span style="display:block;font-size:12px;color:#888;">Menghapus semua invoice, pembayaran, kas harian, dan tagihan rutin. Saldo kas direset ke 0. Nomor urut invoice direset.</span>
                    </span>
                </label>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;color:#888;margin-bottom:6px;">Ketik <strong>RESET</strong> untuk konfirmasi:</label>
                <input type="text" name="confirm_text" placeholder="RESET"
                    style="width:100%;max-width:220px;padding:9px 12px;border:1px solid #fca5a5;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
            </div>

            <button type="submit" style="padding:10px 24px;background:#b91c1c;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">
                🗑️ Reset Data Terpilih
            </button>
        </div>
    </form>
    <script>
        function confirmSunseaReset(form) {
            var checked = form.querySelectorAll('input[name="reset_categories[]"]:checked');
            if (checked.length === 0) {
                alert('Pilih minimal satu jenis data yang ingin direset.');
                return false;
            }
            var confirmInput = form.querySelector('input[name="confirm_text"]');
            if ((confirmInput.value || '').trim() !== 'RESET') {
                alert('Ketik RESET pada kolom konfirmasi untuk melanjutkan.');
                return false;
            }
            var labels = [];
            checked.forEach(function(c) {
                labels.push(c.value);
            });
            return confirm('Anda yakin ingin menghapus data: ' + labels.join(', ') + '?\n\nTindakan ini TIDAK BISA DIBATALKAN!');
        }
    </script>
<?php endif; ?>

<?php include 'layout-footer.php'; ?>