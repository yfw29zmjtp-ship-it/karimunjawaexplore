<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$code = trim((string)($_GET['k'] ?? ''));
if ($code === '') {
    http_response_code(400);
    exit('Link tidak valid');
}

try {
    $db = Database::getInstance();
    $link = $db->fetchOne("SELECT token FROM breakfast_guest_links WHERE short_code = ? LIMIT 1", [$code]);
    if (!$link || empty($link['token'])) {
        http_response_code(404);
        exit('Link tidak ditemukan');
    }

    $previewLogoUrl = '';
    $portalLogoRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1", ['breakfast_portal_logo_path']);
    $portalLogoPath = $portalLogoRow['setting_value'] ?? '';
    if ($portalLogoPath) {
        $previewLogoUrl = (strpos($portalLogoPath, 'http') === 0)
            ? $portalLogoPath
            : rtrim(BASE_URL, '/') . '/' . ltrim($portalLogoPath, '/');
    }

    $shortUrl = rtrim(BASE_URL, '/') . '/go-breakfast.php?k=' . urlencode($code);
    $target = rtrim(BASE_URL, '/') . '/modules/frontdesk/breakfast-guest.php?t=' . urlencode($link['token']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Breakfast Menu Selection</title>
    <meta property="og:type" content="website">
    <meta property="og:title" content="Breakfast Menu Selection">
    <meta property="og:description" content="Please select your breakfast menu using this portal.">
    <meta property="og:url" content="<?php echo htmlspecialchars($shortUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($previewLogoUrl !== ''): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($previewLogoUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($previewLogoUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php else: ?>
    <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <meta name="twitter:title" content="Breakfast Menu Selection">
    <meta name="twitter:description" content="Please select your breakfast menu using this portal.">
    <meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: #0f172a;
        }
        .box {
            background: #fff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 20px 24px;
            text-align: center;
            box-shadow: 0 10px 24px rgba(30, 64, 175, 0.1);
        }
        a {
            color: #1d4ed8;
            text-decoration: none;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <div class="box">
        <div>Redirecting to breakfast portal...</div>
        <div style="margin-top:8px;"><a href="<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>">Open Portal</a></div>
    </div>
    <script>
        window.location.replace(<?php echo json_encode($target); ?>);
    </script>
</body>
</html>
<?php
    exit;
} catch (Exception $e) {
    http_response_code(500);
    exit('Terjadi kesalahan sistem');
}
