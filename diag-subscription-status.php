<?php

/** Temporary diagnostic page to inspect subscription_invoices state — no login required, not linked anywhere. */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'modules/sunsea/db-helper.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = getSunseaConnection();
sunseaEnsureSubscriptionBillingSchema($pdo);

echo "== subscription_invoices (last 10) ==\n";
$rows = $pdo->query("SELECT period, type, status, due_date, total_amount, paid_at FROM subscription_invoices ORDER BY period DESC LIMIT 10")->fetchAll();
foreach ($rows as $r) {
    echo implode(' | ', $r) . "\n";
}

echo "\n== sunseaGetNearestUnpaidSubscriptionInvoice() ==\n";
var_export(sunseaGetNearestUnpaidSubscriptionInvoice($pdo));

echo "\n\n== sunseaGetSubscriptionReminder() ==\n";
var_export(sunseaGetSubscriptionReminder($pdo));

echo "\n\n== current month invoice via sunseaGetOrRefreshSubscriptionInvoice() ==\n";
$currentSubInvoice = sunseaGetOrRefreshSubscriptionInvoice($pdo, date('Y-m'));
var_export($currentSubInvoice);

echo "\n\n== next month invoice (generated because current is paid) ==\n";
if ($currentSubInvoice && $currentSubInvoice['status'] === 'paid') {
    var_export(sunseaGetOrRefreshSubscriptionInvoice($pdo, date('Y-m', strtotime('first day of next month'))));
} else {
    echo "SKIPPED — current month invoice status is: " . ($currentSubInvoice['status'] ?? 'NULL');
}

echo "\n\n== after that, nearest unpaid again ==\n";
var_export(sunseaGetNearestUnpaidSubscriptionInvoice($pdo));
