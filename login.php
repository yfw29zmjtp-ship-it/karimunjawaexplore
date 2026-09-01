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

// Safety switch: disable cookie-based auto-login while troubleshooting hosting/session issues.
// Manual login (username/password) stays active.
$allowRememberTokenAutoLogin = false;

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
if ($allowRememberTokenAutoLogin && !empty($_COOKIE['adf_remember_token']) && !$auth->isLoggedIn() && !isPost()) {
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

// If auto-login is disabled, force-clear old remember token cookie to stop password-less login.
if (!$allowRememberTokenAutoLogin && !empty($_COOKIE['adf_remember_token'])) {
    setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
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
                $error = 'User is not registered in the system. Contact developer to set access.';
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
                        setFlash('success', 'Owner login successful!');
                        header('Location: ' . BASE_URL . '/modules/owner/dashboard-2028.php');
                        exit;
                    } else {
                        $error = 'Access denied. Only Owner role can access the Owner Dashboard.';
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
                    setFlash('success', 'Login successful. Developer mode is active.');
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
                    $error = 'You do not have access to any business. Contact developer.';
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
                        setFlash('success', 'Login successful!');
                        redirect(BASE_URL . '/index.php');
                    } else {
                        $error = 'You do not have access to this business.';
                        $auth->logout();
                    }
                } else {
                    // One or multiple businesses - auto login to first business
                    $bizCode = $userBusinesses[0]['business_code'];
                    $businessId = isset($codeToSlugMap[$bizCode]) ? $codeToSlugMap[$bizCode] : strtolower(str_replace('_', '-', $bizCode));
                    $_SESSION['business_id'] = (int)$userBusinesses[0]['id']; // Set numeric business_id
                    setActiveBusinessId($businessId);

                    if (count($userBusinesses) === 1) {
                        setFlash('success', 'Login successful! Welcome to ' . $userBusinesses[0]['business_name']);
                    } else {
                        setFlash('success', 'Login successful! You can switch business using the sidebar dropdown.');
                    }

                    redirect(BASE_URL . '/index.php');
                }
            }
        } catch (PDOException $e) {
            error_log('Login business check error: ' . $e->getMessage());
            $error = 'A system error occurred. Please try again.';
            $auth->logout();
        }
    } else {
        $error = 'Invalid username or password.';
    }
}

