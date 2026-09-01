<?php
/**
 * Developer - Simplified User Management
 * Step-by-step user setup: Create User → Assign Business → Set Permissions
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../includes/auth.php';

// Check developer access (allow admin and developer roles)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'developer'])) {
    header('Location: ../login.php');
    exit;
}

$auth = new Auth();
$user = $_SESSION;

// Database connection
$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$activeStep = $_GET['step'] ?? 'users';
$selectedUserId = $_GET['user_id'] ?? null;

// =============================================
// STEP 1: USER LOGIN MANAGEMENT
// =============================================
if ($activeStep === 'users') {
    // Handle create/edit user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'save_user') {
            try {
                $userId = $_POST['user_id'] ?? null;
                $username = trim($_POST['username']) ?: null;
                $password = trim($_POST['password']) ?: null;
                $fullName = trim($_POST['full_name']) ?: null;
                $email = trim($_POST['email']) ?: null;
                $roleId = $_POST['role_id'] ?? null;
                
                if (!$username || !$fullName || !$email || !$roleId) {
                    throw new Exception('Username, name, email, dan role harus diisi!');
                }
                
                if ($userId) {
                    // UPDATE user
                    if ($password) {
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, password=?, full_name=?, role_id=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$username, $email, $hashedPassword, $fullName, $roleId, $userId]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, full_name=?, role_id=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$username, $email, $fullName, $roleId, $userId]);
                    }
                    $auth->logAction('update_user', 'users', $userId);
                    $_SESSION['success_message'] = '✅ User updated successfully!';
                } else {
                    // CREATE user
                    if (!$password) {
                        throw new Exception('Password harus diisi untuk user baru!');
                    }
                    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role_id, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$username, $email, $hashedPassword, $fullName, $roleId]);
                    $newUserId = $pdo->lastInsertId();
                    
                    // Create user preferences
                    $stmt = $pdo->prepare("INSERT INTO user_preferences (user_id, theme, language) VALUES (?, 'dark', 'id')");
                    $stmt->execute([$newUserId]);
                    
                    $auth->logAction('create_user', 'users', $newUserId);
                    $_SESSION['success_message'] = '✅ User created! Now assign business →';
                    $selectedUserId = $newUserId;
                }
            } catch (Exception $e) {
                $_SESSION['error_message'] = '❌ Error: ' . $e->getMessage();
            }
        } elseif ($action === 'delete_user') {
            try {
                $deleteUserId = $_POST['user_id'];
                if ((int)$deleteUserId === (int)$user['user_id']) {
                    throw new Exception('You cannot delete yourself!');
                }
                
                $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                
                // Reassign businesses
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM businesses WHERE owner_id = ?");
                $stmt->execute([$deleteUserId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result['count'] > 0) {
                    $stmt = $pdo->prepare("UPDATE businesses SET owner_id = ? WHERE owner_id = ?");
                    $stmt->execute([$user['user_id'], $deleteUserId]);
                }
                
                // Delete references
                $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ?")->execute([$deleteUserId]);
                $pdo->prepare("DELETE FROM user_preferences WHERE user_id = ?")->execute([$deleteUserId]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$deleteUserId]);
                
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                
                $auth->logAction('delete_user', 'users', $deleteUserId);
                $_SESSION['success_message'] = '✅ User deleted successfully!';
                $selectedUserId = null;
            } catch (Exception $e) {
                $_SESSION['error_message'] = '❌ Error: ' . $e->getMessage();
            }
        }
    }
    
    // Get all users
    $users = $pdo->query("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id ORDER BY u.username")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get selected user for edit
    $editUser = null;
    if ($selectedUserId) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$selectedUserId]);
        $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get all roles
    $roles = $pdo->query("SELECT * FROM roles WHERE is_system_role = 1 ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
}

// =============================================
// STEP 2: BUSINESS ASSIGNMENT
// =============================================
elseif ($activeStep === 'business') {
    if (!$selectedUserId) {
        $_SESSION['error_message'] = '❌ Pilih user dulu!';
        header('Location: user-setup-simple.php?step=users');
        exit;
    }
    
    // Handle business assignment
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        try {
            $businessId = $_POST['business_id'];
            $action = $_POST['action'];
            
            if ($action === 'assign') {
                // Assign business to user
                $stmt = $pdo->prepare("INSERT IGNORE INTO user_business_assignment (user_id, business_id) VALUES (?, ?)");
                $stmt->execute([$selectedUserId, $businessId]);
                $auth->logAction('assign_business', 'user_business_assignment', $selectedUserId);
                $_SESSION['success_message'] = '✅ Business assigned!';
            } else if ($action === 'remove') {
                // Remove business from user
                $stmt = $pdo->prepare("DELETE FROM user_business_assignment WHERE user_id = ? AND business_id = ?");
                $stmt->execute([$selectedUserId, $businessId]);
                $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?")->execute([$selectedUserId, $businessId]);
                $_SESSION['success_message'] = '✅ Business removed!';
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = '❌ Error: ' . $e->getMessage();
        }
    }
    
    // Get user info
    $stmt = $pdo->prepare("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$selectedUserId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get all businesses
    $allBusinesses = $pdo->query("SELECT id, business_name FROM businesses ORDER BY business_name")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get assigned businesses
    $stmt = $pdo->prepare("SELECT business_id FROM user_business_assignment WHERE user_id = ?");
    $stmt->execute([$selectedUserId]);
    $assignedBusinesses = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// =============================================
// STEP 3: PERMISSION SETUP
// =============================================
elseif ($activeStep === 'permissions') {
    if (!$selectedUserId) {
        $_SESSION['error_message'] = '❌ Pilih user dulu!';
        header('Location: user-setup-simple.php?step=users');
        exit;
    }
    
    // Handle permission updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        try {
            $businessId = $_POST['business_id'];
            $menuCode = $_POST['menu_code'];
            $permission = $_POST['permission'];
            
            $permissions = ['can_view' => 1, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
            if ($permission === 'view') $permissions = ['can_view' => 1, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
            if ($permission === 'create') $permissions = ['can_view' => 1, 'can_create' => 1, 'can_edit' => 1, 'can_delete' => 0];
            if ($permission === 'all') $permissions = ['can_view' => 1, 'can_create' => 1, 'can_edit' => 1, 'can_delete' => 1];
            
            // Delete existing permission for this specific menu, then insert
            $delStmt = $pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ? AND menu_code = ?");
            $delStmt->execute([$selectedUserId, $businessId, $menuCode]);
            
            $stmt = $pdo->prepare("INSERT INTO user_menu_permissions (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$selectedUserId, $businessId, $menuCode, 
                           $permissions['can_view'], $permissions['can_create'], $permissions['can_edit'], $permissions['can_delete']]);
            
            $_SESSION['success_message'] = '✅ Permission updated!';
        } catch (Exception $e) {
            $_SESSION['error_message'] = '❌ Error: ' . $e->getMessage();
        }
    }
    
    // Get user info
    $stmt = $pdo->prepare("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$selectedUserId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get assigned businesses
    $stmt = $pdo->prepare("SELECT id, business_name FROM businesses WHERE id IN (SELECT business_id FROM user_business_assignment WHERE user_id = ?)");
    $stmt->execute([$selectedUserId]);
    $userBusinesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Menu list - load from database
    $menuRows = $pdo->query("SELECT menu_code, menu_name FROM menu_items WHERE is_active = 1 ORDER BY menu_order")->fetchAll(PDO::FETCH_ASSOC);
    $menus = [];
    foreach ($menuRows as $mr) {
        $menus[$mr['menu_code']] = $mr['menu_name'];
    }
    if (empty($menus)) {
        // Fallback if menu_items table is empty
        $menus = ['dashboard' => 'Dashboard', 'cashbook' => 'Buku Kas Besar', 'divisions' => 'Kelola Divisi', 
            'frontdesk' => 'Frontdesk', 'sales_invoice' => 'Sales Invoice', 'procurement' => 'PO & SHOOP',
            'bills' => 'Tagihan', 'reports' => 'Reports', 'investor' => 'Investor', 'project' => 'Project',
            'cqc-projects' => 'CQC Projects', 'database' => 'Database Master', 'finance' => 'Manajemen Keuangan',
            'settings' => 'Pengaturan', 'payroll' => 'Payroll', 'owner' => 'Owner Monitoring'];
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Simplified Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI'; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 10px; }
        .steps { display: flex; gap: 10px; margin: 20px 0; }
        .step { padding: 12px 20px; background: #e0e0e0; border-radius: 4px; cursor: pointer; transition: all 0.3s; }
        .step.active { background: #2196F3; color: white; }
        .step:hover { background: #1976D2; color: white; }
        .content { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 30px; margin-top: 20px; }
        .list { max-height: 600px; overflow-y: auto; }
        .list-item { padding: 12px; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 8px; cursor: pointer; transition: all 0.3s; }
        .list-item:hover { background: #f0f0f0; border-color: #2196F3; }
        .list-item.selected { background: #2196F3; color: white; border-color: #2196F3; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; color: #333; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        input:focus, select:focus { outline: none; border-color: #2196F3; box-shadow: 0 0 0 2px rgba(33,150,243,0.1); }
        .password-wrapper { position: relative; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #666; padding: 5px; }
        .password-toggle:hover { color: #2196F3; }
        button { padding: 10px 20px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        button:hover { background: #45a049; }
        button.danger { background: #f44336; }
        button.danger:hover { background: #da190b; }
        button.secondary { background: #2196F3; }
        button.secondary:hover { background: #0b7dda; }
        .business-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
        .business-card { padding: 15px; border: 2px solid #ddd; border-radius: 6px; transition: all 0.3s; }
        .business-card.assigned { background: #c8e6c9; border-color: #4CAF50; }
        .business-card:hover { border-color: #2196F3; }
        .permission-row { display: grid; grid-template-columns: 1fr repeat(3, 80px); gap: 15px; padding: 12px; border-bottom: 1px solid #eee; align-items: center; }
        .permission-row:last-child { border-bottom: none; }
        .permission-select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .message { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>👤 User Management - Simplified Setup</h1>
            <p style="color: #666; margin-top: 5px;">3-step user configuration: Create User → Assign Business → Set Permissions</p>
            
            <!-- Banner untuk akses developer dashboard lengkap -->
            <div style="margin-top: 15px; padding: 12px 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
                <strong style="color: #856404;">💡 Tip:</strong> 
                <span style="color: #856404;">Ini hanya untuk setup user. Untuk akses <strong>Developer Dashboard lengkap</strong> dengan sidebar menu (Businesses, Permissions, Settings, dll), 
                <a href="index.php" style="color: #ff6b00; font-weight: 600; text-decoration: none;">klik ke Developer Dashboard →</a></span>
            </div>
        </div>
        
        <?php
        if (isset($_SESSION['success_message'])) {
            echo '<div class="message success">' . $_SESSION['success_message'] . '</div>';
            unset($_SESSION['success_message']);
        }
        if (isset($_SESSION['error_message'])) {
            echo '<div class="message error">' . $_SESSION['error_message'] . '</div>';
            unset($_SESSION['error_message']);
        }
        ?>
        
        <div class="steps">
            <div class="step <?php echo $activeStep === 'users' ? 'active' : ''; ?>" onclick="location.href='user-setup-simple.php?step=users'">
                1️⃣ User Login
            </div>
            <div class="step <?php echo $activeStep === 'business' ? 'active' : ''; ?>" onclick="if('<?=$selectedUserId?>') location.href='user-setup-simple.php?step=business&user_id=<?=$selectedUserId?>'; else alert('Pilih user dulu!')">
                2️⃣ Business Assignment
            </div>
            <div class="step <?php echo $activeStep === 'permissions' ? 'active' : ''; ?>" onclick="if('<?=$selectedUserId?>') location.href='user-setup-simple.php?step=permissions&user_id=<?=$selectedUserId?>'; else alert('Pilih user dulu!')">
                3️⃣ Permissions
            </div>
        </div>
        
        <div class="content">
            
            <?php if ($activeStep === 'users'): ?>
                <!-- STEP 1: USER LOGIN -->
                <h2>📋 Step 1: Create & Edit User Login</h2>
                
                <div class="grid">
                    <div class="list">
                        <h3 style="margin-bottom: 15px;">All Users</h3>
                        <?php foreach ($users as $u): ?>
                            <div class="list-item <?php echo $selectedUserId == $u['id'] ? 'selected' : ''; ?>" 
                                 onclick="location.href='user-setup-simple.php?step=users&user_id=<?=$u['id']?>'">
                                <strong><?php echo htmlspecialchars($u['username']); ?></strong><br>
                                <small><?php echo htmlspecialchars($u['full_name']); ?> (<?php echo htmlspecialchars($u['role_name']); ?>)</small>
                            </div>
                        <?php endforeach; ?>
                        <button onclick="location.href='user-setup-simple.php?step=users'" style="margin-top: 10px; width: 100%;">➕ New User</button>
                    </div>
                    
                    <div>
                        <h3><?php echo $editUser ? 'Edit User' : 'New User'; ?></h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_user">
                            <input type="hidden" name="user_id" value="<?php echo $editUser['id'] ?? ''; ?>">
                            
                            <div class="form-group">
                                <label>Username *</label>
                                <input type="text" name="username" value="<?php echo htmlspecialchars($editUser['username'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" value="<?php echo htmlspecialchars($editUser['full_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Email *</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Role *</label>
                                <select name="role_id" required>
                                    <option value="">-- Select Role --</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" <?php echo ($editUser['role_id'] ?? '') == $role['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($role['role_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label><?php echo $editUser ? 'New Password (leave empty to keep current)' : 'Password *'; ?></label>
                                <div class="password-wrapper">
                                    <input type="password" id="password_field" name="password" <?php echo $editUser ? '' : 'required'; ?>>
                                    <button type="button" class="password-toggle" onclick="togglePassword()">
                                        <svg id="eye_closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <svg id="eye_open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                            <line x1="1" y1="1" x2="23" y2="23"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 10px;">
                                <button type="submit">💾 <?php echo $editUser ? 'Update' : 'Create'; ?> User</button>
                                <?php if ($editUser): ?>
                                    <button type="button" class="danger" onclick="if(confirm('Delete user?')) { document.form_delete.submit(); }">🗑️ Delete</button>
                                    <form name="form_delete" method="POST" style="display:none;">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
                                    </form>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                
            <?php elseif ($activeStep === 'business'): ?>
                <!-- STEP 2: BUSINESS ASSIGNMENT -->
                <h2>🏢 Step 2: Assign Business to User</h2>
                <p style="color: #666; margin-bottom: 20px;">User: <strong><?php echo htmlspecialchars($editUser['username']); ?></strong></p>
                
                <div class="business-grid">
                    <?php foreach ($allBusinesses as $biz): ?>
                        <form method="POST" style="display:contents;">
                            <input type="hidden" name="action" value="<?php echo in_array($biz['id'], $assignedBusinesses) ? 'remove' : 'assign'; ?>">
                            <input type="hidden" name="business_id" value="<?php echo $biz['id']; ?>">
                            
                            <div class="business-card <?php echo in_array($biz['id'], $assignedBusinesses) ? 'assigned' : ''; ?>">
                                <p style="margin-bottom: 10px;">📌 <?php echo htmlspecialchars($biz['business_name']); ?></p>
                                <button type="submit" class="<?php echo in_array($biz['id'], $assignedBusinesses) ? 'danger' : 'secondary'; ?>" style="width: 100%;">
                                    <?php echo in_array($biz['id'], $assignedBusinesses) ? '❌ Remove' : '✅ Assign'; ?>
                                </button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 30px;">
                    <button class="secondary" onclick="location.href='user-setup-simple.php?step=permissions&user_id=<?=$selectedUserId?>'">Continue to Permissions →</button>
                </div>
                
            <?php elseif ($activeStep === 'permissions'): ?>
                <!-- STEP 3: PERMISSION SETUP -->
                <h2>🔐 Step 3: Set Menu Permissions</h2>
                <p style="color: #666; margin-bottom: 20px;">User: <strong><?php echo htmlspecialchars($editUser['username']); ?></strong></p>
                
                <?php if (empty($userBusinesses)): ?>
                    <p style="color: #f44336; font-weight: 600;">⚠️ User belum assign ke bisnis apapun! <a href="user-setup-simple.php?step=business&user_id=<?=$selectedUserId?>">Assign business dulu</a></p>
                <?php else: ?>
                    <?php foreach ($userBusinesses as $biz): ?>
                        <h3 style="margin-top: 25px; margin-bottom: 15px; color: #2196F3;">📌 <?php echo htmlspecialchars($biz['business_name']); ?></h3>
                        
                        <div style="border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden;">
                            <div class="permission-row" style="background: #f5f5f5; font-weight: 600; border-bottom: 2px solid #ddd;">
                                <div>Menu</div>
                                <div>View</div>
                                <div>Create</div>
                                <div>Delete</div>
                            </div>
                            
                            <?php
                            // Get current permissions for this business
                            $stmt = $pdo->prepare("SELECT * FROM user_menu_permissions WHERE user_id = ? AND business_id = ?");
                            $stmt->execute([$selectedUserId, $biz['id']]);
                            $currentPerms = $stmt->fetchAll(PDO::FETCH_KEY_PAIR, PDO::FETCH_ASSOC);
                            $permsByMenu = [];
                            foreach ($currentPerms as $perm) {
                                $permsByMenu[$perm['menu_code']] = $perm;
                            }
                            ?>
                            
                            <?php foreach ($menus as $menuCode => $menuName): ?>
                                <?php
                                $perm = $permsByMenu[$menuCode] ?? [];
                                $currentLevel = 'none';
                                if ($perm) {
                                    if ($perm['can_delete']) $currentLevel = 'all';
                                    elseif ($perm['can_create']) $currentLevel = 'create';
                                    elseif ($perm['can_view']) $currentLevel = 'view';
                                }
                                ?>
                                <form method="POST" style="display:contents;">
                                    <input type="hidden" name="action" value="update_permission">
                                    <input type="hidden" name="business_id" value="<?php echo $biz['id']; ?>">
                                    <input type="hidden" name="menu_code" value="<?php echo $menuCode; ?>">
                                    
                                    <div class="permission-row">
                                        <div><?php echo $menuName; ?></div>
                                        <div><input type="radio" name="permission" value="view" <?php echo $currentLevel === 'view' ? 'checked' : ''; ?> onchange="this.form.submit();"></div>
                                        <div><input type="radio" name="permission" value="create" <?php echo $currentLevel === 'create' ? 'checked' : ''; ?> onchange="this.form.submit();"></div>
                                        <div><input type="radio" name="permission" value="all" <?php echo $currentLevel === 'all' ? 'checked' : ''; ?> onchange="this.form.submit();"></div>
                                    </div>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div style="margin-top: 30px;">
                    <button class="secondary" onclick="location.href='user-setup-simple.php?step=users'">← Back to Users</button>
                    <button style="background: #4CAF50; margin-left: 10px;" onclick="location.href='../index.php'">✅ Done - Go to Dashboard</button>
                </div>
            <?php endif; ?>
            
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const field = document.getElementById('password_field');
            const eyeClosed = document.getElementById('eye_closed');
            const eyeOpen = document.getElementById('eye_open');
            
            if (field.type === 'password') {
                field.type = 'text';
                eyeClosed.style.display = 'none';
                eyeOpen.style.display = 'block';
            } else {
                field.type = 'password';
                eyeClosed.style.display = 'block';
                eyeOpen.style.display = 'none';
            }
        }
    </script>
    
    <!-- Developer Menu Navigation Footer -->
    <div style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #e0e0e0; text-align: center;">
        <p style="color: #666; margin-bottom: 15px;">
            <strong style="color: #333;">Atau akses menu developer lainnya:</strong>
        </p>
        <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
            <a href="index.php" style="padding: 10px 15px; background: #2196F3; color: white; text-decoration: none; border-radius: 4px; font-weight: 600;">📊 Dashboard</a>
            <a href="businesses.php" style="padding: 10px 15px; background: #FF9800; color: white; text-decoration: none; border-radius: 4px; font-weight: 600;">🏢 Manage Business</a>
            <a href="users.php" style="padding: 10px 15px; background: #9C27B0; color: white; text-decoration: none; border-radius: 4px; font-weight: 600;">👥 User Management (Old)</a>
            <a href="permissions.php" style="padding: 10px 15px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; font-weight: 600;">🔐 Permissions</a>
        </div>
        <p style="color: #999; font-size: 12px; margin-top: 15px;">Step-by-step user setup (simple) ✓ | Sidebar menu untuk fitur lain</p>
    </div>
        </div>
    </div>
</body>
</html>
