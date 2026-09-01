<?php
/**
 * PWA Icon Generator — outputs PNG for iOS apple-touch-icon
 * If custom icon is uploaded via developer panel, serve that instead.
 * Otherwise generates one dynamically via GD.
 */
$size = (int)($_GET['size'] ?? 192);
$size = in_array($size, [120, 152, 167, 180, 192, 512]) ? $size : 192;

// Check for custom icon from developer settings
try {
    require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
    require_once dirname(dirname(dirname(__FILE__))) . '/config/database.php';
    // Settings (login_logo, pwa_app_icon) are stored by developer-settings.php
    // which uses Database::getInstance() — same DB as login.php reads from
    $db = Database::getInstance();
    // Check pwa_app_icon first, then login_logo
    $customIcon = null;
    $iconLocalPrefix = '';
    $iconKeys = [
        'pwa_app_icon' => 'uploads/icons/',
        'login_logo'   => 'uploads/logos/',
    ];
    foreach ($iconKeys as $iconKey => $localPrefix) {
        $iconSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$iconKey]);
        if (!empty($iconSetting['setting_value'])) {
            $customIcon = $iconSetting['setting_value'];
            $iconLocalPrefix = $localPrefix;
            break;
        }
    }
    
    if ($customIcon) {
        // Cloudinary / external URL — proxy content directly (no redirect)
        if (strpos($customIcon, 'http') === 0) {
            while (ob_get_level()) ob_end_clean();
            $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'ADF-PWA-Icon/1.0']]);
            $imgData = @file_get_contents($customIcon, false, $ctx);
            if ($imgData !== false) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($imgData) ?: 'image/png';
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . strlen($imgData));
                header('Cache-Control: public, max-age=86400');
                header('X-Content-Type-Options: nosniff');
                echo $imgData;
                exit;
            }
            // Fallback: redirect if proxy fails
            header('Location: ' . $customIcon);
            exit;
        }
        // Local file — prepend directory prefix
        $localPath = dirname(dirname(dirname(__FILE__))) . '/' . $iconLocalPrefix . $customIcon;
        if (file_exists($localPath)) {
            while (ob_get_level()) ob_end_clean();
            $mime = mime_content_type($localPath);
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=3600');
            header('X-Content-Type-Options: nosniff');
            readfile($localPath);
            exit;
        }
    }
} catch (Exception $e) {
    // Fall through to default icon generation
}

// Clean any buffered output before sending image
while (ob_get_level()) ob_end_clean();

header('Content-Type: image/png');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(404); exit;
}

$img = imagecreatetruecolor($size, $size);
imagesavealpha($img, true);

// Colors
$navy     = imagecolorallocate($img, 13,  31,  60);   // #0d1f3c
$gold     = imagecolorallocate($img, 240, 180, 41);   // #f0b429
$darkGold = imagecolorallocate($img, 180, 120, 0);    // #b47800
$white    = imagecolorallocate($img, 255, 255, 255);
$trans    = imagecolorallocatealpha($img, 0, 0, 0, 127);

// ── Background: navy rounded rect ──
imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $trans);
$r = (int)round($size * 0.188); // ~36/192 corner radius

// Fill rounded rectangle manually
imagefilledrectangle($img, $r, 0,      $size - $r - 1, $size - 1, $navy);
imagefilledrectangle($img, 0,  $r,     $size - 1,      $size - $r - 1, $navy);
imagefilledellipse($img, $r,          $r,          $r * 2, $r * 2, $navy);
imagefilledellipse($img, $size - $r,  $r,          $r * 2, $r * 2, $navy);
imagefilledellipse($img, $r,          $size - $r,  $r * 2, $r * 2, $navy);
imagefilledellipse($img, $size - $r,  $size - $r,  $r * 2, $r * 2, $navy);

