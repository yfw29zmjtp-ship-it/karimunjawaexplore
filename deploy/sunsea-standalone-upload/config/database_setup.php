
<?php
/**
 * ADF System 2.0 - Database Setup & Initialization Script
 * Safely initializes master and default business databases
 * 
 * INSTRUCTIONS:
 * 1. Save this file as: config/database_setup.php
 * 2. Access via browser: http://localhost/adf_system/setup-database.php (need to create router)
 * 3. Or run from CLI
 */

define('APP_ACCESS', true);

// Disable output buffering for real-time feedback
@ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// Color codes for terminal output
class Colors {
    const GREEN = "\033[32m";
    const RED = "\033[31m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const RESET = "\033[0m";
    
    public static function success($msg) { echo self::GREEN . "✓ " . $msg . self::RESET . "\n"; }
    public static function error($msg) { echo self::RED . "✗ " . $msg . self::RESET . "\n"; }
    public static function info($msg) { echo self::BLUE . "ℹ " . $msg . self::RESET . "\n"; }
    public static function warning($msg) { echo self::YELLOW . "⚠ " . $msg . self::RESET . "\n"; }
}

class DatabaseSetup {
    private $pdo;
    private $host;
    private $user;
    private $pass;
    
    public function __construct() {
        $this->host = DB_HOST;
        $this->user = DB_USER;
        $this->pass = DB_PASS;
        
        try {
            $this->pdo = new PDO(
                "mysql:host={$this->host}",
                $this->user,
                $this->pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            Colors::success("Database server connected");
        } catch (PDOException $e) {
            Colors::error("Database connection failed: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Check if database exists
     */
    public function databaseExists($dbName) {
        try {
            $stmt = $this->pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$dbName}'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Create database
     */
    public function createDatabase($dbName) {
        try {
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            Colors::success("Database created: {$dbName}");
            return true;
        } catch (PDOException $e) {
            Colors::error("Failed to create database {$dbName}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Execute SQL file
     */
    public function executeSqlFile($dbName, $filePath) {
        if (!file_exists($filePath)) {
            Colors::error("SQL file not found: {$filePath}");
            return false;
        }
        
        try {
            $sql = file_get_contents($filePath);
            $dbPdo = new PDO(
                "mysql:host={$this->host};dbname={$dbName}",
                $this->user,
                $this->pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Split and execute statements
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($s) { return !empty($s) && strpos($s, '--') !== 0; }
            );
            
            foreach ($statements as $statement) {
                $dbPdo->exec($statement);
            }
            
            Colors::success("SQL executed: {$filePath}");
            return true;
        } catch (Exception $e) {
            Colors::error("Failed to execute SQL: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Initialize master database
     */
    public function initMasterDatabase() {
        Colors::info("Initializing Master Database...");
        
        $dbName = 'adf_system';
        
        // Check if exists
        if ($this->databaseExists($dbName)) {
            Colors::warning("Master database already exists!");
            return false;
        }
        
        // Get path relative to setup script location
        $basePath = dirname(dirname(__FILE__));
        $sqlFile = $basePath . '/database/adf_system_master.sql';
        
        Colors::info("Creating master database: {$dbName}");
        if (!$this->createDatabase($dbName)) {
            return false;
        }
        
        Colors::info("Loading master schema...");
        if (!$this->executeSqlFile($dbName, $sqlFile)) {
            Colors::error("Failed to load master schema");
            return false;
        }
        
        Colors::success("Master database initialized successfully!");
        return true;
    }
    
    /**
     * Create default business database
     */
    public function createDefaultBusiness($businessName, $businessCode, $businessType = 'other') {
        Colors::info("Creating business database...");
        
        // Generate database name
        $dbName = 'adf_' . strtolower(preg_replace('/[^a-z0-9]/i', '_', $businessCode));
        
        // Check if exists
        if ($this->databaseExists($dbName)) {
            Colors::warning("Business database already exists: {$dbName}");
            return false;
        }
        
        $basePath = dirname(dirname(__FILE__));
        $sqlFile = $basePath . '/database/business_template.sql';
        
        Colors::info("Creating database: {$dbName}");
        if (!$this->createDatabase($dbName)) {
            return false;
        }
        
        Colors::info("Loading business schema...");
        if (!$this->executeSqlFile($dbName, $sqlFile)) {
            Colors::error("Failed to load business schema");
            return false;
        }
        
        // Register in master database
        try {
            $masterPdo = new PDO(
                "mysql:host={$this->host};dbname=adf_system",
                $this->user,
                $this->pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Use developer user (id=1) as default owner
            $stmt = $masterPdo->prepare(
                "INSERT INTO businesses (business_code, business_name, business_type, database_name, owner_id) 
                 VALUES (?, ?, ?, ?, (SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE role_code = 'developer') LIMIT 1))"
            );
            
            $stmt->execute([$businessCode, $businessName, $businessType, $dbName]);
            
            // Get inserted business ID
            $businessId = $masterPdo->lastInsertId();
            
            // Enable all menus for this business
            $menuStmt = $masterPdo->query("SELECT id FROM menu_items WHERE is_active = 1");
            $menus = $menuStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $configStmt = $masterPdo->prepare(
                "INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, 1)"
            );
            
            foreach ($menus as $menuId) {
                $configStmt->execute([$businessId, $menuId]);
            }
            
            Colors::success("Business database registered: {$dbName}");
            return true;
        } catch (Exception $e) {
            Colors::error("Failed to register business: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reset everything (DESTRUCTIVE!)
     */
    public function resetAll() {
        Colors::warning("WARNING: This will delete all databases!");
        Colors::warning("This action cannot be undone!");
        
        if (php_sapi_name() === 'cli') {
            Colors::info("Type 'DELETE' to confirm: ");
            $handle = fopen("php://stdin", "r");
            $input = trim(fgets($handle));
            fclose($handle);
            
            if ($input !== 'DELETE') {
                Colors::info("Reset cancelled");
                return false;
            }
        } else {
            // For web, just decline
            Colors::warning("Confirmation failed");
            return false;
        }
        
        try {
            // Delete master database
            $this->pdo->exec("DROP DATABASE IF EXISTS adf_system");
            Colors::success("Master database deleted");
            
            // Delete all business databases
            $dbList = $this->pdo->query("SHOW DATABASES LIKE 'adf_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($dbList as $dbName) {
                $this->pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
                Colors::success("Business database deleted: {$dbName}");
            }
            
            return true;
        } catch (Exception $e) {
            Colors::error("Reset failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * List all ADF databases
     */
    public function listDatabases() {
        try {
            $databases = $this->pdo->query("SHOW DATABASES LIKE 'adf%'")->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($databases)) {
                Colors::info("No ADF databases found");
                return;
            }
            
            Colors::info("Found " . count($databases) . " ADF database(s):");
            foreach ($databases as $db) {
                $tables = $this->pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}'")->fetchColumn();
                Colors::success("  {$db} ({$tables} tables)");
            }
        } catch (Exception $e) {
            Colors::error("Failed to list databases: " . $e->getMessage());
        }
    }
}

// ============================================
// MAIN EXECUTION
// ============================================

if (php_sapi_name() === 'cli') {
    // CLI Mode
    Colors::info("\n=== ADF System 2.0 - Database Setup ===\n");
    
    $setup = new DatabaseSetup();
    $args = $argc > 1 ? $argv[1] : '';
    
    switch ($args) {
        case 'init-master':
            echo "\n";
            $setup->initMasterDatabase();
            break;
            
        case 'create-business':
            if ($argc < 4) {
                Colors::error("Usage: php database_setup.php create-business <code> <name> [type]");
                Colors::info("Example: php database_setup.php create-business HOTEL_01 'Narayana Hotel' hotel");
                exit(1);
            }
            echo "\n";
            $setup->createDefaultBusiness($argv[3], $argv[2], $argv[4] ?? 'other');
            break;
            
        case 'setup-all':
            echo "\n";
            Colors::info("Full Setup: Master + Default Business\n");
            $setup->initMasterDatabase();
            echo "\n";
            $setup->createDefaultBusiness('Sample Business', 'SAMPLE', 'other');
            break;
            
        case 'list':
            echo "\n";
            $setup->listDatabases();
            break;
            
        case 'reset':
            echo "\n";
            $setup->resetAll();
            break;
            
        default:
            echo "Usage: php database_setup.php <command>\n\n";
            echo "Commands:\n";
            echo "  init-master              Initialize master database\n";
            echo "  create-business <code> <name>  Create new business database\n";
            echo "  setup-all                Initialize master + sample business\n";
            echo "  list                     List all ADF databases\n";
            echo "  reset                    Delete all ADF databases (DESTRUCTIVE!)\n";
    }
    
    echo "\n";
} else {
    // Web Mode (future use)
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>ADF Setup</title></head><body>";
    echo "<h1>ADF System Database Setup</h1>";
    echo "<p>This page is for CLI use only. Use terminal commands.</p>";
    echo "</body></html>";
}

?>
