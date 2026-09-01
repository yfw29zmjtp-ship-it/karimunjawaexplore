<?php

/**
 * Header Notification Banner - Unpaid Checked-in Guests
 * Display running text notification for guests who checked in without full payment
 * Include in includes/header.php
 */

function getUnpaidCheckedInGuests($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT
                b.id,
                b.booking_code,
                b.final_price,
                b.paid_amount,
                g.guest_name,
                r.room_number,
                COALESCE(bp.total_paid, b.paid_amount, 0) AS total_paid
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN (
                SELECT booking_id, SUM(amount) AS total_paid
                FROM booking_payments
                GROUP BY booking_id
            ) bp ON bp.booking_id = b.id
            WHERE b.status IN ('checked_in', 'checked_out')
            AND b.payment_status != 'paid'
            ORDER BY b.actual_checkin_time ASC
            LIMIT 50
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("Unpaid checked-in guests query failed: " . $e->getMessage());
        return [];
    }
}

function formatUnpaidGuestMessages($unpaidGuests)
{
    if (empty($unpaidGuests)) {
        return [];
    }

    // Gabungkan tamu dengan nama persis sama jadi satu baris (total tagihan digabung)
    $grouped = [];
    foreach ($unpaidGuests as $guest) {
        $name = trim($guest['guest_name'] ?? '-');
        $key = mb_strtolower($name);
        $total = (float)($guest['final_price'] ?? 0);
        $paid = (float)($guest['total_paid'] ?? 0);
        $remaining = max(0, $total - $paid);

        if (!isset($grouped[$key])) {
            $grouped[$key] = ['name' => $name, 'rooms' => [], 'remaining' => 0];
        }
        $grouped[$key]['rooms'][] = $guest['room_number'];
        $grouped[$key]['remaining'] += $remaining;
    }

    $messages = [];
    foreach ($grouped as $g) {
        $roomLabel = count($g['rooms']) > 1
            ? count($g['rooms']) . ' Kamar (' . implode(', ', $g['rooms']) . ')'
            : 'Room ' . $g['rooms'][0];
        $messages[] = "💰 {$roomLabel} — {$g['name']} — BELUM LUNAS (Sisa Rp " . number_format($g['remaining'], 0, ',', '.') . ")";
    }

    return $messages;
}

function getUnpaidHotelServiceInvoices($pdo, $businessId)
{
    try {
        $stmt = $pdo->prepare("
            SELECT invoice_number, guest_name, room_number, total, paid_amount
            FROM hotel_invoices
            WHERE business_id = ?
            AND payment_status != 'paid'
            AND status != 'cancelled'
            ORDER BY created_at ASC
            LIMIT 50
        ");
        $stmt->execute([$businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("Unpaid hotel service invoices query failed: " . $e->getMessage());
        return [];
    }
}

function formatUnpaidHotelServiceMessages($unpaidInvoices)
{
    if (empty($unpaidInvoices)) {
        return [];
    }

    // Gabungkan tamu dengan nama persis sama jadi satu baris (total tagihan digabung)
    $grouped = [];
    foreach ($unpaidInvoices as $invoice) {
        $name = trim($invoice['guest_name'] ?? '-');
        $key = mb_strtolower($name);
        $remaining = max(0, (float)($invoice['total'] ?? 0) - (float)($invoice['paid_amount'] ?? 0));

        if (!isset($grouped[$key])) {
            $grouped[$key] = ['name' => $name, 'rooms' => [], 'remaining' => 0];
        }
        if (!empty($invoice['room_number'])) {
            $grouped[$key]['rooms'][] = $invoice['room_number'];
        }
        $grouped[$key]['remaining'] += $remaining;
    }

    $messages = [];
    foreach ($grouped as $g) {
        $rooms = array_unique($g['rooms']);
        $roomLabel = count($rooms) > 1
            ? count($rooms) . ' Kamar (' . implode(', ', $rooms) . ')'
            : (count($rooms) === 1 ? 'Room ' . reset($rooms) : '-');
        $messages[] = "🛎️ {$roomLabel} — {$g['name']} — Hotel Service BELUM LUNAS (Sisa Rp " . number_format($g['remaining'], 0, ',', '.') . ")";
    }

    return $messages;
}
