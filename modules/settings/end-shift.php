<?php
/**
 * End Shift Settings - Configure WhatsApp Number and Admin Contact
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Only admin can modify settings
if (!$auth->hasPermission('settings')) {
    header('HTTP/1.0 403 Forbidden');
    die('Access denied');
}

$db = Database::getInstance();
$pageTitle = 'End Shift Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $whatsappNumber = sanitizeInput($_POST['whatsapp_number'] ?? '');
    $adminPhone = sanitizeInput($_POST['admin_phone'] ?? '');
    $adminEmail = sanitizeInput($_POST['admin_email'] ?? '');
    
    try {
        // Update business settings
        $settings = $db->fetchOne(
            "SELECT id FROM business_settings WHERE business_id = ?",
            [ACTIVE_BUSINESS_ID]
        );
        
        if ($settings) {
            $db->execute(
                "UPDATE business_settings SET whatsapp_number = ? WHERE business_id = ?",
                [$whatsappNumber, ACTIVE_BUSINESS_ID]
            );
        } else {
            $db->execute(
                "INSERT INTO business_settings (business_id, whatsapp_number) VALUES (?, ?)",
                [ACTIVE_BUSINESS_ID, $whatsappNumber]
            );
        }
        
        // Update admin user contact if provided
        if ($adminPhone || $adminEmail) {
            $adminUser = $db->fetchOne(
                "SELECT id FROM users WHERE role IN ('admin', 'gm') AND business_id = ? LIMIT 1",
                [ACTIVE_BUSINESS_ID]
            );
            
            if ($adminUser) {
                $updateData = [];
                $updateValues = [];
                
                if ($adminPhone) {
                    $updateData[] = "phone = ?";
                    $updateValues[] = $adminPhone;
                }
                if ($adminEmail) {
                    $updateData[] = "email = ?";
                    $updateValues[] = $adminEmail;
                }
                
                if (!empty($updateData)) {
                    $updateValues[] = $adminUser['id'];
                    $query = "UPDATE users SET " . implode(', ', $updateData) . " WHERE id = ?";
                    $db->execute($query, $updateValues);
                }
            }
        }
        
        setFlash('success', 'End Shift settings updated successfully!');
        redirect(BASE_URL . '/modules/settings/end-shift.php');
    } catch (Exception $e) {
        setFlash('error', 'Error updating settings: ' . $e->getMessage());
    }
}

// Get current settings
$settings = $db->fetchOne(
    "SELECT * FROM business_settings WHERE business_id = ?",
    [ACTIVE_BUSINESS_ID]
);

$adminUser = $db->fetchOne(
    "SELECT id, phone, email FROM users WHERE role IN ('admin', 'gm') AND business_id = ? LIMIT 1",
    [ACTIVE_BUSINESS_ID]
);

include '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div style="padding: 2rem; border-bottom: 1px solid var(--bg-tertiary);">
        <h2 style="margin: 0; color: var(--text-primary);">🌅 End Shift Configuration</h2>
        <p style="margin: 0.5rem 0 0; color: var(--text-muted); font-size: 0.875rem;">
            Configure WhatsApp and admin contact information for End Shift reports
        </p>
    </div>

    <form method="POST" style="padding: 2rem;">
        <!-- WhatsApp Number Section -->
        <div style="margin-bottom: 2rem;">
            <label style="display: block; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                📱 WhatsApp Number (with country code)
            </label>
            <input type="text" 
                   name="whatsapp_number" 
                   placeholder="+62812345678" 
                   value="<?php echo htmlspecialchars($settings['whatsapp_number'] ?? ''); ?>"
                   style="width: 100%; padding: 0.75rem; border: 1px solid var(--bg-tertiary); border-radius: 6px; background: var(--bg-secondary); color: var(--text-primary); font-family: monospace;">
            <small style="display: block; margin-top: 0.5rem; color: var(--text-muted);">
                Format: +62812345678 or 62812345678
            </small>
        </div>

        <!-- Admin Phone Section -->
        <div style="margin-bottom: 2rem;">
            <label style="display: block; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                ☎️ Admin/GM Phone Number
            </label>
            <input type="tel" 
                   name="admin_phone" 
                   placeholder="+62812345678" 
                   value="<?php echo htmlspecialchars($adminUser['phone'] ?? ''); ?>"
                   style="width: 100%; padding: 0.75rem; border: 1px solid var(--bg-tertiary); border-radius: 6px; background: var(--bg-secondary); color: var(--text-primary);">
            <small style="display: block; margin-top: 0.5rem; color: var(--text-muted);">
                Phone number of Admin/GM for receiving End Shift reports
            </small>
        </div>

        <!-- Admin Email Section -->
        <div style="margin-bottom: 2rem;">
            <label style="display: block; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                ✉️ Admin/GM Email
            </label>
            <input type="email" 
                   name="admin_email" 
                   placeholder="admin@example.com" 
                   value="<?php echo htmlspecialchars($adminUser['email'] ?? ''); ?>"
                   style="width: 100%; padding: 0.75rem; border: 1px solid var(--bg-tertiary); border-radius: 6px; background: var(--bg-secondary); color: var(--text-primary);">
            <small style="display: block; margin-top: 0.5rem; color: var(--text-muted);">
                Email of Admin/GM for alternative communication
            </small>
        </div>

        <!-- Info Box -->
        <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px; padding: 1rem; margin-bottom: 2rem;">
            <p style="margin: 0; color: var(--text-primary); font-size: 0.875rem;">
                <strong>ℹ️ How it works:</strong><br>
                <br>
                1. Staff clicks "End Shift" button<br>
                2. System displays daily transaction report<br>
                3. Shows all PO images created today<br>
                4. Staff can send report to WhatsApp instantly<br>
                5. Report includes income, expense, and balance summary
            </p>
        </div>

        <!-- Buttons -->
        <div style="display: flex; gap: 1rem;">
            <button type="submit" 
                    style="flex: 1; padding: 0.75rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">
                💾 Save Settings
            </button>
            <a href="<?php echo BASE_URL; ?>/modules/settings/" 
               style="flex: 1; padding: 0.75rem; background: var(--bg-tertiary); color: var(--text-primary); border: 1px solid var(--bg-quaternary); border-radius: 6px; font-weight: 600; text-align: center; text-decoration: none;">
                Cancel
            </a>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
