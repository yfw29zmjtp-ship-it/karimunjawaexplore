<?php

/**
 * FRONT DESK - TAMU IN HOUSE
 * Daftar semua tamu yang sedang check-in
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================
// SECURITY & AUTHENTICATION
// ============================================
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Tamu In House';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// ============================================
// GET IN-HOUSE GUESTS
// ============================================
$queryError = null;
$debugInfo = [];
try {
    $today = date('Y-m-d');

    // Test simple query first
    $testQuery = "SELECT COUNT(*) as total FROM bookings WHERE status = 'checked_in'";
    $testResult = $db->fetchOne($testQuery);
    $debugInfo['simple_count'] = $testResult['total'] ?? 0;

    // Test with direct connection
    $conn = $db->getConnection();
    $stmt = $conn->prepare("
        SELECT 
            b.id as booking_id,
            b.booking_code,
            b.check_in_date,
            b.check_out_date,
            b.actual_checkin_time,
            b.room_price,
            b.final_price,
            b.payment_status,
            b.status,
            b.booking_source,
            COALESCE(bp.total_paid, 0) as paid_amount,
            g.id as guest_id,
            g.guest_name,
            g.phone,
            g.email,
            g.id_card_number,
            g.address,
            r.id as room_id,
            r.room_number,
            r.floor_number,
            rt.type_name as type_name,
            rt.base_price,
            DATEDIFF(b.check_out_date, b.check_in_date) as total_nights,
            DATEDIFF(b.check_out_date, CURDATE()) as nights_remaining,
            DATEDIFF(CURDATE(), b.check_in_date) as nights_stayed
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN (
            SELECT booking_id, SUM(amount) as total_paid
            FROM booking_payments
            GROUP BY booking_id
        ) bp ON b.id = bp.booking_id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC
    ");

    $stmt->execute();
    $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $debugInfo['query_result'] = count($inHouseGuests);

    error_log("Direct PDO query returned: " . count($inHouseGuests) . " results");
    if (count($inHouseGuests) > 0) {
        error_log("First result: " . print_r($inHouseGuests[0], true));
    }
} catch (Exception $e) {
    $queryError = $e->getMessage();
    error_log("In House Query Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $inHouseGuests = [];
}

// Get Checkout History (today and yesterday)
$checkoutHistory = [];
try {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $conn->prepare("
        SELECT 
            b.id as booking_id,
            b.booking_code,
            b.check_in_date,
            b.check_out_date,
            b.actual_checkout_time,
            b.final_price,
            b.payment_status,
            g.guest_name,
            g.phone,
            r.room_number,
            rt.type_name
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.status = 'checked_out'
        AND DATE(b.actual_checkout_time) >= :yesterday
        ORDER BY b.actual_checkout_time DESC
        LIMIT 20
    ");
    $stmt->execute(['yesterday' => $yesterday]);
    $checkoutHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Checkout History Error: " . $e->getMessage());
}

// Calculate statistics
$totalInHouse = count($inHouseGuests);
$totalRevenue = array_sum(array_column($inHouseGuests, 'final_price'));
$paidCount = count(array_filter($inHouseGuests, fn($g) => $g['payment_status'] === 'paid'));
$unpaidCount = $totalInHouse - $paidCount;

include '../../includes/header.php';
?>

<style>
    .ih-container {
        max-width: 1400px;
        margin: 0 auto;
    }

    .ih-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .ih-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .ih-subtitle {
        color: var(--text-muted);
        font-size: 0.75rem;
        margin-top: 0.25rem;
    }

    /* Compact Stats */
    .ih-stats {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
    }

    .ih-stat {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        padding: 0.75rem 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 140px;
        flex: 1;
    }

    .ih-stat-icon {
        font-size: 1.25rem;
    }

    .ih-stat-info {
        flex: 1;
    }

    .ih-stat-value {
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.2;
    }

    .ih-stat-label {
        font-size: 0.6rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Compact Guest Cards */
    .ih-guests {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 0.875rem;
        margin-bottom: 2rem;
    }

    .ih-card {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        padding: 0.875rem;
        transition: all 0.2s ease;
        position: relative;
        border-left: 3px solid #6366f1;
    }

    .ih-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .ih-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.6rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px dashed var(--bg-tertiary);
    }

    .ih-booking-code {
        font-size: 0.7rem;
        font-family: 'Courier New', monospace;
        color: #6366f1;
        font-weight: 700;
    }

    .ih-room-badge {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 5px;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .ih-guest-name {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.35rem;
    }

    .ih-info-row {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.72rem;
        color: var(--text-muted);
        margin-bottom: 0.25rem;
    }

    .ih-info-row span:first-child {
        font-size: 0.85rem;
    }

    .ih-payment {
        background: var(--bg-primary);
        border-radius: 6px;
        padding: 0.5rem;
        margin-top: 0.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .ih-payment-label {
        font-size: 0.65rem;
        color: var(--text-muted);
    }

    .ih-payment-value {
        font-size: 0.8rem;
        font-weight: 700;
    }

    .ih-payment-badge {
        padding: 0.15rem 0.4rem;
        border-radius: 4px;
        font-size: 0.6rem;
        font-weight: 700;
        color: white;
    }

    .ih-payment-badge.paid {
        background: #10b981;
    }

    .ih-payment-badge.partial {
        background: #f59e0b;
    }

    .ih-payment-badge.unpaid {
        background: #ef4444;
    }

    .ih-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.65rem;
    }

    .ih-btn {
        flex: 1;
        padding: 0.45rem 0.5rem;
        border: none;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.3rem;
        transition: all 0.2s;
        color: white;
    }

    .ih-btn-breakfast {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .ih-btn-checkout {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }

    .ih-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
    }

    /* History Section */
    .ih-section-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid var(--bg-tertiary);
    }

    .ih-history {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 10px;
        overflow: hidden;
    }

    .ih-history-item {
        display: flex;
        align-items: center;
        padding: 0.6rem 0.875rem;
        border-bottom: 1px solid var(--bg-tertiary);
        gap: 0.75rem;
        font-size: 0.75rem;
    }

    .ih-history-item:last-child {
        border-bottom: none;
    }

    .ih-history-item:hover {
        background: var(--bg-primary);
    }

    .ih-history-room {
        background: var(--bg-tertiary);
        padding: 0.3rem 0.5rem;
        border-radius: 4px;
        font-weight: 700;
        font-size: 0.7rem;
        min-width: 45px;
        text-align: center;
    }

    .ih-history-guest {
        flex: 1;
        font-weight: 600;
        color: var(--text-primary);
    }

    .ih-history-time {
        color: var(--text-muted);
        font-size: 0.68rem;
    }

    .ih-history-price {
        font-weight: 700;
        color: #10b981;
    }

    .ih-empty {
        text-align: center;
        padding: 2rem;
        color: var(--text-muted);
    }

    .ih-empty-icon {
        font-size: 2.5rem;
        margin-bottom: 0.5rem;
    }

    @media (max-width: 768px) {
        .ih-guests {
            grid-template-columns: 1fr;
        }

        .ih-stats {
            flex-direction: column;
        }

        .ih-stat {
            min-width: 100%;
        }
    }
