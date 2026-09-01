<?php

/**
 * NARAYANA KARIMUNJAWA — Reservations
 * Marriott-style booking with real-time availability
 */
// Flexible path: works on hosting (config inside webroot) and local dev (config outside public/)
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

// Booking is handled by Cloudbeds channel manager.
$checkInParam = $_GET['checkin'] ?? $_GET['check_in'] ?? null;
$checkOutParam = $_GET['checkout'] ?? $_GET['check_out'] ?? null;
$adultsParam = $_GET['adults'] ?? $_GET['guests'] ?? null;
$kidsParam = $_GET['kids'] ?? 0;

$cloudbedsUrl = buildCloudbedsReservationUrl([
    'checkin' => $checkInParam,
    'checkout' => $checkOutParam,
    'adults' => $adultsParam,
    'kids' => $kidsParam,
]);

header('Location: ' . $cloudbedsUrl, true, 302);
exit;

$currentPage = 'booking';
$pageTitle = 'Reservations';

$checkIn = $_GET['check_in'] ?? date('Y-m-d');
$checkOut = $_GET['check_out'] ?? date('Y-m-d', strtotime('+1 day'));
$guests = (int)($_GET['guests'] ?? 2);
$roomTypeFilter = (int)($_GET['room_type'] ?? 0);
$hasSearch = isset($_GET['check_in']) || isset($_GET['room_type']);

$roomTypes = dbFetchAll("SELECT * FROM room_types ORDER BY base_price DESC");

$availableRooms = [];
$totalNights = 0;
if ($hasSearch && $checkIn && $checkOut && strtotime($checkIn) < strtotime($checkOut)) {
    $checkInDate = new DateTime($checkIn);
    $checkOutDate = new DateTime($checkOut);
    $totalNights = $checkInDate->diff($checkOutDate)->days;

    $sql = "
        SELECT r.id, r.room_number, r.floor_number,
               rt.id as room_type_id, rt.type_name, rt.base_price, 
               rt.max_occupancy, rt.amenities
        FROM rooms r
        JOIN room_types rt ON r.room_type_id = rt.id
        WHERE r.status NOT IN ('maintenance', 'blocked')
        AND rt.max_occupancy >= :guests
        AND r.id NOT IN (
            SELECT DISTINCT b.room_id 
            FROM bookings b
            WHERE b.status IN ('pending', 'confirmed', 'checked_in')
            AND b.check_in_date < :checkout 
            AND b.check_out_date > :checkin
        )
    ";
    $params = [':guests' => $guests, ':checkout' => $checkOut, ':checkin' => $checkIn];

    if ($roomTypeFilter > 0) {
        $sql .= " AND rt.id = :room_type";
        $params[':room_type'] = $roomTypeFilter;
    }

    $sql .= " ORDER BY rt.base_price DESC, r.room_number ASC";
    $availableRooms = dbFetchAll($sql, $params);
}

$roomIcons = ['King' => '👑', 'Queen' => '🌙', 'Twin' => '🛏️'];

include __DIR__ . '/includes/header.php';
?>

<!-- Page Hero -->
<section class="page-hero">
    <div class="container">
        <div class="section-eyebrow" style="color:var(--gold-light);">Book Your Stay</div>
        <h1>Reservations</h1>
        <p>Select your dates and find the perfect room for your island escape.</p>
    </div>
</section>

