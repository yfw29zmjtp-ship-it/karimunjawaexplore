<?php

/**
 * HK Room Allocation - Daily Auto Sync Cron (Multi-Business)
 * ------------------------------------------------------------------
 * Keeps "Pembagian HK Room" (Front Desk > Pembagian HK Room) always
 * ready WITHOUT anyone needing to open the page or click a button:
 *
 *   1. Right after midnight: if today's plan hasn't been generated yet,
 *      generate it using ALL active HK staff (attendance doesn't matter
 *      this early), so it's already there first thing in the morning
 *      in the staff portal.
 *   2. Shortly after the 09:00 attendance cutoff: if some HK staff still
 *      haven't checked in, their auto-assigned rooms are redistributed
 *      to staff who DID check in - the room numbers already assigned to
 *      present staff (auto or manual) are NEVER changed.
 *
 * The underlying function (hkSyncDailyState, in
 * includes/HkAllocationHelper.php) is idempotent - safe to call more
 * than once, it only touches the database when something actually
 * needs fixing.
 *
 * SETUP (cPanel > Cron Jobs) - add BOTH lines:
 *
 *   1) Right after midnight (generate the day's plan):
 *      /usr/bin/curl -s "https://adfsystem.online/api/hk-allocation-cron.php?token=YOUR_TOKEN" > /home/adfb2574/hk_cron_log.txt 2>&1
 *      Schedule: minute=5 hour=0
 *
 *   2) Shortly after the 09:00 attendance cutoff (redistribute absentees):
 *      /usr/bin/curl -s "https://adfsystem.online/api/hk-allocation-cron.php?token=YOUR_TOKEN" >> /home/adfb2574/hk_cron_log.txt 2>&1
 *      Schedule: minute=10 hour=9
 *
 * You can also run it manually to test:
 *   https://adfsystem.online/api/hk-allocation-cron.php?token=YOUR_TOKEN
 *   php api/hk-allocation-cron.php --token=YOUR_TOKEN
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/HkAllocationHelper.php';

const HK_CRON_TOKEN = 'adf-hk-cron-2026-secure';

$isCli = (php_sapi_name() === 'cli');

$params = $_GET;
if ($isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
            $params[$m[1]] = $m[2];
        }
    }
}

$token = $params['token'] ?? '';
if (!hash_equals(HK_CRON_TOKEN, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("Forbidden\n");
}

if (!$isCli) {
    header('Content-Type: text/plain');
}
set_time_limit(0);
ignore_user_abort(true);

$workDate = date('Y-m-d');
echo "=== HK allocation cron sync -- {$workDate} " . date('H:i:s') . " ===\n";

$bizFiles = glob(__DIR__ . '/../config/businesses/*.php') ?: [];
foreach ($bizFiles as $bf) {
    $slug = basename($bf, '.php');
    try {
        $cfg = require $bf;
        if (empty($cfg['database'])) {
            continue;
        }

        // Resolve hosting DB name the same way Database::switchDatabase() does,
        // then probe the connection first so an unreachable/demo DB doesn't
        // kill the whole loop (switchDatabase() calls die() on failure).
        $dbName = $cfg['database'];
        if (strpos($dbName, 'adfb2574_') !== 0 && strpos($dbName, 'adf_') === 0) {
            $dbName = 'adfb2574_' . substr($dbName, 4);
        }
        try {
            $probe = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=' . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $probe = null;
        } catch (PDOException $pe) {
            echo "[{$slug}] skip (DB unreachable): " . $pe->getMessage() . "\n";
            continue;
        }

        $bdb = Database::switchDatabase($cfg['database']);

        // Skip businesses that don't use the hotel rooms / payroll modules at all.
        if (!$bdb->fetchOne("SHOW TABLES LIKE 'rooms'") || !$bdb->fetchOne("SHOW TABLES LIKE 'payroll_employees'")) {
            continue;
        }

        ensureHkTables($bdb);
        $res = hkSyncDailyState($bdb, $workDate);

        if ($res['generated']) {
            echo "[{$slug}] generated fresh HK plan for {$workDate}\n";
        } elseif ($res['redistributed'] > 0) {
            echo "[{$slug}] redistributed {$res['redistributed']} room(s) away from absent staff ("
                . implode(', ', $res['absent_staff']) . ")\n";
        } else {
            echo "[{$slug}] no change needed\n";
        }
    } catch (Exception $e) {
        echo "[{$slug}] skip: " . $e->getMessage() . "\n";
    }
}

echo "=== Done ===\n";