</style>

<div class="ih-container">
    <!-- Header -->
    <div class="ih-header">
        <div>
            <h1>🏨 Tamu In House</h1>
            <p class="ih-subtitle">Daftar tamu yang sedang menginap • <?php echo date('l, d F Y'); ?></p>
        </div>
    </div>

    <!-- Compact Stats -->
    <div class="ih-stats">
        <div class="ih-stat">
            <div class="ih-stat-icon">🏨</div>
            <div class="ih-stat-info">
                <div class="ih-stat-value"><?php echo $totalInHouse; ?></div>
                <div class="ih-stat-label">Total In House</div>
            </div>
        </div>
        <div class="ih-stat">
            <div class="ih-stat-icon">✅</div>
            <div class="ih-stat-info">
                <div class="ih-stat-value"><?php echo $paidCount; ?></div>
                <div class="ih-stat-label">Lunas</div>
            </div>
        </div>
        <div class="ih-stat">
            <div class="ih-stat-icon">⚠️</div>
            <div class="ih-stat-info">
                <div class="ih-stat-value"><?php echo $unpaidCount; ?></div>
                <div class="ih-stat-label">Belum Bayar</div>
            </div>
        </div>
        <div class="ih-stat">
            <div class="ih-stat-icon">💰</div>
            <div class="ih-stat-info">
                <div class="ih-stat-value">Rp <?php echo number_format($totalRevenue / 1000000, 1, ',', '.'); ?>jt</div>
                <div class="ih-stat-label">Revenue</div>
            </div>
        </div>
    </div>

    <!-- In House Guests -->
    <?php if (count($inHouseGuests) > 0): ?>
        <h2 class="ih-section-title">👥 Tamu Menginap (<?php echo $totalInHouse; ?>)</h2>
        <div class="ih-guests">
            <?php foreach ($inHouseGuests as $guest):
                $checkIn = date('d/m', strtotime($guest['check_in_date']));
                $checkOut = date('d/m', strtotime($guest['check_out_date']));
                $totalPrice = number_format($guest['final_price'], 0, ',', '.');
                $paidRaw = $guest['paid_amount'] ?? 0;
                $source = match ($guest['booking_source'] ?? '') {
                    'walk_in' => 'Walk-in',
                    'phone' => 'Phone',
                    'online' => 'Online',
                    default => 'OTA'
                };
                $paymentClass = match ($guest['payment_status']) {
                    'paid' => 'paid',
                    'partial' => 'partial',
                    default => 'unpaid'
                };
                $paymentLabel = match ($guest['payment_status']) {
                    'paid' => 'LUNAS',
                    'partial' => 'CICIL',
                    default => 'PENDING'
                };
            ?>
                <div class="ih-card">
                    <div class="ih-card-header">
                        <div class="ih-booking-code"><?php echo htmlspecialchars($guest['booking_code']); ?></div>
                        <div class="ih-room-badge"><?php echo $guest['room_number']; ?></div>
                    </div>

                    <div class="ih-guest-name"><?php echo htmlspecialchars($guest['guest_name']); ?></div>

                    <div class="ih-info-row">
                        <span>📞</span>
                        <?php echo htmlspecialchars($guest['phone'] ?: '-'); ?>
                    </div>

                    <div class="ih-info-row">
                        <span>📅</span>
                        <?php echo $checkIn; ?> → <?php echo $checkOut; ?> (<?php echo $guest['total_nights']; ?> mlm) • <?php echo $source; ?>
                    </div>

                    <div class="ih-info-row">
                        <span>🏠</span>
                        <?php echo htmlspecialchars($guest['type_name']); ?>
                    </div>

                    <div class="ih-payment">
                        <div>
                            <div class="ih-payment-label">Total Harga</div>
                            <div class="ih-payment-value">Rp <?php echo $totalPrice; ?></div>
                        </div>
                        <span class="ih-payment-badge <?php echo $paymentClass; ?>"><?php echo $paymentLabel; ?></span>
                    </div>

                    <div class="ih-actions">
                        <button class="ih-btn ih-btn-breakfast" onclick="selectBreakfast(<?php echo $guest['booking_id']; ?>, '<?php echo htmlspecialchars($guest['guest_name']); ?>')">
                            🍳 Breakfast
                        </button>
                        <button class="ih-btn ih-btn-checkout" onclick="doCheckOutGuest(<?php echo $guest['booking_id']; ?>, '<?php echo htmlspecialchars($guest['guest_name']); ?>', '<?php echo $guest['room_number']; ?>')">
                            🚪 Check-out
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="ih-empty">
            <div class="ih-empty-icon">🏖️</div>
            <p>Tidak ada tamu in house saat ini</p>
        </div>
    <?php endif; ?>

    <!-- Checkout History -->
    <?php if (count($checkoutHistory) > 0): ?>
        <h2 class="ih-section-title" style="margin-top: 1.5rem;">📋 History Check-out (Hari Ini & Kemarin)</h2>
        <div class="ih-history">
            <?php foreach ($checkoutHistory as $history):
                $coTime = $history['actual_checkout_time'] ? date('d/m H:i', strtotime($history['actual_checkout_time'])) : '-';
            ?>
                <div class="ih-history-item">
                    <div class="ih-history-room"><?php echo $history['room_number']; ?></div>
                    <div class="ih-history-guest"><?php echo htmlspecialchars($history['guest_name']); ?></div>
                    <div class="ih-history-time">CO: <?php echo $coTime; ?></div>
                    <div class="ih-history-price">Rp <?php echo number_format($history['final_price'], 0, ',', '.'); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- CHECKOUT BILLS MODAL -->
