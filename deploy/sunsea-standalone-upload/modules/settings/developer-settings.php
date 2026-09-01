<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Check if user has permission to access settings
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Developer Settings';

// Get settings from database
$loginBgSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_background'");
$currentLoginBg = $loginBgSetting['setting_value'] ?? null;

$waSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'developer_whatsapp'");
$currentWA = $waSetting['setting_value'] ?? '';

$footerCopyrightSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_copyright'");
$currentFooterCopyright = $footerCopyrightSetting['setting_value'] ?? '';

$footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
$currentFooterVersion = $footerVersionSetting['setting_value'] ?? '';

// Read current config
$configFile = BASE_PATH . '/config/config.php';
$configContent = file_get_contents($configFile);

// Extract current values
preg_match("/define\('DEVELOPER_NAME',\s*'([^']*)'\);/", $configContent, $nameMatch);
preg_match("/define\('DEVELOPER_LOGO',\s*'([^']*)'\);/", $configContent, $logoMatch);

$currentDevName = $nameMatch[1] ?? 'DevTeam Studio';
$currentDevLogo = $logoMatch[1] ?? 'assets/img/developer-logo.png';

// Get businesses for reset data functionality
$businesses = [];
try {
    $businessResult = $db->fetchAll("SELECT business_id, business_name, business_type FROM businesses WHERE status = 'active' ORDER BY business_name");
    foreach ($businessResult as $business) {
        $businesses[$business['business_id']] = $business;
    }
} catch (Exception $e) {
    // If no businesses table, use default
    $businesses = ['1' => ['business_id' => '1', 'business_name' => 'Default Business', 'business_type' => 'hotel']];
}

// Function to get business display name
function getBusinessDisplayName($businessId) {
    global $businesses;
    if (isset($businesses[$businessId])) {
        $b = $businesses[$businessId];
        return $b['business_name'] . ' (' . ucfirst($b['business_type']) . ')';
    }
    return 'Business #' . $businessId;
}

$selectedBusiness = $_POST['reset_business_id'] ?? (array_key_first($businesses) ?: '1');
$resetResult = null;

// Handle reset data submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_data_submit'])) {
    $businessId = $_POST['reset_business_id'] ?? '';
    $resetTypes = $_POST['reset_type'] ?? [];
    
    if (!empty($businessId) && !empty($resetTypes)) {
        $resetResult = [];
        
        foreach ($resetTypes as $type) {
            // Call reset API for each type
            $postData = json_encode([
                'business_id' => $businessId,
                'reset_type' => $type
            ]);
            
            // Build session cookie header for API authentication
            $sessionCookie = session_name() . '=' . session_id();
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nCookie: $sessionCookie",
                    'content' => $postData,
                    'ignore_errors' => true
                ]
            ]);
            
            $response = @file_get_contents(BASE_URL . '/api/reset-business-data.php', false, $context);
            $httpCode = 200; // Default success
            
            $resetResult[$type] = [
                'http' => $httpCode,
                'response' => $response ?: json_encode(['success' => false, 'message' => 'No response from API'])
            ];
        }
        
        setFlash('info', 'Reset data telah dijalankan. Lihat hasil detail di bawah.');
    } else {
        setFlash('error', 'Pilih bisnis dan minimal 1 jenis data untuk direset.');
    }
}

// Handle form submission for name
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['developer_name'])) {
    $newName = trim($_POST['developer_name']);
    
    if (!empty($newName)) {
        // Update config file
        $newConfigContent = preg_replace(
            "/define\('DEVELOPER_NAME',\s*'[^']*'\);/",
            "define('DEVELOPER_NAME', '" . addslashes($newName) . "');",
            $configContent
        );
        
        if (file_put_contents($configFile, $newConfigContent)) {
            setFlash('success', 'Nama developer berhasil diupdate!');
            header('Location: developer-settings.php');
            exit;
        } else {
            setFlash('error', 'Tidak berhasil memperbarui berkas konfigurasi. Periksa izin folder.');
        }
    } else {
        setFlash('error', 'Nama developer tidak boleh kosong.');
    }
}

// Handle WhatsApp number update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['whatsapp_number'])) {
    $waNumber = trim($_POST['whatsapp_number']);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('developer_whatsapp', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$waNumber, $waNumber]);
    setFlash('success', 'Nomor WhatsApp berhasil disimpan!');
    header('Location: developer-settings.php');
    exit;
}

