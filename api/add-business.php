<?php
/**
 * API: Add Business with Auto Database Creation & Schema Setup
 * Handles both local (adf_*) and hosting (adfb2574_*) database naming
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['name']) || !isset($input['database'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields: name, database']);
    exit;
}

try {
    // 1. Detect if Production (hosting) or Local
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
    
    $requestedDbName = $input['database'];
    $actualDbName = $requestedDbName;
    
    // Map database name for hosting
    if ($isProduction) {
        // On hosting, prefix database correctly
        if (strpos($requestedDbName, 'adfb2574_') === false) {
            $actualDbName = 'adfb2574_' . str_replace('adf_', '', $requestedDbName);
        }
    }
    
    // 2. Get PDO Connection with correct credentials
    if ($isProduction) {
        $dbHost = 'localhost';
        $dbUser = 'adfb2574_adfsystem';
        $dbPass = '@Nnoc2025';
    } else {
        $dbHost = 'localhost';
        $dbUser = 'root';
        $dbPass = '';
    }
    
    $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 3. Create Database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$actualDbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // 4. Connect to new database and create basic tables
    $bizPdo = new PDO("mysql:host=$dbHost;dbname=$actualDbName", $dbUser, $dbPass);
    $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create users table (minimal setup)
    $bizPdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `username` varchar(100) UNIQUE,
            `password` varchar(255),
            `full_name` varchar(150),
            `email` varchar(100),
            `role` varchar(50),
            `is_active` tinyint DEFAULT 1,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // 5. Add to businesses.php config
    $configFile = __DIR__ . '/../config/businesses.php';
    if (!file_exists($configFile)) {
        // Create minimal config if doesn't exist
        file_put_contents($configFile, "<?php\n\$BUSINESSES = [\n];\n");
    }
    
    $content = file_get_contents($configFile);
    
    // Get next ID
    preg_match_all("/'id' => (\d+)/", $content, $matches);
    $maxId = !empty($matches[1]) ? max($matches[1]) : 0;
    $nextId = $maxId + 1;
    
    // Create new business array - always store base name (adf_*) in config
    // Mapping to actual database name (adfb2574_*) happens at connection time
    $baseDbName = str_replace('adfb2574_', 'adf_', $actualDbName);
    
    $newBusiness = "    [\n" .
                   "        'id' => {$nextId},\n" .
                   "        'name' => '" . addslashes($input['name']) . "',\n" .
                   "        'database' => '{$baseDbName}',\n" .
                   "        'type' => '" . ($input['type'] ?? 'other') . "',\n" .
                   "        'active' => true\n" .
                   "    ]\n";
    
    // Insert before closing bracket
    $content = str_replace(
        "];",
        ",\n" . $newBusiness . "];",
        $content
    );
    
    file_put_contents($configFile, $content);
    
    // 6. Auto-assign all menus to new business in master database
    try {
        // Connect to master database
        $masterDb = $isProduction ? 'adfb2574_adf' : 'adf_system';
        $masterPdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
        $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get all menu items
        $menus = $masterPdo->query("SELECT id FROM menu_items WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        
        // Assign all menus to new business
        $stmt = $masterPdo->prepare("INSERT INTO business_menu_config (business_id, menu_id) VALUES (?, ?)");
        foreach ($menus as $menuId) {
            try {
                $stmt->execute([$nextId, $menuId]);
            } catch (Exception $e) {
                // Menu already assigned, skip
            }
        }
        
    } catch (Exception $e) {
        // Master database operation failed, but don't fail the whole request
        error_log("Warning: Could not auto-assign menus to business {$nextId}: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'business_id' => $nextId,
        'database' => $actualDbName,
        'message' => 'Business added successfully! Database created with schema and menus assigned.'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

