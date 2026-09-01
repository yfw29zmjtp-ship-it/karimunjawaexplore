<?php

/**
 * FRONT DESK - RESERVASI MANAGEMENT
 * Booking Management dengan Direct/OTA + Fee Calculation
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

$pageTitle = 'Reservasi Management - Direct/OTA Bookings';

// ============================================
// OTA CONFIGURATION - Load from Database
// ============================================
$otaProviders = [];
try {
    $otaRows = $db->fetchAll("SELECT source_key, source_name, fee_percent, icon FROM booking_sources WHERE is_active = 1 ORDER BY sort_order ASC");
    if ($otaRows) {
        foreach ($otaRows as $row) {
            $otaProviders[$row['source_key']] = [
                'name' => $row['source_name'],
                'fee'  => (float)$row['fee_percent'],
                'icon' => $row['icon'] ?? '🌐'
            ];
        }
    }
} catch (Exception $e) {
    error_log("Load booking_sources error: " . $e->getMessage());
}
// Fallback defaults if DB query fails or table doesn't exist
if (empty($otaProviders)) {
    $otaProviders = [
        'walk_in' => ['name' => 'Walk-in', 'fee' => 0, 'icon' => '🚶'],
        'phone' => ['name' => 'Phone Booking', 'fee' => 0, 'icon' => '📞'],
        'online' => ['name' => 'Direct Online', 'fee' => 0, 'icon' => '💻'],
        'agoda' => ['name' => 'Agoda', 'fee' => 15, 'icon' => '🏨'],
        'booking' => ['name' => 'Booking.com', 'fee' => 12, 'icon' => '📱'],
        'tiket' => ['name' => 'Tiket.com', 'fee' => 10, 'icon' => '✈️'],
        'traveloka' => ['name' => 'Traveloka', 'fee' => 15, 'icon' => '🎫'],
        'airbnb' => ['name' => 'Airbnb', 'fee' => 3, 'icon' => '🏠'],
        'expedia' => ['name' => 'Expedia', 'fee' => 15, 'icon' => '🗺️'],
        'pegipegi' => ['name' => 'PegiPegi', 'fee' => 10, 'icon' => '🧳'],
        'ota' => ['name' => 'OTA Lainnya', 'fee' => 10, 'icon' => '🌐'],
    ];
}

// ============================================
// GET BOOKINGS LIST
// ============================================
// Ensure group_id column exists
$hasGroupId = $db->fetchOne("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'group_id'");
if (!$hasGroupId) {
    $db->query("ALTER TABLE bookings ADD COLUMN group_id VARCHAR(50) DEFAULT NULL");
    $db->query("CREATE INDEX idx_bookings_group_id ON bookings(group_id)");
}

try {
    // Auto-checkout: set status to 'checked_out' for bookings past their check-out date
    $db->query(
        "UPDATE bookings SET status = 'checked_out' WHERE status IN ('checked_in', 'confirmed') AND check_out_date < CURDATE()"
    );

    $status_filter = $_GET['status'] ?? 'all';

    $query = "
        SELECT 
            b.id, b.booking_code, b.group_id, b.check_in_date, b.check_out_date,
            b.room_price, b.total_price, b.final_price, b.discount,
            b.status, b.payment_status, b.booking_source, b.total_nights,
            b.paid_amount, b.special_request, b.adults, b.children,
            g.guest_name, g.phone, g.email,
            r.room_number,
            rt.type_name,
            COALESCE(SUM(bp.amount), b.paid_amount, 0) as total_paid
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN booking_payments bp ON b.id = bp.booking_id
        WHERE 1=1
    ";

    $params = [];

    if ($status_filter !== 'all') {
        $query .= " AND b.status = ?";
        $params[] = $status_filter;
    }

    $query .= " GROUP BY b.id
    ORDER BY 
        CASE 
            WHEN b.status = 'checked_in' THEN 1
            WHEN b.status = 'confirmed' THEN 2
            WHEN b.status = 'pending' THEN 3
            WHEN b.status = 'checked_out' THEN 4
            ELSE 5
        END,
        ABS(DATEDIFF(b.check_in_date, CURDATE())) ASC,
        b.check_in_date ASC,
        b.group_id ASC,
        b.id ASC
    LIMIT 100";

    $rawBookings = $db->fetchAll($query, $params);

    // Group bookings by group_id
    $bookings = [];
    $groupedBookings = [];
    foreach ($rawBookings as $bk) {
        $gid = $bk['group_id'] ?? null;
        if ($gid) {
            if (!isset($groupedBookings[$gid])) {
                $groupedBookings[$gid] = $bk; // first booking as base
                $groupedBookings[$gid]['_rooms'] = [];
                $groupedBookings[$gid]['_booking_ids'] = [];
                $groupedBookings[$gid]['_combined_final_price'] = 0;
                $groupedBookings[$gid]['_combined_total_paid'] = 0;
                $groupedBookings[$gid]['_combined_total_price'] = 0;
                $groupedBookings[$gid]['_combined_discount'] = 0;
            }
            $groupedBookings[$gid]['_rooms'][] = [
                'room_number' => $bk['room_number'],
                'type_name' => $bk['type_name'],
                'room_price' => $bk['room_price'],
                'final_price' => $bk['final_price'],
                'booking_id' => $bk['id'],
                'booking_code' => $bk['booking_code'],
                'status' => $bk['status'],
            ];
            $groupedBookings[$gid]['_booking_ids'][] = $bk['id'];
            $groupedBookings[$gid]['_combined_final_price'] += $bk['final_price'];
            $groupedBookings[$gid]['_combined_total_paid'] += $bk['total_paid'];
            $groupedBookings[$gid]['_combined_total_price'] += $bk['total_price'];
            $groupedBookings[$gid]['_combined_discount'] += $bk['discount'];
        } else {
            // Single room booking
            $bk['_rooms'] = [[
                'room_number' => $bk['room_number'],
                'type_name' => $bk['type_name'],
                'room_price' => $bk['room_price'],
                'final_price' => $bk['final_price'],
                'booking_id' => $bk['id'],
                'booking_code' => $bk['booking_code'],
                'status' => $bk['status'],
            ]];
            $bk['_booking_ids'] = [$bk['id']];
            $bk['_combined_final_price'] = $bk['final_price'];
            $bk['_combined_total_paid'] = $bk['total_paid'];
            $bk['_combined_total_price'] = $bk['total_price'];
            $bk['_combined_discount'] = $bk['discount'];
            $bookings[] = $bk;
        }
    }
    // Merge grouped bookings into bookings list
    foreach ($groupedBookings as $gBk) {
        // Override display fields with combined values
        $gBk['final_price'] = $gBk['_combined_final_price'];
        $gBk['total_paid'] = $gBk['_combined_total_paid'];
        $gBk['total_price'] = $gBk['_combined_total_price'];
        $gBk['discount'] = $gBk['_combined_discount'];
        $gBk['room_number'] = implode(', ', array_column($gBk['_rooms'], 'room_number'));
        $gBk['type_name'] = count(array_unique(array_column($gBk['_rooms'], 'type_name'))) === 1
            ? $gBk['_rooms'][0]['type_name']
            : implode(', ', array_unique(array_column($gBk['_rooms'], 'type_name')));
        $gBk['booking_code'] = $gBk['_rooms'][0]['booking_code'];
        $bookings[] = $gBk;
    }
    // Re-sort by status priority + check-in date
    usort($bookings, function ($a, $b) {
        $statusOrder = ['checked_in' => 1, 'confirmed' => 2, 'pending' => 3, 'checked_out' => 4, 'cancelled' => 5];
        $aOrder = $statusOrder[$a['status']] ?? 4;
        $bOrder = $statusOrder[$b['status']] ?? 4;
        if ($aOrder !== $bOrder) return $aOrder - $bOrder;
        $today = date('Y-m-d');
        $aDiff = abs(strtotime($a['check_in_date']) - strtotime($today));
        $bDiff = abs(strtotime($b['check_in_date']) - strtotime($today));
        return $aDiff - $bDiff;
    });
} catch (Exception $e) {
    error_log("Reservasi List Error: " . $e->getMessage());
    $bookings = [];
}

// ============================================
// GET ALL AVAILABLE ROOMS (For Multi-Room Booking)
// ============================================
try {
    $rooms = $db->fetchAll("
        SELECT r.id, r.room_number, r.floor_number, r.status, rt.type_name, rt.base_price, rt.color_code
        FROM rooms r
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE r.status != 'maintenance'
        ORDER BY rt.type_name ASC, r.floor_number ASC, r.room_number ASC
    ", []);
} catch (Exception $e) {
    error_log("Rooms Error: " . $e->getMessage());
    $rooms = [];
}

// ============================================
// CALCULATE OTA FEE & NET INCOME
// ============================================
function calculateNetIncome($roomPrice, $otaProvider, $otaProviders)
{
    $feePercent = $otaProviders[$otaProvider]['fee'] ?? 0;
    $feeAmount = ($roomPrice * $feePercent) / 100;
    $netIncome = $roomPrice - $feeAmount;

    return [
        'gross' => $roomPrice,
        'fee_percent' => $feePercent,
        'fee_amount' => $feeAmount,
        'net' => $netIncome
    ];
}

include '../../includes/header.php';
?>

<style>
    /* SIMPLE & ELEGANT DESIGN */

    .reservasi-container {
        max-width: 1600px;
        margin: 0 auto;
        padding: 1rem;
    }

    .reservasi-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        gap: 1rem;
    }

    .reservasi-header h1 {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        color: var(--text-primary);
    }

    .header-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .btn-primary {
        background: #6366f1;
        color: white;
        padding: 0.4rem 0.85rem;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 0.8rem;
    }

    .btn-primary:hover {
        background: #4f46e5;
        transform: translateY(-1px);
    }

    .filter-section {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
    }

    .filter-group label {
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-secondary);
    }

    .filter-group select,
    .filter-group input {
        padding: 0.5rem 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 4px;
        font-size: 0.85rem;
        background: var(--bg-secondary);
        color: var(--text-primary);
    }

    /* Bookings Table */
    .bookings-table-wrapper {
        overflow-x: auto;
    }

    .bookings-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.75rem;
    }

    .bookings-table thead {
        border-bottom: 2px solid var(--border-color);
        background: var(--bg-secondary);
    }

    .bookings-table th {
        padding: 0.5rem 0.65rem;
        text-align: left;
        font-weight: 700;
        color: var(--text-primary);
        text-transform: uppercase;
        font-size: 0.7rem;
        letter-spacing: 0.3px;
    }

    .bookings-table td {
        padding: 0.5rem 0.65rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .bookings-table tbody tr {
        transition: background 0.2s ease;
    }

    .bookings-table tbody tr:hover {
        background: var(--bg-secondary);
    }

    /* Badge */
    .badge {
        display: inline-block;
        padding: 0.25rem 0.6rem;
        border-radius: 4px;
        font-weight: 600;
        font-size: 0.7rem;
        text-transform: uppercase;
    }

    .badge-confirmed {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }

    .badge-pending {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
    }

    .badge-checked-in {
        background: rgba(59, 130, 246, 0.2);
        color: #3b82f6;
    }

    .badge-paid {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }

    .badge-unpaid {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
    }

    .badge-cancelled {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
    }

    /* Action Dropdown */
    .action-dropdown {
        position: relative;
        display: inline-block;
    }

    .action-dropdown-btn {
        padding: 0.4rem 0.8rem;
        background: #6366f1;
        color: white;
        border: 1px solid #4f46e5;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.75rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.3rem;
        white-space: nowrap;
        transition: background 0.15s;
    }

    .action-dropdown-btn:hover {
        background: #4f46e5;
    }

    .action-dropdown-menu {
        display: none;
        position: fixed;
        background: var(--card-bg, #fff);
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        min-width: 160px;
        z-index: 9999;
        overflow: hidden;
    }

    .action-dropdown.open .action-dropdown-menu {
        display: block;
    }

    .action-dropdown-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        width: 100%;
        padding: 0.55rem 0.85rem;
        border: none;
        background: none;
        cursor: pointer;
        font-size: 0.78rem;
        color: var(--text-primary, #1e293b);
        text-align: left;
        transition: background 0.1s;
        white-space: nowrap;
    }

    .action-dropdown-item:hover {
        background: rgba(99, 102, 241, 0.08);
    }

    .action-dropdown-item.item-checkin {
        color: #16a34a;
        font-weight: 600;
    }

    .action-dropdown-item.item-pay {
        color: #ca8a04;
        font-weight: 600;
    }

    .action-dropdown-item.item-cancel {
        color: #ea580c;
    }

    .action-dropdown-item.item-delete {
        color: #dc2626;
    }

    .action-dropdown-divider {
        height: 1px;
        background: rgba(0, 0, 0, 0.08);
        margin: 2px 0;
    }

    .empty-state {
        text-align: center;
        padding: 3rem;
        color: var(--text-secondary);
    }

    .room-badge {
        display: inline-block;
        background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-weight: 600;
        font-size: 0.7rem;
        color: #ffffff !important;
    }

    .ota-badge {
        display: inline-block;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-weight: 600;
        font-size: 0.7rem;
        background: #dbeafe;
        color: #1e40af;
    }

    .ota-badge .fee {
        color: #dc2626;
        font-weight: 700;
        margin-left: 0.25rem;
    }

    .price-breakdown {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }

    .price-item {
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .price-gross {
        color: var(--text-secondary);
    }

    .price-fee {
        color: #dc2626;
    }

    .price-net {
        font-weight: 700;
        color: #059669;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .reservasi-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .bookings-table {
            font-size: 0.7rem;
        }

        .bookings-table th,
        .bookings-table td {
            padding: 0.5rem;
        }
    }

    /* Cancel Modal Styles */
    .cancel-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }

    .cancel-modal-overlay.active {
        display: flex;
    }

    .cancel-modal {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 480px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        overflow: hidden;
    }

    .cancel-modal-header {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
        padding: 1.2rem;
        font-weight: 700;
        font-size: 1.1rem;
    }

    .cancel-modal-body {
        padding: 1.5rem;
    }

    .cancel-booking-info {
        background: #f8fafc;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .cancel-booking-info h4 {
        margin: 0 0 0.5rem 0;
        color: #1e293b;
    }

    .cancel-booking-info p {
        margin: 0.3rem 0;
        color: #64748b;
        font-size: 0.9rem;
    }

    .refund-policy-box {
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        padding: 1rem;
        margin: 1rem 0;
        text-align: center;
    }

    .refund-policy-label {
        font-size: 0.85rem;
        color: #64748b;
        margin-bottom: 0.3rem;
    }

    .refund-policy-value {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .refund-amount-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e2e8f0;
    }

    .refund-amount-row:last-child {
        border-bottom: none;
        font-weight: 700;
    }

    .refund-amount-row.refund {
        color: #10b981;
    }

    .refund-amount-row.forfeit {
        color: #ef4444;
    }

    .refund-amount-row.manual-note {
        color: #64748b;
        font-style: italic;
        border-bottom: 2px dashed #d1d5db;
    }

    .cancel-modal-actions {
        display: flex;
        gap: 0.5rem;
        padding: 1rem 1.5rem;
        background: #f8fafc;
        justify-content: flex-end;
    }

    .cancel-modal-actions button {
        padding: 0.7rem 1.5rem;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        border: none;
    }

    .btn-cancel-modal {
        background: #e2e8f0;
        color: #475569;
    }

    .btn-cancel-modal:hover {
        background: #cbd5e1;
    }

    .btn-confirm-cancel {
        background: #ef4444;
        color: white;
    }

    .btn-confirm-cancel:hover {
        background: #dc2626;
    }
</style>

<div class="reservasi-container">
    <!-- Header -->
    <div class="reservasi-header">
        <div>
            <h1>Reservasi Management</h1>
        </div>
        <div class="header-actions">
            <button class="btn-primary" onclick="openNewBookingModal()">
                New Booking
            </button>
            <button class="btn-primary" onclick="window.location='calendar.php'">
                Calendar View
            </button>
            <button class="btn-primary" onclick="window.location='breakfast.php'">
                Breakfast List
            </button>
            <button class="btn-primary" onclick="window.location='settings.php'">
                Settings
            </button>
            <button class="btn-primary btn-secondary" onclick="window.location='dashboard.php'">
                Dashboard
            </button>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-group">
            <label>Cari Tamu</label>
            <input type="text" id="searchGuest" placeholder="Nama tamu, kode booking, no kamar..." oninput="searchBookings(this.value)" style="min-width: 280px;">
        </div>
        <div class="filter-group">
            <label>Status Filter</label>
            <select onchange="filterBookings(this.value)">
                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                <option value="checked_in" <?php echo $status_filter === 'checked_in' ? 'selected' : ''; ?>>Checked In</option>
                <option value="checked_out" <?php echo $status_filter === 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="bookings-table-wrapper">
        <?php if (!empty($bookings)): ?>
            <div id="bulkDeleteBar" style="display:none; padding: 10px 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; margin-bottom: 10px; align-items: center; gap: 10px;">
                <span id="selectedCount" style="font-weight: 600; color: #856404;">0 dipilih</span>
                <button type="button" onclick="bulkDeleteBookings()" style="background: #dc3545; color: white; border: none; padding: 6px 16px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">🗑️ Hapus Terpilih</button>
                <button type="button" onclick="clearSelection()" style="background: #6c757d; color: white; border: none; padding: 6px 16px; border-radius: 6px; cursor: pointer; font-size: 0.85rem;">Batal</button>
            </div>
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="Pilih Semua"></th>
                        <th>Booking Code</th>
                        <th>Guest Name</th>
                        <th>Room</th>
                        <th>Check-in / Check-out</th>
                        <th>Nights</th>
                        <th>OTA Source</th>
                        <th>Price Breakdown</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking):
                        $netIncome = calculateNetIncome(
                            $booking['room_price'],
                            $booking['booking_source'],
                            $otaProviders
                        );
                        $otaIcon = $otaProviders[$booking['booking_source']]['icon'] ?? '🌐';
                        $otaName = $otaProviders[$booking['booking_source']]['name'] ?? 'Other';
                        $otaFee = $otaProviders[$booking['booking_source']]['fee'] ?? 0;
                        $roomCount = count($booking['_rooms'] ?? []);
                        $isGrouped = $roomCount > 1;
                    ?>
                        <tr>
                            <!-- Checkbox -->
                            <td style="text-align: center;">
                                <input type="checkbox" class="booking-checkbox" value="<?php echo implode(',', $booking['_booking_ids']); ?>" onchange="updateBulkBar()">
                            </td>
                            <!-- Booking Code -->
                            <td>
                                <strong><?php echo htmlspecialchars($booking['booking_code']); ?></strong>
                                <?php if ($isGrouped): ?>
                                    <div style="font-size: 0.65rem; color: #6366f1; margin-top: 2px;">📦 <?php echo $roomCount; ?> rooms</div>
                                <?php endif; ?>
                            </td>

                            <!-- Guest -->
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($booking['guest_name']); ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">
                                        <?php echo htmlspecialchars($booking['phone'] ?? '-'); ?>
                                    </div>
                                </div>
                            </td>

                            <!-- Room -->
                            <td>
                                <?php if ($isGrouped): ?>
                                    <?php foreach ($booking['_rooms'] as $rm): ?>
                                        <span class="room-badge" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;padding:0.15rem 0.4rem;border-radius:4px;font-weight:600;font-size:0.75rem;display:inline-block;margin:1px;">
                                            <?php echo htmlspecialchars($rm['room_number']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <div style="font-size: 0.7rem; margin-top: 0.25rem;">
                                        <?php echo htmlspecialchars($booking['type_name']); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="room-badge" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;padding:0.2rem 0.5rem;border-radius:4px;font-weight:600;">
                                        <?php echo htmlspecialchars($booking['room_number']); ?>
                                    </span>
                                    <div style="font-size: 0.7rem; margin-top: 0.25rem;">
                                        <?php echo htmlspecialchars($booking['type_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Dates -->
                            <td>
                                <div style="font-size: 0.75rem;">
                                    <?php echo date('d M', strtotime($booking['check_in_date'])); ?> -
                                    <?php echo date('d M', strtotime($booking['check_out_date'])); ?>
                                </div>
                            </td>

                            <!-- Nights -->
                            <td>
                                <strong><?php echo $booking['total_nights']; ?></strong>
                            </td>

                            <!-- OTA Source -->
                            <td>
                                <span class="ota-badge">
                                    <?php echo $otaName; ?>
                                    <?php if ($otaFee > 0): ?>
                                        <span class="fee">-<?php echo $otaFee; ?>%</span>
                                    <?php endif; ?>
                                </span>
                            </td>

                            <!-- Price Breakdown -->
                            <td>
                                <?php if ($isGrouped): ?>
                                    <div class="price-breakdown" style="font-size: 0.75rem;">
                                        <div class="price-item price-gross">
                                            <span>Total:</span>
                                            <span>Rp <?php echo number_format($booking['_combined_total_price'], 0, ',', '.'); ?></span>
                                        </div>
                                        <?php if ($booking['_combined_discount'] > 0): ?>
                                            <div class="price-item price-fee">
                                                <span>Disc:</span>
                                                <span>-Rp <?php echo number_format($booking['_combined_discount'], 0, ',', '.'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($otaFee > 0): ?>
                                            <div class="price-item price-fee">
                                                <span>Fee (<?php echo $otaFee; ?>%):</span>
                                                <span>-Rp <?php echo number_format(round($booking['_combined_total_price'] * $otaFee / 100), 0, ',', '.'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="price-item price-net">
                                            <span>Net:</span>
                                            <span>Rp <?php echo number_format($booking['_combined_final_price'], 0, ',', '.'); ?></span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="price-breakdown" style="font-size: 0.75rem;">
                                        <div class="price-item price-gross">
                                            <span>Gross:</span>
                                            <span>Rp <?php echo number_format($netIncome['gross'], 0, ',', '.'); ?></span>
                                        </div>
                                        <?php if ($netIncome['fee_percent'] > 0): ?>
                                            <div class="price-item price-fee">
                                                <span>Fee (<?php echo $netIncome['fee_percent']; ?>%):</span>
                                                <span>-Rp <?php echo number_format($netIncome['fee_amount'], 0, ',', '.'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="price-item price-net">
                                            <span>Net:</span>
                                            <span>Rp <?php echo number_format($netIncome['net'], 0, ',', '.'); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Status -->
                            <td>
                                <span class="badge badge-status badge-<?php echo str_replace('_', '-', $booking['status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $booking['status'])); ?>
                                </span>
                            </td>

                            <!-- Payment Status -->
                            <td>
                                <?php
                                $cFinal = $booking['_combined_final_price'];
                                $cPaid = $booking['_combined_total_paid'];
                                $cRemaining = $cFinal - $cPaid;
                                $cPayStatus = 'unpaid';
                                $cPayLabel = 'Belum Bayar';
                                if ($cPaid >= $cFinal) {
                                    $cPayStatus = 'paid';
                                    $cPayLabel = 'Lunas';
                                } elseif ($cPaid > 0) {
                                    $cPayStatus = 'partial';
                                    $cPayLabel = 'DP';
                                }
                                ?>
                                <span class="badge badge-payment-<?php echo str_replace('_', '-', $cPayStatus); ?>">
                                    <?php echo $cPayLabel; ?>
                                </span>
                                <div style="font-size: 0.7rem; margin-top: 0.3rem; line-height: 1.4;">
                                    <div><span style="color: var(--text-secondary);">Total:</span> <strong>Rp <?php echo number_format($cFinal, 0, ',', '.'); ?></strong></div>
                                    <div><span style="color: var(--text-secondary);">Bayar:</span> <strong style="color: #10b981;">Rp <?php echo number_format($cPaid, 0, ',', '.'); ?></strong></div>
                                    <?php if ($cRemaining > 0): ?>
                                        <div><span style="color: var(--text-secondary);">Sisa:</span> <strong style="color: #ef4444;">Rp <?php echo number_format($cRemaining, 0, ',', '.'); ?></strong></div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Actions -->
                            <td>
                                <?php $remaining = $cRemaining; ?>
                                <div class="action-dropdown">
                                    <button type="button" class="action-dropdown-btn" onclick="toggleActionMenu(event)">Aksi ▾</button>
                                    <div class="action-dropdown-menu">
                                        <?php if ($booking['status'] === 'confirmed'): ?>
                                            <?php if ($isGrouped): ?>
                                                <?php foreach ($booking['_rooms'] as $rm): ?>
                                                    <button class="action-dropdown-item item-checkin" onclick="checkinBooking(<?php echo $rm['booking_id']; ?>, '<?php echo htmlspecialchars($rm['booking_code']); ?>')">✅ Check-in Room <?php echo htmlspecialchars($rm['room_number']); ?></button>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <button class="action-dropdown-item item-checkin" onclick="checkinBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_code']); ?>')">✅ Check-in</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($remaining > 0 && $cPayStatus !== 'paid' && $booking['status'] !== 'cancelled' && $booking['status'] !== 'checked_out'): ?>
                                            <button class="action-dropdown-item item-pay" onclick="addPayment(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_code']); ?>', <?php echo $remaining; ?>, '<?php echo htmlspecialchars($booking['booking_source']); ?>', <?php echo $otaFee; ?>, '<?php echo addslashes($otaName); ?>')">💰 Bayar</button>
                                        <?php endif; ?>
                                        <div class="action-dropdown-divider"></div>
                                        <button class="action-dropdown-item" onclick="savePDF(<?php echo $booking['id']; ?>)">📥 Save PDF</button>
                                        <button class="action-dropdown-item" onclick="editBooking(<?php echo $booking['id']; ?>)">✏️ Edit</button>
                                        <?php if ($booking['status'] !== 'checked_in' && $booking['status'] !== 'checked_out'): ?>
                                            <div class="action-dropdown-divider"></div>
                                            <?php if ($isGrouped): ?>
                                                <?php foreach ($booking['_rooms'] as $rm): ?>
                                                    <button class="action-dropdown-item item-cancel" onclick="cancelBooking(<?php echo $rm['booking_id']; ?>, '<?php echo htmlspecialchars($rm['booking_code']); ?>')">⚠️ Cancel Room <?php echo htmlspecialchars($rm['room_number']); ?></button>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <button class="action-dropdown-item item-cancel" onclick="cancelBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_code']); ?>')">⚠️ Cancel</button>
                                                <button class="action-dropdown-item item-delete" onclick="deleteBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_code']); ?>')">🗑️ Hapus</button>
                                            <?php endif; ?>
                                        <?php elseif ($currentUser['role'] === 'developer'): ?>
                                            <div class="action-dropdown-divider"></div>
                                            <button class="action-dropdown-item item-delete" onclick="deleteBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_code']); ?>')">🗑️ Hapus</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <p style="font-size: 1.1rem;">Tidak ada reservasi</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- NEW BOOKING MODAL -->
<div id="newBookingModal" class="modal-overlay" style="display: none;">
    <div class="modal-compact-booking">
        <div class="modal-header-compact">
            <h2>New Reservation - Multiple Rooms</h2>
            <button type="button" class="close-btn" onclick="closeNewBookingModal()">&times;</button>
        </div>

        <form id="newBookingForm" onsubmit="submitMultiRoomBooking(event)">
            <div class="form-compact">
                <!-- GUEST INFO -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Guest Name*</label>
                        <input type="text" id="guestName" name="guest_name" required placeholder="Full name">
                    </div>
                    <div class="input-compact">
                        <label>Phone</label>
                        <input type="text" id="guestPhone" name="guest_phone" placeholder="Phone/WA">
                    </div>
                </div>

                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Asal Negara</label>
                        <input type="text" id="guestNationality" name="guest_nationality" value="Indonesia" placeholder="Contoh: Indonesia, Australia, Germany">
                    </div>
                    <div class="input-compact">
                        <label>Email</label>
                        <input type="email" id="guestEmail" name="guest_email" placeholder="guest@email.com">
                    </div>
                </div>

                <!-- DATES -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Check In*</label>
                        <input type="date" id="checkInDate" name="check_in_date" required onchange="loadAvailableRooms()">
                    </div>
                    <div class="input-compact">
                        <label>Check Out*</label>
                        <input type="date" id="checkOutDate" name="check_out_date" required onchange="loadAvailableRooms()">
                    </div>
                </div>

                <!-- ROOMS SELECTION (MULTI SELECT) -->
                <div class="input-compact">
                    <label>Select Rooms* (dapat pilih lebih dari 1)</label>
                    <div id="availabilityInfo" style="margin-bottom: 8px; font-size: 0.85rem;"></div>
                    <div id="roomsChecklistContainer" class="rooms-checklist" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px; background: #f9f9f9;">
                        <em style="color: #888;">Loading rooms...</em>
                    </div>
                    <div id="selectedRoomsSummary" style="margin-top: 8px; font-size: 0.85rem; color: #6366f1;"></div>
                </div>

                <!-- SOURCE & PAYMENT METHOD -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Booking Source</label>
                        <select id="bookingSource" name="booking_source" onchange="onBookingSourceChange()">
                            <?php
                            $directKeys = ['walk_in', 'phone', 'online'];
                            $srcDirectR = array_filter($otaProviders, fn($v, $k) => in_array($k, $directKeys), ARRAY_FILTER_USE_BOTH);
                            $srcOtaR = array_filter($otaProviders, fn($v, $k) => !in_array($k, $directKeys), ARRAY_FILTER_USE_BOTH);
                            ?>
                            <optgroup label="Direct">
                                <?php foreach ($srcDirectR as $key => $src): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo $src['icon'] . ' ' . htmlspecialchars($src['name']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="OTA">
                                <?php foreach ($srcOtaR as $key => $src): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo $src['icon'] . ' ' . htmlspecialchars($src['name']) . ' (fee ' . $src['fee'] . '%)'; ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="input-compact">
                        <label>Payment Method</label>
                        <select name="payment_method" id="paymentMethod">
                            <option value="cash">Cash</option>
                            <option value="transfer">Transfer</option>
                            <option value="qris">QRIS</option>
                        </select>
                    </div>
                </div>

                <!-- PRICE SUMMARY -->
                <div class="price-summary-compact">
                    <div class="price-line">
                        <span>Total Rooms:</span>
                        <strong id="totalRoomsDisplay">0 rooms</strong>
                    </div>
                    <div class="price-line">
                        <span>Nights:</span>
                        <strong id="displayNights">0</strong>
                    </div>
                    <div class="price-line">
                        <span>Subtotal:</span>
                        <strong id="subtotalDisplay">Rp 0</strong>
                    </div>
                    <div class="price-line" style="flex-direction: column; align-items: flex-start;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; width: 100%; margin-bottom: 0.5rem;">
                            <span>Discount:</span>
                            <div class="discount-type-toggle" style="display: flex; gap: 0; margin-left: auto;">
                                <button type="button" class="disc-type-btn active" data-type="rp" onclick="setDiscountType('rp')" style="padding: 4px 10px; font-size: 0.75rem; border: 1px solid #6366f1; background: #6366f1; color: white; border-radius: 4px 0 0 4px; cursor: pointer;">Rp</button>
                                <button type="button" class="disc-type-btn" data-type="percent" onclick="setDiscountType('percent')" style="padding: 4px 10px; font-size: 0.75rem; border: 1px solid #6366f1; background: white; color: #6366f1; border-radius: 0 4px 4px 0; cursor: pointer;">%</button>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem; width: 100%;">
                            <input type="number" id="discount" name="discount" value="0" min="0" onchange="calculateMultiRoomTotal()" style="text-align:right; flex: 1; padding: 6px 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="0">
                            <input type="hidden" id="discountType" name="discount_type" value="rp">
                            <span id="discountTypeLabel" style="font-size: 0.8rem; color: #888; min-width: 30px;">Rp</span>
                        </div>
                        <div id="discountPreview" style="font-size: 0.75rem; color: #10b981; margin-top: 4px;"></div>
                    </div>
                    <div class="price-line" id="otaFeeRow" style="display: none; background: #fef3c7; padding: 8px; border-radius: 6px; margin: 4px 0;">
                        <span style="color: #92400e;">OTA Fee (<span id="otaFeePercentDisplay">0</span>%):</span>
                        <strong id="otaFeeAmountDisplay" style="color: #dc2626;">- Rp 0</strong>
                        <input type="hidden" id="otaFeeAmount" name="ota_fee_amount" value="0">
                    </div>
                    <div class="price-line" id="extrasSummaryRow" style="display: none;">
                        <span>Extras:</span>
                        <strong id="extrasSummaryAmount" style="color:#6366f1;">+ Rp 0</strong>
                    </div>
                    <div class="price-line-total">
                        <span>GRAND TOTAL:</span>
                        <strong id="grandTotalDisplay" style="color:#10b981; font-size: 1.3rem;">Rp 0</strong>
                    </div>
                </div>

                <!-- PAYMENT -->
                <div class="input-compact">
                    <label>Initial Payment (DP) - Rp</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="number" id="paidAmount" name="paid_amount" value="0" placeholder="0" style="flex: 1;">
                        <button type="button" onclick="payFullMultiRoom()" class="btn-pay-all" title="Pay Full Amount">Pay All</button>
                    </div>
                </div>

                <!-- SPECIAL REQUEST -->
                <div class="input-compact">
                    <label>Special Request</label>
                    <textarea name="special_request" id="specialRequest" rows="2" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ddd;"></textarea>
                </div>

                <!-- PER-ROOM EXTRAS (rendered dynamically per checked room) -->
                <div id="perRoomExtrasContainer"></div>
            </div>

            <div class="modal-footer-compact">
                <button type="button" class="btn-cancel" onclick="closeNewBookingModal()">Cancel</button>
                <button type="submit" class="btn-save">Save Reservation</button>
            </div>
        </form>
    </div>
</div>

<style>
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        backdrop-filter: blur(3px);
    }

    .modal-compact-booking {
        width: 90%;
        max-width: 700px;
        max-height: 90vh;
        background: white;
        border-radius: 12px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        overflow-y: auto;
    }

    .modal-header-compact {
        padding: 1.2rem 1.5rem;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .modal-header-compact h2 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 700;
    }

    .close-btn {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        font-size: 1.8rem;
        width: 35px;
        height: 35px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        line-height: 1;
    }

    .close-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: rotate(90deg);
    }

    .form-compact {
        padding: 1.5rem;
    }

    .form-row-2col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .input-compact {
        margin-bottom: 1rem;
    }

    .input-compact label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 0.4rem;
    }

    .input-compact input,
    .input-compact select {
        width: 100%;
        padding: 0.6rem 0.8rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.9rem;
        transition: all 0.2s;
    }

    .input-compact input:focus,
    .input-compact select:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .rooms-checklist {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid #ddd;
        padding: 10px;
        border-radius: 5px;
        background: #f9f9f9;
    }

    .room-checkbox-item {
        display: block;
        padding: 8px;
        margin-bottom: 5px;
        background: white;
        border-radius: 3px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .room-checkbox-item:hover {
        background: #f0f9ff;
        border-left: 3px solid #6366f1;
    }

    .room-checkbox-item input:checked+* {
        font-weight: bold;
    }

    .price-summary-compact {
        background: #f9fafb;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
    }

    .price-line {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.6rem;
        font-size: 0.9rem;
    }

    .price-line-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 0.8rem;
        margin-top: 0.8rem;
        border-top: 2px solid #e5e7eb;
        font-size: 1.1rem;
        font-weight: 700;
    }

    .btn-pay-all {
        background: #10b981;
        color: white;
        border: none;
        padding: 0.6rem 1rem;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.2s;
    }

    .btn-pay-all:hover {
        background: #059669;
    }

    .modal-footer-compact {
        padding: 1rem 1.5rem;
        background: #f9fafb;
        border-top: 1px solid #e5e7eb;
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        position: sticky;
        bottom: 0;
    }

    .btn-preset-extra {
        padding: 4px 10px;
        font-size: 0.78rem;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        border-radius: 20px;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }

    .btn-preset-extra:hover {
        background: #6366f1;
        color: white;
        border-color: #6366f1;
    }

    .new-extra-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 6px 8px;
        background: #f0fdf4;
        border-radius: 6px;
        margin-bottom: 4px;
        font-size: 0.85rem;
    }

    .new-extra-item .extra-del {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 1rem;
        padding: 0 4px;
    }

    .per-room-extra-card {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 8px;
        background: #fafbfc;
    }

    .per-room-extra-card .room-extra-header {
        font-weight: 600;
        font-size: 0.85rem;
        color: #1a1a2e;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .per-room-extra-card .room-extra-header .room-badge {
        background: #6366f1;
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.72rem;
    }

    .btn-cancel {
        padding: 0.7rem 1.5rem;
        background: #6b7280;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.2s;
    }

    .btn-cancel:hover {
        background: #4b5563;
    }

    .btn-save {
        padding: 0.7rem 2rem;
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.2s;
    }

    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
    }
</style>

<!-- Cancel Booking Modal -->
<div id="cancelBookingModal" class="cancel-modal-overlay">
    <div class="cancel-modal">
        <div class="cancel-modal-header">
            ⚠️ Konfirmasi Pembatalan Reservasi
        </div>
        <div class="cancel-modal-body">
            <div class="cancel-booking-info">
                <h4 id="cancelBookingCode">-</h4>
                <p><strong>Tamu:</strong> <span id="cancelGuestName">-</span></p>
                <p><strong>Check-in:</strong> <span id="cancelCheckIn">-</span></p>
                <p><strong>Check-out:</strong> <span id="cancelCheckOut">-</span></p>
            </div>

            <div style="margin-top: 1rem;">
                <div class="refund-amount-row">
                    <span>Total Dibayar</span>
                    <span id="cancelPaidAmount">Rp 0</span>
                </div>
            </div>

            <p style="margin-top: 1rem; font-size: 0.9rem; color: #92400e; background: #fef3c7; padding: 0.75rem; border-radius: 6px;">
                💡 <strong>Catatan:</strong> Reservasi akan dibatalkan. Jika perlu refund, lakukan manual melalui menu Kas Besar (pengeluaran).
            </p>
        </div>
        <div class="cancel-modal-actions">
            <button class="btn-cancel-modal" onclick="closeCancelModal()">Batal</button>
            <button class="btn-confirm-cancel" id="btnConfirmCancel" onclick="confirmCancelBooking()">
                ❌ Ya, Batalkan Reservasi
            </button>
        </div>
    </div>
</div>
<input type="hidden" id="cancelBookingId" value="">

<script>
    // Action dropdown toggle (named differently from main.js toggleDropdown)
    function toggleActionMenu(e) {
        e = e || window.event;
        e.stopPropagation();
        e.preventDefault();
        var btn = e.currentTarget || e.target;
        var dropdown = btn.closest('.action-dropdown');
        var wasOpen = dropdown.classList.contains('open');
        // Close all open dropdowns first
        document.querySelectorAll('.action-dropdown.open').forEach(function(d) {
            d.classList.remove('open');
        });
        if (!wasOpen) {
            var menu = dropdown.querySelector('.action-dropdown-menu');
            var rect = btn.getBoundingClientRect();
            // Position menu below button, right-aligned
            menu.style.top = (rect.bottom + 4) + 'px';
            menu.style.left = Math.max(8, rect.right - 170) + 'px';
            dropdown.classList.add('open');
        }
    }
    // Close action dropdowns on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.action-dropdown')) {
            document.querySelectorAll('.action-dropdown.open').forEach(function(d) {
                d.classList.remove('open');
            });
        }
    });
    // Close action dropdowns on scroll
    document.addEventListener('scroll', function() {
        document.querySelectorAll('.action-dropdown.open').forEach(function(d) {
            d.classList.remove('open');
        });
    }, true);

    // OTA Fee percentages from settings
    var OTA_FEES = {
        <?php
        foreach ($otaProviders as $key => $data) {
            echo "    '$key': " . $data['fee'] . ",\n";
        }
        ?>
    };

    // OTA source names for payment method labels
    var OTA_NAMES = {
        <?php
        foreach ($otaProviders as $key => $data) {
            echo "    '$key': '" . addslashes($data['name']) . "',\n";
        }
        ?>
    };

    // Direct booking sources (no OTA fee)
    var DIRECT_SOURCES = ['walk_in', 'phone', 'online'];

    function onBookingSourceChange() {
        const source = document.getElementById('bookingSource').value;
        syncPaymentMethodToSource(source);
        calculateMultiRoomTotal();
    }

    function syncPaymentMethodToSource(source) {
        const paymentSelect = document.getElementById('paymentMethod');
        const paidAmountInput = document.getElementById('paidAmount');
        const payAllBtn = document.querySelector('.btn-pay-all');
        const isOta = !DIRECT_SOURCES.includes(source);

        if (isOta) {
            // OTA selected: set payment to OTA with specific name
            const otaName = OTA_NAMES[source] || source;
            const otaValue = 'ota_' + source; // e.g. ota_agoda, ota_booking

            paymentSelect.innerHTML = '<option value="' + otaValue + '" selected>OTA ' + otaName + '</option>';
            paymentSelect.disabled = true;
            paymentSelect.style.opacity = '0.7';

            // OTA: disable Pay All & paid amount (OTA pays later at check-in)
            if (paidAmountInput) {
                paidAmountInput.value = 0;
                paidAmountInput.disabled = true;
                paidAmountInput.style.opacity = '0.5';
            }
            if (payAllBtn) {
                payAllBtn.disabled = true;
                payAllBtn.style.opacity = '0.5';
                payAllBtn.style.cursor = 'not-allowed';
                payAllBtn.title = 'OTA: pembayaran masuk saat check-in';
            }
        } else {
            // Direct selected: restore normal payment options
            paymentSelect.innerHTML =
                '<option value="cash">Cash</option>' +
                '<option value="transfer">Transfer</option>' +
                '<option value="qris">QRIS</option>';
            paymentSelect.disabled = false;
            paymentSelect.style.opacity = '1';

            // Direct: enable Pay All & paid amount
            if (paidAmountInput) {
                paidAmountInput.disabled = false;
                paidAmountInput.style.opacity = '1';
            }
            if (payAllBtn) {
                payAllBtn.disabled = false;
                payAllBtn.style.opacity = '1';
                payAllBtn.style.cursor = 'pointer';
                payAllBtn.title = 'Pay Full Amount';
            }
        }
    }

    function filterBookings(value) {
        window.location.search = '?status=' + value;
    }

    function searchBookings(keyword) {
        const query = keyword.toLowerCase().trim();
        const rows = document.querySelectorAll('.bookings-table tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    }

    function openNewBookingModal() {
        document.getElementById('newBookingModal').style.display = 'flex';
        // Set default dates
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);

        document.getElementById('checkInDate').value = today.toISOString().split('T')[0];
        document.getElementById('checkOutDate').value = tomorrow.toISOString().split('T')[0];
        const nationalityInput = document.getElementById('guestNationality');
        if (nationalityInput && !nationalityInput.value.trim()) {
            nationalityInput.value = 'Indonesia';
        }

        // Load available rooms for default dates
        loadAvailableRooms();
    }

    function closeNewBookingModal() {
        document.getElementById('newBookingModal').style.display = 'none';
        document.getElementById('newBookingForm').reset();
        resetNewExtras();
    }

    function updateCheckOutMinDate() {
        const checkInInput = document.getElementById('checkInDate');
        const checkOutInput = document.getElementById('checkOutDate');

        if (checkInInput && checkOutInput && checkInInput.value) {
            // Set min check-out to day after check-in
            const checkInDate = new Date(checkInInput.value);
            checkInDate.setDate(checkInDate.getDate() + 1);
            const minCheckOut = checkInDate.toISOString().split('T')[0];
            checkOutInput.min = minCheckOut;

            // If current check-out is before min, auto-update it
            if (!checkOutInput.value || checkOutInput.value <= checkInInput.value) {
                checkOutInput.value = minCheckOut;
            }
        }
    }

    async function loadAvailableRooms() {
        const checkIn = document.getElementById('checkInDate').value;
        const checkOut = document.getElementById('checkOutDate').value;

        // Update min date for check-out
        updateCheckOutMinDate();

        if (!checkIn || !checkOut) {
            document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">Pilih tanggal check-in dan check-out terlebih dahulu</em>';
            return;
        }

        // Validate dates
        if (new Date(checkOut) <= new Date(checkIn)) {
            document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">❌ Check-out harus minimal 1 hari setelah check-in</em>';
            document.getElementById('availabilityInfo').innerHTML = '<small style="color: #ef4444;">Invalid dates</small>';
            return;
        }

        // Show loading
        document.getElementById('roomsChecklistContainer').innerHTML = '<div style="text-align:center; padding: 20px;"><em>Loading available rooms...</em></div>';

        try {
            const response = await fetch(`../../api/get-available-rooms.php?check_in=${checkIn}&check_out=${checkOut}`);

            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.rooms.length > 0) {
                let html = '';
                result.rooms.forEach(room => {
                    html += `
                    <label class="room-checkbox-item" style="display: block; padding: 8px; margin-bottom: 5px; background: white; border-radius: 3px; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="rooms[]" value="${room.id}" 
                               data-price="${room.base_price}"
                               data-room="${room.room_number}"
                               data-type="${room.type_name}"
                               onchange="onRoomCheckChange()"
                               style="margin-right: 8px;">
                        <strong>Room ${room.room_number}</strong> - ${room.type_name}
                        <span style="color: #10b981; font-weight: bold;">(Rp ${parseInt(room.base_price).toLocaleString('id-ID')}/night)</span>
                    </label>
                `;
                });
                document.getElementById('roomsChecklistContainer').innerHTML = html;
                document.getElementById('availabilityInfo').innerHTML = `<small style="color: #10b981;">✅ ${result.available_rooms} room(s) available (${result.booked_rooms} booked)</small>`;
            } else if (result.success && result.rooms.length === 0) {
                document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">❌ Tidak ada room yang tersedia untuk tanggal ini (semua sudah di-booking)</em>';
                document.getElementById('availabilityInfo').innerHTML = `<small style="color: #ef4444;">0 rooms available (all ${result.booked_rooms} rooms booked)</small>`;
            } else {
                document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">Error loading rooms: ' + (result.message || 'Unknown error') + '</em>';
            }

            // Recalculate totals
            calculateMultiRoomTotal();

        } catch (error) {
            console.error('Error loading rooms:', error);
            document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">Error loading rooms. Please try again.</em>';
        }
    }

    function setDiscountType(type) {
        const discountTypeInput = document.getElementById('discountType');
        const discountLabel = document.getElementById('discountTypeLabel');
        const discountInput = document.getElementById('discount');
        const buttons = document.querySelectorAll('.disc-type-btn');

        buttons.forEach(btn => {
            if (btn.dataset.type === type) {
                btn.classList.add('active');
                btn.style.background = '#6366f1';
                btn.style.color = 'white';
            } else {
                btn.classList.remove('active');
                btn.style.background = 'white';
                btn.style.color = '#6366f1';
            }
        });

        discountTypeInput.value = type;
        discountLabel.textContent = type === 'percent' ? '%' : 'Rp';

        if (type === 'percent') {
            discountInput.max = 100;
            discountInput.placeholder = '0-100';
        } else {
            discountInput.removeAttribute('max');
            discountInput.placeholder = '0';
        }

        calculateMultiRoomTotal();
    }

    function calculateMultiRoomTotal() {
        const checkInStr = document.getElementById('checkInDate').value;
        const checkOutStr = document.getElementById('checkOutDate').value;
        const discountValue = parseFloat(document.getElementById('discount').value) || 0;
        const discountType = document.getElementById('discountType').value;

        if (!checkInStr || !checkOutStr) {
            return;
        }

        const checkIn = new Date(checkInStr);
        const checkOut = new Date(checkOutStr);
        const nights = Math.ceil((checkOut - checkIn) / (1000 * 60 * 60 * 24));

        if (nights <= 0) {
            alert('Check-out must be after check-in!');
            return;
        }

        // Get all checked rooms
        const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');
        const totalRooms = checkedRooms.length;

        let subtotal = 0;
        let roomDetails = [];

        checkedRooms.forEach(checkbox => {
            const price = parseFloat(checkbox.dataset.price) || 0;
            const roomNumber = checkbox.dataset.room;
            const roomType = checkbox.dataset.type;
            const roomTotal = price * nights;
            subtotal += roomTotal;
            roomDetails.push(`Room ${roomNumber} (${roomType}): Rp ${roomTotal.toLocaleString('id-ID')}`);
        });

        // Calculate discount based on type
        let discountAmount = 0;
        const discountPreview = document.getElementById('discountPreview');

        if (discountType === 'percent') {
            discountAmount = Math.round(subtotal * (discountValue / 100));
            if (discountValue > 0 && subtotal > 0) {
                discountPreview.textContent = `= Rp ${discountAmount.toLocaleString('id-ID')} (${discountValue}% dari ${subtotal.toLocaleString('id-ID')})`;
            } else {
                discountPreview.textContent = '';
            }
        } else {
            discountAmount = discountValue;
            discountPreview.textContent = '';
        }

        // Calculate OTA Fee based on booking source
        const bookingSource = document.getElementById('bookingSource').value;

        // Auto-set payment method based on booking source
        syncPaymentMethodToSource(bookingSource);
        const otaFeeRow = document.getElementById('otaFeeRow');
        const otaFeePercentDisplay = document.getElementById('otaFeePercentDisplay');
        const otaFeeAmountDisplay = document.getElementById('otaFeeAmountDisplay');
        const otaFeeAmountInput = document.getElementById('otaFeeAmount');

        let otaFeePercent = 0;
        let otaFeeAmount = 0;

        // Get OTA fee from settings
        if (typeof OTA_FEES !== 'undefined' && OTA_FEES[bookingSource]) {
            otaFeePercent = OTA_FEES[bookingSource];
        }

        if (otaFeePercent > 0 && subtotal > 0) {
            otaFeeAmount = Math.round(subtotal * (otaFeePercent / 100));
            otaFeeRow.style.display = 'flex';
            otaFeePercentDisplay.textContent = otaFeePercent;
            otaFeeAmountDisplay.textContent = '- Rp ' + otaFeeAmount.toLocaleString('id-ID');
            otaFeeAmountInput.value = otaFeeAmount;
        } else {
            otaFeeRow.style.display = 'none';
            otaFeeAmountInput.value = 0;
        }

        // Grand total = subtotal - discount + extras (OTA fee is deducted by CashbookHelper, not from guest price)
        const extrasTotal = typeof getNewExtrasTotal === 'function' ? getNewExtrasTotal() : 0;
        const grandTotal = subtotal - discountAmount + extrasTotal;

        // Update display
        document.getElementById('totalRoomsDisplay').textContent = totalRooms + ' room' + (totalRooms !== 1 ? 's' : '');
        document.getElementById('displayNights').textContent = nights + ' night' + (nights !== 1 ? 's' : '');
        document.getElementById('subtotalDisplay').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');

        // Extras row in summary
        const extrasRow = document.getElementById('extrasSummaryRow');
        if (extrasRow) {
            if (extrasTotal > 0) {
                extrasRow.style.display = 'flex';
                document.getElementById('extrasSummaryAmount').textContent = '+ Rp ' + extrasTotal.toLocaleString('id-ID');
            } else {
                extrasRow.style.display = 'none';
            }
        }

        document.getElementById('grandTotalDisplay').textContent = 'Rp ' + grandTotal.toLocaleString('id-ID');

        // Update summary
        if (totalRooms > 0) {
            document.getElementById('selectedRoomsSummary').innerHTML =
                '<strong>Selected:</strong> ' + totalRooms + ' room(s) × ' + nights + ' night(s) = Rp ' + subtotal.toLocaleString('id-ID');
        } else {
            document.getElementById('selectedRoomsSummary').innerHTML = '<em style="color: #ef4444;">Belum ada room yang dipilih</em>';
        }
    }

    // ========== PER-ROOM EXTRAS ==========
    let perRoomExtras = {}; // { roomId: [{ name, qty, price, total }] }

    function onRoomCheckChange() {
        renderPerRoomExtras();
        calculateMultiRoomTotal();
    }

    function renderPerRoomExtras() {
        const container = document.getElementById('perRoomExtrasContainer');
        const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');

        if (checkedRooms.length === 0) {
            container.innerHTML = '';
            return;
        }

        // Clean up extras for unchecked rooms
        const checkedIds = new Set();
        checkedRooms.forEach(cb => checkedIds.add(cb.value));
        Object.keys(perRoomExtras).forEach(rid => {
            if (!checkedIds.has(rid)) delete perRoomExtras[rid];
        });

        let html = '<div class="input-compact"><label>Tambahan Per Room (Extra Bed, Laundry, dll)</label>';
        checkedRooms.forEach(cb => {
            const roomId = cb.value;
            const roomNum = cb.dataset.room;
            const roomType = cb.dataset.type;
            if (!perRoomExtras[roomId]) perRoomExtras[roomId] = [];
            const extras = perRoomExtras[roomId];
            let extrasTotal = extras.reduce((s, e) => s + e.total, 0);

            html += `<div class="per-room-extra-card">
                <div class="room-extra-header">
                    <span class="room-badge">Room ${roomNum}</span> ${roomType}
                    ${extrasTotal > 0 ? `<span style="margin-left:auto;color:#6366f1;font-size:0.78rem;">Rp ${extrasTotal.toLocaleString('id-ID')}</span>` : ''}
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px;">
                    <button type="button" class="btn-preset-extra" onclick="addRoomExtra('${roomId}','Extra Bed',350000)">🛏️ Extra Bed</button>
                    <button type="button" class="btn-preset-extra" onclick="addRoomExtra('${roomId}','Laundry',25000)">👔 Laundry</button>
                    <button type="button" class="btn-preset-extra" onclick="addRoomExtra('${roomId}','Extra Pillow',25000)">🛏️ Pillow</button>
                    <button type="button" class="btn-preset-extra" onclick="addRoomExtra('${roomId}','Extra Towel',15000)">🧺 Towel</button>
                    <button type="button" class="btn-preset-extra" onclick="addRoomExtra('${roomId}','Breakfast',35000)">🍳 Breakfast</button>
                    <button type="button" class="btn-preset-extra" onclick="showRoomCustomExtra('${roomId}')">➕ Lainnya</button>
                </div>
                <div id="customExtraRow_${roomId}" style="display:none;gap:6px;margin-bottom:6px;">
                    <input type="text" id="extraName_${roomId}" placeholder="Nama item" style="flex:2;padding:5px 7px;border:1px solid #ddd;border-radius:4px;font-size:0.82rem;">
                    <input type="number" id="extraQty_${roomId}" value="1" min="1" style="width:45px;padding:5px 7px;border:1px solid #ddd;border-radius:4px;font-size:0.82rem;">
                    <input type="number" id="extraPrice_${roomId}" placeholder="Harga" step="1000" style="flex:1;padding:5px 7px;border:1px solid #ddd;border-radius:4px;font-size:0.82rem;">
                    <button type="button" onclick="addRoomCustomExtra('${roomId}')" style="padding:5px 10px;background:#6366f1;color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.82rem;">+</button>
                </div>`;

            if (extras.length > 0) {
                extras.forEach((ex, idx) => {
                    html += `<div class="new-extra-item">
                        <span>${ex.name} (${ex.qty}x @ Rp ${ex.price.toLocaleString('id-ID')})</span>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <strong>Rp ${ex.total.toLocaleString('id-ID')}</strong>
                            <button type="button" class="extra-del" onclick="removeRoomExtra('${roomId}',${idx})">🗑️</button>
                        </div>
                    </div>`;
                });
            }
            html += '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function addRoomExtra(roomId, name, price, qty = 1) {
        if (!perRoomExtras[roomId]) perRoomExtras[roomId] = [];
        perRoomExtras[roomId].push({
            name,
            qty,
            price,
            total: qty * price
        });
        renderPerRoomExtras();
        calculateMultiRoomTotal();
    }

    function showRoomCustomExtra(roomId) {
        const row = document.getElementById('customExtraRow_' + roomId);
        row.style.display = row.style.display === 'none' ? 'flex' : 'none';
        if (row.style.display === 'flex') document.getElementById('extraName_' + roomId).focus();
    }

    function addRoomCustomExtra(roomId) {
        const name = document.getElementById('extraName_' + roomId).value.trim();
        const qty = parseInt(document.getElementById('extraQty_' + roomId).value) || 1;
        const price = parseFloat(document.getElementById('extraPrice_' + roomId).value) || 0;
        if (!name) {
            alert('Nama item harus diisi');
            return;
        }
        if (price <= 0) {
            alert('Harga harus > 0');
            return;
        }
        addRoomExtra(roomId, name, price, qty);
    }

    function removeRoomExtra(roomId, idx) {
        if (perRoomExtras[roomId]) {
            perRoomExtras[roomId].splice(idx, 1);
        }
        renderPerRoomExtras();
        calculateMultiRoomTotal();
    }

    function getNewExtrasTotal() {
        let total = 0;
        Object.values(perRoomExtras).forEach(extras => {
            extras.forEach(ex => total += ex.total);
        });
        return total;
    }

    function getRoomExtrasTotal(roomId) {
        if (!perRoomExtras[roomId]) return 0;
        return perRoomExtras[roomId].reduce((s, e) => s + e.total, 0);
    }

    function resetNewExtras() {
        perRoomExtras = {};
        const container = document.getElementById('perRoomExtrasContainer');
        if (container) container.innerHTML = '';
    }

    function payFullMultiRoom() {
        const grandTotalText = document.getElementById('grandTotalDisplay').textContent;
        const grandTotal = parseFloat(grandTotalText.replace(/[^\d]/g, ''));
        document.getElementById('paidAmount').value = grandTotal;
    }

    async function submitMultiRoomBooking(event) {
        event.preventDefault();

        // Validate room selection
        const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');
        if (checkedRooms.length === 0) {
            alert('Silakan pilih minimal 1 room!');
            return;
        }

        // Get form data
        const guestName = document.getElementById('guestName').value;
        const guestPhone = document.getElementById('guestPhone').value || '';
        const guestEmail = document.getElementById('guestEmail').value || '';
        const guestNationality = document.getElementById('guestNationality').value || 'Indonesia';
        const checkIn = document.getElementById('checkInDate').value;
        const checkOut = document.getElementById('checkOutDate').value;
        const bookingSource = document.getElementById('bookingSource').value;
        const paymentMethod = document.getElementById('paymentMethod').value;
        const discountValue = parseFloat(document.getElementById('discount').value) || 0;
        const discountType = document.getElementById('discountType').value;
        const paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;
        const specialRequest = document.getElementById('specialRequest').value || '';

        // Calculate nights
        const nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24));

        // Calculate subtotal first for percentage discount
        let subtotal = 0;
        checkedRooms.forEach(checkbox => {
            const price = parseFloat(checkbox.dataset.price) * nights;
            subtotal += price;
        });

        // Calculate actual discount amount in Rp
        let discount = 0;
        if (discountType === 'percent') {
            discount = Math.round(subtotal * (discountValue / 100));
        } else {
            discount = discountValue;
        }

        // Calculate OTA fee (for display only - actual deduction happens in CashbookHelper)
        let otaFeePercent = 0;
        let otaFeeAmount = 0;
        if (typeof OTA_FEES !== 'undefined' && OTA_FEES[bookingSource]) {
            otaFeePercent = OTA_FEES[bookingSource];
            otaFeeAmount = Math.round(subtotal * (otaFeePercent / 100));
        }

        // Calculate discount per room (distribute equally)
        // NOTE: OTA fee is NOT subtracted from final_price - CashbookHelper handles fee deduction
        const discountPerRoom = discount / checkedRooms.length;

        // Extras total per room (each room has its own extras now)
        const extrasTotal = getNewExtrasTotal();

        // Calculate payment per room (distribute proportionally)
        let totalPrice = 0;
        const roomPrices = [];
        checkedRooms.forEach(checkbox => {
            const roomId = checkbox.value;
            const roomExtrasTotal = getRoomExtrasTotal(roomId);
            const price = parseFloat(checkbox.dataset.price) * nights - discountPerRoom + roomExtrasTotal;
            roomPrices.push(price);
            totalPrice += price;
        });

        // Disable submit button
        const submitBtn = event.target.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating bookings...';

        let successCount = 0;
        let errorCount = 0;
        const bookingCodes = [];

        // Generate group_id for multi-room bookings
        const groupId = checkedRooms.length > 1 ? 'GRP-' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '-' + Math.random().toString(36).substr(2, 6).toUpperCase() : '';

        // Create booking for each room
        for (let i = 0; i < checkedRooms.length; i++) {
            const checkbox = checkedRooms[i];
            const roomId = checkbox.value;
            const roomNumber = checkbox.dataset.room;
            const roomPrice = roomPrices[i];

            // Calculate proportional payment
            const proportionalPayment = totalPrice > 0 ? (paidAmount * (roomPrice / totalPrice)) : 0;

            // Create FormData for API
            const roomBasePrice = parseFloat(checkbox.dataset.price);
            const roomTotalPrice = roomBasePrice * nights;
            const roomExtrasTotal = getRoomExtrasTotal(roomId);
            const roomFinalPrice = roomTotalPrice - discountPerRoom + roomExtrasTotal;

            const formData = new FormData();
            if (groupId) formData.append('group_id', groupId);
            formData.append('guest_name', guestName);
            formData.append('guest_phone', guestPhone);
            formData.append('guest_email', guestEmail);
            formData.append('guest_nationality', guestNationality);
            formData.append('room_id', roomId);
            formData.append('check_in_date', checkIn);
            formData.append('check_out_date', checkOut);
            formData.append('room_price', roomBasePrice);
            formData.append('total_price', roomTotalPrice);
            formData.append('total_nights', nights);
            formData.append('adult_count', 1);
            formData.append('children_count', 0);
            formData.append('discount', discountPerRoom);
            formData.append('final_price', roomFinalPrice);
            formData.append('booking_source', bookingSource);
            formData.append('payment_method', paymentMethod);
            formData.append('paid_amount', proportionalPayment);
            formData.append('special_request', specialRequest);

            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/create-reservation.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    successCount++;
                    bookingCodes.push(result.booking_code);

                    // Save extras for this room's booking
                    const roomExtras = perRoomExtras[roomId] || [];
                    if (roomExtras.length > 0 && result.booking_id) {
                        for (const ex of roomExtras) {
                            try {
                                const extForm = new FormData();
                                extForm.append('action', 'add');
                                extForm.append('booking_id', result.booking_id);
                                extForm.append('item_name', ex.name);
                                extForm.append('quantity', ex.qty);
                                extForm.append('unit_price', ex.price);
                                await fetch('<?php echo BASE_URL; ?>/api/booking-extras.php', {
                                    method: 'POST',
                                    body: extForm
                                });
                            } catch (extErr) {
                                console.error('Error saving extra:', extErr);
                            }
                        }
                    }
                } else {
                    errorCount++;
                    console.error(`Error booking Room ${roomNumber}:`, result.message);
                }
            } catch (error) {
                errorCount++;
                console.error(`Error booking Room ${roomNumber}:`, error);
            }
        }

        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Booking';

        // Show results
        if (successCount > 0) {
            alert(`✅ Berhasil membuat ${successCount} booking!\n\nBooking Codes: ${bookingCodes.join(', ')}\n\n${errorCount > 0 ? `⚠️ ${errorCount} booking gagal dibuat.` : ''}`);
            resetNewExtras();
            closeNewBookingModal();
            window.location.reload(); // Refresh to show new bookings
        } else {
            alert('❌ Gagal membuat booking. Silakan coba lagi.');
        }
    }

    function viewBooking(id) {
        alert('Coming Soon: View Booking #' + id);
    }

    function viewBooking(id) {
        window.open('invoice.php?booking_id=' + id, '_blank', 'width=800,height=900');
    }

    function savePDF(id) {
        // Open invoice page and trigger PDF save
        window.open('invoice.php?booking_id=' + id + '&pdf=1', '_blank', 'width=800,height=900');
    }

    function editBooking(id) {
        window.location.href = 'edit-booking.php?id=' + id;
    }

    function printInvoice(id) {
        window.open('invoice.php?booking_id=' + id, '_blank', 'width=800,height=900');
    }

    function addPayment(bookingId, bookingCode, remainingAmount, bookingSource, otaFeePercent, otaSourceName) {
        const formattedRemaining = 'Rp ' + remainingAmount.toLocaleString('id-ID');
        const feePercent = otaFeePercent || OTA_FEES[bookingSource] || 0;
        const sourceName = otaSourceName || OTA_NAMES[bookingSource] || bookingSource || 'Direct';
        const isOta = feePercent > 0;

        // === STEP 1: Input jumlah pembayaran ===
        let promptMsg = '━━━━━━━━━━━━━━━━━━━━━━━\n';
        promptMsg += '💰 PEMBAYARAN - ' + bookingCode + '\n';
        promptMsg += '━━━━━━━━━━━━━━━━━━━━━━━\n\n';
        promptMsg += '📌 Sumber: ' + sourceName;
        if (isOta) promptMsg += ' (OTA Fee ' + feePercent + '%)';
        promptMsg += '\n💵 Sisa Tagihan: ' + formattedRemaining + '\n\n';
        promptMsg += 'Masukkan jumlah pembayaran:';

        const amount = prompt(promptMsg, remainingAmount);
        if (amount === null) return;

        const payAmount = parseFloat(amount);
        if (isNaN(payAmount) || payAmount <= 0) {
            alert('❌ Jumlah pembayaran tidak valid!');
            return;
        }

        if (payAmount > remainingAmount) {
            if (!confirm('⚠️ Jumlah melebihi sisa tagihan!\n\nSisa: ' + formattedRemaining + '\nInput: Rp ' + payAmount.toLocaleString('id-ID') + '\n\nLanjutkan?')) {
                return;
            }
        }

        // === STEP 2: Payment method ===
        let paymentMethod;
        if (isOta) {
            // OTA: otomatis set metode pembayaran ke OTA
            paymentMethod = 'ota_' + bookingSource;
            const feeAmount = Math.round(payAmount * feePercent / 100);
            const netAmount = payAmount - feeAmount;

            let confirmMsg = '━━━━━━━━━━━━━━━━━━━━━━━\n';
            confirmMsg += '📋 KONFIRMASI PEMBAYARAN OTA\n';
            confirmMsg += '━━━━━━━━━━━━━━━━━━━━━━━\n\n';
            confirmMsg += '🏷️ Sumber: ' + sourceName + '\n';
            confirmMsg += '💰 Jumlah Bayar: Rp ' + payAmount.toLocaleString('id-ID') + '\n';
            confirmMsg += '📉 OTA Fee (' + feePercent + '%): -Rp ' + feeAmount.toLocaleString('id-ID') + '\n';
            confirmMsg += '✅ Net Diterima: Rp ' + netAmount.toLocaleString('id-ID') + '\n\n';
            confirmMsg += '💳 Metode: OTA ' + sourceName + '\n\n';
            confirmMsg += 'Proses pembayaran ini?';

            if (!confirm(confirmMsg)) return;
        } else {
            // Direct: pilih metode pembayaran
            const method = prompt(
                '━━━━━━━━━━━━━━━━━━━━━━━\n' +
                '💳 METODE PEMBAYARAN\n' +
                '━━━━━━━━━━━━━━━━━━━━━━━\n\n' +
                '📌 Sumber: ' + sourceName + '\n\n' +
                '1 = 💵 Cash\n' +
                '2 = 🏦 Transfer\n' +
                '3 = 📱 QRIS\n' +
                '4 = 💳 Card\n\n' +
                'Pilih nomor:',
                '1'
            );
            if (method === null) return;
            const methodMap = {
                '1': 'cash',
                '2': 'transfer',
                '3': 'qris',
                '4': 'card'
            };
            paymentMethod = methodMap[method] || 'cash';
        }

        // === STEP 3: Submit ===
        const formData = new FormData();
        formData.append('booking_id', bookingId);
        formData.append('amount', payAmount);
        formData.append('payment_method', paymentMethod);

        fetch('../../api/add-booking-payment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ PEMBAYARAN BERHASIL!\n\n' + (data.message || 'Payment recorded'));
                    location.reload();
                } else {
                    alert('❌ Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('❌ Error: ' + error.message);
            });
    }

    function checkinBooking(id, bookingCode) {
        // First, check payment status and booking source
        fetch('../../api/get-booking-details.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('Error: ' + data.message);
                    return;
                }

                const booking = data.booking;
                const finalPrice = parseFloat(booking.final_price);
                const paidAmount = parseFloat(booking.paid_amount);
                const remaining = finalPrice - paidAmount;

                // Detect OTA booking from booking_source
                const bookingSource = (booking.booking_source || '').toLowerCase();
                const isOta = !DIRECT_SOURCES.includes(bookingSource) && bookingSource !== '' && bookingSource !== 'other';
                const sourceName = OTA_NAMES[bookingSource] || (booking.booking_source || '').charAt(0).toUpperCase() + (booking.booking_source || '').slice(1);
                const feePercent = OTA_FEES[bookingSource] || 0;

                // OTA booking: auto check-in (payment already handled by OTA platform)
                if (isOta) {
                    let otaMsg = `🏨 Booking via OTA: ${sourceName}\n\n`;
                    otaMsg += `Tamu: ${booking.guest_name}\n`;
                    otaMsg += `Room: ${booking.room_number}\n`;
                    otaMsg += `Total: Rp ${finalPrice.toLocaleString('id-ID')}\n`;
                    if (feePercent > 0) {
                        const feeAmount = Math.round(finalPrice * feePercent / 100);
                        const netAmount = finalPrice - feeAmount;
                        otaMsg += `\n📉 Fee OTA (${feePercent}%): -Rp ${feeAmount.toLocaleString('id-ID')}`;
                        otaMsg += `\n✅ Net masuk Kas Bank: Rp ${netAmount.toLocaleString('id-ID')}`;
                    }
                    otaMsg += `\n\nStatus otomatis LUNAS. Uang masuk ke Kas Bank.\nLanjutkan Check-in?`;

                    if (!confirm(otaMsg)) return;
                    proceedCheckin(id, bookingCode);
                    return;
                }

                // If not fully paid (direct booking), ask user what to do
                if (remaining > 0) {
                    const formattedRemaining = 'Rp ' + remaining.toLocaleString('id-ID');
                    const formattedTotal = 'Rp ' + finalPrice.toLocaleString('id-ID');
                    const formattedPaid = 'Rp ' + paidAmount.toLocaleString('id-ID');

                    const choice = confirm(
                        `⚠️ PEMBAYARAN BELUM LUNAS!\n\n` +
                        `Booking: ${bookingCode}\n` +
                        `Total Tagihan: ${formattedTotal}\n` +
                        `Sudah Dibayar: ${formattedPaid}\n` +
                        `SISA KURANG: ${formattedRemaining}\n\n` +
                        `Klik OK untuk BAYAR SEKARANG\n` +
                        `Klik Cancel untuk TETAP CHECK-IN (tagihan masih ada)`
                    );

                    if (choice) {
                        // User wants to pay - open payment modal
                        openPaymentModal(id, bookingCode, remaining);
                    } else {
                        // User wants to check-in anyway
                        proceedCheckin(id, bookingCode);
                    }
                } else {
                    // Fully paid, just confirm check-in
                    if (confirm(`Konfirmasi Check-in untuk booking ${bookingCode}?\n\n- Status akan berubah menjadi CHECKED IN\n- Kamar akan ditandai TERISI`)) {
                        proceedCheckin(id, bookingCode);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Fallback - just proceed with check-in
                if (confirm(`Konfirmasi Check-in untuk booking ${bookingCode}?\n\n- Status akan berubah menjadi CHECKED IN\n- Kamar akan ditandai TERISI`)) {
                    proceedCheckin(id, bookingCode);
                }
            });
    }

    function proceedCheckin(id, bookingCode) {
        fetch('../../api/checkin-guest.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    booking_id: id
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Check-in BERHASIL! Tamu sudah masuk.');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
                console.error('Error:', error);
            });
    }

    function openPaymentModal(bookingId, bookingCode, remainingAmount) {
        const amount = prompt(
            `💰 PEMBAYARAN BOOKING ${bookingCode}\n\n` +
            `Sisa Tagihan: Rp ${remainingAmount.toLocaleString('id-ID')}\n\n` +
            `Masukkan jumlah pembayaran:`,
            remainingAmount
        );

        if (amount === null) return; // User cancelled

        const payAmount = parseFloat(amount);
        if (isNaN(payAmount) || payAmount <= 0) {
            alert('❌ Jumlah pembayaran tidak valid!');
            return;
        }

        // Ask for payment method
        const method = prompt(
            `Pilih metode pembayaran:\n\n` +
            `1 = Cash\n` +
            `2 = Transfer\n` +
            `3 = QRIS\n` +
            `4 = Card\n\n` +
            `Masukkan nomor pilihan:`,
            '1'
        );

        const methodMap = {
            '1': 'cash',
            '2': 'transfer',
            '3': 'qris',
            '4': 'card'
        };

        const paymentMethod = methodMap[method] || 'cash';

        // Submit payment
        const formData = new FormData();
        formData.append('booking_id', bookingId);
        formData.append('amount', payAmount);
        formData.append('payment_method', paymentMethod);

        fetch('../../api/add-booking-payment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Pembayaran berhasil!\n\n' + (data.message || ''));

                    // Now proceed with check-in
                    if (confirm(`Lanjutkan Check-in untuk booking ${bookingCode}?`)) {
                        proceedCheckin(bookingId, bookingCode);
                    } else {
                        location.reload();
                    }
                } else {
                    alert('❌ Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('❌ Error: ' + error.message);
                console.error('Error:', error);
            });
    }

    function cancelBooking(id, bookingCode) {
        document.getElementById('cancelBookingModal').classList.add('active');
        document.getElementById('cancelBookingCode').textContent = bookingCode;
        document.getElementById('cancelGuestName').textContent = 'Loading...';
        document.getElementById('cancelBookingId').value = id;

        fetch(`../../api/get-refund-preview.php?booking_id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const d = data.data;
                    document.getElementById('cancelGuestName').textContent = d.guest_name || '-';
                    document.getElementById('cancelCheckIn').textContent = formatDateID(d.check_in_date);
                    document.getElementById('cancelCheckOut').textContent = formatDateID(d.check_out_date);
                    document.getElementById('cancelPaidAmount').textContent = formatRupiah(d.paid_amount);
                } else {
                    alert('Error: ' + data.message);
                    closeCancelModal();
                }
            })
            .catch(error => {
                alert('Error loading data: ' + error.message);
                closeCancelModal();
            });
    }

    function closeCancelModal() {
        document.getElementById('cancelBookingModal').classList.remove('active');
        document.getElementById('cancelBookingId').value = '';
    }

    function confirmCancelBooking() {
        const bookingId = document.getElementById('cancelBookingId').value;
        if (!bookingId) return;

        const btn = document.getElementById('btnConfirmCancel');
        btn.disabled = true;
        btn.textContent = 'Processing...';

        fetch('../../api/cancel-booking.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    booking_id: bookingId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`✅ Reservasi ${data.data.booking_code} berhasil dibatalkan!\n\nJika perlu refund, lakukan manual melalui menu Kas Besar.`);
                    closeCancelModal();
                    location.reload();
                } else {
                    alert('❌ Error: ' + data.message);
                    btn.disabled = false;
                    btn.textContent = '❌ Ya, Batalkan Reservasi';
                }
            })
            .catch(error => {
                alert('❌ Error: ' + error.message);
                btn.disabled = false;
                btn.textContent = '❌ Ya, Batalkan Reservasi';
            });
    }

    function formatDateID(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return d.toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    }

    function formatRupiah(amount) {
        return 'Rp ' + parseInt(amount || 0).toLocaleString('id-ID');
    }

    function deleteBooking(id, bookingCode) {
        if (!confirm(`PERINGATAN: Yakin ingin menghapus reservasi ${bookingCode}?\n\nAksi ini TIDAK BISA DIBATALKAN!\n\nData akan dihapus permanen dari sistem.`)) {
            return;
        }

        fetch('../../api/delete-booking.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    booking_id: id
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Reservasi berhasil dihapus permanen');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
                console.error('Error:', error);
            });
    }

    // Bulk delete functions
    function toggleSelectAll(el) {
        document.querySelectorAll('.booking-checkbox').forEach(cb => cb.checked = el.checked);
        updateBulkBar();
    }

    function updateBulkBar() {
        const checked = document.querySelectorAll('.booking-checkbox:checked');
        const bar = document.getElementById('bulkDeleteBar');
        const count = document.getElementById('selectedCount');
        if (checked.length > 0) {
            bar.style.display = 'flex';
            count.textContent = checked.length + ' dipilih';
        } else {
            bar.style.display = 'none';
            document.getElementById('selectAll').checked = false;
        }
    }

    function clearSelection() {
        document.querySelectorAll('.booking-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('selectAll').checked = false;
        updateBulkBar();
    }

    async function bulkDeleteBookings() {
        const checked = document.querySelectorAll('.booking-checkbox:checked');
        if (checked.length === 0) return;

        // Each checkbox value can be comma-separated (grouped bookings)
        const ids = [];
        checked.forEach(cb => cb.value.split(',').forEach(v => ids.push(parseInt(v))));
        if (!confirm(`PERINGATAN: Yakin ingin menghapus ${ids.length} reservasi?\n\nAksi ini TIDAK BISA DIBATALKAN!`)) {
            return;
        }

        let success = 0,
            failed = 0;
        for (const id of ids) {
            try {
                const res = await fetch('../../api/delete-booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        booking_id: id
                    })
                });
                const data = await res.json();
                if (data.success) success++;
                else failed++;
            } catch (e) {
                failed++;
            }
        }
        alert(`Selesai: ${success} berhasil dihapus${failed > 0 ? ', ' + failed + ' gagal' : ''}`);
        location.reload();
    }
</script>

<?php include '../../includes/footer.php'; ?>