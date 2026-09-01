<?php

/**
 * OWNER LOGIN PAGE
 * Login khusus untuk Owner - akses dashboard monitoring bisnis
 */

define('APP_ACCESS', true);
require_once 'config/config.php';

// Check if database exists
try {
    $testConn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    unset($testConn);
} catch (PDOException $e) {
    header('Location: setup-required.html');
    exit;
}

require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'config/database.php';
require_once 'includes/business_helper.php';

$auth = new Auth();
$db = Database::getInstance();

// ============================================
// REMEMBER ME - Auto-login via HMAC token
// ============================================
$cookiePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$ownerRememberSecret = hash('sha256', DB_PASS . DB_NAME . '__adf_owner_remember_salt__');

function generateOwnerRememberToken($userId, $secret)
{
    $expiry = time() + (30 * 24 * 60 * 60); // 30 days
    $payload = $userId . ':' . $expiry;
    $hmac = hash_hmac('sha256', $payload, $secret);
    return base64_encode($payload . ':' . $hmac);
}

function validateOwnerRememberToken($token, $secret)
{
    $decoded = base64_decode($token, true);
    if (!$decoded) return false;
    $parts = explode(':', $decoded);
    if (count($parts) !== 3) return false;
    [$userId, $expiry, $hmac] = $parts;
    if (!is_numeric($userId) || !is_numeric($expiry)) return false;
    if (time() > (int)$expiry) return false;
    $expected = hash_hmac('sha256', $userId . ':' . $expiry, $secret);
    if (!hash_equals($expected, $hmac)) return false;
    return (int)$userId;
}

