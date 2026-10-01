<?php
/**
 * API: Create Booking
 * Creates a new booking in the HOTEL database (adf_narayana_hotel)
 * So it appears in both the website AND ADF frontdesk system
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

// Flexible path: works on hosting (config inside webroot) and local dev (config outside public/)
$_cfg = dirname(__DIR__) . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__, 2) . '/config/config.php';
require_once $_cfg;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request data');
    }
    
    // Honeypot: a real visitor never sees or fills this field, bots usually fill everything
    if (!empty($input['website'])) {
        throw new Exception('Invalid submission');
    }

    // Validate required fields
    $required = ['room_id', 'check_in', 'check_out', 'guest_name', 'guest_email', 'guest_phone'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    $roomId = (int)$input['room_id'];
    $checkIn = $input['check_in'];
    $checkOut = $input['check_out'];
    $guestName = trim($input['guest_name']);
    $guestEmail = trim($input['guest_email']);
    $guestPhone = trim($input['guest_phone']);

    // Reject spam/bot-injected names (URLs, promo codes, excessive length/symbols)
    if (mb_strlen($guestName) < 2 || mb_strlen($guestName) > 80) {
        throw new Exception('Invalid name length');
    }
    if (preg_match('/https?:\/\/|www\.|\.(com|net|org|ru|xyz|top|info|online)\b|->>|\bhs=/i', $guestName)) {
        throw new Exception('Invalid name format');
    }
    if (!preg_match('/^[\p{L}\p{M}\x27\.\-\s]+$/u', $guestName)) {
        throw new Exception('Name contains invalid characters');
    }
    $guests = (int)($input['guests'] ?? 2);
    $idCardType = $input['id_card_type'] ?? 'ktp';
    $idCardNumber = trim($input['id_card_number'] ?? '');
    $nationality = trim($input['nationality'] ?? 'Indonesia');
    $specialRequest = trim($input['special_request'] ?? '');
    
    // Validate dates
    if (strtotime($checkIn) >= strtotime($checkOut)) {
        throw new Exception('Check-out must be after check-in');
    }
    if (strtotime($checkIn) < strtotime(date('Y-m-d'))) {
        throw new Exception('Check-in cannot be in the past');
    }
    
    // Validate email
    if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address');
    }
    
    // All queries use $pdo = adf_narayana_hotel (the hotel system DB)
    // This ensures bookings show in both website AND ADF frontdesk
    
    // Check room exists and get price
    $room = dbFetch("
        SELECT r.*, rt.type_name, rt.base_price, rt.max_occupancy
        FROM rooms r 
        JOIN room_types rt ON r.room_type_id = rt.id 
        WHERE r.id = ?
    ", [$roomId]);
    
    if (!$room) {
        throw new Exception('Room not found');
    }
    
    // Check guest count doesn't exceed room capacity
    if ($guests > $room['max_occupancy']) {
        throw new Exception('Number of guests exceeds room capacity (' . $room['max_occupancy'] . ' max)');
    }
    
    // Check room is still available for these dates
    $conflict = dbFetch("
        SELECT COUNT(*) as count FROM bookings 
        WHERE room_id = ? 
        AND status IN ('pending', 'confirmed', 'checked_in')
        AND check_in_date < ? AND check_out_date > ?
    ", [$roomId, $checkOut, $checkIn]);
    
    if ($conflict['count'] > 0) {
        throw new Exception('This room is no longer available for your selected dates. Please choose another room.');
    }
    
    // Calculate pricing
    $nights = (new DateTime($checkIn))->diff(new DateTime($checkOut))->days;
    $roomPrice = $room['base_price'];
    $totalPrice = $roomPrice * $nights;
    
    // Generate booking code
    $bookingCode = 'BK-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    
    // Write directly to hotel DB ($pdo) — NOT web DB
    $pdo->beginTransaction();
    
    try {
        // Create or find guest in hotel DB
        $stmt = $pdo->prepare("SELECT id FROM guests WHERE email = ? OR phone = ?");
        $stmt->execute([$guestEmail, $guestPhone]);
        $existingGuest = $stmt->fetch();
        
        if ($existingGuest) {
            $guestId = $existingGuest['id'];
            // Update guest info
            $stmt = $pdo->prepare("UPDATE guests SET guest_name = ?, phone = ?, email = ?, id_card_type = ?, id_card_number = ?, nationality = ? WHERE id = ?");
            $stmt->execute([$guestName, $guestPhone, $guestEmail, $idCardType, $idCardNumber, $nationality, $guestId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO guests (guest_name, phone, email, id_card_type, id_card_number, nationality) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$guestName, $guestPhone, $guestEmail, $idCardType, $idCardNumber, $nationality]);
            $guestId = $pdo->lastInsertId();
        }
        
        // Create booking in hotel DB
        $stmt = $pdo->prepare("
            INSERT INTO bookings (booking_code, guest_id, room_id, check_in_date, check_out_date, adults, children, room_price, total_nights, total_price, discount, final_price, status, payment_status, paid_amount, booking_source, special_request, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bookingCode, $guestId, $roomId, $checkIn, $checkOut,
            $guests, 0, $roomPrice, $nights, $totalPrice, 0, $totalPrice,
            'confirmed', 'unpaid', 0, 'online', $specialRequest, 'Booked via website'
        ]);
        $bookingId = $pdo->lastInsertId();
        
        $pdo->commit();
        
        // Notify owner/admin dashboard (bell + push) — best effort, must not break the booking response
        try {
            $db = Database::getInstance();
            $notifTitle = '🏨 Reservasi Baru';
            $notifMessage = "Tamu: $guestName | Kamar: {$room['room_number']} | Check-in: $checkIn | Kode: $bookingCode";

            $owners = $db->fetchAll("
                SELECT u.id FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE r.role_code IN ('owner', 'admin', 'developer') AND u.is_active = 1
            ") ?: [];
            foreach ($owners as $owner) {
                $db->insert('notifications', [
                    'user_id' => $owner['id'],
                    'type' => 'new_booking',
                    'title' => $notifTitle,
                    'message' => $notifMessage,
                    'data' => json_encode(['booking_id' => $bookingId, 'booking_code' => $bookingCode]),
                    'is_read' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            require_once dirname(__DIR__, 2) . '/includes/PushNotificationHelper.php';
            $pushHelper = new PushNotificationHelper($db);
            $pushSentAt = date('Y-m-d H:i:s');
            $pushResult = $pushHelper->sendToAdmins($notifTitle, $notifMessage, [
                'type' => 'new_booking',
                'tag'  => 'new_booking-' . $bookingId,
                'url'  => '/index.php',
            ]);
            // Temporary diagnostic: confirm the server actually dispatched the push at this timestamp,
            // so a delay can be isolated to the device/OS/push-service side vs. our own code.
            error_log('website create-booking push dispatched at ' . $pushSentAt . ' result=' . json_encode($pushResult));
        } catch (\Throwable $notifyErr) {
            error_log('website create-booking notify owner failed: ' . $notifyErr->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'booking_id' => $bookingId,
                'booking_code' => $bookingCode,
                'room_type' => trim($room['type_name']),
                'room_number' => $room['room_number'],
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nights' => $nights,
                'total_price' => $totalPrice,
                'guest_name' => $guestName,
            ],
            'message' => 'Reservation confirmed successfully!'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
