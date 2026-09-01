<?php

/**
 * Fingerspot.io Auto-Sync Cron — Multi-Business
 * ------------------------------------------------------------------
 * Pulls recent attendance logs from the Fingerspot.io cloud API and
 * replays each scan into the EXISTING webhook receiver
 * (api/fingerprint-webhook.php). This guarantees attendance keeps
 * updating to the system & staff phones even when the Fingerspot
 * real-time push (webhook) stops sending data.
 *
 * It reuses all webhook processing logic (de-dup, split-shift, late
 * detection, multi-business fan-out) — this script only fetches and
 * replays, it does NOT duplicate the attendance logic.
 *
 * USAGE
 *   HTTP : https://adfsystem.online/api/fingerprint-cron-sync.php?token=adf-deploy-2025-secure&days=2
 *   CLI  : php api/fingerprint-cron-sync.php --token=adf-deploy-2025-secure --days=2
 *
 * PARAMS
 *   token : required security token (must match CRON_TOKEN below)
 *   days  : how many days back to sync (default 2, Fingerspot API max chunk is 2 days)
 *   b     : optional business slug — limit sync to one business only
 */

define('APP_ACCESS', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

const CRON_TOKEN = 'adf-deploy-2025-secure';

$isCli = (php_sapi_name() === 'cli');

// ── Resolve parameters (CLI args or HTTP query) ──
$params = $_GET;
if ($isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
            $params[$m[1]] = $m[2];
        }
    }
}

$token   = $params['token'] ?? '';
$days    = max(1, min(30, (int)($params['days'] ?? 2)));
$bizOnly = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($params['b'] ?? '')));
$reset   = !empty($params['reset']); // when set, wipe fingerprint rows in range before replay (clean rebuild)

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

// ── Security ──
if (!hash_equals(CRON_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

$syncFrom = date('Y-m-d', strtotime("-{$days} days"));
$syncTo   = date('Y-m-d');

// Webhook URL (multi-business fan-out mode — no ?b= param)
$webhookUrl = 'https://' . MASTER_DOMAIN . '/api/fingerprint-webhook.php';

// ──────────────────────────────────────────────────────────────
// Collect UNIQUE Fingerspot devices (cloud_id + token) across all
// enabled businesses. One physical device may serve many businesses,
// so we fetch once per cloud_id and let the webhook fan it out.
// ──────────────────────────────────────────────────────────────
$devices = []; // cloud_id => token
$resetCounts = []; // slug => deleted rows (only when reset=1)

$bizFiles = glob(__DIR__ . '/../config/businesses/*.php') ?: [];
foreach ($bizFiles as $bf) {
    $slug = basename($bf, '.php');
    if ($bizOnly && $slug !== $bizOnly) {
        continue;
    }
    try {
        $cfg = require $bf;
        if (empty($cfg['database'])) {
            continue;
        }

        // Resolve hosting DB name the same way Database::switchDatabase() does,
        // then probe the connection FIRST. switchDatabase() calls die() on a
        // failed connection, so we must skip unreachable (test/demo) DBs here.
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
            error_log("Cron-sync: skip {$slug} (DB unreachable): " . $pe->getMessage());
            continue;
        }

        $bdb  = Database::switchDatabase($cfg['database']);
        $bpdo = $bdb->getConnection();

        $fcfg = $bpdo->query("SELECT fingerspot_cloud_id, fingerspot_token, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$fcfg) {
            continue;
        }
        $enabled = (int)($fcfg['fingerspot_enabled'] ?? 0);
        $cloudId = trim($fcfg['fingerspot_cloud_id'] ?? '');
        $apiTok  = trim($fcfg['fingerspot_token'] ?? '');

        // Optional clean rebuild: remove fingerprint-sourced attendance rows in
        // the sync range so the (fixed) webhook dedup can rebuild them correctly.
        if ($reset && $enabled && $cloudId) {
            $del = $bpdo->prepare(
                "DELETE FROM payroll_attendance
                 WHERE attendance_date BETWEEN ? AND ?
                   AND (check_in_device LIKE 'fingerprint:%'
                        OR notes LIKE '%Fingerspot%'
                        OR notes LIKE '%Split-shift%'
                        OR notes LIKE 'Sync:%')"
            );
            $del->execute([$syncFrom, $syncTo]);
            $resetCounts[$slug] = $del->rowCount();
        }

        if ($enabled && $cloudId && $apiTok && !isset($devices[$cloudId])) {
            $devices[$cloudId] = $apiTok;
        }
    } catch (Exception $e) {
        error_log("Cron-sync: skip {$slug}: " . $e->getMessage());
    }
}

if (empty($devices)) {
    echo json_encode([
        'success' => false,
        'message' => 'No enabled Fingerspot device found (cloud_id/token missing).',
        'time'    => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT);
    exit;
}

// ──────────────────────────────────────────────────────────────
// Helper: fetch attlog for one cloud_id over a (max 2-day) range
// ──────────────────────────────────────────────────────────────
function fp_fetch_attlog(string $cloudId, string $apiToken, string $from, string $to): array
{
    $apiUrl  = 'https://developer.fingerspot.io/api/get_attlog';
    $allLogs = [];
    $errors  = [];

    $start = new DateTime($from);
    $end   = new DateTime($to);
    $end->modify('+1 day'); // inclusive

    // Fingerspot API allows max 2-day range per call — chunk it
    $period = new DatePeriod($start, new DateInterval('P2D'), $end);

    foreach ($period as $chunkStart) {
        $chunkEnd = clone $chunkStart;
        $chunkEnd->modify('+1 day');
        if ($chunkEnd > new DateTime($to)) {
            $chunkEnd = new DateTime($to);
        }

        $postData = [
            'trans_id'   => uniqid('cron_'),
            'cloud_id'   => $cloudId,
            'start_date' => $chunkStart->format('Y-m-d'),
            'end_date'   => $chunkEnd->format('Y-m-d'),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiToken,
            ],
        ]);
        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $errors[] = "{$chunkStart->format('Y-m-d')}: {$curlError}";
            continue;
        }

        $data = json_decode($response, true);
        if ($data && ($data['success'] ?? false) === true && isset($data['data']) && is_array($data['data'])) {
            $allLogs = array_merge($allLogs, $data['data']);
        } elseif ($data && ($data['success'] ?? null) === false) {
            $errors[] = "{$chunkStart->format('Y-m-d')}: " . ($data['message'] ?? 'API error');
        }

        usleep(200000); // 0.2s — avoid rate limiting
    }

    return ['logs' => $allLogs, 'errors' => $errors];
}