// Check auto-login token BEFORE showing login form
if (!empty($_COOKIE['adf_owner_remember_token']) && !$auth->isLoggedIn() && !isPost()) {
    $tokenUserId = validateOwnerRememberToken($_COOKIE['adf_owner_remember_token'], $ownerRememberSecret);
    if ($tokenUserId) {
        try {
            $masterDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $stmt = $masterDb->prepare("SELECT u.id, u.username, u.full_name, u.is_active, r.role_code, u.business_access 
                FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND u.is_active = 1");
            $stmt->execute([$tokenUserId]);
            $tokenUser = $stmt->fetch();

            if ($tokenUser && in_array($tokenUser['role_code'], ['owner', 'admin', 'developer'])) {
                // Set session
                if (session_status() === PHP_SESSION_NONE) session_start();
                $_SESSION['user_id'] = $tokenUser['id'];
                $_SESSION['username'] = $tokenUser['username'];
                $_SESSION['full_name'] = $tokenUser['full_name'] ?? $tokenUser['username'];
                $_SESSION['role'] = $tokenUser['role_code'];
                $_SESSION['business_access'] = $tokenUser['business_access'] ?? 'all';
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();

                // Refresh token
                $newToken = generateOwnerRememberToken($tokenUser['id'], $ownerRememberSecret);
                setcookie('adf_owner_remember_token', $newToken, time() + (30 * 24 * 60 * 60), $cookiePath, '', $isSecure, true);

                // Set business and redirect
                require_once __DIR__ . '/includes/business_access.php';
                $ownerBizList = getUserAvailableBusinesses();
                if (empty($ownerBizList)) {
                    setcookie('adf_owner_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
                    $error = 'Akun owner ini belum memiliki akses bisnis yang diatur di Developer.';
                } else {
                    // Use user's business_access as default if it's a valid slug
                    $tokenUserBiz = trim($tokenUser['business_access'] ?? '');
                    if ($tokenUserBiz && $tokenUserBiz !== 'all' && !str_starts_with($tokenUserBiz, '[') && isset($ownerBizList[$tokenUserBiz])) {
                        setActiveBusinessId($tokenUserBiz);
                    } else {
                        setActiveBusinessId(array_key_first($ownerBizList));
                    }
                    header('Location: ' . BASE_URL . '/modules/owner/dashboard-2028.php');
                    exit;
                }
            }
        } catch (Exception $e) {
            error_log("Owner remember token auto-login failed: " . $e->getMessage());
        }
        // Invalid token - clear cookie
        setcookie('adf_owner_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
    } else {
        setcookie('adf_owner_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
    }
}

// If already logged in as owner, redirect to dashboard
if ($auth->isLoggedIn()) {
    $currentUser = $auth->getCurrentUser();
    if (in_array($currentUser['role'], ['owner', 'admin', 'developer'])) {
        redirect(BASE_URL . '/modules/owner/dashboard-2028.php');
    } else {
        session_destroy();
    }
}

$error = '';
$savedUser = '';
if (!empty($_COOKIE['adf_owner_saved_user'])) {
    $savedUser = base64_decode($_COOKIE['adf_owner_saved_user']);
}

// Handle login form submission
if (isPost()) {
    $username = sanitize(getPost('username'));
    $password = getPost('password');
    $rememberMe = isset($_POST['remember_me']);

    if (empty($username)) {
        $error = 'Username harus diisi!';
    } else {
        // Connect to MASTER database for authentication
        try {
            $masterDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            $sql = "SELECT u.id, u.username, u.password, u.full_name, u.is_active, r.role_code, u.business_access 
                    FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = :username";
            $stmt = $masterDb->prepare($sql);
            $stmt->execute([':username' => $username]);
            $currentUser = $stmt->fetch();

            if (!$currentUser) {
                $error = 'Username tidak ditemukan!';
            } else if ($currentUser['is_active'] == 0) {
                $error = 'Akun Anda tidak aktif!';
            } else if (!in_array($currentUser['role_code'], ['owner', 'admin', 'developer'])) {
                $error = 'Anda bukan owner! Akses ditolak.';
            } else if (!password_verify($password, $currentUser['password']) && md5($password) !== $currentUser['password']) {
                $error = 'Password salah!';
            } else {
                // Check if owner has business_access
                $businessAccess = $currentUser['business_access'] ?? null;

                if (empty($businessAccess)) {
                    $error = 'Anda belum memiliki akses ke bisnis manapun. Hubungi administrator.';
                } else {
                    // Owner login successful - set session
                    if (session_status() === PHP_SESSION_NONE) session_start();
                    $_SESSION['user_id'] = $currentUser['id'];
                    $_SESSION['username'] = $currentUser['username'];
                    $_SESSION['full_name'] = $currentUser['full_name'] ?? $currentUser['username'];
                    $_SESSION['role'] = $currentUser['role_code'];
                    $_SESSION['business_access'] = $businessAccess;
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();

                    // Handle remember me cookies
                    if ($rememberMe) {
                        $cookieExpiry = time() + (30 * 24 * 60 * 60);
                        setcookie('adf_owner_saved_user', base64_encode($username), $cookieExpiry, $cookiePath, '', $isSecure, true);
                        $token = generateOwnerRememberToken($currentUser['id'], $ownerRememberSecret);
                        setcookie('adf_owner_remember_token', $token, $cookieExpiry, $cookiePath, '', $isSecure, true);
                    } else {
                        setcookie('adf_owner_saved_user', '', time() - 3600, $cookiePath, '', $isSecure, true);
                        setcookie('adf_owner_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
                    }

                    // Set business and redirect
                    require_once __DIR__ . '/includes/business_access.php';
                    $ownerBizList = getUserAvailableBusinesses();
                    if (empty($ownerBizList)) {
                        session_destroy();
                        $error = 'Akun owner ini belum memiliki akses bisnis yang diatur di Developer.';
                    } else {
                        // Prefer the user's own business_access field if it maps to a valid slug
                        $preferredBiz = trim($businessAccess ?? '');
                        if ($preferredBiz && $preferredBiz !== 'all' && !str_starts_with($preferredBiz, '[') && isset($ownerBizList[$preferredBiz])) {
                            // business_access is directly a valid slug (e.g., 'sunsea')
                            setActiveBusinessId($preferredBiz);
                        } else {
                            // Fall back to first assignment in user_business_assignment (ordered by insertion)
                            $firstBiz = array_key_first($ownerBizList);
                            setActiveBusinessId($firstBiz);
                        }
                        redirect(BASE_URL . '/modules/owner/dashboard-2028.php');
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
            error_log("Owner login error: " . $e->getMessage());
        }
    }
}

// Get available businesses for display
$availableBusinesses = getAvailableBusinesses();

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Owner Login - <?php echo APP_NAME; ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">

    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
        }

        .login-box {
            background: white;
            border-radius: 15px;
            padding: 3rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 420px;
            position: relative;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
        }

        .login-title {
            font-size: 24px;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .login-subtitle {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            color: #333;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 14px;
        }

        .alert-danger {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ef5350;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e0e0e0;
            font-size: 13px;
            color: #666;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .businesses-info {
            background: #f5f7fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 13px;
        }

        .businesses-info strong {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .businesses-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .businesses-list li {
            padding: 4px 0;
            color: #555;
        }

        .businesses-list li:before {
            content: "✓ ";
            color: #667eea;
            font-weight: 600;
            margin-right: 6px;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div>
            <div class="login-box">
                <div class="login-header">
                    <span class="login-icon">👔</span>
                    <h1 class="login-title">Owner Login</h1>
                    <p class="login-subtitle">Dashboard Monitoring Bisnis</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="businesses-info">
                    <strong>📊 Bisnis Tersedia:</strong>
                    <ul class="businesses-list">
                        <?php foreach ($availableBusinesses as $biz): ?>
                            <li><?php echo htmlspecialchars($biz['name']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label" for="username">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            placeholder="Masukkan username"
                            value="<?= htmlspecialchars($savedUser) ?>"
                            autocomplete="username"
                            required
                            autofocus>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required>
                    </div>

                    <div class="form-group" style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="rememberMe" name="remember_me" value="1" <?= isset($_COOKIE['adf_owner_remember_token']) ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: #667eea;">
                        <label for="rememberMe" style="margin: 0; font-size: 13px; color: #555; cursor: pointer;">
                            <?= isset($_COOKIE['adf_owner_remember_token']) ? '✅ Auto Login Aktif' : '🔒 Ingat Saya (Auto Login)' ?>
                        </label>
                    </div>

                    <button type="submit" class="btn-login">🔓 Login</button>
                </form>

                <div class="login-footer">
                    Hanya untuk pemilik bisnis yang terdaftar
                </div>
            </div>

            <a href="<?php echo BASE_URL; ?>/home.php" class="back-link">← Kembali ke Halaman Utama</a>
        </div>
    </div>
</body>

</html>