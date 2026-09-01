<?php
// Show extension_dir and check what ini files are loaded
echo "<h3>Extension Dir: " . ini_get('extension_dir') . "</h3>";
echo "<h3>Loaded INI: " . php_ini_loaded_file() . "</h3>";
echo "<h3>Additional INI: " . php_ini_scanned_files() . "</h3>";
echo "<h3>PDO loaded: " . (extension_loaded('pdo') ? 'YES' : 'NO') . "</h3>";
echo "<h3>PDO_MySQL loaded: " . (extension_loaded('pdo_mysql') ? 'YES' : 'NO') . "</h3>";
echo "<hr>";
phpinfo();