// Handle footer text update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['footer_copyright'])) {
    $copyright = trim($_POST['footer_copyright']);
    $version = trim($_POST['footer_version']);
    
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_copyright', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$copyright, $copyright]);
    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('footer_version', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$version, $version]);
    
    setFlash('success', 'Teks footer berhasil diupdate!');
    header('Location: developer-settings.php');
    exit;
}

include '../../includes/header.php';
?>

<style>
    .preview-box {
        background: var(--bg-secondary);
        border: 2px solid var(--bg-tertiary);
        border-radius: var(--radius-lg);
        padding: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .dev-logo-preview {
        width: 80px;
        height: 80px;
        border-radius: var(--radius-md);
        object-fit: contain;
        background: var(--bg-primary);
        padding: 0.5rem;
    }
    
    .checkbox-group {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 0.75rem;
        margin: 1rem 0;
    }
    
    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        color: var(--text-primary);
        cursor: pointer;
        padding: 0.5rem;
        border-radius: var(--radius-md);
        transition: all 0.2s;
    }
    
    .checkbox-label:hover {
        background: var(--bg-tertiary);
    }
    
    .checkbox-label input[type="checkbox"] {
        margin: 0;
        width: 16px;
        height: 16px;
    }
</style>

<!-- Page Header -->
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.375rem;">
            Developer Settings
        </h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">
            Konfigurasi nama developer, logo, WhatsApp, footer, dan reset data bisnis
        </p>
    </div>
    <a href="../settings/" class="btn btn-secondary">
        <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
        Kembali
    </a>
</div>

<!--==========================================
DEVELOPER NAME SETTINGS 
==========================================-->
<div class="card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
        <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center;">
            <i data-feather="user" style="width: 18px; height: 18px; color: white;"></i>
        </div>
        <div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                Nama Developer
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Tampil di footer sidebar
            </p>
        </div>
    </div>
    
    <form method="POST">
        <div class="form-group">
            <label class="form-label">Nama Developer</label>
            <input type="text" name="developer_name" class="form-control" value="<?php echo htmlspecialchars($currentDevName); ?>" required maxlength="50">
            <small style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.375rem; display: block;">
                Maksimal 50 karakter. Contoh: "DevTeam Studio", "PT. Teknologi Indonesia"
            </small>
        </div>
        
        <button type="submit" class="btn btn-primary" style="width: 100%;">
            <i data-feather="save" style="width: 16px; height: 16px;"></i>
            Simpan Nama Developer
        </button>
    </form>
    
    <!-- Current Value Display -->
    <div style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-primary); border-radius: var(--radius-md); border-left: 3px solid var(--primary-color);">
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.375rem;">Nama Saat Ini:</div>
        <div style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($currentDevName); ?></div>
    </div>
</div>

<!--==========================================
WHATSAPP SETTINGS 
==========================================-->
<div class="card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
        <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, #25d366, #128c7e); display: flex; align-items: center; justify-content: center;">
            <i data-feather="message-circle" style="width: 18px; height: 18px; color: white;"></i>
        </div>
        <div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">WhatsApp Developer</h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">Kontak support via WhatsApp</p>
        </div>
    </div>
    
    <form method="POST">
        <div class="form-group">
            <label class="form-label">Nomor WhatsApp</label>
            <input type="text" name="whatsapp_number" class="form-control" value="<?php echo htmlspecialchars($currentWA); ?>" placeholder="628123456789">
            <small style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.375rem; display: block;">
                Format: 628123456789 (tanpa tanda +, spasi, atau tanda hubung)
            </small>
        </div>
        
        <button type="submit" class="btn btn-success" style="width: 100%;">
            <i data-feather="phone" style="width: 16px; height: 16px;"></i>
            Simpan WhatsApp
        </button>
    </form>
</div>

<!--==========================================
FOOTER TEXT SETTINGS 
==========================================-->
<div class="card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
        <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, #8b5cf6, #06b6d4); display: flex; align-items: center; justify-content: center;">
            <i data-feather="type" style="width: 18px; height: 18px; color: white;"></i>
        </div>
        <div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">Footer Text</h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">Teks copyright dan versi di footer</p>
        </div>
    </div>
    
    <form method="POST">
        <div class="form-group">
            <label class="form-label">Copyright Text</label>
            <input type="text" name="footer_copyright" class="form-control" value="<?php echo htmlspecialchars($currentFooterCopyright); ?>" placeholder="© 2026 ADF System. All rights reserved.">
        </div>
        
        <div class="form-group">
            <label class="form-label">Version</label>
            <input type="text" name="footer_version" class="form-control" value="<?php echo htmlspecialchars($currentFooterVersion); ?>" placeholder="v2.1.0">
        </div>
        
        <button type="submit" class="btn btn-info" style="width: 100%;">
            <i data-feather="edit-3" style="width: 16px; height: 16px;"></i>
            Update Footer Text
        </button>
    </form>
