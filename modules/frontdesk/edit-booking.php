<?php

/**
 * EDIT BOOKING PAGE
 * Full edit form matching reservation form
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$bookingId = $_GET['id'] ?? null;

if (!$bookingId) {
    header('Location: reservasi.php');
    exit;
}

// Get booking details with guest info
$stmt = $pdo->prepare("
    SELECT b.*, g.guest_name, g.phone as guest_phone, g.email as guest_email, 
           COALESCE(g.id_card_number,'') as guest_id_number,
           r.room_number, rt.type_name, rt.base_price
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    JOIN rooms r ON b.room_id = r.id
    JOIN room_types rt ON r.room_type_id = rt.id
    WHERE b.id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    header('Location: reservasi.php');
    exit;
}

// Detect group booking - fetch all rooms in the group
$groupBookings = [];
$isGroup = false;
$groupId = $booking['group_id'] ?? null;
if ($groupId) {
    $gStmt = $pdo->prepare("
        SELECT b.*, r.room_number, rt.type_name, rt.base_price
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.group_id = ? AND b.status != 'cancelled'
        ORDER BY r.room_number ASC
    ");
    $gStmt->execute([$groupId]);
    $groupBookings = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    $isGroup = count($groupBookings) > 1;
}
if (!$isGroup) {
    $groupBookings = [$booking];
}

// Get combined paid for group
$allBookingIds = array_column($groupBookings, 'id');
$placeholders = implode(',', array_fill(0, count($allBookingIds), '?'));
$paidRow = $db->fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM booking_payments WHERE booking_id IN ($placeholders)", $allBookingIds);
$totalPaid = (float)($paidRow['total'] ?? 0);

// Combined final price for group
$combinedFinalPrice = 0;
foreach ($groupBookings as $gb) {
    $combinedFinalPrice += (float)$gb['final_price'];
}

// Get available rooms: exclude rooms with overlapping bookings (except current booking's rooms)
$currentRoomIds = array_map('intval', array_column($groupBookings, 'room_id'));

// Get IDs of rooms booked during this period (excluding current booking group)
$bookedRoomIds = [];
try {
    $brSql = "SELECT DISTINCT room_id FROM bookings WHERE status NOT IN ('cancelled','checked_out') AND id NOT IN ($placeholders) AND check_in_date < ? AND check_out_date > ?";
    $brStmt = $pdo->prepare($brSql);
    $brStmt->execute(array_merge($allBookingIds, [$booking['check_out_date'], $booking['check_in_date']]));
    $bookedRoomIds = array_map('intval', $brStmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {
    error_log('Room filter error: ' . $e->getMessage());
}

// Fetch all non-maintenance rooms
$allRooms = $db->fetchAll("
    SELECT r.id, r.room_number, rt.type_name, rt.base_price
    FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id
    WHERE r.status != 'maintenance'
    ORDER BY rt.type_name, r.room_number
");

// Filter: only available + currently assigned rooms
$rooms = [];
foreach ($allRooms as $room) {
    $rid = intval($room['id']);
    if (in_array($rid, $currentRoomIds) || !in_array($rid, $bookedRoomIds)) {
        $rooms[] = $room;
    }
}

// Group rooms by type for optgroup display
$roomsByType = [];
foreach ($rooms as $room) {
    $roomsByType[$room['type_name']][] = $room;
}

// Get booking sources from DB
$bookingSources = $db->fetchAll("SELECT source_key, source_name, source_type, fee_percent, icon FROM booking_sources WHERE is_active = 1 ORDER BY sort_order ASC");
if (!$bookingSources) {
    $bookingSources = [
        ['source_key' => 'walk_in', 'source_name' => 'Walk-in', 'source_type' => 'direct', 'fee_percent' => 0, 'icon' => '🚶'],
        ['source_key' => 'phone', 'source_name' => 'Phone', 'source_type' => 'direct', 'fee_percent' => 0, 'icon' => '📞'],
        ['source_key' => 'online', 'source_name' => 'Online', 'source_type' => 'direct', 'fee_percent' => 0, 'icon' => '🌐'],
        ['source_key' => 'agoda', 'source_name' => 'Agoda', 'source_type' => 'ota', 'fee_percent' => 15, 'icon' => '🅰️'],
        ['source_key' => 'booking', 'source_name' => 'Booking.com', 'source_type' => 'ota', 'fee_percent' => 12, 'icon' => '🅱️'],
        ['source_key' => 'tiket', 'source_name' => 'Tiket.com', 'source_type' => 'ota', 'fee_percent' => 10, 'icon' => '🎫'],
        ['source_key' => 'traveloka', 'source_name' => 'Traveloka', 'source_type' => 'ota', 'fee_percent' => 15, 'icon' => '✈️'],
        ['source_key' => 'airbnb', 'source_name' => 'Airbnb', 'source_type' => 'ota', 'fee_percent' => 3, 'icon' => '🏡'],
        ['source_key' => 'ota', 'source_name' => 'OTA Lainnya', 'source_type' => 'ota', 'fee_percent' => 10, 'icon' => '🌐'],
    ];
}

// Build OTA fees map for JS
$otaFees = [];
foreach ($bookingSources as $src) {
    $otaFees[$src['source_key']] = (float)$src['fee_percent'];
}

// Get total paid - already calculated above for group

// Get existing extras for ALL bookings in group
$bookingExtras = [];
$totalExtras = 0;
try {
    $bookingExtras = $db->fetchAll("SELECT id, booking_id, item_name, quantity, unit_price, total_price, notes FROM booking_extras WHERE booking_id IN ($placeholders) ORDER BY created_at ASC", $allBookingIds);
    foreach ($bookingExtras as $ex) {
        $totalExtras += (float)$ex['total_price'];
    }
} catch (Exception $e) { /* table might not exist yet */
}

$pageTitle = 'Edit Booking';
include '../../includes/header.php';
?>

