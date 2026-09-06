<?php

/**
 * Master DB user role manager - gates access to the root "Owner Login" button
 * (login.php / owner-login.php check role_code in the MASTER database, which
 * is separate from each business's own users/roles tables e.g. adf_sunsea).
 * Developer-only tool.
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
if (($currentUser['role'] ?? '') !== 'developer') {
    die('Akses ditolak. Hanya role Developer yang bisa membuka tool ini.');
}

$masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$flashMsg = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $roleId = (int)($_POST['role_id'] ?? 0);
    $validRoleIds = array_map('intval', $masterPdo->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN));
    if ($uid > 0 && in_array($roleId, $validRoleIds, true)) {
        $masterPdo->prepare("UPDATE users SET role_id = ?, updated_at = NOW() WHERE id = ?")->execute([$roleId, $uid]);
        $flashMsg = 'Role user berhasil diubah di master database (adf_system). "Owner Login" akan mengenali role ini.';
        $flashType = 'success';
    } else {
        $flashMsg = 'Role tidak valid.';
        $flashType = 'error';
    }
}

$roles = $masterPdo->query("SELECT id, role_name, role_code FROM roles ORDER BY id")->fetchAll();
$roleNameById = [];
foreach ($roles as $r) {
    $roleNameById[$r['id']] = $r['role_name'] . ' (' . $r['role_code'] . ')';
}
$users = $masterPdo->query("SELECT id, username, full_name, role_id, is_active FROM users ORDER BY username")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Master DB - Atur Role User (Owner Login)</title>
<style>
body{font-family:Arial,sans-serif;background:#f8fafc;padding:24px;color:#1e293b;}
.box{max-width:800px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;}
h2{margin-top:0;font-size:18px;}
.note{background:#FFF7ED;border:1px solid #FDE4CC;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{padding:8px 10px;border-bottom:1px solid #e2e8f0;text-align:left;}
select{padding:4px 6px;}
.flash{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;}
.flash.success{background:#ECFDF5;color:#065F46;border:1px solid #A7F3D0;}
.flash.error{background:#FEF2F2;color:#991B1B;border:1px solid #FECACA;}
</style>
</head>
<body>
<div class="box">
    <h2>Master Database - Atur Role User</h2>
    <div class="note">
        Tool ini mengubah role di database master (<code><?php echo htmlspecialchars(DB_NAME); ?></code>), yang dipakai
        oleh tombol <strong>"Owner Login"</strong> di halaman login utama / <code>owner-login.php</code>.
        Ini <strong>berbeda</strong> dari role di Pengaturan modul Sunsea (yang hanya mengatur akses "Owner Dashboard" Sunsea).
    </div>
    <?php if ($flashMsg): ?>
        <div class="flash <?php echo $flashType; ?>"><?php echo htmlspecialchars($flashMsg); ?></div>
    <?php endif; ?>
    <table>
        <tr><th>Username</th><th>Nama</th><th>Role Saat Ini</th><th>Ubah Role</th></tr>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?php echo htmlspecialchars($u['username']); ?></td>
            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
            <td><?php echo htmlspecialchars($roleNameById[$u['role_id']] ?? '-'); ?></td>
            <td>
                <form method="POST" style="display:flex;gap:6px;">
                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                    <select name="role_id">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>" <?php echo ($r['id'] == $u['role_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Simpan</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
</body>
</html>
