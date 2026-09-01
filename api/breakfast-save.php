<?php

/**
 * BREAKFAST SAVE API
 * - Drop FK on created_by (users table is in system DB, not business DB)
 * - 1 guest_name per day = max 1 order (duplicate prevention)
 * - Support multi-room per guest
 */
define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$action = $input['action'] ?? '';
$db = Database::getInstance();
$pdo = $db->getConnection();

// Drop FK constraint on created_by if it exists (users table is in system DB, not this business DB)
try {
    $fks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breakfast_orders' 
        AND COLUMN_NAME = 'created_by' AND REFERENCED_TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($fks as $fk) {
        $pdo->exec("ALTER TABLE breakfast_orders DROP FOREIGN KEY `$fk`");
    }
} catch (Exception $e) { /* ignore */
}

// User from session (already validated by requireLogin)
$validUserId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

/**
 * Compute over-quota Extra Breakfast charge for a manual (front desk) order.
 * Kids / fruit portion is ALWAYS free and never counted. Only main course and
 * beverages above the guest's allowance are charged.
 *
 * @param array $bookingIds  one or more booking ids whose quotas are summed
 * @param array $menuItems   selected items (each has menu_id, quantity, category)
 * @return array{charge:float, extra_main:int, extra_drink:int, max_main:int, max_drink:int}
 */
function bf_compute_extra($db, array $bookingIds, array $menuItems)
{
    $result = ['charge' => 0.0, 'extra_main' => 0, 'extra_drink' => 0, 'max_main' => 0, 'max_drink' => 0];
    $bookingIds = array_values(array_unique(array_filter(array_map('intval', $bookingIds), function ($v) {
        return $v > 0;
    })));
    if (count($bookingIds) === 0) return $result;

    $maxMain = 0;
    $maxDrink = 0;
    $extraMainPrice = 75000.0;
    $extraDrinkPrice = 20000.0;
    $childIds = [];
    $foundQuota = false;

    foreach ($bookingIds as $bid) {
        $q = $db->fetchOne(
            "SELECT max_main, max_drink, child_menu_ids, extra_main_price, extra_drink_price
             FROM breakfast_guest_quota WHERE booking_id = ? LIMIT 1",
            [$bid]
        );
        if (!$q) continue;
        $foundQuota = true;
        $maxMain += max(0, (int)$q['max_main']);
        $maxDrink += max(0, (int)$q['max_drink']);
        $emp = (float)($q['extra_main_price'] ?? 75000);
        if ($emp > 0) $extraMainPrice = $emp;
        $edp = (float)($q['extra_drink_price'] ?? 20000);
        if ((int)round($edp) === 75000) $edp = 20000.0;
        if ($edp > 0) $extraDrinkPrice = $edp;
        $cids = json_decode($q['child_menu_ids'] ?? '[]', true);
        if (is_array($cids)) {
            foreach ($cids as $cid) $childIds[(int)$cid] = true;
        }
    }
    if (!$foundQuota) return $result;

    $sumMain = 0;
    $sumDrink = 0;
    foreach ($menuItems as $mi) {
        $mid = (int)($mi['menu_id'] ?? 0);
        $qty = max(1, (int)($mi['quantity'] ?? 1));
        if ($mid > 0 && isset($childIds[$mid])) continue; // kids menu = free
        $cat = strtolower(trim((string)($mi['category'] ?? '')));
        if ($cat === 'drinks' || $cat === 'beverages') {
            $sumDrink += $qty;
        } else {
            $sumMain += $qty;
        }
    }

    $extraMain = max(0, $sumMain - $maxMain);
    $extraDrink = max(0, $sumDrink - $maxDrink);
    $charge = ($extraMain * $extraMainPrice) + ($extraDrink * $extraDrinkPrice);

    $result['charge'] = (float)$charge;
    $result['extra_main'] = $extraMain;
    $result['extra_drink'] = $extraDrink;
    $result['max_main'] = $maxMain;
    $result['max_drink'] = $maxDrink;
    return $result;
}

/**
 * Insert or update an "Extra Breakfast" line in booking_extras for a front desk order.
 * Dedups per order via the notes marker (order=<id>). Removes the row when charge is 0.
 */