<style>
    .edit-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 1rem;
    }

    .edit-card {
        background: var(--bg-primary, #fff);
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        overflow: hidden;
    }

    .edit-card-header {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        padding: 0.75rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .edit-card-header h2 {
        margin: 0;
        font-size: 1.15rem;
    }

    .edit-card-header .booking-code {
        opacity: 0.9;
        font-size: 0.85rem;
        font-family: 'Courier New', monospace;
    }

    .edit-card-body {
        padding: 1.25rem;
    }

    .edit-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        align-items: start;
    }

    .edit-col {
        min-width: 0;
    }

    .section-title {
        font-size: 0.85rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6366f1;
        margin: 0 0 0.75rem 0;
        padding-bottom: 0.4rem;
        border-bottom: 2px solid rgba(99, 102, 241, 0.15);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .form-group {
        margin-bottom: 0.75rem;
    }

    .form-group label {
        display: block;
        font-size: 0.78rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
        color: var(--text-secondary, #475569);
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 0.5rem 0.65rem;
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 6px;
        font-size: 0.85rem;
        background: var(--bg-secondary, #f8fafc);
        color: var(--text-primary, #1e293b);
        box-sizing: border-box;
        transition: border-color 0.2s;
    }

    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .form-group textarea {
        min-height: 55px;
        resize: vertical;
    }

    .price-box {
        background: rgba(99, 102, 241, 0.04);
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 8px;
        padding: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .price-line {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.3rem 0;
        font-size: 0.85rem;
    }

    .price-line-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-top: 2px solid rgba(99, 102, 241, 0.2);
        margin-top: 0.4rem;
        font-weight: 700;
        font-size: 1.05rem;
    }

    .ota-fee-row {
        background: #fef3c7;
        padding: 0.4rem 0.65rem;
        border-radius: 6px;
        margin: 0.4rem 0;
    }

    .status-badge {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 4px;
        font-size: 0.72rem;
        font-weight: 700;
        color: white;
    }

    .status-pending { background: #f59e0b; }
    .status-confirmed { background: #6366f1; }
    .status-checked_in { background: #10b981; }
    .status-checked_out { background: #6b7280; }

    .discount-row {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .disc-type-btn {
        padding: 4px 10px;
        font-size: 0.75rem;
        border: 1px solid #6366f1;
        cursor: pointer;
        transition: all 0.2s;
    }

    .disc-type-btn.active { background: #6366f1; color: white; }
    .disc-type-btn:not(.active) { background: white; color: #6366f1; }

    .btn-row {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
    }

    .btn-save {
        flex: 1;
        padding: 0.7rem;
        background: #10b981;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-save:hover { background: #059669; transform: translateY(-1px); }

    .btn-cancel {
        flex: 1;
        padding: 0.7rem;
        background: var(--bg-secondary, #f3f4f6);
        color: var(--text-secondary, #6b7280);
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        text-decoration: none;
        text-align: center;
    }

    .btn-cancel:hover { background: #e5e7eb; }

    .alert {
        padding: 0.6rem 0.85rem;
        border-radius: 6px;
        margin-bottom: 0.75rem;
        font-size: 0.85rem;
    }

    .alert-success { background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid #d1fae5; }
    .alert-error { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid #fee2e2; }

    .paid-info {
        background: rgba(16, 185, 129, 0.06);
        border: 1px solid rgba(16, 185, 129, 0.2);
        border-radius: 6px;
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
        color: #059669;
        margin-bottom: 0.75rem;
    }

    /* EXTRAS */
    .extras-section { margin-bottom: 0.75rem; }

    .extras-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }

    .extras-header h3 { margin: 0; font-size: 0.85rem; font-weight: 700; color: var(--text-primary, #1e293b); }

    .btn-add-extra {
        padding: 0.3rem 0.6rem;
        background: #6366f1;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 0.75rem;
        cursor: pointer;
        font-weight: 600;
    }

    .btn-add-extra:hover { background: #4f46e5; }

    .btn-add-room {
        display: block;
        width: 100%;
        padding: 0.5rem;
        margin: 0.5rem 0 0.75rem;
        background: #f0fdf4;
        color: #16a34a;
        border: 2px dashed #86efac;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-add-room:hover { background: #dcfce7; border-color: #16a34a; }

    .new-room-badge {
        display: inline-block;
        background: #fbbf24;
        color: #78350f;
        padding: 1px 8px;
        border-radius: 4px;
        font-size: 0.6rem;
        font-weight: 700;
        letter-spacing: 0.5px;
    }

    .extras-list { display: flex; flex-direction: column; gap: 0.4rem; }

    .extra-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: rgba(99, 102, 241, 0.04);
        border: 1px solid rgba(99, 102, 241, 0.12);
        border-radius: 6px;
        padding: 0.4rem 0.65rem;
        font-size: 0.8rem;
    }

    .extra-item-info { flex: 1; }
    .extra-item-name { font-weight: 600; color: var(--text-primary, #1e293b); }
    .extra-item-detail { font-size: 0.72rem; color: var(--text-secondary, #64748b); margin-top: 0.1rem; }
    .extra-item-price { font-weight: 700; color: #6366f1; margin: 0 0.5rem; white-space: nowrap; }

    .extra-item-delete {
        background: none;
        border: none;
        color: #ef4444;
        cursor: pointer;
        font-size: 1rem;
        padding: 0.15rem;
        line-height: 1;
    }
    .extra-item-delete:hover { color: #dc2626; }

    .extras-empty { padding: 0.6rem; text-align: center; color: var(--text-secondary, #64748b); font-size: 0.8rem; font-style: italic; }

    .extras-total {
        display: flex;
        justify-content: space-between;
        padding: 0.4rem 0.65rem;
        background: rgba(99, 102, 241, 0.08);
        border-radius: 6px;
        font-weight: 700;
        font-size: 0.85rem;
        margin-top: 0.4rem;
    }

    .add-extra-form {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        padding: 0.65rem;
        margin-top: 0.4rem;
        display: none;
    }
    .add-extra-form.active { display: block; }

    .add-extra-row {
        display: grid;
        grid-template-columns: 2fr 1fr 1.5fr;
        gap: 0.4rem;
        margin-bottom: 0.4rem;
    }

    .add-extra-row input,
    .add-extra-row select {
        padding: 0.4rem 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        font-size: 0.8rem;
    }

    .add-extra-actions { display: flex; gap: 0.4rem; justify-content: flex-end; }
    .add-extra-actions button { padding: 0.3rem 0.65rem; border: none; border-radius: 4px; font-size: 0.78rem; cursor: pointer; font-weight: 600; }
    .btn-confirm-extra { background: #10b981; color: white; }
    .btn-cancel-extra { background: #e5e7eb; color: #6b7280; }

    .preset-btns { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-bottom: 0.4rem; }
    .preset-btn { padding: 0.2rem 0.45rem; background: white; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.72rem; cursor: pointer; }
    .preset-btn:hover { background: #6366f1; color: white; border-color: #6366f1; }

    /* GROUP ROOMS */
    .group-badge {
        display: inline-block;
        background: rgba(255, 255, 255, 0.25);
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 700;
        margin-left: 0.5rem;
    }

    .room-cards { display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 0.5rem; }

    .room-card {
        background: rgba(99, 102, 241, 0.03);
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 8px;
        padding: 0.65rem;
    }

    .room-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.4rem;
        padding-bottom: 0.35rem;
        border-bottom: 1px solid rgba(99, 102, 241, 0.1);
        gap: 0.5rem;
    }

    .room-card-title { font-weight: 700; font-size: 0.85rem; color: #6366f1; flex: 1; }
    .room-card-status { font-size: 0.65rem; }
    .room-card .form-row { margin-bottom: 0.4rem; }
    .room-card .form-group { margin-bottom: 0.4rem; }
    .room-card .form-group label { font-size: 0.75rem; }
    .room-card .price-box { margin-bottom: 0; }

    .btn-remove-room {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fca5a5;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.72rem;
        font-weight: 600;
        white-space: nowrap;
        transition: all 0.2s;
    }
    .btn-remove-room:hover { background: #dc2626; color: white; }

    @media (max-width: 900px) {
        .edit-layout { grid-template-columns: 1fr; }
    }
</style>

<div class="edit-page">
    <div class="edit-card">
        <div class="edit-card-header">
            <div>
                <h2>✏️ Edit Reservasi<?php if ($isGroup): ?><span class="group-badge">📦 <?php echo count($groupBookings); ?> Rooms</span><?php endif; ?></h2>
            </div>
            <div class="booking-code"><?php echo htmlspecialchars($booking['booking_code']); ?><?php if ($isGroup): ?> (Group)<?php endif; ?> ·
                <span class="status-badge status-<?php echo $booking['status']; ?>"><?php echo strtoupper(str_replace('_', ' ', $booking['status'])); ?></span>
            </div>
        </div>

        <div class="edit-card-body">
            <div id="alertBox"></div>

            <?php if ($totalPaid > 0): ?>
                <div class="paid-info">
                    💰 Sudah Dibayar: <strong>Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></strong>
                    <?php if ($totalPaid >= $combinedFinalPrice): ?>
                        <span style="margin-left:0.5rem;background:#10b981;color:white;padding:2px 8px;border-radius:4px;font-size:0.7rem;">LUNAS</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form id="editForm" onsubmit="return saveEdit(event)">
                <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
                <?php if ($isGroup): ?>
                    <input type="hidden" name="group_id" value="<?php echo htmlspecialchars($groupId); ?>">
                <?php endif; ?>

                <div class="edit-layout">
                    <!-- ========== LEFT COLUMN: Guest + Dates + Rooms ========== -->
                    <div class="edit-col">
                        <h3 class="section-title">👤 Info Tamu & Tanggal</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nama Tamu *</label>
                                <input type="text" name="guest_name" id="guestName" value="<?php echo htmlspecialchars($booking['guest_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Telepon</label>
                                <input type="text" name="guest_phone" id="guestPhone" value="<?php echo htmlspecialchars($booking['guest_phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="guest_email" value="<?php echo htmlspecialchars($booking['guest_email'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>No. KTP/Paspor</label>
                                <input type="text" name="guest_id_number" value="<?php echo htmlspecialchars($booking['guest_id_number'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Check-in *</label>
                                <input type="date" name="check_in_date" id="checkIn" value="<?php echo $booking['check_in_date']; ?>" required onchange="recalculate()">
                            </div>
                            <div class="form-group">
                                <label>Check-out *</label>
                                <input type="date" name="check_out_date" id="checkOut" value="<?php echo $booking['check_out_date']; ?>" required onchange="recalculate()">
                            </div>
                        </div>

                        <h3 class="section-title">🏠 Kamar</h3>

                        <!-- ROOM(S) -->
                        <?php if ($isGroup): ?>
                            <div class="room-cards">
                                <?php foreach ($groupBookings as $idx => $gb): ?>
                                    <div class="room-card" data-room-idx="<?php echo $idx; ?>" data-booking-id="<?php echo $gb['id']; ?>">
                                        <input type="hidden" name="rooms[<?php echo $idx; ?>][booking_id]" value="<?php echo $gb['id']; ?>">
                                        <div class="room-card-header">
                                            <span class="room-card-title">🏠 Room <?php echo htmlspecialchars($gb['room_number']); ?> — <?php echo htmlspecialchars($gb['type_name']); ?></span>
                                            <span class="room-card-status status-badge status-<?php echo $gb['status']; ?>"><?php echo strtoupper(str_replace('_', ' ', $gb['status'])); ?></span>
                                            <?php if (count($groupBookings) > 1 && $gb['status'] !== 'checked_in'): ?>
                                                <button type="button" class="btn-remove-room" onclick="removeExistingRoom(<?php echo $gb['id']; ?>, '<?php echo htmlspecialchars($gb['room_number']); ?>', this)">✕ Hapus</button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label>Kamar</label>
                                                <select name="rooms[<?php echo $idx; ?>][room_id]" class="grp-room-select" data-idx="<?php echo $idx; ?>" onchange="onRoomChange(this); recalculate()">
                                                    <?php foreach ($roomsByType as $typeName => $typeRooms): ?>
                                                        <optgroup label="<?php echo $typeName; ?> (Rp <?php echo number_format($typeRooms[0]['base_price'], 0, ',', '.'); ?>)">
                                                        <?php foreach ($typeRooms as $room): 
                                                            $isCurrent = (intval($room['id']) == intval($gb['room_id']));
                                                        ?>
                                                            <option value="<?php echo $room['id']; ?>" data-price="<?php echo $room['base_price']; ?>" <?php echo $isCurrent ? 'selected' : ''; ?>>
                                                                <?php echo $room['room_number']; ?><?php echo $isCurrent ? ' ✓' : ''; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                        </optgroup>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>Harga/Malam (Rp)</label>
                                                <input type="number" name="rooms[<?php echo $idx; ?>][room_price]" class="grp-room-price" data-idx="<?php echo $idx; ?>" value="<?php echo $gb['room_price']; ?>" step="1000" onchange="recalculate()">
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label>Diskon (Rp)</label>
                                            <input type="number" name="rooms[<?php echo $idx; ?>][discount]" class="grp-discount" data-idx="<?php echo $idx; ?>" value="<?php echo $gb['discount']; ?>" min="0" onchange="recalculate()">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn-add-room" onclick="addNewRoomCard()">➕ Tambah Room</button>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Booking Source</label>
                                    <select name="booking_source" id="bookingSource" onchange="recalculate()">
                                        <?php
                                        $directSources = array_filter($bookingSources, fn($s) => $s['source_type'] === 'direct');
                                        $otaSrc = array_filter($bookingSources, fn($s) => $s['source_type'] !== 'direct');
                                        ?>
                                        <optgroup label="Direct">
                                            <?php foreach ($directSources as $src): ?>
                                                <option value="<?php echo $src['source_key']; ?>"
                                                    <?php echo $src['source_key'] === $booking['booking_source'] ? 'selected' : ''; ?>>
                                                    <?php echo $src['icon'] . ' ' . $src['source_name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <optgroup label="OTA">
                                            <?php foreach ($otaSrc as $src): ?>
                                                <option value="<?php echo $src['source_key']; ?>"
                                                    <?php echo $src['source_key'] === $booking['booking_source'] ? 'selected' : ''; ?>>
                                                    <?php echo $src['icon'] . ' ' . $src['source_name'] . ' (fee ' . $src['fee_percent'] . '%)'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Jumlah Tamu</label>
                                    <input type="number" name="num_guests" min="1" max="30" value="<?php echo $booking['adults'] ?? 1; ?>">
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Kamar</label>
                                    <select name="room_id" id="roomSelect" onchange="onRoomChange(this); recalculate()">
                                        <?php foreach ($roomsByType as $typeName => $typeRooms): ?>
                                            <optgroup label="<?php echo $typeName; ?> (Rp <?php echo number_format($typeRooms[0]['base_price'], 0, ',', '.'); ?>)">
                                            <?php foreach ($typeRooms as $room): 
                                                $isCurrent = (intval($room['id']) == intval($booking['room_id']));
                                            ?>
                                                <option value="<?php echo $room['id']; ?>" data-price="<?php echo $room['base_price']; ?>" <?php echo $isCurrent ? 'selected' : ''; ?>>
                                                    <?php echo $room['room_number']; ?><?php echo $isCurrent ? ' ✓' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Jumlah Tamu</label>
                                    <input type="number" name="num_guests" min="1" max="10" value="<?php echo $booking['adults'] ?? 1; ?>">
                                </div>
                            </div>

                            <button type="button" class="btn-add-room" onclick="convertToGroupAndAddRoom()">➕ Tambah Room</button>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Booking Source</label>
                                    <select name="booking_source" id="bookingSource" onchange="recalculate()">
                                        <?php
                                        $directSources = array_filter($bookingSources, fn($s) => $s['source_type'] === 'direct');
                                        $otaSrc = array_filter($bookingSources, fn($s) => $s['source_type'] !== 'direct');
                                        ?>
                                        <optgroup label="Direct">
                                            <?php foreach ($directSources as $src): ?>
                                                <option value="<?php echo $src['source_key']; ?>"
                                                    <?php echo $src['source_key'] === $booking['booking_source'] ? 'selected' : ''; ?>>
                                                    <?php echo $src['icon'] . ' ' . $src['source_name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <optgroup label="OTA">
                                            <?php foreach ($otaSrc as $src): ?>
                                                <option value="<?php echo $src['source_key']; ?>"
                                                    <?php echo $src['source_key'] === $booking['booking_source'] ? 'selected' : ''; ?>>
                                                    <?php echo $src['icon'] . ' ' . $src['source_name'] . ' (fee ' . $src['fee_percent'] . '%)'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Harga/Malam (Rp)</label>
                                    <input type="number" name="room_price" id="roomPrice" value="<?php echo $booking['room_price']; ?>" step="1000" onchange="recalculate()">
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ========== RIGHT COLUMN: Discount + Extras + Price + Actions ========== -->
                    <div class="edit-col">
                        <h3 class="section-title">💰 Harga & Diskon</h3>

                        <!-- DISCOUNT -->
                        <div class="form-group">
                            <label>Diskon<?php echo $isGroup ? ' (Total)' : ''; ?></label>
                            <div class="discount-row">
                                <button type="button" class="disc-type-btn active" data-type="rp" onclick="setDiscType('rp')" style="border-radius:4px 0 0 4px;">Rp</button>
                                <button type="button" class="disc-type-btn" data-type="percent" onclick="setDiscType('percent')" style="border-radius:0 4px 4px 0;">%</button>
                                <input type="number" name="discount_value" id="discountValue" value="<?php echo $isGroup ? 0 : $booking['discount']; ?>" min="0" style="flex:1;" onchange="recalculate()">
                                <input type="hidden" name="discount_type" id="discountType" value="rp">
                            </div>
                        </div>

                        <!-- SPECIAL REQUEST -->
                        <div class="form-group">
                            <label>Catatan / Permintaan Khusus</label>
                            <textarea name="special_requests"><?php echo htmlspecialchars($booking['special_request'] ?? ''); ?></textarea>
                        </div>

                        <!-- EXTRAS -->
                        <h3 class="section-title">🛏️ Tambahan / Extras</h3>
                        <div class="extras-section">
                            <div class="extras-header">
                                <h3>Item Extras</h3>
                                <button type="button" class="btn-add-extra" onclick="toggleAddExtraForm()">+ Tambah</button>
                            </div>

                            <div id="addExtraForm" class="add-extra-form">
                                <div class="preset-btns">
                                    <button type="button" class="preset-btn" onclick="fillPreset('Extra Bed', 350000)">🛏️ Extra Bed</button>
                                    <button type="button" class="preset-btn" onclick="fillPreset('Laundry', 25000)">👔 Laundry</button>
                                    <button type="button" class="preset-btn" onclick="fillPreset('Breakfast', 35000)">🍳 Breakfast</button>
                                    <button type="button" class="preset-btn" onclick="fillPreset('Mini Bar', 15000)">🍺 Mini Bar</button>
                                    <button type="button" class="preset-btn" onclick="fillPreset('Transport', 50000)">🚗 Transport</button>
                                    <button type="button" class="preset-btn" onclick="fillPreset('Towel Extra', 10000)">🧴 Towel</button>
                                </div>
                                <div class="add-extra-row">
                                    <input type="text" id="extraItemName" placeholder="Nama item (eg. Extra Bed)">
                                    <input type="number" id="extraQty" placeholder="Qty" value="1" min="1">
                                    <input type="number" id="extraUnitPrice" placeholder="Harga satuan" min="0" step="1000">
                                </div>
                                <div class="add-extra-actions">
                                    <button type="button" class="btn-cancel-extra" onclick="toggleAddExtraForm()">Batal</button>
                                    <button type="button" class="btn-confirm-extra" onclick="addExtra()">✅ Simpan</button>
                                </div>
                            </div>

                            <div id="extrasList" class="extras-list">
                                <?php if (empty($bookingExtras)): ?>
                                    <div class="extras-empty" id="extrasEmpty">Belum ada tambahan</div>
                                <?php else: ?>
                                    <?php foreach ($bookingExtras as $ex): ?>
                                        <div class="extra-item" data-extra-id="<?php echo $ex['id']; ?>">
                                            <div class="extra-item-info">
                                                <div class="extra-item-name"><?php echo htmlspecialchars($ex['item_name']); ?></div>
                                                <div class="extra-item-detail"><?php echo $ex['quantity']; ?>x @ Rp <?php echo number_format($ex['unit_price'], 0, ',', '.'); ?></div>
                                            </div>
                                            <div class="extra-item-price">Rp <?php echo number_format($ex['total_price'], 0, ',', '.'); ?></div>
                                            <button type="button" class="extra-item-delete" onclick="deleteExtra(<?php echo $ex['id']; ?>, this)" title="Hapus">🗑️</button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($totalExtras > 0): ?>
                                <div class="extras-total" id="extrasTotal">
                                    <span>Total Extras:</span>
                                    <span>Rp <?php echo number_format($totalExtras, 0, ',', '.'); ?></span>
                                </div>
                            <?php else: ?>
                                <div class="extras-total" id="extrasTotal" style="display:none;">
                                    <span>Total Extras:</span>
                                    <span>Rp 0</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- PRICE SUMMARY -->
                        <h3 class="section-title">📊 Ringkasan Harga</h3>
                        <div class="price-box">
                            <div class="price-line">
                                <span>Malam:</span>
                                <strong id="dispNights"><?php echo $booking['total_nights']; ?></strong>
                            </div>
                            <div class="price-line">
                                <span>Subtotal:</span>
                                <strong id="dispSubtotal">Rp <?php echo number_format($booking['total_price'], 0, ',', '.'); ?></strong>
                            </div>
                            <div class="price-line" id="discountRow" style="<?php echo $booking['discount'] > 0 ? '' : 'display:none'; ?>">
                                <span>Diskon:</span>
                                <strong id="dispDiscount" style="color:#ef4444;">- Rp <?php echo number_format($booking['discount'], 0, ',', '.'); ?></strong>
                            </div>
                            <div class="price-line ota-fee-row" id="otaFeeRow" style="display:none;">
                                <span style="color:#92400e;">Fee OTA (<span id="dispFeePercent">0</span>%):</span>
                                <strong id="dispFeeAmount" style="color:#dc2626;">- Rp 0</strong>
                            </div>
                            <div class="price-line" id="extrasRow" style="<?php echo $totalExtras > 0 ? '' : 'display:none'; ?>">
                                <span>Extras:</span>
                                <strong id="dispExtras" style="color:#6366f1;">+ Rp <?php echo number_format($totalExtras, 0, ',', '.'); ?></strong>
                            </div>
                            <div class="price-line-total">
                                <span>TOTAL (Net):</span>
                                <strong id="dispTotal" style="color:#10b981;">Rp <?php echo number_format($booking['final_price'], 0, ',', '.'); ?></strong>
                            </div>
                        </div>

                        <div class="btn-row">
                            <button type="submit" class="btn-save" id="btnSave">💾 Simpan Perubahan</button>
                            <a href="reservasi.php" class="btn-cancel">❌ Batal</a>
                        </div>
                    </div>
                </div><!-- /edit-layout -->
            </form>
        </div>
    </div>
</div>

<script>
    const OTA_FEES = <?php echo json_encode($otaFees); ?>;
    const BASE_URL = '<?php echo BASE_URL; ?>';
    const BOOKING_ID = <?php echo $bookingId; ?>;
    let IS_GROUP = <?php echo $isGroup ? 'true' : 'false'; ?>;
    const ALL_BOOKING_IDS = <?php echo json_encode($allBookingIds); ?>;
    const GUEST_ID = <?php echo intval($booking['guest_id']); ?>;
    const GROUP_ID = '<?php echo htmlspecialchars($booking['group_id'] ?? ''); ?>';
    const AVAILABLE_ROOMS = <?php echo json_encode(array_values($rooms)); ?>;
    const ROOMS_BY_TYPE = <?php echo json_encode($roomsByType); ?>;
    let roomCardIndex = <?php echo count($groupBookings); ?>;
    let discountType = 'rp';
    let currentExtrasTotal = <?php echo $totalExtras; ?>;

    function setDiscType(type) {
        discountType = type;
        document.getElementById('discountType').value = type;
        document.querySelectorAll('.disc-type-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.type === type);
        });
        recalculate();
    }

    function onRoomChange(selectEl) {
        const opt = selectEl.options[selectEl.selectedIndex];
        if (!opt || !opt.dataset.price) return;
        if (IS_GROUP) {
            const card = selectEl.closest('.room-card');
            if (card) card.querySelector('.grp-room-price').value = opt.dataset.price;
            updateGroupRoomOptions();
        } else {
            document.getElementById('roomPrice').value = opt.dataset.price;
        }
    }

    // Hide rooms already selected in other group cards
    function updateGroupRoomOptions() {
        if (!IS_GROUP) return;
        const selects = document.querySelectorAll('.grp-room-select');
        // Collect all currently selected values
        const selected = {};
        selects.forEach(sel => {
            selected[sel.dataset.idx] = sel.value;
        });
        // For each select, disable options chosen by OTHER selects
        selects.forEach(sel => {
            const myIdx = sel.dataset.idx;
            Array.from(sel.options).forEach(opt => {
                if (opt.parentElement.tagName === 'OPTGROUP' || !opt.value) return;
                const takenByOther = Object.entries(selected).some(([idx, val]) => idx !== myIdx && val === opt.value);
                opt.disabled = takenByOther;
                opt.style.display = takenByOther ? 'none' : '';
            });
        });
    }

    function buildRoomOptionsByType(selectedRoomId) {
        let html = '';
        for (const [typeName, rooms] of Object.entries(ROOMS_BY_TYPE)) {
            const price = rooms[0].base_price;
            html += `<optgroup label="${typeName} (Rp ${parseInt(price).toLocaleString('id-ID')})">`;
            rooms.forEach(r => {
                const sel = (r.id == selectedRoomId) ? 'selected' : '';
                html += `<option value="${r.id}" data-price="${r.base_price}" ${sel}>${r.room_number}</option>`;
            });
            html += '</optgroup>';
        }
        return html;
    }

    function addNewRoomCard() {
        const idx = roomCardIndex++;
        const firstRoom = AVAILABLE_ROOMS[0];
        const container = document.querySelector('.room-cards');
        const card = document.createElement('div');
        card.className = 'room-card';
        card.dataset.roomIdx = idx;
        card.dataset.isNew = 'true';
        card.innerHTML = `
            <input type="hidden" name="rooms[${idx}][booking_id]" value="0">
            <div class="room-card-header">
                <span class="room-card-title">🏠 Room Baru</span>
                <span class="new-room-badge">NEW</span>
                <button type="button" class="btn-remove-room" onclick="this.closest('.room-card').remove(); recalculate(); updateGroupRoomOptions();">✕ Hapus</button>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Kamar</label>
                    <select class="grp-room-select" data-idx="${idx}" onchange="onRoomChange(this); recalculate()">
                        ${buildRoomOptionsByType('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>Harga/Malam (Rp)</label>
                    <input type="number" class="grp-room-price" data-idx="${idx}" value="${firstRoom ? firstRoom.base_price : 0}" step="1000" onchange="recalculate()">
                </div>
            </div>
            <div class="form-group">
                <label>Diskon (Rp)</label>
                <input type="number" class="grp-discount" data-idx="${idx}" value="0" min="0" onchange="recalculate()">
            </div>
        `;
        container.appendChild(card);
        // Auto-select first available room and update price
        const newSel = card.querySelector('.grp-room-select');
        onRoomChange(newSel);
        updateGroupRoomOptions();
        recalculate();
    }

    function convertToGroupAndAddRoom() {
        // Convert single booking to group mode
        IS_GROUP = true;

        // Get current single room data
        const currentRoomId = document.getElementById('roomSelect').value;
        const currentRoomPrice = document.getElementById('roomPrice').value;
        const discVal = document.getElementById('discountValue') ? document.getElementById('discountValue').value : '0';

        // Replace single room section with group room cards
        const roomSection = document.querySelector('.form-row:has(#roomSelect)');
        const priceSection = roomSection.nextElementSibling; // SOURCE & PRICE row

        // Build group container
        const container = document.createElement('div');
        container.className = 'room-cards';
        container.innerHTML = `
            <div class="room-card" data-room-idx="0">
                <input type="hidden" name="rooms[0][booking_id]" value="${BOOKING_ID}">
                <div class="room-card-header">
                    <span class="room-card-title">🏠 Room Saat Ini</span>
                    <span class="room-card-status status-badge" style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;padding:2px 8px;font-size:0.65rem;">EXISTING</span>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Kamar</label>
                        <select class="grp-room-select" data-idx="0" onchange="onRoomChange(this); recalculate()">
                            ${buildRoomOptionsByType(currentRoomId)}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Harga/Malam (Rp)</label>
                        <input type="number" class="grp-room-price" data-idx="0" value="${currentRoomPrice}" step="1000" onchange="recalculate()">
                    </div>
                </div>
                <div class="form-group">
                    <label>Diskon (Rp)</label>
                    <input type="number" class="grp-discount" data-idx="0" value="${discVal}" min="0" onchange="recalculate()">
                </div>
            </div>
        `;

        // Insert container and add room button before the source row
        roomSection.replaceWith(container);

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn-add-room';
        addBtn.textContent = '➕ Tambah Room';
        addBtn.onclick = addNewRoomCard;
        container.after(addBtn);

        roomCardIndex = 1;
        addNewRoomCard();
        updateGroupRoomOptions();
    }

    function removeExistingRoom(bookingId, roomNumber, btn) {
        const totalCards = document.querySelectorAll('.room-card').length;
        if (totalCards <= 1) {
            alert('Tidak bisa menghapus room terakhir. Minimal harus ada 1 room.');
            return;
        }
        if (!confirm('Hapus Room ' + roomNumber + ' dari reservasi ini?\nBooking untuk room ini akan dibatalkan.')) return;

        btn.disabled = true;
        btn.textContent = '⏳...';

        fetch(BASE_URL + '/api/delete-booking.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ booking_id: bookingId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const card = btn.closest('.room-card');
                card.style.transition = 'opacity 0.3s, transform 0.3s';
                card.style.opacity = '0';
                card.style.transform = 'translateX(-20px)';
                setTimeout(() => {
                    card.remove();
                    recalculate();
                    updateGroupRoomOptions();
                }, 300);
            } else {
                alert('Gagal hapus: ' + data.message);
                btn.disabled = false;
                btn.textContent = '✕ Hapus';
            }
        })
        .catch(err => {
            alert('Error: ' + err.message);
            btn.disabled = false;
            btn.textContent = '✕ Hapus';
        });
    }

    function recalculate() {
        const ci = new Date(document.getElementById('checkIn').value);
        const co = new Date(document.getElementById('checkOut').value);
        if (!ci || !co || co <= ci) return;

        const nights = Math.ceil((co - ci) / 86400000);

        // OTA Fee percent from source
        const source = document.getElementById('bookingSource').value;
        const feePercent = OTA_FEES[source] || 0;

        let grandSubtotal = 0;
        let grandDiscount = 0;
        let grandFee = 0;
        let grandRoomNet = 0;

        if (IS_GROUP) {
            // Sum across all room cards
            document.querySelectorAll('.room-card').forEach(card => {
                const price = parseFloat(card.querySelector('.grp-room-price').value) || 0;
                const disc = parseFloat(card.querySelector('.grp-discount').value) || 0;
                const sub = nights * price;
                const afterDisc = sub - disc;
                const fee = feePercent > 0 ? Math.round(afterDisc * feePercent / 100) : 0;
                grandSubtotal += sub;
                grandDiscount += disc;
                grandFee += fee;
                grandRoomNet += (afterDisc - fee);
            });

            // Global discount (applied on top of per-room discounts)
            const globalDiscVal = parseFloat(document.getElementById('discountValue').value) || 0;
            if (globalDiscVal > 0) {
                let globalDisc = 0;
                if (discountType === 'percent') {
                    globalDisc = Math.round(grandSubtotal * globalDiscVal / 100);
                } else {
                    globalDisc = globalDiscVal;
                }
                grandDiscount += globalDisc;
                grandRoomNet -= globalDisc;
            }
        } else {
            const price = parseFloat(document.getElementById('roomPrice').value) || 0;
            grandSubtotal = nights * price;

            // Discount
            const discVal = parseFloat(document.getElementById('discountValue').value) || 0;
            if (discountType === 'percent') {
                grandDiscount = Math.round(grandSubtotal * discVal / 100);
            } else {
                grandDiscount = discVal;
            }

            const afterDiscount = grandSubtotal - grandDiscount;
            grandFee = feePercent > 0 ? Math.round(afterDiscount * feePercent / 100) : 0;
            grandRoomNet = afterDiscount - grandFee;
        }

        const total = grandRoomNet + currentExtrasTotal;

        // Update display
        document.getElementById('dispNights').textContent = nights;
        document.getElementById('dispSubtotal').textContent = 'Rp ' + grandSubtotal.toLocaleString('id-ID');

        if (grandDiscount > 0) {
            document.getElementById('discountRow').style.display = 'flex';
            document.getElementById('dispDiscount').textContent = '- Rp ' + grandDiscount.toLocaleString('id-ID');
        } else {
            document.getElementById('discountRow').style.display = 'none';
        }

        if (feePercent > 0) {
            document.getElementById('otaFeeRow').style.display = 'flex';
            document.getElementById('dispFeePercent').textContent = feePercent;
            document.getElementById('dispFeeAmount').textContent = '- Rp ' + grandFee.toLocaleString('id-ID');
        } else {
            document.getElementById('otaFeeRow').style.display = 'none';
        }

        // Extras row
        if (currentExtrasTotal > 0) {
            document.getElementById('extrasRow').style.display = 'flex';
            document.getElementById('dispExtras').textContent = '+ Rp ' + currentExtrasTotal.toLocaleString('id-ID');
        } else {
            document.getElementById('extrasRow').style.display = 'none';
        }

        document.getElementById('dispTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
    }

    // ========== EXTRAS MANAGEMENT ==========

    function toggleAddExtraForm() {
        const form = document.getElementById('addExtraForm');
        form.classList.toggle('active');
        if (form.classList.contains('active')) {
            document.getElementById('extraItemName').focus();
        }
    }

    function fillPreset(name, price) {
        document.getElementById('extraItemName').value = name;
        document.getElementById('extraUnitPrice').value = price;
        document.getElementById('extraQty').value = 1;
        document.getElementById('extraItemName').focus();
    }

    function addExtra() {
        const itemName = document.getElementById('extraItemName').value.trim();
        const qty = parseInt(document.getElementById('extraQty').value) || 1;
        const unitPrice = parseFloat(document.getElementById('extraUnitPrice').value) || 0;

        if (!itemName) {
            alert('Nama item harus diisi');
            return;
        }
        if (unitPrice <= 0) {
            alert('Harga harus lebih dari 0');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('booking_id', BOOKING_ID);
        formData.append('item_name', itemName);
        formData.append('quantity', qty);
        formData.append('unit_price', unitPrice);

        fetch(BASE_URL + '/api/booking-extras.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const totalPrice = qty * unitPrice;
                    currentExtrasTotal += totalPrice;

                    // Remove empty message
                    const empty = document.getElementById('extrasEmpty');
                    if (empty) empty.remove();

                    // Add item to list
                    const list = document.getElementById('extrasList');
                    const div = document.createElement('div');
                    div.className = 'extra-item';
                    div.dataset.extraId = data.extra.id;
                    div.innerHTML = `
                <div class="extra-item-info">
                    <div class="extra-item-name">${escHtml(itemName)}</div>
                    <div class="extra-item-detail">${qty}x @ Rp ${unitPrice.toLocaleString('id-ID')}</div>
                </div>
                <div class="extra-item-price">Rp ${totalPrice.toLocaleString('id-ID')}</div>
                <button type="button" class="extra-item-delete" onclick="deleteExtra(${data.extra.id}, this)" title="Hapus">🗑️</button>
            `;
                    list.appendChild(div);

                    // Update extras total display
                    updateExtrasTotalDisplay();

                    // Clear form
                    document.getElementById('extraItemName').value = '';
                    document.getElementById('extraQty').value = '1';
                    document.getElementById('extraUnitPrice').value = '';
                    document.getElementById('addExtraForm').classList.remove('active');

                    recalculate();
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }

    function deleteExtra(extraId, btn) {
        if (!confirm('Hapus item ini?')) return;

        const item = btn.closest('.extra-item');
        const priceText = item.querySelector('.extra-item-price').textContent;
        const price = parseFloat(priceText.replace(/[^\d]/g, '')) || 0;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('extra_id', extraId);

        fetch(BASE_URL + '/api/booking-extras.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    currentExtrasTotal -= price;
                    if (currentExtrasTotal < 0) currentExtrasTotal = 0;

                    item.remove();

                    // Show empty if no items left
                    const list = document.getElementById('extrasList');
                    if (!list.querySelector('.extra-item')) {
                        list.innerHTML = '<div class="extras-empty" id="extrasEmpty">Belum ada tambahan</div>';
                    }

                    updateExtrasTotalDisplay();
                    recalculate();
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }

    function updateExtrasTotalDisplay() {
        const el = document.getElementById('extrasTotal');
        if (currentExtrasTotal > 0) {
            el.style.display = 'flex';
            el.innerHTML = '<span>Total Extras:</span><span>Rp ' + currentExtrasTotal.toLocaleString('id-ID') + '</span>';
        } else {
            el.style.display = 'none';
        }
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function saveEdit(event) {
        event.preventDefault();

        const btn = document.getElementById('btnSave');
        btn.innerHTML = '⏳ Menyimpan...';
        btn.disabled = true;

        const form = document.getElementById('editForm');
        const formData = new FormData(form);

        // Debug: log room info before save
        if (!IS_GROUP) {
            const roomSel = document.getElementById('roomSelect');
            const selectedOpt = roomSel.options[roomSel.selectedIndex];
            console.log('🔑 ROOM SAVE DEBUG:', {
                room_id: formData.get('room_id'),
                room_price: formData.get('room_price'),
                selected_text: selectedOpt ? selectedOpt.textContent.trim() : 'N/A'
            });
        }

        if (IS_GROUP) {
            // Group save: send all rooms data as JSON
            const ci = new Date(document.getElementById('checkIn').value);
            const co = new Date(document.getElementById('checkOut').value);
            const nights = (ci && co && co > ci) ? Math.ceil((co - ci) / 86400000) : 1;
            const roomCards = document.querySelectorAll('.room-card');
            const roomsData = [];
            const newRooms = [];
            roomCards.forEach(card => {
                const bookingId = card.querySelector('input[name*="[booking_id]"]')?.value || '0';
                const roomData = {
                    booking_id: bookingId,
                    room_id: card.querySelector('.grp-room-select').value,
                    room_price: card.querySelector('.grp-room-price').value,
                    discount: card.querySelector('.grp-discount').value
                };
                if (bookingId === '0' || card.dataset.isNew === 'true') {
                    newRooms.push(roomData);
                } else {
                    roomsData.push(roomData);
                }
            });
            // Distribute global discount proportionally across rooms
            const globalDiscVal = parseFloat(document.getElementById('discountValue').value) || 0;
            if (globalDiscVal > 0) {
                let globalDisc = 0;
                if (discountType === 'percent') {
                    let totalSub = 0;
                    roomsData.forEach(r => totalSub += parseFloat(r.room_price) || 0);
                    newRooms.forEach(r => totalSub += parseFloat(r.room_price) || 0);
                    totalSub *= nights;
                    globalDisc = Math.round(totalSub * globalDiscVal / 100);
                } else {
                    globalDisc = globalDiscVal;
                }
                // Distribute proportionally among existing rooms
                const allRooms = [...roomsData, ...newRooms];
                let totalPrice = 0;
                allRooms.forEach(r => totalPrice += parseFloat(r.room_price) || 0);
                if (totalPrice > 0) {
                    let distributed = 0;
                    allRooms.forEach((r, i) => {
                        const share = (i === allRooms.length - 1)
                            ? (globalDisc - distributed)
                            : Math.round(globalDisc * (parseFloat(r.room_price) || 0) / totalPrice);
                        r.discount = (parseFloat(r.discount) || 0) + share;
                        distributed += share;
                    });
                }
            }

            formData.append('is_group', '1');
            formData.append('rooms_json', JSON.stringify(roomsData));
            if (newRooms.length > 0) {
                formData.append('new_rooms_json', JSON.stringify(newRooms));
            }
            console.log('GROUP SAVE existing:', roomsData, 'new:', newRooms);
        }

        const apiUrl = BASE_URL + '/api/update-reservation.php';
        console.log('Saving to:', apiUrl);
        console.log('FormData entries:');
        for (let [k, v] of formData.entries()) {
            console.log('  ', k, '=', typeof v === 'string' && v.length > 200 ? v.substring(0, 200) + '...' : v);
        }

        fetch(apiUrl, {
                method: 'POST',
                body: formData
            })
            .then(r => r.text())
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Response bukan JSON:', text);
                    throw new Error('Server error: respons tidak valid');
                }
                const alertBox = document.getElementById('alertBox');
                if (data.success) {
                    console.log('✅ SERVER RESPONSE:', JSON.stringify(data, null, 2));
                    let msg = '✅ ' + data.message;
                    if (data.debug && data.debug.verified_row) {
                        msg += '\n\nVerifikasi DB:\n- Room ID: ' + data.debug.verified_row.room_id;
                        msg += '\n- Status: ' + data.debug.verified_row.status;
                    }
                    alertBox.innerHTML = '<div class="alert alert-success">✅ ' + data.message + '</div>';
                    alertBox.scrollIntoView({
                        behavior: 'smooth'
                    });
                    alert(msg);
                    setTimeout(() => {
                        window.location.href = 'reservasi.php';
                    }, 1200);
                } else {
                    alertBox.innerHTML = '<div class="alert alert-error">❌ ' + data.message + '</div>';
                    alertBox.scrollIntoView({
                        behavior: 'smooth'
                    });
                    alert('❌ ' + data.message);
                    btn.innerHTML = '💾 Simpan Perubahan';
                    btn.disabled = false;
                }
            })
            .catch(err => {
                const alertBox = document.getElementById('alertBox');
                alertBox.innerHTML = '<div class="alert alert-error">❌ Error: ' + err.message + '</div>';
                alertBox.scrollIntoView({
                    behavior: 'smooth'
                });
                alert('❌ Error: ' + err.message);
                btn.innerHTML = '💾 Simpan Perubahan';
                btn.disabled = false;
            });

        return false;
    }

    // Initial calculation including OTA
    recalculate();
    updateGroupRoomOptions();
</script>

<?php include '../../includes/footer.php'; ?>