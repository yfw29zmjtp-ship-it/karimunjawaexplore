<?php
/**
 * PWF PO System - Clean Isolated Configuration
 * Database: adfb2574_pwf_po_system (completely separate from ADF)
 * Project: Furniture Factory Purchase Order System
 */

// START: Buffer & Session MUST be first before any output
if (!ob_get_level()) {
    ob_start();
}

// Session configuration BEFORE any requires
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'PWF_PO_SESSION');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600 * 8);

// Initialize session BEFORE anything else
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    
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
define('APP_NAME', 'PWF PO System - Furniture Factory');
define('APP_VERSION', '1.0.0');
define('APP_YEAR', '2026');
define('DEVELOPER_NAME', 'Ariefsystemdesign.net');

// ============================================
// DATABASE CONFIGURATION - PWF ONLY
// NO automatic remapping - direct database connection
// ============================================

// Detect environment
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

if ($isProduction) {
    // PRODUCTION HOSTING
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'adfb2574_pwf_po_system');    // PWF DATABASE ONLY
    define('DB_USER', 'adfb2574_adfsystem');        // Shared user with ADF
    define('DB_PASS', '@Nnoc2025');                 // Same password
} else {
    // LOCAL DEVELOPMENT
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'pwf_po_system');             // Local PWF database
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATION', 'utf8mb4_unicode_ci');

// ============================================
// PATH CONFIGURATION
// ============================================
define('BASE_PATH', dirname(dirname(__FILE__)));
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('MODULES_PATH', BASE_PATH . '/modules');
define('ASSETS_PATH', BASE_PATH . '/assets');

// ============================================
// BASE URL - For PWF Project
// ============================================
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

if (!$isProduction) {
    // Local: http://localhost:8081/narayanakarimunjawa/pwf_po_system/
    define('BASE_URL', $protocol . '://' . $host . '/narayanakarimunjawa/pwf_po_system');
} else {
    // Production: https://pwfoffice.com/ (when addon domain is setup)
    // Or: https://adfsystem.online/pwf-po-system/
    if (strpos($host, 'pwfoffice.com') !== false) {
        define('BASE_URL', $protocol . '://' . $host);
    } else {
        define('BASE_URL', $protocol . '://' . $host . '/pwf-po-system');
    }
}

// ============================================
// TIMEZONE & LOCALE
// ============================================
date_default_timezone_set('Asia/Jakarta');
define('DATE_FORMAT', 'd/m/Y');
define('DATETIME_FORMAT', 'd/m/Y H:i:s');
define('TIME_FORMAT', 'H:i');

// ============================================
// ERROR REPORTING
// ============================================
if ($isProduction) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('log_errors_max_len', 1024);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// ============================================
// CURRENCY & FORMATTING
// ============================================
define('CURRENCY_SYMBOL', 'Rp');
define('CURRENCY_DECIMAL', 0);
define('DECIMAL_SEPARATOR', '.');
define('THOUSANDS_SEPARATOR', ',');

// ============================================
// PAGINATION & DISPLAY
// ============================================
define('RECORDS_PER_PAGE', 25);
define('MAX_UPLOAD_SIZE', 5242880); // 5MB

// ============================================
// DATABASE CONNECTION CLASS (Simple)
// ============================================
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("DATABASE CONNECTION FAILED: " . htmlspecialchars($e->getMessage()));
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
}

// ============================================
// LOAD ADDITIONAL CONFIG FILES
// ============================================
if (file_exists(INCLUDES_PATH . '/database.php')) {
    require_once INCLUDES_PATH . '/database.php';
}

// ============================================
// END CONFIG
// ============================================
?>
