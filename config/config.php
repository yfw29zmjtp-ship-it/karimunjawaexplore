<?php

/**
 * ADF SYSTEM - Multi-Business Management Platform
 * Configuration File
 */

// START: Buffer & Session MUST be first before any output
if (!ob_get_level()) {
    ob_start();
}

// Session configuration BEFORE any requires
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'NARAYANA_SESSION');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600 * 8);

// Initialize session BEFORE anything else
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);

    // Force app-owned session directory to avoid shared-host path issues.
    $appSessionPath = dirname(__DIR__) . '/tmp_sessions';
    if (!is_dir($appSessionPath)) {
        @mkdir($appSessionPath, 0700, true);
    }
    if (is_dir($appSessionPath) && is_writable($appSessionPath)) {
        session_save_path($appSessionPath);
    }

    // Secure session cookie settings
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookieDomain = '';
    $httpHost = $_SERVER['HTTP_HOST'] ?? '';
    // Share session across www and non-www for production domains
    if (strpos($httpHost, 'localhost') === false && strpos($httpHost, '127.0.0.1') === false) {
        // Strip www prefix and leading dot to get base domain, then prefix with dot
        $baseDomain = preg_replace('/^www\./', '', $httpHost);
        $baseDomain = explode(':', $baseDomain)[0]; // remove port if any
        $cookieDomain = '.' . $baseDomain; // e.g. .pwfoffice.com
    }
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'domain' => $cookieDomain,
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    @session_start();
}

// Prevent direct access
defined('APP_ACCESS') or define('APP_ACCESS', true);

// ============================================
// APPLICATION SETTINGS
// ============================================
if (!defined('APP_NAME')) define('APP_NAME', 'ADF System - Multi-Business Management');
if (!defined('APP_VERSION')) define('APP_VERSION', '2.0.0');
if (!defined('APP_YEAR')) define('APP_YEAR', '2026');
if (!defined('DEVELOPER_NAME')) define('DEVELOPER_NAME', 'Ariefsystemdesign.net');
if (!defined('DEVELOPER_LOGO')) define('DEVELOPER_LOGO', 'assets/img/developer-logo.png');

// ============================================
// DATABASE CONFIGURATION
// ============================================
// Local config (override for production in separate file if needed)
$httpHostForDb = $_SERVER['HTTP_HOST'] ?? '';
$isProduction = (strpos($httpHostForDb, 'localhost') === false &&
    strpos($httpHostForDb, '127.0.0.1') === false);