function bf_save_extra($pdo, $db, $bookingId, $breakfastDate, array $extra, $orderId, $userId, array $rooms = [])
{
    $bookingId = (int)$bookingId;
    if ($bookingId <= 0) return;

    // Ensure table exists (front desk DBs may not have it yet).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS booking_extras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* ignore */
    }

    $marker = 'order=' . (int)$orderId;
    $existing = $db->fetchOne(
        "SELECT id FROM booking_extras WHERE booking_id = ? AND item_name = 'Extra Breakfast' AND notes LIKE ? LIMIT 1",
        [$bookingId, '%front desk ' . $marker . '%']
    );

    $charge = (float)$extra['charge'];
    if ($charge <= 0) {
        // No (longer any) over-quota charge -> remove stale line if present.
        if (!empty($existing['id'])) {
            $pdo->prepare("DELETE FROM booking_extras WHERE id = ?")->execute([(int)$existing['id']]);
        }
        return;
    }

    $label = [];
    if ($extra['extra_main'] > 0) $label[] = 'main x' . $extra['extra_main'];
    if ($extra['extra_drink'] > 0) $label[] = 'drink x' . $extra['extra_drink'];
    $roomTxt = count($rooms) ? ' room=' . implode(',', $rooms) : '';
    $notes = 'Auto extra breakfast (front desk ' . $marker . ') [' . implode(', ', $label) . ']' . $roomTxt . ' date=' . $breakfastDate;

    if (!empty($existing['id'])) {
        $pdo->prepare("UPDATE booking_extras SET quantity = 1, unit_price = ?, total_price = ?, notes = ? WHERE id = ?")
            ->execute([$charge, $charge, $notes, (int)$existing['id']]);
    } else {
        $pdo->prepare("INSERT INTO booking_extras (booking_id, item_name, quantity, unit_price, total_price, notes, created_by)
            VALUES (?, 'Extra Breakfast', 1, ?, ?, ?, ?)")
            ->execute([$bookingId, $charge, $charge, $notes, $userId]);
    }
}

