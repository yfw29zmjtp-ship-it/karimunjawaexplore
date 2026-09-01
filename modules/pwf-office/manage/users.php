<?php

/**
 * PWF Management - User Management
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Check access
$isOwner = isset($_SESSION['role']) && in_array($_SESSION['role'], ['owner', 'developer']);
if (!$isOwner) {
    http_response_code(403);
    echo 'Access Denied';
    exit;
}

$masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$msg = '';
$msgType = 'success';

// Get PWF business ID
$pwfBiz = $masterPdo->query("SELECT id FROM businesses WHERE business_name LIKE '%PWF%' OR business_name LIKE '%Prapen%' LIMIT 1")->fetch();
$pwfBizId = $pwfBiz['id'] ?? null;

// Get all available roles
$roles = $masterPdo->query("SELECT id, role_name, role_code FROM roles WHERE is_system_role = 1 ORDER BY id")->fetchAll();

// Get default staff role_id for new users
$staffRole = $masterPdo->query("SELECT id FROM roles WHERE role_code = 'staff' LIMIT 1")->fetch();
$staffRoleId = $staffRole['id'] ?? 1; // Default to 1 if not found

// Get users assigned to PWF business only (include role info)
$query = "SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.role_id, u.created_at, r.role_name, r.role_code
          FROM users u 
          INNER JOIN user_business_assignment uba ON u.id = uba.user_id
          LEFT JOIN roles r ON u.role_id = r.id
          WHERE uba.business_id = ? 
          ORDER BY u.id DESC";
$stmt = $masterPdo->prepare($query);
$stmt->execute([$pwfBizId]);
$users = $stmt->fetchAll();

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');

        if (empty($username) || empty($email) || empty($password)) {
            $msg = 'Username, email, dan password harus diisi';
            $msgType = 'error';
        } elseif (strlen($password) < 6) {
            $msg = 'Password minimal 6 karakter';
            $msgType = 'error';
        } else {
            // Check username/email globally (must be unique across entire system)
            $check = $masterPdo->prepare('SELECT id FROM users WHERE username=? OR email=?');
            $check->execute([$username, $email]);
            if ($check->fetch()) {
                $msg = 'Username atau email sudah terdaftar di sistem';
                $msgType = 'error';
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                // Insert with correct columns for master database: role_id and created_by are required
                $stmt = $masterPdo->prepare('
                    INSERT INTO users (username, email, password, full_name, role_id, is_active, created_by, created_at, updated_at) 
                    VALUES (?,?,?,?,?,1,?,NOW(),NOW())
                ');
                $insertResult = $stmt->execute([$username, $email, $hashed, $fullName, $staffRoleId, $_SESSION['user_id'] ?? null]);

                if ($insertResult) {
                    // Get newly created user ID
                    $userId = $masterPdo->lastInsertId();

                    // Assign user to PWF business
                    $assign = $masterPdo->prepare('INSERT INTO user_business_assignment (user_id, business_id) VALUES (?,?)');
                    if ($assign->execute([$userId, $pwfBizId])) {
                        $msg = 'User berhasil dibuat dan di-assign ke PWF!';
                        $msgType = 'success';
                        header('Refresh: 2');
                    } else {
                        // Delete user jika assignment gagal (rollback)
                        $masterPdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
                        $error = $assign->errorInfo();
                        $msg = 'Gagal assign user ke PWF: ' . ($error[2] ?? 'Unknown error');
                        $msgType = 'error';
                    }
                } else {
                    $error = $stmt->errorInfo();
                    $msg = 'Gagal membuat user: ' . ($error[2] ?? 'Unknown error');
                    $msgType = 'error';
                }
            }
        }
    } elseif ($action === 'toggle_active') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);
        $stmt = $masterPdo->prepare('UPDATE users SET is_active=?, updated_at=NOW() WHERE id=?');
        if ($stmt->execute([$isActive, $userId])) {
            $msg = $isActive ? 'User diaktifkan!' : 'User dinonaktifkan!';
            $msgType = 'success';
            header('Refresh: 1');
        }
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = 'Password123'; // Default password
        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $masterPdo->prepare('UPDATE users SET password=?, updated_at=NOW() WHERE id=?');
        if ($stmt->execute([$hashed, $userId])) {
            $msg = 'Password direset ke: Password123 (silakan minta user menggantinya)';
            $msgType = 'success';
        }
    } elseif ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        // Delete from user_business_assignment first (foreign key)
        $stmt = $masterPdo->prepare('DELETE FROM user_business_assignment WHERE user_id=?');
        $stmt->execute([$userId]);
        // Then delete from user_menu_permissions
        $stmt = $masterPdo->prepare('DELETE FROM user_menu_permissions WHERE user_id=?');
        $stmt->execute([$userId]);
        // Finally delete user
        $stmt = $masterPdo->prepare('DELETE FROM users WHERE id=?');
        if ($stmt->execute([$userId])) {
            $msg = 'User berhasil dihapus!';
            $msgType = 'success';
            header('Refresh: 1');
        }
    } elseif ($action === 'update_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);

        if ($userId > 0 && $roleId > 0) {
            $stmt = $masterPdo->prepare('UPDATE users SET role_id=?, updated_at=NOW() WHERE id=?');
            if ($stmt->execute([$roleId, $userId])) {
                // Get new role name for message
                $roleInfo = $masterPdo->prepare('SELECT role_name FROM roles WHERE id=?');
                $roleInfo->execute([$roleId]);
                $roleRow = $roleInfo->fetch();
                $roleName = $roleRow['role_name'] ?? 'Unknown';
                $msg = "Role user berhasil diubah menjadi: $roleName";
                $msgType = 'success';
                header('Refresh: 1');
            }
        }
    }
}

require_once __DIR__ . '/../layout.php';
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Kelola User - PWF Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #f5f3f0 0%, #faf9f7 100%);
            color: #1c1511;
        }

        .navbar {
            background: white;
            border-bottom: 1px solid #E7E5E4;
            padding: 14px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .04);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #1c1511;
            text-decoration: none;
        }

        .navbar-brand i {
            color: #B8860B;
            font-size: 20px;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .nav-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-btn-secondary {
            background: #F5F3F0;
            color: #1c1511;
        }

        .nav-btn-secondary:hover {
            background: #EAE8E5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 24px;
        }

        .header {
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }

        .header h1 i {
            color: #B8860B;
            font-size: 32px;
        }

        .btn-new {
            padding: 12px 22px;
            background: linear-gradient(135deg, #B8860B 0%, #D4A017 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all .2s;
            box-shadow: 0 2px 6px rgba(184, 134, 11, .2);
        }

        .btn-new:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(184, 134, 11, .3);
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .4);
            backdrop-filter: blur(2px);
            z-index: 9000;
            align-items: center;
            justify-content: center;
        }

        .modal.open {
            display: flex;
        }

        .modal-box {
            background: white;
            border-radius: 14px;
            width: min(520px, 96vw);
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
            border: 1px solid rgba(0, 0, 0, .05);
        }

        .modal-header {
            padding: 22px 28px;
            border-bottom: 1px solid #E7E5E4;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #999;
            transition: color .2s;
            line-height: 1;
        }

        .modal-close:hover {
            color: #B8860B;
        }

        .modal-body {
            padding: 28px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #E7E5E4;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all .2s;
        }

        input:focus {
            outline: none;
            border-color: #B8860B;
            box-shadow: 0 0 0 3px rgba(184, 134, 11, .1);
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 28px;
        }

        button {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all .2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #B8860B 0%, #D4A017 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(184, 134, 11, .2);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(184, 134, 11, .3);
        }

        .btn-secondary {
            background: #F5F3F0;
            color: #1c1511;
            border: 1px solid #E7E5E4;
        }

        .btn-secondary:hover {
            background: #EAE8E5;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 13px;
        }

        .alert.success {
            background: #F0FDF4;
            color: #166534;
            border: 1px solid #DCFCE7;
        }

        .alert.error {
            background: #FEF2F2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 24px;
        }

        .user-card {
            background: white;
            border: 1px solid #E7E5E4;
            border-radius: 12px;
            padding: 24px;
            transition: all .2s;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
        }

        .user-card:hover {
            border-color: #B8860B;
            box-shadow: 0 4px 16px rgba(184, 134, 11, .1);
            transform: translateY(-2px);
        }

        .user-card-header {
            margin-bottom: 16px;
        }

        .user-username {
            font-size: 16px;
            font-weight: 800;
            color: #1c1511;
            margin: 0 0 4px 0;
        }

        .user-email {
            font-size: 12px;
            color: #999;
            margin: 0;
            word-break: break-all;
        }

        .user-info {
            margin: 16px 0;
            padding: 12px;
            background: #F5F3F0;
            border-radius: 8px;
        }

        .user-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .user-info-row:last-child {
            margin-bottom: 0;
        }

        .user-label {
            color: #999;
            font-weight: 600;
        }

        .user-value {
            color: #1c1511;
            font-weight: 600;
        }

        .user-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .user-status.active {
            background: #F0FDF4;
            color: #166534;
        }

        .user-status.inactive {
            background: #FEF2F2;
            color: #991B1B;
        }

        .user-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .user-action-btn {
            flex: 1;
            min-width: 80px;
            padding: 8px 12px;
            border: 1px solid #E7E5E4;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            transition: all .2s;
            text-align: center;
        }

        .user-action-btn:hover {
            background: #F5F3F0;
            border-color: #B8860B;
            color: #B8860B;
        }

        .user-action-btn.danger {
            color: #991B1B;
            border-color: #FECACA;
        }

        .user-action-btn.danger:hover {
            background: #FEF2F2;
            border-color: #991B1B;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
    </style>
    <div class="navbar">
        <div class="navbar-left">
            <a href="index.php" class="navbar-brand">
                <i class="bi bi-gear-fill"></i>
                PWF Management
            </a>
        </div>
        <div class="navbar-right">
            <button class="nav-btn nav-btn-secondary" onclick="window.location.href='index.php'" title="Back to Menu">
                <i class="bi bi-arrow-left"></i> Kembali
            </button>
            <button class="nav-btn nav-btn-secondary" onclick="window.location.href='../dashboard.php'" title="Back to Dashboard">
                <i class="bi bi-house"></i> Dashboard
            </button>
        </div>
    </div>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><i class="bi bi-person-badge"></i>Kelola User</h1>
            <button class="btn-new" onclick="document.getElementById('createModal').classList.add('open')">+ Buat User Baru</button>
        </div>

        <?php if ($msg): ?>
            <div class="alert <?= $msgType ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($users)): ?>
            <div class="empty-state">
                <p>📭 Belum ada user terdaftar</p>
            </div>
        <?php else: ?>
            <div class="users-grid">
                <?php foreach ($users as $user): ?>
                    <div class="user-card">
                        <div class="user-card-header">
                            <h3 class="user-username"><?= htmlspecialchars($user['username']) ?></h3>
                            <p class="user-email"><?= htmlspecialchars($user['email']) ?></p>
                        </div>

                        <div class="user-info">
                            <div class="user-info-row">
                                <span class="user-label">Nama:</span>
                                <span class="user-value"><?= htmlspecialchars($user['full_name'] ?? '—') ?></span>
                            </div>
                            <div class="user-info-row">
                                <span class="user-label">Status:</span>
                                <span class="user-status <?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                    <?= $user['is_active'] ? '✓ Aktif' : '✗ Nonaktif' ?>
                                </span>
                            </div>
                            <div class="user-info-row">
                                <span class="user-label">Role:</span>
                                <span class="user-value"><?= htmlspecialchars($user['role_name'] ?? '—') ?></span>
                            </div>
                            <div class="user-info-row">
                                <span class="user-label">Dibuat:</span>
                                <span class="user-value"><?= date('d M Y', strtotime($user['created_at'])) ?></span>
                            </div>
                        </div>

                        <div class="user-actions">
                            <button type="button" class="user-action-btn" title="Edit role" onclick="openEditRoleModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['role_name'] ?? 'N/A') ?>', <?= $user['role_id'] ?? 0 ?>)">
                                👤 Edit Role
                            </button>

                            <form method="post" style="display: contents;">
                                <input type="hidden" name="_action" value="toggle_active">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="is_active" value="<?= $user['is_active'] ? 0 : 1 ?>">
                                <button type="submit" class="user-action-btn" title="<?= $user['is_active'] ? 'Nonaktifkan user' : 'Aktifkan user' ?>">
                                    <?= $user['is_active'] ? '🔒 Nonaktif' : '🔓 Aktif' ?>
                                </button>
                            </form>

                            <form method="post" style="display: contents;" onsubmit="return confirm('Reset password user ini ke Password123?');">
                                <input type="hidden" name="_action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <button type="submit" class="user-action-btn" title="Reset password ke Password123">
                                    🔑 Reset Pass
                                </button>
                            </form>

                            <form method="post" style="display: contents;" onsubmit="return confirm('Hapus user ini? Tidak bisa dibatalkan!');">
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <button type="submit" class="user-action-btn danger" title="Hapus user">
                                    🗑️ Hapus
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal Buat User -->
    <div class="modal" id="createModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Buat User Baru</h2>
                <button class="modal-close" onclick="document.getElementById('createModal').classList.remove('open')">×</button>
            </div>
            <div class="modal-body">
                <form method="post">
                    <input type="hidden" name="_action" value="create">

                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" minlength="6" required>
                        <div style="font-size: 11px; color: #999; margin-top: 4px;">Minimal 6 karakter</div>
                    </div>

                    <div class="form-group">
                        <label>Nama Lengkap</label>
                        <input type="text" name="full_name">
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn-primary">Buat User</button>
                        <button type="button" class="btn-secondary" onclick="document.getElementById('createModal').classList.remove('open')">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Edit Role -->
    <div class="modal" id="editRoleModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Edit Role User</h2>
                <button class="modal-close" onclick="document.getElementById('editRoleModal').classList.remove('open')">×</button>
            </div>
            <div class="modal-body">
                <form method="post">
                    <input type="hidden" name="_action" value="update_role">
                    <input type="hidden" name="user_id" id="editRoleUserId" value="">

                    <div class="form-group">
                        <label>User</label>
                        <input type="text" id="editRoleUserName" readonly style="background: #F5F3F0; cursor: not-allowed;">
                    </div>

                    <div class="form-group">
                        <label>Pilih Role Baru</label>
                        <select name="role_id" id="editRoleSelect" required style="width: 100%; padding: 12px 14px; border: 1px solid #E7E5E4; border-radius: 8px; font-size: 14px; font-family: inherit; transition: all .2s;">
                            <option value="">-- Pilih Role --</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn-primary">Ubah Role</button>
                        <button type="button" class="btn-secondary" onclick="document.getElementById('editRoleModal').classList.remove('open')">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditRoleModal(userId, userName, currentRoleId) {
            document.getElementById('editRoleUserId').value = userId;
            document.getElementById('editRoleUserName').value = userName;
            document.getElementById('editRoleSelect').value = currentRoleId;
            document.getElementById('editRoleModal').classList.add('open');
        }
    </script>