<!-- Search -->
<section class="section" style="padding-top:48px;">
    <div class="container" style="max-width:880px;">

        <div class="form-card">
            <div class="section-eyebrow">Find & Reserve</div>
            <h3 style="margin-bottom:24px;">Check Availability</h3>
            <form method="GET">
                <div class="form-row">
                    <div class="form-group">
                        <label>Check-in Date</label>
                        <input type="date" name="check_in" class="form-control" value="<?= htmlspecialchars($checkIn) ?>" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Check-out Date</label>
                        <input type="date" name="check_out" class="form-control" value="<?= htmlspecialchars($checkOut) ?>" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Guests</label>
                        <select name="guests" class="form-control">
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?= $i ?>" <?= $guests === $i ? 'selected' : '' ?>><?= $i ?> Guest<?= $i > 1 ? 's' : '' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Room Type</label>
                        <select name="room_type" class="form-control">
                            <option value="0">All Room Types</option>
                            <?php foreach ($roomTypes as $rt): ?>
                                <option value="<?= $rt['id'] ?>" <?= $roomTypeFilter === (int)$rt['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rt['type_name']) ?> — <?= formatCurrency($rt['base_price']) ?>/night
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">
                    <i class="fas fa-search"></i> Search Available Rooms
                </button>
            </form>
        </div>

        <?php if ($hasSearch): ?>
            <!-- Results -->
            <div style="margin-top:32px;" class="fade-in">
                <?php if ($totalNights > 0): ?>
                    <div class="search-summary">
                        <div>
                            <strong><?= count($availableRooms) ?></strong> room<?= count($availableRooms) !== 1 ? 's' : '' ?> available
                            &nbsp;·&nbsp;
                            <?= date('d M Y', strtotime($checkIn)) ?> → <?= date('d M Y', strtotime($checkOut)) ?>
                            &nbsp;·&nbsp;
                            <?= $totalNights ?> night<?= $totalNights > 1 ? 's' : '' ?>
                        </div>
                        <div style="font-size:13px; color:var(--mid-gray);">
                            <?= $guests ?> guest<?= $guests > 1 ? 's' : '' ?>
                        </div>
                    </div>

                    <?php if (empty($availableRooms)): ?>
                        <div style="text-align:center; padding:48px 24px; background:var(--white); border:1px solid var(--pale-gray); border-radius:var(--radius-lg);">
                            <i class="fas fa-bed" style="font-size:2rem; color:var(--light-gray); display:block; margin-bottom:16px;"></i>
                            <h3 style="margin-bottom:8px;">No Rooms Available</h3>
                            <p style="color:var(--warm-gray); margin-bottom:20px;">No rooms match your criteria for these dates. Try different dates or fewer guests.</p>
                            <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=Hi%2C%20I'd%20like%20to%20check%20availability%20for%20<?= $checkIn ?>%20to%20<?= $checkOut ?>" target="_blank" class="btn btn-primary">
                                <i class="fab fa-whatsapp"></i> Ask via WhatsApp
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($availableRooms as $room):
                            $amenities = $room['amenities'] ? array_map('trim', explode(',', $room['amenities'])) : [];
                            $totalPrice = $room['base_price'] * $totalNights;
                            $icon = $roomIcons[$room['type_name']] ?? '🏨';
                        ?>
                            <div class="room-result-card" id="room-<?= $room['id'] ?>">
                                <div class="room-result-img"><span><?= $icon ?></span></div>
                                <div class="room-result-info">
                                    <h4><?= htmlspecialchars($room['type_name']) ?> Room</h4>
                                    <div class="room-number">Room <?= htmlspecialchars($room['room_number']) ?> · Floor <?= $room['floor_number'] ?></div>
                                    <div class="room-amenities" style="margin-bottom:0;">
                                        <?php foreach (array_slice($amenities, 0, 4) as $a): ?>
                                            <span><?= htmlspecialchars($a) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="margin-top:6px; font-size:12px; color:var(--mid-gray);">
                                        <i class="fas fa-user"></i> Up to <?= $room['max_occupancy'] ?> guests
                                    </div>
                                </div>
                                <div class="room-result-price">
                                    <div class="per-night"><?= formatCurrency($room['base_price']) ?></div>
                                    <div class="total"><?= formatCurrency($totalPrice) ?> total · <?= $totalNights ?> night<?= $totalNights > 1 ? 's' : '' ?></div>
                                    <button onclick="selectRoom(<?= $room['id'] ?>, '<?= htmlspecialchars($room['type_name']) ?>', '<?= $room['room_number'] ?>', <?= $room['base_price'] ?>, <?= $totalPrice ?>)" class="btn btn-primary btn-block btn-sm">
                                        Select Room
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="text-align:center; padding:24px; background:#fff8e1; border:1px solid #ffe082; border-radius:var(--radius);">
                        <p style="color:var(--warning);">Check-out date must be after check-in date.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Booking Form (hidden) -->
<section class="section" id="bookingFormSection" style="display:none; padding-top:0;">
    <div class="container" style="max-width:880px;">
        <div class="form-card">
            <div class="section-eyebrow">Complete Booking</div>
            <h3 style="margin-bottom:24px;">Guest Information</h3>

            <!-- Selected Room -->
            <div class="selected-summary" id="selectedRoomSummary">
                <div>
                    <strong id="sumRoomType"></strong> — Room <span id="sumRoomNumber"></span><br>
                    <small style="color:var(--mid-gray);">
                        <?= date('d M Y', strtotime($checkIn)) ?> → <?= date('d M Y', strtotime($checkOut)) ?> · <?= $totalNights ?> night<?= $totalNights > 1 ? 's' : '' ?>
                    </small>
                </div>
                <div style="text-align:right;">
                    <div style="font-family:var(--font-heading); font-size:1.2rem; color:var(--black);" id="sumTotal"></div>
                    <small style="color:var(--mid-gray);"><span id="sumPerNight"></span>/night</small>
                </div>
            </div>

            <form id="reservationForm" onsubmit="submitBooking(event)">
                <input type="hidden" name="room_id" id="formRoomId">
                <input type="hidden" name="check_in" value="<?= htmlspecialchars($checkIn) ?>">
                <input type="hidden" name="check_out" value="<?= htmlspecialchars($checkOut) ?>">
                <input type="hidden" name="guests" value="<?= $guests ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="guest_name" class="form-control" placeholder="Your full name" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="guest_email" class="form-control" placeholder="your@email.com" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="tel" name="guest_phone" class="form-control" placeholder="+62 xxx-xxxx-xxxx" required>
                    </div>
                    <div class="form-group">
                        <label>ID Type</label>
                        <select name="id_card_type" class="form-control">
                            <option value="ktp">KTP</option>
                            <option value="passport">Passport</option>
                            <option value="sim">SIM (Driver's License)</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>ID Number</label>
                        <input type="text" name="id_card_number" class="form-control" placeholder="ID card number">
                    </div>
                    <div class="form-group">
                        <label>Nationality</label>
                        <input type="text" name="nationality" class="form-control" value="Indonesia">
                    </div>
                </div>
                <div class="form-group">
                    <label>Special Requests</label>
                    <textarea name="special_request" class="form-control" rows="3" placeholder="Early check-in, extra pillows, etc."></textarea>
                </div>

                <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--pale-gray);">
                    <button type="submit" class="btn btn-primary btn-block btn-lg" id="submitBtn">
                        <i class="fas fa-check"></i> Confirm Reservation
                    </button>
                    <p style="text-align:center; font-size:12px; color:var(--mid-gray); margin-top:10px;">
                        Payment can be made at check-in. Free cancellation up to 24 hours before arrival.
                    </p>
                </div>
            </form>
        </div>
    </div>
</section>

<!-- Help -->
<section class="section section-alt">
    <div class="container" style="max-width:640px; text-align:center;">
        <h3>Need Assistance?</h3>
        <p style="color:var(--warm-gray); margin-bottom:20px;">Our reservation team is available to help you find the perfect room.</p>
        <div class="btn-group" style="justify-content:center;">
            <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>" target="_blank" class="btn btn-primary"><i class="fab fa-whatsapp"></i> WhatsApp Us</a>
            <a href="tel:<?= BUSINESS_PHONE ?>" class="btn btn-outline"><i class="fas fa-phone"></i> <?= BUSINESS_PHONE ?></a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
    function selectRoom(roomId, typeName, roomNumber, perNight, total) {
        document.getElementById('formRoomId').value = roomId;
        document.getElementById('sumRoomType').textContent = typeName + ' Room';
        document.getElementById('sumRoomNumber').textContent = roomNumber;
        document.getElementById('sumPerNight').textContent = formatCurrency(perNight);
        document.getElementById('sumTotal').textContent = formatCurrency(total);

        document.querySelectorAll('.room-result-card').forEach(c => c.classList.remove('selected'));
        document.getElementById('room-' + roomId).classList.add('selected');

        const section = document.getElementById('bookingFormSection');
        section.style.display = 'block';
        setTimeout(() => section.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        }), 100);
    }

    async function submitBooking(e) {
        e.preventDefault();
        const form = document.getElementById('reservationForm');
        const btn = document.getElementById('submitBtn');
        const formData = new FormData(form);

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        try {
            const response = await fetch('<?= BASE_URL ?>/api/create-booking.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(Object.fromEntries(formData))
            });
            const result = await response.json();

            if (result.success) {
                showToast('Reservation confirmed! Code: ' + result.data.booking_code, 'success');
                setTimeout(() => {
                    window.location.href = '<?= BASE_URL ?>/confirmation.php?code=' + result.data.booking_code;
                }, 1500);
            } else {
                showToast(result.error || 'Failed to create reservation', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Confirm Reservation';
            }
        } catch (err) {
            showToast('Connection error. Please try again.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Confirm Reservation';
        }
    }

    document.querySelector('input[name="check_in"]')?.addEventListener('change', function() {
        const co = document.querySelector('input[name="check_out"]');
        const next = new Date(this.value);
        next.setDate(next.getDate() + 1);
        co.min = next.toISOString().split('T')[0];
        if (co.value <= this.value) co.value = co.min;
    });
</script>