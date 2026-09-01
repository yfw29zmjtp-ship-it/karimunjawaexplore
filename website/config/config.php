<?php

/**
 * NARAYANA KARIMUNJAWA - Configuration
 * Connects to MAIN hotel system database for real-time room & booking sync
 */

// Detect environment
$isLocal = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'] ?? '127.0.0.1', '127.0.0.1') !== false);

// Database Configuration — DUAL DATABASE SETUP
// Database Sistem: untuk ambil data master (room types, harga, occupancy) - READ ONLY
// Database Website: untuk simpan booking customer, payment - READ WRITE

if ($isLocal) {
    // Database SISTEM ADF (untuk data master room, harga, occupancy)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'adf_narayana_hotel');   // Sistem hotel management
    define('DB_PORT', 3306);

    // Database WEBSITE (untuk booking customer, payment)
    define('DB_WEB_HOST', 'localhost');
    define('DB_WEB_USER', 'root');
    define('DB_WEB_PASS', '');
    define('DB_WEB_NAME', 'adf_web_narayana'); // Website booking database
    define('DB_WEB_PORT', 3306);
} else {
    // PRODUCTION — narayanakarimunjawa.com
    // Database SISTEM ADF (untuk data master)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'adfb2574_adfsystem');
    define('DB_PASS', '@Nnoc2025');
    define('DB_NAME', 'adfb2574_narayana_hotel'); // Sistem hotel management
    define('DB_PORT', 3306);

    // Database WEBSITE (untuk booking customer)
    define('DB_WEB_HOST', 'localhost');
    define('DB_WEB_USER', 'adfb2574_adfsystem');
    define('DB_WEB_PASS', '@Nnoc2025');
    define('DB_WEB_NAME', 'adfb2574_web_narayana'); // Website booking database
    define('DB_WEB_PORT', 3306);
}

// Site Configuration
define('SITE_NAME', 'Narayana Karimunjawa');
define('SITE_TAGLINE', 'Island Paradise Resort');
define('SITE_DESCRIPTION', 'Luxury beachfront resort in the heart of Karimunjawa Islands. Premium accommodations with stunning ocean views.');

// URLs
if ($isLocal) {
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '/narayanakarimunjawa/public/index.php';
    $base_path = dirname($script_name);
    if ($base_path === '/' || $base_path === '\\') $base_path = '';
} else {
    // Production: domain root = public_html = website root
    $base_path = '';
}
define('SITE_URL', 'http' . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
define('BASE_URL', $base_path);

// Channel Manager (Cloudbeds) Booking URL
define('CLOUDBEDS_RESERVATION_BASE_URL', 'https://hotels.cloudbeds.com/reservation/RHAfo6');
define('CLOUDBEDS_CURRENCY', 'idr');
define('CLOUDBEDS_UTM_SOURCE', 'google');
define('CLOUDBEDS_UTM_MEDIUM', 'fbl');
define('CLOUDBEDS_UTM_CAMPAIGN', 'cloudbeds');
define('CLOUDBEDS_UTM_TERM', '3541085-gh3-92');
define('CLOUDBEDS_RID', '651704');

// Business Information
define('BUSINESS_EMAIL', 'narayanahotelkarimunjawa@gmail.com');
define('BUSINESS_PHONE', '+62 812-2222-8590');
define('BUSINESS_ADDRESS', 'Karimunjawa, Jepara, Central Java, Indonesia 59455');
define('BUSINESS_WHATSAPP', '6281222228590');
define('BUSINESS_INSTAGRAM', 'narayanakarimunjawa');
define('BUSINESS_CHECKIN_TIME', '14:00');
define('BUSINESS_CHECKOUT_TIME', '12:00');

// Payment Gateway
define('MIDTRANS_MERCHANT_ID', 'MERCHANT_ID');
define('MIDTRANS_CLIENT_KEY', 'CLIENT_KEY');
define('MIDTRANS_SERVER_KEY', 'SERVER_KEY');
define('MIDTRANS_IS_PRODUCTION', !$isLocal);

// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-app-password');

// Debug Mode
define('DEBUG_MODE', $isLocal);

// Session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', !$isLocal);
    session_start();
}

// Error Reporting
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/error.log');
}

if (!is_dir(__DIR__ . '/../logs')) {
    @mkdir(__DIR__ . '/../logs', 0755, true);
}