</div>

<!--==========================================
RESET DATA BUSINESS SETTINGS 
==========================================-->
<div class="card" style="margin-bottom: 2rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
        <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: linear-gradient(135deg, #ef4444, #f59e42); display: flex; align-items: center; justify-content: center;">
            <i data-feather="trash-2" style="width: 18px; height: 18px; color: white;"></i>
        </div>
        <div>
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">Reset Data Bisnis</h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">Hapus data tertentu untuk bisnis terpilih</p>
        </div>
    </div>
    
    <!-- Warning Notice -->
    <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: var(--radius-lg); padding: 1rem; margin-bottom: 1.5rem; display: flex; align-items: start; gap: 0.75rem;">
        <i data-feather="alert-triangle" style="width: 20px; height: 20px; color: var(--danger); flex-shrink: 0; margin-top: 0.125rem;"></i>
        <div>
            <h4 style="font-size: 0.938rem; font-weight: 600; color: var(--text-primary); margin: 0 0 0.375rem 0;">
                ⚠️ PERINGATAN - Reset Data Permanen
            </h4>
            <p style="font-size: 0.813rem; color: var(--text-secondary); margin: 0; line-height: 1.4;">
                Data yang dihapus <strong>tidak dapat dikembalikan!</strong> Pastikan sudah backup database sebelum melakukan reset.
            </p>
        </div>
    </div>
    
    <form method="POST" onsubmit="return confirm('⚠️ KONFIRMASI RESET DATA\\n\\nAnda yakin ingin reset data untuk bisnis ini?\\nData akan dihapus PERMANEN dan tidak dapat dikembalikan!\\n\\nKlik OK untuk melanjutkan atau Cancel untuk membatalkan.');">
        <div class="form-group">
            <label class="form-label">Pilih Bisnis</label>
            <select name="reset_business_id" class="form-control" required>
                <?php if (!empty($businesses)): ?>
                    <?php foreach ($businesses as $bid => $b): ?>
                        <option value="<?php echo htmlspecialchars($bid); ?>" <?php if ($selectedBusiness == $bid) echo 'selected'; ?>>
                            <?php echo getBusinessDisplayName($bid); ?>
                        </option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <option value="1">Default Business (Hotel)</option>
                <?php endif; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin-top: 1.5rem;">
            <label class="form-label">Pilih Data yang Direset</label>
            <div class="checkbox-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="accounting"> 
                    💰 Data Accounting
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="bookings"> 
                    📅 Data Booking/Reservasi
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="invoices"> 
                    🧾 Data Invoice
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="procurement"> 
                    📋 Data PO & Procurement
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="inventory"> 
                    📦 Data Inventory
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="employees"> 
                    👥 Data Karyawan
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="users"> 
                    🔐 Data User (non-admin)
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="guests"> 
                    🏨 Data Tamu
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="menu"> 
                    🍽️ Data Menu
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="orders"> 
                    🛒 Data Orders
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="reports"> 
                    📊 Data Reports
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="reset_type[]" value="logs"> 
                    📝 Data Logs
                </label>
            </div>
        </div>
        
        <div style="margin-top: 1.5rem;">
            <button type="submit" name="reset_data_submit" class="btn btn-danger" style="width: 100%; font-weight: 600; font-size: 0.95rem;">
                <i data-feather="trash-2" style="width: 16px; height: 16px;"></i>
                Reset Data yang Dipilih
            </button>
        </div>
    </form>
    
    <?php if ($resetResult !== null): ?>
        <div style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-primary); border-radius: var(--radius-lg); border-left: 3px solid var(--info);">
            <h4 style="font-size: 0.95rem; font-weight: 600; color: var(--text-primary); margin: 0 0 0.75rem 0;">
                📊 Hasil Reset Data:
            </h4>
            <ul style="font-size: 0.875rem; margin: 0; padding-left: 1.25rem;">
                <?php foreach ($resetResult as $type => $res): 
                    $data = json_decode($res['response'], true);
                    $isSuccess = $res['http'] == 200 && !empty($data['success']);
                    $message = $data['message'] ?? 'No response';
                ?>
                    <li style="margin-bottom: 0.5rem; color: <?php echo $isSuccess ? 'var(--success)' : 'var(--danger)'; ?>;">
                        <strong><?php echo htmlspecialchars($type); ?>:</strong> 
                        <?php echo $isSuccess ? '✅' : '❌'; ?> 
                        <?php echo htmlspecialchars($message); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>