// ── Gold bottom bar (occupies bottom 17%) ──
$barY = (int)round($size * 0.822);
imagefilledrectangle($img, 0, $barY, $size - 1, $size - 1, $gold);
// re-apply rounded corners at very bottom
imagefilledellipse($img, $r,         $size - $r,  $r * 2, $r * 2, $gold);
imagefilledellipse($img, $size - $r, $size - $r,  $r * 2, $r * 2, $gold);
// Clip navy corners above bar
imagefilledrectangle($img, 0, $barY, $r - 1,      $barY + $r - 1, $navy);
imagefilledrectangle($img, $size - $r, $barY, $size - 1, $barY + $r - 1, $navy);

// ── "AdF" text — try TTF first, fallback to built-in GD scaled text ──
$drawn = false;
$ttfCandidates = [
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
    '/usr/share/fonts/truetype/ubuntu/Ubuntu-B.ttf',
    '/usr/share/fonts/truetype/msttcorefonts/Arial_Bold.ttf',
    '/usr/share/fonts/truetype/Arial_Bold.ttf',
    '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
    'C:\\Windows\\Fonts\\arialbd.ttf',
    'C:\\Windows\\Fonts\\Arial Bold.ttf',
];

foreach ($ttfCandidates as $font) {
    if (file_exists($font)) {
        $fontSize = (int)round($size * 0.40);
        $bbox     = imagettfbbox($fontSize, 0, $font, 'AdF');
        $tw = $bbox[2] - $bbox[0];
        $th = $bbox[1] - $bbox[7];
        $tx = (int)(($size - $tw) / 2) - $bbox[0];
        $ty = (int)(($barY - $th) / 2 + $th + ($size * 0.06));
        imagettftext($img, $fontSize, 0, $tx, $ty, $gold, $font, 'AdF');
        $drawn = true;
        break;
    }
}

if (!$drawn) {
    // Fallback: draw "A d F" using thick geometric shapes
    $ctr  = (int)($size / 2);
    $midY = (int)($barY / 2);
    $unit = (int)round($size * 0.07);

    // Draw three vertical gold bars (like a stylized "AdF")
    $barW = $unit;
    $barH = (int)round($size * 0.38);
    $gap  = (int)round($size * 0.1);
    $x0   = $ctr - $barW * 1 - $gap;
    $x1   = $ctr;
    $x2   = $ctr + $barW + $gap;
    $yTop = $midY - (int)($barH / 2);
    $yBot = $yTop + $barH;

    imagefilledrectangle($img, $x0 - $barW, $yTop, $x0,            $yBot, $gold);
    imagefilledrectangle($img, $x1 - (int)($barW/2), $yTop, $x1 + (int)($barW/2), $yBot, $gold);
    imagefilledrectangle($img, $x2,          $yTop, $x2 + $barW,   $yBot, $gold);
    // Cross-bar on first "A"
    $cbY = $yTop + (int)($barH * 0.45);
    imagefilledrectangle($img, $x0 - $barW, $cbY, $x0 + $barW, $cbY + $unit, $gold);
    // Dot on "d" (circle)
    imagefilledellipse($img, $x1 + $barW + (int)($unit*1.5), $yTop + $unit + (int)($unit/2), $unit + 2, $unit + 2, $gold);
}

// ── "ABSEN" text in bar (using GD built-in) ──
// Scale a small text block up to fit the bar
$label    = 'ABSEN';
$barCenter = (int)(($barY + $size) / 2);
$textImg  = imagecreatetruecolor(strlen($label) * 9, 15);
imagefill($textImg, 0, 0, imagecolorallocate($textImg, 240, 180, 41));
$navyTxt = imagecolorallocate($textImg, 13, 31, 60);
imagestring($textImg, 5, 0, 0, $label, $navyTxt);
$labelW = (int)round($size * 0.55);
$labelH = (int)round(($size - $barY) * 0.6);
$labelX = (int)(($size - $labelW) / 2);
$labelY = $barY + (int)(($size - $barY - $labelH) / 2);
imagecopyresized($img, $textImg, $labelX, $labelY, 0, 0, $labelW, $labelH, strlen($label) * 9, 15);
imagedestroy($textImg);

imagepng($img);
imagedestroy($img);
