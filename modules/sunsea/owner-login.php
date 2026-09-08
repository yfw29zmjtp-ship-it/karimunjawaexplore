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

// Already logged in with an owner-capable role — skip straight to the dashboard.
if ($auth->isLoggedIn()) {
    $existingRole = $_SESSION['role'] ?? '';
    if (in_array($existingRole, ['developer', 'owner', 'admin'], true)) {
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
        if (in_array($role, ['developer', 'owner', 'admin'], true)) {
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

$pdo = getSunseaConnection();
$companyName = sunseaSetting($pdo, 'company_name', 'Karimunjawa Explore');
$logoPath = sunseaSetting($pdo, 'company_logo', '');
$logoSrc  = $logoPath ? sunseaAssetUrl($logoPath) : '';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Owner Login - <?php echo htmlspecialchars($companyName); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0369A1">
    <link rel="manifest" href="owner-manifest.php">
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
        }

        .ol-card {
            width: 100%;
            max-width: 360px;
            background: rgba(255, 255, 255, .92);
            backdrop-filter: blur(14px);
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
            color: var(--muted);
            margin-bottom: 5px;
            display: block;
        }

        .ol-input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            font-size: 14px;
            color: var(--text);
            background: #fff;
        }

        .ol-input:focus {
            outline: none;
            border-color: var(--ocean);
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
            color: var(--muted);
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
                <input type="password" name="password" class="ol-input" required>
            </div>
            <button type="submit" class="ol-submit">Masuk ke Monitor Owner</button>
        </form>

        <div class="ol-foot">Khusus Owner / Admin / Developer</div>
    </div>
</body>

</html>