<?php

/**
 * PWF Management - Password Change
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

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $msg = 'Semua field harus diisi';
        $msgType = 'error';
    } elseif (strlen($newPassword) < 6) {
        $msg = 'Password minimal 6 karakter';
        $msgType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $msg = 'Password baru tidak cocok';
        $msgType = 'error';
    } else {
        // Get current user
        $userStmt = $masterPdo->prepare('SELECT password FROM users WHERE id=?');
        $userStmt->execute([$_SESSION['user_id']]);
        $user = $userStmt->fetch();

        if (!$user) {
            $msg = 'User tidak ditemukan';
            $msgType = 'error';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $msg = 'Password lama tidak sesuai';
            $msgType = 'error';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt = $masterPdo->prepare('UPDATE users SET password=?, updated_at=NOW() WHERE id=?');
            if ($updateStmt->execute([$hashedPassword, $_SESSION['user_id']])) {
                $msg = 'Password berhasil diubah!';
                $msgType = 'success';
            } else {
                $msg = 'Gagal mengubah password';
                $msgType = 'error';
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
    <title>Ganti Password - PWF Management</title>
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
            max-width: 600px;
            margin: 0 auto;
            padding: 40px 24px;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 32px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .06);
            border: 1px solid #E7E5E4;
        }

        .card h1 {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #1c1511;
        }

        .card h1 i {
            color: #B8860B;
            font-size: 32px;
        }

        .card p {
            color: #78716C;
            margin-bottom: 28px;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 22px;
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

        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #E7E5E4;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all .2s;
        }

        input[type="password"]:focus {
            outline: none;
            border-color: #B8860B;
            box-shadow: 0 0 0 3px rgba(184, 134, 11, .1);
        }

        .form-hint {
            font-size: 12px;
            color: #999;
            margin-top: 6px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 13px;
            display: flex;
            gap: 12px;
            align-items: center;
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

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 32px;
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
        <div class="card">
            <h1><i class="bi bi-key-fill"></i>Ganti Password</h1>
            <p>Ubah password login Anda untuk keamanan yang lebih baik</p>

            <?php if ($msg): ?>
                <div class="alert <?= $msgType ?>">
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label>Password Lama</label>
                    <input type="password" name="current_password" required>
                    <div class="form-hint">Masukkan password Anda saat ini</div>
                </div>

                <div class="form-group">
                    <label>Password Baru</label>
                    <input type="password" name="new_password" required minlength="6">
                    <div class="form-hint">Minimal 6 karakter</div>
                </div>

                <div class="form-group">
                    <label>Konfirmasi Password Baru</label>
                    <input type="password" name="confirm_password" required minlength="6">
                    <div class="form-hint">Ketik ulang password baru</div>
                </div>

                <div class="button-group">
                    <button type="submit" class="btn-primary">Ubah Password</button>
                    <a href="index.php" class="btn-secondary" style="text-decoration: none; display: flex; align-items: center;">Batal</a>
                </div>
            </form>
        </div>
    </div>
</body>

</html>