<?php
/**
 * Developer Panel - User Management
 * Create, Edit, Delete users and assign roles
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'User Management';

$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;
$error = '';
$success = '';

// Get roles
$roles = [];
try {
    $roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';
    
    if ($formAction === 'create' || $formAction === 'update') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        
        // Validation
        if (empty($username) || empty($email) || empty($fullName) || $roleId === 0) {
            $error = 'Please fill all required fields';
        } else {
            try {
                if ($formAction === 'create') {
                    if (empty($password)) {
                        $error = 'Password is required for new user';
                    } else {
                        // Check duplicate
                        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
                        $check->execute([$username, $email]);
                        if ($check->fetchColumn() > 0) {
                            $error = 'Username or email already exists';
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO users (username, email, password, full_name, phone, role_id, is_active, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            $stmt->execute([$username, $email, $hashedPassword, $fullName, $phone, $roleId, $isActive, $user['id']]);
                            
                            $auth->logAction('create_user', 'users', $pdo->lastInsertId(), null, ['username' => $username]);
                            $_SESSION['success_message'] = 'User created successfully!';
                            header('Location: users.php');
                            exit;
                        }
                    }
                } else {
                    // Update
                    $updateId = (int)$_POST['user_id'];
                    
                    if (!empty($password)) {
                        $stmt = $pdo->prepare("
                            UPDATE users SET username = ?, email = ?, password = ?, full_name = ?, phone = ?, role_id = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt->execute([$username, $email, $hashedPassword, $fullName, $phone, $roleId, $isActive, $updateId]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE users SET username = ?, email = ?, full_name = ?, phone = ?, role_id = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$username, $email, $fullName, $phone, $roleId, $isActive, $updateId]);
                    }
                    
                    $auth->logAction('update_user', 'users', $updateId, null, ['username' => $username]);
                    $_SESSION['success_message'] = 'User updated successfully!';
                    header('Location: users.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle delete
if ($action === 'delete' && $editId) {
    try {
        // Don't allow deleting self
        if ((int)$editId === (int)$user['id']) {
            $_SESSION['error_message'] = 'You cannot delete yourself!';
        } else {
            // Disable FK checks first to avoid constraint violations
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            
            // Check if user owns any businesses
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM businesses WHERE owner_id = ?");
            $stmt->execute([$editId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $ownedBusinessesCount = $result['count'] ?? 0;
            
            if ($ownedBusinessesCount > 0) {
                // Reassign businesses to current user (admin)
                $stmt = $pdo->prepare("UPDATE businesses SET owner_id = ? WHERE owner_id = ?");
                $stmt->execute([$user['id'], $editId]);
            }
            
            // Delete user menu permissions
            $stmt = $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ?");
            $stmt->execute([$editId]);
            
            // Delete user business assignments
            if ($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_business_assignment'")->fetchColumn() > 0) {
                $stmt = $pdo->prepare("DELETE FROM user_business_assignment WHERE user_id = ?");
                $stmt->execute([$editId]);
            }
            
            // Delete user preferences
            if ($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_preferences'")->fetchColumn() > 0) {
                $stmt = $pdo->prepare("DELETE FROM user_preferences WHERE user_id = ?");
                $stmt->execute([$editId]);
            }
            
            // Get username before deleting (for cascade to business DBs)
            $usernameStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $usernameStmt->execute([$editId]);
            $deletedUser = $usernameStmt->fetch(PDO::FETCH_ASSOC);
            $deletedUsername = $deletedUser['username'] ?? '';
            
            // Delete the user from master database
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$editId]);
            
            // Re-enable FK checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            
            // Also delete/deactivate user from ALL business databases
            if ($deletedUsername) {
                $businessDatabases = ['adf_narayana_hotel', 'adf_benscafe', 'adf_demo'];
                foreach ($businessDatabases as $bizDb) {
                    try {
                        $bizDbName = getDbName($bizDb);
                        $bizPdo = new PDO(
                            "mysql:host=" . DB_HOST . ";dbname=" . $bizDbName . ";charset=utf8mb4",
                            DB_USER, DB_PASS,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                        );
                        $bizPdo->exec("SET FOREIGN_KEY_CHECKS=0");
                        // Delete user from business DB
                        $bizStmt = $bizPdo->prepare("DELETE FROM users WHERE username = ?");
                        $bizStmt->execute([$deletedUsername]);
                        $bizPdo->exec("SET FOREIGN_KEY_CHECKS=1");
                    } catch (Exception $bizErr) {
                        // Business DB might not exist or no users table — skip
                    }
                }
            }
            
            $auth->logAction('delete_user', 'users', $editId);
            $deleteMsg = $ownedBusinessesCount > 0 
                ? "User deleted successfully! Their $ownedBusinessesCount business(es) reassigned to you."
                : 'User deleted successfully!';
            $_SESSION['success_message'] = $deleteMsg;
        }
        header('Location: users.php');
        exit;
    } catch (Exception $e) {
        // Re-enable FK checks on error
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Exception $ignored) {}
        $_SESSION['error_message'] = 'Failed to delete user: ' . $e->getMessage();
    }
}

// Get user for editing
$editUser = null;
if ($action === 'edit' && $editId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all users
$users = [];
try {
    $stmt = $pdo->query("
        SELECT u.*, r.role_name, r.role_code,
               (SELECT COUNT(*) FROM user_business_assignment WHERE user_id = u.id) as business_count
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-3">
    <!-- Success/Error Messages -->
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show alert-sm">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="bi bi-people me-2"></i>User Management</h5>
        <?php if ($action !== 'add' && $action !== 'edit'): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#userModal">
            <i class="bi bi-person-plus me-1"></i>Add User
        </button>
        <?php endif; ?>
    </div>
    
    <?php if ($action === 'add' || $action === 'edit'): ?>
    <!-- Back Button -->
    <div class="mb-3">
        <a href="users.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to List
        </a>
    </div>
    
    <!-- Form Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-3">
            <h6 class="card-title mb-3">
                <i class="bi bi-person-<?php echo $action === 'add' ? 'plus' : 'gear'; ?> me-2"></i>
                <?php echo $action === 'add' ? 'Add New User' : 'Edit User'; ?>
            </h6>
            
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="<?php echo $action === 'add' ? 'create' : 'update'; ?>">
                <?php if ($editUser): ?>
                <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
                <?php endif; ?>
                
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="username" required
                               value="<?php echo htmlspecialchars($editUser['username'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control form-control-sm" name="email" required
                               value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="row g-2 mt-1">
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="full_name" required
                               value="<?php echo htmlspecialchars($editUser['full_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Phone</label>
                        <input type="text" class="form-control form-control-sm" name="phone"
                               value="<?php echo htmlspecialchars($editUser['phone'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="row g-2 mt-1">
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Role <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="role_id" required>
                            <option value="">Select Role</option>
                            <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>" 
                                    <?php echo ($editUser['role_id'] ?? '') == $role['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['role_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Password <?php echo $action === 'add' ? '<span class="text-danger">*</span>' : ''; ?></label>
                        <div class="input-group input-group-sm">
                            <input type="password" class="form-control form-control-sm" name="password" id="userPassword" <?php echo $action === 'add' ? 'required' : ''; ?>
                                   placeholder="<?php echo $action === 'add' ? 'Required' : 'Kosongkan jika tidak ingin mengubah'; ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleDevPassword()" title="Tampilkan/Sembunyikan Password">
                                <i class="bi bi-eye" id="userPasswordIcon"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="mt-2">
                    <div class="form-check form-check-sm">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                               <?php echo ($editUser['is_active'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active User</label>
                    </div>
                </div>
                
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?php echo $action === 'add' ? 'Create' : 'Update'; ?>
                    </button>
                    <a href="users.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Users Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:25%;">User</th>
                        <th style="width:20%;">Email</th>
                        <th style="width:12%;">Role</th>
                        <th style="width:13%;">Status</th>
                        <th style="width:20%;">Last Login</th>
                        <th style="width:10%;" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            <i class="bi bi-people fs-5 d-block mb-2"></i>
                            No users found
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="user-avatar" style="width:28px;height:28px;font-size:0.7rem;display:flex;align-items:center;justify-content:center;border-radius:50%;background:#6c757d;color:white;">
                                    <?php echo strtoupper(substr($u['full_name'], 0, 1)); ?>
                                </div>
                                <div style="line-height:1.2;">
                                    <strong style="font-size:0.85rem;display:block;"><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                    <small class="text-muted" style="font-size:0.75rem;">@<?php echo htmlspecialchars($u['username']); ?></small>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:0.85rem;"><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                            <span class="badge" style="font-size:0.75rem;background-color:<?php 
                                echo $u['role_code'] === 'developer' ? '#6f42c1' : 
                                    ($u['role_code'] === 'owner' ? '#3b82f6' : '#6c757d'); 
                            ?>">
                                <?php echo htmlspecialchars($u['role_name']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['is_active']): ?>
                            <span class="badge bg-success" style="font-size:0.75rem;">Active</span>
                            <?php else: ?>
                            <span class="badge bg-danger" style="font-size:0.75rem;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.85rem;">
                            <?php echo $u['last_login'] ? date('M d, Y H:i', strtotime($u['last_login'])) : '<span class="text-muted">Never</span>'; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="?action=edit&id=<?php echo $u['id']; ?>" class="btn btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="user-setup-simple.php?user_id=<?php echo $u['id']; ?>" class="btn btn-outline-info" title="Assign Business">
                                    <i class="bi bi-building"></i>
                                </a>
                                <?php if ($u['id'] != $user['id']): ?>
                                <button type="button" class="btn btn-outline-danger" onclick="confirmDelete('?action=delete&id=<?php echo $u['id']; ?>', '<?php echo addslashes($u['full_name']); ?>')" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
    .form-label-sm {
        font-size: 0.85rem;
        margin-bottom: 0.3rem;
        font-weight: 500;
    }
    
    .form-control-sm, .form-select-sm {
        font-size: 0.85rem;
        padding: 0.35rem 0.5rem;
        height: auto;
        min-height: 2rem;
    }
    
    .alert-sm {
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
    }
    
    .btn-close-sm {
        width: 1rem;
        height: 1rem;
    }
    
    .table-sm {
        font-size: 0.875rem;
    }
    
    .table-sm th,
    .table-sm td {
        padding: 0.5rem 0.75rem;
        vertical-align: middle;
    }
    
    .card {
        border-radius: 0.5rem;
    }
    
    .user-avatar {
        font-weight: 600;
        font-size: 0.8rem;
        background-color: #6c757d;
        color: white;
    }
    
    @media (max-width: 768px) {
        .table-sm th:nth-child(n+4),
        .table-sm td:nth-child(n+4) {
            display: none;
        }
        
        .btn-group-sm {
            flex-direction: column;
            width: 100%;
        }
        
        .btn-group-sm .btn {
            border-radius: 0.25rem;
            width: 100%;
            margin-bottom: 0.25rem;
        }
    }
</style>

<script>
function toggleDevPassword() {
    var field = document.getElementById('userPassword');
    var icon = document.getElementById('userPasswordIcon');
    if (!field || !icon) return;
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
