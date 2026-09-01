<?php
/**
 * Room Number Sign Image Generator
 * Self-contained: auto-generates walnut wood texture, uses system fonts
 * Produces transparent PNG matching Narayana Hotel room sign design
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

// ─── Parameters ───────────────────────────────────
$room_number = preg_replace('/[^A-Za-z0-9\-]/', '', substr($_GET['no'] ?? '301', 0, 5)) ?: '301';
$hotel_name  = strtoupper(preg_replace('/[^A-Za-z0-9\s]/', '', substr($_GET['hotel'] ?? 'NARAYANA', 0, 30))) ?: 'NARAYANA';
$sub_text    = strtoupper(preg_replace('/[^A-Za-z0-9\s]/', '', substr($_GET['sub'] ?? 'HOTEL', 0, 20))) ?: 'HOTEL';
$download    = isset($_GET['download']);

if (!extension_loaded('gd')) {
    header('Content-Type: text/plain');
    echo 'GD extension required';
    exit;
}

// ─── Find Font (custom upload → Windows system fonts → Linux fallback) ────
$font = null;
foreach ([
    __DIR__ . '/assets/room-design/font.ttf',
    'C:/Windows/Fonts/georgia.ttf',
    'C:/Windows/Fonts/georgiab.ttf',
    'C:/Windows/Fonts/times.ttf',
    'C:/Windows/Fonts/timesbd.ttf',
    'C:/Windows/Fonts/cambria.ttc',
    'C:/Windows/Fonts/calibri.ttf',
    'C:/Windows/Fonts/arial.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf',
] as $f) {
    if (file_exists($f)) { $font = $f; break; }
}

if (!$font) {
    header('Content-Type: image/png');
    $e = imagecreatetruecolor(400, 200);
    imagefill($e, 0, 0, imagecolorallocate($e, 220, 220, 220));
    imagestring($e, 5, 100, 90, 'No TTF font found', imagecolorallocate($e, 180, 0, 0));
    imagepng($e); imagedestroy($e); exit;
}

// ─── Canvas & Shape Config ────────────────────────
$W = 800;
$H = 900;
$pad = 15;
$halfW  = ($W / 2) - $pad;        // 385 = arch radius
$cX     = $W / 2;                  // 400 = center X
$archCY = $pad + $halfW;           // 400 = arch circle center Y
$botY   = $H - $pad - 15;         // 870 = bottom of shape

$img = imagecreatetruecolor($W, $H);
imagealphablending($img, true);
imagesavealpha($img, true);
imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

// ─── Wood Texture ─────────────────────────────────
$customWood = __DIR__ . '/assets/room-design/wood_texture.jpg';
if (file_exists($customWood)) {
    $woodSrc = imagecreatefromjpeg($customWood);
    $wood = imagecreatetruecolor($W, $H);
    imagecopyresampled($wood, $woodSrc, 0, 0, 0, 0, $W, $H, imagesx($woodSrc), imagesy($woodSrc));
    imagedestroy($woodSrc);
} else {
    // Auto-generate walnut grain at 1/4 scale → upscale (fast + smooth)
    $sf = 4;
    $sw = intdiv($W, $sf);
    $sh = intdiv($H, $sf);
    $sm = imagecreatetruecolor($sw, $sh);

    mt_srand(54321);
    for ($y = 0; $y < $sh; $y++) {
        for ($x = 0; $x < $sw; $x++) {
            // Multi-frequency sine waves = vertical wood grain
            $g = sin($x * 0.12 + sin($y * 0.025) * 3.5) * 16
               + sin($x * 0.055 + cos($y * 0.012) * 5.5) * 11
               + sin($x * 0.28 + $y * 0.004) * 5
               + mt_rand(-500, 500) / 100.0;

            imagesetpixel($sm, $x, $y, imagecolorallocate($sm,
                max(0, min(255, (int)(152 + $g))),
                max(0, min(255, (int)(108 + $g * 0.72))),
                max(0, min(255, (int)(70 + $g * 0.42)))
            ));
        }
    }
    mt_srand();

    $wood = imagecreatetruecolor($W, $H);
    imagecopyresampled($wood, $sm, 0, 0, 0, 0, $W, $H, $sw, $sh);
    imagedestroy($sm);
}

// ─── Apply Arch Mask (scanline-based = fast) ──────
for ($y = 0; $y < $H; $y++) {
    $x0 = -1; $x1 = -1;

    if ($y >= $pad && $y <= $archCY) {
        // Semicircle top
        $dy = $y - $archCY;
        $r2 = $halfW * $halfW - $dy * $dy;
        if ($r2 >= 0) {
            $dx = sqrt($r2);
            $x0 = max(0, (int)ceil($cX - $dx));
            $x1 = min($W - 1, (int)floor($cX + $dx));
        }
    } elseif ($y > $archCY && $y <= $botY) {
        // Rectangle bottom
        $x0 = max(0, (int)ceil($cX - $halfW));
        $x1 = min($W - 1, (int)floor($cX + $halfW));
    }

    if ($x0 >= 0 && $x1 >= $x0) {
        imagecopy($img, $wood, $x0, $y, $x0, $y, $x1 - $x0 + 1, 1);
    }
}
imagedestroy($wood);

// ─── Inner edge darkening (depth illusion) ────────
$edgeDark = imagecolorallocatealpha($img, 60, 35, 15, 80);
$edgeWidth = 6;
for ($e = 0; $e < $edgeWidth; $e++) {
    $alpha = 80 + $e * 8; // Gets lighter inward
    if ($alpha > 120) break;
    $ec = imagecolorallocatealpha($img, 60, 35, 15, $alpha);
    $r = $halfW - $e;
    imagearc($img, (int)$cX, (int)$archCY, (int)($r * 2), (int)($r * 2), 180, 360, $ec);
    // Left & right edges
    imageline($img, (int)($cX - $r), (int)$archCY, (int)($cX - $r), (int)($botY - $e), $ec);
    imageline($img, (int)($cX + $r), (int)$archCY, (int)($cX + $r), (int)($botY - $e), $ec);
    // Bottom edge
    imageline($img, (int)($cX - $r), (int)($botY - $e), (int)($cX + $r), (int)($botY - $e), $ec);
}

// ─── Outer border ─────────────────────────────────
$border = imagecolorallocatealpha($img, 100, 65, 30, 30);
imagesetthickness($img, 3);
imagearc($img, (int)$cX, (int)$archCY, (int)($halfW * 2), (int)($halfW * 2), 180, 360, $border);
imageline($img, (int)($cX - $halfW), (int)$archCY, (int)($cX - $halfW), (int)$botY, $border);
imageline($img, (int)($cX + $halfW), (int)$archCY, (int)($cX + $halfW), (int)$botY, $border);
imageline($img, (int)($cX - $halfW), (int)$botY, (int)($cX + $halfW), (int)$botY, $border);
imagesetthickness($img, 1);

// ─── Colors ───────────────────────────────────────
$goldHL   = imagecolorallocate($img, 235, 210, 155);  // Highlight
$goldMain = imagecolorallocate($img, 210, 175, 100);  // Main gold
$goldSH   = imagecolorallocate($img, 125, 90, 35);    // Shadow
$engrave  = imagecolorallocate($img, 52, 33, 18);     // Dark brown (burned/engraved)

// ─── Room Number (gold 3-layer emboss) ────────────
$rFS = 130;
$rBB = imagettfbbox($rFS, 0, $font, $room_number);
$rX  = ($W - ($rBB[2] - $rBB[0])) / 2 - $rBB[0];
$rY  = 390;

// Shadow (bottom-right offset)
imagettftext($img, $rFS, 0, (int)($rX + 4), (int)($rY + 4), $goldSH, $font, $room_number);
// Main gold body
imagettftext($img, $rFS, 0, (int)($rX + 1), (int)($rY + 1), $goldMain, $font, $room_number);
// Highlight (top-left, bright edge)
imagettftext($img, $rFS, 0, (int)$rX, (int)$rY, $goldHL, $font, $room_number);

// ─── Lotus Logo (engraved) ────────────────────────
$lotusX = (int)$cX;
$lotusY = 560;
$lotusS = 38;
imagesetthickness($img, 2);

$petals = [
    [0, 1.0], [-18, 0.9], [18, 0.9],
    [-35, 0.72], [35, 0.72],
    [-50, 0.5], [50, 0.5],
];
foreach ($petals as $p) {
    $deg = $p[0]; $scale = $p[1];
    $h = $lotusS * $scale;
    $w = $lotusS * 0.13;
    $a = deg2rad($deg);
    $dirX = sin($a);  $dirY = -cos($a);   // petal direction (upward)
    $ppX  = -$dirY;   $ppY  = $dirX;      // perpendicular

    // Tip
    $tx = $lotusX + $dirX * $h;
    $ty = $lotusY + $dirY * $h;
    // Mid-bulge points (at 40% height)
    $mx = $lotusX + $dirX * $h * 0.4;
    $my = $lotusY + $dirY * $h * 0.4;
    $lx = $mx + $ppX * $w; $ly = $my + $ppY * $w;
    $rx = $mx - $ppX * $w; $ry = $my - $ppY * $w;

    // Draw petal outline (4 lines forming leaf shape)
    imageline($img, $lotusX, $lotusY, (int)$lx, (int)$ly, $engrave);
    imageline($img, (int)$lx, (int)$ly, (int)$tx, (int)$ty, $engrave);
    imageline($img, (int)$tx, (int)$ty, (int)$rx, (int)$ry, $engrave);
    imageline($img, (int)$rx, (int)$ry, $lotusX, $lotusY, $engrave);
}
// Lotus base curve
imagearc($img, $lotusX, $lotusY + 4, (int)($lotusS * 0.5), (int)($lotusS * 0.12), 0, 180, $engrave);
imagesetthickness($img, 1);

// ─── Helper: Centered text with letter spacing ───
function drawCenteredText($img, $size, $cx, $y, $color, $font, $text, $spacing = 0) {
    if ($spacing <= 0) {
        $bb = imagettfbbox($size, 0, $font, $text);
        $x = $cx - ($bb[2] - $bb[0]) / 2 - $bb[0];
        imagettftext($img, $size, 0, (int)$x, (int)$y, $color, $font, $text);
        return;
    }
    $chars = str_split($text);
    $widths = [];
    $total = 0;
    foreach ($chars as $i => $c) {
        $bb = imagettfbbox($size, 0, $font, $c);
        $w = $bb[2] - $bb[0];
        $widths[] = $w;
        $total += $w;
        if ($i < count($chars) - 1) $total += $spacing;
    }
    $x = $cx - $total / 2;
    foreach ($chars as $i => $c) {
        $bb = imagettfbbox($size, 0, $font, $c);
        imagettftext($img, $size, 0, (int)($x - $bb[0]), (int)$y, $color, $font, $c);
        $x += $widths[$i] + $spacing;
    }
}

// ─── Hotel Name (engraved, spaced) ────────────────
drawCenteredText($img, 30, (int)$cX, 640, $engrave, $font, $hotel_name, 6);

// ─── Sub Text (engraved, spaced) ──────────────────
drawCenteredText($img, 16, (int)$cX, 680, $engrave, $font, $sub_text, 4);

// ─── Output ───────────────────────────────────────
if ($download) {
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="room-' . $room_number . '.png"');
} else {
    header('Content-Type: image/png');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}
imagepng($img);
imagedestroy($img);
imagedestroy($wood);
if ($wood_resized) imagedestroy($wood_resized);
