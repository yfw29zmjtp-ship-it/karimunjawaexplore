<?php

/** Public payment link for the current overdue subscription invoice — reachable even when login is blocked. */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'modules/sunsea/db-helper.php';

$pdo = getSunseaConnection();
sunseaEnsureSubscriptionBillingSchema($pdo);
sunseaSyncLedger($pdo, true); // nominal terbaru dari ADF Store sebelum link bayar dibuat
sunseaGetOrRefreshSubscriptionInvoice($pdo, date('Y-m'));

$reminder = sunseaGetSubscriptionReminder($pdo);
$cfg = sunseaSubscriptionConfig($pdo);

if (!$reminder || empty($reminder['invoice']) || $reminder['invoice']['status'] !== 'unpaid') {
    header('Location: login.php');
    exit;
}

$invoice = $reminder['invoice'];

// Tombol/link bayar baru dibuka mulai H-3 jatuh tempo.
if (!sunseaPayWindowOpen($invoice)) {
    header('Location: login.php');
    exit;
}

if (!sunseaSubscriptionIsConfigured($cfg)) {
    header('Location: login.php?pay_error=1');
    exit;
}

$orderId = 'SUB' . str_replace('-', '', $invoice['period']) . strtoupper(bin2hex(random_bytes(3)));
$result = sunseaPakasirCreatePaymentLink($cfg, $orderId, (int) round((float) $invoice['total_amount']));

if ($result === null) {
    header('Location: login.php?pay_error=1');
    exit;
}

$pdo->prepare("UPDATE subscription_invoices SET order_id=?, txn_id=?, payment_link=? WHERE period=?")
    ->execute([$orderId, $result['txn_id'], $result['payment_link'], $invoice['period']]);

header('Location: ' . $result['payment_link']);
exit;