<!-- ═══════════════════════════════════════════════════════════ -->
<style>
    .co-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.65);
        z-index: 99999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .co-modal {
        background: white;
        border-radius: 16px;
        padding: 1.5rem;
        width: 100%;
        max-width: 500px;
        max-height: 88vh;
        overflow-y: auto;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.35);
    }

    .co-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.15rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #f1f5f9;
    }

    .co-modal-header h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 800;
        color: #1e293b;
    }

    .co-modal-close {
        background: none;
        border: none;
        font-size: 1.4rem;
        cursor: pointer;
        color: #94a3b8;
        padding: 0;
        line-height: 1;
    }

    .co-modal-close:hover {
        color: #475569;
    }

    .co-bill-section {
        background: #f8fafc;
        border-radius: 10px;
        padding: 0.8rem 0.9rem;
        margin-bottom: 0.75rem;
        border: 1px solid #e2e8f0;
    }

    .co-bill-section.co-bill-alert {
        background: #fff7ed;
        border-color: #fed7aa;
    }

    .co-bill-head {
        font-size: 0.8rem;
        font-weight: 800;
        color: #1e293b;
        margin-bottom: 0.55rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .co-bill-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.82rem;
        margin-bottom: 0.3rem;
        color: #475569;
    }

    .co-bill-row span:last-child {
        font-weight: 600;
        color: #1e293b;
    }

    .co-bill-balance {
        padding-top: 0.4rem;
        margin-top: 0.4rem;
        border-top: 1px solid #e2e8f0;
        font-weight: 700 !important;
    }

    .co-bill-balance.ok span {
        color: #10b981 !important;
    }

    .co-bill-balance.bad span {
        color: #ef4444 !important;
    }

    .co-paid {
        color: #10b981 !important;
    }

    .co-warn {
        color: #f59e0b !important;
    }

    .co-badge-overdue {
        display: inline-block;
        background: #ef4444;
        color: white;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 0.1rem 0.45rem;
        border-radius: 20px;
    }

    .co-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.6rem;
        margin-top: 1.1rem;
        padding-top: 0.85rem;
        border-top: 1px solid #f1f5f9;
    }

    .co-btn {
        padding: 0.55rem 1.25rem;
        border: none;
        border-radius: 9px;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }

    .co-btn-cancel {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #e5e7eb;
    }

    .co-btn-confirm {
        background: #10b981;
        color: white;
    }

    .co-btn-confirm:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .co-block-note {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 0.7rem 0.85rem;
        font-size: 0.8rem;
        color: #b91c1c;
        font-weight: 600;
        margin-top: 0.75rem;
        text-align: center;
    }
