<?php
/**
 * Agent Tool: get_booking
 * Ambil detail booking berdasarkan kode booking atau nomor telepon tamu.
 * Method: GET
 * Params: code=BK-xxx  ATAU  phone=08xxx
 * Header: X-Agent-Key: <key>
 */

header('Content-Type: application/json');
error_reporting(0); ini_set('display_errors', 0);

define('APP_ACCESS', true);
require_once '_auth.php';

$pdo = agent_auth_check();

try {
    $code  = trim($_GET['code']  ?? '');
    $phone = trim($_GET['phone'] ?? '');

    if (!$code && !$phone) {
        echo json_encode(['success' => false, 'error' => 'Parameter code atau phone wajib diisi']);
        exit;
    }

    if ($code) {
        $stmt = $pdo->prepare("SELECT b.*, g.guest_name, g.phone, g.email,
                    r.room_number, rt.type_name, rt.color_code
             FROM bookings b
             LEFT JOIN guests g ON b.guest_id = g.id
             LEFT JOIN rooms r ON b.room_id = r.id
             LEFT JOIN room_types rt ON r.room_type_id = rt.id
             WHERE b.booking_code = ? LIMIT 1");
        $stmt->execute([$code]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT b.*, g.guest_name, g.phone, g.email,
                    r.room_number, rt.type_name, rt.color_code
             FROM bookings b
             LEFT JOIN guests g ON b.guest_id = g.id
             LEFT JOIN rooms r ON b.room_id = r.id
             LEFT JOIN room_types rt ON r.room_type_id = rt.id
             WHERE g.phone LIKE ?
             ORDER BY b.created_at DESC LIMIT 1");
        $stmt->execute(['%' . preg_replace('/\D/', '', $phone) . '%']);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$booking) {
        echo json_encode(['success' => false, 'error' => 'Booking tidak ditemukan']);
        exit;
    }

    // Status label
    $statusMap = [
        'confirmed'  => 'Dikonfirmasi',
        'checked_in' => 'Sedang Menginap',
        'checked_out'=> 'Sudah Check-out',
        'cancelled'  => 'Dibatalkan',
        'pending'    => 'Menunggu Konfirmasi',
    ];
    $payStatusMap = [
        'paid'    => 'Lunas',
        'partial' => 'Bayar Sebagian',
        'unpaid'  => 'Belum Bayar',
    ];

    $b = [
            'booking_code'   => $booking['booking_code'],
            'guest_name'     => $booking['guest_name'],
            'phone'          => $booking['phone'],
            'email'          => $booking['email'],
            'room_number'    => $booking['room_number'],
            'room_type'      => $booking['type_name'],
            'check_in'       => $booking['check_in_date'],
            'check_out'      => $booking['check_out_date'],
            'total_nights'   => (int)$booking['total_nights'],
            'adults'         => (int)($booking['adults'] ?? 1),
            'children'       => (int)($booking['children'] ?? 0),
            'final_price'    => (int)$booking['final_price'],
            'paid_amount'    => (int)($booking['paid_amount'] ?? 0),
            'remaining'      => (int)$booking['final_price'] - (int)($booking['paid_amount'] ?? 0),
            'status'         => $booking['status'],
            'status_label'   => $statusMap[$booking['status']] ?? $booking['status'],
            'payment_status' => $booking['payment_status'],
            'payment_label'  => $payStatusMap[$booking['payment_status']] ?? $booking['payment_status'],
            'special_request'=> $booking['special_request'] ?? '',
            'booking_source' => $booking['booking_source'],
            'created_at'     => $booking['created_at'],
        ];

    // Ringkasan teks untuk AI
    $textSummary = "Booking {$b['booking_code']}:\n"
        . "- Tamu: {$b['guest_name']} ({$b['phone']})\n"
        . "- Kamar: {$b['room_number']} ({$b['room_type']})\n"
        . "- Check-in: {$b['check_in']}, Check-out: {$b['check_out']} ({$b['total_nights']} malam)\n"
        . "- Status: {$b['status_label']}\n"
        . "- Total: Rp " . number_format($b['final_price'], 0, ',', '.') . ", Dibayar: Rp " . number_format($b['paid_amount'], 0, ',', '.') . ", Sisa: Rp " . number_format($b['remaining'], 0, ',', '.') . "\n"
        . "- Pembayaran: {$b['payment_label']}";

    echo json_encode([
        'success'      => true,
        'text_summary' => $textSummary,
        'booking'      => $b,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
