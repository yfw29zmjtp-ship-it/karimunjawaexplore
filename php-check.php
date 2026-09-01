<?php
// Temporary diagnostic - DELETE after use
if (!isset($_GET['key']) || $_GET['key'] !== 'adf2024diag') {
    http_response_code(403);
    exit('Forbidden');
}

// Show full phpinfo if requested
if (isset($_GET['info'])) {
    phpinfo();
    exit;
}

echo "<h2>PHP Diagnostic</h2>";
echo "<b>PHP Version:</b> " . PHP_VERSION . "<br>";
echo "<b>PHP Version ID:</b> " . PHP_VERSION_ID . "<br>";
echo "<b>Min required (Composer):</b> 8.2.0 (80200)<br><br>";

if (PHP_VERSION_ID < 80200) {
    echo "<b style='color:red'>&#10060; PHP versi terlalu lama! Perlu upgrade ke PHP 8.2+</b><br>";
} else {
    echo "<b style='color:green'>&#10004; PHP version OK (" . PHP_VERSION . ")</b><br>";
}

echo "<br><b>Extensions loaded:</b><br>";
$required = ['pdo', 'pdo_mysql', 'openssl', 'curl', 'mbstring', 'gmp', 'json'];
foreach ($required as $ext) {
    $loaded = extension_loaded($ext);
    echo ($loaded ? "&#10004;" : "&#10060;") . " $ext<br>";
}

echo "<br><b>Server Software:</b> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "<br>";
echo "<b>Memory Limit:</b> " . ini_get('memory_limit') . "<br>";
echo "<b>Max Execution Time:</b> " . ini_get('max_execution_time') . "s<br>";

// Check vendor autoload
echo "<br><b>Vendor autoload test:</b><br>";
try {
    require_once __DIR__ . '/vendor/autoload.php';
    echo "<span style='color:green'>&#10004; vendor/autoload.php loaded OK</span><br>";
} catch (Throwable $e) {
    echo "<span style='color:red'>&#10060; vendor/autoload.php ERROR: " . htmlspecialchars($e->getMessage()) . "</span><br>";
}

// Check DB connection
echo "<br><b>Database test:</b><br>";
try {
    require_once __DIR__ . '/config/config.php';
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 5]);
    echo "<span style='color:green'>&#10004; Database connected OK</span><br>";
} catch (Throwable $e) {
    echo "<span style='color:red'>&#10060; Database ERROR: " . htmlspecialchars($e->getMessage()) . "</span><br>";
}
