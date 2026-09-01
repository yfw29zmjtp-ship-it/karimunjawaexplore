<?php
/**
 * Developer Panel - Owner Monitoring Access Management
 * Configure which businesses each owner user can access for monitoring
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Owner Monitoring Access';

$success = '';
$error = '';

// Check & show success message from session
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Ensure user_business_assignment table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_business_assignment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        business_id INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_business (user_id, business_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Get all businesses
$businesses = [];
try {
    $businesses = $pdo->query("SELECT id, business_code, business_name, business_type, database_name, is_active FROM businesses WHERE is_active = 1 ORDER BY business_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get all users (except developer) for monitoring access management
$owners = [];
try {
    $owners = $pdo->query("
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.is_active, u.last_login, r.role_name, r.role_code
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.role_code != 'developer' AND u.is_active = 1
        ORDER BY r.role_code, u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Ensure owner_footer_config table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS owner_footer_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        menu_key VARCHAR(50) NOT NULL,
        menu_order INT DEFAULT 0,
        is_enabled TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_menu (user_id, menu_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Available footer menu definitions
$footerMenuDefs = [
    'home' => ['label' => 'Home', 'icon' => 'bi-house-fill', 'desc' => 'Dashboard utama', 'always' => true],
    'frontdesk' => ['label' => 'Frontdesk', 'icon' => 'bi-calendar3', 'desc' => 'Monitor kamar & booking'],
    'projects' => ['label' => 'Projects', 'icon' => 'bi-graph-up', 'desc' => 'Monitor investor & proyek'],
    'cashbook' => ['label' => 'Cashbook', 'icon' => 'bi-wallet2', 'desc' => 'Kas harian'],
    'capital' => ['label' => 'Capital', 'icon' => 'bi-bank', 'desc' => 'Modal & investasi'],
    'health' => ['label' => 'Health', 'icon' => 'bi-clipboard2-pulse', 'desc' => 'Laporan kesehatan bisnis'],
    'logout' => ['label' => 'Logout', 'icon' => 'bi-box-arrow-right', 'desc' => 'Keluar', 'always' => true],
];

// Get current assignments for all users
$assignments = [];
try {
    $stmt = $pdo->query("SELECT user_id, business_id FROM user_business_assignment");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $assignments[$row['user_id']][] = (int)$row['business_id'];
    }
} catch (Exception $e) {}

// Business code to slug mapping (for display)
$codeToSlug = [
    'BENSCAFE' => 'bens-cafe',
    'NARAYANAHOTEL' => 'narayana-hotel',
    'DEMO' => 'demo'
];

// Business type icons
$typeIcons = [
    'hotel' => 'bi-building',
    'restaurant' => 'bi-cup-hot',
    'cafe' => 'bi-cup-hot',
    'retail' => 'bi-shop',
    'manufacture' => 'bi-gear',
    'tourism' => 'bi-globe',
    'general' => 'bi-grid',
    'other' => 'bi-grid'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    
    // Save owner business access
    if ($formAction === 'save_owner_access') {
        $ownerId = (int)($_POST['owner_id'] ?? 0);
        $businessIds = $_POST['business_ids'] ?? [];
        
        if ($ownerId) {
            try {
                $pdo->beginTransaction();
                
                // Remove all existing assignments for this owner
                $pdo->prepare("DELETE FROM user_business_assignment WHERE user_id = ?")->execute([$ownerId]);
                
                // Insert new assignments
                if (!empty($businessIds)) {
                    $insertStmt = $pdo->prepare("INSERT INTO user_business_assignment (user_id, business_id, assigned_at) VALUES (?, ?, NOW())");
                    foreach ($businessIds as $bizId) {
                        $insertStmt->execute([$ownerId, (int)$bizId]);
                    }
                }
                
                $pdo->commit();
                
                // Get owner name for message
                $ownerName = '';
                foreach ($owners as $o) {
                    if ($o['id'] == $ownerId) {
                        $ownerName = $o['full_name'];
                        break;
                    }
                }
                
                $auth->logAction('update_owner_access', 'user_business_assignment', $ownerId, null, [
                    'owner_id' => $ownerId,
                    'business_ids' => $businessIds
                ]);
                
                $_SESSION['success_message'] = "Akses monitoring untuk <strong>{$ownerName}</strong> berhasil diperbarui! (" . count($businessIds) . " bisnis)";
                header("Location: owner-access.php");
                exit;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    }
    
    // Bulk save all owners at once
    if ($formAction === 'save_all_access') {
        $allAccess = $_POST['access'] ?? [];
        
        try {
            $pdo->beginTransaction();
            
// Clear all user assignments (non-developer users only)
                $ownerIds = array_column($owners, 'id');
                if (empty($ownerIds)) {
                    // Re-fetch user IDs since $owners may not be populated yet in POST
                    $idRows = $pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code != 'developer' AND u.is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                    $ownerIds = $idRows;
                }
            if (!empty($ownerIds)) {
                $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
                $pdo->prepare("DELETE FROM user_business_assignment WHERE user_id IN ($placeholders)")->execute($ownerIds);
            }
            
            // Insert new assignments
            $insertStmt = $pdo->prepare("INSERT INTO user_business_assignment (user_id, business_id, assigned_at) VALUES (?, ?, NOW())");
            $totalAssigned = 0;
            foreach ($allAccess as $ownerId => $bizIds) {
                foreach ($bizIds as $bizId) {
                    $insertStmt->execute([(int)$ownerId, (int)$bizId]);
                    $totalAssigned++;
                }
            }
            
            $pdo->commit();
            
            $auth->logAction('bulk_update_owner_access', 'user_business_assignment', null, null, [
                'owners' => count($allAccess),
                'total_assignments' => $totalAssigned
            ]);
            
            $_SESSION['success_message'] = "Semua akses monitoring berhasil diperbarui! ({$totalAssigned} assignment untuk " . count($allAccess) . " user)";
            header("Location: owner-access.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
    
    // Save footer menus for all users
    if ($formAction === 'save_all_footer') {
        $allFooter = $_POST['footer'] ?? [];
        
        try {
            $pdo->beginTransaction();
            
            // Clear existing footer configs for all non-developer users
            $ownerIds = array_column($owners, 'id');
            if (empty($ownerIds)) {
                $idRows = $pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code != 'developer' AND u.is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                $ownerIds = $idRows;
            }
            if (!empty($ownerIds)) {
                $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
                $pdo->prepare("DELETE FROM owner_footer_config WHERE user_id IN ($placeholders)")->execute($ownerIds);
            }
            
            // Insert new footer configs
            $insertStmt = $pdo->prepare("INSERT INTO owner_footer_config (user_id, menu_key, menu_order, is_enabled) VALUES (?, ?, ?, 1)");
            $totalMenus = 0;
            foreach ($allFooter as $userId => $menuKeys) {
                $order = 0;
                foreach ($menuKeys as $key) {
                    $insertStmt->execute([(int)$userId, $key, $order++]);
                    $totalMenus++;
                }
            }
            
            $pdo->commit();
            
            $auth->logAction('bulk_update_footer_menus', 'owner_footer_config', null, null, [
                'users' => count($allFooter),
                'total_menus' => $totalMenus
            ]);
            
            $_SESSION['success_message'] = "Footer menu berhasil diperbarui! ({$totalMenus} menu untuk " . count($allFooter) . " user)";
            header("Location: owner-access.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan footer: ' . $e->getMessage();
        }
    }
}

// Refresh assignments after save
$assignments = [];
try {
    $stmt = $pdo->query("SELECT user_id, business_id FROM user_business_assignment");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $assignments[$row['user_id']][] = (int)$row['business_id'];
    }
} catch (Exception $e) {}

// Load footer configs for all users
$footerConfigs = [];
try {
    $stmt = $pdo->query("SELECT user_id, menu_key FROM owner_footer_config WHERE is_enabled = 1 ORDER BY menu_order, id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $footerConfigs[$row['user_id']][] = $row['menu_key'];
    }
} catch (Exception $e) {}
$defaultFooter = ['home', 'frontdesk', 'projects', 'logout'];

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .owner-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.3s;
        border: 1px solid #e9ecef;
    }
    .owner-card:hover {
        box-shadow: 0 8px 25px rgba(0,0,0,0.08);
    }
    .owner-header {
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        border-bottom: 1px solid #f0f0f0;
    }
    .owner-avatar {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        background: linear-gradient(135deg, #6f42c1, #8b5cf6);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .owner-info h6 {
        margin: 0;
        font-weight: 600;
        color: var(--dev-dark);
    }
    .owner-info small {
        color: #666;
    }
    .owner-businesses {
        padding: 20px;
    }
    .biz-toggle {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        border-radius: 10px;
        margin-bottom: 8px;
        cursor: pointer;
        transition: all 0.2s;
        border: 2px solid #e9ecef;
        user-select: none;
    }
    .biz-toggle:hover {
        border-color: #d0d0d0;
        background: #fafafa;
    }
    .biz-toggle.active {
        border-color: #6f42c1;
        background: rgba(111, 66, 193, 0.05);
    }
    .biz-toggle input[type="checkbox"] {
        display: none;
    }
    .biz-toggle .biz-check {
        width: 22px;
        height: 22px;
        border: 2px solid #d0d0d0;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    .biz-toggle.active .biz-check {
        background: #6f42c1;
        border-color: #6f42c1;
    }
    .biz-toggle.active .biz-check::after {
        content: '✓';
        color: white;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .biz-toggle .biz-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 10px;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .biz-toggle .biz-details {
        flex: 1;
    }
    .biz-toggle .biz-name {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--dev-dark);
    }
    .biz-toggle .biz-type {
        font-size: 0.75rem;
        color: #888;
    }
    .access-count {
        font-size: 0.75rem;
        padding: 3px 10px;
        border-radius: 20px;
        font-weight: 600;
    }
    .access-count.has-access {
        background: rgba(16, 185, 129, 0.15);
        color: #059669;
    }
    .access-count.no-access {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }
    
    /* Summary Matrix Table */
    .matrix-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .matrix-table th {
        background: #f8f9fa;
        padding: 10px 12px;
        font-weight: 600;
        font-size: 0.75rem;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border-bottom: 2px solid #e9ecef;
        white-space: nowrap;
    }
    .matrix-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 0.85rem;
        vertical-align: middle;
    }
    .matrix-table tr:hover td {
        background: #fafafa;
    }
    .matrix-check {
        text-align: center;
    }
    .matrix-check .bi-check-circle-fill {
        color: #10b981;
        font-size: 1.1rem;
    }
    .matrix-check .bi-x-circle {
        color: #d1d5db;
        font-size: 1.1rem;
    }
    
    /* Tab Navigation */
    .access-tabs {
        display: flex;
        gap: 5px;
        padding: 5px;
        background: #f0f2f5;
        border-radius: 12px;
        margin-bottom: 24px;
    }
    .access-tab {
        flex: 1;
        padding: 10px 20px;
        border: none;
        background: transparent;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 500;
        font-size: 0.85rem;
        color: #666;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    .access-tab:hover {
        color: var(--dev-dark);
    }
    .access-tab.active {
        background: white;
        color: var(--dev-primary);
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    
    .save-float {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 999;
        display: none;
    }
    .save-float.show {
        display: block;
        animation: slideUp 0.3s ease;
    }
    @keyframes slideUp {
        from { transform: translateY(20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="mb-1">
                        <i class="bi bi-eye me-2"></i>Owner Monitoring Access
                    </h4>
                    <p class="text-muted mb-0" style="font-size: 0.85rem;">
                        Kelola akses monitoring owner ke masing-masing bisnis
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge bg-light text-dark border" style="font-size: 0.8rem; padding: 8px 15px;">
                        <i class="bi bi-people me-1"></i> <?php echo count($owners); ?> User
                    </span>
                    <span class="badge bg-light text-dark border" style="font-size: 0.8rem; padding: 8px 15px;">
                        <i class="bi bi-building me-1"></i> <?php echo count($businesses); ?> Bisnis
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (empty($owners)): ?>
    <!-- No Users Found -->
    <div class="content-card">
        <div class="text-center py-5">
            <i class="bi bi-person-x fs-1 text-muted mb-3 d-block"></i>
            <h5>Belum Ada User</h5>
            <p class="text-muted">Buat user terlebih dahulu di menu User Setup</p>
            <a href="index.php?section=user-setup" class="btn btn-primary">
                <i class="bi bi-person-plus me-1"></i> Buat User
            </a>
        </div>
    </div>
    
    <?php else: ?>
    
    <!-- Tab Navigation -->
    <div class="access-tabs">
        <button class="access-tab active" onclick="switchTab('cards')" id="tab-cards">
            <i class="bi bi-grid-3x3-gap"></i> Akses Bisnis
        </button>
        <button class="access-tab" onclick="switchTab('footer')" id="tab-footer">
            <i class="bi bi-phone"></i> Footer Menu
        </button>
        <button class="access-tab" onclick="switchTab('matrix')" id="tab-matrix">
            <i class="bi bi-table"></i> Matrix View
        </button>
    </div>
    
    <!-- TAB 1: Per Owner Cards -->
    <div id="view-cards">
        <form method="POST" action="" id="bulkForm">
            <input type="hidden" name="form_action" value="save_all_access">
            
            <div class="row g-4">
                <?php foreach ($owners as $owner): ?>
                <?php 
                    $ownerAssignments = $assignments[$owner['id']] ?? [];
                    $initials = strtoupper(substr($owner['full_name'], 0, 2));
                    $accessCount = count($ownerAssignments);
                ?>
                <div class="col-lg-6 col-xl-4">
                    <div class="owner-card">
                        <div class="owner-header">
                            <div class="owner-avatar"><?php echo $initials; ?></div>
                            <div class="owner-info" style="flex:1;">
                                <h6><?php echo htmlspecialchars($owner['full_name']); ?></h6>
                                <small>@<?php echo htmlspecialchars($owner['username']); ?></small>
                                <span class="badge <?php echo $owner['role_code'] === 'owner' ? 'bg-warning text-dark' : 'bg-secondary'; ?>" style="font-size:0.65rem; margin-left:4px;"><?php echo $owner['role_name']; ?></span>
                            </div>
                            <span class="access-count <?php echo $accessCount > 0 ? 'has-access' : 'no-access'; ?>">
                                <?php echo $accessCount; ?>/<?php echo count($businesses); ?> bisnis
                            </span>
                        </div>
                        <div class="owner-businesses">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <small class="text-muted fw-semibold text-uppercase" style="letter-spacing:0.5px; font-size:0.7rem;">Akses Bisnis</small>
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:0.7rem;" onclick="toggleAll(<?php echo $owner['id']; ?>, true)">
                                        <i class="bi bi-check-all"></i> Semua
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.7rem;" onclick="toggleAll(<?php echo $owner['id']; ?>, false)">
                                        <i class="bi bi-x-lg"></i> Hapus
                                    </button>
                                </div>
                            </div>
                            
                            <?php foreach ($businesses as $biz): ?>
                            <?php 
                                $isAssigned = in_array($biz['id'], $ownerAssignments);
                                $bizType = $biz['business_type'] ?? 'other';
                                $icon = $typeIcons[$bizType] ?? 'bi-grid';
                                $colors = [
                                    'hotel' => ['bg' => 'rgba(99, 102, 241, 0.15)', 'fg' => '#6366f1'],
                                    'restaurant' => ['bg' => 'rgba(245, 158, 11, 0.15)', 'fg' => '#f59e0b'],
                                    'cafe' => ['bg' => 'rgba(146, 64, 14, 0.15)', 'fg' => '#92400e'],
                                    'retail' => ['bg' => 'rgba(16, 185, 129, 0.15)', 'fg' => '#10b981'],
                                    'general' => ['bg' => 'rgba(5, 150, 105, 0.15)', 'fg' => '#059669'],
                                ];
                                $color = $colors[$bizType] ?? ['bg' => 'rgba(107, 114, 128, 0.15)', 'fg' => '#6b7280'];
                            ?>
                            <label class="biz-toggle <?php echo $isAssigned ? 'active' : ''; ?>" 
                                   data-owner="<?php echo $owner['id']; ?>" 
                                   data-biz="<?php echo $biz['id']; ?>">
                                <input type="checkbox" 
                                       name="access[<?php echo $owner['id']; ?>][]" 
                                       value="<?php echo $biz['id']; ?>"
                                       <?php echo $isAssigned ? 'checked' : ''; ?>
                                       onchange="toggleBiz(this)">
                                <div class="biz-check"></div>
                                <div class="biz-icon" style="background:<?php echo $color['bg']; ?>; color:<?php echo $color['fg']; ?>;">
                                    <i class="bi <?php echo $icon; ?>"></i>
                                </div>
                                <div class="biz-details">
                                    <div class="biz-name"><?php echo htmlspecialchars($biz['business_name']); ?></div>
                                    <div class="biz-type"><?php echo ucfirst($bizType); ?> &middot; <?php echo $biz['business_code']; ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Floating Save Button -->
            <div class="save-float" id="saveFloat">
                <button type="submit" class="btn btn-primary btn-lg shadow-lg" style="border-radius:50px; padding: 12px 30px;">
                    <i class="bi bi-check-lg me-2"></i>Simpan Semua Perubahan
                </button>
            </div>
        </form>
    </div>
    
    <!-- TAB 2: Footer Menu Config -->
    <div id="view-footer" style="display:none;">
        <form method="POST" action="" id="footerForm">
            <input type="hidden" name="form_action" value="save_all_footer">
            
            <div class="alert alert-info mb-4">
                <i class="bi bi-info-circle me-2"></i>
                Centang menu yang akan muncul di <strong>footer navigation</strong> owner dashboard untuk setiap user.
                Menu <strong>Home</strong> dan <strong>Logout</strong> akan selalu muncul.
            </div>
            
            <div class="row g-4">
                <?php foreach ($owners as $owner): ?>
                <?php 
                    $userFooter = $footerConfigs[$owner['id']] ?? $defaultFooter;
                    $initials = strtoupper(substr($owner['full_name'], 0, 2));
                ?>
                <div class="col-lg-6 col-xl-4">
                    <div class="owner-card">
                        <div class="owner-header">
                            <div class="owner-avatar"><?php echo $initials; ?></div>
                            <div class="owner-info" style="flex:1;">
                                <h6><?php echo htmlspecialchars($owner['full_name']); ?></h6>
                                <small>@<?php echo htmlspecialchars($owner['username']); ?></small>
                                <span class="badge <?php echo $owner['role_code'] === 'owner' ? 'bg-warning text-dark' : 'bg-secondary'; ?>" style="font-size:0.65rem; margin-left:4px;"><?php echo $owner['role_name']; ?></span>
                            </div>
                            <span class="access-count has-access"><?php echo count($userFooter); ?> menu</span>
                        </div>
                        <div class="owner-businesses">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <small class="text-muted fw-semibold text-uppercase" style="letter-spacing:0.5px; font-size:0.7rem;">Footer Menu</small>
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:0.7rem;" onclick="toggleAllFooter(<?php echo $owner['id']; ?>, true)">
                                        <i class="bi bi-check-all"></i> Semua
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.7rem;" onclick="toggleAllFooter(<?php echo $owner['id']; ?>, false)">
                                        <i class="bi bi-x-lg"></i> Reset
                                    </button>
                                </div>
                            </div>
                            
                            <?php foreach ($footerMenuDefs as $key => $menu): ?>
                            <?php 
                                $isEnabled = in_array($key, $userFooter);
                                $isAlways = isset($menu['always']);
                            ?>
                            <label class="biz-toggle <?php echo $isEnabled ? 'active' : ''; ?> <?php echo $isAlways ? 'always-on' : ''; ?>" 
                                   data-footer-user="<?php echo $owner['id']; ?>">
                                <input type="checkbox" 
                                       name="footer[<?php echo $owner['id']; ?>][]" 
                                       value="<?php echo $key; ?>"
                                       <?php echo $isEnabled ? 'checked' : ''; ?>
                                       <?php echo $isAlways ? 'checked onclick="return false;"' : ''; ?>
                                       onchange="toggleFooterItem(this)">
                                <div class="biz-check"></div>
                                <div class="biz-icon" style="background:rgba(99,102,241,0.12); color:#6366f1;">
                                    <i class="bi <?php echo $menu['icon']; ?>"></i>
                                </div>
                                <div class="biz-details">
                                    <div class="biz-name"><?php echo $menu['label']; ?></div>
                                    <div class="biz-type"><?php echo $menu['desc']; ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Floating Save Button -->
            <div class="save-float" id="saveFooterFloat">
                <button type="submit" class="btn btn-primary btn-lg shadow-lg" style="border-radius:50px; padding: 12px 30px;">
                    <i class="bi bi-check-lg me-2"></i>Simpan Footer Menu
                </button>
            </div>
        </form>
    </div>
    
    <!-- TAB 3: Matrix View -->
    <div id="view-matrix" style="display:none;">
        <div class="content-card">
            <div class="card-header-custom">
                <h5><i class="bi bi-table me-2"></i>Matrix Akses Owner</h5>
                <small class="text-muted">Overview akses monitoring semua owner ke semua bisnis</small>
            </div>
            <div class="table-responsive">
                <table class="matrix-table">
                    <thead>
                        <tr>
                            <th style="min-width:180px;">Owner</th>
                            <?php foreach ($businesses as $biz): ?>
                            <th class="text-center" style="min-width:120px;">
                                <div><?php echo htmlspecialchars($biz['business_name']); ?></div>
                                <div style="font-weight:400; text-transform:none; color:#999; font-size:0.65rem;">
                                    <?php echo ucfirst($biz['business_type'] ?? 'other'); ?>
                                </div>
                            </th>
                            <?php endforeach; ?>
                            <th class="text-center">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($owners as $owner): ?>
                        <?php $ownerAssignments = $assignments[$owner['id']] ?? []; ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="owner-avatar" style="width:32px;height:32px;font-size:0.7rem;border-radius:8px;">
                                        <?php echo strtoupper(substr($owner['full_name'], 0, 2)); ?>
                                    </div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($owner['full_name']); ?></strong>
                                        <br><small class="text-muted">@<?php echo $owner['username']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <?php foreach ($businesses as $biz): ?>
                            <td class="matrix-check">
                                <?php if (in_array($biz['id'], $ownerAssignments)): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle"></i>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="text-center">
                                <span class="badge <?php echo count($ownerAssignments) > 0 ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo count($ownerAssignments); ?>/<?php echo count($businesses); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<script>
let hasChanges = false;

function switchTab(tab) {
    document.getElementById('tab-cards').classList.toggle('active', tab === 'cards');
    document.getElementById('tab-matrix').classList.toggle('active', tab === 'matrix');
    document.getElementById('tab-footer').classList.toggle('active', tab === 'footer');
    document.getElementById('view-cards').style.display = tab === 'cards' ? '' : 'none';
    document.getElementById('view-matrix').style.display = tab === 'matrix' ? '' : 'none';
    document.getElementById('view-footer').style.display = tab === 'footer' ? '' : 'none';
}

function toggleBiz(checkbox) {
    const label = checkbox.closest('.biz-toggle');
    label.classList.toggle('active', checkbox.checked);
    updateAccessCount(checkbox);
    showSaveButton();
}

function toggleAll(ownerId, checked) {
    document.querySelectorAll(`.biz-toggle[data-owner="${ownerId}"] input[type="checkbox"]`).forEach(cb => {
        cb.checked = checked;
        cb.closest('.biz-toggle').classList.toggle('active', checked);
    });
    // Update count
    const firstCb = document.querySelector(`.biz-toggle[data-owner="${ownerId}"] input[type="checkbox"]`);
    if (firstCb) updateAccessCount(firstCb);
    showSaveButton();
}

function updateAccessCount(checkbox) {
    const card = checkbox.closest('.owner-card');
    const total = card.querySelectorAll('.biz-toggle').length;
    const checked = card.querySelectorAll('.biz-toggle input:checked').length;
    const badge = card.querySelector('.access-count');
    badge.textContent = `${checked}/${total} bisnis`;
    badge.className = 'access-count ' + (checked > 0 ? 'has-access' : 'no-access');
}

function showSaveButton() {
    hasChanges = true;
    document.getElementById('saveFloat').classList.add('show');
}

// Warn before leaving with unsaved changes
window.addEventListener('beforeunload', function(e) {
    if (hasChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// Remove warning after form submit
document.getElementById('bulkForm')?.addEventListener('submit', function() {
    hasChanges = false;
});
document.getElementById('footerForm')?.addEventListener('submit', function() {
    hasChanges = false;
});

function toggleFooterItem(checkbox) {
    const label = checkbox.closest('.biz-toggle');
    if (label.classList.contains('always-on')) return;
    label.classList.toggle('active', checkbox.checked);
    showFooterSave();
}

function toggleAllFooter(userId, checked) {
    document.querySelectorAll(`[data-footer-user="${userId}"] input[type="checkbox"]`).forEach(cb => {
        if (cb.closest('.always-on')) return;
        cb.checked = checked;
        cb.closest('.biz-toggle').classList.toggle('active', checked);
    });
    // Always keep always-on checked
    document.querySelectorAll(`[data-footer-user="${userId}"].always-on input`).forEach(cb => {
        cb.checked = true;
        cb.closest('.biz-toggle').classList.add('active');
    });
    showFooterSave();
}

function showFooterSave() {
    hasChanges = true;
    document.getElementById('saveFooterFloat').classList.add('show');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
