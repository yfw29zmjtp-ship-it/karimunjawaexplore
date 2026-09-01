<?php
/**
 * Cloudbed PMS Integration Settings & Management
 * Interface untuk mengatur integrasi dengan Cloudbed Property Management System
 */

require_once '../../config/config.php';
require_once '../../includes/CloudbedPMS.php';

// Check if user is logged in
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: /adf_system/login.php');
    exit;
}

$cloudbed = new CloudbedPMS();
$db = Database::getInstance();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_settings':
            $settings = [
                'cloudbed_client_id' => $_POST['client_id'] ?? '',
                'cloudbed_client_secret' => $_POST['client_secret'] ?? '',
                'cloudbed_property_id' => $_POST['property_id'] ?? '',
                'cloudbed_active' => isset($_POST['active']) ? '1' : '0'
            ];
            
            foreach ($settings as $key => $value) {
                $db->query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?", 
                           [$key, $value, $value]);
            }
            
            $success_message = "Cloudbed PMS settings saved successfully!";
            break;
            
        case 'test_connection':
            $testResult = $cloudbed->testConnection();
            break;
            
        case 'sync_reservations':
            $startDate = $_POST['sync_start_date'] ?? date('Y-m-d');
            $endDate = $_POST['sync_end_date'] ?? date('Y-m-d', strtotime('+30 days'));
            $syncResult = $cloudbed->syncReservationsFromCloudbed($startDate, $endDate);
            break;
    }
}

// Get current settings
$settings = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'cloudbed_%'");
$currentSettings = [];
foreach ($settings as $setting) {
    $currentSettings[$setting['setting_key']] = $setting['setting_value'];
}

