<?php
/**
 * Developer Panel - Database Management
 * Create, backup, and manage databases
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once dirname(dirname(__FILE__)) . '/includes/DatabaseManager.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Database Management';

$error = '';
$success = '';
$output = '';

// Get all business databases
$databases = [];
try {
    $stmt = $pdo->query("
        SELECT b.*, 
               (SELECT COUNT(*) FROM user_business_assignment WHERE business_id = b.id) as users_count
        FROM businesses b
        ORDER BY b.business_name
    ");
    $databases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $dbName = $_POST['database_name'] ?? '';
    
    try {
        $dbMgr = new DatabaseManager();
        
        switch ($action) {
            case 'init_master':
                $result = $dbMgr->initializeMasterDatabase();
                if ($result) {
                    $success = 'Master database (adf_system) initialized successfully!';
                    $auth->logAction('init_master_db', 'database', null);
                }
                break;
                
            case 'backup':
                if ($dbName) {
                    $backupPath = $dbMgr->backupDatabase($dbName);
                    if ($backupPath) {
                        $success = "Backup created: " . basename($backupPath);
                        $auth->logAction('backup_database', 'database', null, null, ['database' => $dbName, 'file' => $backupPath]);
                    }
                }
                break;
                
            case 'check_tables':
                if ($dbName) {
                    // Get table info
                    $configPdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
                    $configPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $configPdo->exec("USE `$dbName`");
                    
                    $tables = $configPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                    $output = "<strong>Tables in $dbName:</strong><br><ul>";
                    foreach ($tables as $table) {
                        $count = $configPdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                        $output .= "<li><code>$table</code> - $count rows</li>";
                    }
                    $output .= "</ul>";
                }
                break;
                
            case 'reset_business':
                if ($dbName && isset($_POST['confirm_reset'])) {
                    // Recreate business database
                    $dbMgr->createBusinessDatabase($dbName, true);
                    $success = "Database '$dbName' has been reset with fresh template!";
                    $auth->logAction('reset_database', 'database', null, null, ['database' => $dbName]);
                }
                break;
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Get backups
$backups = [];
$backupDir = dirname(dirname(__FILE__)) . '/database/backups';
if (is_dir($backupDir)) {
    $files = glob($backupDir . '/*.sql');
    foreach ($files as $file) {
        $backups[] = [
            'name' => basename($file),
            'size' => filesize($file),
            'date' => filemtime($file)
        ];
    }
    usort($backups, fn($a, $b) => $b['date'] - $a['date']);
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h4 class="mb-0"><i class="bi bi-database me-2"></i>Database Management</h4>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($output): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?php echo $output; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Quick Actions -->
        <div class="col-lg-4 mb-4">
            <div class="content-card h-100">
                <div class="card-header-custom">
                    <h5><i class="bi bi-lightning me-2"></i>Quick Actions</h5>
                </div>
                <div class="p-4">
                    <form method="POST" class="mb-3">
                        <input type="hidden" name="action" value="init_master">
                        <button type="submit" class="btn btn-outline-primary w-100 mb-2" 
                                onclick="return confirm('This will initialize/reset the master database. Continue?')">
                            <i class="bi bi-database-add me-2"></i>Initialize Master DB
                        </button>
                    </form>
                    
                    <hr>
                    
                    <div class="alert alert-warning small">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Master Database:</strong> adf_system<br>
                        Contains: users, roles, businesses, menus, permissions, audit logs
                    </div>
                    
                    <p class="small text-muted mb-0">
                        Business databases are created automatically when you add a new business via the Businesses page.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Business Databases -->
        <div class="col-lg-8 mb-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-stack me-2"></i>Business Databases</h5>
                    <span class="badge bg-primary"><?php echo count($databases); ?> databases</span>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Business</th>
                                <th>Database</th>
                                <th>Users</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($databases)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    No business databases yet
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($databases as $db): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($db['business_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo $db['business_code']; ?></small>
                                </td>
                                <td><code><?php echo htmlspecialchars($db['database_name']); ?></code></td>
                                <td><span class="badge bg-secondary"><?php echo $db['users_count']; ?></span></td>
                                <td>
                                    <?php if ($db['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="check_tables">
                                        <input type="hidden" name="database_name" value="<?php echo htmlspecialchars($db['database_name']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-info" title="Check Tables">
                                            <i class="bi bi-table"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="backup">
                                        <input type="hidden" name="database_name" value="<?php echo htmlspecialchars($db['database_name']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Backup">
                                            <i class="bi bi-download"></i>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Reset Database"
                                            onclick="showResetModal('<?php echo htmlspecialchars($db['database_name']); ?>', '<?php echo addslashes($db['business_name']); ?>')">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Backups -->
    <div class="row">
        <div class="col-12">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-archive me-2"></i>Recent Backups</h5>
                </div>
                
                <?php if (empty($backups)): ?>
                <div class="text-center py-4 text-muted">
                    No backups found
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Size</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($backups, 0, 10) as $backup): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($backup['name']); ?></code></td>
                                <td><?php echo number_format($backup['size'] / 1024, 2); ?> KB</td>
                                <td><?php echo date('d M Y H:i', $backup['date']); ?></td>
                                <td>
                                    <a href="../database/backups/<?php echo urlencode($backup['name']); ?>" 
                                       class="btn btn-sm btn-outline-primary" download>
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Reset Database Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Reset Database</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reset_business">
                    <input type="hidden" name="database_name" id="reset_db_name">
                    
                    <div class="alert alert-danger">
                        <strong>Warning!</strong> This will delete ALL data in the database <code id="reset_db_display"></code> 
                        for business <span id="reset_biz_name"></span> and recreate it with fresh tables.
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="confirm_reset" id="confirm_reset" required>
                        <label class="form-check-label" for="confirm_reset">
                            I understand this will permanently delete all data
                        </label>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Database
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showResetModal(dbName, bizName) {
    document.getElementById('reset_db_name').value = dbName;
    document.getElementById('reset_db_display').textContent = dbName;
    document.getElementById('reset_biz_name').textContent = bizName;
    document.getElementById('confirm_reset').checked = false;
    new bootstrap.Modal(document.getElementById('resetModal')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
