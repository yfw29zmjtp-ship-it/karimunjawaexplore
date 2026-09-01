<?php

/**
 * Motor Return Tracking System
 * Track unpaid motor returns with 24-hour reminder
 * 
 * Features:
 * - Confirm motor return status when invoice is paid
 * - Auto-notification if motor not returned within 24 hours
 * - Dashboard widget for overdue motors
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$pdo = $db->getConnection();
$businessId = $_SESSION['business_id'] ?? 1;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        // Confirm motor return status (called when invoice is paid or from monitoring dashboard)
        if ($_POST['action'] === 'confirm_return_status') {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $rentalId = (int)($_POST['rental_id'] ?? 0); // Support single rental_id from monitoring
            $motorBookingIds = json_decode($_POST['motor_ids'] ?? '[]', true); // array of rental_motor_bookings IDs
            $isReturned = (int)($_POST['is_returned'] ?? 0); // 1 = sudah kembali, 0 = belum kembali

            // Build motor IDs array
            if ($rentalId > 0) {
                $motorBookingIds = [$rentalId];
            } elseif (!$invoiceId || empty($motorBookingIds)) {
                throw new Exception('Data tidak lengkap');
            }

            $updated = 0;
            foreach ($motorBookingIds as $rbId) {
                $rbId = (int)$rbId;
                $pdo->prepare("
                    UPDATE rental_motor_bookings 
                    SET 
                        return_confirmed_at = NOW(),
                        return_confirmed = ?,
                        payment_date = NOW()
                    WHERE id = ? AND business_id = ?
                ")->execute([$isReturned, $rbId, $businessId]);
                $updated++;

                // If motor returned immediately, update status
                if ($isReturned) {
                    $motor = $pdo->prepare("SELECT motor_id FROM rental_motor_bookings WHERE id = ?");
                    $motor->execute([$rbId]);
                    $motorId = $motor->fetchColumn();

                    if ($motorId) {
                        $pdo->prepare("UPDATE rental_motors SET status='available', updated_at=NOW() WHERE id=?")->execute([$motorId]);
                        $pdo->prepare("UPDATE rental_motor_bookings SET status='returned', actual_return=NOW() WHERE id=?")->execute([$rbId]);
                    }
                }
                // If motor not returned (return_confirmed=0), status stays 'active' and payment_date is set for 24h tracking
            }

            echo json_encode(['success' => true, 'updated' => $updated]);
            exit;
        }

        // Get motors not yet returned (24h tracking)
        if ($_POST['action'] === 'get_overdue_motors') {
            $motorsOverdue = $pdo->prepare("
                SELECT 
                    rb.id,
                    rb.guest_name,
                    rm.motor_name,
                    rm.plate_number,
                    hi.invoice_number,
                    rb.start_datetime,
                    rb.payment_date,
                    TIMESTAMPDIFF(HOUR, rb.payment_date, NOW()) as hours_since_payment,
                    CASE WHEN TIMESTAMPDIFF(HOUR, rb.payment_date, NOW()) >= 24 THEN 1 ELSE 0 END as is_overdue
                FROM rental_motor_bookings rb
                JOIN rental_motors rm ON rb.motor_id = rm.id
                LEFT JOIN hotel_invoices hi ON rb.invoice_id = hi.id
                WHERE rb.business_id = ?
                AND rb.status = 'active'
                AND rb.return_confirmed = 0
                AND rb.payment_date IS NOT NULL
                ORDER BY rb.payment_date ASC
            ");
            $motorsOverdue->execute([$businessId]);
            $overdue = $motorsOverdue->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'overdue' => $overdue]);
            exit;
        }

        // Manually mark motor as returned (from dashboard notification)
        if ($_POST['action'] === 'mark_motor_returned') {
            $motorBookingId = (int)($_POST['motor_booking_id'] ?? 0);
            if (!$motorBookingId) throw new Exception('Invalid rental ID');

            $booking = $pdo->prepare("SELECT motor_id FROM rental_motor_bookings WHERE id = ? AND business_id = ?");
            $booking->execute([$motorBookingId, $businessId]);
            $motorId = $booking->fetchColumn();

            if (!$motorId) throw new Exception('Rental tidak ditemukan');

            // Mark as returned
            $pdo->prepare("UPDATE rental_motor_bookings SET status='returned', actual_return=NOW(), updated_at=NOW() WHERE id=?")->execute([$motorBookingId]);
            $pdo->prepare("UPDATE rental_motors SET status='available', updated_at=NOW() WHERE id=?")->execute([$motorId]);

            echo json_encode(['success' => true, 'message' => 'Motor berhasil diupdate sebagai sudah kembali']);
            exit;
        }
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Default: show HTML page if accessed directly
?>
<!DOCTYPE html>
<html>

<head>
    <title>Motor Return Tracking</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 20px;
        }

        .notification {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            padding: 12px;
            border-radius: 6px;
            margin: 10px 0;
        }

        .notification.overdue {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
        }

        .motor-item {
            background: #fff;
            border: 1px solid #e5e7eb;
            padding: 12px;
            margin: 8px 0;
            border-radius: 4px;
        }

        .btn {
            padding: 8px 16px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn:hover {
            background: #2563eb;
        }
    </style>
</head>

<body>
    <h1>Motor Return Tracking</h1>
    <p>This module tracks motors that haven't been returned within 24 hours after invoice payment.</p>
    <p><em>API Endpoints:</em></p>
    <ul>
        <li><code>confirm_return_status</code> - Called when invoice is paid (in hotel-services.php)</li>
        <li><code>get_overdue_motors</code> - Get motors not returned > 24hrs (for dashboard widget)</li>
        <li><code>mark_motor_returned</code> - Manually mark motor as returned (from dashboard)</li>
    </ul>
</body>

</html>