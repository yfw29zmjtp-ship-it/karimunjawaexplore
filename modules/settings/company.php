<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/CloudinaryHelper.php';

$auth = new Auth();
$auth->requireLogin();

// Check if user has permission to access settings
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Pengaturan Perusahaan';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Update company settings
        $settings = [
            'company_name', 'company_tagline', 'company_address', 
            'company_phone', 'company_email', 'company_website'
        ];
        
        foreach ($settings as $key) {
            if (isset($_POST[$key])) {
                // Check if setting exists
                $exists = $db->fetchOne(
                    "SELECT id FROM settings WHERE setting_key = :key",
                    ['key' => $key]
                );
                
                if ($exists) {
                    // Update existing setting
                    $db->query(
                        "UPDATE settings SET setting_value = :value WHERE setting_key = :key",
                        ['value' => $_POST[$key], 'key' => $key]
                    );
                } else {
                    // Insert new setting
                    $db->insert('settings', [
                        'setting_key' => $key,
                        'setting_value' => $_POST[$key],
                        'setting_type' => 'text',
                        'description' => ucwords(str_replace('_', ' ', $key))
                    ]);
                }
            }
        }
        
        // Handle logo upload (per business)
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $fileExt = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($fileExt, $allowedExts)) {
                $localFilename = ACTIVE_BUSINESS_ID . '_logo.' . $fileExt;
                
                // Smart upload: Cloudinary → local fallback
                $cloudinary = CloudinaryHelper::getInstance();
                $uploadResult = $cloudinary->smartUpload(
                    $_FILES['company_logo'],
                    'uploads/logos',
                    $localFilename,
                    'logos',
                    'company_logo_' . ACTIVE_BUSINESS_ID
                );
                
                if ($uploadResult['success']) {
                    $storedValue = $uploadResult['path'];
                    
                    // Store in settings
                    $settingKey = 'company_logo_' . ACTIVE_BUSINESS_ID;
                    $exists = $db->fetchOne(
                        "SELECT id FROM settings WHERE setting_key = :key",
                        ['key' => $settingKey]
                    );
                    if ($exists) {
                        $db->query(
                            "UPDATE settings SET setting_value = :value WHERE setting_key = :key",
                            ['value' => $storedValue, 'key' => $settingKey]
                        );
                    } else {
                        $db->insert('settings', [
                            'setting_key' => $settingKey,
                            'setting_value' => $storedValue,
                            'setting_type' => 'file',
                            'description' => 'Company logo for ' . BUSINESS_NAME
                        ]);
                    }
                }
            }
        }
        
        $db->commit();
        setFlashMessage('success', 'Pengaturan perusahaan berhasil disimpan!');
        header('Location: company.php');
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        setFlashMessage('error', 'Gagal menyimpan pengaturan: ' . $e->getMessage());
    }
}

// Get current settings for active business
$currentSettings = [];
$settings = $db->fetchAll(
    "SELECT setting_key, setting_value FROM settings 
     WHERE setting_key LIKE 'company_%' 
     ORDER BY setting_key DESC"
);

foreach ($settings as $setting) {
    // Check if this is a business-specific setting
    if (strpos($setting['setting_key'], 'company_logo_') === 0) {
        // Extract business ID from key (e.g., 'company_logo_bens-cafe')
        $businessId = str_replace('company_logo_', '', $setting['setting_key']);
        if ($businessId === ACTIVE_BUSINESS_ID) {
            $currentSettings['company_logo'] = $setting['setting_value'];
        }
    } else {
        $currentSettings[$setting['setting_key']] = $setting['setting_value'];
    }
}

include '../../includes/header.php';
?>

