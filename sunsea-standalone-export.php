<?php

/**
 * sunsea-standalone-export.php
 * ------------------------------------------------------------------
 * One-click export tool to move the Sunsea / Explore Karimunjawa business
 * to its OWN standalone hosting account, while still running the same
 * adf.system codebase (git-synced).
 *
 * What it does:
 *   1. Dumps the MASTER database in full (businesses, users, roles,
 *      menu config, cash_accounts, etc. - needed because those tables
 *      have foreign keys between each other, so a partial/filtered dump
 *      risks import errors on the new server).
 *   2. Dumps the Sunsea BUSINESS database in full (all real data:
 *      customers, quotations, invoices, bookings, cash_book, etc.)
 *   3. Zips both .sql files together with a README and streams the zip
 *      straight to your browser as a download - nothing is uploaded
 *      anywhere, nothing is left behind on this server.
 *
 * Usage (must be logged in as owner/admin/developer):
 *   https://adfsystem.online/sunsea-standalone-export.php
 *
 * After downloading, see the README.txt inside the zip for import steps
 * on the new hosting. Also see config/local-db-config.example.php for
 * how to point this same codebase at the new server's database.
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$__role = $_SESSION['role'] ?? '';
if (!in_array($__role, ['owner', 'admin', 'developer'], true)) {
    http_response_code(403);
    exit("Forbidden - hanya owner/admin/developer yang boleh export database.\n");
}

set_time_limit(0);
ignore_user_abort(true);

// ------------------------------------------------------------------
// Resolve database names (same mapping logic as config/database.php
// and cron-daily-backup.php, kept in sync intentionally)
// ------------------------------------------------------------------
function resolveHostingDbName(string $localName): string
{
    $dbMapping = [
        'adf_system'         => 'adfb2574_adf',
        'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
        'adf_benscafe'       => 'adfb2574_Adf_Bens',
        'adf_demo'           => 'adfb2574_demo',
        'adf_cqc'            => 'adfb2574_cqc',
    ];
    if (isset($dbMapping[$localName])) {
        return $dbMapping[$localName];
    }
    if (strpos($localName, 'adf_') === 0) {
        $prefix = 'adfb2574_';
        if (defined('DB_USER')) {
            $parts = explode('_', DB_USER);
            if (count($parts) >= 2) {
                $prefix = $parts[0] . '_';
            }
        }
        return $prefix . substr($localName, 4);
    }
    return $localName;
}

$isLocalDev = (defined('DB_USER') && DB_USER === 'root');

$sunseaConfFile = __DIR__ . '/config/businesses/sunsea.php';
if (!file_exists($sunseaConfFile)) {
    exit("config/businesses/sunsea.php tidak ditemukan.\n");
}
$sunseaConf = require $sunseaConfFile;

$masterDbName = DB_NAME; // already correct for whichever host is running this
$sunseaDbName = $isLocalDev ? $sunseaConf['database'] : resolveHostingDbName($sunseaConf['database']);

// ------------------------------------------------------------------
// Pure-PHP full SQL dump (no mysqldump binary needed - same approach
// already proven working in cron-daily-backup.php on this hosting)
// ------------------------------------------------------------------
function dumpDatabaseToFile(PDO $pdo, string $dbName, string $filePath): void
{
    $handle = fopen($filePath, 'w');
    fwrite($handle, "-- Sunsea Standalone Export\n-- Database: {$dbName}\n-- Date: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = [];
    $result = $pdo->query('SHOW TABLES');
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        fwrite($handle, "-- --------------------------------------------------------\n");
        fwrite($handle, "-- Table structure for `{$table}`\n");
        fwrite($handle, "-- --------------------------------------------------------\n\n");
        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($handle, $createRow[1] . ";\n\n");

        $result = $pdo->query("SELECT * FROM `{$table}`");
        $hasRows = false;
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if (!$hasRows) {
                fwrite($handle, "-- Dumping data for table `{$table}`\n\n");
                $hasRows = true;
            }
            $columns = array_keys($row);
            $escapedValues = array_map(function ($value) use ($pdo) {
                return $value === null ? 'NULL' : $pdo->quote($value);
            }, array_values($row));
            fwrite($handle, "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $escapedValues) . ");\n");
        }
        if ($hasRows) {
            fwrite($handle, "\n");
        }
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);
}

// ------------------------------------------------------------------
// Do the export
// ------------------------------------------------------------------
$stamp  = date('Ymd-His');
$tmpDir = sys_get_temp_dir() . '/sunsea-export-' . $stamp;
@mkdir($tmpDir, 0755, true);

$masterSqlPath = $tmpDir . "/master-{$masterDbName}.sql";
$sunseaSqlPath = $tmpDir . "/business-{$sunseaDbName}.sql";

try {
    $masterPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    dumpDatabaseToFile($masterPdo, $masterDbName, $masterSqlPath);

    $sunseaPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $sunseaDbName . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    dumpDatabaseToFile($sunseaPdo, $sunseaDbName, $sunseaSqlPath);
} catch (Exception $e) {
    http_response_code(500);
    exit('Export gagal: ' . $e->getMessage() . "\n");
}

$readme = <<<TXT
SUNSEA STANDALONE HOSTING - IMPORT INSTRUCTIONS
================================================

Isi zip ini:
  - master-{$masterDbName}.sql   -> import ke database MASTER baru
  - business-{$sunseaDbName}.sql -> import ke database BISNIS (sunsea) baru

Langkah di hosting BARU:
  1. cPanel -> MySQL Databases: buat 2 database (misal yourprefix_adf dan
     yourprefix_sunsea) + 1 user MySQL dengan ALL PRIVILEGES ke keduanya.
  2. cPanel -> phpMyAdmin: pilih database master baru -> tab Import ->
     upload master-{$masterDbName}.sql
  3. phpMyAdmin: pilih database bisnis baru -> tab Import -> upload
     business-{$sunseaDbName}.sql
  4. Deploy kode adf.system ke hosting baru (Git Version Control di cPanel,
     atau upload manual) dari repo yang sama.
  5. Di server BARU, copy config/local-db-config.example.php menjadi
     config/local-db-config.php (lewat File Manager, JANGAN lewat git),
     lalu isi DB_HOST/DB_NAME/DB_USER/DB_PASS sesuai database MASTER yang
     baru dibuat di langkah 1.
  6. Arahkan DNS domain karimunjawaexplore.com (A record) ke IP server
     hosting baru, lalu aktifkan SSL (AutoSSL/Let's Encrypt) di cPanel.

Catatan: dump master ini berisi SEMUA baris dari tabel master (businesses,
users, dll), bukan cuma Sunsea, karena tabel-tabel itu saling berelasi
(foreign key). Ini aman - business database untuk bisnis lain TIDAK ikut
dipindahkan, jadi bisnis lain tidak akan bisa dipakai dari server baru ini.
TXT;

$zipPath = $tmpDir . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit("Gagal membuat file zip.\n");
}
$zip->addFile($masterSqlPath, basename($masterSqlPath));
$zip->addFile($sunseaSqlPath, basename($sunseaSqlPath));
$zip->addFromString('README.txt', $readme);
$zip->close();

// Stream to browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="sunsea-standalone-export-' . $stamp . '.zip"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-store');
readfile($zipPath);

// Cleanup temp files
@unlink($masterSqlPath);
@unlink($sunseaSqlPath);
@rmdir($tmpDir);
@unlink($zipPath);
exit;
