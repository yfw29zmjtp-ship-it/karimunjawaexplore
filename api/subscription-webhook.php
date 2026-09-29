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
        $updated = $pdo->prepare("UPDATE subscription_invoices SET status='paid', paid_at=?, order_id=? WHERE order_id=?");
        $updated->execute([$paidAt, $orderId, $orderId]);

        // Manual invoice payment links are generated directly by ADF System with
        // order_id = 'manual-<id>', which never gets written locally beforehand
        // (unlike recurring invoices paid via pay-subscription.php). Fall back to
        // matching by period so those payments still get marked paid here.
        if ($updated->rowCount() === 0 && preg_match('/^manual-(.+)$/i', $orderId, $m)) {
            $period = 'MANUAL-' . $m[1];
            $pdo->prepare("UPDATE subscription_invoices SET status='paid', paid_at=?, order_id=? WHERE period=? AND type='manual'")
                ->execute([$paidAt, $orderId, $period]);
        }

        $stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE order_id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $paidInvoice = $stmt->fetch();
        if ($paidInvoice) {
            sunseaNotifyAdfSystemPaymentSuccess($pdo, $paidInvoice);
            $label = ($paidInvoice['type'] ?? 'recurring') === 'manual'
                ? ($paidInvoice['description'] ?: 'Tagihan Manual')
                : ('Periode ' . $paidInvoice['period']);
            sunseaNotifyOwnersPush(
                '✅ Pembayaran Berhasil',
                'Tagihan ADF System (' . $label . ') sebesar ' . sunseaRupiah((float) $paidInvoice['total_amount']) . ' sudah lunas.',
                ['url' => 'subscription-billing.php']
            );
        }
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('subscription-webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
