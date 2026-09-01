
<?php
/**
 * Database Manager Class
 * Handle master and business database operations
 * Methods for creating, copying, and managing databases
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

class DatabaseManager {
    private $pdo;
    private $host;
    private $user;
    private $pass;
    
    public function __construct($host = DB_HOST, $user = DB_USER, $pass = DB_PASS) {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        
        try {
            // Connect to MySQL without selecting a database
            $this->pdo = new PDO(
                "mysql:host={$this->host}",
                $this->user,
                $this->pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Check if database exists
     */
    public function databaseExists($dbName) {
        try {
            $stmt = $this->pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$dbName}'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new Exception("Error checking database: " . $e->getMessage());
        }
    }
    
    /**
     * Detect hosting environment
     */
    private function isProduction() {
        return (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
    }
    
    /**
     * Get cPanel username from DB_USER (e.g., 'adfb2574_adfsystem' -> 'adfb2574')
     */
    private function getCpanelUser() {
        if (defined('DB_USER')) {
            $parts = explode('_', DB_USER);
            if (count($parts) >= 2) {
                return $parts[0];
            }
        }
        return '';
    }
    
    /**
     * Get hosting DB prefix (e.g., 'adfb2574_')
     */
    public function getHostingPrefix() {
        $cpUser = $this->getCpanelUser();
        return $cpUser ? $cpUser . '_' : '';
    }
    
    /**
     * Apply hosting prefix to database name if on production
     * e.g., 'adf_cafe_new' -> 'adfb2574_cafe_new' (on hosting)
     */
    public function resolveDbName($localDbName) {
        if (!$this->isProduction()) {
            return $localDbName;
        }
        
        // Use getDbName() if available (handles known mappings)
        if (function_exists('getDbName')) {
            $mapped = getDbName($localDbName);
            if ($mapped !== $localDbName) {
                return $mapped;
            }
        }
        
        // Auto-prefix for new/unknown databases
        $prefix = $this->getHostingPrefix();
        if ($prefix && strpos($localDbName, $prefix) !== 0) {
            // Strip 'adf_' prefix and add hosting prefix
            if (strpos($localDbName, 'adf_') === 0) {
                return $prefix . substr($localDbName, 4);
            }
            return $prefix . $localDbName;
        }
        
        return $localDbName;
    }
    
    /**
     * Create new database (multi-strategy: SQL → cPanel UAPI → fallback)
     */
    public function createDatabase($dbName) {
        if ($this->databaseExists($dbName)) {
            return ['success' => true, 'method' => 'exists', 'message' => "Database '{$dbName}' already exists"];
        }
        
        // Strategy 1: Direct SQL (works on localhost, VPS, dedicated servers)
        try {
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            return ['success' => true, 'method' => 'sql'];
        } catch (PDOException $e) {
            $sqlError = $e->getMessage();
        }
        
        // Strategy 2: cPanel UAPI via shell (shared hosting with cPanel)
        if ($this->isProduction()) {
            $cpanelResult = $this->createDatabaseViaCpanel($dbName);
            if ($cpanelResult['success']) {
                return $cpanelResult;
            }
        }
        
        // All strategies failed
        throw new Exception("Could not create database '{$dbName}'. SQL Error: {$sqlError}. " .
            "On shared hosting, you may need to create it manually via cPanel → MySQL Databases.");
    }
    
    /**
     * Create database via cPanel UAPI (for shared hosting)
     */
    private function createDatabaseViaCpanel($dbName) {
        // Try shell_exec with cPanel UAPI
        if (function_exists('shell_exec')) {
            $uapiPath = '/usr/local/cpanel/bin/uapi';
            
            if (@file_exists($uapiPath)) {
                try {
                    // Create database
                    $cmd = $uapiPath . ' --output=json Mysql create_database name=' . escapeshellarg($dbName) . ' 2>&1';
                    $result = @shell_exec($cmd);
                    $json = json_decode($result, true);
                    
                    if ($json && isset($json['result']['status']) && $json['result']['status'] == 1) {
                        // Grant privileges to current DB user
                        $this->grantCpanelPrivileges($dbName);
                        return ['success' => true, 'method' => 'cpanel_uapi'];
                    }
                    
                    // Try alternative: cpanel MySQL API
                    $cpUser = $this->getCpanelUser();
                    if ($cpUser) {
                        $cmd2 = $uapiPath . ' --user=' . escapeshellarg($cpUser) . 
                                ' --output=json Mysql create_database name=' . escapeshellarg($dbName) . ' 2>&1';
                        $result2 = @shell_exec($cmd2);
                        $json2 = json_decode($result2, true);
                        
                        if ($json2 && isset($json2['result']['status']) && $json2['result']['status'] == 1) {
                            $this->grantCpanelPrivileges($dbName);
                            return ['success' => true, 'method' => 'cpanel_uapi_user'];
                        }
                    }
                } catch (Exception $e) {
                    // Continue to next strategy
                }
            }
        }
        
        return ['success' => false, 'error' => 'cPanel UAPI not available'];
    }
    
    /**
     * Grant all privileges to DB_USER on a database via cPanel UAPI
     */
    private function grantCpanelPrivileges($dbName) {
        if (!function_exists('shell_exec')) return false;
        $uapiPath = '/usr/local/cpanel/bin/uapi';
        if (!@file_exists($uapiPath)) return false;
        
        $dbUser = defined('DB_USER') ? DB_USER : '';
        if (empty($dbUser)) return false;
        
        try {
            $cmd = $uapiPath . ' --output=json Mysql set_privileges_on_database' .
                   ' user=' . escapeshellarg($dbUser) .
                   ' database=' . escapeshellarg($dbName) .
                   ' privileges=ALL%20PRIVILEGES 2>&1';
            @shell_exec($cmd);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Create business database from template
     * Reads template SQL file and executes it in new database
     * Automatically handles hosting prefix
     */
    public function createBusinessDatabase($dbName, $templatePath = null) {
        // Resolve the actual database name (apply hosting prefix if needed)
        $actualDbName = $this->resolveDbName($dbName);
        
        // Create the database first (multi-strategy)
        $createResult = $this->createDatabase($actualDbName);
        
        // Use default path if not provided
        if (!$templatePath) {
            $templatePath = dirname(dirname(__FILE__)) . '/database/business_template.sql';
        }
        
        if (!file_exists($templatePath)) {
            throw new Exception("Template file not found: {$templatePath}");
        }
        
        try {
            // Read template SQL
            $sql = file_get_contents($templatePath);
            
            // Connect to new database
            $dbPdo = new PDO(
                "mysql:host={$this->host};dbname={$actualDbName}",
                $this->user,
                $this->pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Execute SQL statements
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (!empty($statement) && strpos($statement, '--') !== 0) {
                    $dbPdo->exec($statement);
                }
            }
            
            return ['success' => true, 'database' => $actualDbName, 'method' => $createResult['method'] ?? 'unknown'];
        } catch (PDOException $e) {
            // Clean up - try to drop database if template execution failed
            try {
                $this->pdo->exec("DROP DATABASE IF EXISTS `{$actualDbName}`");
            } catch (Exception $dropErr) {
                // Silently ignore
            }
            throw new Exception("Error creating business database '{$actualDbName}': " . $e->getMessage());
        }
    }
    
    /**
     * Initialize master database
     */
    public function initializeMasterDatabase($masterDbName = 'adf_system', $templatePath = null) {
        $actualDbName = $this->resolveDbName($masterDbName);
        
        if ($this->databaseExists($actualDbName)) {
            throw new Exception("Master database '{$actualDbName}' already exists!");
        }
        
        if (!$templatePath) {
            $templatePath = dirname(dirname(__FILE__)) . '/database/adf_system_master.sql';
        }
        
        if (!file_exists($templatePath)) {
            throw new Exception("Master template file not found: {$templatePath}");
        }
        
        try {
            // Create database (multi-strategy)
            $this->createDatabase($actualDbName);
            
            // Read and execute SQL
            $sql = file_get_contents($templatePath);
            
            // Connect to new master database
            $dbPdo = new PDO(
                "mysql:host={$this->host};dbname={$actualDbName}",
                $this->user,
                $this->pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Execute SQL statements
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (!empty($statement) && strpos($statement, '--') !== 0) {
                    $dbPdo->exec($statement);
                }
            }
            
            return true;
        } catch (PDOException $e) {
            // Clean up
            try {
                $this->pdo->exec("DROP DATABASE IF EXISTS `{$actualDbName}`");
            } catch (Exception $dropErr) {
                // Silently ignore
            }
            throw new Exception("Error initializing master database: " . $e->getMessage());
        }
    }
    
    /**
     * Delete database
     * WARNING: This is destructive!
     */
    public function deleteDatabase($dbName, $confirmDelete = false) {
        if (!$confirmDelete) {
            throw new Exception("Database deletion requires confirmation. Set confirmDelete to true.");
        }
        
        if (!$this->databaseExists($dbName)) {
            throw new Exception("Database '{$dbName}' does not exist!");
        }
        
        try {
            $this->pdo->exec("DROP DATABASE `{$dbName}`");
            return true;
        } catch (PDOException $e) {
            throw new Exception("Error deleting database: " . $e->getMessage());
        }
    }
    
    /**
     * Get database size in MB
     */
    public function getDatabaseSize($dbName) {
        try {
            $stmt = $this->pdo->query(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
                 FROM information_schema.TABLES 
                 WHERE table_schema = '{$dbName}'"
            );
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['size_mb'] ?? 0;
        } catch (PDOException $e) {
            throw new Exception("Error getting database size: " . $e->getMessage());
        }
    }
    
    /**
     * Get list of all databases
     */
    public function getAllDatabases() {
        try {
            $stmt = $this->pdo->query("SHOW DATABASES");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            throw new Exception("Error getting databases: " . $e->getMessage());
        }
    }
    
    /**
     * Get database statistics
     */
    public function getDatabaseStats($dbName) {
        try {
            $stmt = $this->pdo->query(
                "SELECT 
                    TABLE_NAME,
                    TABLE_ROWS,
                    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as size_mb
                 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = '{$dbName}'
                 ORDER BY TABLE_NAME"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error getting database stats: " . $e->getMessage());
        }
    }
    
    /**
     * Backup database (dump to SQL file)
     */
    public function backupDatabase($dbName, $outputPath) {
        try {
            $backupFile = $outputPath . '/backup_' . $dbName . '_' . date('Y-m-d_H-i-s') . '.sql';
            
            // Use mysqldump command
            $command = "mysqldump -h {$this->host} -u {$this->user}" . 
                      (!empty($this->pass) ? " -p{$this->pass}" : "") . 
                      " {$dbName} > {$backupFile}";
            
            exec($command, $output, $returnVar);
            
            if ($returnVar !== 0) {
                throw new Exception("Backup failed with error code: {$returnVar}");
            }
            
            return $backupFile;
        } catch (Exception $e) {
            throw new Exception("Error backing up database: " . $e->getMessage());
        }
    }
    
    /**
     * Restore database from backup file
     */
    public function restoreDatabase($dbName, $backupFile) {
        try {
            if (!file_exists($backupFile)) {
                throw new Exception("Backup file not found: {$backupFile}");
            }
            
            $sql = file_get_contents($backupFile);
            
            // Connect to the database
            $dbPdo = new PDO(
                "mysql:host={$this->host};dbname={$dbName}",
                $this->user,
                $this->pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Execute SQL
            $dbPdo->exec($sql);
            
            return true;
        } catch (Exception $e) {
            throw new Exception("Error restoring database: " . $e->getMessage());
        }
    }
    
    /**
     * Get PDO connection to specific database
     */
    public function getConnection($dbName) {
        try {
            return new PDO(
                "mysql:host={$this->host};dbname={$dbName}",
                $this->user,
                $this->pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            throw new Exception("Error connecting to database: " . $e->getMessage());
        }
    }
    
    /**
     * Test connection
     */
    public function testConnection($dbName) {
        try {
            $conn = $this->getConnection($dbName);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

?>
