<?php

/**
 * Sunsea - Owner Login (dedicated entry point, separate from the main system login)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$pdo = getSunseaConnection();

// Already logged in with an owner-capable role (atau sudah diberi Hak Akses Khusus) — skip straight to the dashboard.
if ($auth->isLoggedIn()) {
    $existingRole = $_SESSION['role'] ?? '';
    $existingUser = ['id' => $_SESSION['user_id'] ?? 0, 'role' => $existingRole];
    if (in_array($existingRole, ['developer', 'owner', 'admin'], true) || sunseaCanAccessMenu($pdo, $existingUser, 'owner_dashboard')) {
        header('Location: owner-dashboard.php');
        exit;
    }
    // Logged in as some other role — this portal is owner-only, force a clean re-login.
    $auth->logout();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } elseif ($auth->login($username, $password)) {
        $role = $_SESSION['role'] ?? '';
        $loggedInUser = ['id' => $_SESSION['user_id'] ?? 0, 'role' => $role];
        if (in_array($role, ['developer', 'owner', 'admin'], true) || sunseaCanAccessMenu($pdo, $loggedInUser, 'owner_dashboard')) {
            require_once '../../includes/business_helper.php';
            require_once '../../includes/business_access.php';
            $bizList = getUserAvailableBusinesses();
            if (!empty($bizList)) {
                setActiveBusinessId(getPreferredDefaultBusiness($bizList));
            }
            header('Location: owner-dashboard.php');
            exit;
        }
        $auth->logout();
        $error = 'Akun ini bukan Owner/Admin/Developer. Gunakan login system biasa.';
    } else {
        $error = 'Username atau password salah.';
    }
}


$companyName = sunseaSetting($pdo, 'company_name', 'Karimunjawa Explore');
$logoPath = sunseaSetting($pdo, 'company_logo', '');
$logoSrc  = $logoPath ? sunseaAssetUrl($logoPath) : '';
$loginBgFile = sunseaSetting($pdo, 'login_background', '');
$loginBgSrc  = $loginBgFile ? sunseaAssetUrl('uploads/backgrounds/' . $loginBgFile) : '';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Owner Login - <?php echo htmlspecialchars($companyName); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0369A1">
    <link rel="manifest" href="owner-manifest.php">
    <!-- Global Loading Indicator (progress bar + overlay) -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/global-loader.css?v=<?php echo time(); ?>">
    <script src="<?php echo BASE_URL; ?>/assets/js/global-loader.js?v=<?php echo time(); ?>"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --ocean: #0369A1;
            --danger: #DC2626;
            --muted: #64748B;
            --text: #1E293B;
            --border: #E2E8F0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(160deg, #0369A1 0%, #0EA5E9 45%, #7DD3FC 100%);
            <?php if ($loginBgSrc): ?>background-image: linear-gradient(160deg, rgba(3, 105, 161, .55) 0%, rgba(3, 105, 161, .35) 100%), url('<?php echo htmlspecialchars($loginBgSrc, ENT_QUOTES); ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            <?php endif; ?>
        }

        .ol-card {
            width: 100%;
            max-width: 360px;
            background: rgba(255, 255, 255, .32);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, .45);
            border-radius: 22px;
            padding: 30px 26px;
            box-shadow: 0 20px 50px rgba(3, 105, 161, .25);
            text-align: center;
        }

        .ol-logo {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: #fff;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .1);
            font-size: 28px;
            overflow: hidden;
        }

        .ol-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .ol-eyebrow {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--ocean);
        }

        .ol-title {
            font-size: 19px;
            font-weight: 800;
            color: var(--text);
            margin: 4px 0 22px;
        }

        .ol-field {
            text-align: left;
            margin-bottom: 14px;
        }

        .ol-label {
            font-size: 11.5px;
            font-weight: 600;
            color: #fff;
            margin-bottom: 5px;
            display: block;
        }

        .ol-input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1.5px solid rgba(255, 255, 255, .5);
            font-size: 14px;
            color: var(--text);
            background: rgba(255, 255, 255, .35);
            backdrop-filter: blur(6px);
        }

        .ol-input:focus {
            outline: none;
            border-color: var(--ocean);
            background: rgba(255, 255, 255, .55);
        }

        .ol-password-wrap {
            position: relative;
        }

        .ol-password-wrap .ol-input {
            padding-right: 42px;
        }

        .ol-eye-toggle {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            border: none;
            background: none;
            color: var(--text);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ol-eye-toggle svg {
            width: 19px;
            height: 19px;
        }

        .ol-error {
            background: #FEF2F2;
            color: var(--danger);
            font-size: 12px;
            font-weight: 600;
            padding: 9px 12px;
            border-radius: 10px;
            margin-bottom: 14px;
            text-align: left;
        }

        .ol-submit {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 12px;
            margin-top: 6px;
            background: linear-gradient(135deg, #0369A1, #0EA5E9);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
        }

        .ol-foot {
            margin-top: 18px;
            font-size: 11px;
            color: rgba(255, 255, 255, .9);
        }
    </style>
</head>

<body>
    <div class="ol-card">
        <div class="ol-logo">
            <?php if ($logoSrc): ?>
                <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Logo">
            <?php else: ?>
                🌊
            <?php endif; ?>
        </div>
        <div class="ol-eyebrow">Owner Portal</div>
        <div class="ol-title"><?php echo htmlspecialchars($companyName); ?></div>

        <?php if ($error): ?>
            <div class="ol-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="ol-field">
                <label class="ol-label">Username</label>
                <input type="text" name="username" class="ol-input" required autofocus>
            </div>
            <div class="ol-field">
                <label class="ol-label">Password</label>
                <div class="ol-password-wrap">
                    <input type="password" name="password" id="olPassword" class="ol-input" required>
                    <button type="button" class="ol-eye-toggle" onclick="olTogglePassword()" aria-label="Lihat/sembunyikan password">
                        <svg id="olEyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="ol-submit">Masuk ke Monitor Owner</button>
        </form>

        <div class="ol-foot">Khusus Owner / Admin / Developer</div>
    </div>

    <script>
        function olTogglePassword() {
            var input = document.getElementById('olPassword');
            var icon = document.getElementById('olEyeIcon');
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            icon.innerHTML = showing ?
                '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>' :
                '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.6 18.6 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
        }
    </script>
</body>

</html>