<?php

/**
 * Buyer Portal PWA manifest
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/db-helper.php';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=300');

$_isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
$_pwfDb = $_isProduction ? 'adfb2574_pwf' : 'adf_pwf';
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $_pwfDb . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    if (function_exists('ensurePwfOfficeTables')) {
        ensurePwfOfficeTables($pdo);
    }
} catch (PDOException $e) {
    $_altDb = $_isProduction ? 'adfb2574_pwf' : 'adf_system_pwf';
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $_altDb . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$queryText = trim((string)($_GET['q'] ?? $_GET['c'] ?? ''));
$queryText = preg_replace('/[^a-zA-Z0-9\-\s]/', '', $queryText);
$buyerKey = trim((string)($_GET['buyer_key'] ?? ''));
$buyerKey = preg_replace('/[^a-zA-Z0-9]/', '', $buyerKey);
$buyerName = '';

$companyName = 'PWF Buyer Portal';
$iconUrl = $baseUrl . '/favicon.ico';
$iconType = 'image/png';

try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_favicon','pwf_login_logo')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['pwf_company_name'])) {
        $companyName = $rows['pwf_company_name'] . ' Buyer Portal';
    }

    $iconCandidate = '';
    if (!empty($rows['pwf_favicon'])) {
        $iconCandidate = $rows['pwf_favicon'];
    } elseif (!empty($rows['pwf_login_logo'])) {
        $iconCandidate = $rows['pwf_login_logo'];
    }

    if ($iconCandidate !== '') {
        $iconUrl = (strpos($iconCandidate, 'http') === 0)
            ? $iconCandidate
            : $baseUrl . '/' . ltrim($iconCandidate, '/');
        $ext = strtolower(pathinfo(parse_url($iconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            $iconType = 'image/jpeg';
        } elseif ($ext === 'svg') {
            $iconType = 'image/svg+xml';
        } else {
            $iconType = 'image/png';
        }
    }
} catch (Exception $e) {
}

if ($buyerKey !== '') {
    try {
        $stmtBuyer = $pdo->prepare("SELECT buyer_name FROM pwf_buyers WHERE access_key = ? AND is_active = 1 LIMIT 1");
        $stmtBuyer->execute([$buyerKey]);
        $buyerRow = $stmtBuyer->fetch(PDO::FETCH_ASSOC);
        if ($buyerRow) {
            $buyerName = (string)$buyerRow['buyer_name'];
            $companyName = $companyName . ' - ' . $buyerName;
        }
    } catch (Exception $e) {
    }
}

$startUrl = $baseUrl . '/modules/pwf-office/customer-portal.php';
$queryParams = [];
if ($queryText !== '') {
    $queryParams['q'] = $queryText;
}
if ($buyerKey !== '') {
    $queryParams['buyer_key'] = $buyerKey;
}
if (!empty($queryParams)) {
    $startUrl .= '?' . http_build_query($queryParams);
}

while (ob_get_level()) {
    ob_end_clean();
}

echo json_encode([
    'id' => '/modules/pwf-office/customer-portal' . (!empty($queryParams) ? '?' . http_build_query($queryParams) : ''),
    'name' => $companyName,
    'short_name' => 'Buyer Portal',
    'description' => 'Buyer monitoring portal: multi-customer orders, progress, containers, and monthly recap',
    'start_url' => $startUrl,
    'scope' => $baseUrl . '/modules/pwf-office/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'theme_color' => '#0E223D',
    'background_color' => '#0E223D',
    'lang' => 'en',
    'prefer_related_applications' => false,
    'icons' => [
        [
            'src' => $iconUrl,
            'sizes' => '192x192',
            'type' => $iconType,
            'purpose' => 'any maskable',
        ],
        [
            'src' => $iconUrl,
            'sizes' => '512x512',
            'type' => $iconType,
            'purpose' => 'any maskable',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
