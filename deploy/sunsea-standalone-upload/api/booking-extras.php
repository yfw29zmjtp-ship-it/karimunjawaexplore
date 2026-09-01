<?php

/**
 * API: Booking Extras (Extra Bed, Laundry, dll)
 * CRUD for additional charges per booking
 * 
 * GET    ?booking_id=X        → list extras
 * POST   action=add           → add extra item
 * POST   action=delete        → delete extra item
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

ob_clean();
header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!$auth->hasPermission('frontdesk')) {
    echo json_encode(['success' => false, 'message' => 'No permission']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$currentUser = $auth->getCurrentUser();

// Auto-create table if not exists
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS booking_extras (
            id INT PRIMARY KEY AUTO_INCREMENT,
            booking_id INT NOT NULL,
            item_name VARCHAR(200) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // table might already exist, ignore
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // ========== GET: List extras for a booking ==========
    if ($method === 'GET') {
        $bookingId = intval($_GET['booking_id'] ?? 0);
        if (!$bookingId) {
            throw new Exception('Booking ID required');
        }

        $stmt = $conn->prepare("
            SELECT id, item_name, quantity, unit_price, total_price, notes, created_at
            FROM booking_extras
            WHERE booking_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$bookingId]);
        $extras = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalExtras = 0;
        foreach ($extras as $e) {
            $totalExtras += (float)$e['total_price'];
        }

        echo json_encode([
            'success' => true,
            'extras' => $extras,
            'total_extras' => $totalExtras
        ]);
        exit;
    }

    // ========== POST: Add or Delete ==========
    if ($method === 'POST') {
        $action = $_POST['action'] ?? '';

        // ---------- ADD ----------
        if ($action === 'add') {
            $bookingId = intval($_POST['booking_id'] ?? 0);
            $itemName = trim($_POST['item_name'] ?? '');
            $quantity = max(1, intval($_POST['quantity'] ?? 1));
            $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));

            if (!$bookingId) throw new Exception('Booking ID required');
            if (!$itemName) throw new Exception('Nama item harus diisi');
            if ($unitPrice <= 0) throw new Exception('Harga harus lebih dari 0');

            // Verify booking exists
            $stmt = $conn->prepare("SELECT id, status FROM bookings WHERE id = ?");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$booking) throw new Exception('Booking tidak ditemukan');

            $totalPrice = $quantity * $unitPrice;

            $stmt = $conn->prepare("
                INSERT INTO booking_extras (booking_id, item_name, quantity, unit_price, total_price, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $bookingId,
                $itemName,
                $quantity,
                $unitPrice,
                $totalPrice,
                trim($_POST['notes'] ?? ''),
                $currentUser['id']
            ]);

            $extraId = $conn->lastInsertId();

            // Recalculate booking final_price with extras
            recalcFinalPrice($conn, $bookingId);

            echo json_encode([
                'success' => true,
                'message' => 'Extra item ditambahkan: ' . $itemName,
                'extra' => [
                    'id' => $extraId,
                    'item_name' => $itemName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice
                ]
            ]);
            exit;
        }

        // ---------- DELETE ----------
        if ($action === 'delete') {
            $extraId = intval($_POST['extra_id'] ?? 0);
            if (!$extraId) throw new Exception('Extra ID required');

            // Get booking_id before deleting
            $stmt = $conn->prepare("SELECT booking_id FROM booking_extras WHERE id = ?");
            $stmt->execute([$extraId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Extra item tidak ditemukan');

            $bookingId = $row['booking_id'];

            $stmt = $conn->prepare("DELETE FROM booking_extras WHERE id = ?");
            $stmt->execute([$extraId]);

            // Recalculate booking final_price
            recalcFinalPrice($conn, $bookingId);

            echo json_encode([
                'success' => true,
                'message' => 'Extra item dihapus'
            ]);
            exit;
        }

        throw new Exception('Invalid action: ' . $action);
    }

    throw new Exception('Method not allowed');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Recalculate booking final_price including extras
 * final_price = (room_price × nights - discount - ota_fee) + SUM(extras)
 */
function recalcFinalPrice($conn, $bookingId)
{
    // Get current booking data
    $stmt = $conn->prepare("SELECT room_price, total_nights, total_price, discount, booking_source FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) return;

    $totalPrice = (float)$booking['total_price'];  // room_price × nights
    $discount = (float)$booking['discount'];
    $afterDiscount = $totalPrice - $discount;

    // OTA fee
    $otaFeePercent = 0;
    try {
        $feeStmt = $conn->prepare("SELECT fee_percent FROM booking_sources WHERE source_key = ? AND is_active = 1 LIMIT 1");
        $feeStmt->execute([$booking['booking_source']]);
        $feeRow = $feeStmt->fetch(PDO::FETCH_ASSOC);
        if ($feeRow) $otaFeePercent = (float)$feeRow['fee_percent'];
    } catch (Exception $e) {
    }

    $otaFee = $otaFeePercent > 0 ? round($afterDiscount * $otaFeePercent / 100) : 0;
    $roomFinal = $afterDiscount - $otaFee;

    // Sum extras
    $extStmt = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) as total FROM booking_extras WHERE booking_id = ?");
    $extStmt->execute([$bookingId]);
    $extrasTotal = (float)$extStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // New final price = room net + extras
    $finalPrice = $roomFinal + $extrasTotal;

    // Update booking
    $updStmt = $conn->prepare("UPDATE bookings SET final_price = ?, updated_at = NOW() WHERE id = ?");
    $updStmt->execute([$finalPrice, $bookingId]);

    // Recalc payment status
    $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM booking_payments WHERE booking_id = ?");
    $paidStmt->execute([$bookingId]);
    $paidTotal = (float)$paidStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $remaining = $finalPrice - $paidTotal;
    if ($paidTotal <= 0) {
        $paymentStatus = 'unpaid';
    } elseif ($remaining <= 1000) {
        $paymentStatus = 'paid';
    } else {
        $paymentStatus = 'partial';
    }

    $conn->prepare("UPDATE bookings SET payment_status = ?, paid_amount = ? WHERE id = ?")->execute([$paymentStatus, $paidTotal, $bookingId]);
}

ob_end_flush();
