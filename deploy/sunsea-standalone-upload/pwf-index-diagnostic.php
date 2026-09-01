<?php
/**
 * PWF System - Diagnostic Script
 * Upload ke /public_html/pwf-po-system/index.php untuk test
 */

// Enable all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 0);

echo "<!DOCTYPE html>";
echo "<html><head><title>PWF Diagnostic</title>";
echo "<style>body{font-family:monospace;margin:20px;} .ok{color:green;} .error{color:red;} .info{color:blue;}</style>";
echo "</head><body>";

echo "<h1>PWF System - Diagnostic Report</h1>";
echo "<p>Generated: " . date('Y-m-d H:i:s') . "</p>";
echo "<hr>";

// 1. Check environment
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

echo "<h2>Environment</h2>";
echo "<p><strong>HTTP_HOST:</strong> " . htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'N/A') . "</p>";
echo "<p><strong>Mode:</strong> <span class='" . ($isProduction ? 'info' : 'ok') . "'>" . 
      ($isProduction ? 'PRODUCTION' : 'LOCAL') . "</span></p>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<hr>";

// 2. Check required files
echo "<h2>File Structure</h2>";

$requiredFiles = [
    'config.php' => dirname(__FILE__) . '/config/config.php',
    'index.php' => dirname(__FILE__) . '/index.php',
    'database.sql' => dirname(__FILE__) . '/database.sql',
];

foreach ($requiredFiles as $name => $path) {
    $exists = file_exists($path);
    echo "<p><span class='" . ($exists ? 'ok' : 'error') . "'>";
    echo ($exists ? '✓' : '✗');
    echo "</span> <strong>$name:</strong> " . htmlspecialchars($path) . "</p>";
}
echo "<hr>";

// 3. Check if config.php can be included
echo "<h2>Config Inclusion Test</h2>";
try {
    $configPath = dirname(__FILE__) . '/config/config.php';
    if (file_exists($configPath)) {
        ob_start();
        include_once $configPath;
        $output = ob_get_clean();
        echo "<p class='ok'>✓ config.php included successfully</p>";
        
        // Check if constants are defined
        $constants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'];
        echo "<p><strong>Database Constants:</strong></p>";
        foreach ($constants as $const) {
            if (defined($const)) {
                $value = constant($const);
                if ($const === 'DB_PASS') {
                    echo "<p>  • $const: ******* (hidden)</p>";
                } else {
                    echo "<p>  • $const: " . htmlspecialchars($value) . "</p>";
                }
            } else {
                echo "<p class='error'>  • $const: NOT DEFINED</p>";
            }
        }
    } else {
        echo "<p class='error'>✗ config.php not found at: " . htmlspecialchars($configPath) . "</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Error including config.php:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
echo "<hr>";

// 4. Test database connection
echo "<h2>Database Connection Test</h2>";
if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', 
                      DB_HOST, DB_NAME, DB_CHARSET ?? 'utf8mb4');
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        
        echo "<p class='ok'>✓ Database connection SUCCESS!</p>";
        
        // Check tables
        $result = $pdo->query("SHOW TABLES");
        $tables = $result->fetchAll(PDO::FETCH_ASSOC);
        echo "<p><strong>Tables:</strong> " . count($tables) . " found</p>";
        
        if (!empty($tables)) {
            echo "<ul>";
            foreach ($tables as $table) {
                $tableName = array_values($table)[0];
                echo "<li>" . htmlspecialchars($tableName) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p class='error'>⚠️ Database is empty - need to import schema</p>";
        }
    } catch (PDOException $e) {
        echo "<p class='error'>✗ Database connection FAILED:</p>";
        echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    }
} else {
    echo "<p class='error'>✗ Database constants not defined - config.php may have syntax error</p>";
}

echo "<hr>";
echo "<p><a href='javascript:location.reload()'>Refresh</a> | ";
echo "<a href='https://adfsystem.online/cPanel' target='_blank'>cPanel</a></p>";
echo "</body></html>";
?>