// Check if redirected from account removal
if (isset($_GET['error']) && $_GET['error'] === 'account_removed') {
    $error = 'Your account has been removed or disabled. Contact developer.';
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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Login - <?php echo APP_NAME; ?></title>

    <!-- Favicon: always the ADF System logo, never the business icon -->
    <link rel="icon" type="image/png" sizes="500x500" href="<?php echo BASE_URL; ?>/assets/img/developer-logo.png?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="<?php echo BASE_URL; ?>/assets/img/developer-logo.png?v=<?php echo time(); ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">

    <style>
        @property --angle {
            syntax: '<angle>';
            inherits: false;
            initial-value: 0deg;
        }

        :root {
            --ink-900: #f6f8fc;
            --ink-700: #c7d2e8;
            --ink-600: #8b96b3;
            --line: rgba(148, 163, 184, 0.16);
            --cyan: #22d3ee;
            --violet: #a78bfa;
            --blue: #3b82f6;
            --danger: #f87171;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--ink-900);
            background: #030510;
        }

        .login-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            padding: 1.25rem 14vw 1.25rem 1.25rem;
            position: relative;
            overflow: hidden;
            <?php if ($bgUrl): ?>background-image: linear-gradient(165deg, rgba(1, 2, 8, 0.55), rgba(2, 6, 20, 0.4)), url('<?php echo $bgUrl; ?>?v=<?php echo time(); ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            <?php else: ?>background: radial-gradient(ellipse 55% 45% at 82% 8%, rgba(34, 211, 238, 0.16) 0%, transparent 62%), radial-gradient(ellipse 50% 42% at 10% 94%, rgba(167, 139, 250, 0.14) 0%, transparent 62%), #030510;
            <?php endif; ?>
        }

        /* Faint futuristic grid, masked to fade out toward the edges */
        .login-container::before {
            content: '';
            position: absolute;
            inset: -10%;
            background-image:
                linear-gradient(rgba(148, 163, 184, 0.055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, 0.055) 1px, transparent 1px);
            background-size: 44px 44px;
            -webkit-mask-image: radial-gradient(ellipse 65% 60% at 50% 42%, #000 20%, transparent 82%);
            mask-image: radial-gradient(ellipse 65% 60% at 50% 42%, #000 20%, transparent 82%);
            pointer-events: none;
            z-index: 0;
        }

        /* Slow diagonal light sweep for a "scanning" HUD feel */
        .login-container::after {
            content: '';
            position: absolute;
            inset: -25%;
            background: linear-gradient(115deg, transparent 42%, rgba(34, 211, 238, 0.05) 50%, transparent 58%);
            animation: scanSweep 9s linear infinite;
            pointer-events: none;
            z-index: 0;
        }

        @keyframes scanSweep {
            0% {
                transform: translateX(-25%);
            }

            100% {
                transform: translateX(25%);
            }
        }

        @keyframes riseIn {
            from {
                opacity: 0;
                transform: translateY(18px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .developer-logo-top {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            margin-bottom: 0.9rem;
            position: relative;
            z-index: 2;
        }

        .developer-logo-top img {
            width: 46px;
            height: auto;
            opacity: 0.92;
            filter: drop-shadow(0 0 18px rgba(34, 211, 238, 0.35)) drop-shadow(0 6px 20px rgba(0, 4, 16, 0.7));
        }

        /* Narrow, elongated shell with a slow-rotating neon gradient ring */
        .login-shell {
            width: min(340px, 90vw);
            border-radius: 22px;
            position: relative;
            z-index: 1;
            animation: riseIn 0.45s ease;
            isolation: isolate;
        }

        .login-shell::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 26px;
            padding: 1px;
            background: conic-gradient(from var(--angle, 0deg), var(--cyan), var(--violet) 35%, var(--blue) 65%, var(--cyan));
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            opacity: 0.6;
            animation: ringSpin 7s linear infinite;
            z-index: -1;
        }

        @keyframes ringSpin {
            to {
                --angle: 360deg;
            }
        }

        .login-box {
            background: linear-gradient(170deg, rgba(9, 13, 28, 0.5), rgba(6, 9, 20, 0.55));
            backdrop-filter: blur(20px) saturate(160%);
            border-radius: 21px;
            padding: 1.85rem 1.5rem 1.6rem;
            display: flex;
            flex-direction: column;
            color: #f6f8fc !important;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 34px 90px rgba(0, 2, 12, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.07);
        }

        .login-box .login-logo {
            color: #f6f8fc !important;
        }

        .login-header {
            margin-bottom: 1.1rem;
            text-align: center;
        }

        .login-logo {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.3rem;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.4px;
            background: linear-gradient(120deg, #f6f8fc 25%, #7dd3fc 60%, #c4b5fd 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .login-subtitle {
            color: var(--ink-700);
            font-size: 0.78rem;
            margin-top: 0.3rem;
        }

        .login-box .login-subtitle {
            color: rgba(148, 178, 220, 0.75) !important;
        }

        .database-status {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.07);
            background: rgba(255, 255, 255, 0.03);
            border-radius: 999px;
            padding: 0.42rem 0.7rem;
            margin-bottom: 0.95rem;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 0 0 rgba(34, 211, 238, 0.5);
            flex-shrink: 0;
            animation: pulseDot 2.2s ease-in-out infinite;
        }

        @keyframes pulseDot {
            0% {
                box-shadow: 0 0 0 0 rgba(34, 211, 238, 0.45);
            }

            70% {
                box-shadow: 0 0 0 7px rgba(34, 211, 238, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(34, 211, 238, 0);
            }
        }

        .db-label {
            font-size: 0.62rem;
            color: var(--ink-600);
            letter-spacing: 0.4px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .login-box .db-label {
            color: #93a3c4 !important;
        }

        .db-name {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.82rem;
            color: var(--cyan);
            font-weight: 700;
            letter-spacing: 0.1px;
        }

        .login-box .db-name {
            color: #67e8f9 !important;
        }

        .form-group {
            margin-bottom: 0.9rem;
        }

        .form-label {
            display: block;
            color: rgba(168, 192, 232, 0.75);
            font-size: 0.68rem;
            font-weight: 600;
            margin-bottom: 0.35rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .login-box .form-label {
            color: rgba(168, 192, 232, 0.75) !important;
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
            color: #a7b6d6;
            font-size: 0.94rem;
            user-select: none;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: #f6f8fc;
        }

        .form-control {
            width: 100%;
            padding: 0.68rem 0.85rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: 12px;
            color: #e8eeff;
            font-size: 0.9rem;
            font-weight: 500;
            transition: border-color 0.22s, box-shadow 0.22s, background 0.22s;
        }

        .login-box .form-control {
            color: #e8eeff !important;
            background: rgba(255, 255, 255, 0.04) !important;
        }

        .form-control::placeholder {
            color: rgba(120, 150, 200, 0.55);
            opacity: 1;
        }

        .login-box .form-control::placeholder {
            color: rgba(120, 150, 200, 0.55) !important;
            opacity: 1 !important;
        }

        .form-control:focus {
            outline: none;
            border-color: rgba(34, 211, 238, 0.55);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.16), 0 0 18px rgba(34, 211, 238, 0.12);
            background: rgba(255, 255, 255, 0.07) !important;
        }

        .alert-danger,
        .login-box .alert-danger {
            background: #fff1f2;
            border: 1px solid #ef4444;
            color: #991b1b !important;
            padding: 0.68rem 0.78rem;
            border-radius: 12px;
            margin-bottom: 0.9rem;
            text-align: center;
            font-size: 0.82rem;
            font-weight: 800;
            line-height: 1.45;
            box-shadow: 0 10px 24px rgba(185, 28, 28, 0.08);
            text-shadow: 0 1px 0 rgba(255, 255, 255, 0.75);
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

        .save-pw-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 0.2rem 0 0.9rem;
            gap: 0.55rem;
        }

        .save-pw-label {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            color: #c7d2e8;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
        }

        .login-box .save-pw-label,
        .login-box .save-pw-label span {
            color: #c7d2e8 !important;
        }

        .save-pw-label input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: var(--cyan);
            cursor: pointer;
            flex-shrink: 0;
        }

        .btn-clear-saved {
            padding: 0.28rem 0.62rem;
            background: #fff;
            border: 1px solid #fecaca;
            border-radius: 7px;
            color: var(--danger);
            font-size: 0.76rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .btn-clear-saved:hover {
            background: #fef2f2;
        }

        .login-buttons {
            display: grid;
            gap: 0.55rem;
            margin-top: 0.3rem;
            grid-template-columns: 1fr 1.45fr;
        }

        .login-buttons button {
            padding: 0.72rem 0.7rem;
            border-radius: 12px;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, background-position 0.6s ease;
            letter-spacing: 0.15px;
        }

        .btn-owner {
            background: rgba(255, 255, 255, 0.05);
            color: rgba(199, 210, 232, 0.88);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: none;
        }

        .btn-owner:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.22);
            color: #ffffff;
        }

        .btn-primary {
            background: linear-gradient(120deg, #22d3ee, #3b82f6 55%, #a78bfa);
            background-size: 200% 200%;
            background-position: 0% 50%;
            color: #04050a;
            box-shadow: 0 10px 28px rgba(59, 130, 246, 0.38);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            background-position: 100% 50%;
            box-shadow: 0 14px 36px rgba(59, 130, 246, 0.52);
        }

        .login-footer {
            text-align: center;
            margin-top: 1.1rem;
            padding-top: 0.9rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            color: rgba(130, 150, 190, 0.55);
            font-size: 0.6rem;
            letter-spacing: 0.3px;
        }

        .login-box .login-footer {
            color: rgba(130, 150, 190, 0.55) !important;
        }

        @media (max-width: 860px) {
            .login-shell {
                width: min(340px, 90vw);
            }

            .login-container {
                align-items: center;
                padding-right: 1.25rem;
            }

            .developer-logo-top {
                justify-content: center;
            }
        }

        @media (max-width: 420px) {
            .login-container {
                padding: 0.75rem;
            }

            .login-box {
                padding: 1.9rem 1.15rem 1.4rem;
            }

            .login-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-shell">
            <div class="login-box">
                <div class="login-header">
                    <h2 class="login-logo">Sign in to Dashboard</h2>
                    <p class="login-subtitle"><?php echo $displayInfo['subtitle']; ?><?php if (isset($_GET['biz'])): ?> • Hotel System<?php endif; ?></p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert-danger" role="alert" aria-live="assertive">
                        <span style="font-weight:800;color:#991b1b !important;opacity:1 !important;-webkit-text-fill-color:#991b1b !important;">&#9888; <?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" autocomplete="on" onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('btnSystemLogin').click();}">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" autocomplete="username" class="form-control" placeholder="Enter username" required autofocus value="<?= htmlspecialchars($savedUser) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="loginPassword" autocomplete="current-password" class="form-control" placeholder="Enter password" required style="padding-right: 45px;">
                            <span class="password-toggle" onclick="togglePassword('loginPassword', this)">👁️</span>
                        </div>
                    </div>

                    <div class="save-pw-row">
                        <label class="save-pw-label">
                            <input type="checkbox" id="savePasswordChk" onchange="toggleSavePassword(this)">
                            <span>Remember me</span>
                        </label>
                        <button type="button" class="btn-clear-saved" id="clearSavedBtn" onclick="clearSavedCredentials()" style="display:none;">Clear</button>
                    </div>

                    <div class="login-buttons">
                        <button type="submit" name="login_type" value="owner" class="btn-owner">Owner Login</button>
                        <button type="submit" name="login_type" value="normal" class="btn-primary" id="btnSystemLogin">System Login</button>
                    </div>
                </form>

                <div class="login-footer">
                    &copy; <?php echo APP_YEAR; ?> <?php echo APP_NAME; ?>
                </div>
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
            if (confirm('Clear all saved credentials? You will need to log in manually again.')) {
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

                    // Reset UI states
                    const clearBtn = document.getElementById('clearSavedBtn');
                    const saveChk = document.getElementById('savePasswordChk');
                    if (saveChk) saveChk.checked = false;
                    clearBtn.style.display = 'none';

                    // Remove hidden remember_me input
                    const rememberInput = document.querySelector('input[name="remember_me"]');
                    if (rememberInput) rememberInput.remove();

                    alert('Saved credentials cleared successfully.');
                    location.reload();
                }).catch(err => {
                    alert('Failed to clear saved credentials. Please try again.');
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