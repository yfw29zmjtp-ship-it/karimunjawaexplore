<?php

/**
 * Owner Portal PWA Manifest — makes the mobile owner dashboard installable on phone.
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/db-helper.php';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=300');

$pdo = getSunseaConnection();
$companyName = sunseaSetting($pdo, 'company_name', 'Karimunjawa Explore');
$logoPath = sunseaSetting($pdo, 'company_logo', '');
$iconUrl = $logoPath ? sunseaAssetUrl($logoPath) : (BASE_URL . '/modules/sunsea/assets/owner-icon.png');
$moduleUrl = BASE_URL . '/modules/sunsea';

while (ob_get_level()) {
    ob_end_clean();
}
echo json_encode([
    'id'               => '/modules/sunsea/owner-dashboard',
    'name'             => $companyName . ' — Owner Portal',
    'short_name'       => 'Owner Portal',
    'description'      => 'Portal owner: reservasi, kalender booking, invoice, dan finance',
    'start_url'        => $moduleUrl . '/owner-login.php',
    'scope'            => $moduleUrl . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'theme_color'      => '#0369A1',
    'background_color' => '#F8FAFC',
    'lang'             => 'id',
    'categories'       => ['business', 'productivity'],
    'prefer_related_applications' => false,
    'icons' => [
        ['src' => $iconUrl, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconUrl, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ],
], JSON_UNESCAPED_SLASHES);
