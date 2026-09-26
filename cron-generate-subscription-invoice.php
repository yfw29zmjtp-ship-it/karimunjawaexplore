<?php

/**
 * cron-generate-subscription-invoice.php
 * ------------------------------------------------------------------
 * Ensures the current month's ADF System subscription invoice exists
 * (base fee + per-confirmed-guest fee). Safe to run daily — it only
 * creates a new row the first time it's called for a given period, and
 * refreshes the guest count on the still-unpaid current-month invoice.
 *
 * SETUP (cPanel Cron Job, once a month is enough, e.g. day 1, hour 6):
 *   /usr/bin/curl -s "https://karimunjawaexplore.com/cron-generate-subscription-invoice.php?token=YOUR_TOKEN"
 *
 * The token is read from the `subscription_cron_token` setting — set it once
 * manually (e.g. via a one-off script or directly in the settings table),
 * then use that same value in the cron command above.
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/modules/sunsea/db-helper.php';

header('Content-Type: text/plain');

$pdo = getSunseaConnection();
sunseaEnsureSubscriptionBillingSchema($pdo);

$expectedToken = sunseaSetting($pdo, 'subscription_cron_token', '');
$providedToken = $_GET['token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$period = date('Y-m');
$invoice = sunseaGetOrRefreshSubscriptionInvoice($pdo, $period);

if ($invoice) {
    echo "OK - invoice periode {$period}: {$invoice['guest_count']} tamu, total Rp " . number_format((float) $invoice['total_amount'], 0, ',', '.') . " (status: {$invoice['status']})\n";
} else {
    echo "Gagal membuat/mengambil invoice periode {$period}\n";
}
