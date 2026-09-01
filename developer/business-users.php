<?php
/**
 * Developer Panel - Business User Management
 * Manage users in specific business databases
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Business Users Management';

$action = $_GET['action'] ?? 'select';
$selectedBusinessId = $_GET['business_id'] ?? null;
$editId = $_GET['id'] ?? null;
$error = '';
$success = '';

// Get all businesses
$businesses = [];
try {
    $businesses = $pdo->query("SELECT * FROM businesses WHERE is_active = 1 ORDER BY business_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Auto-redirect to first business if no business selected (skip select page)
if (!$selectedBusinessId && !empty($businesses)) {
    header('Location: business-users.php?business_id=' . $businesses[0]['id']);
    exit;
}

// If business selected, connect to business database
$businessPdo = null;
$businessConfig = null;
if ($selectedBusinessId) {
    $bizStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = ?");
    $bizStmt->execute([$selectedBusinessId]);
    $businessConfig = $bizStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($businessConfig) {
        try {
            $businessPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $businessConfig['database_name'],
                DB_USER,
                DB_PASS
            );
            $businessPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $error = 'Cannot connect to business database: ' . $e->getMessage();
        }
    }
}

// Roles for business users
$businessRoles = [
    'admin' => 'Administrator',
    'manager' => 'Manager',
    'accountant' => 'Accountant',
    'cashier' => 'Cashier',
    'staff' => 'Staff',
    'owner' => 'Owner'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $businessPdo) {
    $formAction = $_POST['form_action'] ?? '';
    
    if ($formAction === 'create' || $formAction === 'update') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $businessAccess = $_POST['business_access'] ?? 'all';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        
        // Validation
        if (empty($username) || empty($fullName)) {
            $error = 'Username and Full Name are required';
        } else {
            try {
                if ($formAction === 'create') {
                    if (empty($password)) {
                        $error = 'Password is required for new user';
                    } else {
                        // Get master database connection
                        $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                        $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        
                        // Check duplicate in MASTER database
                        $check = $masterPdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                        $check->execute([$username]);
                        
                        if ($check->fetchColumn() > 0) {
                            $error = 'Username already exists in system';
                        } else {
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            
                            // 1. Create in MASTER database (for login)
                            $masterStmt = $masterPdo->prepare("
                                INSERT INTO users (username, password, full_name, email, phone, role_id, is_active)
                                VALUES (?, ?, ?, ?, ?, 3, ?)
                            ");
                            $masterStmt->execute([$username, $hashedPassword, $fullName, $email, $phone, $isActive]);
                            $masterUserId = $masterPdo->lastInsertId();
                            
                            // 2. Also create in BUSINESS database with same password
                            $stmt = $businessPdo->prepare("
                                INSERT INTO users (username, password, full_name, email, phone, role, business_access, is_active)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$username, $hashedPassword, $fullName, $email, $phone, $role, $businessAccess, $isActive]);
                            
                            // 3. CREATE PERMISSION ENTRIES in MASTER database
                            // Get all menus enabled for this business
                            $menuStmt = $masterPdo->prepare("
                                SELECT m.id, m.menu_code FROM menu_items m
                                JOIN business_menu_config bmc ON m.id = bmc.menu_id
                                WHERE bmc.business_id = ? AND bmc.is_enabled = 1
                            ");
                            $menuStmt->execute([$selectedBusinessId]);
                            $menus = $menuStmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Insert permission for each menu (only for THIS business)
                            $permStmt = $masterPdo->prepare("
                                INSERT INTO user_menu_permissions (user_id, business_id, menu_id, menu_code, can_view, can_create, can_edit, can_delete)
                                VALUES (?, ?, ?, ?, 1, 1, 1, 1)
                            ");
                            
                            foreach ($menus as $menu) {
                                try {
                                    $permStmt->execute([$masterUserId, $selectedBusinessId, $menu['id'], $menu['menu_code']]);
                                } catch (Exception $e) {
                                    // Skip if menu already exists for user
                                }
                            }
                            
                            // 4. Also create assignment in user_business_assignment table
                            try {
                                $assignStmt = $masterPdo->prepare("
                                    INSERT IGNORE INTO user_business_assignment (user_id, business_id, assigned_at)
                                    VALUES (?, ?, NOW())
                                ");
                                $assignStmt->execute([$masterUserId, $selectedBusinessId]);
                            } catch (Exception $e) {}
                            
                            $auth->logAction('create_business_user', 'users', $masterUserId, null, [
                                'business' => $businessConfig['business_name'],
                                'username' => $username,
                                'menus_assigned' => count($menus)
                            ]);
                            
                            $_SESSION['success_message'] = "User '{$username}' created successfully with " . count($menus) . " menu permissions and can now login!";
                            header("Location: business-users.php?business_id={$selectedBusinessId}");
                            exit;
                        }
                    }
                } else {
                    // Update
                    $updateId = (int)$_POST['user_id'];
                    
                    // Get master database connection
                    $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Update in MASTER database
                        $masterStmt = $masterPdo->prepare("
                            UPDATE users SET username = ?, password = ?, full_name = ?, email = ?, phone = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $masterStmt->execute([$username, $hashedPassword, $fullName, $email, $phone, $isActive, $updateId]);
                        
                        // Update in BUSINESS database
                        $stmt = $businessPdo->prepare("
                            UPDATE users SET username = ?, password = ?, full_name = ?, email = ?, phone = ?, role = ?, business_access = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$username, $hashedPassword, $fullName, $email, $phone, $role, $businessAccess, $isActive, $updateId]);
                    } else {
                        $stmt = $businessPdo->prepare("
                            UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, role = ?, business_access = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$username, $fullName, $email, $phone, $role, $businessAccess, $isActive, $updateId]);
                    }
                    
                    $auth->logAction('update_business_user', 'users', $updateId, null, [
                        'business' => $businessConfig['business_name'],
                        'username' => $username
                    ]);
                    
                    $_SESSION['success_message'] = 'User updated successfully!';
                    header("Location: business-users.php?business_id={$selectedBusinessId}");
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle delete (SOFT DELETE - set inactive instead of permanent delete)
if ($action === 'delete' && $editId && $businessPdo) {
    try {
        // Soft delete: set is_active = 0 instead of DELETE to preserve FK constraints
        $stmt = $businessPdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->execute([$editId]);
        
        $auth->logAction('delete_business_user', 'users', $editId, null, [
            'business' => $businessConfig['business_name']
        ]);
        
        $_SESSION['success_message'] = 'User deactivated successfully!';
        header("Location: business-users.php?business_id={$selectedBusinessId}");
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Failed to deactivate user: ' . $e->getMessage();
    }
}

// Get user for editing
$editUser = null;
if ($action === 'edit' && $editId && $businessPdo) {
    $stmt = $businessPdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all users from business
$businessUsers = [];
if ($businessPdo) {
    try {
        $businessUsers = $businessPdo->query("SELECT * FROM users WHERE is_active = 1 ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.list-group-item-action {
    cursor: pointer !important;
    text-decoration: none !important;
    color: inherit !important;
}
.list-group-item-action:hover {
    background-color: rgba(111, 66, 193, 0.1) !important;
    border-color: var(--dev-primary) !important;
    transform: translateX(5px);
}
.password-wrapper {
    position: relative;
}
.password-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #6c757d;
    font-size: 1.25rem;
    user-select: none;
    transition: color 0.2s;
}
.password-toggle:hover {
    color: #495057;
}
</style>

<div class="container-fluid py-4">
    <?php if ($action === 'add' || $action === 'edit'): ?>
    <!-- Add/Edit Form -->
    <div class="row">
        <div class="col-12 mb-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="business-users.php">Business Users</a></li>
                    <li class="breadcrumb-item"><a href="?business_id=<?php echo $selectedBusinessId; ?>"><?php echo htmlspecialchars($businessConfig['business_name']); ?></a></li>
                    <li class="breadcrumb-item active"><?php echo $action === 'add' ? 'Add User' : 'Edit User'; ?></li>
                </ol>
            </nav>
        </div>
    </div>
    
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-person-<?php echo $action === 'add' ? 'plus' : 'gear'; ?> me-2"></i>
                        <?php echo $action === 'add' ? 'Add New User' : 'Edit User'; ?>
                    </h5>
                    <a href="?business_id=<?php echo $selectedBusinessId; ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to List
                    </a>
                </div>
                
                <div class="p-4">
                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($action === 'edit' && $editUser): ?>
                    <?php 
                    // Map business_code to URL slug
                    $codeToSlug = [
                        'BENSCAFE' => 'bens-cafe',
                        'NARAYANAHOTEL' => 'narayana-hotel'
                    ];
                    $businessSlug = $codeToSlug[$businessConfig['business_code']] ?? strtolower($businessConfig['business_code']);
                    $loginUrl = BASE_URL . '/login.php?biz=' . $businessSlug;
                    ?>
                    <div class="alert alert-info d-flex align-items-center mb-4">
                        <i class="bi bi-link-45deg fs-3 me-3"></i>
                        <div class="flex-grow-1">
                            <h6 class="alert-heading mb-2">Share Login Link</h6>
                            <p class="mb-2 small">Send this link to <strong><?php echo htmlspecialchars($editUser['full_name']); ?></strong> for direct business access:</p>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($loginUrl); ?>" readonly id="editLoginLink" style="font-size: 0.813rem;">
                                <button class="btn btn-outline-primary" type="button" onclick="copyEditLoginLink()" title="Copy Link">
                                    <i class="bi bi-clipboard"></i> Copy
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="form_action" value="<?php echo $action === 'add' ? 'create' : 'update'; ?>">
                        <?php if ($editUser): ?>
                        <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" required
                                       value="<?php echo htmlspecialchars($editUser['username'] ?? ''); ?>">
                                <small class="text-muted">Untuk login ke sistem</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" required
                                       value="<?php echo htmlspecialchars($editUser['full_name'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email"
                                       value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone"
                                       value="<?php echo htmlspecialchars($editUser['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" name="role" required>
                                    <?php foreach ($businessRoles as $roleCode => $roleName): ?>
                                    <option value="<?php echo $roleCode; ?>" 
                                            <?php echo ($editUser['role'] ?? 'staff') == $roleCode ? 'selected' : ''; ?>>
                                        <?php echo $roleName; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">
                                    <strong>accountant</strong> = Staff Accounting, 
                                    <strong>staff</strong> = Staff FO/Other
                                </small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password <?php echo $action === 'add' ? '<span class="text-danger">*</span>' : '(kosongkan jika tidak diubah)'; ?></label>
                                <div class="password-wrapper">
                                    <input type="password" class="form-control" name="password" id="userPassword" <?php echo $action === 'add' ? 'required' : ''; ?> style="padding-right: 45px;">
                                    <span class="password-toggle" onclick="togglePassword('userPassword', this)">👁️</span>
                                </div>
                                <small class="text-muted">
                                    <?php if ($action === 'edit'): ?>
                                    <i class="bi bi-shield-check"></i> <strong>Developer Reset:</strong> Tidak perlu password lama
                                    <?php else: ?>
                                    Minimal 6 karakter
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                        
                        <?php if ($action === 'edit'): ?>
                        <div class="alert alert-info mb-3" style="background: rgba(59, 130, 246, 0.1); border-left: 4px solid #3b82f6;">
                            <div class="d-flex gap-2">
                                <i class="bi bi-info-circle flex-shrink-0" style="font-size: 1.2rem; color: #3b82f6;"></i>
                                <div>
                                    <strong>Password Reset untuk User yang Lupa Password</strong><br>
                                    <small>Sebagai developer, Anda bisa langsung reset password user tanpa perlu memasukkan password lama. 
                                    User dapat meminta reset password kepada Anda jika lupa password mereka.</small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Business Access</label>
                            <input type="text" class="form-control" name="business_access"
                                   value="<?php echo htmlspecialchars($editUser['business_access'] ?? 'all'); ?>">
                            <small class="text-muted">Leave 'all' for full access or specify business IDs</small>
                        </div>
                        
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                       <?php echo ($editUser['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active User</label>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i><?php echo $action === 'add' ? 'Create User' : 'Update User'; ?>
                            </button>
                            <a href="?business_id=<?php echo $selectedBusinessId; ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Users List -->
    <?php if (!$businessConfig): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Business not found. <a href="business-users.php">Go back</a>
    </div>
    <?php else: ?>
    <div class="row mb-3">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="business-users.php">Business Users</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($businessConfig['business_name']); ?></li>
                </ol>
            </nav>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0"><?php echo htmlspecialchars($businessConfig['business_name']); ?> - Users</h4>
                    <small class="text-muted">
                        <i class="bi bi-database me-1"></i><?php echo htmlspecialchars($businessConfig['database_name']); ?>
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <?php if (count($businesses) > 1): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-building me-1"></i>Switch Business
                        </button>
                        <ul class="dropdown-menu">
                            <?php foreach ($businesses as $biz): ?>
                                <?php if ($biz['id'] != $selectedBusinessId): ?>
                                <li>
                                    <a class="dropdown-item" href="?business_id=<?php echo $biz['id']; ?>">
                                        <?php echo htmlspecialchars($biz['business_name']); ?>
                                    </a>
                                </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <a href="?business_id=<?php echo $selectedBusinessId; ?>&action=add" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i>Add New User
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <div class="content-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th width="5%">ID</th>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Access</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Login Link</th>
                        <th width="15%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($businessUsers)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-people fs-1 d-block mb-2"></i>
                            No users found. <a href="?business_id=<?php echo $selectedBusinessId; ?>&action=add">Create one</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($businessUsers as $u): ?>
                    <tr>
                        <td><?php echo $u['id']; ?></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="user-avatar me-3" style="width:36px;height:36px;font-size:0.9rem;">
                                    <?php echo strtoupper(substr($u['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                    <br><small class="text-muted">@<?php echo htmlspecialchars($u['username']); ?></small>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($u['email'] ?? '-'); ?></td>
                        <td>
                            <span class="badge bg-<?php 
                                echo $u['role'] === 'admin' ? 'danger' : 
                                    ($u['role'] === 'manager' ? 'warning' :
                                    ($u['role'] === 'accountant' ? 'info' : 
                                    ($u['role'] === 'owner' ? 'primary' : 'secondary'))); 
                            ?>">
                                <?php echo ucfirst($u['role']); ?>
                            </span>
                        </td>
                        <td>
                            <small class="text-muted"><?php echo htmlspecialchars($u['business_access'] ?? 'all'); ?></small>
                        </td>
                        <td>
                            <?php if ($u['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted"><?php echo date('d/m/Y', strtotime($u['created_at'])); ?></small>
                        </td>
                        <td>
                            <?php 
                            // Map business_code to URL slug
                            $codeToSlug = [
                                'BENSCAFE' => 'bens-cafe',
                                'NARAYANAHOTEL' => 'narayana-hotel'
                            ];
                            $businessSlug = $codeToSlug[$businessConfig['business_code']] ?? strtolower($businessConfig['business_code']);
                            $loginUrl = BASE_URL . '/login.php?biz=' . $businessSlug;
                            ?>
                            <div class="input-group input-group-sm" style="max-width: 300px;">
                                <input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars($loginUrl); ?>" readonly id="loginLink<?php echo $u['id']; ?>" style="font-size: 0.75rem;">
                                <button class="btn btn-outline-secondary" type="button" onclick="copyLoginLink(<?php echo $u['id']; ?>)" title="Copy Link">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </td>
                        <td>
                            <a href="?business_id=<?php echo $selectedBusinessId; ?>&action=edit&id=<?php echo $u['id']; ?>" 
                               class="btn btn-sm btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button onclick="confirmDelete('?business_id=<?php echo $selectedBusinessId; ?>&action=delete&id=<?php echo $u['id']; ?>', '<?php echo addslashes($u['username']); ?>')" 
                                    class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; // if $businessConfig ?>
    <?php endif; // else (Users List) ?>
</div>

<script>
function confirmDelete(url, username) {
    if (confirm('Delete user "' + username + '"? This action cannot be undone!')) {
        window.location.href = url;
    }
}

function copyLoginLink(userId) {
    const input = document.getElementById('loginLink' + userId);
    input.select();
    input.setSelectionRange(0, 99999); // For mobile devices
    
    // Copy to clipboard
    navigator.clipboard.writeText(input.value).then(function() {
        // Show success feedback
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i>';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        
        setTimeout(function() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2000);
    }).catch(function(err) {
        alert('Failed to copy: ' + err);
    });
}

function copyEditLoginLink() {
    const input = document.getElementById('editLoginLink');
    input.select();
    input.setSelectionRange(0, 99999); // For mobile devices
    
    // Copy to clipboard
    navigator.clipboard.writeText(input.value).then(function() {
        // Show success feedback
        const btn = event.target;
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-success');
        
        setTimeout(function() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-primary');
        }, 2000);
    }).catch(function(err) {
        alert('Failed to copy: ' + err);
    });
}

function togglePassword(inputId, iconElement) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        iconElement.textContent = '👁️‍🗨️'; // Eye with slash
    } else {
        input.type = 'password';
        iconElement.textContent = '👁️'; // Normal eye
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
