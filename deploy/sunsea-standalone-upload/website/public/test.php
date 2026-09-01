<?php
/**
 * Test file — No database, no config
 * If this page loads, PHP and server are working correctly
 */
echo '<!DOCTYPE html><html><head><title>Test OK</title></head><body>';
echo '<h1 style="color:green;">✅ Server is working!</h1>';
echo '<p>PHP Version: ' . phpversion() . '</p>';
echo '<p>Server Software: ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . '</p>';
echo '<p>Document Root: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . '</p>';
echo '<p>Script Filename: ' . ($_SERVER['SCRIPT_FILENAME'] ?? 'unknown') . '</p>';
echo '<p>Time: ' . date('Y-m-d H:i:s') . '</p>';
echo '<hr>';
echo '<h3>Checking files:</h3>';
echo '<ul>';
$files = ['index.php', 'config/config.php', '.htaccess', 'assets/css/style.css', 'includes/header.php', 'includes/footer.php'];
foreach ($files as $f) {
    $exists = file_exists(__DIR__ . '/' . $f);
    echo '<li>' . $f . ': ' . ($exists ? '✅ EXISTS' : '❌ NOT FOUND') . '</li>';
}
echo '</ul>';
echo '</body></html>';
