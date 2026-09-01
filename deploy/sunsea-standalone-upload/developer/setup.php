<?php
/**
 * Developer Panel - Quick Setup
 * Initialize master database with one click
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';

$setupComplete = false;
$errors = [];
$success = [];

// Handle setup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_setup'])) {
    try {
        // Connect without database
        $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create master database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $success[] = "✅ Database '" . DB_NAME . "' created/verified";
        
        // Use the database
        $pdo->exec("USE `" . DB_NAME . "`");
        
        // Read and execute schema
        $schemaFile = dirname(dirname(__FILE__)) . '/database/adf_system_master.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            
            // Remove CREATE DATABASE and USE statements (we handle them above)
            $sql = preg_replace('/CREATE DATABASE.*?;/is', '', $sql);
            $sql = preg_replace('/USE.*?;/is', '', $sql);
            
            // Execute each statement
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if (!empty($stmt) && strlen($stmt) > 5) {
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $e) {
                        // Ignore duplicate key errors for INSERT IGNORE
                        if (strpos($e->getMessage(), 'Duplicate') === false) {
                            // Log but continue for minor errors
                        }
                    }
                }
            }
            $success[] = "✅ Schema tables created";
        } else {
            $errors[] = "❌ Schema file not found: $schemaFile";
        }
        
        // Verify tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $requiredTables = ['roles', 'users', 'businesses', 'menu_items', 'settings'];
        $missingTables = array_diff($requiredTables, $tables);
        
        if (empty($missingTables)) {
            $success[] = "✅ All required tables exist: " . implode(', ', $requiredTables);
        } else {
            $errors[] = "❌ Missing tables: " . implode(', ', $missingTables);
        }
        
        // Check if developer user exists
        $devUser = $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'developer'")->fetchColumn();
        if ($devUser > 0) {
            $success[] = "✅ Developer user exists";
        } else {
            $errors[] = "❌ Developer user not created";
        }
        
        if (empty($errors)) {
            $setupComplete = true;
        }
        
    } catch (PDOException $e) {
        $errors[] = "❌ Database error: " . $e->getMessage();
    }
}

// Check current status
$dbStatus = 'unknown';
$tableCount = 0;
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tableCount = $pdo->query("SHOW TABLES")->rowCount();
    $dbStatus = 'connected';
} catch (PDOException $e) {
    $dbStatus = 'not_exists';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Setup - ADF System Developer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .setup-card {
            background: rgba(30, 30, 60, 0.9);
            border: 1px solid rgba(111, 66, 193, 0.3);
            border-radius: 16px;
            backdrop-filter: blur(10px);
        }
        .text-purple { color: #a78bfa; }
        .btn-purple {
            background: linear-gradient(135deg, #6f42c1, #8b5cf6);
            border: none;
            color: white;
        }
        .btn-purple:hover {
            background: linear-gradient(135deg, #5a32a3, #7c4ddb);
            color: white;
        }
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 500;
        }
        .status-connected { background: rgba(34, 197, 94, 0.2); color: #4ade80; }
        .status-missing { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .log-item { padding: 8px 12px; margin-bottom: 4px; border-radius: 6px; }
        .log-success { background: rgba(34, 197, 94, 0.1); color: #4ade80; }
        .log-error { background: rgba(239, 68, 68, 0.1); color: #f87171; }
    </style>
</head>
<body class="d-flex align-items-center py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-purple rounded-circle mb-3" style="width:80px;height:80px;background:linear-gradient(135deg,#6f42c1,#8b5cf6)">
                        <i class="bi bi-database-gear text-white fs-2"></i>
                    </div>
                    <h2 class="text-white mb-2">ADF System Quick Setup</h2>
                    <p class="text-muted">Initialize master database untuk Developer Panel</p>
                </div>
                
                <div class="setup-card p-4">
                    <!-- Current Status -->
                    <div class="mb-4">
                        <h6 class="text-purple mb-3"><i class="bi bi-info-circle me-2"></i>Current Status</h6>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-light">Database Host:</span>
                            <code><?php echo DB_HOST; ?></code>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-light">Database Name:</span>
                            <code><?php echo DB_NAME; ?></code>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-light">Connection:</span>
                            <?php if ($dbStatus === 'connected'): ?>
                            <span class="status-badge status-connected"><i class="bi bi-check-circle me-1"></i>Connected</span>
                            <?php else: ?>
                            <span class="status-badge status-missing"><i class="bi bi-x-circle me-1"></i>Not Found</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($dbStatus === 'connected'): ?>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-light">Tables:</span>
                            <span class="text-info"><?php echo $tableCount; ?> tables</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($setupComplete): ?>
                    <!-- Setup Complete -->
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <strong>Setup Complete!</strong>
                    </div>
                    
                    <?php foreach ($success as $msg): ?>
                    <div class="log-item log-success"><?php echo $msg; ?></div>
                    <?php endforeach; ?>
                    
                    <div class="mt-4 text-center">
                        <a href="login.php" class="btn btn-purple btn-lg px-5">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                        </a>
                        <p class="text-muted mt-3 small">
                            Default login: <code>developer</code> / <code>developer123</code>
                        </p>
                    </div>
                    
                    <?php elseif (!empty($errors)): ?>
                    <!-- Errors -->
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Setup encountered errors</strong>
                    </div>
                    
                    <?php foreach ($success as $msg): ?>
                    <div class="log-item log-success"><?php echo $msg; ?></div>
                    <?php endforeach; ?>
                    
                    <?php foreach ($errors as $msg): ?>
                    <div class="log-item log-error"><?php echo $msg; ?></div>
                    <?php endforeach; ?>
                    
                    <div class="mt-4">
                        <form method="POST">
                            <button type="submit" name="do_setup" class="btn btn-purple w-100">
                                <i class="bi bi-arrow-repeat me-2"></i>Try Again
                            </button>
                        </form>
                    </div>
                    
                    <?php else: ?>
                    <!-- Run Setup -->
                    <div class="alert alert-info border-0" style="background:rgba(59,130,246,0.1)">
                        <i class="bi bi-lightbulb me-2"></i>
                        Click tombol di bawah untuk membuat database master dan tabel yang diperlukan.
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="text-purple mb-3"><i class="bi bi-list-check me-2"></i>Will Create:</h6>
                        <ul class="text-light small">
                            <li>Database: <code><?php echo DB_NAME; ?></code></li>
                            <li>Tables: roles, users, businesses, menu_items, settings, audit_logs, dll</li>
                            <li>Default developer user</li>
                            <li>Default menu items</li>
                        </ul>
                    </div>
                    
                    <form method="POST">
                        <button type="submit" name="do_setup" class="btn btn-purple btn-lg w-100">
                            <i class="bi bi-play-fill me-2"></i>Run Setup
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                
                <div class="text-center mt-4">
                    <small class="text-muted">
                        ADF System v<?php echo APP_VERSION; ?> &copy; <?php echo APP_YEAR; ?> <?php echo DEVELOPER_NAME; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
