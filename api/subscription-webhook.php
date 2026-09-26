<?php

/**
 * Pakasir webhook receiver for the Sunsea subscription billing charged by ADF System.
 * Configure this URL in the Pakasir project settings (Webhook URL field).
 */
define('APP_ACCESS', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/sunsea/db-helper.php';

header('Content-Type: application/json');

try {
    $pdo = getSunseaConnection();
    sunseaEnsureSubscriptionBillingSchema($pdo);
    $cfg = sunseaSubscriptionConfig($pdo);
    $expectedSecret = $cfg['pakasir_webhook_secret'];

    $receivedSecret = $_SERVER['HTTP_X_SECRET'] ?? '';
    if ($expectedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
        http_response_code(403);
        echo json_encode(['error' => 'invalid secret']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload) || empty($payload['order_id']) || empty($payload['status'])) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid payload']);
        exit;
    }

    $orderId = (string) $payload['order_id'];
    $status = (string) $payload['status'];
    $completedAt = $payload['completed_at'] ?? null;

    if ($status === 'completed') {
        $paidAt = $completedAt ?? date('c');
        $pdo->prepare("UPDATE subscription_invoices SET status='paid', paid_at=? WHERE order_id=?")
            ->execute([$paidAt, $orderId]);

        $stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE order_id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $paidInvoice = $stmt->fetch();
        if ($paidInvoice) {
            sunseaNotifyAdfSystemPaymentSuccess($pdo, $paidInvoice);
        }
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('subscription-webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
