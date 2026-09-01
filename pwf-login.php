<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/business_helper.php';
require_once __DIR__ . '/modules/pwf-office/db-helper.php';

$auth = new Auth();
$error = '';
$savedUser = '';

// Setup cookie handling for remember me
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

// Check if user has saved credentials
if (!empty($_COOKIE['adf_saved_user'])) {
  $savedUser = base64_decode($_COOKIE['adf_saved_user']);
}

if ($auth->isLoggedIn() && empty($_POST)) {
  @setActiveBusinessId('pwf-furniture');
  header('Location: ' . BASE_URL . '/modules/pwf-office/' . pwfGetDefaultLandingUrl());
  exit;
}

if (!empty($_POST)) {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';

  if ($auth->login($username, $password)) {
    // Handle remember me - save username cookie
    if ($rememberMe) {
      setcookie('adf_saved_user', base64_encode($username), time() + (30 * 24 * 60 * 60), $cookiePath, '', $isSecure, true);

      // Set auto-login HMAC token
      try {
        $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $stmt = $masterPdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
          $token = generateRememberToken($user['id'], $rememberSecret);
          setcookie('adf_remember_token', $token, time() + (30 * 24 * 60 * 60), $cookiePath, '', $isSecure, true);
        }
      } catch (Exception $e) {
      }
    } else {
      // Clear remember cookies if unchecked
      setcookie('adf_saved_user', '', time() - 3600, $cookiePath, '', $isSecure, true);
      setcookie('adf_remember_token', '', time() - 3600, $cookiePath, '', $isSecure, true);
    }

    @setActiveBusinessId('pwf-furniture');
    header('Location: ' . BASE_URL . '/modules/pwf-office/' . pwfGetDefaultLandingUrl());
    exit;
  }
  $error = 'Invalid username or password.';
}

