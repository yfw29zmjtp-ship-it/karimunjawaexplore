<?php

/** Temporary diagnostic: manually re-fire the ADF System payment-notify call for the last paid invoice. */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'modules/sunsea/db-helper.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = getSunseaConnection();
sunseaEnsureSubscriptionBillingSchema($pdo);

echo "== settings ==\n";
echo "subscription_sync_url   = " . sunseaSetting($pdo, 'subscription_sync_url', '(default)') . "\n";
echo "subscription_client_key = " . sunseaSetting($pdo, 'subscription_client_key', '') . "\n";
echo "subscription_client_token = " . (sunseaSetting($pdo, 'subscription_client_token', '') !== '' ? '(set)' : '(EMPTY)') . "\n";

$invoice = sunseaGetRecentPaidSubscriptionInvoice($pdo, null);
echo "\n== last paid invoice ==\n";
var_export($invoice);

if ($invoice) {
    echo "\n\n== manually re-sending notify ==\n";
    $syncUrl = sunseaSetting($pdo, 'subscription_sync_url', 'https://adfsystem.store/api/subscription-config.php');
    $notifyUrl = str_replace('subscription-config.php', 'subscription-payment-notify.php', $syncUrl);
    echo "notifyUrl = $notifyUrl\n";

    $ch = curl_init($notifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'client_key' => sunseaSetting($pdo, 'subscription_client_key', ''),
            'client_token' => sunseaSetting($pdo, 'subscription_client_token', ''),
            'period' => $invoice['period'],
            'total_amount' => (float) $invoice['total_amount'],
            'paid_at' => $invoice['paid_at'] ?? date('c'),
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERAGENT => 'KarimunjawaExplore-SubscriptionNotify/1.0',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $verboseLog = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_STDERR, $verboseLog);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    rewind($verboseLog);
    $verboseOutput = stream_get_contents($verboseLog);
    fclose($verboseLog);
    curl_close($ch);

    echo "http_code = $httpCode\n";
    echo "curl_error = $curlError\n";
    echo "response = $response\n";
    echo "\n== verbose transcript ==\n" . $verboseOutput . "\n";

    echo "\n\n== control test: GET subscription-config.php (known-working sync endpoint) ==\n";
    $configUrl = sunseaSetting($pdo, 'subscription_sync_url', 'https://adfsystem.store/api/subscription-config.php');
    $configUrl .= (str_contains($configUrl, '?') ? '&' : '?') . 'client_key=' . urlencode(sunseaSetting($pdo, 'subscription_client_key', ''))
        . '&client_token=' . urlencode(sunseaSetting($pdo, 'subscription_client_token', ''));
    $ch2 = curl_init($configUrl);
    curl_setopt_array($ch2, [
        CURLOPT_USERAGENT => 'KarimunjawaExplore-SubscriptionNotify/1.0',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $curlError2 = curl_error($ch2);
    curl_close($ch2);
    echo "http_code = $httpCode2\n";
    echo "curl_error = $curlError2\n";
    echo "response = " . substr((string) $response2, 0, 500) . "\n";
}
