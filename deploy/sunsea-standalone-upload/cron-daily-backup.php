<?php

/**
 * cron-daily-backup.php
 * ------------------------------------------------------------------
 * Daily automated backup for ALL business databases in adf.system:
 *   1. Dumps every business database (pure PHP, no mysqldump/shell needed
 *      - this hosting has exec/shell_exec/system disabled).
 *   2. Zips each dump.
 *   3. Uploads the zip straight to a Backblaze B2 bucket (plain REST API
 *      over cURL - no SDK, no CLI tool, works fine on shared hosting).
 *   4. Keeps only a short local retention window (default 2 days) of the
 *      zip files as an emergency fallback, then deletes older ones - so
 *      backups never pile up and eat hosting disk quota.
 *
 * SETUP:
 *   1. Copy config/backup-secrets.example.php -> config/backup-secrets.php
 *      and fill in your Backblaze B2 keys + a secret cron token.
 *   2. Trigger this script once a day via a cPanel Cron Job. Same pattern
 *      already used for the fingerprint sync cron (see
 *      _deploy_fingerprint_cron.php / api/fingerprint-cron-sync.php):
 *
 *      /usr/bin/curl -s "https://adfsystem.online/cron-daily-backup.php?token=YOUR_TOKEN" > /home/adfb2574/backup_cron_log.txt 2>&1
 *
 *   3. Recommended schedule: once a day, e.g. minute=0 hour=3 (03:00 local).
 *
 * You can also run it manually to test:
 *   https://adfsystem.online/cron-daily-backup.php?token=YOUR_TOKEN
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

$secretsFile = __DIR__ . '/config/backup-secrets.php';
if (!file_exists($secretsFile)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit("Missing config/backup-secrets.php - copy config/backup-secrets.example.php and fill in your Backblaze B2 credentials first.\n");
}
require_once $secretsFile;

// ---- Access guard (this endpoint is HTTP-triggered by cron, so it must be token-protected) ----
$providedToken = $_GET['token'] ?? '';
if (!defined('BACKUP_CRON_TOKEN') || !$providedToken || !hash_equals(BACKUP_CRON_TOKEN, $providedToken)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("Forbidden\n");
}

header('Content-Type: text/plain');
set_time_limit(0);
ignore_user_abort(true);

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}
$logFile = $backupDir . '/backup-log.txt';

function backupLog(string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

backupLog('=== Daily backup started ===');

// ------------------------------------------------------------------
// 1. Resolve which databases to back up (master + every business)
// ------------------------------------------------------------------
function resolveHostingDbName(string $localName): string
{
    // Same mapping table used by config/database.php's Database class.
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
$excludeIds = defined('BACKUP_EXCLUDE_BUSINESS_IDS') ? BACKUP_EXCLUDE_BUSINESS_IDS : [];

$databases = [];
$databases['master'] = $isLocalDev ? 'adf_system' : resolveHostingDbName('adf_system');

foreach (glob(__DIR__ . '/config/businesses/*.php') as $file) {
    $conf = require $file;
    if (empty($conf['database']) || empty($conf['business_id'])) {
        continue;
    }
    if (in_array($conf['business_id'], $excludeIds, true)) {
        continue;
    }
    $databases[$conf['business_id']] = $isLocalDev ? $conf['database'] : resolveHostingDbName($conf['database']);
}

backupLog('Databases to back up: ' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($databases), $databases)));

// ------------------------------------------------------------------
// 2. Pure-PHP SQL dump (no mysqldump binary needed)
// ------------------------------------------------------------------
function dumpDatabaseToFile(PDO $pdo, string $dbName, string $filePath): void
{
    $handle = fopen($filePath, 'w');
    fwrite($handle, "-- ADF System Auto Backup\n-- Database: {$dbName}\n-- Date: " . date('Y-m-d H:i:s') . "\n\n");
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
// 3. Backblaze B2 native API client (plain cURL, no SDK)
// ------------------------------------------------------------------
function b2Authorize(string $keyId, string $appKey): array
{
    $ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode($keyId . ':' . $appKey)],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        throw new Exception('B2 authorize cURL error: ' . $err);
    }
    if ($code !== 200) {
        throw new Exception("B2 authorize failed ({$code}): {$resp}");
    }
    return json_decode($resp, true);
}

function b2ResolveBucketId(string $apiUrl, string $authToken, string $accountId, string $bucketName): string
{
    $ch = curl_init(rtrim($apiUrl, '/') . '/b2api/v2/b2_list_buckets');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $authToken, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['accountId' => $accountId, 'bucketName' => $bucketName]),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        throw new Exception("B2 list_buckets failed ({$code}): {$resp}");
    }
    $data = json_decode($resp, true);
    if (empty($data['buckets'][0]['bucketId'])) {
        throw new Exception("B2 bucket not found: {$bucketName}");
    }
    return $data['buckets'][0]['bucketId'];
}

function b2GetUploadUrl(string $apiUrl, string $authToken, string $bucketId): array
{
    $ch = curl_init(rtrim($apiUrl, '/') . '/b2api/v2/b2_get_upload_url');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $authToken, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['bucketId' => $bucketId]),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        throw new Exception("B2 get_upload_url failed ({$code}): {$resp}");
    }
    return json_decode($resp, true);
}

function b2UploadFile(string $uploadUrl, string $uploadAuthToken, string $localFilePath, string $remoteFileName): array
{
    $fileSize = filesize($localFilePath);
    $sha1     = sha1_file($localFilePath);
    $fh       = fopen($localFilePath, 'rb');

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_UPLOAD         => true,      // stream the file body...
        CURLOPT_CUSTOMREQUEST  => 'POST',    // ...but B2 requires POST, not PUT
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => $fileSize,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $uploadAuthToken,
            'X-Bz-File-Name: ' . rawurlencode($remoteFileName),
            'Content-Type: application/zip',
            'Content-Length: ' . $fileSize,
            'X-Bz-Content-Sha1: ' . $sha1,
        ],
        CURLOPT_TIMEOUT        => 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($resp === false) {
        throw new Exception('B2 upload cURL error: ' . $err);
    }
    if ($code !== 200) {
        throw new Exception("B2 upload failed ({$code}): {$resp}");
    }
    return json_decode($resp, true);
}

// ------------------------------------------------------------------
// 4. Authorize with B2 once for this whole run
// ------------------------------------------------------------------
$b2Ready  = false;
$b2Auth   = null;
$bucketId = null;

if (defined('B2_KEY_ID') && defined('B2_APPLICATION_KEY') && (defined('B2_BUCKET_ID') || defined('B2_BUCKET_NAME'))) {
    try {
        $b2Auth   = b2Authorize(B2_KEY_ID, B2_APPLICATION_KEY);
        $bucketId = defined('B2_BUCKET_ID') ? B2_BUCKET_ID : b2ResolveBucketId($b2Auth['apiUrl'], $b2Auth['authorizationToken'], $b2Auth['accountId'], B2_BUCKET_NAME);
        $b2Ready  = true;
        backupLog('B2 authorized OK, bucketId=' . $bucketId);
    } catch (Exception $e) {
        backupLog('ERROR: B2 authorize/bucket resolve failed - ' . $e->getMessage() . ' (backups will stay local-only this run)');
    }
} else {
    backupLog('WARNING: B2 credentials not fully configured in config/backup-secrets.php - backups will stay local-only.');
}

// ------------------------------------------------------------------
// 5. Dump -> zip -> upload -> cleanup, per database
// ------------------------------------------------------------------
$successCount = 0;
$failCount    = 0;

foreach ($databases as $businessId => $dbName) {
    $sqlFile = null;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE                 => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // stream large tables instead of loading fully into memory
            ]
        );

        $timestamp = date('Y-m-d_H-i-s');
        $sqlFile   = $backupDir . "/{$businessId}_{$timestamp}.sql";
        $zipFile   = $sqlFile . '.zip';

        dumpDatabaseToFile($pdo, $dbName, $sqlFile);
        $pdo = null;

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Could not create zip file');
        }
        $zip->addFile($sqlFile, basename($sqlFile));
        $zip->close();
        unlink($sqlFile); // raw SQL never needs to stay on disk, only the zip
        $sqlFile = null;

        $sizeKb = round(filesize($zipFile) / 1024, 1);
        backupLog("Dumped {$businessId} ({$dbName}) -> " . basename($zipFile) . " ({$sizeKb} KB)");

        if ($b2Ready) {
            $uploadInfo = b2GetUploadUrl($b2Auth['apiUrl'], $b2Auth['authorizationToken'], $bucketId);
            $remoteName = 'adf-backups/' . date('Y/m') . '/' . basename($zipFile);
            b2UploadFile($uploadInfo['uploadUrl'], $uploadInfo['uploadAuthToken'], $zipFile, $remoteName);
            backupLog("Uploaded to B2: {$remoteName}");
        } else {
            backupLog("SKIPPED B2 upload for {$businessId} (B2 not available this run) - zip kept locally only");
        }

        $successCount++;
    } catch (Throwable $e) {
        if ($sqlFile && file_exists($sqlFile)) {
            @unlink($sqlFile);
        }
        backupLog("ERROR backing up {$businessId} ({$dbName}): " . $e->getMessage());
        $failCount++;
    }
}

// ------------------------------------------------------------------
// 6. Local retention cleanup - never let backups/ grow unbounded
// ------------------------------------------------------------------
$retentionDays = defined('BACKUP_LOCAL_RETENTION_DAYS') ? (int)BACKUP_LOCAL_RETENTION_DAYS : 2;
$cutoff = time() - ($retentionDays * 86400);
$removed = 0;
foreach (glob($backupDir . '/*.zip') as $oldZip) {
    if (filemtime($oldZip) < $cutoff) {
        unlink($oldZip);
        $removed++;
    }
}
if ($removed > 0) {
    backupLog("Removed {$removed} local backup(s) older than {$retentionDays} day(s) (retention cleanup)");
}

backupLog("=== Daily backup finished: {$successCount} ok, {$failCount} failed ===");
