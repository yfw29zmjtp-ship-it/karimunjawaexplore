<?php

/**
 * NARAYANA KARIMUNJAWA — Booking Confirmation
 * Marriott-style confirmation page
 */
// Flexible path: works on hosting (config inside webroot) and local dev (config outside public/)
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

$currentPage = 'confirmation';
$pageTitle = 'Booking Confirmation';

$code = $_GET['code'] ?? '';
$booking = null;

if ($code) {
    // Query hotel DB directly — booking is stored in adf_narayana_hotel
    $booking = dbFetch("
        SELECT b.*, 
               g.guest_name, g.email, g.phone,
               g.id_card_type, g.nationality,
               r.room_number, r.floor_number,
               rt.type_name, rt.base_price, rt.amenities
        FROM bookings b
        JOIN guests g ON b.guest_id = g.id
        JOIN rooms r ON b.room_id = r.id
        JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.booking_code = ?
    ", [$code]);
}

include __DIR__ . '/includes/header.php';
?>

<!-- Page Hero -->
<section class="page-hero" style="min-height:240px;">
    <div class="container">
        <h1><?= $booking ? 'Booking Confirmed' : 'Confirmation' ?></h1>
        <p><?= $booking ? 'Your reservation has been received successfully.' : 'Look up your booking details.' ?></p>
    </div>
</section>

<section class="section" style="padding-top:48px;">
    <div class="container" style="max-width:720px;">

        <?php if ($booking):
            $checkIn = new DateTime($booking['check_in_date']);
            $checkOut = new DateTime($booking['check_out_date']);
            $nights = $checkIn->diff($checkOut)->days;
            $statusColors = [
                'pending' => '#f39c12',
                'confirmed' => '#27ae60',
                'checked_in' => '#2980b9',
                'checked_out' => '#95a5a6',
                'cancelled' => '#e74c3c'
            ];
            $statusColor = $statusColors[$booking['status']] ?? 'var(--mid-gray)';
        ?>

            <div class="confirmation-card fade-in">
                <!-- Header -->
                <div class="confirmation-header">
                    <div class="confirmation-icon"><i class="fas fa-check-circle"></i></div>
                    <h2>Reservation Confirmed</h2>
                    <p>Thank you, <?= htmlspecialchars($booking['guest_name']) ?>!</p>
                    <div class="booking-code"><?= htmlspecialchars($booking['booking_code']) ?></div>
                </div>

                <!-- Details -->
                <div class="confirmation-details">

                    <div class="detail-section">
                        <h4><i class="fas fa-calendar-alt"></i> Stay Details</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Check-in</div>
                                <div class="detail-value"><?= $checkIn->format('l, d M Y') ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Check-out</div>
                                <div class="detail-value"><?= $checkOut->format('l, d M Y') ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Duration</div>
                                <div class="detail-value"><?= $nights ?> Night<?= $nights > 1 ? 's' : '' ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <span class="payment-badge" style="background:<?= $statusColor ?>;"><?= ucfirst($booking['status']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4><i class="fas fa-bed"></i> Room Details</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Room Type</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['type_name']) ?> Room</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Room Number</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['room_number']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Floor</div>
                                <div class="detail-value"><?= $booking['floor_number'] ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Guests</div>
                                <div class="detail-value"><?= $booking['adults'] ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4><i class="fas fa-user"></i> Guest Information</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Name</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['guest_name']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['email']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Phone</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['phone']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Nationality</div>
                                <div class="detail-value"><?= htmlspecialchars($booking['nationality'] ?: 'Indonesia') ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4><i class="fas fa-receipt"></i> Pricing</h4>
                        <div class="pricing-breakdown">
                            <div class="price-line">
                                <span><?= htmlspecialchars($booking['type_name']) ?> Room × <?= $nights ?> night<?= $nights > 1 ? 's' : '' ?></span>
                                <span><?= formatCurrency($booking['base_price'] * $nights) ?></span>
                            </div>
                            <?php if ($booking['special_request']): ?>
                                <div class="price-line" style="font-size:13px; color:var(--warm-gray);">
                                    <span>Special Request</span>
                                    <span style="font-style:italic;"><?= htmlspecialchars($booking['special_request']) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="price-line total">
                                <span>Total Amount</span>
                                <span><?= formatCurrency($booking['total_price']) ?></span>
                            </div>
                            <div style="margin-top:12px;">
                                <span class="payment-badge" style="background:var(--gold);">
                                    <?= ucfirst($booking['payment_status'] ?? 'unpaid') ?>
                                </span>
                                <span style="font-size:12px; color:var(--mid-gray); margin-left:8px;">Payment at check-in</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="confirmation-actions">
                    <div class="important-info">
                        <h4><i class="fas fa-info-circle"></i> Important Information</h4>
                        <ul>
                            <li>Check-in time: <strong>14:00</strong> · Check-out time: <strong>12:00</strong></li>
                            <li>Please bring a valid ID (KTP/Passport) at check-in.</li>
                            <li>Free cancellation up to 24 hours before arrival.</li>
                            <li>Save your booking code: <strong><?= htmlspecialchars($booking['booking_code']) ?></strong></li>
                        </ul>
                    </div>
                    <div class="action-buttons">
                        <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=Hi%2C%20I%20have%20a%20booking%20with%20code%20<?= $booking['booking_code'] ?>" target="_blank" class="btn btn-primary btn-lg">
                            <i class="fab fa-whatsapp"></i> Contact via WhatsApp
                        </a>
                        <a href="<?= BASE_URL ?>" class="btn btn-outline">Back to Home</a>
                        <button onclick="window.print()" class="btn btn-outline">
                            <i class="fas fa-print"></i> Print Confirmation
                        </button>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Not found -->
            <div class="form-card" style="text-align:center;">
                <div style="font-size:3rem; margin-bottom:16px;">🔍</div>
                <h3>Booking Not Found</h3>
                <p style="color:var(--warm-gray); margin-bottom:24px;">
                    <?php if ($code): ?>
                        No reservation found for code "<strong><?= htmlspecialchars($code) ?></strong>".
                    <?php else: ?>
                        Enter your booking code to view your reservation details.
                    <?php endif; ?>
                </p>
                <form method="GET" style="max-width:400px; margin:0 auto;">
                    <div class="form-group">
                        <input type="text" name="code" class="form-control" placeholder="Enter booking code (e.g., NRY-XXXX)" value="<?= htmlspecialchars($code) ?>" style="text-align:center; text-transform:uppercase;" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Look Up Booking</button>
                </form>
                <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--pale-gray);">
                    <a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>" class="btn btn-outline">Make a New Reservation</a>
                </div>
            </div>
        <?php endif; ?>

    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>