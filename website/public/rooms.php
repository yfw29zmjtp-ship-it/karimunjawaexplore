<?php

/**
 * NARAYANA KARIMUNJAWA — Rooms
 * Marriott-style room showcase with real-time availability
 */
// Flexible path: works on hosting (config inside webroot) and local dev (config outside public/)
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

$currentPage = 'rooms';
$pageTitle = 'Rooms';

function normalizeRoomTypeKey(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
    $normalized = trim((string)$normalized, '_');

    // Match settings keys like deluxe_king even if DB type_name is "Deluxe King Room".
    if (str_ends_with($normalized, '_room')) {
        $normalized = substr($normalized, 0, -5);
    }

    return $normalized;
}

// Load room galleries from settings
$gallerySettings = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_room_gallery_%'");
$roomGalleries = [];
foreach ($gallerySettings as $setting) {
    $roomType = str_replace('web_room_gallery_', '', $setting['setting_key']);
    $roomGalleries[normalizeRoomTypeKey($roomType)] = json_decode($setting['setting_value'] ?? '[]', true) ?: [];
}

// Load room descriptions from settings
$roomDescSettings = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_room_desc_%'");
$roomDescriptions = [
    'King' => 'Our finest accommodation featuring a luxurious king-size bed, elegant furnishings, and panoramic views. Perfect for couples seeking the ultimate island retreat.',
    'Queen' => 'A beautifully appointed room with a comfortable queen-size bed and modern amenities. Enjoy serene island views and the gentle sound of ocean waves.',
    'Twin' => 'Ideal for friends or family, our twin rooms feature two comfortable single beds in a spacious setting with all the comforts for a relaxing stay.',
];
foreach ($roomDescSettings as $setting) {
    $roomType = str_replace('web_room_desc_', '', $setting['setting_key']);
    $roomDescriptions[ucfirst($roomType)] = $setting['setting_value'];
}

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$roomTypes = dbFetchAll("
    SELECT rt.*,
           COUNT(r.id) as total_rooms,
           SUM(CASE 
               WHEN r.status IN ('maintenance', 'blocked') THEN 0
               WHEN r.id IN (
                   SELECT DISTINCT b.room_id FROM bookings b 
                   WHERE b.status IN ('checked_in', 'confirmed') 
                   AND b.check_in_date < ? AND b.check_out_date > ?
               ) THEN 0
               ELSE 1
           END) as available_rooms
    FROM room_types rt
    LEFT JOIN rooms r ON rt.id = r.room_type_id
    GROUP BY rt.id
    ORDER BY rt.base_price DESC
", [$tomorrow, $today]);

$rooms = dbFetchAll("
    SELECT r.*, rt.type_name, rt.base_price,
           CASE 
               WHEN r.status IN ('maintenance', 'blocked') THEN r.status
               WHEN r.id IN (
                   SELECT DISTINCT b.room_id FROM bookings b 
                   WHERE b.status IN ('checked_in', 'confirmed')
                   AND b.check_in_date < ? AND b.check_out_date > ?
               ) THEN 'occupied'
               WHEN r.status = 'cleaning' THEN 'cleaning'
               ELSE 'available'
           END as live_status
    FROM rooms r
    JOIN room_types rt ON r.room_type_id = rt.id
    ORDER BY r.room_number ASC
", [$tomorrow, $today]);

$roomIcons = ['King' => '👑', 'Queen' => '🌙', 'Twin' => '🛏️'];

// Load hero settings for rooms page
$_heroRooms = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero_rooms_%'");
$_heroR = [];
foreach ($_heroRooms as $_h) $_heroR[$_h['setting_key']] = $_h['setting_value'];
$roomsHeroEyebrow  = $_heroR['web_hero_rooms_eyebrow']  ?? 'Accommodations';
$roomsHeroTitle    = $_heroR['web_hero_rooms_title']    ?? 'Our Rooms';
$roomsHeroSubtitle = $_heroR['web_hero_rooms_subtitle'] ?? 'Thoughtfully designed spaces where island comfort meets refined elegance.';
$roomsHeroBg       = $_heroR['web_hero_rooms_background'] ?? '';

include __DIR__ . '/includes/header.php';
?>

<!-- Page Hero -->
<section class="page-hero" <?php if (!empty($roomsHeroBg)): ?> style="background: linear-gradient(180deg, rgba(0,0,0,0.45), rgba(0,0,0,0.6)), url('<?= htmlspecialchars($roomsHeroBg) ?>') center/cover no-repeat;" <?php endif; ?>>
    <div class="container">
        <div class="section-eyebrow" style="color:var(--gold-light);"><?= htmlspecialchars($roomsHeroEyebrow) ?></div>
        <h1><?= htmlspecialchars($roomsHeroTitle) ?></h1>
        <p><?= htmlspecialchars($roomsHeroSubtitle) ?></p>
    </div>
</section>

<!-- Room Types -->
<section class="section">
    <div class="container">
        <?php foreach ($roomTypes as $i => $room):
            $amenities = $room['amenities'] ? array_map('trim', explode(',', $room['amenities'])) : [];
            $typeName = trim($room['type_name']);
            $icon = $roomIcons[$typeName] ?? '🏨';
            $desc = $roomDescriptions[$typeName] ?? 'A comfortable room designed for your perfect island stay.';
            $avail = (int)$room['available_rooms'];
            $reverse = $i % 2 !== 0;

            if ($avail >= 3) {
                $ac = 'available';
                $at = $avail . ' rooms available';
            } elseif ($avail > 0) {
                $ac = 'limited';
                $at = 'Only ' . $avail . ' remaining';
            } else {
                $ac = 'full';
                $at = 'Fully booked today';
            }

            $typeRooms = array_filter($rooms, fn($r) => $r['room_type_id'] == $room['id']);
            $typeKey = normalizeRoomTypeKey($typeName);
            $gallery = $roomGalleries[$typeKey] ?? [];
            $firstImage = !empty($gallery) ? $gallery[0] : null;
        ?>
            <div class="room-detail <?= $reverse ? 'reverse' : '' ?> fade-in">
                <!-- Image -->
                <div class="room-detail-image">
                    <?php if ($firstImage):
                        $imgSrc = (str_starts_with($firstImage, 'http://') || str_starts_with($firstImage, 'https://'))
                            ? $firstImage
                            : BASE_URL . '/' . $firstImage;
                    ?>
                        <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($typeName) ?> Room" style="width: 100%; height: 100%; object-fit: cover; position: absolute; top: 0; left: 0;">
                    <?php else: ?>
                        <span class="room-emoji"><?= $icon ?></span>
                    <?php endif; ?>
                    <div style="position:absolute; bottom:12px; left:12px; z-index: 2;">
                        <span class="avail-badge <?= $ac ?>"><span class="avail-dot"></span><?= $at ?></span>
                    </div>
                </div>

                <!-- Info -->
                <div class="room-detail-info">
                    <div class="section-eyebrow"><?= htmlspecialchars($typeName) ?> Room</div>
                    <h2><?= htmlspecialchars($typeName) ?></h2>
                    <div class="divider"></div>
                    <p style="color:var(--warm-gray); line-height:1.7; margin-bottom:12px; font-size:13px;"><?= $desc ?></p>

                    <div class="room-specs">
                        <div class="room-spec"><i class="fas fa-user"></i> Up to <?= $room['max_occupancy'] ?> Guests</div>
                        <div class="room-spec"><i class="fas fa-door-open"></i> <?= (int)$room['total_rooms'] ?> Rooms</div>
                        <div class="room-spec"><i class="fas fa-clock"></i> Check-in <?= BUSINESS_CHECKIN_TIME ?></div>
                        <div class="room-spec"><i class="fas fa-wifi"></i> Free WiFi</div>
                    </div>

                    <!-- Amenities -->
                    <div class="room-amenities">
                        <?php foreach ($amenities as $a): ?>
                            <span><?= htmlspecialchars($a) ?></span>
                        <?php endforeach; ?>
                    </div>

                    <!-- Room Status -->
                    <div style="margin-top:6px;">
                        <div style="font-size:10px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:var(--mid-gray); margin-bottom:4px;">Room Status</div>
                        <div class="room-status-grid">
                            <?php foreach ($typeRooms as $tr):
                                $sc = match ($tr['live_status']) {
                                    'available' => 'available',
                                    'occupied' => 'occupied',
                                    'cleaning' => 'cleaning',
                                    default => 'maintenance'
                                };
                            ?>
                                <div class="room-status-box <?= $sc ?>" title="Room <?= $tr['room_number'] ?> — <?= ucfirst($tr['live_status']) ?>">
                                    <?= $tr['room_number'] ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="status-legend">
                            <span class="legend-available">Available</span>
                            <span class="legend-occupied">Occupied</span>
                            <span class="legend-cleaning">Cleaning</span>
                        </div>
                    </div>

                    <!-- Price & CTA -->
                    <div class="room-detail-footer">
                        <div class="room-price"><?= formatCurrency($room['base_price']) ?><small> /night</small></div>
                        <a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>" class="btn btn-primary">Book Now</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Amenities Overview -->
<section class="section section-alt">
    <div class="container">
        <div class="section-header text-center fade-in">
            <div class="section-eyebrow">Amenities</div>
            <h2 class="section-title">Every Room Includes</h2>
            <div class="divider center"></div>
        </div>
        <div class="features-grid">
            <?php
            $amenList = [
                ['icon' => 'fa-snowflake', 'name' => 'Air Conditioning', 'desc' => 'Climate-controlled comfort'],
                ['icon' => 'fa-wifi', 'name' => 'High-Speed WiFi', 'desc' => 'Stay connected always'],
                ['icon' => 'fa-tv', 'name' => 'Flat Screen TV', 'desc' => 'In-room entertainment'],
                ['icon' => 'fa-shower', 'name' => 'Hot Water', 'desc' => 'Rain shower experience'],
                ['icon' => 'fa-bed', 'name' => 'Premium Bedding', 'desc' => 'Luxury linens & pillows'],
                ['icon' => 'fa-broom', 'name' => 'Daily Housekeeping', 'desc' => 'Pristine & refreshed daily'],
            ];
            foreach ($amenList as $am):
            ?>
                <div class="feature-card fade-in" style="text-align:center;">
                    <div class="feature-icon" style="margin:0 auto 16px;"><i class="fas <?= $am['icon'] ?>"></i></div>
                    <h4><?= $am['name'] ?></h4>
                    <p><?= $am['desc'] ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-section">
    <div class="container">
        <h2>Find Your Perfect Room</h2>
        <p>Check availability for your desired dates and reserve your island escape.</p>
        <a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>" class="btn btn-gold btn-lg">Check Availability</a>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>