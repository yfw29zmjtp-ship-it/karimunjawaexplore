<?php
/**
 * Developer Panel - System Settings
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'System Settings';

$error = '';
$success = '';

// Get current settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM settings ORDER BY setting_group, setting_key");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_group']][$row['setting_key']] = $row;
    }
} catch (Exception $e) {}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updates = $_POST['settings'] ?? [];
    
    try {
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE id = ?");
        
        foreach ($updates as $id => $value) {
            $stmt->execute([$value, $id]);
        }
        
        $auth->logAction('update_settings', 'settings', null);
        $_SESSION['success_message'] = 'Settings updated successfully!';
        header('Location: settings.php');
        exit;
    } catch (Exception $e) {
        $error = 'Failed to update: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h4 class="mb-0"><i class="bi bi-sliders me-2"></i>System Settings</h4>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <?php if (empty($settings)): ?>
        <div class="content-card">
            <div class="text-center py-5 text-muted">
                <i class="bi bi-sliders fs-1 d-block mb-2"></i>
                No settings configured yet.<br>
                <small>Settings will be added when you initialize the master database.</small>
            </div>
        </div>
        <?php else: ?>
        
        <?php foreach ($settings as $group => $groupSettings): ?>
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h5><i class="bi bi-gear me-2"></i><?php echo ucfirst($group); ?> Settings</h5>
            </div>
            <div class="p-4">
                <div class="row">
                    <?php foreach ($groupSettings as $key => $setting): ?>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">
                            <?php echo htmlspecialchars($setting['setting_key']); ?>
                            <?php if ($setting['description']): ?>
                            <i class="bi bi-info-circle text-muted" title="<?php echo htmlspecialchars($setting['description']); ?>"></i>
                            <?php endif; ?>
                        </label>
                        
                        <?php if ($setting['setting_type'] === 'boolean'): ?>
                        <select class="form-select" name="settings[<?php echo $setting['id']; ?>]">
                            <option value="true" <?php echo $setting['setting_value'] === 'true' ? 'selected' : ''; ?>>Yes</option>
                            <option value="false" <?php echo $setting['setting_value'] === 'false' ? 'selected' : ''; ?>>No</option>
                        </select>
                        <?php elseif ($setting['setting_type'] === 'number'): ?>
                        <input type="number" class="form-control" name="settings[<?php echo $setting['id']; ?>]"
                               value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                        <?php elseif ($setting['setting_type'] === 'json'): ?>
                        <textarea class="form-control font-monospace" name="settings[<?php echo $setting['id']; ?>]"
                                  rows="3"><?php echo htmlspecialchars($setting['setting_value']); ?></textarea>
                        <?php else: ?>
                        <input type="text" class="form-control" name="settings[<?php echo $setting['id']; ?>]"
                               value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Save Settings
            </button>
            <button type="reset" class="btn btn-outline-secondary">Reset</button>
        </div>
        
        <?php endif; ?>
    </form>
    
    <!-- System Info -->
    <div class="content-card mt-4">
        <div class="card-header-custom">
            <h5><i class="bi bi-info-circle me-2"></i>System Information</h5>
        </div>
        <div class="p-4">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><td class="text-muted">PHP Version</td><td><?php echo PHP_VERSION; ?></td></tr>
                        <tr><td class="text-muted">MySQL Version</td><td><?php echo $pdo->getAttribute(PDO::ATTR_SERVER_VERSION); ?></td></tr>
                        <tr><td class="text-muted">Server Software</td><td><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'; ?></td></tr>
                        <tr><td class="text-muted">Document Root</td><td><?php echo $_SERVER['DOCUMENT_ROOT']; ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><td class="text-muted">Master Database</td><td><code>adf_system</code></td></tr>
                        <tr><td class="text-muted">Database Host</td><td><?php echo DB_HOST; ?></td></tr>
                        <tr><td class="text-muted">Current Time</td><td><?php echo date('Y-m-d H:i:s'); ?></td></tr>
                        <tr><td class="text-muted">Timezone</td><td><?php echo date_default_timezone_get(); ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
