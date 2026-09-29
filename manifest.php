<?php

/**
 * Karimunjawa Explore - Web App Manifest
 * Dibuat dinamis (bukan .json statis) supaya ikon & URL selalu ikut BASE_URL,
 * dan supaya "Add to Home Screen" jadi PWA standalone (display:standalone) -
 * ini prasyarat wajib supaya App Badging API (angka merah di ikon) bisa jalan.
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/manifest+json');

$icon = BASE_URL . '/assets/img/developer-logo.png';

echo json_encode([
    'name'             => 'Karimunjawa Explore',
    'short_name'       => 'KJ Explore',
    'start_url'        => BASE_URL . '/index.php',
    'scope'            => BASE_URL . '/',
    'display'          => 'standalone',
    'background_color' => '#0f172a',
    'theme_color'      => '#0f172a',
    'icons'            => [
        [
            'src'   => $icon,
            'sizes' => '500x500',
            'type'  => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
], JSON_UNESCAPED_SLASHES);
