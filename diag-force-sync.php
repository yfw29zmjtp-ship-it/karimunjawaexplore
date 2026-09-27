<?php

/** Temporary: force an immediate manual-invoice + config sync from ADF System, bypassing the 1hr throttle. */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'modules/sunsea/db-helper.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = getSunseaConnection();
sunseaEnsureSubscriptionBillingSchema($pdo);

echo "== before ==\n";
$before = $pdo->query("SELECT id, period, type, description, total_amount, status, due_date FROM subscription_invoices ORDER BY id DESC LIMIT 10")->fetchAll();
foreach ($before as $row) {
    echo json_encode($row) . "\n";
}

echo "\n== forcing sync ==\n";
sunseaSyncSubscriptionConfig($pdo);
sunseaSyncManualInvoices($pdo);

echo "\n== after ==\n";
$after = $pdo->query("SELECT id, period, type, description, total_amount, status, due_date FROM subscription_invoices ORDER BY id DESC LIMIT 10")->fetchAll();
foreach ($after as $row) {
    echo json_encode($row) . "\n";
}
