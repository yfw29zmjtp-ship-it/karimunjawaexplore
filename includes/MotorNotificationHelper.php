<?php

/**
 * Header Notification Banner - Motor Overdue Tracking
 * Display running text notification for overdue motors
 * Include in includes/header.php
 */

// Get all overdue motors (status='overdue' OR end_datetime passed without return)
function getOverdueMotorsForNotification($pdo, $businessId = 1)
{
    try {
        $stmt = $pdo->prepare("
            SELECT 
                rb.id,
                rb.guest_name,
                rm.motor_name,
                rm.plate_number,
                rb.end_datetime,
                rb.status,
                TIMESTAMPDIFF(HOUR, rb.end_datetime, NOW()) as hours_overdue
            FROM rental_motor_bookings rb
            JOIN rental_motors rm ON rb.motor_id = rm.id
            WHERE rb.business_id = ?
            AND rb.status IN ('active', 'overdue')
            AND rb.end_datetime < NOW()
            AND TIMESTAMPDIFF(HOUR, rb.end_datetime, NOW()) >= 24
            ORDER BY rb.end_datetime ASC
            LIMIT 15
        ");
        $stmt->execute([$businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("Overdue motors query failed: " . $e->getMessage());
        return [];
    }
}

// Format notification messages
function formatOverdueMotorMessages($overdueMotors)
{
    if (empty($overdueMotors)) {
        return [];
    }

    $messages = [];
    foreach ($overdueMotors as $motor) {
        $hoursOverdue = max(0, (int)($motor['hours_overdue'] ?? 0));
        $daysOverdue  = floor($hoursOverdue / 24);
        $hoursLeft    = $hoursOverdue % 24;
        $timeStr      = $daysOverdue > 0 ? "{$daysOverdue} hari {$hoursLeft} jam" : "{$hoursOverdue} jam";

        $messages[] = "🚨 {$motor['motor_name']} ({$motor['plate_number']}) — Guest: {$motor['guest_name']} — {$timeStr} OVERDUE! Segera update status.";
    }

    return $messages;
}