if ($isProduction) {
    // Allow a per-hosting-account override BEFORE the hardcoded defaults below.
    // Used when this SAME codebase is deployed standalone on a DIFFERENT cPanel
    // account/server (e.g. Sunsea / Explore Karimunjawa on its own hosting).
    // This file is NEVER committed to git (see .gitignore) - each server keeps
    // its own copy with its own DB credentials. Copy
    // config/local-db-config.example.php -> config/local-db-config.php on that
    // server and fill in its real MySQL host/db/user/pass.
    $__localDbConfig = __DIR__ . '/local-db-config.php';
    if (file_exists($__localDbConfig)) {
        require $__localDbConfig;
    }
    unset($__localDbConfig);

    // Production (Hosting) - adfsystem.online, uses adf_system database prefixed
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', 'adfb2574_adf');
    if (!defined('DB_USER')) define('DB_USER', 'adfb2574_adfsystem');
    if (!defined('DB_PASS')) define('DB_PASS', '@Nnoc2025');
} else {
    // Local development - uses adf_system as master database
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', 'adf_system');
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

        // If in localhost and script path contains adf_system, use it
        if (
            strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
            strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false
        ) {
            // Local development - use /adf_system
            $basePath = '/adf_system';
        } else {
            // Production - assume at root (or detect from script path)
            $basePath = '';
        }

        define('BASE_URL', $protocol . '://' . $host . $basePath);
    } else {
        // For CLI, just use a placeholder
        define('BASE_URL', 'http://localhost/adf_system');
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
// DATABASE NAME HELPER (Production vs Local)
// ============================================
/**
 * Get correct database name for production or local
 * @param string $localDbName - Local database name (e.g., 'adf_system', 'adf_narayana_hotel')
 * @return string - Correct database name for current environment
 */
function getDbName($localDbName)
{
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

    if (!$isProduction) {
        return $localDbName;
    }

    // Production database mapping (known databases)
    $dbMapping = [
        'adf_system' => 'adfb2574_adf',
        'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
        'adf_benscafe' => 'adfb2574_Adf_Bens',
        'adf_demo' => 'adfb2574_demo'
    ];

    if (isset($dbMapping[$localDbName])) {
        return $dbMapping[$localDbName];
    }

    // Auto-generate for new/unknown databases on hosting
    // Derive prefix from DB_USER (e.g., 'adfb2574_adfsystem' -> 'adfb2574_')
    $prefix = '';
    if (defined('DB_USER')) {
        $parts = explode('_', DB_USER);
        if (count($parts) >= 2) {
            $prefix = $parts[0] . '_';
        }
    }

    if ($prefix && strpos($localDbName, $prefix) !== 0) {
        // Strip 'adf_' prefix and add hosting prefix: adf_cafe -> adfb2574_cafe
        if (strpos($localDbName, 'adf_') === 0) {
            return $prefix . substr($localDbName, 4);
        }
        return $prefix . $localDbName;
    }

    return $localDbName;
}

// Also make it available as constant for master DB.
// NOTE: uses DB_NAME directly (not getDbName('adf_system')) so it stays correct
// per-domain — DB_NAME above is already the right master DB name for whichever
// domain/hosting account is currently running this request.
if (!defined('MASTER_DB_NAME')) define('MASTER_DB_NAME', DB_NAME);

// ============================================
// PAGINATION
// ============================================
if (!defined('RECORDS_PER_PAGE')) define('RECORDS_PER_PAGE', 25);

// ============================================
// DATE FORMAT
// ============================================
if (!defined('DATE_FORMAT')) define('DATE_FORMAT', 'd/m/Y');
if (!defined('DATETIME_FORMAT')) define('DATETIME_FORMAT', 'd/m/Y H:i');
if (!defined('TIME_FORMAT')) define('TIME_FORMAT', 'H:i');

// ============================================
// ADDON DOMAIN ROUTING
// Auto-switch active business based on incoming domain
// e.g. pwfoffice.online → pwf-furniture
// adfsystem.online → no override (use session/default)
// ============================================
if (!defined('MASTER_DOMAIN')) define('MASTER_DOMAIN', 'adfsystem.online');

if (php_sapi_name() !== 'cli') {
    $incomingHost = strtolower(preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? ''));
    $masterDomain = strtolower(MASTER_DOMAIN);

    // Only do domain routing if NOT on master domain and NOT on localhost
    if (
        $incomingHost && $incomingHost !== $masterDomain
        && strpos($incomingHost, 'localhost') === false
        && strpos($incomingHost, '127.0.0.1') === false
    ) {

        // Lookup business with this addon_domain in master DB
        try {
            $__domainPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $__domainStmt = $__domainPdo->prepare(
                "SELECT slug FROM businesses WHERE LOWER(REPLACE(addon_domain,'www.','')) = ? AND is_active = 1 LIMIT 1"
            );
            $__domainStmt->execute([$incomingHost]);
            $__domainBiz = $__domainStmt->fetch();

            if ($__domainBiz && !empty($__domainBiz['slug'])) {
                // Force this business as active for this request
                // Only override if not already set to this business (prevents loop)
                if (($_SESSION['active_business_id'] ?? '') !== $__domainBiz['slug']) {
                    $_SESSION['active_business_id'] = $__domainBiz['slug'];
                    unset($_SESSION['business_id']); // will be re-resolved below
                }

                // ── Auto-redirect root requests to the business landing page ──
                // Map: business slug → landing file at project root
                $__landingMap = [
                    'pwf-furniture' => '/pwf-login.php',
                    'sunsea'        => '/login.php?biz=sunsea',
                    // add more: 'cqc-construction' => '/cqc.php', etc.
                ];
                $__landing = $__landingMap[$__domainBiz['slug']] ?? null;
                $__reqUri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
                $__isRoot  = ($__reqUri === '/' || $__reqUri === '/index.php');
                if ($__landing && $__isRoot) {
                    $__proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    header('Location: ' . $__proto . '://' . $_SERVER['HTTP_HOST'] . $__landing);
                    exit;
                }
                unset($__landingMap, $__landing, $__reqUri, $__isRoot);
            }
            unset($__domainPdo, $__domainStmt, $__domainBiz);
        } catch (Exception $__e) {
            // Silently fail — don't break the app if addon_domain column missing
        }
    }
    unset($incomingHost, $masterDomain);
}

// ============================================
// MULTI-BUSINESS CONFIGURATION
// ============================================
require_once __DIR__ . '/../includes/business_helper.php';

$activeBusinessId = getActiveBusinessId();
$BUSINESS_CONFIG = getActiveBusinessConfig();

// AUTO-FIX: Ensure numeric business_id is set from active_business_id
if (isset($_SESSION['active_business_id']) && empty($_SESSION['business_id'])) {
    $numericId = getNumericBusinessId($_SESSION['active_business_id']);
    if ($numericId) {
        $_SESSION['business_id'] = $numericId;
        error_log("CONFIG: Auto-set business_id={$numericId} from active_business_id={$_SESSION['active_business_id']}");
    }
}

define('ACTIVE_BUSINESS_ID', $activeBusinessId);
define('BUSINESS_NAME', $BUSINESS_CONFIG['name']);
define('BUSINESS_TYPE', $BUSINESS_CONFIG['business_type']);
define('BUSINESS_ICON', $BUSINESS_CONFIG['theme']['icon']);
define('BUSINESS_COLOR', $BUSINESS_CONFIG['theme']['color_primary']);

// ============================================
// LANGUAGE CONFIGURATION
// ============================================
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/language.php';
