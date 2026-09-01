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
$pageTitle = 'Pengaturan Laporan PDF';

// Get current settings - prioritize active business settings
$settings = [];
$result = $db->fetchAll("SELECT setting_key, setting_value FROM settings");
foreach ($result as $row) {
    // Check if this is a business-specific setting
    if (strpos($row['setting_key'], 'company_') === 0) {
        // For company_logo_{businessid}, extract and match
        if (strpos($row['setting_key'], 'company_logo_') === 0) {
            $businessId = str_replace('company_logo_', '', $row['setting_key']);
            if ($businessId === ACTIVE_BUSINESS_ID) {
                $settings['company_logo'] = $row['setting_value'];
            }
        } else {
            // Global company settings
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } else {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// Get business-specific company info from active business
require_once '../../includes/business_helper.php';
$businessConfig = getActiveBusinessConfig();

// Get company name from settings, fallback to business config name
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$businessName = ($companyNameSetting && $companyNameSetting['setting_value']) 
    ? $companyNameSetting['setting_value'] 
    : ($businessConfig['name'] ?? BUSINESS_NAME);

$businessAddress = $settings['company_address'] ?? '';
$businessPhone = $settings['company_phone'] ?? '';
$businessEmail = $settings['company_email'] ?? '';
$businessTagline = $settings['company_tagline'] ?? '';

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['invoice_logo'])) {
    $file = $_FILES['invoice_logo'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        if (!in_array($file['type'], $allowedTypes)) {
            setFlashMessage('error', 'Tipe file tidak diizinkan. Gunakan JPG, PNG, atau GIF.');
        } elseif ($file['size'] > $maxSize) {
            setFlashMessage('error', 'Ukuran file terlalu besar. Maksimal 2MB.');
        } else {
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $localFilename = ACTIVE_BUSINESS_ID . '_invoice_logo.' . $extension;
            
            // Smart upload: Cloudinary → local fallback
            $cloudinary = CloudinaryHelper::getInstance();
            $uploadResult = $cloudinary->smartUpload(
                $file,
                'uploads/logos',
                $localFilename,
                'logos',
                'invoice_logo_' . ACTIVE_BUSINESS_ID
            );
            
            if ($uploadResult['success']) {
                $storedValue = $uploadResult['path'];
                $settingKey = 'invoice_logo_' . ACTIVE_BUSINESS_ID;
                $exists = $db->fetchOne("SELECT COUNT(*) as count FROM settings WHERE setting_key = :key", 
                    ['key' => $settingKey]);
                
                if ($exists['count'] > 0) {
                    $db->update('settings', 
                        ['setting_value' => $storedValue], 
                        'setting_key = :key', 
                        ['key' => $settingKey]
                    );
                } else {
                    $db->insert('settings', [
                        'setting_key' => $settingKey,
                        'setting_value' => $storedValue
                    ]);
                }
                
                setFlashMessage('success', 'Logo faktur berhasil diunggah!' . ($uploadResult['is_cloud'] ? ' (Cloudinary)' : ''));
                header('Location: report-settings.php');
                exit;
            } else {
                setFlashMessage('error', 'Tidak berhasil mengunggah berkas.');
            }
        }
    }
}

// Handle logo delete
if (isset($_GET['delete_invoice_logo']) && $_GET['delete_invoice_logo'] === '1') {
    $settingKey = 'invoice_logo_' . ACTIVE_BUSINESS_ID;
    $currentLogo = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = :key", 
        ['key' => $settingKey]);
    if ($currentLogo) {
        $logoVal = $currentLogo['setting_value'];
        if (strpos($logoVal, 'http') === 0) {
            // Cloudinary URL - delete from Cloudinary
            try {
                $cloudinary = CloudinaryHelper::getInstance();
                $cloudinary->delete($logoVal);
            } catch (Exception $e) {
                error_log('Cloudinary delete error: ' . $e->getMessage());
            }
        } else {
            // Local file
            $uploadDir = BASE_PATH . '/uploads/logos/';
            if (file_exists($uploadDir . $logoVal)) {
                unlink($uploadDir . $logoVal);
            }
        }
        
        $db->query("DELETE FROM settings WHERE setting_key = :key", 
            ['key' => $settingKey]);
        setFlashMessage('success', 'Logo faktur berhasil dihapus!');
        header('Location: report-settings.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['invoice_logo'])) {
    try {
        $db->getConnection()->beginTransaction();
        
        $updates = [
            'report_show_logo' => isset($_POST['report_show_logo']) ? '1' : '0',
            'report_show_address' => isset($_POST['report_show_address']) ? '1' : '0',
            'report_show_phone' => isset($_POST['report_show_phone']) ? '1' : '0'
        ];
        
        foreach ($updates as $key => $value) {
            $db->update('settings', 
                ['setting_value' => $value], 
                'setting_key = :key', 
                ['key' => $key]
            );
        }
        
        $db->getConnection()->commit();
        setFlashMessage('success', 'Pengaturan laporan PDF berhasil diupdate!');
        header('Location: report-settings.php');
        exit;
    } catch (Exception $e) {
        $db->getConnection()->rollBack();
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

include '../../includes/header.php';
?>

<div style="max-width: 1200px;">
    <div style="margin-bottom: 1rem;">
        <a href="index.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
    </div>

    <!-- Logo Invoice Upload Section -->
    <div class="card" style="margin-bottom: 1.5rem;">
        <div style="padding: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                <i data-feather="image" style="width: 18px; height: 18px; margin-right: 0.5rem;"></i>
                Logo Invoice/PDF
            </h3>
        </div>
        
        <div style="padding: 1rem;">
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 1.5rem; align-items: start;">
                <!-- Logo Preview -->
                <div style="text-align: center;">
                    <div style="width: 120px; height: 120px; border: 2px dashed var(--bg-tertiary); border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; background: var(--bg-primary); overflow: hidden;">
                        <?php 
                            $invoiceLogo = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = :key", 
                                ['key' => 'invoice_logo_' . ACTIVE_BUSINESS_ID]);
                            $invoiceLogoVal = $invoiceLogo ? $invoiceLogo['setting_value'] : '';
                            $isCloudUrl = ($invoiceLogoVal && strpos($invoiceLogoVal, 'http') === 0);
                            if ($invoiceLogo && ($isCloudUrl || file_exists(BASE_PATH . '/uploads/logos/' . $invoiceLogoVal))): 
                                $invoiceLogoSrc = $isCloudUrl ? $invoiceLogoVal : BASE_URL . '/uploads/logos/' . $invoiceLogoVal;
                        ?>
                            <img src="<?php echo htmlspecialchars($invoiceLogoSrc); ?>" 
                                 style="max-width: 100%; max-height: 100%; object-fit: contain;" alt="Invoice Logo">
                        <?php else: ?>
                            <div style="text-align: center; color: var(--text-muted);">
                                <i data-feather="image" style="width: 36px; height: 36px; margin-bottom: 0.25rem;"></i>
                                <p style="font-size: 0.75rem; margin: 0;">Belum ada logo</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($invoiceLogo): ?>
                        <button onclick="if(confirm('Hapus logo invoice?')) window.location.href='report-settings.php?delete_invoice_logo=1'" 
                                class="btn btn-danger btn-sm" style="margin-top: 0.5rem; width: 100%;">
                            <i data-feather="trash-2" style="width: 12px; height: 12px;"></i> Hapus
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Upload Form -->
                <div>
                    <div style="background: rgba(99, 102, 241, 0.1); border-left: 3px solid var(--primary-color); border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem;">
                        <div style="font-size: 0.813rem; color: var(--text-secondary);">
                            <strong>📌 Keterangan:</strong><br>
                            • Logo ini akan tampil di <strong>Invoice</strong> dan dokumen PDF lainnya<br>
                            • Jika tidak di-upload, akan menggunakan logo perusahaan default<br>
                            • Format: JPG, PNG, GIF • Maksimal: 2MB
                        </div>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <div style="display: flex; gap: 0.75rem; align-items: center;">
                            <input type="file" name="invoice_logo" id="invoiceLogoInput" accept="image/*" required 
                                   style="flex: 1; padding: 0.5rem; border: 1px solid var(--bg-tertiary); border-radius: 0.375rem;">
                            <button type="submit" class="btn btn-primary">
                                <i data-feather="upload" style="width: 14px; height: 14px;"></i> Upload Logo
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 1rem;">
        <!-- Settings Form -->
        <div class="card">
            <div style="padding: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
                <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                    <i data-feather="file-text" style="width: 18px; height: 18px; margin-right: 0.5rem;"></i>
                    Konfigurasi Header PDF
                </h3>
            </div>
            
            <form method="POST" style="padding: 1rem;">
                <div style="padding: 1rem; background: var(--bg-tertiary); border-radius: 0.5rem; margin-bottom: 1rem;">
                    <h4 style="font-size: 0.938rem; font-weight: 700; margin-bottom: 0.75rem; color: var(--text-primary);">
                        Elemen yang Ditampilkan
                    </h4>
                    
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <label style="display: flex; align-items: center; cursor: pointer; padding: 0.75rem; background: var(--bg-secondary); border-radius: 0.375rem;">
                            <input type="checkbox" name="report_show_logo" value="1" 
                                   <?php echo ($settings['report_show_logo'] ?? '1') == '1' ? 'checked' : ''; ?> 
                                   style="width: 18px; height: 18px; margin-right: 0.75rem;">
                            <div>
                                <div style="font-weight: 600; margin-bottom: 0.125rem;">Logo Perusahaan</div>
                                <div style="font-size: 0.813rem; color: var(--text-muted);">Tampilkan logo di header laporan</div>
                            </div>
                        </label>
                        
                        <label style="display: flex; align-items: center; cursor: pointer; padding: 0.75rem; background: var(--bg-secondary); border-radius: 0.375rem;">
                            <input type="checkbox" name="report_show_address" value="1" 
                                   <?php echo ($settings['report_show_address'] ?? '1') == '1' ? 'checked' : ''; ?> 
                                   style="width: 18px; height: 18px; margin-right: 0.75rem;">
                            <div>
                                <div style="font-weight: 600; margin-bottom: 0.125rem;">Alamat Perusahaan</div>
                                <div style="font-size: 0.813rem; color: var(--text-muted);">Tampilkan alamat lengkap</div>
                            </div>
                        </label>
                        
                        <label style="display: flex; align-items: center; cursor: pointer; padding: 0.75rem; background: var(--bg-secondary); border-radius: 0.375rem;">
                            <input type="checkbox" name="report_show_phone" value="1" 
                                   <?php echo ($settings['report_show_phone'] ?? '1') == '1' ? 'checked' : ''; ?> 
                                   style="width: 18px; height: 18px; margin-right: 0.75rem;">
                            <div>
                                <div style="font-weight: 600; margin-bottom: 0.125rem;">Nomor Telepon</div>
                                <div style="font-size: 0.813rem; color: var(--text-muted);">Tampilkan kontak telepon</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div style="padding: 1rem; background: rgba(59, 130, 246, 0.1); border-radius: 0.5rem; border-left: 3px solid #3b82f6;">
                    <div style="display: flex; gap: 0.5rem;">
                        <i data-feather="info" style="width: 18px; height: 18px; color: #3b82f6; flex-shrink: 0;"></i>
                        <div style="font-size: 0.813rem; color: var(--text-muted);">
                            <strong>Catatan:</strong> Data perusahaan (nama, logo, alamat, telepon) dapat diubah di menu 
                            <a href="company.php" style="color: var(--primary-color);">Pengaturan Perusahaan</a>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; gap: 0.5rem; padding-top: 0.75rem; border-top: 1px solid var(--bg-tertiary); margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">
                        <i data-feather="save" style="width: 14px; height: 14px;"></i> Simpan Pengaturan
                    </button>
                    <a href="index.php" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>

        <!-- PDF Preview -->
        <div class="card">
            <div style="padding: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
                <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">
                    <i data-feather="eye" style="width: 18px; height: 18px; margin-right: 0.5rem;"></i>
                    Preview Header PDF
                </h3>
            </div>
            
            <div style="padding: 1rem;">
                <!-- PDF Preview Container -->
                <div style="background: white; padding: 1.5rem; border-radius: 0.5rem; border: 1px solid #e2e8f0;">
                    <div style="display: flex; align-items: start; gap: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #333;">
                        <!-- Logo -->
                        <?php 
                        $invoiceLogo = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = :key", 
                            ['key' => 'invoice_logo_' . ACTIVE_BUSINESS_ID]);
                        $displayLogo = null;
                        
                        if ($invoiceLogo && !empty($invoiceLogo['setting_value'])) {
                            $val = $invoiceLogo['setting_value'];
                            $displayLogo = (strpos($val, 'http') === 0) ? $val : BASE_URL . '/uploads/logos/' . $val;
                        } elseif (!empty($settings['company_logo'])) {
                            $val = $settings['company_logo'];
                            $displayLogo = (strpos($val, 'http') === 0) ? $val : BASE_URL . '/uploads/logos/' . $val;
                        }
                        ?>
                        
                        <?php if (($settings['report_show_logo'] ?? '1') == '1'): ?>
                            <div style="flex-shrink: 0;">
                                <?php if ($displayLogo): ?>
                                    <img src="<?php echo $displayLogo; ?>" 
                                         style="height: 60px; width: auto; object-fit: contain;" alt="Logo">
                                <?php else: ?>
                                    <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 0.375rem; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.5rem;">
                                        <?php echo substr($businessName, 0, 1); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Company Info -->
                        <div style="flex: 1;">
                            <h2 style="font-size: 1.25rem; font-weight: 700; color: #1e293b; margin: 0 0 0.25rem 0;">
                                <?php echo htmlspecialchars($businessName); ?>
                            </h2>
                            <?php if (!empty($businessTagline)): ?>
                                <p style="font-size: 0.813rem; color: #64748b; margin: 0 0 0.5rem 0;">
                                    <?php echo htmlspecialchars($businessTagline); ?>
                                </p>
                            <?php endif; ?>
                            
                            <?php if (($settings['report_show_address'] ?? '1') == '1' && !empty($businessAddress)): ?>
                                <p style="font-size: 0.813rem; color: #475569; margin: 0;">
                                    <strong>Alamat:</strong> <?php echo htmlspecialchars($businessAddress); ?>
                                </p>
                            <?php endif; ?>
                            
                            <?php if (($settings['report_show_phone'] ?? '1') == '1' && (!empty($businessPhone) || !empty($businessEmail))): ?>
                                <p style="font-size: 0.813rem; color: #475569; margin: 0.125rem 0 0 0;">
                                    <?php if (!empty($businessPhone)): ?>
                                        <strong>Telepon:</strong> <?php echo htmlspecialchars($businessPhone); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($businessEmail)): ?>
                                        <?php if (!empty($businessPhone)): ?>| <?php endif; ?><strong>Email:</strong> <?php echo htmlspecialchars($businessEmail); ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Report Title Sample -->
                    <div style="margin-top: 1rem;">
                        <h3 style="font-size: 1rem; font-weight: 700; color: #1e293b; text-align: center; margin: 0;">
                            LAPORAN KEUANGAN HARIAN
                        </h3>
                        <p style="font-size: 0.813rem; color: #64748b; text-align: center; margin: 0.25rem 0 0 0;">
                            Tanggal: <?php echo date('d/m/Y'); ?>
                        </p>
                    </div>
                    
                    <!-- Sample Data Table -->
                    <div style="margin-top: 1rem; font-size: 0.75rem;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 1px solid #cbd5e1;">
                                    <th style="padding: 0.5rem; text-align: left; color: #475569;">Tanggal</th>
                                    <th style="padding: 0.5rem; text-align: left; color: #475569;">Keterangan</th>
                                    <th style="padding: 0.5rem; text-align: right; color: #475569;">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 0.5rem; color: #64748b;">01/01/2024</td>
                                    <td style="padding: 0.5rem; color: #334155;">Pendapatan Room</td>
                                    <td style="padding: 0.5rem; text-align: right; color: #059669;">Rp 2,500,000</td>
                                </tr>
                                <tr style="border-bottom: 1px solid #e2e8f0;">
                                    <td style="padding: 0.5rem; color: #64748b;">01/01/2024</td>
                                    <td style="padding: 0.5rem; color: #334155;">Biaya Operasional</td>
                                    <td style="padding: 0.5rem; text-align: right; color: #dc2626;">Rp 800,000</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div style="margin-top: 0.75rem; padding: 0.75rem; background: var(--bg-tertiary); border-radius: 0.375rem;">
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-align: center;">
                        <i data-feather="info" style="width: 12px; height: 12px;"></i>
                        Preview ini menampilkan tampilan header yang akan muncul di laporan PDF
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