// PDO Database Connections — DUAL DATABASE SETUP
// $pdo → Database SISTEM (room types, rooms, harga) - READ ONLY
// $pdo_web → Database WEBSITE (bookings, payments, guests) - READ WRITE

try {
    // Koneksi ke Database SISTEM (untuk ambil data master)
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    if (DEBUG_MODE) {
        die('Database Connection Error (System): ' . $e->getMessage());
    } else {
        die('Service temporarily unavailable. Please try again later.');
    }
}

// Koneksi ke Database WEBSITE (opsional — untuk booking customer)
$pdo_web = null;
try {
    $pdo_web = new PDO(
        'mysql:host=' . DB_WEB_HOST . ';port=' . DB_WEB_PORT . ';dbname=' . DB_WEB_NAME . ';charset=utf8mb4',
        DB_WEB_USER,
        DB_WEB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    // Web database belum ada — tidak fatal, homepage tetap bisa jalan
    if (DEBUG_MODE) {
        error_log('Web Database not available: ' . $e->getMessage());
    }
}

// Helper Functions — DUAL DATABASE SUPPORT

// === Database SISTEM (room types, harga, occupancy) - READ ONLY ===
function dbQuery($sql, $params = [])
{
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function dbFetch($sql, $params = [])
{
    return dbQuery($sql, $params)->fetch();
}

function dbFetchAll($sql, $params = [])
{
    return dbQuery($sql, $params)->fetchAll();
}

// === Database WEBSITE (bookings, payments, guests) - READ WRITE ===
function dbWebQuery($sql, $params = [])
{
    global $pdo_web;
    $stmt = $pdo_web->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function dbWebFetch($sql, $params = [])
{
    return dbWebQuery($sql, $params)->fetch();
}

function dbWebFetchAll($sql, $params = [])
{
    return dbWebQuery($sql, $params)->fetchAll();
}

function dbWebInsert($table, $data)
{
    global $pdo_web;
    $cols = implode(', ', array_keys($data));
    $vals = implode(', ', array_fill(0, count($data), '?'));
    $sql = "INSERT INTO $table ($cols) VALUES ($vals)";
    $stmt = $pdo_web->prepare($sql);
    $stmt->execute(array_values($data));
    return $pdo_web->lastInsertId();
}

function dbWebUpdate($table, $data, $where, $whereParams = [])
{
    global $pdo_web;
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
    $sql = "UPDATE $table SET $set WHERE $where";
    $params = array_merge(array_values($data), $whereParams);
    $stmt = $pdo_web->prepare($sql);
    return $stmt->execute($params);
}

// === BACKWARD COMPATIBILITY (untuk data website) ===
function dbInsert($table, $data)
{
    return dbWebInsert($table, $data);
}

function dbUpdate($table, $data, $where, $whereParams = [])
{
    return dbWebUpdate($table, $data, $where, $whereParams);
}

function formatCurrency($amount)
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function formatDate($date)
{
    return date('d M Y', strtotime($date));
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function json_response($success, $data = null, $error = null)
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'error' => $error
    ]);
    exit;
}

/**
 * Build Cloudbeds reservation URL with optional dynamic date/guest parameters.
 */
function buildCloudbedsReservationUrl(array $overrides = []): string
{
    $today = new DateTime('today');
    $tomorrow = (clone $today)->modify('+1 day');
    $dayAfter = (clone $tomorrow)->modify('+1 day');

    $params = [
        'currency' => CLOUDBEDS_CURRENCY,
        'utm_source' => CLOUDBEDS_UTM_SOURCE,
        'utm_medium' => CLOUDBEDS_UTM_MEDIUM,
        'utm_campaign' => CLOUDBEDS_UTM_CAMPAIGN,
        'utm_term' => CLOUDBEDS_UTM_TERM,
        'checkin' => $tomorrow->format('Y-m-d'),
        'checkout' => $dayAfter->format('Y-m-d'),
        'adults' => 2,
        'kids' => 0,
        'rid' => CLOUDBEDS_RID,
    ];

    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $params[$k] = $v;
    }

    if (!empty($params['checkin']) && !empty($params['checkout'])) {
        $in = strtotime((string)$params['checkin']);
        $out = strtotime((string)$params['checkout']);
        if ($in !== false && $out !== false && $out <= $in) {
            $params['checkout'] = date('Y-m-d', strtotime('+1 day', $in));
        }
    }

    return CLOUDBEDS_RESERVATION_BASE_URL . '?' . http_build_query($params);
}
