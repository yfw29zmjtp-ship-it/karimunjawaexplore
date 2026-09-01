<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

// Only admin or developer can backup
if (!$auth->hasRole('admin') && !$auth->hasRole('developer')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya admin atau developer yang bisa backup data.']);
    exit;
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Get database name from config
    $dbName = DB_NAME;
    
    // Create backup filename with timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = "backup_narayana_{$timestamp}.sql";
    $backupPath = __DIR__ . '/../backups/' . $backupFile;
    
    // Create backups directory if not exists
    if (!file_exists(__DIR__ . '/../backups')) {
        mkdir(__DIR__ . '/../backups', 0755, true);
    }
    
    // Get all tables
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    // Open file for writing
    $handle = fopen($backupPath, 'w');
    
    // Write SQL header
    fwrite($handle, "-- Narayana Hotel Database Backup\n");
    fwrite($handle, "-- Backup Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- Database: {$dbName}\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    // Export each table
    foreach ($tables as $table) {
        // Get table structure
        $result = $conn->query("SHOW CREATE TABLE `{$table}`");
        $row = $result->fetch(PDO::FETCH_NUM);
        
        fwrite($handle, "-- --------------------------------------------------------\n");
        fwrite($handle, "-- Table structure for `{$table}`\n");
        fwrite($handle, "-- --------------------------------------------------------\n\n");
        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($handle, $row[1] . ";\n\n");
        
        // Get table data
        $result = $conn->query("SELECT * FROM `{$table}`");
        $rowCount = $result->rowCount();
        
        if ($rowCount > 0) {
            fwrite($handle, "-- Dumping data for table `{$table}`\n\n");
            
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $values = array_values($row);
                
                // Escape values
                $escapedValues = array_map(function($value) use ($conn) {
                    if ($value === null) {
                        return 'NULL';
                    }
                    return $conn->quote($value);
                }, $values);
                
                $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $escapedValues) . ");\n";
                fwrite($handle, $sql);
            }
            
            fwrite($handle, "\n");
        }
    }
    
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);
    
    // Return download URL
    echo json_encode([
        'success' => true,
        'message' => 'Backup berhasil dibuat!',
        'filename' => $backupFile,
        'download_url' => BASE_URL . '/api/download-backup.php?file=' . urlencode($backupFile),
        'file_size' => round(filesize($backupPath) / 1024, 2) . ' KB'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saat backup: ' . $e->getMessage()
    ]);
}