</style>

<div id="checkoutBillsModal" class="co-modal-overlay" onclick="if(event.target===this)closeCheckoutBillsModal()" style="display:none;align-items:center;justify-content:center">
    <div class="co-modal">
        <div class="co-modal-header">
            <h3>🚪 Ringkasan Tagihan Check-out</h3>
            <button class="co-modal-close" onclick="closeCheckoutBillsModal()">✕</button>
        </div>
        <div id="coBillsBody"></div>
        <div id="coBillsBlockNote" class="co-block-note" style="display:none">
            ⛔ Check-out tidak bisa dilanjutkan. Selesaikan semua tagihan & kembalikan kendaraan terlebih dahulu.
        </div>
        <div class="co-modal-footer">
            <button class="co-btn co-btn-cancel" onclick="closeCheckoutBillsModal()">Batal</button>
            <button class="co-btn co-btn-confirm" id="coBillsConfirmBtn" onclick="confirmCheckout()">✅ Konfirmasi Check-out</button>
        </div>
    </div>
</div>

<!-- Breakfast Orders Modal -->
<div id="breakfastModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--bg-primary); border-radius: 20px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; padding: 2rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="margin: 0; font-size: 1.5rem; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                🍳 Breakfast Orders
            </h2>
            <button onclick="closeBreakfastModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">✕</button>
        </div>
        <div id="breakfastContent" style="color: var(--text-primary);">
            <div style="text-align: center; padding: 2rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">⏳</div>
                <p>Loading breakfast orders...</p>
            </div>
        </div>
        <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
            <button onclick="closeBreakfastModal()" style="flex: 1; padding: 0.75rem; border: 1px solid var(--bg-tertiary); background: var(--bg-secondary); color: var(--text-primary); border-radius: 10px; font-weight: 600; cursor: pointer;">
                Tutup
            </button>
            <button onclick="addNewBreakfast()" style="flex: 1; padding: 0.75rem; border: none; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; border-radius: 10px; font-weight: 600; cursor: pointer;">
                + Tambah Order
            </button>
        </div>
    </div>