// Get sync statistics
$syncStats = $cloudbed->getSyncStats();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloudbed PMS Integration - ADF Hotel System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .feature-card {
            transition: transform 0.2s;
            margin-bottom: 20px;
        }
        .feature-card:hover {
            transform: translateY(-2px);
        }
        .sync-stats {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-connected {
            background-color: #28a745;
        }
        .status-disconnected {
            background-color: #dc3545;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../../">
                <i class="fas fa-hotel"></i> ADF Hotel System
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../../">Dashboard</a>
                <a class="nav-link" href="../">Settings</a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1><i class="fas fa-cloud"></i> Cloudbed PMS Integration</h1>
                        <p class="text-muted">Sinkronisasi data hotel dengan Cloudbed Property Management System</p>
                    </div>
                    <div>
                        <span class="status-indicator <?php echo $cloudbed->isAvailable() ? 'status-connected' : 'status-disconnected'; ?>"></span>
                        <span class="ms-2"><?php echo $cloudbed->isAvailable() ? 'Connected' : 'Not Connected'; ?></span>
                    </div>
                </div>

                <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($testResult)): ?>
                <div class="alert <?php echo $testResult['success'] ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show">
                    <i class="fas <?php echo $testResult['success'] ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <strong><?php echo $testResult['success'] ? 'Connection Test Successful!' : 'Connection Test Failed'; ?></strong><br>
                    <?php echo $testResult['message'] ?? $testResult['error']; ?>
                    <?php if (isset($testResult['property'])): ?>
                        <br><small>Property: <?php echo htmlspecialchars($testResult['property']); ?></small>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($syncResult)): ?>
                <div class="alert alert-info alert-dismissible fade show">
                    <i class="fas fa-sync"></i>
                    <strong>Sync Results:</strong><br>
                    <?php echo $syncResult['message']; ?>
                    <?php if (!empty($syncResult['errors'])): ?>
                        <details class="mt-2">
                            <summary>Errors (<?php echo count($syncResult['errors']); ?>)</summary>
                            <ul class="mt-2 mb-0">
                                <?php foreach ($syncResult['errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- Configuration -->
            <div class="col-lg-8">
                <div class="card feature-card">
                    <div class="card-header">
                        <h5><i class="fas fa-cog"></i> Cloudbed Configuration</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="save_settings">
                            
                            <div class="mb-3">
                                <label class="form-label">Client ID</label>
                                <input type="text" name="client_id" class="form-control" 
                                       value="<?php echo htmlspecialchars($currentSettings['cloudbed_client_id'] ?? ''); ?>"
                                       placeholder="Cloudbed OAuth Client ID">
                                <small class="form-text text-muted">Dapatkan dari Cloudbed Developer Dashboard</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Client Secret</label>
                                <input type="password" name="client_secret" class="form-control" 
                                       value="<?php echo htmlspecialchars($currentSettings['cloudbed_client_secret'] ?? ''); ?>"
                                       placeholder="Cloudbed OAuth Client Secret">
                                <small class="form-text text-muted">Keep this secret and secure</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Property ID</label>
                                <input type="text" name="property_id" class="form-control" 
                                       value="<?php echo htmlspecialchars($currentSettings['cloudbed_property_id'] ?? ''); ?>"
                                       placeholder="Your Cloudbed Property ID">
                                <small class="form-text text-muted">ID properti hotel Anda di Cloudbed</small>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" name="active" class="form-check-input" 
                                           <?php echo ($currentSettings['cloudbed_active'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label">Enable Cloudbed Integration</label>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Settings
                                </button>
                                
                                <button type="submit" name="action" value="test_connection" class="btn btn-outline-success">
                                    <i class="fas fa-plug"></i> Test Connection
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sync Management -->
                <div class="card feature-card">
                    <div class="card-header">
                        <h5><i class="fas fa-sync-alt"></i> Reservation Synchronization</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-4">
                            <input type="hidden" name="action" value="sync_reservations">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" name="sync_start_date" class="form-control" 
                                           value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">End Date</label>
                                    <input type="date" name="sync_end_date" class="form-control" 
                                           value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-warning mt-3" 
                                    <?php echo !$cloudbed->isAvailable() ? 'disabled' : ''; ?>>
                                <i class="fas fa-download"></i> Sync Reservations from Cloudbed
                            </button>
                        </form>
                        
                        <hr>
                        
                        <h6>Sync Features:</h6>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-calendar-check"></i> Import Reservations</span>
                                <span class="badge bg-primary">Auto</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-users"></i> Guest Data Sync</span>
                                <span class="badge bg-primary">Auto</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-bed"></i> Room Types</span>
                                <span class="badge bg-warning">Manual</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-dollar-sign"></i> Rate Management</span>
                                <span class="badge bg-warning">Manual</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Statistics & Status -->
            <div class="col-lg-4">
                <div class="card feature-card sync-stats">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-chart-bar"></i> Sync Statistics</h5>
                        <div class="row text-center">
                            <div class="col-6">
                                <h3><?php echo $syncStats['total_synced']; ?></h3>
                                <small>Synced Reservations</small>
                            </div>
                            <div class="col-6">
                                <h3><?php echo $syncStats['pending_push']; ?></h3>
                                <small>Pending Push</small>
                            </div>
                        </div>
                        <?php if ($syncStats['last_sync']): ?>
                        <hr class="my-3">
                        <small>
                            <i class="fas fa-clock"></i> Last Sync: 
                            <?php echo date('d M Y H:i', strtotime($syncStats['last_sync'])); ?>
                        </small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card feature-card">
                    <div class="card-header">
                        <h6><i class="fas fa-info-circle"></i> Integration Benefits</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check text-success"></i> Automatic reservation sync</li>
                            <li><i class="fas fa-check text-success"></i> Guest data management</li>
                            <li><i class="fas fa-check text-success"></i> Real-time availability</li>
                            <li><i class="fas fa-check text-success"></i> Rate synchronization</li>
                            <li><i class="fas fa-check text-success"></i> Unified reporting</li>
                        </ul>
                    </div>
                </div>
                
                <div class="card feature-card">
                    <div class="card-header">
                        <h6><i class="fas fa-link"></i> Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="../../modules/frontdesk/reservasi.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-calendar"></i> Manage Reservations
                            </a>
                            <a href="../../test-api-connections.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-plug"></i> Test All Connections
                            </a>
                            <a href="https://developers.cloudbeds.com/" target="_blank" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-external-link-alt"></i> Cloudbed Docs
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Setup Guide -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-book"></i> Setup Guide</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>1. Get Cloudbed Credentials</h6>
                                <ol>
                                    <li>Login ke <a href="https://hotels.cloudbeds.com/" target="_blank">Cloudbed Dashboard</a></li>
                                    <li>Pergi ke Apps & Integrations → API</li>
                                    <li>Create new OAuth App</li>
                                    <li>Copy Client ID & Client Secret</li>
                                    <li>Dapatkan Property ID dari property settings</li>
                                </ol>
                            </div>
                            <div class="col-md-6">
                                <h6>2. Configure Integration</h6>
                                <ol>
                                    <li>Masukkan credentials di form di atas</li>
                                    <li>Enable integration dengan centang checkbox</li>
                                    <li>Test connection untuk memastikan berhasil</li>
                                    <li>Run initial sync untuk import data existing</li>
                                    <li>Setup auto-sync untuk update otomatis</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>