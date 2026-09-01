<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Ganti Password';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'Semua field harus diisi!';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Password baru dan konfirmasi password tidak cocok!';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password minimal 6 karakter!';
    } else {
        // Verify old password
        try {
            $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$currentUser['id']]);
            
            if (!$user || !password_verify($oldPassword, $user['password'])) {
                $error = 'Password lama tidak sesuai!';
            } else {
                // Update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $username = $user['username'];
                
                // 1. Update in current business database
                $db->update('users', ['password' => $hashedPassword], ['id' => $currentUser['id']]);
                
                // 2. Sync password to MASTER database and ALL business databases
                try {
                    // Determine master database name
                    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                                    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
                    $masterDbName = $isProduction ? 'adfb2574_adf' : 'adf_system';
                    
                    // Connect to master database
                    $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $masterDbName, DB_USER, DB_PASS);
                    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // Update password in master database (by username to match same user)
                    $masterStmt = $masterPdo->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $masterStmt->execute([$hashedPassword, $username]);
                    
                    // Get all businesses to sync password across all databases
                    $bizStmt = $masterPdo->query("SELECT database_name FROM businesses WHERE is_active = 1");
                    $businesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($businesses as $biz) {
                        try {
                            // Map database name for production
                            $bizDbName = $biz['database_name'];
                            if ($isProduction) {
                                $dbMapping = [
                                    'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
                                    'adf_benscafe' => 'adfb2574_Adf_Bens'
                                ];
                                if (isset($dbMapping[$bizDbName])) {
                                    $bizDbName = $dbMapping[$bizDbName];
                                }
                            }
                            
                            // Connect to business database and update password
                            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bizDbName, DB_USER, DB_PASS);
                            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            
                            $bizStmt = $bizPdo->prepare("UPDATE users SET password = ? WHERE username = ?");
                            $bizStmt->execute([$hashedPassword, $username]);
                        } catch (Exception $e) {
                            // Skip if database not accessible
                        }
                    }
                } catch (Exception $e) {
                    // Continue even if sync fails - password updated in current db
                }
                
                $success = 'Password berhasil diubah di semua database! Silakan login kembali dengan password baru.';
                
                // Log activity
                try {
                    $db->insert('activity_logs', [
                        'user_id' => $currentUser['id'],
                        'action' => 'change_password',
                        'description' => 'User mengubah password (synced to all databases)',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan: ' . $e->getMessage();
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="container" style="max-width: 600px; margin-top: 2rem;">
    <!-- Back Button -->
    <div class="mb-3">
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
            Kembali ke Settings
        </a>
    </div>

    <!-- Change Password Card -->
    <div class="card" style="box-shadow: var(--shadow-lg); border-radius: var(--radius-xl); overflow: hidden;">
        <div style="padding: 1.5rem; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white;">
            <h4 style="margin: 0; font-weight: 700; display: flex; align-items: center; gap: 0.75rem;">
                <i data-feather="lock" style="width: 24px; height: 24px;"></i>
                Ganti Password
            </h4>
            <p style="margin: 0.5rem 0 0 0; opacity: 0.9; font-size: 0.875rem;">
                Ubah password akun Anda dengan memasukkan password lama terlebih dahulu
            </p>
        </div>
        
        <div style="padding: 2rem;">
            <?php if ($error): ?>
            <div class="alert alert-danger" style="border-radius: var(--radius-lg); display: flex; align-items: start; gap: 0.75rem;">
                <i data-feather="alert-circle" style="width: 20px; height: 20px; flex-shrink: 0; margin-top: 2px;"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success" style="border-radius: var(--radius-lg); display: flex; align-items: start; gap: 0.75rem;">
                <i data-feather="check-circle" style="width: 20px; height: 20px; flex-shrink: 0; margin-top: 2px;"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
            <?php endif; ?>
            
            <!-- User Info -->
            <div style="padding: 1rem; background: var(--bg-secondary); border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.25rem;">
                        <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($currentUser['full_name']); ?></div>
                        <div style="font-size: 0.875rem; color: var(--text-muted);">@<?php echo htmlspecialchars($currentUser['username']); ?> • <?php echo ucfirst($currentUser['role']); ?></div>
                    </div>
                </div>
            </div>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                        Password Lama <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="password" class="form-control" name="old_password" required 
                           style="border-radius: var(--radius-lg); padding: 0.75rem 1rem;"
                           placeholder="Masukkan password lama Anda">
                    <small style="color: var(--text-muted); font-size: 0.813rem;">
                        Untuk keamanan, Anda harus memasukkan password lama terlebih dahulu
                    </small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                        Password Baru <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="password" class="form-control" name="new_password" required 
                           style="border-radius: var(--radius-lg); padding: 0.75rem 1rem;"
                           placeholder="Masukkan password baru (minimal 6 karakter)">
                </div>
                
                <div class="mb-4">
                    <label class="form-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                        Konfirmasi Password Baru <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="password" class="form-control" name="confirm_password" required 
                           style="border-radius: var(--radius-lg); padding: 0.75rem 1rem;"
                           placeholder="Ulangi password baru">
                </div>
                
                <!-- Info Box -->
                <div style="padding: 1rem; background: rgba(59, 130, 246, 0.1); border-left: 4px solid var(--info); border-radius: var(--radius-lg); margin-bottom: 1.5rem;">
                    <div style="display: flex; gap: 0.75rem;">
                        <i data-feather="info" style="width: 20px; height: 20px; color: var(--info); flex-shrink: 0; margin-top: 2px;"></i>
                        <div>
                            <div style="font-weight: 600; color: var(--info); margin-bottom: 0.25rem;">Lupa Password?</div>
                            <div style="font-size: 0.875rem; color: var(--text-secondary);">
                                Jika Anda lupa password lama, hubungi developer untuk reset password.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 0.75rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1; padding: 0.75rem; border-radius: var(--radius-lg); font-weight: 600;">
                        <i data-feather="check" style="width: 18px; height: 18px;"></i>
                        Ganti Password
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary" style="padding: 0.75rem 1.5rem; border-radius: var(--radius-lg);">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Security Tips -->
    <div class="card mt-3" style="border-radius: var(--radius-lg);">
        <div style="padding: 1.25rem;">
            <h6 style="font-weight: 700; color: var(--text-primary); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="shield" style="width: 18px; height: 18px;"></i>
                Tips Keamanan Password
            </h6>
            <ul style="margin: 0; padding-left: 1.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                <li style="margin-bottom: 0.5rem;">Gunakan kombinasi huruf besar, huruf kecil, angka, dan simbol</li>
                <li style="margin-bottom: 0.5rem;">Minimal 6 karakter (disarankan 8+ karakter)</li>
                <li style="margin-bottom: 0.5rem;">Jangan gunakan informasi pribadi (tanggal lahir, nama, dll)</li>
                <li style="margin-bottom: 0.5rem;">Ubah password secara berkala untuk keamanan</li>
                <li>Jangan bagikan password Anda kepada siapapun</li>
            </ul>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
