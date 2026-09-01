<?php

/**
 * PushNotificationHelper - Server-side Web Push using VAPID
 * Sends real push notifications to subscribed browsers
 */

defined('APP_ACCESS') or die('Direct access not allowed');

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    // Vendor not installed — class will still work for subscription management
    // but sendToSubscriptions() will fail gracefully
} else {
    require_once $autoloadPath;
}
require_once __DIR__ . '/../config/vapid.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushNotificationHelper
{
    private $db;
    private $webPush;

    public function __construct($db = null)
    {
        if ($db === null) {
            require_once __DIR__ . '/../config/database.php';
            $db = Database::getInstance();
        }
        $this->db = $db;
        $this->ensureTable();
        $this->initWebPush();
    }

    /**
     * Auto-create push_subscriptions table
     */
    private function ensureTable()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT DEFAULT NULL COMMENT 'users.id for admin/owner, NULL for staff',
            `employee_id` INT DEFAULT NULL COMMENT 'payroll_employees.id for staff portal',
            `endpoint` TEXT NOT NULL,
            `public_key` VARCHAR(255) NOT NULL,
            `auth_token` VARCHAR(255) NOT NULL,
            `user_agent` VARCHAR(500) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Initialize WebPush instance
     */
    private function initWebPush()
    {
        if (!class_exists('Minishlink\\WebPush\\WebPush')) {
            $this->webPush = null;
            return;
        }

        $auth = [
            'VAPID' => [
                'subject'    => VAPID_SUBJECT,
                'publicKey'  => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ],
        ];

        $this->webPush = new WebPush($auth, [], 30, [
            'verify' => false // disable SSL verify for local dev
        ]);
        $this->webPush->setReuseVAPIDHeaders(true);
        $this->webPush->setAutomaticPadding(false);
    }

    /**
     * Save or update a push subscription
     */
    public function saveSubscription(array $sub, ?int $userId = null, ?int $employeeId = null): bool
    {
        $endpoint  = $sub['endpoint'] ?? '';
        $publicKey = $sub['keys']['p256dh'] ?? '';
        $authToken = $sub['keys']['auth'] ?? '';
        $userAgent = $sub['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');

        if (empty($endpoint) || empty($publicKey) || empty($authToken)) {
            return false;
        }

        // Delete existing subscription with same endpoint
        $this->db->query("DELETE FROM push_subscriptions WHERE endpoint = ?", [$endpoint]);

        // Insert new
        $this->db->insert('push_subscriptions', [
            'user_id'     => $userId,
            'employee_id' => $employeeId,
            'endpoint'    => $endpoint,
            'public_key'  => $publicKey,
            'auth_token'  => $authToken,
            'user_agent'  => mb_substr($userAgent, 0, 500),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Remove a push subscription by endpoint
     */
    public function removeSubscription(string $endpoint): bool
    {
        $this->db->query("DELETE FROM push_subscriptions WHERE endpoint = ?", [$endpoint]);
        return true;
    }

    /**
     * Send push to specific user IDs (admin/owner system)
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): array
    {
        if (empty($userIds)) return ['sent' => 0, 'failed' => 0];

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $subs = $this->db->fetchAll(
            "SELECT * FROM push_subscriptions WHERE user_id IN ($placeholders)",
            $userIds
        ) ?: [];

        return $this->sendToSubscriptions($subs, $title, $body, $data);
    }

    /**
     * Send push to specific employee IDs (staff portal)
     */
    public function sendToEmployees(array $employeeIds, string $title, string $body, array $data = []): array
    {
        if (empty($employeeIds)) return ['sent' => 0, 'failed' => 0];

        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $subs = $this->db->fetchAll(
            "SELECT * FROM push_subscriptions WHERE employee_id IN ($placeholders)",
            $employeeIds
        ) ?: [];

        return $this->sendToSubscriptions($subs, $title, $body, $data);
    }

    /**
     * Send push to all admin/owner/developer users
     */
    public function sendToAdmins(string $title, string $body, array $data = []): array
    {
        try {
            $admins = $this->db->fetchAll("
                SELECT u.id FROM users u 
                JOIN roles r ON u.role_id = r.id 
                WHERE r.role_code IN ('owner', 'admin', 'developer') AND u.is_active = 1
            ") ?: [];

            $adminIds = array_column($admins, 'id');
            if (empty($adminIds)) return ['sent' => 0, 'failed' => 0];

            return $this->sendToUsers($adminIds, $title, $body, $data);
        } catch (\Exception $e) {
            return ['sent' => 0, 'failed' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send push to all subscriptions (broadcast)
     */
    public function sendToAll(string $title, string $body, array $data = []): array
    {
        $subs = $this->db->fetchAll("SELECT * FROM push_subscriptions") ?: [];
        return $this->sendToSubscriptions($subs, $title, $body, $data);
    }

    /**
     * Internal: send to array of subscription rows
     */
    private function sendToSubscriptions(array $subs, string $title, string $body, array $data = []): array
    {
        if (!$this->webPush) {
            return ['sent' => 0, 'failed' => 0, 'error' => 'WebPush library not available'];
        }

        $sent = 0;
        $failed = 0;
        $expired = [];

        $payload = json_encode([
            'title'   => $title,
            'body'    => $body,
            'icon'    => '/assets/img/logo.png',
            'badge'   => '/assets/img/badge.png',
            'tag'     => $data['tag'] ?? 'adf-push-' . time(),
            'data'    => $data,
            'vibrate' => [200, 100, 200],
        ]);

        foreach ($subs as $sub) {
            $subscription = Subscription::create([
                'endpoint'        => $sub['endpoint'],
                'publicKey'       => $sub['public_key'],
                'authToken'       => $sub['auth_token'],
                'contentEncoding' => 'aesgcm',
            ]);

            $this->webPush->queueNotification($subscription, $payload);
        }

        foreach ($this->webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } else {
                $failed++;
                // Remove expired/invalid subscriptions
                if ($report->isSubscriptionExpired()) {
                    $expired[] = $report->getEndpoint();
                }
            }
        }

        // Clean up expired subscriptions
        foreach ($expired as $endpoint) {
            $this->removeSubscription($endpoint);
        }

        return ['sent' => $sent, 'failed' => $failed, 'expired' => count($expired)];
    }

    /**
     * Get subscription count for a user
     */
    public function getSubscriptionCount(?int $userId = null, ?int $employeeId = null): int
    {
        if ($userId) {
            $result = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM push_subscriptions WHERE user_id = ?", [$userId]);
        } elseif ($employeeId) {
            $result = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM push_subscriptions WHERE employee_id = ?", [$employeeId]);
        } else {
            $result = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM push_subscriptions");
        }
        return (int)($result['cnt'] ?? 0);
    }
}