</div>

<script>
    let currentBookingId = null;
    let currentGuestName = null;

    // ── Checkout Bills Modal ────────────────────────────────────────────────────
    let _coBookingId = null,
        _coGuestName = null,
        _coRoomNumber = null,
        _coBtn = null,
        _coBtnOrigHTML = null;

    function doCheckOutGuest(bookingId, guestName, roomNumber) {
        const btn = event.target.closest('.ih-btn-checkout');
        if (!btn) return;

        _coBookingId = bookingId;
        _coGuestName = guestName;
        _coRoomNumber = roomNumber;
        _coBtn = btn;
        _coBtnOrigHTML = btn.innerHTML;

        btn.innerHTML = '⏳ Memuat...';
        btn.disabled = true;

        fetch('<?php echo BASE_URL; ?>/api/get-checkout-bills.php?booking_id=' + bookingId, {
                credentials: 'include'
            })
            .then(r => r.json())
            .then(data => {
                btn.innerHTML = _coBtnOrigHTML;
                btn.disabled = false;
                if (!data.success) {
                    alert('❌ ' + (data.message || 'Gagal memuat tagihan'));
                    return;
                }
                showCheckoutBillsModal(data.bills);
            })
            .catch(err => {
                btn.innerHTML = _coBtnOrigHTML;
                btn.disabled = false;
                alert('❌ Network error: ' + err.message);
            });
    }

    function fmtRp(n) {
        return 'Rp ' + Number(n).toLocaleString('id-ID');
    }

    function fmtDt(s) {
        if (!s) return '-';
        const d = new Date(s);
        return d.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            }) +
            ' ' + d.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit'
            });
    }

    function showCheckoutBillsModal(bills) {
        const modal = document.getElementById('checkoutBillsModal');
        const body = document.getElementById('coBillsBody');
        const confirmBtn = document.getElementById('coBillsConfirmBtn');

        let html = '';
        let hasBlock = false;

        // Room charge
        const r = bills.room;
        const roomOk = r.remaining <= 1000;
        html += `<div class="co-bill-section">
        <div class="co-bill-head">🏨 Tagihan Kamar #${r.room_number}</div>
        <div class="co-bill-row"><span>Total Kamar</span><span>${fmtRp(r.final_price)}</span></div>
        <div class="co-bill-row"><span>Sudah Dibayar</span><span class="co-paid">${fmtRp(r.paid_amount)}</span></div>
        <div class="co-bill-row co-bill-balance ${roomOk ? 'ok' : 'bad'}">
            <span>Sisa Tagihan</span><span>${fmtRp(r.remaining)}</span>
        </div>
    </div>`;
        if (!roomOk) hasBlock = true;

        // Rental motor
        if (bills.motor && bills.motor.length > 0) {
            hasBlock = true;
            html += `<div class="co-bill-section co-bill-alert">
            <div class="co-bill-head">🏍️ Rental Motor — Belum Dikembalikan!</div>`;
            bills.motor.forEach(m => {
                html += `<div class="co-bill-row"><span>${m.label}</span><span class="co-badge-overdue">${m.status.toUpperCase()}</span></div>
            <div class="co-bill-row"><span>Mulai</span><span>${fmtDt(m.start)}</span></div>
            <div class="co-bill-row"><span>Estimasi Tagihan</span><span class="co-warn">${fmtRp(m.est_price)}</span></div>`;
            });
            html += `<div style="font-size:0.75rem;color:#b45309;margin-top:0.4rem">⚠ Kembalikan motor di menu Rental Motor terlebih dahulu</div></div>`;
        }

        // Rental car
        if (bills.car && bills.car.length > 0) {
            hasBlock = true;
            html += `<div class="co-bill-section co-bill-alert">
            <div class="co-bill-head">🚗 Rental Mobil/Taxi — Belum Dikembalikan!</div>`;
            bills.car.forEach(c => {
                html += `<div class="co-bill-row"><span>${c.label}</span><span class="co-badge-overdue">${c.status.toUpperCase()}</span></div>
            <div class="co-bill-row"><span>Mulai</span><span>${fmtDt(c.start)}</span></div>
            <div class="co-bill-row"><span>Estimasi Tagihan</span><span class="co-warn">${fmtRp(c.est_price)}</span></div>`;
            });
            html += `<div style="font-size:0.75rem;color:#b45309;margin-top:0.4rem">⚠ Kembalikan kendaraan di menu Rental Mobil terlebih dahulu</div></div>`;
        }

        // Unpaid hotel invoices
        if (bills.invoices && bills.invoices.length > 0) {
            hasBlock = true;
            html += `<div class="co-bill-section co-bill-alert">
            <div class="co-bill-head">📄 Invoice Hotel Service Belum Lunas</div>`;
            bills.invoices.forEach(inv => {
                html += `<div style="margin:0.6rem 0;border-left:3px solid #f59e0b;padding-left:0.6rem">
                    <div class="co-bill-row" style="margin-bottom:0.3rem"><strong>${inv.invoice_number}</strong><span class="co-warn">${fmtRp(inv.remaining)} sisa</span></div>`;

                // Show invoice items if available
                if (inv.items && inv.items.length > 0) {
                    inv.items.forEach(item => {
                        let icon = '🔹';
                        if (item.service_type.includes('motor')) icon = '🏍️';
                        else if (item.service_type.includes('car')) icon = '🚗';
                        else if (item.service_type === 'laundry') icon = '👕';
                        html += `<div style="font-size:0.75rem;color:#666;margin:0.2rem 0">
                            ${icon} ${item.description || item.service_type}: <strong>${fmtRp(item.total_price)}</strong>
                        </div>`;
                    });
                }
                html += `</div>`;
            });
            html += `</div>`;
        }

        body.innerHTML = html;

        if (hasBlock) {
            confirmBtn.style.display = 'none';
            document.getElementById('coBillsBlockNote').style.display = 'block';
        } else {
            confirmBtn.style.display = 'inline-flex';
            document.getElementById('coBillsBlockNote').style.display = 'none';
        }

        modal.style.display = 'flex';
    }

    function closeCheckoutBillsModal() {
        document.getElementById('checkoutBillsModal').style.display = 'none';
    }

    function confirmCheckout() {
        const confirmBtn = document.getElementById('coBillsConfirmBtn');
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '⏳ Processing...';

        fetch('<?php echo BASE_URL; ?>/api/checkout-guest.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: 'booking_id=' + _coBookingId
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    closeCheckoutBillsModal();
                    alert('✅ ' + data.message);
                    window.location.reload();
                } else {
                    alert('❌ ' + data.message);
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '✅ Konfirmasi Check-out';
                }
            })
            .catch(err => {
                alert('❌ Network error: ' + err.message);
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '✅ Konfirmasi Check-out';
            });
    }

    // Select Breakfast - Show Modal with Orders
    function selectBreakfast(bookingId, guestName) {
        currentBookingId = bookingId;
        currentGuestName = guestName;

        // Show modal
        document.getElementById('breakfastModal').style.display = 'flex';

        // Fetch breakfast orders
        fetch('<?php echo BASE_URL; ?>/api/get-breakfast-orders.php?booking_id=' + bookingId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayBreakfastOrders(data.orders, guestName);
                } else {
                    document.getElementById('breakfastContent').innerHTML = `
                    <div style="text-align: center; padding: 2rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">❌</div>
                        <p>Error: ${data.message}</p>
                    </div>
                `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('breakfastContent').innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">⚠️</div>
                    <p>Gagal memuat data breakfast orders</p>
                </div>
            `;
            });
    }

    function displayBreakfastOrders(orders, guestName) {
        const content = document.getElementById('breakfastContent');

        if (orders.length === 0) {
            content.innerHTML = `
            <div style="text-align: center; padding: 2rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🍽️</div>
                <p style="color: var(--text-secondary);">${guestName} belum memiliki breakfast order</p>
            </div>
        `;
            return;
        }

        let html = `<div style="margin-bottom: 1rem;"><strong>${guestName}</strong></div>`;

        orders.forEach(order => {
            const orderDate = new Date(order.breakfast_date + ' ' + order.breakfast_time);
            const formattedDate = orderDate.toLocaleDateString('id-ID', {
                day: 'numeric',
                month: 'short'
            });
            const formattedTime = orderDate.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit'
            });

            html += `
            <div style="background: var(--bg-secondary); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; border: 1px solid var(--bg-tertiary);">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem;">
                    <div>
                        <div style="font-weight: 600; color: var(--primary);">${formattedDate} ${formattedTime}</div>
                        <div style="font-size: 0.9rem; color: var(--text-secondary);">Room ${(() => { try { const r = JSON.parse(order.room_number); return Array.isArray(r) ? r.join(', ') : order.room_number; } catch(e) { return order.room_number || '-'; } })()} • ${order.total_pax} pax</div>
                    </div>
                    <span style="padding: 0.25rem 0.75rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; ${order.location === 'restaurant' ? 'background: rgba(99, 102, 241, 0.2); color: #6366f1;' : 'background: rgba(139, 92, 246, 0.2); color: #8b5cf6;'}">
                        ${order.location === 'restaurant' ? '🍽️ Restaurant' : '🚪 Room'}
                    </span>
                </div>
                <div style="padding-left: 0.5rem;">
                    <strong style="font-size: 0.9rem;">Menu:</strong>
                    <ul style="margin: 0.5rem 0 0 0; padding-left: 1.5rem;">
        `;

            order.menu_items.forEach(item => {
                html += `<li style="margin: 0.25rem 0;"><span style="color: var(--primary); font-weight: 600;">x${item.quantity}</span> ${item.menu_name}</li>`;
            });

            html += `
                    </ul>
                </div>
            </div>
        `;
        });

        content.innerHTML = html;
    }

    function closeBreakfastModal() {
        document.getElementById('breakfastModal').style.display = 'none';
        currentBookingId = null;
        currentGuestName = null;
    }

    function addNewBreakfast() {
        window.location.href = 'breakfast.php?booking_id=' + currentBookingId;
    }

    // Close modal when clicking outside
    document.getElementById('breakfastModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeBreakfastModal();
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>