<div style="max-width: 800px;">
    <!-- Back Button -->
    <div style="margin-bottom: 1rem;">
        <a href="index.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
    </div>

    <div class="card">
        <div style="padding: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="briefcase" style="width: 22px; height: 22px; color: var(--primary-color);"></i>
                Pengaturan Perusahaan
            </h2>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0.5rem 0 0 0;">
                Informasi ini akan tampil di header laporan PDF dan tampilan aplikasi
            </p>
        </div>

        <form method="POST" enctype="multipart/form-data" style="padding: 1.25rem;">
            
            <!-- Logo Upload -->
            <div class="form-group">
                <label class="form-label">Logo Perusahaan (<?php echo BUSINESS_NAME; ?>)</label>
                <?php if (!empty($currentSettings['company_logo'])): ?>
                    <?php 
                    $isCloudLogo = (strpos($currentSettings['company_logo'], 'http') === 0);
                    $logoPath = '../../uploads/logos/' . $currentSettings['company_logo'];
                    if ($isCloudLogo || file_exists($logoPath)): 
                        $logoDisplaySrc = $isCloudLogo ? $currentSettings['company_logo'] : BASE_URL . '/uploads/logos/' . $currentSettings['company_logo'];
                    ?>
                    <div style="margin-bottom: 0.75rem;">
                        <img src="<?php echo htmlspecialchars($logoDisplaySrc); ?>" 
                             alt="Current Logo" 
                             style="max-width: 200px; max-height: 80px; border-radius: var(--radius-md); border: 1px solid var(--bg-tertiary); padding: 0.5rem; background: white;"
                             onerror="this.style.display='none'">
                    </div>
                    <?php else: ?>
                    <div style="margin-bottom: 0.75rem; padding: 1rem; background: var(--warning-bg); border: 1px solid var(--warning-border); border-radius: var(--radius-md); color: var(--warning-text); font-size: 0.875rem;">
                        ⚠️ Logo tidak ditemukan. Silakan upload logo baru.
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                <input type="file" name="company_logo" class="form-control" accept="image/*">
                <small style="font-size: 0.75rem; color: var(--text-muted);">Format: JPG, PNG, GIF. Max 2MB. Rekomendasi: 400x150px</small>
            </div>

            <!-- Company Name -->
            <div class="form-group">
                <label class="form-label">Nama Perusahaan *</label>
                <input type="text" name="company_name" class="form-control" 
                       value="<?php echo htmlspecialchars($currentSettings['company_name'] ?? ''); ?>" required>
            </div>

            <!-- Tagline -->
            <div class="form-group">
                <label class="form-label">Tagline</label>
                <input type="text" name="company_tagline" class="form-control" 
                       value="<?php echo htmlspecialchars($currentSettings['company_tagline'] ?? ''); ?>" 
                       placeholder="Hotel Management System">
            </div>

            <!-- Address -->
            <div class="form-group">
                <label class="form-label">Alamat Lengkap *</label>
                <textarea name="company_address" class="form-control" rows="3" required><?php echo htmlspecialchars($currentSettings['company_address'] ?? ''); ?></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <!-- Phone -->
                <div class="form-group">
                    <label class="form-label">Telepon</label>
                    <input type="text" name="company_phone" class="form-control" 
                           value="<?php echo htmlspecialchars($currentSettings['company_phone'] ?? ''); ?>" 
                           placeholder="+62 361 123456">
                </div>

                <!-- Email -->
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="company_email" class="form-control" 
                           value="<?php echo htmlspecialchars($currentSettings['company_email'] ?? ''); ?>" 
                           placeholder="info@narayanahotel.com">
                </div>
            </div>

            <!-- Website -->
            <div class="form-group">
                <label class="form-label">Website</label>
                <input type="text" name="company_website" class="form-control" 
                       value="<?php echo htmlspecialchars($currentSettings['company_website'] ?? ''); ?>" 
                       placeholder="www.narayanahotel.com">
            </div>

            <!-- Submit Button -->
            <div style="display: flex; gap: 0.75rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                <button type="submit" class="btn btn-primary">
                    <i data-feather="save" style="width: 16px; height: 16px;"></i>
                    Simpan Pengaturan
                </button>
                <a href="index.php" class="btn btn-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
