<?php
/**
 * DEBUG: List API files
 */

echo "<h1>Available API Files</h1>\n";
echo "<pre>\n";

$apiDir = __DIR__ . '/api';

if (is_dir($apiDir)) {
    $files = scandir($apiDir);
    echo "Files in " . $apiDir . ":\n\n";
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $filePath = $apiDir . '/' . $file;
        $fileSize = filesize($filePath);
        $fileTime = date('Y-m-d H:i:s', filemtime($filePath));
        
        if (is_file($filePath)) {
            echo "✓ $file ($fileSize bytes, $fileTime)\n";
        } else {
            echo "📁 $file/ (directory)\n";
        }
    }
} else {
    echo "ERROR: /api directory not found!\n";
}

echo "\n\nChecking specific file:\n";
$targetFile = __DIR__ . '/api/get-monthly-bills-simple.php';
echo "Checking: $targetFile\n";
echo "Exists: " . (file_exists($targetFile) ? "YES ✓" : "NO ✗") . "\n";
echo "Is file: " . (is_file($targetFile) ? "YES ✓" : "NO ✗") . "\n";
echo "Readable: " . (is_readable($targetFile) ? "YES ✓" : "NO ✗") . "\n";
echo "Size: " . filesize($targetFile) . " bytes\n";

echo "\n\nDirect access test:\n";
$url = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost/adf_system') . '/api/get-monthly-bills-simple.php?business=narayana-hotel&month=2026-04';
echo "Test URL: $url\n";

echo "\n</pre>\n";
?>
