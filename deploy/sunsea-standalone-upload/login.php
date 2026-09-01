<?php

/**
 * ADF SYSTEM - Multi Business Management
 * Login Page
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

$auth = new Auth();
$db = Database::getInstance();

// Get custom login background from settings (with error handling)
$customBg = null;
$bgUrl = null;
$loginLogo = null;
$loginLogoUrl = null;
$faviconUrl = null;
try {
    require_once __DIR__ . '/includes/CloudinaryHelper.php';
    $cl = CloudinaryHelper::getInstance();

    $loginBgSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_background'");
    $customBg = $loginBgSetting['setting_value'] ?? null;
    $bgUrl = $customBg ? $cl->getDisplayUrl($customBg, 'uploads/backgrounds/') : null;

    // Fallback: use Cloudinary hero background if no custom background set
    if (!$bgUrl) {
        $bgUrl = 'https://res.cloudinary.com/dpdmut9ls/image/upload/v1772739188/adf_system/website/hero/ombs61riq165vcwenxy1.png';
    }

    $loginLogoSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'login_logo'");
    $loginLogo = $loginLogoSetting['setting_value'] ?? null;
    $loginLogoUrl = $loginLogo ? $cl->getDisplayUrl($loginLogo, 'uploads/logos/') : null;

    $faviconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'site_favicon'");
    $faviconFile = $faviconSetting['setting_value'] ?? null;
    $faviconUrl = $faviconFile ? $cl->getDisplayUrl($faviconFile, 'uploads/icons/') : null;

    // Get demo credentials from settings
    $demoUsernameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_username'");
    $demoUsername = $demoUsernameSetting['setting_value'] ?? 'admin';

    $demoPasswordSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'demo_password'");
    $demoPassword = $demoPasswordSetting['setting_value'] ?? 'admin';
} catch (Exception $e) {
    // Settings table might not exist yet, continue without background
}

// ============================================
// REMEMBER ME - Auto-login via HMAC token
// ============================================
$cookiePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$rememberSecret = hash('sha256', DB_PASS . DB_NAME . '__adf_remember_salt__');

function generateRememberToken($userId, $secret)
{
    $expiry = time() + (30 * 24 * 60 * 60); // 30 days
    $payload = $userId . ':' . $expiry;
    $hmac = hash_hmac('sha256', $payload, $secret);
    return base64_encode($payload . ':' . $hmac);
}

function validateRememberToken($token, $secret)
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
$savedUser = '';
$isRemembered = false;
if (!empty($_COOKIE['adf_remember_token']) && !$auth->isLoggedIn() && !isPost()) {
    $tokenUserId = validateRememberToken($_COOKIE['adf_remember_token'], $rememberSecret);
    if ($tokenUserId) {
        // Valid token - auto-login this user
        try {
            $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $masterPdo->prepare("SELECT id, username, full_name, role_id, business_access, is_active FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$tokenUserId]);
            $tokenUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tokenUser) {
                // Get role code
                $roleCode = 'staff';
                try {
                    $roleStmt = $masterPdo->prepare("SELECT role_code FROM roles WHERE id = ?");
                    $roleStmt->execute([$tokenUser['role_id']]);
                    $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);
                    $roleCode = $roleData['role_code'] ?? 'staff';
                } catch (Exception $e) {
                }

                // Set session
                if (session_status() === PHP_SESSION_NONE) session_start();
                $_SESSION['user_id'] = $tokenUser['id'];
                $_SESSION['username'] = $tokenUser['username'];
                $_SESSION['full_name'] = $tokenUser['full_name'];
                $_SESSION['role'] = $roleCode;
                $_SESSION['business_access'] = $tokenUser['business_access'] ?? 'all';
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                $_SESSION['user_theme'] = 'dark';
                $_SESSION['user_language'] = 'id';

                // Refresh token (extend expiry)
                $newToken = generateRememberToken($tokenUser['id'], $rememberSecret);
                setcookie('adf_remember_token', $newToken, time() + (30 * 24 * 60 * 60), $cookiePath, '', $isSecure, true);

                // Set business and redirect
                require_once 'includes/business_helper.php';
                require_once __DIR__ . '/includes/business_access.php';

                if (in_array($roleCode, ['owner', 'admin', 'developer'])) {
                    $ownerBizList = getUserAvailableBusinesses();
                    if (!empty($ownerBizList)) {
                        setActiveBusinessId(getPreferredDefaultBusiness($ownerBizList));
                    }
                    header('Location: ' . BASE_URL . '/modules/owner/dashboard-2028.php');
                    exit;
                } else {
                    // Normal user - set first business
                    try {
                        $bizStmt = $masterPdo->prepare("
                            SELECT DISTINCT b.id, b.business_code 
                            FROM businesses b
                            LEFT JOIN user_business_assignment uba ON b.id = uba.business_id AND uba.user_id = ?
                            WHERE b.is_active = 1
                            ORDER BY uba.user_id DESC, b.business_name
                            LIMIT 1
                        ");
                        $bizStmt->execute([$tokenUser['id']]);
                        $firstBiz = $bizStmt->fetch(PDO::FETCH_ASSOC);
                        if ($firstBiz) {
                            $_SESSION['business_id'] = (int)$firstBiz['id'];
                            $slug = strtolower(str_replace('_', '-', $firstBiz['business_code']));
                            setActiveBusinessId($slug);
                        }
                    } catch (Exception $e) {
                    }
                    redirect(BASE_URL . '/index.php');
                }
            }
        } catch (Exception $e) {
            // Token valid but DB error - clear token
            error_log("Remember token auto-login failed: " . $e->getMessage());
        }
        // If we get here, token was invalid or user not found - clear cookie
        setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
    } else {
        // Invalid/expired token - clear cookie
        setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
    }
}

// Pre-fill username from cookie (for display only)
if (!empty($_COOKIE['adf_saved_user'])) {
    $savedUser = base64_decode($_COOKIE['adf_saved_user']);
    $isRemembered = true;
}

// If already logged in, redirect to dashboard
// But allow POST login_type=owner to re-login as owner
if ($auth->isLoggedIn() && !isPost()) {
    // If user role is owner/admin/developer, go to owner dashboard
    $currentRole = $_SESSION['role'] ?? '';
    if (in_array($currentRole, ['owner', 'admin', 'developer'])) {
        redirect(BASE_URL . '/modules/owner/dashboard-2028.php');
    } else {
        redirect(BASE_URL . '/index.php');
    }
}

// Handle login form submission
if (isPost()) {
    $username = sanitize(getPost('username'));
    $password = getPost('password');
    $rememberMe = isset($_POST['remember_me']);
    $loginType = getPost('login_type') ?? 'normal'; // owner or normal

    // Handle remember me - save username cookie (token set after successful login)
    if ($rememberMe && $username) {
        $cookieExpiry = time() + (30 * 24 * 60 * 60); // 30 days
        setcookie('adf_saved_user', base64_encode($username), $cookieExpiry, $cookiePath, '', $isSecure, true);
    } else {
        // Clear all remember cookies
        setcookie('adf_saved_user', '', time() - 3600, $cookiePath, '', $isSecure, true);
        setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
        setcookie('adf_remember', '', time() - 3600, $cookiePath, '', $isSecure, true);
        setcookie('adf_saved_cred', '', time() - 3600, $cookiePath, '', $isSecure, true);
    }

    // Check if business specified via URL parameter
    $forcedBusiness = isset($_GET['biz']) ? sanitize($_GET['biz']) : null;

    if ($auth->login($username, $password)) {
        $currentUser = $auth->getCurrentUser();

        // Set remember-me auto-login token cookie
        if ($rememberMe) {
            $userId = $currentUser['id'] ?? $_SESSION['user_id'] ?? 0;
            if ($userId) {
                $token = generateRememberToken($userId, $rememberSecret);
                setcookie('adf_remember_token', $token, time() + (30 * 24 * 60 * 60), $cookiePath, '', $isSecure, true);
            }
        }

        // Auto-detect user's accessible businesses
        require_once 'includes/business_helper.php';

        try {
            // Connect to master database (DB_NAME is correct for current environment)
            $masterDbName = DB_NAME;
            $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Get user ID and role from master
            $userStmt = $masterPdo->prepare("SELECT u.id, u.role_id, r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = ?");
            $userStmt->execute([$username]);
            $masterUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$masterUser) {
                $error = 'Pengguna tidak terdaftar di sistem! Hubungi pengembang untuk mengatur akses.';
                $auth->logout();
            } else {
                $masterId = $masterUser['id'];
                $roleCode = $masterUser['role_code'];

                // Build dynamic business code <-> slug mappings from DB
                // Auto-add slug column if missing
                try {
                    $colCheck = $masterPdo->query("SHOW COLUMNS FROM businesses LIKE 'slug'")->fetchAll();
                    if (empty($colCheck)) {
                        $masterPdo->exec("ALTER TABLE businesses ADD COLUMN slug VARCHAR(100) AFTER business_code");
                    }
                } catch (Exception $e) {
                }

                $allBizRows = $masterPdo->query("SELECT id, business_code, slug, database_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
                $codeToSlugMap = []; // BENSCAFE => bens-cafe
                $slugToCodeMap = []; // bens-cafe => BENSCAFE
                $bizIdToSlugMap = []; // 4 => eat-meet

                // Known overrides first
                $knownSlugs = ['BENSCAFE' => 'bens-cafe', 'NARAYANAHOTEL' => 'narayana-hotel', 'DEMO' => 'demo'];

                foreach ($allBizRows as $br) {
                    // Determine slug: use DB slug column if set, then known overrides, then derive
                    if (!empty($br['slug'])) {
                        $slug = $br['slug'];
                    } elseif (isset($knownSlugs[$br['business_code']])) {
                        $slug = $knownSlugs[$br['business_code']];
                    } else {
                        $slug = strtolower(str_replace('_', '-', $br['business_code']));
                    }

                    // Auto-populate slug in DB if empty
                    if (empty($br['slug'])) {
                        try {
                            $masterPdo->prepare("UPDATE businesses SET slug = ? WHERE id = ?")->execute([$slug, $br['id']]);
                        } catch (Exception $e) {
                        }
                    }

                    $codeToSlugMap[$br['business_code']] = $slug;
                    $slugToCodeMap[$slug] = $br['business_code'];
                    $bizIdToSlugMap[$br['id']] = $slug;
                }

                // Check if owner login requested
                if ($loginType === 'owner') {
                    // Only owner, admin, developer can access owner dashboard
                    if (in_array($roleCode, ['owner', 'admin', 'developer'])) {
                        $_SESSION['role'] = $roleCode;
                        // Set active business to user's first assigned business
                        require_once __DIR__ . '/includes/business_access.php';
                        $ownerBizList = getUserAvailableBusinesses();
                        if (!empty($ownerBizList)) {
                            $firstOwnerBiz = getPreferredDefaultBusiness($ownerBizList);
                            setActiveBusinessId($firstOwnerBiz);
                        }
                        setFlash('success', 'Login Owner berhasil!');
                        header('Location: ' . BASE_URL . '/modules/owner/dashboard-2028.php');
                        exit;
                    } else {
                        $error = 'Akses ditolak! Hanya Pemilik yang dapat mengakses Dasbor Pemilik.';
                        $auth->logout();
                    }
                }

                // Developer role has full access to all businesses
                if ($roleCode === 'developer') {
                    if ($forcedBusiness) {
                        setActiveBusinessId($forcedBusiness);
                    } else {
                        // Default to narayana-hotel if available
                        $allBiz = getAvailableBusinesses();
                        $firstBiz = getPreferredDefaultBusiness($allBiz);
                        setActiveBusinessId($firstBiz);
                    }
                    setFlash('success', 'Login berhasil! Developer mode aktif.');
                    redirect(BASE_URL . '/index.php');
                }

                // Get businesses user has access to (check both user_menu_permissions and user_business_assignment)
                $userBusinesses = [];

                // Try user_business_assignment first (newer system)
                try {
                    $bizStmt = $masterPdo->prepare("
                        SELECT DISTINCT b.id, b.business_code, b.business_name
                        FROM businesses b
                        JOIN user_business_assignment uba ON b.id = uba.business_id
                        WHERE uba.user_id = ? AND b.is_active = 1
                        ORDER BY b.business_name
                    ");
                    $bizStmt->execute([$masterId]);
                    $userBusinesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                }

                // Fallback: try user_menu_permissions (legacy)
                if (empty($userBusinesses)) {
                    try {
                        $bizStmt = $masterPdo->prepare("
                            SELECT DISTINCT b.id, b.business_code, b.business_name
                            FROM businesses b
                            JOIN user_menu_permissions p ON b.id = p.business_id
                            WHERE p.user_id = ? AND b.is_active = 1
                            ORDER BY b.business_name
                        ");
                        $bizStmt->execute([$masterId]);
                        $userBusinesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                    }
                }

                // Final fallback: if user has no assignments, get all active businesses
                if (empty($userBusinesses)) {
                    try {
                        $bizStmt = $masterPdo->query("SELECT id, business_code, business_name FROM businesses WHERE is_active = 1 ORDER BY business_name");
                        $userBusinesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                    }
                }

                if (empty($userBusinesses)) {
                    $error = 'Anda tidak memiliki akses ke bisnis manapun! Hubungi pengembang.';
                    $auth->logout();
                } elseif ($forcedBusiness) {
                    // Direct link with business parameter - validate access
                    $forcedBizCode = isset($slugToCodeMap[$forcedBusiness]) ? $slugToCodeMap[$forcedBusiness] : strtoupper(str_replace('-', '_', $forcedBusiness));
                    $hasAccess = false;

                    foreach ($userBusinesses as $biz) {
                        if ($biz['business_code'] === $forcedBizCode) {
                            $hasAccess = true;
                            break;
                        }
                    }

                    if ($hasAccess) {
                        // Find the numeric business ID from the matched business
                        foreach ($userBusinesses as $biz) {
                            if ($biz['business_code'] === $forcedBizCode) {
                                $_SESSION['business_id'] = (int)$biz['id']; // Set numeric business_id
                                break;
                            }
                        }
                        setActiveBusinessId($forcedBusiness);
                        setFlash('success', 'Login berhasil!');
                        redirect(BASE_URL . '/index.php');
                    } else {
                        $error = 'Anda tidak memiliki akses ke bisnis tersebut!';
                        $auth->logout();
                    }
                } else {
                    // One or multiple businesses - auto login to first business
                    $bizCode = $userBusinesses[0]['business_code'];
                    $businessId = isset($codeToSlugMap[$bizCode]) ? $codeToSlugMap[$bizCode] : strtolower(str_replace('_', '-', $bizCode));
                    $_SESSION['business_id'] = (int)$userBusinesses[0]['id']; // Set numeric business_id
                    setActiveBusinessId($businessId);

                    if (count($userBusinesses) === 1) {
                        setFlash('success', 'Login berhasil! Selamat datang ke ' . $userBusinesses[0]['business_name']);
                    } else {
                        setFlash('success', 'Login berhasil! Anda bisa switch bisnis melalui dropdown di sidebar.');
                    }

                    redirect(BASE_URL . '/index.php');
                }
            }
        } catch (PDOException $e) {
            error_log('Login business check error: ' . $e->getMessage());
            $error = 'Terjadi kesalahan sistem. Silakan coba lagi.';
            $auth->logout();
        }
    } else {
        $error = 'Nama pengguna atau kata sandi tidak tepat!';
    }
}

// Check if redirected from account removal
if (isset($_GET['error']) && $_GET['error'] === 'account_removed') {
    $error = 'Akun Anda telah dihapus atau dinonaktifkan. Hubungi pengembang.';
}

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Get business-specific information for display
$displayInfo = [
    'icon' => '🏢',
    'name' => 'ADF System',
    'subtitle' => 'Business Management System',
    'db_name' => 'Multi-Business Platform'
];

if (isset($_GET['biz'])) {
    $bizParam = strtolower(sanitize($_GET['biz']));

    // Map business codes to display info
    $businessMap = [
        'narayana-hotel' => [
            'icon' => '🏨',
            'name' => 'Narayana Hotel',
            'subtitle' => 'Karimunjawa',
            'db_name' => 'adf_narayana_hotel'
        ],
        'bens-cafe' => [
            'icon' => '☕',
            'name' => 'Ben\'s Cafe',
            'subtitle' => 'Karimunjawa',
            'db_name' => 'adf_benscafe'
        ],
        'demo' => [
            'icon' => '🏢',
            'name' => 'Demo Business',
            'subtitle' => 'Demo System',
            'db_name' => 'adf_demo'
        ]
    ];

    if (isset($businessMap[$bizParam])) {
        $displayInfo = $businessMap[$bizParam];
    } else {
        // Dynamic: try to load from businesses table
        try {
            $bizSlugCode = strtoupper(str_replace('-', '_', $bizParam));
            $dynBiz = $db->fetchOne("SELECT business_name, business_type FROM businesses WHERE business_code = :code AND is_active = 1", ['code' => $bizSlugCode]);
            if ($dynBiz) {
                $typeIcons = ['hotel' => '🏨', 'restaurant' => '🍽️', 'cafe' => '☕', 'retail' => '🏪', 'manufacture' => '🏭', 'tourism' => '🏝️'];
                $displayInfo = [
                    'icon' => $typeIcons[$dynBiz['business_type']] ?? '🏢',
                    'name' => $dynBiz['business_name'],
                    'subtitle' => ucfirst($dynBiz['business_type'] ?? 'Business'),
                    'db_name' => $bizParam
                ];
            }
        } catch (Exception $e) {
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Login - <?php echo APP_NAME; ?></title>

    <!-- Favicon -->
    <?php if ($faviconUrl): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
        <link rel="shortcut icon" href="<?php echo $faviconUrl; ?>?v=<?php echo time(); ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .login-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0.75rem;
            position: relative;
            <?php if ($bgUrl): ?>background-image: linear-gradient(160deg, rgba(1, 5, 16, 0.84), rgba(2, 10, 28, 0.76)), url('<?php echo $bgUrl; ?>?v=<?php echo time(); ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-color: #020810;
            <?php else: ?>background: radial-gradient(ellipse 70% 55% at 68% 12%, rgba(22, 70, 220, 0.2) 0%, transparent 65%), radial-gradient(ellipse 60% 50% at 18% 85%, rgba(10, 44, 190, 0.15) 0%, transparent 65%), #020810;
            <?php endif; ?>
        }

        /* Ambient lighting */
        .login-container::before {
            content: '';
            position: absolute;
            top: -200px;
            right: -180px;
            width: 580px;
            height: 520px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(22, 80, 235, 0.1), transparent 70%);
            pointer-events: none;
            animation: drift 14s ease-in-out infinite;
        }

        .login-box {
            background: linear-gradient(160deg, rgba(6, 18, 52, 0.82), rgba(9, 25, 62, 0.74));
            backdrop-filter: blur(26px) saturate(160%);
            border-radius: 24px;
            padding: 1.6rem 1.45rem;
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.07), 0 40px 100px rgba(0, 4, 18, 0.82), inset 0 1px 0 rgba(255, 255, 255, 0.13);
            border: none;
            width: 100%;
            max-width: 375px;
            position: relative;
            z-index: 1;
            animation: slideUp 0.28s ease-out;
        }

        .developer-logo-top {
            width: 100%;
            display: flex;
            justify-content: center;
            margin-bottom: 0.84rem;
            position: relative;
            z-index: 1;
        }

        .developer-logo-top img {
            width: 82px;
            height: auto;
            opacity: 0.84;
            filter: drop-shadow(0 6px 24px rgba(0, 6, 24, 0.6));
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideOutUp {
            from {
                opacity: 1;
                transform: translateY(0);
            }

            to {
                opacity: 0;
                transform: translateY(-20px);
            }
        }

        /* Glow effect on hover */
        .login-box::before {
            display: none;
        }

        .login-header {
            text-align: center;
            margin-bottom: 1rem;
            position: relative;
        }

        .business-logo-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            display: block;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
        }

        .business-logo-img {
            width: 48px;
            height: 48px;
            object-fit: contain;
            margin-bottom: 0.45rem;
            border-radius: 10px;
            box-shadow: 0 5px 12px rgba(0, 0, 0, 0.28);
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .login-logo {
            font-size: 1.42rem;
            letter-spacing: -0.5px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.15rem;
            text-shadow: 0 1px 6px rgba(0, 0, 0, 0.25);
        }

        .login-subtitle {
            color: rgba(148, 178, 220, 0.8);
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 0.12rem;
        }

        .form-group {
            margin-bottom: 0.76rem;
        }

        .form-label {
            display: block;
            color: rgba(168, 192, 232, 0.8);
            font-size: 0.69rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
            text-transform: uppercase;
            letter-spacing: 0.55px;
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            font-size: 1rem;
            user-select: none;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: #cbd5e1;
        }

        .form-control {
            width: 100%;
            padding: 0.62rem 0.76rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.11);
            border-radius: 11px;
            color: #e2e8f0;
            font-size: 0.9rem;
            transition: all 0.25s ease;
        }

        .form-control::placeholder {
            color: #9fb1cc;
        }

        .form-control:focus {
            outline: none;
            border-color: rgba(80, 145, 255, 0.6);
            box-shadow: 0 0 0 3px rgba(28, 98, 240, 0.14);
            background: rgba(255, 255, 255, 0.08);
        }

        .alert-danger,
        .login-box .alert-danger {
            background: rgba(255, 241, 242, 0.96);
            border: 1px solid rgba(239, 68, 68, 0.95);
            color: #991b1b !important;
            padding: 0.68rem 0.78rem;
            border-radius: 8px;
            margin-bottom: 0.85rem;
            text-align: center;
            font-size: 0.82rem;
            font-weight: 800;
            line-height: 1.45;
            backdrop-filter: blur(4px);
            box-shadow: 0 10px 24px rgba(185, 28, 28, 0.12);
            text-shadow: 0 1px 0 rgba(255, 255, 255, 0.72);
            opacity: 1 !important;
            letter-spacing: 0.1px;
            -webkit-text-fill-color: #991b1b !important;
        }

        .login-box .alert-danger * {
            color: #991b1b !important;
            opacity: 1 !important;
            -webkit-text-fill-color: #991b1b !important;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .database-status {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 0.55rem 0.72rem;
            border-radius: 9px;
            margin-top: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            backdrop-filter: blur(4px);
        }

        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 10px rgba(16, 185, 129, 1), inset 0 0 3px rgba(255, 255, 255, 0.3);
            animation: blink 1.2s ease-in-out infinite;
            flex-shrink: 0;
        }

        @keyframes blink {
            0% {
                background: #10b981;
                box-shadow: 0 0 10px rgba(16, 185, 129, 1), inset 0 0 3px rgba(255, 255, 255, 0.3);
            }

            50% {
                background: #059669;
                box-shadow: 0 0 15px rgba(16, 185, 129, 0.8), inset 0 0 5px rgba(255, 255, 255, 0.2);
            }

            100% {
                background: #10b981;
                box-shadow: 0 0 10px rgba(16, 185, 129, 1), inset 0 0 3px rgba(255, 255, 255, 0.3);
            }
        }

        .db-info {
            flex: 1;
        }

        .db-label {
            font-size: 0.55rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.15rem;
            font-weight: 600;
        }

        .db-name {
            font-size: 0.72rem;
            color: #6aaeff;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            text-shadow: 0 0 12px rgba(28, 80, 240, 0.4);
        }

        /* remember-me-info simplified to checkbox row */

        .remember-me-wrapper {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 0.88rem;
            padding: 9px 13px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 11px;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .remember-me-wrapper:hover {
            background: rgba(255, 255, 255, 0.07);
            border-color: rgba(255, 255, 255, 0.18);
            transform: translateY(-1px);
        }

        .remember-me-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #3b82f6;
            flex-shrink: 0;
        }

        .remember-me-wrapper label {
            color: #e2e8f0;
            font-size: 0.85rem;
            cursor: pointer;
            margin-bottom: 0;
            user-select: none;
            font-weight: 500;
            flex: 1;
        }

        .remember-me-wrapper input[type="checkbox"]:checked+label {
            color: #93c5fd;
            font-weight: 600;
        }

        .save-pw-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.82rem;
        }

        .save-pw-label {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            color: #94a3b8;
            font-size: 0.82rem;
            cursor: pointer;
            user-select: none;
        }

        .save-pw-label input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: #3b82f6;
            cursor: pointer;
            flex-shrink: 0;
        }

        .btn-clear-saved {
            padding: 4px 10px;
            background: transparent;
            border: 1px solid rgba(239, 68, 68, 0.35);
            border-radius: 6px;
            color: #fca5a5;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-clear-saved:hover {
            background: rgba(239, 68, 68, 0.08);
            border-color: rgba(239, 68, 68, 0.55);
        }

        /* demo-credentials removed */

        .login-footer {
            text-align: center;
            margin-top: 0.9rem;
            padding-top: 0.82rem;
            border-top: 1px solid rgba(255, 255, 255, 0.07);
            color: rgba(130, 158, 205, 0.65);
            font-size: 0.62rem;
            letter-spacing: 0.18px;
        }

        .login-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.78rem;
        }

        .login-buttons button {
            padding: 0.68rem 0.7rem;
            border-radius: 12px;
            font-size: 0.83rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .btn-owner {
            flex: 1;
        }

        .btn-primary {
            flex: 1.45;
        }



        .login-buttons button::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(rgba(255, 255, 255, 0.2), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .login-buttons button:hover::after {
            opacity: 1;
        }

        .btn-owner {
            background: rgba(255, 255, 255, 0.06);
            color: rgba(195, 218, 255, 0.88);
            border: 1px solid rgba(255, 255, 255, 0.14);
            box-shadow: none;
        }

        .btn-owner:hover {
            background: rgba(255, 255, 255, 0.11);
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.24);
            color: #ffffff;
        }

        .btn-primary {
            background: linear-gradient(155deg, #1e5cf5, #1340cc);
            color: white;
            box-shadow: 0 8px 26px rgba(20, 78, 235, 0.4);
        }

        .btn-primary:hover {
            background: linear-gradient(155deg, #2568ff, #1848d8);
            transform: translateY(-2px);
            box-shadow: 0 12px 34px rgba(20, 78, 235, 0.55);
        }

        /* Responsive */
        @media (max-width: 360px) {
            .login-box {
                padding: 1.3rem 1.1rem;
                max-width: 95%;
            }

            .login-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="developer-logo-top">
            <img src="<?php echo BASE_URL; ?>/assets/img/developer-logo.png" alt="Developer Logo">
        </div>
        <div class="login-box">
            <div class="login-header">
                <?php if ($loginLogoUrl): ?>
                    <img src="<?php echo $loginLogoUrl; ?>?v=<?php echo time(); ?>" alt="Logo" class="business-logo-img">
                <?php else: ?>
                    <span class="business-logo-icon"><?php echo $displayInfo['icon']; ?></span>
                <?php endif; ?>
                <h1 class="login-logo"><?php echo $displayInfo['name']; ?></h1>
                <p class="login-subtitle"><?php echo $displayInfo['subtitle']; ?></p>
                <?php if (isset($_GET['biz'])): ?>
                    <p class="login-subtitle">Hotel System</p>
                <?php endif; ?>
            </div>

            <div class="database-status">
                <div class="status-indicator"></div>
                <div class="db-info">
                    <div class="db-label">DATABASE</div>
                    <div class="db-name"><?php echo $displayInfo['db_name']; ?></div>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert-danger" role="alert" aria-live="assertive">
                    <span style="font-weight:800;color:#991b1b !important;opacity:1 !important;-webkit-text-fill-color:#991b1b !important;">&#9888; <?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="on">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" autocomplete="username" class="form-control" placeholder="Masukkan username" required autofocus value="<?= htmlspecialchars($savedUser) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="loginPassword" autocomplete="current-password" class="form-control" placeholder="Masukkan password" required style="padding-right: 45px;">
                        <span class="password-toggle" onclick="togglePassword('loginPassword', this)">👁️</span>
                    </div>
                </div>

                <div class="save-pw-row">
                    <label class="save-pw-label">
                        <input type="checkbox" id="savePasswordChk" onchange="toggleSavePassword(this)">
                        <span>Simpan Password</span>
                    </label>
                    <button type="button" class="btn-clear-saved" id="clearSavedBtn" onclick="clearSavedCredentials()" style="display:none;">Hapus</button>
                </div>

                <div class="login-buttons">
                    <button type="submit" name="login_type" value="owner" class="btn-owner">Login Owner</button>
                    <button type="submit" name="login_type" value="normal" class="btn-primary">Login System</button>
                </div>
            </form>

            <div class="login-footer">
                &copy; <?php echo APP_YEAR; ?> <?php echo APP_NAME; ?>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(inputId, iconElement) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                iconElement.textContent = '👁️‍🗨️';
            } else {
                input.type = 'password';
                iconElement.textContent = '👁️';
            }
        }

        function toggleSavePassword(chk) {
            let rememberInput = document.querySelector('input[name="remember_me"]');
            if (chk.checked) {
                if (!rememberInput) {
                    rememberInput = document.createElement('input');
                    rememberInput.type = 'hidden';
                    rememberInput.name = 'remember_me';
                    document.querySelector('form').appendChild(rememberInput);
                }
                rememberInput.value = '1';
                document.getElementById('clearSavedBtn').style.display = 'inline-flex';
            } else {
                if (rememberInput) rememberInput.value = '0';
                document.getElementById('clearSavedBtn').style.display = 'none';
            }
        }

        // Clear Saved Credentials
        function clearSavedCredentials() {
            if (confirm('Hapus semua kredensial tersimpan? Anda akan perlu login manual lagi.')) {
                // Clear cookies via server-side (send AJAX request)
                fetch('<?= BASE_URL ?>/api/clear-login-cookie.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                }).then(() => {
                    // Clear form
                    document.querySelector('input[name="username"]').value = '';
                    document.querySelector('input[name="password"]').value = '';

                    // Reset button states
                    const saveBtn = document.getElementById('savePasswordBtn');
                    const clearBtn = document.getElementById('clearSavedBtn');
                    saveBtn.classList.remove('active');
                    saveBtn.innerHTML = '💾 Simpan Password';
                    clearBtn.style.display = 'none';

                    // Remove hidden remember_me input
                    const rememberInput = document.querySelector('input[name="remember_me"]');
                    if (rememberInput) rememberInput.remove();

                    alert('✅ Kredensial tersimpan berhasil dihapus!');
                    location.reload();
                }).catch(err => {
                    alert('❌ Gagal menghapus kredensial. Silakan coba lagi.');
                });
            }
        }





        // Remember me - auto login via secure HMAC token
        document.addEventListener('DOMContentLoaded', function() {
            const clearBtn = document.getElementById('clearSavedBtn');
            const usernameInput = document.querySelector('input[name="username"]');

            // If user saved, check the checkbox and show clear button
            const hasSavedUser = <?= !empty($savedUser) ? 'true' : 'false' ?>;
            if (hasSavedUser) {
                const chk = document.getElementById('savePasswordChk');
                if (chk) chk.checked = true;
                clearBtn.style.display = 'inline-flex';
            }

            // Clean up old localStorage (one-time migration)
            try {
                localStorage.removeItem('saved_username');
                localStorage.removeItem('saved_password');
                localStorage.removeItem('remember_me');
            } catch (e) {}
        });
    </script>
</body>

</html>