$wallpaperUrl = '';
$logoUrl = '';
$companyName = 'Prapen Wood Furniture';
$faviconUrl = '';
$faviconType = 'image/x-icon';
try {
  $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
  $pwfDb = $isProduction ? 'adfb2574_pwf' : 'adf_pwf';
  $pwfPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=$pwfDb;charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $rows = $pwfPdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('pwf_login_wallpaper','pwf_login_logo','pwf_company_name','pwf_favicon')")->fetchAll(PDO::FETCH_KEY_PAIR);
  if (!empty($rows['pwf_login_wallpaper'])) $wallpaperUrl = htmlspecialchars($rows['pwf_login_wallpaper']);
  if (!empty($rows['pwf_login_logo']))      $logoUrl      = htmlspecialchars($rows['pwf_login_logo']);
  if (!empty($rows['pwf_company_name']))    $companyName  = htmlspecialchars($rows['pwf_company_name']);
  if (!empty($rows['pwf_favicon'])) {
    $faviconUrl = ltrim($rows['pwf_favicon'], '/');
    $ext = strtolower(pathinfo(parse_url($faviconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    $mimeMap = [
      'ico' => 'image/x-icon',
      'png' => 'image/png',
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'webp' => 'image/webp',
      'svg' => 'image/svg+xml'
    ];
    $faviconType = $mimeMap[$ext] ?? 'image/x-icon';
  }
} catch (Exception $e) {
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PWF Office – Sign In</title>
  <?php if (!empty($faviconUrl)): ?>
    <link rel="icon" type="<?= htmlspecialchars($faviconType) ?>" href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . $faviconUrl) ?>">
    <link rel="shortcut icon" type="<?= htmlspecialchars($faviconType) ?>" href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . $faviconUrl) ?>">
  <?php else: ?>
    <link rel="icon" href="<?= htmlspecialchars(rtrim(BASE_URL, '/')) ?>/favicon.ico">
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0
    }

    :root {
      --gold: #D4A017;
      --gold-lt: #F5C842
    }

    body {
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      padding: 0 6vw 0 0;
      background: #0a0e1a;
      position: relative;
      overflow: hidden
    }

    /* WALLPAPER */
    .bg {
      position: fixed;
      inset: 0;
      z-index: 0
    }

    .bg-img {
      position: absolute;
      inset: 0;
      <?php if ($wallpaperUrl): ?>background: url('<?= $wallpaperUrl ?>') center/cover no-repeat;
      <?php else: ?>background: radial-gradient(ellipse at 55% 40%, #1a3a2e 0%, #0a0e1a 60%, #0f172a 100%);
      <?php endif; ?>
    }

    .bg-overlay {
      position: absolute;
      inset: 0;
      background: rgba(5, 8, 18, <?= $wallpaperUrl ? '.28' : '.15' ?>);
    }

    /* CARD — wide rectangle */
    .card {
      position: relative;
      z-index: 2;
      width: min(360px, 90vw);
      background: rgba(8, 11, 22, .86);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, .1);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 28px 72px rgba(0, 0, 0, .55), 0 0 0 1px rgba(255, 255, 255, .04);
    }

    /* LOGO HEADER BAR */
    .card-head {
      padding: 18px 22px 15px;
      background: rgba(255, 255, 255, .035);
      border-bottom: 1px solid rgba(255, 255, 255, .07);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      text-align: center;
    }

    .logo-box {
      width: 56px;
      height: 56px;
      border-radius: 12px;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      flex-shrink: 0;
      box-shadow: 0 4px 18px rgba(0, 0, 0, .36)
    }

    .logo-box img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      padding: 3px
    }

    .logo-icon {
      font-size: 26px;
      line-height: 1
    }

    .brand-name {
      font-size: 14px;
      font-weight: 700;
      color: var(--gold-lt);
      letter-spacing: .3px;
      text-transform: uppercase
    }

    .brand-sub {
      font-size: 9.5px;
      color: rgba(255, 255, 255, .36);
      margin-top: 0px
    }

    /* FORM BODY */
    .card-body {
      padding: 18px 22px 20px
    }

    .title {
      font-size: 16px;
      font-weight: 700;
      color: #fff;
      margin-bottom: 2px
    }

    .subtitle {
      font-size: 10.5px;
      color: rgba(255, 255, 255, .32);
      margin-bottom: 16px
    }

    .field {
      margin-bottom: 11px
    }

    .field label {
      display: block;
      font-size: 9.5px;
      font-weight: 600;
      color: rgba(255, 255, 255, .38);
      letter-spacing: .6px;
      text-transform: uppercase;
      margin-bottom: 4px
    }

    .input-wrap {
      position: relative
    }

    .field input {
      width: 100%;
      padding: 9px 12px;
      background: rgba(255, 255, 255, .07);
      border: 1px solid rgba(255, 255, 255, .1);
      border-radius: 7px;
      color: #fff;
      font-size: 12.5px;
      outline: none;
      font-family: inherit;
      transition: border-color .2s, box-shadow .2s
    }

    .field input::placeholder {
      color: rgba(255, 255, 255, .2)
    }

    .field input:focus {
      border-color: var(--gold);
      box-shadow: 0 0 0 3px rgba(212, 160, 23, .1)
    }

    /* eye button */
    .eye-btn {
      position: absolute;
      right: 9px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: rgba(212, 160, 23, .7);
      padding: 2px;
      display: flex;
      align-items: center;
      transition: color .2s
    }

    .eye-btn:hover {
      color: var(--gold)
    }

    .eye-btn svg {
      width: 14px;
      height: 14px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round
    }

    /* save password checkbox */
    .save-pwd-wrapper {
      display: flex;
      align-items: center;
      gap: 7px;
      margin: 10px 0 9px 0;
      padding: 8px 10px;
      background: rgba(34, 197, 94, .08);
      border: 1px solid rgba(34, 197, 94, .2);
      border-radius: 7px;
      cursor: pointer;
      transition: all .2s;
    }

    .save-pwd-wrapper:hover {
      background: rgba(34, 197, 94, .12);
      border-color: rgba(34, 197, 94, .3)
    }

    .save-pwd-wrapper input[type="checkbox"] {
      width: 15px;
      height: 15px;
      cursor: pointer;
      accent-color: #10b981;
      flex-shrink: 0;
      margin: 0
    }

    .save-pwd-wrapper label {
      color: #86efac;
      font-size: 11px;
      cursor: pointer;
      margin: 0;
      user-select: none;
      font-weight: 500;
      flex: 1
    }

    /* submit button */
    .btn {
      width: 100%;
      padding: 10px;
      margin-top: 8px;
      background: linear-gradient(135deg, var(--gold) 0%, #9a6d00 100%);
      border: none;
      border-radius: 7px;
      color: #0a0e1a;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      letter-spacing: .3px;
      text-transform: uppercase;
      font-family: inherit;
      transition: opacity .2s, transform .1s;
      box-shadow: 0 4px 16px rgba(212, 160, 23, .26)
    }

    .btn:hover {
      opacity: .88;
      transform: translateY(-1px)
    }

    .btn:active {
      transform: translateY(0)
    }

    /* error */
    .err {
      background: rgba(239, 68, 68, .1);
      border: 1px solid rgba(239, 68, 68, .22);
      border-radius: 7px;
      padding: 8px 10px;
      color: #fca5a5;
      font-size: 11px;
      margin-bottom: 11px
    }

    /* footer */
    .card-foot {
      padding: 8px 22px;
      border-top: 1px solid rgba(255, 255, 255, .06);
      font-size: 9px;
      color: rgba(255, 255, 255, .18);
      text-align: center;
      background: rgba(0, 0, 0, .15)
    }

    @media(max-width:500px) {
      body {
        justify-content: center;
        padding: 16px
      }

      .card {
        width: 100%;
        max-width: 420px
      }
    }
  </style>
</head>

<body>
  <div class="bg">
    <div class="bg-img"></div>
    <div class="bg-overlay"></div>
  </div>

  <div class="card">

    <!-- LOGO HEADER -->
    <div class="card-head">
      <div class="logo-box">
        <?php if ($logoUrl): ?>
          <img src="<?= $logoUrl ?>" alt="Logo">
        <?php else: ?>
          <span class="logo-icon">🪵</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="brand-name">PWF Office</div>
        <div class="brand-sub">Management System</div>
      </div>
    </div>

    <!-- FORM -->
    <div class="card-body">
      <div class="title">Welcome Back</div>
      <div class="subtitle">Sign in to continue</div>

      <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" id="loginForm" autocomplete="off">
        <div class="field">
          <label>Username</label>
          <input name="username" type="text" id="usernameInput" autocomplete="username" required placeholder="Enter username" value="<?= htmlspecialchars($savedUser) ?>">
        </div>
        <div class="field">
          <label>Password</label>
          <div class="input-wrap">
            <input name="password" type="password" id="pwdInput" autocomplete="current-password" required placeholder="••••••••" style="padding-right:32px">
            <button type="button" class="eye-btn" onclick="togglePwd()" title="Show / hide">
              <svg id="iconEye" viewBox="0 0 24 24">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
              <svg id="iconEyeOff" viewBox="0 0 24 24" style="display:none">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                <line x1="1" y1="1" x2="23" y2="23" />
              </svg>
            </button>
          </div>
        </div>

        <div class="save-pwd-wrapper" onclick="document.getElementById('rememberMe').click();">
          <input type="checkbox" name="remember_me" id="rememberMe" value="1" <?= !empty($savedUser) ? 'checked' : '' ?> onclick="event.stopPropagation();">
          <label for="rememberMe">💾 Simpan Password</label>
        </div>

        <button class="btn" type="submit">Sign In &rarr;</button>
      </form>
    </div>

    <!-- FOOTER -->
    <div class="card-foot">© 2026 <?= $companyName ?> · PWF Office v1.0</div>

  </div>

  <script>
    function togglePwd() {
      const inp = document.getElementById('pwdInput');
      const show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      document.getElementById('iconEye').style.display = show ? 'none' : '';
      document.getElementById('iconEyeOff').style.display = show ? '' : 'none';
    }

    document.addEventListener('DOMContentLoaded', function() {
      const usernameInput = document.getElementById('usernameInput');
      const pwdInput = document.getElementById('pwdInput');
      const rememberCb = document.getElementById('rememberMe');
      const form = document.getElementById('loginForm');

      // Auto-fill password from localStorage if saved
      const saved = localStorage.getItem('pwf_saved_cred');
      if (saved) {
        try {
          const cred = JSON.parse(saved);
          if (cred.u) usernameInput.value = cred.u;
          if (cred.p) pwdInput.value = cred.p;
          rememberCb.checked = true;
        } catch (e) {}
      }

      // On form submit: save or clear credentials
      form.addEventListener('submit', function() {
        if (rememberCb.checked && usernameInput.value && pwdInput.value) {
          localStorage.setItem('pwf_saved_cred', JSON.stringify({
            u: usernameInput.value,
            p: pwdInput.value
          }));
        } else if (!rememberCb.checked) {
          localStorage.removeItem('pwf_saved_cred');
        }
      });

      // Update label style when checkbox changes
      rememberCb.addEventListener('change', function() {
        if (!this.checked) {
          localStorage.removeItem('pwf_saved_cred');
        }
      });
    });
  </script>
</body>

</html>