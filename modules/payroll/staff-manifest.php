<?php
/**
 * Staff Portal PWA Manifest
 * Makes the staff portal installable as mobile app
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=300');

$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizName = 'Staff Portal';

if ($bizSlug) {
    $bizFile = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
    if (file_exists($bizFile)) {
        $cfg = require $bizFile;
        $bizName = ($cfg['name'] ?? 'Staff Portal');
    }
}

// Resolve icon: pwa_app_icon > login_logo > fallback GD icon
// All URLs must be ABSOLUTE for Android WebAPK generation
$baseHttpUrl = defined('BASE_URL') ? BASE_URL : '';
$moduleUrl = $baseHttpUrl . '/modules/payroll';
$iconUrl192 = $moduleUrl . '/absen-icon.php?size=192';
$iconUrl512 = $moduleUrl . '/absen-icon.php?size=512';
$iconType = 'image/png';
$rootDir = dirname(dirname(__DIR__));
try {
    $mdb = Database::getInstance();
    $iconKeys = [
        'pwa_app_icon' => 'uploads/icons/',
        'login_logo'   => 'uploads/logos/',
    ];
    foreach ($iconKeys as $iconKey => $localPrefix) {
        $iconRow = $mdb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$iconKey]);
        $iconVal = $iconRow['setting_value'] ?? null;
        if (!$iconVal) continue;
        if (strpos($iconVal, 'http') === 0) {
            $iconUrl192 = $iconVal;
            $iconUrl512 = $iconVal;
            if (preg_match('/\.(png)$/i', $iconVal)) $iconType = 'image/png';
            elseif (preg_match('/\.(jpe?g)$/i', $iconVal)) $iconType = 'image/jpeg';
            else $iconType = 'image/png';
            break;
        } else {
            $fullPath = $rootDir . '/' . $localPrefix . $iconVal;
            if (file_exists($fullPath)) {
                $iconUrl192 = $baseHttpUrl . '/' . $localPrefix . $iconVal;
                $iconUrl512 = $iconUrl192;
                $ext = strtolower(pathinfo($iconVal, PATHINFO_EXTENSION));
                $iconType = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
                break;
            }
        }
    }
} catch (Exception $e) {}

$startUrl = $moduleUrl . '/staff-portal.php' . ($bizSlug ? '?b=' . urlencode($bizSlug) : '');
$scopeUrl = $moduleUrl . '/';
$manifestId = '/modules/payroll/staff-portal' . ($bizSlug ? '?b=' . $bizSlug : '');

while (ob_get_level()) ob_end_clean();
echo json_encode([
    'id'               => $manifestId,
    'name'             => $bizName . ' — Staff Portal',
    'short_name'       => 'Staff Portal',
    'description'      => 'Portal karyawan: absensi, monitoring, cuti, occupancy, breakfast',
    'start_url'        => $startUrl,
    'scope'            => $scopeUrl,
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'theme_color'      => '#0d1f3c',
    'background_color' => '#0d1f3c',
    'lang'             => 'id',
    'categories'       => ['business', 'productivity'],
    'prefer_related_applications' => false,
    'icons'            => [
        [
            'src'     => $iconUrl192,
            'sizes'   => '192x192',
            'type'    => $iconType,
            'purpose' => 'any',
        ],
        [
            'src'     => $iconUrl512,
            'sizes'   => '512x512',
            'type'    => $iconType,
            'purpose' => 'any',
        ],
        [
            'src'     => $iconUrl512,
            'sizes'   => '512x512',
            'type'    => $iconType,
            'purpose' => 'maskable',
        ],
        [
            'src'     => $iconUrl192,
            'sizes'   => '192x192',
            'type'    => $iconType,
            'purpose' => 'maskable',
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