// ──────────────────────────────────────────────────────────────
// Helper: replay one scan into the existing webhook receiver
// ──────────────────────────────────────────────────────────────
function fp_replay_to_webhook(string $webhookUrl, string $cloudId, array $log): bool
{
    $pin      = trim((string)($log['pin'] ?? $log['user_id'] ?? ''));
    $scanTime = $log['scan_date'] ?? $log['datetime'] ?? $log['scan'] ?? '';
    $verify   = $log['verify'] ?? $log['verify_type'] ?? 'finger';
    $status   = $log['status_scan'] ?? $log['status'] ?? '';

    if ($pin === '' || $scanTime === '') {
        return false;
    }

    $payload = json_encode([
        'type'     => 'attlog',
        'cloud_id' => $cloudId,
        'data'     => [
            'pin'         => $pin,
            'scan'        => $scanTime,
            'verify'      => $verify,
            'status_scan' => $status,
        ],
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $webhookUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode > 0 && $httpCode < 500;
}

// ──────────────────────────────────────────────────────────────
// Run sync per device
// ──────────────────────────────────────────────────────────────
$summary = [
    'success'    => true,
    'time'       => date('Y-m-d H:i:s'),
    'range'      => ['from' => $syncFrom, 'to' => $syncTo],
    'webhook'    => $webhookUrl,
    'devices'    => [],
];

$grandReplayed = 0;
foreach ($devices as $cloudId => $apiToken) {
    $fetch    = fp_fetch_attlog($cloudId, $apiToken, $syncFrom, $syncTo);
    $logs     = $fetch['logs'];
    $replayed = 0;

    foreach ($logs as $log) {
        if (fp_replay_to_webhook($webhookUrl, $cloudId, $log)) {
            $replayed++;
        }
    }
    $grandReplayed += $replayed;

    $summary['devices'][] = [
        'cloud_id'      => $cloudId,
        'logs_fetched'  => count($logs),
        'replayed'      => $replayed,
        'api_errors'    => $fetch['errors'],
    ];
}

$summary['total_replayed'] = $grandReplayed;
if ($reset) {
    $summary['reset'] = true;
    $summary['rows_deleted'] = $resetCounts;
}
$logLine = date('Y-m-d H:i:s') . " | range {$syncFrom}..{$syncTo} | devices " . count($devices) . " | replayed {$grandReplayed}\n";
@file_put_contents(__DIR__ . '/../uploads/fingerprint-cron.log', $logLine, FILE_APPEND);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
