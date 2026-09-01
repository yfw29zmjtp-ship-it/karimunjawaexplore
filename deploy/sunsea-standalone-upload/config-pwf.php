<?php
/**
 * PWF PO System - Configuration File
 * Separate configuration for PWF PO System project
 * This file overrides database settings for PWF-specific database
 */

// START: Buffer & Session MUST be first before any output
if (!ob_get_level()) {
    ob_start();
}

// Session configuration BEFORE any requires
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'PWF_SESSION');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600 * 8);

// Initialize session BEFORE anything else
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    
    // Secure session cookie settings
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
}

// Prevent direct access
defined('APP_ACCESS') or define('APP_ACCESS', true);

// ============================================
// APPLICATION SETTINGS
// ============================================
if (!defined('APP_NAME')) define('APP_NAME', 'PWF PO System - Purchase Order Management');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');
if (!defined('APP_YEAR')) define('APP_YEAR', '2026');
if (!defined('DEVELOPER_NAME')) define('DEVELOPER_NAME', 'Ariefsystemdesign.net');
if (!defined('DEVELOPER_LOGO')) define('DEVELOPER_LOGO', 'assets/img/developer-logo.png');

// ============================================
// DATABASE CONFIGURATION - PWF SPECIFIC
// ============================================
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

if ($isProduction) {
    // Production (Hosting) - PWF database
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', 'adfb2574_pwf_po_system');
    if (!defined('DB_USER')) define('DB_USER', 'adfb2574_pwf_user');
    if (!defined('DB_PASS')) define('DB_PASS', 'Pwf123456');
} else {
    // Local development - PWF local database
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', 'pwf_po_system');
    if (!defined('DB_USER')) define('DB_USER', 'root');
    if (!defined('DB_PASS')) define('DB_PASS', '');
}
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// ============================================
// PATH CONFIGURATION
// ============================================
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(dirname(__FILE__)));

// Handle both web and CLI environment
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$port = $_SERVER['SERVER_PORT'] ?? '80';
$portSuffix = ($port != '80' && $port != '443') ? ':' . $port : '';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// Only define BASE_URL in web environment
if (!defined('BASE_URL')) {
    if (php_sapi_name() !== 'cli') {
        // Detect if running at root or in subdirectory
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $basePath = '';
        
        // If in localhost and script path contains pwf, use it
        if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
            strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false) {
            // Local development - use /pwf_po_system
            $basePath = '/narayanakarimunjawa/pwf_po_system';
        } else {
            // Production - assume at root (or detect from script path)
            $basePath = '';
        }
        
        define('BASE_URL', $protocol . '://' . $host . $basePath);
    } else {
        // For CLI, just use a placeholder
        define('BASE_URL', 'http://localhost/pwf_po_system');
    }
}

// ============================================
// TIMEZONE
// ============================================
date_default_timezone_set('Asia/Jakarta');

// ============================================
// ERROR REPORTING
// ============================================
if ($isProduction) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// ============================================
// CURRENCY FORMAT
// ============================================
if (!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', 'Rp');
if (!defined('CURRENCY_DECIMAL')) define('CURRENCY_DECIMAL', 0);

// ============================================
// DATABASE CLASS & HELPER FUNCTIONS
// ============================================
require_once dirname(__FILE__) . '/database.php';

// Rest of config file continues below (shared from original config.php if needed)
