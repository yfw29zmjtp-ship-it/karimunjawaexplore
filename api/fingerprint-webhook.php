<?php

/**
 * Fingerspot.io Webhook Receiver — Multi-Business Support
 * Receives attendance data from Revo N830 via Fingerspot.io cloud
 * 
 * MODES:
 *   ?b=slug       → Single business (backward compatible)
 *   (no ?b= param) → Auto fan-out: finds ALL businesses with matching cloud_id
 *                     and processes the scan in each. One fingerprint device
 *                     can serve multiple businesses.
 * 
 * POST JSON format:
 * {
 *   "type": "attlog",
 *   "cloud_id": "XXXXXX",
 *   "data": {
 *     "pin": "1",
 *     "scan": "2020-07-21 10:11",
 *     "verify": "finger",
 *     "status_scan": "scan in"
 *   }
 * }
 */
define('APP_ACCESS', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// ── Helper: Log webhook data ──
function logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, $processed, $result, $raw)
{
    try {
        $pdo->prepare("INSERT INTO fingerprint_log (cloud_id, type, pin, scan_time, verify_method, status_scan, employee_id, processed, process_result, raw_data) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, $processed, $result, substr($raw, 0, 5000)]);
    } catch (Exception $e) {
        error_log('Fingerprint log error: ' . $e->getMessage());
    }
}

// ── Helper: Ensure required tables/columns exist ──
function ensureTablesExist($pdo)
{
    try {
        $pdo->query("SELECT 1 FROM fingerprint_log LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `fingerprint_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `cloud_id` VARCHAR(50) NOT NULL,
            `type` VARCHAR(32) NOT NULL DEFAULT 'attlog',
            `pin` VARCHAR(20) DEFAULT NULL,
            `scan_time` DATETIME DEFAULT NULL,
            `verify_method` VARCHAR(30) DEFAULT NULL,
            `status_scan` VARCHAR(30) DEFAULT NULL,
            `employee_id` INT DEFAULT NULL,
            `processed` TINYINT(1) DEFAULT 0,
            `process_result` VARCHAR(255) DEFAULT NULL,
            `raw_data` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cloud (cloud_id),
            INDEX idx_pin (pin),
            INDEX idx_scan (scan_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    try {
        $pdo->query("SELECT fingerspot_cloud_id FROM payroll_attendance_config LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE payroll_attendance_config 
            ADD COLUMN `fingerspot_cloud_id` VARCHAR(50) DEFAULT NULL,
            ADD COLUMN `fingerspot_enabled` TINYINT(1) DEFAULT 0");
    }
    try {
        $pdo->query("SELECT finger_id FROM payroll_employees LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE payroll_employees ADD COLUMN `finger_id` VARCHAR(20) DEFAULT NULL");
    }
}

// ── Helper: Probe a business DB connection BEFORE switching ──
// Database::switchDatabase() calls die() on a failed connection, which would
// abort the whole multi-business loop. We probe first and skip unreachable DBs
// (e.g. a leftover test business whose DB no longer exists).
function canConnectBusiness($cfg)
{
    $dbName = $cfg['database'] ?? '';
    if ($dbName === '') {
        return false;
    }
    // Replicate the hosting name mapping done inside Database::switchDatabase()
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
    if ($isProduction && strpos($dbName, 'adfb2574_') !== 0 && strpos($dbName, 'adf_') === 0) {
        $map = [
            'adf_system' => 'adfb2574_adf',
            'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
            'adf_benscafe' => 'adfb2574_Adf_Bens',
            'adf_demo' => 'adfb2574_demo',
            'adf_cqc' => 'adfb2574_cqc',
        ];
        $dbName = $map[$dbName] ?? ('adfb2574_' . substr($dbName, 4));
    }
    try {
        $probe = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $probe = null;
        return true;
    } catch (PDOException $e) {
        error_log('Fingerprint webhook: skip unreachable DB ' . $dbName . ': ' . $e->getMessage());
        return false;
    }
}

// ── Helper: Process a single scan for one business DB ──
// Returns ['success' => bool, 'message' => string]
function processAttlogForBusiness($pdo, $bizSlug, $cloudId, $type, $pin, $scanStr, $verify, $statusScan, $rawBody)
{
    $scanTime = date('Y-m-d H:i:s', strtotime($scanStr));
    $scanDate = date('Y-m-d', strtotime($scanStr));
    $scanTimeOnly = date('H:i:s', strtotime($scanStr));
    $pin = trim($pin);

    // Find employee by finger_id
    $employee = null;
    $empStmt = $pdo->prepare("SELECT id, full_name, employee_code FROM payroll_employees WHERE TRIM(finger_id) = ? AND is_active = 1");
    $empStmt->execute([$pin]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee && is_numeric($pin)) {
        $empStmt = $pdo->prepare("SELECT id, full_name, employee_code FROM payroll_employees WHERE CAST(TRIM(finger_id) AS UNSIGNED) = CAST(? AS UNSIGNED) AND is_active = 1");
        $empStmt->execute([$pin]);
        $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$employee) {
        // Check inactive
        $inactiveStmt = $pdo->prepare("SELECT id, full_name, is_active FROM payroll_employees WHERE TRIM(finger_id) = ? OR (? != '' AND CAST(TRIM(finger_id) AS UNSIGNED) = CAST(? AS UNSIGNED))");
        $inactiveStmt->execute([$pin, $pin, $pin]);
        $inactive = $inactiveStmt->fetch(PDO::FETCH_ASSOC);
        if ($inactive) {
            $msg = "[{$bizSlug}] Employee '{$inactive['full_name']}' PIN={$pin} is_active={$inactive['is_active']}";
            logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, (int)$inactive['id'], 0, $msg, $rawBody);
            return ['success' => false, 'message' => $msg];
        }

        $allPins = $pdo->query("SELECT full_name, finger_id, is_active FROM payroll_employees WHERE finger_id IS NOT NULL AND finger_id != '' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $pinList = array_map(fn($e) => "{$e['full_name']}='{$e['finger_id']}'", $allPins);
        $debugInfo = "[{$bizSlug}] No employee with PIN={$pin}. Registered: " . implode(', ', $pinList);
        logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, null, 0, substr($debugInfo, 0, 255), $rawBody);
        return ['success' => false, 'message' => "PIN {$pin} not found in {$bizSlug}"];
    }

    $empId = (int)$employee['id'];

    // Ensure split-shift columns exist
    try {
        $pdo->query("SELECT scan_3 FROM payroll_attendance LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE payroll_attendance 
            ADD COLUMN `scan_3` TIME DEFAULT NULL AFTER `check_out_time`,
            ADD COLUMN `scan_4` TIME DEFAULT NULL AFTER `scan_3`,
            ADD COLUMN `shift_1_hours` DECIMAL(5,2) DEFAULT NULL AFTER `work_hours`,
            ADD COLUMN `shift_2_hours` DECIMAL(5,2) DEFAULT NULL AFTER `shift_1_hours`");
    }

    // Get existing attendance
    $existStmt = $pdo->prepare("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?");
    $existStmt->execute([$empId, $scanDate]);
    $att = $existStmt->fetch(PDO::FETCH_ASSOC);

    $scan1 = $att['check_in_time'] ?? null;
    $scan2 = $att['check_out_time'] ?? null;
    $scan3 = $att['scan_3'] ?? null;
    $scan4 = $att['scan_4'] ?? null;
    $filledScans = array_filter([$scan1, $scan2, $scan3, $scan4], fn($s) => !empty($s));

    // Double scan filter (< 5 min) — compare against ALL existing scans, not just
    // the last one. Fingerspot may resend an earlier scan; comparing only to the
    // last scan let a repeat of an earlier time slip into scan_3/scan_4 as a
    // duplicate of scan_1/scan_2.
    if (!empty($filledScans)) {
        $newScanSec = strtotime("2000-01-01 " . $scanTimeOnly);
        foreach ($filledScans as $existingScan) {
            $existingSec = strtotime("2000-01-01 " . $existingScan);
            $diffMinutes = abs($newScanSec - $existingSec) / 60;
            if ($diffMinutes < 5) {
                $result = "[{$bizSlug}] Double scan ignored for {$employee['full_name']} (matches {$existingScan}, diff: " . round($diffMinutes) . "min)";
                logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, 1, $result, $rawBody);
                return ['success' => true, 'message' => $result];
            }
        }
    }

    // Max 4 scans
    if (count($filledScans) >= 4) {
        $result = "[{$bizSlug}] Max 4 scans for {$employee['full_name']} on {$scanDate}";
        logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, 0, $result, $rawBody);
        return ['success' => false, 'message' => $result];
    }

    // Late detection threshold
    $checkinEnd = '10:00:00';
    try {
        $cfgTime = $pdo->query("SELECT checkin_end FROM payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $checkinEnd = $cfgTime['checkin_end'] ?? '10:00:00';
    } catch (Exception $e) {
    }

    $scanLabels = ['Scan 1 (Masuk Shift 1)', 'Scan 2 (Pulang Shift 1)', 'Scan 3 (Masuk Shift 2)', 'Scan 4 (Pulang Shift 2)'];
    $result = '';

    try {
        if (!$att) {
            $status = ($scanTimeOnly > $checkinEnd) ? 'late' : 'present';
            $pdo->prepare("INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, status, check_in_device, notes) VALUES (?,?,?,?,?,?)")
                ->execute([$empId, $scanDate, $scanTimeOnly, $status, 'fingerprint:' . $verify, 'Fingerspot split-shift']);
            $result = "[{$bizSlug}] {$scanLabels[0]}: {$employee['full_name']} at {$scanTimeOnly} ({$status})";
        } else {
            $scanNum = 0;
            $updateCol = '';
            if (empty($scan1)) {
                $updateCol = 'check_in_time';
                $scanNum = 1;
            } elseif (empty($scan2)) {
                $updateCol = 'check_out_time';
                $scanNum = 2;
            } elseif (empty($scan3)) {
                $updateCol = 'scan_3';
                $scanNum = 3;
            } elseif (empty($scan4)) {
                $updateCol = 'scan_4';
                $scanNum = 4;
            }

            if ($scanNum > 0) {
                $shift1Hours = null;
                $shift2Hours = null;

                if ($scanNum === 2 && $scan1) {
                    $s1 = strtotime("2000-01-01 " . $scan1);
                    $s2 = strtotime("2000-01-01 " . $scanTimeOnly);
                    $shift1Hours = ($s2 > $s1) ? round(($s2 - $s1) / 3600, 2) : null;
                }
                if ($scanNum === 4 && $scan3) {
                    $s3 = strtotime("2000-01-01 " . $scan3);
                    $s4 = strtotime("2000-01-01 " . $scanTimeOnly);
                    $shift2Hours = ($s4 > $s3) ? round(($s4 - $s3) / 3600, 2) : null;
                }

                $updates = ["{$updateCol} = ?"];
                $params = [$scanTimeOnly];

                if ($shift1Hours !== null) {
                    $updates[] = "shift_1_hours = ?";
                    $params[] = $shift1Hours;
                }
                if ($shift2Hours !== null) {
                    $updates[] = "shift_2_hours = ?";
                    $params[] = $shift2Hours;
                }

                $curShift1 = ($shift1Hours !== null) ? $shift1Hours : (float)($att['shift_1_hours'] ?? 0);
                $curShift2 = ($shift2Hours !== null) ? $shift2Hours : (float)($att['shift_2_hours'] ?? 0);
                $totalHours = $curShift1 + $curShift2;
                $updates[] = "work_hours = ?";
                $params[] = round($totalHours, 2);

                $updates[] = "notes = ?";
                $params[] = "Split-shift: {$scanNum}/4 scans";
                $params[] = $att['id'];

                $sql = "UPDATE payroll_attendance SET " . implode(', ', $updates) . " WHERE id = ?";
                $pdo->prepare($sql)->execute($params);

                $hoursTxt = '';
                if ($scanNum === 2 && $shift1Hours) $hoursTxt = " (shift1: {$shift1Hours}h)";
                if ($scanNum === 4 && $shift2Hours) $hoursTxt = " (shift2: {$shift2Hours}h, total: {$totalHours}h)";

                $result = "[{$bizSlug}] {$scanLabels[$scanNum - 1]}: {$employee['full_name']} at {$scanTimeOnly}{$hoursTxt}";
            }
        }

        logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, 1, $result, $rawBody);
        return ['success' => true, 'message' => $result];
    } catch (Exception $e) {
        $errMsg = "[{$bizSlug}] DB Error: " . $e->getMessage();
        logWebhook($pdo, $cloudId, $type, $pin, $scanTime, $verify, $statusScan, $empId, 0, $errMsg, $rawBody);
        return ['success' => false, 'message' => $errMsg];
    }
}

// ══════════════════════════════════════════════════════════════
// RESOLVE BUSINESS(ES)
// ══════════════════════════════════════════════════════════════

$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$singleBizMode = !empty($bizSlug);

// Collect business configs to process
$bizList = []; // [{slug, config, db, pdo}, ...]

if ($singleBizMode) {
    // ── Single business mode (backward compatible) ──
    $bizFile = __DIR__ . '/../config/businesses/' . $bizSlug . '.php';
    if (!file_exists($bizFile)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Business parameter (?b=) invalid']);
        exit;
    }
    $bizCfg = require $bizFile;
    if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $bizCfg['business_id']);
    $db = Database::switchDatabase($bizCfg['database']);
    $pdo = $db->getConnection();
    ensureTablesExist($pdo);
    $bizList[] = ['slug' => $bizSlug, 'config' => $bizCfg, 'pdo' => $pdo];
} else {
    // ── Multi-business mode: will resolve after reading cloud_id from POST ──
    // We load all businesses lazily after parsing the payload
}

// ── Diagnostic mode: GET ?b=xxx&diag=1&token=adf-deploy-2025-secure ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['diag'])) {
    $diagToken = $_GET['token'] ?? '';
    if (!hash_equals('adf-deploy-2025-secure', $diagToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    if ($singleBizMode && !empty($bizList)) {
        $pdo = $bizList[0]['pdo'];
        $diag = ['time' => date('Y-m-d H:i:s'), 'business' => $bizSlug, 'mode' => 'single'];
        $cfg = $pdo->query("SELECT fingerspot_cloud_id, fingerspot_enabled, checkin_end FROM payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $diag['config'] = $cfg;
        $emps = $pdo->query("SELECT id, employee_code, full_name, finger_id, is_active FROM payroll_employees ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $diag['employees'] = $emps;
        $logs = $pdo->query("SELECT id, cloud_id, pin, scan_time, verify_method, status_scan, employee_id, processed, process_result, created_at FROM fingerprint_log ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        $diag['recent_logs'] = $logs;
        $unmatched = $pdo->query("SELECT pin, process_result, scan_time, created_at FROM fingerprint_log WHERE employee_id IS NULL AND pin IS NOT NULL ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        $diag['unmatched_pins'] = $unmatched;
        $today = date('Y-m-d');
        $todayAtt = $pdo->query("SELECT pa.*, pe.full_name, pe.finger_id FROM payroll_attendance pa LEFT JOIN payroll_employees pe ON pa.employee_id = pe.id WHERE pa.attendance_date = '{$today}' ORDER BY pa.check_in_time")->fetchAll(PDO::FETCH_ASSOC);
        $diag['today_attendance'] = $todayAtt;
        echo json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        // Multi-business diag: show which businesses have fingerspot enabled
        $diag = ['time' => date('Y-m-d H:i:s'), 'mode' => 'multi', 'businesses' => []];
        $bizFiles = glob(__DIR__ . '/../config/businesses/*.php') ?: [];
        foreach ($bizFiles as $bf) {
            $slug = basename($bf, '.php');
            try {
                $cfg = require $bf;
                if (!canConnectBusiness($cfg)) {
                    $diag['businesses'][] = ['slug' => $slug, 'error' => 'DB unreachable, skipped'];
                    continue;
                }
                $bdb = Database::switchDatabase($cfg['database']);
                $bpdo = $bdb->getConnection();
                ensureTablesExist($bpdo);
                $fcfg = $bpdo->query("SELECT fingerspot_cloud_id, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
                $empCount = $bpdo->query("SELECT COUNT(*) as c FROM payroll_employees WHERE finger_id IS NOT NULL AND finger_id != '' AND is_active = 1")->fetch(PDO::FETCH_ASSOC);
                $diag['businesses'][] = [
                    'slug' => $slug,
                    'cloud_id' => $fcfg['fingerspot_cloud_id'] ?? '',
                    'enabled' => (int)($fcfg['fingerspot_enabled'] ?? 0),
                    'employees_with_pin' => (int)($empCount['c'] ?? 0),
                ];
            } catch (Exception $e) {
                $diag['businesses'][] = ['slug' => $slug, 'error' => $e->getMessage()];
            }
        }
        echo json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// READ POST BODY
// ══════════════════════════════════════════════════════════════

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

// Fallback: try form-encoded data if JSON parsing failed
if (!$data && !empty($_POST)) {
    $data = $_POST;
    $rawBody = json_encode($_POST);
}

// Log ALL incoming requests for debugging (temporary)
$debugLogFile = __DIR__ . '/../uploads/webhook-debug.log';
$debugEntry = date('Y-m-d H:i:s') . " | Method: {$_SERVER['REQUEST_METHOD']} | Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'none') . " | Body length: " . strlen($rawBody) . " | Body: " . substr($rawBody, 0, 500) . "\n";
@file_put_contents($debugLogFile, $debugEntry, FILE_APPEND);

if (!$data || !isset($data['type']) || !isset($data['cloud_id'])) {
    // Try to log to any available business DB
    $logPdo = null;
    if ($singleBizMode && !empty($bizList)) {
        $logPdo = $bizList[0]['pdo'];
    } else {
        // Try to find any business to log the invalid payload
        $bizFiles = glob(__DIR__ . '/../config/businesses/*.php') ?: [];
        foreach ($bizFiles as $bf) {
            try {
                $cfg = require $bf;
                if (!canConnectBusiness($cfg)) {
                    continue;
                }
                $bdb = Database::switchDatabase($cfg['database']);
                $logPdo = $bdb->getConnection();
                ensureTablesExist($logPdo);
                break;
            } catch (Exception $e) {
            }
        }
    }
    if ($logPdo) {
        try {
            $logPdo->prepare("INSERT INTO fingerprint_log (cloud_id, type, raw_data, process_result) VALUES (?,?,?,?)")
                ->execute(['unknown', 'invalid', substr($rawBody, 0, 2000), 'Invalid payload - type or cloud_id missing']);
        } catch (Exception $e) {
        }
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$type = $data['type'];
$cloudId = $data['cloud_id'];

// ══════════════════════════════════════════════════════════════
// MULTI-BUSINESS: Resolve matching businesses by cloud_id
// ══════════════════════════════════════════════════════════════

if (!$singleBizMode) {
    $bizFiles = glob(__DIR__ . '/../config/businesses/*.php') ?: [];
    foreach ($bizFiles as $bf) {
        $slug = basename($bf, '.php');
        try {
            $cfg = require $bf;
            if (!canConnectBusiness($cfg)) {
                continue;
            }
            $bdb = Database::switchDatabase($cfg['database']);
            $bpdo = $bdb->getConnection();
            ensureTablesExist($bpdo);

            $fcfg = $bpdo->query("SELECT fingerspot_cloud_id, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            $fpEnabled = (int)($fcfg['fingerspot_enabled'] ?? 0);
            $fpCloudId = $fcfg['fingerspot_cloud_id'] ?? '';

            // Match: enabled + cloud_id matches
            if ($fpEnabled && !empty($fpCloudId) && $fpCloudId === $cloudId) {
                $bizList[] = ['slug' => $slug, 'config' => $cfg, 'pdo' => $bpdo];
            }
        } catch (Exception $e) {
            error_log("Fingerprint multi-biz: error loading {$slug}: " . $e->getMessage());
        }
    }

    if (empty($bizList)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "No business found with cloud_id '{$cloudId}' and fingerspot enabled"]);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
// SINGLE BUSINESS: Validate config (backward compatible checks)
// ══════════════════════════════════════════════════════════════

if ($singleBizMode) {
    $pdo = $bizList[0]['pdo'];
    $cfgStmt = $pdo->query("SELECT fingerspot_cloud_id, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1");
    $cfg = $cfgStmt->fetch(PDO::FETCH_ASSOC);
    $registeredCloudId = $cfg['fingerspot_cloud_id'] ?? '';
    $fpEnabled = (int)($cfg['fingerspot_enabled'] ?? 0);

    if (!$fpEnabled) {
        logWebhook($pdo, $cloudId, $type, null, null, null, null, null, 0, 'Fingerspot disabled in settings', $rawBody);
        echo json_encode(['success' => false, 'message' => 'Fingerspot integration disabled']);
        exit;
    }

    if (!empty($registeredCloudId) && $cloudId !== $registeredCloudId) {
        logWebhook($pdo, $cloudId, $type, null, null, null, null, null, 0, "Cloud ID mismatch: received '{$cloudId}', expected '{$registeredCloudId}'", $rawBody);
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => "Cloud ID mismatch"]);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
// PROCESS ATTLOG
// ══════════════════════════════════════════════════════════════

if ($type === 'attlog' && isset($data['data'])) {
    $pin = $data['data']['pin'] ?? null;
    $scanStr = $data['data']['scan'] ?? null;
    $verify = $data['data']['verify'] ?? null;
    $statusScan = $data['data']['status_scan'] ?? null;

    if (!$pin || !$scanStr) {
        foreach ($bizList as $biz) {
            logWebhook($biz['pdo'], $cloudId, $type, $pin, null, $verify, $statusScan, null, 0, 'Missing pin or scan time', $rawBody);
        }
        echo json_encode(['success' => false, 'message' => 'Missing required data']);
        exit;
    }

    // Process in each matched business
    $results = [];
    foreach ($bizList as $biz) {
        $r = processAttlogForBusiness($biz['pdo'], $biz['slug'], $cloudId, $type, $pin, $scanStr, $verify, $statusScan, $rawBody);
        $results[] = $r;
    }

    // Return combined result
    $anySuccess = !empty(array_filter($results, fn($r) => $r['success']));
    $messages = array_column($results, 'message');

    echo json_encode([
        'success' => $anySuccess,
        'mode' => $singleBizMode ? 'single' : 'multi',
        'businesses_processed' => count($bizList),
        'message' => implode(' | ', $messages),
        'details' => $results,
    ]);
} elseif ($type === 'get_userinfo' && isset($data['data'])) {
    // ══════════════════════════════════════════════════════════════
    // PROCESS GET_USERINFO (response from device via webhook)
    // ══════════════════════════════════════════════════════════════
    $ud = $data['data'];
    $pin = trim($ud['pin'] ?? '');
    $name = trim($ud['name'] ?? '');
    $privilege = $ud['privilege'] ?? '0';
    $finger = $ud['finger'] ?? '0';
    $face = $ud['face'] ?? '0';
    $password = $ud['password'] ?? '';
    $rfid = $ud['rfid'] ?? '';
    $vein = $ud['vein'] ?? '0';
    $transId = $data['trans_id'] ?? '';

    foreach ($bizList as $biz) {
        $bpdo = $biz['pdo'];
        // Ensure table exists
        try {
            $bpdo->query("SELECT 1 FROM fingerspot_userinfo LIMIT 0");
        } catch (PDOException $e) {
            $bpdo->exec("CREATE TABLE IF NOT EXISTS `fingerspot_userinfo` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `cloud_id` VARCHAR(50) NOT NULL,
                `pin` VARCHAR(20) NOT NULL,
                `name` VARCHAR(100) DEFAULT '',
                `privilege` VARCHAR(10) DEFAULT '0',
                `finger` VARCHAR(10) DEFAULT '0',
                `face` VARCHAR(10) DEFAULT '0',
                `password` VARCHAR(50) DEFAULT '',
                `rfid` VARCHAR(50) DEFAULT '',
                `vein` VARCHAR(10) DEFAULT '0',
                `employee_id` INT DEFAULT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_cloud_pin (cloud_id, pin),
                INDEX idx_pin (pin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // Match employee
        $empId = null;
        $empStmt = $bpdo->prepare("SELECT id FROM payroll_employees WHERE TRIM(finger_id) = ? AND is_active = 1");
        $empStmt->execute([$pin]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp && is_numeric($pin)) {
            $empStmt = $bpdo->prepare("SELECT id FROM payroll_employees WHERE CAST(TRIM(finger_id) AS UNSIGNED) = CAST(? AS UNSIGNED) AND is_active = 1");
            $empStmt->execute([$pin]);
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($emp) $empId = (int)$emp['id'];

        // Upsert
        $bpdo->prepare("INSERT INTO fingerspot_userinfo (cloud_id, pin, name, privilege, finger, face, password, rfid, vein, employee_id) 
            VALUES (?,?,?,?,?,?,?,?,?,?) 
            ON DUPLICATE KEY UPDATE name=VALUES(name), privilege=VALUES(privilege), finger=VALUES(finger), face=VALUES(face), password=VALUES(password), rfid=VALUES(rfid), vein=VALUES(vein), employee_id=VALUES(employee_id), updated_at=NOW()")
            ->execute([$cloudId, $pin, $name, $privilege, $finger, $face, $password, $rfid, $vein, $empId]);

        logWebhook($bpdo, $cloudId, $type, $pin, null, null, null, $empId, 1, "Userinfo saved: {$name} (PIN {$pin})", $rawBody);
    }

    echo json_encode(['success' => true, 'message' => "Userinfo PIN {$pin} saved", 'businesses' => count($bizList)]);
} else {
    // Non-attlog type — just log it
    foreach ($bizList as $biz) {
        logWebhook($biz['pdo'], $cloudId, $type, null, null, null, null, null, 0, "Unhandled type: {$type}", $rawBody);
    }
    echo json_encode(['success' => true, 'message' => "Logged type: {$type}"]);
}