try {
    // Parse & validate menu items
    $menuItemIds = $input['menu_items'] ?? [];
    $menuQty = $input['menu_qty'] ?? [];
    $menuNote = $input['menu_note'] ?? [];
    $customExtras = $input['custom_extras'] ?? [];

    if (empty($menuItemIds) && empty($customExtras)) {
        throw new Exception('Pilih minimal 1 menu item atau tambahkan extra manual');
    }
    if (!is_array($menuItemIds)) $menuItemIds = [];

    $menuItems = [];
    $totalPrice = 0;

    foreach ($menuItemIds as $menuId) {
        $menuId = (int)$menuId;
        $qty = max(1, (int)($menuQty[$menuId] ?? 1));
        $note = isset($menuNote[$menuId]) ? trim($menuNote[$menuId]) : '';

        $menu = $db->fetchOne("SELECT menu_name, price, is_free, category FROM breakfast_menus WHERE id = ?", [$menuId]);
        if ($menu) {
            $item = [
                'menu_id' => $menuId,
                'menu_name' => $menu['menu_name'],
                'quantity' => $qty,
                'price' => $menu['price'],
                'is_free' => $menu['is_free'],
                'category' => $menu['category'] ?? ''
            ];
            if ($note !== '') $item['note'] = $note;
            $menuItems[] = $item;
            if (!$menu['is_free']) $totalPrice += ($menu['price'] * $qty);
        }
    }

    // Process custom extras (manual items)
    if (is_array($customExtras)) {
        foreach ($customExtras as $ce) {
            $ceName = trim($ce['name'] ?? '');
            $cePrice = max(0, (float)($ce['price'] ?? 0));
            $ceQty = max(1, (int)($ce['quantity'] ?? 1));
            $ceNote = trim($ce['note'] ?? '');
            if ($ceName === '') continue;
            $item = [
                'menu_id' => 0,
                'menu_name' => $ceName,
                'quantity' => $ceQty,
                'price' => number_format($cePrice, 2, '.', ''),
                'is_free' => 0,
                'is_custom' => 1
            ];
            if ($ceNote !== '') $item['note'] = $ceNote;
            $menuItems[] = $item;
            $totalPrice += ($cePrice * $ceQty);
        }
    }

    if (count($menuItems) === 0) {
        throw new Exception('Tidak ada menu valid yang dipilih');
    }

    // Parse common fields
    $totalPax = max(1, (int)($input['total_pax'] ?? 1));
    $breakfastTime = $input['breakfast_time'] ?? '';
    // Hotel date: before 10 AM = previous day, so overnight picks remain visible in the morning.
    $defaultDate = (int)date('H') < 10 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
    $breakfastDate = $input['breakfast_date'] ?? $defaultDate;
    $location = $input['location'] ?? 'restaurant';
    $specialRequests = !empty($input['special_requests']) ? trim($input['special_requests']) : null;

    if (empty($breakfastTime)) throw new Exception('Waktu sarapan harus diisi');

    // Validate location
    $validLocations = ['restaurant', 'room_service', 'take_away'];
    if (!in_array($location, $validLocations)) $location = 'restaurant';

    $menuJson = json_encode($menuItems);

    if ($action === 'create_bulk') {
        // Multi-guest → 1 COMBINED order (all guests + all rooms in one record)
        $guests = $input['guests'] ?? [];
        if (empty($guests) || !is_array($guests)) throw new Exception('Pilih minimal 1 tamu');

        $guestNames = [];
        $allRooms = [];
        $firstBookingId = null;
        $allBookingIds = [];
        $skipped = [];

        foreach ($guests as $guest) {
            $gName = trim($guest['guest_name'] ?? '');
            if (empty($gName)) continue;

            $gRooms = $guest['room_number'] ?? [];
            if (!is_array($gRooms)) $gRooms = [$gRooms];
            $gRooms = array_values(array_filter(array_map('trim', $gRooms)));

            // Duplicate check by ROOM NUMBER (reliable for group bookings with same guest name)
            $already = false;
            foreach ($gRooms as $rm) {
                $ex = $db->fetchOne(
                    "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND room_number LIKE ?",
                    [$breakfastDate, '%"' . $rm . '"%']
                );
                if ($ex) {
                    $already = true;
                    break;
                }
            }
            // Fallback to name check only when guest has no room assigned
            if (!$already && count($gRooms) === 0) {
                $ex = $db->fetchOne(
                    "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND FIND_IN_SET(?, REPLACE(guest_name, ', ', ',')) > 0",
                    [$breakfastDate, $gName]
                );
                if ($ex) $already = true;
            }
            if ($already) {
                $skipped[] = $gName . (count($gRooms) ? ' (Room ' . implode(',', $gRooms) . ')' : '');
                continue;
            }

            $guestNames[] = $gName;
            if ($firstBookingId === null && !empty($guest['booking_id'])) {
                $firstBookingId = (int)$guest['booking_id'];
            }
            if (!empty($guest['booking_id'])) {
                $allBookingIds[] = (int)$guest['booking_id'];
            }
            foreach ($gRooms as $r) {
                if (!empty($r) && !in_array($r, $allRooms)) $allRooms[] = $r;
            }
        }

        if (count($guestNames) === 0 && count($skipped) > 0) {
            echo json_encode(['success' => false, 'message' => 'Semua tamu sudah punya order hari ini: ' . implode(', ', $skipped)]);
            exit;
        }
        if (count($guestNames) === 0) {
            throw new Exception('Tidak ada tamu valid yang dipilih');
        }

        $combinedName = implode(', ', $guestNames);
        $roomJson = json_encode($allRooms);

        $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
            (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, 
             location, menu_items, special_requests, total_price, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $firstBookingId,
            $combinedName,
            $roomJson,
            $totalPax,
            $breakfastTime,
            $breakfastDate,
            $location,
            $menuJson,
            $specialRequests,
            $totalPrice,
            $validUserId
        ]);

        $newId = $pdo->lastInsertId();
        $msg = "Order #{$newId} tersimpan untuk: " . $combinedName;
        if (count($skipped) > 0) $msg .= ' (dilewati: ' . implode(', ', $skipped) . ')';

        // Over-quota Extra Breakfast charge (kids free). Quota summed across all selected bookings.
        $extra = bf_compute_extra($db, $allBookingIds, $menuItems);
        bf_save_extra($pdo, $db, $firstBookingId, $breakfastDate, $extra, $newId, $validUserId, $allRooms);

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'id' => $newId,
            'extra_charge' => $extra['charge'],
            'extra_main' => $extra['extra_main'],
            'extra_drink' => $extra['extra_drink']
        ]);
    } elseif ($action === 'create_order') {
        // Single guest order
        $guestName = trim($input['guest_name'] ?? '');
        $bookingId = !empty($input['booking_id']) ? (int)$input['booking_id'] : null;
        $roomNumbers = $input['room_number'] ?? [];
        if (!is_array($roomNumbers)) $roomNumbers = [$roomNumbers];
        $roomJson = json_encode($roomNumbers);

        if (empty($guestName)) throw new Exception('Nama tamu harus diisi');

        // DUPLICATE PREVENTION by ROOM NUMBER (handles group bookings with identical names)
        $cleanRooms = array_values(array_filter(array_map('trim', $roomNumbers)));
        $existing = null;
        foreach ($cleanRooms as $rm) {
            $existing = $db->fetchOne(
                "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND room_number LIKE ?",
                [$breakfastDate, '%"' . $rm . '"%']
            );
            if ($existing) {
                echo json_encode([
                    'success' => false,
                    'message' => "Room {$rm} sudah punya order hari ini (ID #{$existing['id']}). Edit atau hapus dulu."
                ]);
                exit;
            }
        }
        // Fallback to name check only when no room assigned
        if (count($cleanRooms) === 0) {
            $existing = $db->fetchOne(
                "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND FIND_IN_SET(?, REPLACE(guest_name, ', ', ',')) > 0",
                [$breakfastDate, $guestName]
            );
            if ($existing) {
                echo json_encode([
                    'success' => false,
                    'message' => "{$guestName} sudah punya order hari ini (ID #{$existing['id']}). Edit atau hapus dulu."
                ]);
                exit;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO breakfast_orders 
            (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date, 
             location, menu_items, special_requests, total_price, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $bookingId,
            $guestName,
            $roomJson,
            $totalPax,
            $breakfastTime,
            $breakfastDate,
            $location,
            $menuJson,
            $specialRequests,
            $totalPrice,
            $validUserId
        ]);

        $newId = $pdo->lastInsertId();
        $extra = bf_compute_extra($db, [$bookingId], $menuItems);
        bf_save_extra($pdo, $db, $bookingId, $breakfastDate, $extra, $newId, $validUserId, $cleanRooms);
        echo json_encode([
            'success' => true,
            'message' => "Order #{$newId} untuk {$guestName} tersimpan!",
            'id' => $newId,
            'extra_charge' => $extra['charge'],
            'extra_main' => $extra['extra_main'],
            'extra_drink' => $extra['extra_drink']
        ]);
    } elseif ($action === 'update_order') {
        $editId = (int)($input['edit_id'] ?? 0);
        if ($editId <= 0) throw new Exception('ID order tidak valid');

        $guestName = trim($input['guest_name'] ?? '');
        $bookingId = !empty($input['booking_id']) ? (int)$input['booking_id'] : null;
        $roomNumbers = $input['room_number'] ?? [];
        if (!is_array($roomNumbers)) $roomNumbers = [$roomNumbers];
        $roomJson = json_encode($roomNumbers);

        $stmt = $pdo->prepare("UPDATE breakfast_orders SET 
            booking_id=?, guest_name=?, room_number=?, total_pax=?, breakfast_time=?, 
            breakfast_date=?, location=?, menu_items=?, special_requests=?, total_price=?
            WHERE id=?");
        $stmt->execute([
            $bookingId,
            $guestName,
            $roomJson,
            $totalPax,
            $breakfastTime,
            $breakfastDate,
            $location,
            $menuJson,
            $specialRequests,
            $totalPrice,
            $editId
        ]);

        $extra = bf_compute_extra($db, [$bookingId], $menuItems);
        $cleanRoomsUpd = array_values(array_filter(array_map('trim', $roomNumbers)));
        bf_save_extra($pdo, $db, $bookingId, $breakfastDate, $extra, $editId, $validUserId, $cleanRoomsUpd);

        echo json_encode([
            'success' => true,
            'message' => "Order #{$editId} berhasil diupdate!",
            'id' => $editId,
            'extra_charge' => $extra['charge'],
            'extra_main' => $extra['extra_main'],
            'extra_drink' => $extra['extra_drink']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Action tidak valid']);
    }
} catch (Exception $e) {
    error_log("Breakfast Save Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
