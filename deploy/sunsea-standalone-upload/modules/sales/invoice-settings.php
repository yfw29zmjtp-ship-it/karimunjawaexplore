<?php
/**
 * CQC Invoice Settings - Compact Version
 * Setup company info, logo, and bank details for invoices
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/CloudinaryHelper.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$businessId = 7; // CQC business ID

// Define root path safely
$rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(dirname(__DIR__));

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Handle logo upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $fileInfo = pathinfo($_FILES['logo']['name']);
            $extension = strtolower($fileInfo['extension']);
            
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
                throw new Exception('Invalid file type. Only images allowed.');
            }
            
            $newFilename = 'cqc-logo-' . time() . '.' . $extension;
            $cloudinary = CloudinaryHelper::getInstance();
            $uploadResult = $cloudinary->smartUpload($_FILES['logo'], 'uploads/logos', $newFilename, 'logos', 'cqc_invoice_logo');
            if ($uploadResult['success']) {
                $storedPath = $uploadResult['is_cloud'] ? $uploadResult['path'] : 'logos/' . $newFilename;
                saveInvoiceSetting($db, $businessId, 'logo', $storedPath);
            }
        }
        
        // Save other settings
        $fields = ['business_name', 'tagline', 'address', 'city', 'phone', 'email', 'npwp', 'bank_name', 'bank_account', 'bank_holder'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                saveInvoiceSetting($db, $businessId, $field, trim($_POST[$field]));
            }
        }
        
        $message = 'Settings saved successfully!';
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Helper function to save settings
function saveInvoiceSetting($db, $businessId, $key, $value) {
    $existing = $db->fetchOne("SELECT id FROM business_settings WHERE business_id = ? AND setting_key = ?", [$businessId, $key]);
    if ($existing) {
        $db->query("UPDATE business_settings SET setting_value = ?, updated_at = NOW() WHERE business_id = ? AND setting_key = ?", [$value, $businessId, $key]);
    } else {
        $db->query("INSERT INTO business_settings (business_id, setting_key, setting_value, created_at) VALUES (?, ?, ?, NOW())", [$businessId, $key, $value]);
    }
}

// Load current settings
$settings = [];
try {
    $rows = $db->fetchAll("SELECT setting_key, setting_value FROM business_settings WHERE business_id = ?", [$businessId]);
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $db->query("CREATE TABLE IF NOT EXISTS business_settings (id INT AUTO_INCREMENT PRIMARY KEY, business_id INT NOT NULL, setting_key VARCHAR(100) NOT NULL, setting_value TEXT, created_at DATETIME, updated_at DATETIME, UNIQUE KEY unique_setting (business_id, setting_key))");
}

// Default values
$defaults = ['business_name' => 'CQC Enjiniring', 'tagline' => 'Solar Panel Installation Contractor', 'address' => '', 'city' => '', 'phone' => '', 'email' => '', 'npwp' => '', 'logo' => '', 'bank_name' => '', 'bank_account' => '', 'bank_holder' => ''];
foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) $settings[$key] = $value;
}

// Check if logo exists
$logoExists = false;
$logoPath = '';
if (!empty($settings['logo'])) {
    if (strpos($settings['logo'], 'http') === 0) {
        $logoExists = true;
        $logoPath = $settings['logo'];
    } else {
        $fullPath = $rootPath . '/uploads/' . $settings['logo'];
        if (file_exists($fullPath)) {
            $logoExists = true;
            $logoPath = BASE_URL . '/uploads/' . $settings['logo'];
        }
    }
}

$pageTitle = "Invoice Settings";
include '../../includes/header.php';
?>

<style>
    :root { --navy: #0d1f3c; --gold: #f0b429; }
    .set-wrap { max-width: 700px; margin: 0 auto; padding: 15px; }
    .set-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid var(--navy); }
    .set-head h1 { font-size: 20px; color: var(--navy); }
    .set-head p { font-size: 11px; color: #64748b; margin-top: 2px; }
    .btn-back { background: #f1f5f9; color: #475569; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; }
    .btn-back:hover { background: #e2e8f0; }
    
    .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 15px; overflow: hidden; }
    .card-head { background: linear-gradient(135deg, var(--navy), #1a3a5c); color: #fff; padding: 10px 16px; font-size: 12px; font-weight: 700; }
    .card-body { padding: 16px; }
    
    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .form-group { margin-bottom: 0; }
    .form-group.full { grid-column: span 2; }
    .form-group label { display: block; font-size: 10px; font-weight: 600; color: #475569; margin-bottom: 4px; text-transform: uppercase; }
    .form-group input, .form-group textarea { width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 2px rgba(240,180,41,0.15); }
    .form-group textarea { resize: vertical; min-height: 50px; }
    
    .logo-row { display: flex; gap: 16px; align-items: flex-start; }
    .logo-preview { width: 80px; height: 80px; border: 2px dashed #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #f8fafc; overflow: hidden; flex-shrink: 0; }
    .logo-preview img { max-width: 70px; max-height: 70px; object-fit: contain; }
    .logo-preview .no-logo { font-size: 9px; color: #94a3b8; text-align: center; }
    .upload-area { flex: 1; }
    .upload-box { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 14px; text-align: center; background: #f8fafc; cursor: pointer; transition: all 0.2s; }
    .upload-box:hover { border-color: var(--gold); }
    .upload-box input[type="file"] { display: none; }
    .upload-box .icon { font-size: 20px; margin-bottom: 4px; }
    .upload-box .text { font-size: 11px; color: #64748b; }
    .upload-box .text strong { color: var(--gold); }
    
    .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 15px; font-size: 12px; font-weight: 500; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    
    .form-actions { display: flex; justify-content: flex-end; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0; }
    .btn-save { background: linear-gradient(135deg, var(--gold), #d4960d); color: var(--navy); padding: 10px 24px; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    .btn-save:hover { transform: translateY(-1px); box-shadow: 0 4px 15px rgba(240,180,41,0.3); }
</style>

<div class="set-wrap">
    <div class="set-head">
        <div>
            <h1>⚙️ Invoice Settings</h1>
            <p>Configure company information for invoices</p>
        </div>
        <a href="index-cqc.php" class="btn-back">← Back</a>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data">
        <!-- Logo -->
        <div class="card">
            <div class="card-head">🏢 Company Logo</div>
            <div class="card-body">
                <div class="logo-row">
                    <div class="logo-preview">
                        <?php if ($logoExists): ?>
                            <img src="<?php echo $logoPath; ?>" alt="Logo" id="logoPreviewImg">
                        <?php else: ?>
                            <div class="no-logo" id="noLogoText">📷<br>No Logo</div>
                            <img src="" alt="" id="logoPreviewImg" style="display: none;">
                        <?php endif; ?>
                    </div>
                    <div class="upload-area">
                        <label class="upload-box" for="logoInput">
                            <div class="icon">📤</div>
                            <div class="text"><strong>Click to upload</strong> logo (PNG, JPG, max 2MB)</div>
                            <input type="file" name="logo" id="logoInput" accept="image/*">
                        </label>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Company Info -->
        <div class="card">
            <div class="card-head">📋 Company Information</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Company Name *</label>
                        <input type="text" name="business_name" value="<?php echo htmlspecialchars($settings['business_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tagline</label>
                        <input type="text" name="tagline" value="<?php echo htmlspecialchars($settings['tagline']); ?>" placeholder="e.g. Solar Panel Contractor">
                    </div>
                    <div class="form-group full">
                        <label>Address *</label>
                        <textarea name="address" placeholder="Full address"><?php echo htmlspecialchars($settings['address']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="city" value="<?php echo htmlspecialchars($settings['city']); ?>" placeholder="Jakarta">
                    </div>
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($settings['phone']); ?>" placeholder="+62 21 1234567">
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($settings['email']); ?>" placeholder="info@company.com">
                    </div>
                    <div class="form-group">
                        <label>NPWP</label>
                        <input type="text" name="npwp" value="<?php echo htmlspecialchars($settings['npwp']); ?>" placeholder="00.000.000.0-000.000">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Bank Info -->
        <div class="card">
            <div class="card-head">🏦 Bank Information</div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" value="<?php echo htmlspecialchars($settings['bank_name']); ?>" placeholder="Bank Mandiri">
                    </div>
                    <div class="form-group">
                        <label>Account Number</label>
                        <input type="text" name="bank_account" value="<?php echo htmlspecialchars($settings['bank_account']); ?>" placeholder="1234567890">
                    </div>
                    <div class="form-group full">
                        <label>Account Holder</label>
                        <input type="text" name="bank_holder" value="<?php echo htmlspecialchars($settings['bank_holder']); ?>" placeholder="PT Company Name">
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn-save">💾 Save Settings</button>
        </div>
    </form>
</div>

<script>
document.getElementById('logoInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.getElementById('logoPreviewImg');
            const noLogo = document.getElementById('noLogoText');
            img.src = e.target.result;
            img.style.display = 'block';
            if (noLogo) noLogo.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
