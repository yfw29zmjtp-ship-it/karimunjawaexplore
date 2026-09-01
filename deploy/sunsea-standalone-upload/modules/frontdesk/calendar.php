<?php

/**
 * FRONT DESK - CALENDAR BOOKING VIEW
 * Interactive Calendar like CloudBeds
 * Horizontal: Dates | Vertical: Room Numbers + Guest Names
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

function ensureRoomBlocksTable($db)
{
    $db->query("CREATE TABLE IF NOT EXISTS room_blocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        block_code VARCHAR(40) NULL,
        room_id INT NOT NULL,
        block_start_date DATE NOT NULL,
        block_end_date DATE NOT NULL,
        block_reason VARCHAR(50) NOT NULL DEFAULT 'maintenance',
        notes TEXT NULL,
        status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_room_dates (room_id, block_start_date, block_end_date),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensureRoomBlocksTable($db);

$pageTitle = 'Calendar Booking';
$isStaffView = isset($_GET['staff_view']) && $_GET['staff_view'] === '1';

// ============================================
// GET OTA FEES (For Frontend Logic) - Load from booking_sources table
// ============================================
$otaFees = [
    'direct' => 0,
    'walk_in' => 0,
    'phone' => 0,
    'online' => 0,
    'agoda' => 15,
    'booking' => 12,
    'tiket' => 10,
    'traveloka' => 15,
    'airbnb' => 3,
    'ota' => 10
];
try {
    // Read directly from booking_sources table (single source of truth)
    $feesFromDb = $db->fetchAll("SELECT source_key, source_name, source_type, fee_percent, icon FROM booking_sources WHERE is_active = 1 ORDER BY sort_order ASC");
    $bookingSources = $feesFromDb ?: [];
    if ($feesFromDb) {
        foreach ($feesFromDb as $fee) {
            $otaFees[$fee['source_key']] = (float)$fee['fee_percent'];
        }
    }
} catch (Exception $e) {
    $bookingSources = [];
    // Keep defaults
}

// Build dynamic OTA source keys from booking_sources table (source_type != 'direct')
$otaSourceKeys = array_values(array_map(fn($s) => $s['source_key'], array_filter($bookingSources, fn($s) => ($s['source_type'] ?? '') !== 'direct')));
// Fallback if empty
if (empty($otaSourceKeys)) {
    $otaSourceKeys = ['agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'expedia', 'pegipegi', 'ota'];
}

// ============================================
// GET CALENDAR DATE RANGE (Include Past Dates for History)
// ============================================
$startDate = $_GET['start'] ?? date('Y-m-d');
$daysBefore = 60; // Show 60 days before for history/checkout bookings
$daysAfter = 365; // Show 365 days after for future bookings
$dates = [];

// Add past dates (for history view)
for ($i = $daysBefore; $i > 0; $i--) {
    $dates[] = date('Y-m-d', strtotime($startDate . " -{$i} days"));
}

// Add current and future dates
for ($i = 0; $i < $daysAfter; $i++) {
    $dates[] = date('Y-m-d', strtotime($startDate . " +{$i} days"));
}

// ============================================
// GET ALL ROOMS WITH TYPES
// ============================================
try {
    $rooms = $db->fetchAll("
        SELECT r.id, r.room_number, r.floor_number, r.status, rt.type_name, rt.base_price, rt.color_code
        FROM rooms r
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE r.status != 'maintenance'
        ORDER BY FIELD(rt.type_name, 'Queen Chambers', 'Queen', 'Twin Chambers', 'Twin', 'King Quarters', 'King', 'Deluxe Queen', 'Deluxe King'), rt.type_name ASC, r.floor_number ASC, r.room_number ASC
    ", []);
} catch (Exception $e) {
    error_log("Rooms Error: " . $e->getMessage());
    $rooms = [];
}

// ============================================
// GET BOOKINGS FOR DATE RANGE
// ============================================
try {
    // Calculate actual date range (including past dates)
    $actualStartDate = date('Y-m-d', strtotime($startDate . " -{$daysBefore} days"));
    $actualEndDate = date('Y-m-d', strtotime($startDate . " +{$daysAfter} days"));

    // Fetch all bookings that overlap with date range (including history)
    $bookings = $db->fetchAll("
        SELECT 
            b.id, 
            b.booking_code, 
            b.room_id, 
            b.check_in_date, 
            b.check_out_date,
            b.status, 
            b.room_price, 
            b.booking_source,
            b.payment_status,
            g.guest_name, 
            g.phone
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.check_in_date < ? 
        AND b.check_out_date > ?
        AND b.status IN ('pending', 'confirmed', 'checked_in', 'checked_out')
        ORDER BY b.check_in_date ASC, b.room_id ASC
    ", [$actualEndDate, $actualStartDate]);

    echo "<!-- DEBUG: Found " . count($bookings) . " bookings -->\n";
} catch (Exception $e) {
    error_log("Bookings Error: " . $e->getMessage());
    $bookings = [];
}

// ============================================
// GET ROOM BLOCKS FOR DATE RANGE
// ============================================
$roomBlocks = [];
try {
    $roomBlocks = $db->fetchAll(" 
        SELECT rb.id, rb.block_code, rb.room_id, rb.block_start_date, rb.block_end_date,
               rb.block_reason, rb.notes, rb.status,
               r.room_number
        FROM room_blocks rb
        JOIN rooms r ON r.id = rb.room_id
        WHERE rb.status = 'active'
          AND rb.block_start_date < ?
          AND rb.block_end_date > ?
        ORDER BY rb.block_start_date ASC, rb.room_id ASC
    ", [$actualEndDate, $actualStartDate]) ?: [];
} catch (Exception $e) {
    $roomBlocks = [];
}

// ============================================
// BUILD BOOKING MATRIX
// ============================================
$bookingMatrix = [];
foreach ($bookings as $booking) {
    $roomId = $booking['room_id'];
    if (!isset($bookingMatrix[$roomId])) {
        $bookingMatrix[$roomId] = [];
    }
    $bookingMatrix[$roomId][$booking['booking_code']] = $booking;
}

$roomBlockMatrix = [];
foreach ($roomBlocks as $block) {
    $roomId = (int)$block['room_id'];
    if (!isset($roomBlockMatrix[$roomId])) {
        $roomBlockMatrix[$roomId] = [];
    }
    $roomBlockMatrix[$roomId][] = $block;
}

// ============================================
// CALCULATE AVAILABILITY PER DATE
// ============================================
$totalRoomCount = count($rooms);
$availPerDate = [];
$availPerTypeDate = []; // [typeName][date] => available count
$roomCountPerType = [];
foreach ($rooms as $room) {
    $tn = $room['type_name'];
    $roomCountPerType[$tn] = ($roomCountPerType[$tn] ?? 0) + 1;
}
foreach ($dates as $date) {
    $bookedCount = 0;
    $blockedCount = 0;
    $bookedPerType = [];
    $blockedPerType = [];
    $dt = strtotime($date);
    foreach ($bookingMatrix as $roomId => $roomBookings) {
        foreach ($roomBookings as $bk) {
            if ($bk['status'] === 'checked_out') continue;
            $ci = strtotime($bk['check_in_date']);
            $co = strtotime($bk['check_out_date']);
            if ($dt >= $ci && $dt < $co) {
                $bookedCount++;
                // Find room type
                foreach ($rooms as $rm) {
                    if ($rm['id'] == $roomId) {
                        $tn = $rm['type_name'];
                        $bookedPerType[$tn] = ($bookedPerType[$tn] ?? 0) + 1;
                        break;
                    }
                }
                break;
            }
        }
    }

    foreach ($roomBlockMatrix as $roomId => $blocks) {
        foreach ($blocks as $bl) {
            $bs = strtotime((string)$bl['block_start_date']);
            $be = strtotime((string)$bl['block_end_date']);
            if ($dt >= $bs && $dt < $be) {
                $blockedCount++;
                foreach ($rooms as $rm) {
                    if ((int)$rm['id'] === (int)$roomId) {
                        $tn = $rm['type_name'];
                        $blockedPerType[$tn] = ($blockedPerType[$tn] ?? 0) + 1;
                        break;
                    }
                }
                break;
            }
        }
    }

    $availPerDate[$date] = $totalRoomCount - $bookedCount - $blockedCount;
    foreach ($roomCountPerType as $tn => $cnt) {
        $availPerTypeDate[$tn][$date] = $cnt - ($bookedPerType[$tn] ?? 0) - ($blockedPerType[$tn] ?? 0);
    }
}

// ============================================
// BOOKING COLORS - SIMPLE: Default vs Checked-In vs Checked-Out
// ============================================
$defaultColor = ['bg' => '#2a3552', 'text' => 'white'];        // Muted navy for pending/confirmed bookings
$checkedInColor = ['bg' => '#0f8a65', 'text' => 'white'];      // Darker green for checked-in guests (active)
$checkedOutColor = ['bg' => '#9ca3af', 'text' => '#6b7280'];   // Gray transparent for checked-out (history)

include '../../includes/header.php';
?>

<style>
    /* ============================================
   CLOUDBEDS STYLE CALENDAR - SYSTEM THEME
   ============================================ */

    .calendar-container {
        width: 100%;
        padding: 0.3rem 0.1rem;
        overflow: visible;
        box-sizing: border-box;
        max-width: 100%;
        position: relative;
        z-index: 1;
    }

    /* Scroll Container - MUST BE CONSTRAINED */
    .calendar-scroll-wrapper {
        width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        position: relative;
        box-sizing: border-box;
        display: block;
        white-space: nowrap;
        cursor: grab !important;
        background: transparent;
        user-select: none;
        -webkit-user-select: none;
        padding-bottom: 5px;
        /* Space for scrollbar */
    }

    .calendar-scroll-wrapper::-webkit-scrollbar {
        height: 8px;
    }

    .calendar-scroll-wrapper::-webkit-scrollbar-thumb {
        background: rgba(99, 102, 241, 0.3);
        border-radius: 4px;
    }

    .calendar-scroll-wrapper:active {
        cursor: grabbing !important;
    }

    .calendar-scroll-wrapper.dragging {
        cursor: grabbing !important;
    }

    .calendar-header {
        display: flex;
        flex-direction: column;
        margin-bottom: 0.5rem;
        gap: 0.5rem;
    }

    .calendar-header h1 {
        font-size: 1rem;
        font-weight: 800;
        background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin: 0;
    }

    .calendar-header h1 .icon {
        -webkit-text-fill-color: #1e3a8a;
        background: none;
    }

    .calendar-controls {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .btn-nav {
        background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
        color: #ffffff !important;
        border: none;
        padding: 0.4rem 0.7rem;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.75rem;
        transition: all 0.3s ease;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        line-height: 1.2;
        text-decoration: none;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }

    .btn-nav:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(30, 58, 138, 0.4);
        color: #ffffff !important;
    }

    .btn-nav:visited,
    .btn-nav:active,
    .btn-nav:focus {
        color: #ffffff !important;
    }

    .date-display {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .nav-date-input {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        padding: 0.5rem 0.8rem;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.8rem;
    }

    /* Navigation Bar */
    .calendar-nav {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.4rem;
        background: var(--card-bg);
        backdrop-filter: blur(30px);
        border: 0.5px solid var(--border-color);
        border-radius: 8px;
        padding: 0.4rem;
    }

    .nav-btn {
        background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
        color: #ffffff !important;
        border: 1px solid #1e40af;
        padding: 0.4rem 0.6rem;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.75rem;
        transition: all 0.3s ease;
        white-space: nowrap;
    }

    #prevMonthBtn,
    #nextMonthBtn {
        width: 30px;
        height: 30px;
        padding: 0;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        font-weight: 700;
    }

    .nav-btn:hover {
        background: linear-gradient(135deg, #1e40af, #2563eb);
        border-color: #1e40af;
        color: #ffffff !important;
    }

    .today-btn {
        background: rgba(255, 255, 255, 0.15);
        color: var(--text-primary);
        border: 1.5px solid var(--border-color);
        font-weight: 700;
        font-size: 0.7rem;
        letter-spacing: 0.5px;
        padding: 0.4rem 0.8rem;
    }

    .today-btn:hover {
        background: #1e3a8a;
        color: #fff;
        border-color: #1e3a8a;
    }

    body[data-theme="light"] .today-btn {
        background: #fff;
        color: #334155;
        border: 1.5px solid #cbd5e1;
    }

    body[data-theme="light"] .today-btn:hover {
        background: #1e3a8a;
        color: #fff;
        border-color: #1e3a8a;
    }

    .nav-date-input {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: var(--text-primary);
        padding: 0.6rem 1rem;
        border-radius: 8px;
        font-weight: 600;
    }

    /* Calendar Wrapper */
    .calendar-wrapper {
        background: var(--card-bg);
        backdrop-filter: blur(30px);
        border: 0.5px solid var(--border-color);
        border-radius: 8px;
        overflow: visible !important;
        padding: 0.3rem;
        user-select: none;
        -webkit-user-select: none;
        -webkit-touch-callout: none;
        cursor: grab !important;
        width: fit-content;
        min-width: 100%;
        display: inline-block;
        box-sizing: border-box;
        position: relative;
        scroll-behavior: smooth;
        overscroll-behavior: contain;
    }

    .calendar-wrapper.dragging {
        cursor: grabbing !important;
    }

    .calendar-wrapper.dragging,
    .calendar-wrapper.dragging * {
        user-select: none !important;
    }

    body.calendar-dragging {
        user-select: none;
        cursor: grabbing !important;
    }

    /* Light Theme - Make borders more visible */
    body[data-theme="light"] .calendar-wrapper,
    body[data-theme="light"] .calendar-nav,
    body[data-theme="light"] .legend {
        border: 1px solid rgba(51, 65, 85, 0.2);
    }

    body[data-theme="light"] .calendar-wrapper,
    body[data-theme="light"] .calendar-nav,
    body[data-theme="light"] .legend {
        border: 1px solid rgba(51, 65, 85, 0.2);
    }

    body[data-theme="light"] .grid-header-date,
    body[data-theme="light"] .grid-date-cell,
    body[data-theme="light"] .grid-room-label,
    body[data-theme="light"] .grid-room-type-header,
    body[data-theme="light"] .grid-header-room {
        border-color: rgba(51, 65, 85, 0.15);
    }

    body[data-theme="light"] .grid-header-date,
    body[data-theme="light"] .grid-date-cell {
        border-right-width: 1px;
        border-bottom-width: 1px;
    }

    body[data-theme="light"] .grid-room-label,
    body[data-theme="light"] .grid-room-type-header {
        border-right-width: 1px;
        border-bottom-width: 1px;
    }

    .calendar-wrapper::-webkit-scrollbar {
        height: 12px;
    }

    .calendar-wrapper::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .calendar-wrapper::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.7), rgba(139, 92, 246, 0.7));
        border-radius: 10px;
        border: 2px solid rgba(255, 255, 255, 0.2);
    }

    .calendar-wrapper::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.9), rgba(139, 92, 246, 0.9));
    }

    .calendar-grid {
        display: grid;
        gap: 0;
        grid-template-columns: 110px repeat(<?php echo count($dates); ?>, 110px);
        width: fit-content;
        min-width: fit-content;
        max-width: none;
    }

    /* Header Row */
    .calendar-grid-header {
        display: contents;
    }

    /* Month Header Row */
    .calendar-month-header {
        display: contents;
    }

    .grid-month-room {
        background: #f8fafc;
        border-right: 2px solid #e2e8f0;
        border-bottom: 1px solid #e2e8f0;
        position: sticky;
        left: 0;
        z-index: 41;
        min-width: 110px;
        max-width: 110px;
    }

    .grid-month-label {
        background: #f8fafc;
        color: #334155;
        font-weight: 700;
        font-size: 0.85rem;
        letter-spacing: 1px;
        padding: 0.25rem 0;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        min-height: 26px;
        overflow: visible;
    }

    .grid-month-label span {
        position: sticky;
        left: 117px;
        z-index: 2;
        background: #f8fafc;
        padding: 0 0.5rem;
    }

    body[data-theme="dark"] .grid-month-room {
        background: #1e293b;
        border-color: #334155;
    }

    body[data-theme="dark"] .grid-month-label {
        background: #1e293b;
        border-color: #334155;
        color: #cbd5e1;
    }

    body[data-theme="dark"] .grid-month-label span {
        background: #1e293b;
    }

    .grid-header-room {
        background: linear-gradient(135deg, #f1f5f9 0%, #ffffff 100%);
        border-right: 2px solid #e2e8f0;
        backdrop-filter: none;
        border-bottom: 2px solid #cbd5e1;
        padding: 0.3rem 0.5rem;
        font-weight: 800;
        text-align: center;
        position: sticky;
        left: 0;
        z-index: 40;
        font-size: 0.82rem;
        color: #475569;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.04);
        letter-spacing: 1px;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 50px;
        min-width: 110px;
        max-width: 110px;
    }

    /* Light theme - better header visibility */
    body[data-theme="light"] .grid-header-room {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        font-weight: 900;
        border-right: 2px solid #cbd5e1;
        border-bottom: 2px solid #94a3b8;
        color: #1e293b;
    }

    /* Dark theme - header room */
    body[data-theme="dark"] .grid-header-room {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border-right: 2px solid #334155;
        border-bottom: 2px solid #475569;
        color: #e2e8f0;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.3);
    }

    .grid-header-date {
        background: linear-gradient(180deg, #f8fafc, #f1f5f9);
        border-right: 1px solid #e2e8f0;
        border-bottom: 2px solid #cbd5e1;
        padding: 0.25rem 0.15rem;
        text-align: center;
        font-weight: 700;
        font-size: 0.75rem;
        color: #334155;
        position: relative;
        min-height: 50px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
    }

    /* Light theme - visible borders */
    body[data-theme="light"] .grid-header-date {
        border-right: 1px solid #cbd5e1;
        border-bottom: 2px solid #94a3b8;
        background: linear-gradient(180deg, #ffffff, #f8fafc);
        color: #1e293b;
    }

    /* Dark theme - header date */
    body[data-theme="dark"] .grid-header-date {
        background: linear-gradient(180deg, #1e293b, #0f172a);
        border-right: 1px solid #334155;
        border-bottom: 2px solid #475569;
        color: #e2e8f0;
    }

    /* Dark theme - date cells */
    body[data-theme="dark"] .grid-date-cell {
        border-right: 0.5px solid rgba(71, 85, 105, 0.3);
        border-bottom: 0.5px solid rgba(71, 85, 105, 0.3);
    }

    body[data-theme="dark"] .grid-date-cell:hover {
        background: rgba(99, 102, 241, 0.08);
    }

    /* TODAY HIGHLIGHT - SIMPLE & ELEGANT */
    .grid-header-date.today {
        background: rgba(99, 102, 241, 0.1) !important;
    }

    .grid-header-date.today .grid-header-date-num {
        color: #6366f1;
        font-weight: 900;
    }

    .grid-date-cell.today {
        background: rgba(99, 102, 241, 0.05) !important;
    }

    /* Light theme - more visible today highlight */
    body[data-theme="light"] .grid-header-date.today {
        background: rgba(99, 102, 241, 0.15) !important;
    }

    body[data-theme="light"] .grid-date-cell.today {
        background: rgba(99, 102, 241, 0.08) !important;
    }

    .grid-header-date-day {
        display: block;
        font-size: 0.75rem;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.3px;
        color: #334155;
        line-height: 1.1;
    }

    .grid-header-date-occ {
        display: block;
        font-size: 0.65rem;
        font-weight: 600;
        color: #64748b;
        line-height: 1;
    }

    .grid-header-date-avail {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.68rem;
        font-weight: 800;
        color: #10b981;
        background: rgba(16, 185, 129, 0.1);
        border-radius: 50%;
        width: 20px;
        height: 20px;
        line-height: 1;
    }

    .grid-header-date-avail.full {
        color: #ef4444;
        background: rgba(239, 68, 68, 0.1);
    }

    .grid-header-date-num {
        display: inline;
        font-size: 0.95rem;
        font-weight: 900;
        line-height: 1;
        color: #1e293b;
        margin-left: 0.15rem;
    }

    .grid-header-price {
        display: none;
    }

    /* Footer Row - Bottom Date Reference */
    .calendar-grid-footer {
        display: contents;
    }

    .grid-footer-room {
        background: linear-gradient(135deg, #f1f5f9 0%, #ffffff 100%);
        border-right: 2px solid #e2e8f0;
        border-top: 2px solid #cbd5e1;
        padding: 0.3rem 0.5rem;
        font-weight: 800;
        text-align: center;
        position: sticky;
        left: 0;
        z-index: 40;
        font-size: 0.82rem;
        color: #475569;
        letter-spacing: 1px;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 50px;
        min-width: 110px;
        max-width: 110px;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.04);
    }

    body[data-theme="light"] .grid-footer-room {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        font-weight: 900;
        border-right: 2px solid #cbd5e1;
        border-top: 2px solid #94a3b8;
        color: #1e293b;
    }

    body[data-theme="dark"] .grid-footer-room {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border-right: 2px solid #334155;
        border-top: 2px solid #475569;
        color: #e2e8f0;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.3);
    }

    .grid-footer-date {
        background: linear-gradient(180deg, #f8fafc, #f1f5f9);
        border-right: 1px solid #e2e8f0;
        border-top: 2px solid #cbd5e1;
        padding: 0.25rem 0.2rem;
        text-align: center;
        font-weight: 700;
        font-size: 0.82rem;
        color: #334155;
        min-height: 50px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
    }

    body[data-theme="light"] .grid-footer-date {
        border-right: 1px solid #cbd5e1;
        border-top: 2px solid #94a3b8;
        background: linear-gradient(180deg, #ffffff, #f8fafc);
        color: #1e293b;
    }

    body[data-theme="dark"] .grid-footer-date {
        background: linear-gradient(180deg, #1e293b, #0f172a);
        border-right: 1px solid #334155;
        border-top: 2px solid #475569;
        color: #e2e8f0;
    }

    .grid-footer-date.today {
        background: rgba(99, 102, 241, 0.15) !important;
    }

    .grid-footer-date-day {
        display: block;
        font-size: 0.75rem;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.3px;
        color: #334155;
    }

    .grid-footer-date-num {
        display: block;
        font-size: 0.65rem;
        font-weight: 600;
        color: #64748b;
        line-height: 1;
    }

    /* Room Row */
    .grid-room-label {
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-right: 2px solid #e2e8f0;
        border-bottom: 1px solid #f1f5f9;
        padding: 0.2rem 0.4rem;
        font-weight: 700;
        color: #334155;
        position: sticky;
        left: 0;
        z-index: 30;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        gap: 0.1rem;
        min-width: 110px;
        max-width: 110px;
        cursor: grab;
        font-size: 0.85rem;
        min-height: 28px;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.04);
        white-space: nowrap;
        transition: all 0.2s ease;
    }

    .grid-room-label:hover {
        background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
        border-right-color: #a5b4fc;
    }

    /* Light theme - better room label contrast */
    body[data-theme="light"] .grid-room-label {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        color: #1e293b;
        font-weight: 800;
        border-right: 2px solid #cbd5e1;
        border-bottom: 1px solid #e2e8f0;
    }

    body[data-theme="light"] .grid-room-label:hover {
        background: linear-gradient(135deg, #eef2ff 0%, #dbeafe 100%);
        border-right-color: #818cf8;
    }

    /* Dark theme - room label */
    body[data-theme="dark"] .grid-room-label {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        color: #f1f5f9;
        border-right: 2px solid #334155;
        border-bottom: 1px solid #1e293b;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.3);
    }

    body[data-theme="dark"] .grid-room-label:hover {
        background: linear-gradient(135deg, #312e81 0%, #1e1b4b 100%);
        color: #e0e7ff;
        border-right-color: #6366f1;
    }

    /* DIRTY room (kamar habis check-out, perlu dibersihkan) */
    .grid-room-label.dirty {
        background: linear-gradient(135deg, #fef9c3 0%, #fde68a 100%) !important;
        color: #854d0e !important;
        border-right: 2px solid #f59e0b !important;
        box-shadow: inset 0 0 0 2px rgba(245, 158, 11, 0.25);
    }

    .grid-room-label.dirty:hover {
        background: linear-gradient(135deg, #fde68a 0%, #fcd34d 100%) !important;
    }

    .room-dirty-tag {
        display: inline-block;
        background: #d97706;
        color: #fff;
        font-size: 0.55rem;
        font-weight: 800;
        letter-spacing: 0.5px;
        padding: 1px 6px;
        border-radius: 6px;
        margin-top: 2px;
        line-height: 1.3;
        text-transform: uppercase;
    }

    .room-clean-btn {
        margin-top: 3px;
        background: #16a34a;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 2px 8px;
        font-size: 0.6rem;
        font-weight: 700;
        cursor: pointer;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        box-shadow: 0 1px 2px rgba(22, 163, 74, 0.35);
        transition: transform 0.12s, box-shadow 0.12s;
    }

    .room-clean-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(22, 163, 74, 0.5);
    }

    .grid-room-type-label {
        font-size: 0.65rem;
        font-weight: 600;
        color: #6366f1;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        line-height: 1;
    }

    body[data-theme="dark"] .grid-room-type-label {
        color: #a5b4fc;
    }

    .grid-room-number {
        font-size: 0.88rem;
        color: #1e293b;
        font-weight: 900;
        line-height: 1;
        letter-spacing: 0.3px;
    }

    body[data-theme="dark"] .grid-room-number {
        color: #f1f5f9;
    }

    .grid-room-type-header {
        background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
        border-right: 2px solid #a5b4fc;
        border-bottom: 1px solid #c7d2fe;
        padding: 0.15rem 0.4rem;
        font-weight: 800;
        color: #4338ca;
        position: sticky;
        left: 0;
        z-index: 30;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        font-size: 0.78rem;
        gap: 0.2rem;
        min-width: 110px;
        max-width: 110px;
        min-height: 26px;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.04);
        letter-spacing: 0.3px;
    }

    /* Light theme - better type header visibility */
    body[data-theme="light"] .grid-room-type-header {
        background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
        color: #4338ca;
        font-weight: 900;
        border-right: 2px solid #a5b4fc;
        border-bottom: 1px solid #c7d2fe;
    }

    /* Dark theme - type header */
    body[data-theme="dark"] .grid-room-type-header {
        background: linear-gradient(135deg, #312e81 0%, #1e1b4b 100%);
        color: #a5b4fc;
        border-right: 2px solid #6366f1;
        border-bottom: 1px solid #4338ca;
        box-shadow: 3px 0 8px rgba(0, 0, 0, 0.3);
    }

    /* Type Price Cell (date columns in type header row) */
    .grid-type-price-cell {
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        border-right: 1px solid #c7d2fe;
        border-bottom: 1px solid #a5b4fc;
        min-height: 30px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0;
        font-size: 0.7rem;
        font-weight: 700;
        color: #4338ca;
        letter-spacing: 0.2px;
    }

    .type-avail-count {
        font-size: 0.78rem;
        font-weight: 800;
        color: #4338ca;
        line-height: 1.2;
    }

    .type-price-text {
        font-size: 0.62rem;
        font-weight: 600;
        color: #6366f1;
        line-height: 1.1;
        white-space: nowrap;
    }

    body[data-theme="dark"] .grid-type-price-cell {
        background: linear-gradient(135deg, #312e81, #1e1b4b);
        border-right: 1px solid #4338ca;
        border-bottom: 1px solid #3730a3;
        color: #c7d2fe;
    }

    body[data-theme="light"] .grid-type-price-cell {
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        border-right: 1px solid #a5b4fc;
        border-bottom: 1px solid #818cf8;
        color: #3730a3;
    }

    /* Ensure booking bar text stays white in light theme */
    body[data-theme="light"] .booking-bar,
    body[data-theme="light"] .booking-bar span,
    body[data-theme="light"] .booking-bar * {
        color: #ffffff !important;
    }

    /* Maximum specificity - force white text in all scenarios */
    .booking-bar,
    .booking-bar span,
    .booking-bar>span,
    body .booking-bar,
    body .booking-bar span,
    body .booking-bar>span {
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }

    .grid-room-number {
        font-size: 0.85rem;
        color: var(--text-primary);
        font-weight: 900;
        line-height: 1;
        letter-spacing: 0.3px;
    }

    .grid-room-type {
        font-size: 0.72rem;
        color: var(--text-secondary);
        font-weight: 600;
        line-height: 1;
        opacity: 0.7;
    }

    .grid-room-price {
        display: none;
    }

    /* Date Cells */
    .grid-date-cell {
        border-right: 0.5px solid var(--border-color);
        border-bottom: 0.5px solid var(--border-color);
        padding: 0.05rem 0.03rem;
        min-height: 28px;
        position: relative;
        background: transparent;
        cursor: pointer;
        transition: background 0.15s ease;
    }

    /* Same-day turnover divider - HIDDEN */
    .grid-date-cell.has-turnover::before {
        display: none;
    }

    /* Light theme - visible cell borders */
    body[data-theme="light"] .grid-date-cell {
        border-right: 1px solid rgba(51, 65, 85, 0.15);
        border-bottom: 1px solid rgba(51, 65, 85, 0.15);
    }

    .grid-date-cell:last-child {
        border-right: none;
    }

    .grid-date-cell:hover {
        background: rgba(99, 102, 241, 0.05);
    }

    .grid-date-cell.click-selected {
        background: rgba(99, 102, 241, 0.25) !important;
        outline: 2px solid #6366f1;
        outline-offset: -2px;
    }

    /* Booking Bars - CLOUDBED STYLE (Noon to Noon) */
    .booking-bar-container {
        position: absolute;
        top: 2px;
        left: 1px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        overflow: visible;
        pointer-events: auto;
        z-index: 10;
        margin-left: 0;
    }

    .booking-bar {
        width: 100%;
        height: 22px;
        padding: 0 0.4rem;
        cursor: pointer;
        overflow: visible;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        transition: all 0.2s ease;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.08);
        font-weight: 400;
        font-size: 0.66rem;
        line-height: 1;
        position: relative;
        pointer-events: auto;
        border-radius: 3px;
        white-space: nowrap;
        transform: skewX(-20deg);
        background: linear-gradient(135deg, #2a3552, #37476b) !important;
        color: #ffffff !important;
    }

    .booking-bar>span {
        transform: skewX(20deg);
        color: #ffffff !important;
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.25);
        font-weight: 400;
        font-size: 0.62rem;
        display: block;
    }

    .booking-bar *,
    .booking-bar>* {
        color: #ffffff !important;
    }

    .booking-bar::before {
        content: '';
        position: absolute;
        left: -6px;
        top: 50%;
        transform: translateY(-50%);
        width: 0;
        height: 0;
        border-top: 8px solid transparent;
        border-bottom: 8px solid transparent;
        border-right: 5px solid;
        border-right-color: inherit;
    }

    .booking-bar::after {
        content: '';
        position: absolute;
        right: -6px;
        top: 50%;
        transform: translateY(-50%);
        width: 0;
        height: 0;
        border-top: 8px solid transparent;
        border-bottom: 8px solid transparent;
        border-left: 5px solid;
        border-left-color: inherit;
    }

    .booking-bar:hover {
        transform: skewX(-20deg) scaleY(1.15);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        z-index: 20;
    }

    /* Past Booking Styling - Samar-samar Abu-abu Transparan */
    .booking-bar.booking-past {
        opacity: 0.4 !important;
        background: linear-gradient(135deg, #9ca3af, #d1d5db) !important;
        border-right-color: #9ca3af !important;
        border-left-color: #d1d5db !important;
    }

    .booking-bar.booking-past>span {
        color: #6b7280 !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1) !important;
    }

    .booking-bar.booking-past:hover {
        opacity: 0.6 !important;
        transform: skewX(-20deg) scaleY(1.1);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
    }

    /* Status specific bars */
    .booking-confirmed {
        background: linear-gradient(135deg, #2a3552, #37476b) !important;
        border-right-color: #2a3552;
        border-left-color: #37476b;
    }

    .booking-pending {
        background: linear-gradient(135deg, #314166, #415787) !important;
        border-right-color: #314166;
        border-left-color: #415787;
    }

    .booking-checked-in {
        background: linear-gradient(135deg, #0f8a65, #0b6b4e) !important;
        border-right-color: #0f8a65;
        border-left-color: #0b6b4e;
    }

    .booking-blocked {
        background: linear-gradient(135deg, #ef4444, #f97316) !important;
        border-right-color: #ef4444;
        border-left-color: #f97316;
    }

    .booking-bar-guest,
    .booking-bar-code,
    .booking-bar-status {
        color: #ffffff !important;
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.2);
        font-weight: 400;
    }

    /* Action buttons on booking bars */
    .bar-action-btn {
        position: absolute;
        right: 4px;
        top: 50%;
        transform: skewX(20deg) translateY(-50%);
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 1.5px solid rgba(255, 255, 255, 0.7);
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
        font-size: 0.65rem;
        font-weight: 900;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: all 0.2s ease;
        z-index: 15;
        line-height: 1;
        padding: 0;
    }

    .booking-bar:hover .bar-action-btn {
        opacity: 1;
    }

    .bar-action-btn:hover {
        background: rgba(255, 255, 255, 0.5);
        transform: skewX(20deg) translateY(-50%) scale(1.15);
        border-color: #fff;
    }

    .bar-extend-btn {
        background: rgba(16, 185, 129, 0.5);
        border-color: rgba(255, 255, 255, 0.8);
    }

    .bar-edit-btn {
        background: rgba(99, 102, 241, 0.5);
        border-color: rgba(255, 255, 255, 0.8);
    }

    .bar-delete-btn {
        background: rgba(239, 68, 68, 0.75);
        border-color: rgba(255, 255, 255, 0.9);
    }

    /* Drag & Drop Styles */
    .booking-bar-container[draggable="true"] {
        cursor: grab;
    }

    .booking-bar-container[draggable="true"]:active {
        cursor: grabbing;
    }

    .booking-bar-container.dragging {
        opacity: 0.4;
        z-index: 50;
    }

    .grid-date-cell.drag-over {
        background: rgba(99, 102, 241, 0.15) !important;
        outline: 2px dashed #6366f1;
        outline-offset: -2px;
    }

    .grid-date-cell.drag-over-valid {
        background: rgba(16, 185, 129, 0.15) !important;
        outline: 2px dashed #10b981;
    }

    .grid-date-cell.drag-over-invalid {
        background: rgba(239, 68, 68, 0.15) !important;
        outline: 2px dashed #ef4444;
    }

    /* Extend Stay Modal */
    .extend-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        justify-content: center;
        align-items: center;
    }

    .extend-modal-overlay.active {
        display: flex;
    }

    .extend-modal {
        background: var(--card-bg, #fff);
        border-radius: 12px;
        padding: 1.5rem;
        width: 360px;
        max-width: 90vw;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .extend-modal h3 {
        margin: 0 0 1rem;
        font-size: 1rem;
        color: var(--text-primary);
    }

    .extend-modal .form-group {
        margin-bottom: 0.75rem;
    }

    .extend-modal label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
    }

    .extend-modal input,
    .extend-modal select {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        font-size: 0.85rem;
        background: var(--card-bg, #fff);
        color: var(--text-primary);
    }

    .extend-modal .modal-actions {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
        margin-top: 1rem;
    }

    .extend-modal .btn-cancel {
        padding: 0.45rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: transparent;
        color: var(--text-secondary);
        cursor: pointer;
        font-size: 0.8rem;
    }

    .extend-modal .btn-confirm {
        padding: 0.45rem 1rem;
        border: none;
        border-radius: 6px;
        background: #10b981;
        color: #fff;
        cursor: pointer;
        font-weight: 700;
        font-size: 0.8rem;
    }

    .extend-modal .btn-confirm:hover {
        background: #059669;
    }

    /* Edit Reservation Modal */
    .edit-res-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        justify-content: center;
        align-items: center;
    }

    .edit-res-overlay.active {
        display: flex;
    }

    .edit-res-modal {
        background: var(--card-bg, #fff);
        border-radius: 12px;
        padding: 1.5rem;
        width: 480px;
        max-width: 95vw;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .edit-res-modal h3 {
        margin: 0 0 1rem;
        font-size: 1rem;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }

    .edit-res-modal .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .edit-res-modal .form-group {
        margin-bottom: 0.75rem;
    }

    .edit-res-modal label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
    }

    .edit-res-modal input,
    .edit-res-modal select,
    .edit-res-modal textarea {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        font-size: 0.85rem;
        background: var(--card-bg, #fff);
        color: var(--text-primary);
        box-sizing: border-box;
    }

    .edit-res-modal textarea {
        resize: vertical;
        min-height: 60px;
    }

    .edit-res-modal .modal-actions {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
        margin-top: 1rem;
    }

    .edit-res-modal .btn-cancel {
        padding: 0.45rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: transparent;
        color: var(--text-secondary);
        cursor: pointer;
        font-size: 0.8rem;
    }

    .edit-res-modal .btn-save {
        padding: 0.45rem 1rem;
        border: none;
        border-radius: 6px;
        background: #6366f1;
        color: #fff;
        cursor: pointer;
        font-weight: 700;
        font-size: 0.8rem;
    }

    .edit-res-modal .btn-save:hover {
        background: #4f46e5;
    }

    /* Legend */
    .legend {
        display: flex;
        flex-wrap: wrap;
        gap: 0.8rem;
        margin-top: 0.4rem;
        padding: 0.4rem 0.5rem;
        background: var(--card-bg);
        backdrop-filter: blur(30px);
        border: 0.5px solid var(--border-color);
        border-radius: 8px;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .legend-color {
        width: 20px;
        height: 20px;
        border-radius: 3px;
        border: 0.5px solid var(--border-color);
    }

    .legend-label {
        font-weight: 600;
        font-size: 0.65rem;
        color: var(--text-primary);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .calendar-container {
            padding: 0.3rem 0.15rem;
        }

        .calendar-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .calendar-header h1 {
            font-size: 1.2rem;
        }

        .grid-header-date {
            padding: 0.1rem;
            font-size: 0.58rem;
        }

        .grid-header-date-num {
            font-size: 0.68rem;
        }

        .grid-room-label {
            padding: 0.1rem 0.25rem;
            min-width: 90px;
            font-size: 0.7rem;
        }

        .grid-room-type-header {
            min-width: 90px;
            font-size: 0.62rem;
        }

        .grid-date-cell {
            min-height: 24px;
        }

        .booking-bar {
            height: 18px;
            font-size: 0.56rem;
        }

        .calendar-nav {
            flex-direction: column;
            gap: 0.4rem;
        }

        .form-row-3 {
            grid-template-columns: 1fr 1fr;
        }

        .form-row-3 .form-group:last-child {
            grid-column: 1 / -1;
        }
    }

    @media (max-width: 480px) {
        .calendar-wrapper {
            padding: 0.2rem;
        }

        .grid-room-label {
            padding: 0.1rem 0.2rem;
            font-size: 0.62rem;
            min-width: 70px;
        }

        .grid-room-type-header {
            min-width: 70px;
            font-size: 0.58rem;
        }

        .grid-date-cell {
            min-height: 22px;
            padding: 0.05rem;
        }

        .booking-bar {
            height: 16px;
            font-size: 0.52rem;
            padding: 0.1rem;
            color: #ffffff !important;
        }

        .form-row-3 {
            grid-template-columns: 1fr;
        }

        .form-row-3 .form-group:last-child {
            grid-column: 1;
        }

        .grid-header-date-num {
            font-size: 0.8rem;
        }

        .legend {
            flex-direction: column;
            gap: 0.5rem;
        }
    }

    /* ============================================
   MODAL POPUP STYLES
   ============================================ */
    .modal-overlay {
        display: none !important;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(5px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.active {
        display: flex !important;
    }

    .modal-content {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1.5rem;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        animation: slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        z-index: 10000;
    }

    body[data-theme="light"] .modal-content {
        background: white;
        border: 1px solid rgba(51, 65, 85, 0.15);
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal-header {
        margin-bottom: 1rem;
        text-align: center;
    }

    .modal-header h2 {
        color: var(--text-primary);
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
    }

    .modal-header p {
        color: var(--text-secondary);
        font-size: 0.8rem;
    }

    .modal-close {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        background: rgba(239, 68, 68, 0.15);
        border: 2px solid rgba(239, 68, 68, 0.3);
        color: #ef4444;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 1.5rem;
        font-weight: 700;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        z-index: 1000;
    }

    .modal-close:hover {
        background: rgba(239, 68, 68, 0.25);
        border-color: rgba(239, 68, 68, 0.5);
        color: #dc2626;
        transform: rotate(90deg) scale(1.1);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .modal-actions {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .modal-btn {
        padding: 1rem;
        border: none;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        font-size: 1rem;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .modal-btn-primary {
        background: linear-gradient(135deg, #10b981, #34d399);
        color: white;
    }

    .modal-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(16, 185, 129, 0.3);
    }

    .modal-btn-secondary {
        background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
        color: white;
    }

    .modal-btn-secondary:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(30, 58, 138, 0.3);
    }

    .modal-date-info {
        background: rgba(30, 58, 138, 0.12);
        border: 1px solid rgba(30, 58, 138, 0.3);
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 1rem;
        text-align: center;
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    /* RESERVATION FORM STYLES */
    .modal-content-large {
        max-width: 650px;
        height: 90vh;
        /* Fixed height for flex container */
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        /* Header/Footer static, body triggers scroll */
    }

    #reservationForm {
        display: flex;
        flex-direction: column;
        height: 100%;
        min-height: 0;
        overflow: hidden;
    }

    .modal-content-medium {
        max-width: 500px;
        max-height: 80vh;
        overflow-y: auto;
    }

    /* Booking Details Modal Styles */
    .booking-details-content {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        margin: 1rem 0;
    }

    .detail-section {
        background: rgba(99, 102, 241, 0.05);
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 8px;
        padding: 0.75rem;
    }

    body[data-theme="light"] .detail-section {
        background: rgba(248, 250, 252, 0.8);
        border: 1px solid rgba(51, 65, 85, 0.15);
    }

    .detail-section h3 {
        color: var(--text-primary);
        font-size: 0.8rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.4rem;
    }

    .detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.3rem 0;
    }

    .detail-label {
        color: var(--text-secondary);
        font-size: 0.75rem;
        font-weight: 600;
    }

    .detail-value {
        color: var(--text-primary);
        font-size: 0.8rem;
        font-weight: 700;
        text-align: right;
    }

    .status-badge {
        padding: 0.25rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-badge.status-confirmed {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }

    .status-badge.status-pending {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
    }

    .status-badge.status-checked_in {
        background: rgba(59, 130, 246, 0.2);
        color: #3b82f6;
    }

    .status-badge.status-paid {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }

    .status-badge.status-unpaid {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
    }

    .status-badge.status-partial {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
    }

    /* Booking Action Buttons */
    .booking-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
    }

    .btn-action {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.85rem 1rem;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-checkin {
        background: linear-gradient(135deg, #10b981, #34d399);
        color: white;
    }

    .btn-checkin:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    }

    .btn-checkout {
        background: linear-gradient(135deg, #3b82f6, #60a5fa);
        color: white;
    }

    .btn-checkout:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
    }

    .btn-move {
        background: linear-gradient(135deg, #f59e0b, #fbbf24);
        color: white;
    }

    .btn-move:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
    }

    .form-grid {
        display: grid;
        gap: 1rem;
    }

    .form-section {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 0.85rem;
    }

    body[data-theme="light"] .form-section {
        background: rgba(248, 250, 252, 0.5);
        border: 1px solid rgba(51, 65, 85, 0.15);
    }

    .form-section h3 {
        color: var(--text-primary);
        font-size: 0.8rem;
        font-weight: 700;
        margin-bottom: 0.65rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.75rem;
    }

    .form-row-3 {
        grid-template-columns: repeat(3, 1fr);
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }

    .form-group label {
        color: var(--text-secondary);
        font-size: 0.75rem;
        font-weight: 600;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        background: var(--sidebar-bg);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 0.6rem 0.75rem;
        color: var(--text-primary);
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }

    .readonly-input {
        background: rgba(99, 102, 241, 0.1) !important;
        cursor: not-allowed;
        font-weight: 600;
        color: #6366f1 !important;
    }

    body[data-theme="light"] .readonly-input {
        background: rgba(99, 102, 241, 0.08) !important;
    }

    body[data-theme="light"] .form-group input,
    body[data-theme="light"] .form-group select,
    body[data-theme="light"] .form-group textarea {
        background: white;
        border: 1px solid rgba(51, 65, 85, 0.2);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
    }

    .form-group textarea {
        resize: vertical;
        font-family: inherit;
        min-height: 70px;
    }

    .payment-method-group,
    .dp-percent-group {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .payment-method-btn,
    .dp-percent-btn {
        border: 1px solid var(--border-color);
        background: var(--sidebar-bg);
        color: var(--text-primary);
        padding: 0.35rem 0.6rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .payment-method-btn:hover,
    .dp-percent-btn:hover {
        border-color: #6366f1;
        color: #6366f1;
    }

    .payment-method-btn.active,
    .dp-percent-btn.active {
        background: #6366f1;
        color: white;
        border-color: #6366f1;
    }

    .price-summary {
        background: var(--sidebar-bg);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 0.75rem;
        margin-top: 0.5rem;
    }

    body[data-theme="light"] .price-summary {
        background: rgba(16, 185, 129, 0.05);
        border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.4rem 0;
        color: var(--text-secondary);
        font-size: 0.8rem;
    }

    .price-row strong {
        color: var(--text-primary);
        font-size: 0.85rem;
        font-weight: 600;
    }

    .price-row-total {
        border-top: 1px solid var(--border-color);
        margin-top: 0.4rem;
        padding-top: 0.6rem;
        font-size: 0.9rem;
        font-weight: 700;
    }

    body[data-theme="light"] .price-row-total {
        border-top-color: rgba(16, 185, 129, 0.3);
    }

    .price-row-total strong {
        color: #10b981;
        font-size: 1rem;
    }

    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
    }

    .btn-primary,
    .btn-secondary {
        padding: 0.6rem 1.25rem;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }

    .btn-primary {
        background: linear-gradient(135deg, #10b981, #34d399);
        color: white !important;
    }

    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn-secondary {
        background: var(--sidebar-bg);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
    }

    body[data-theme="light"] .btn-secondary {
        background: white;
        border: 1px solid rgba(51, 65, 85, 0.2);
    }

    .btn-secondary:hover {
        background: rgba(30, 58, 138, 0.1);
        border-color: #1e3a8a;
    }

    .modal-date-info strong {
        color: var(--text-primary);
        font-size: 1.1rem;
        display: block;
        margin-top: 0.5rem;
    }

    /* Dashboard Stats Grid - Elegant Modern Design */
    .stats-dashboard-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .stats-card {
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(249, 250, 251, 0.9));
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 10px;
        padding: 1rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
    }

    .stats-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(99, 102, 241, 0.15);
    }

    body[data-theme="light"] .stats-card {
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(249, 250, 251, 0.9));
        border-color: rgba(99, 102, 241, 0.15);
    }

    body[data-theme="dark"] .stats-card {
        background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.9));
        border-color: rgba(99, 102, 241, 0.2);
    }

    .stats-card h3 {
        font-size: 0.75rem;
        font-weight: 700;
        margin: 0 0 0.75rem 0;
        color: #6366f1;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid rgba(99, 102, 241, 0.15);
    }

    .stats-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .stats-list li {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.6rem 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    body[data-theme="dark"] .stats-list li {
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .stats-list li:last-child {
        border-bottom: none;
    }

    .stat-info {
        display: flex;
        flex-direction: column;
        gap: 3px;
        flex: 1;
    }

    .stat-name {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.85rem;
    }

    .stat-meta {
        font-size: 0.7rem;
        color: #64748b;
    }

    .stat-tag {
        font-size: 0.7rem;
        padding: 3px 8px;
        border-radius: 4px;
        font-weight: 600;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        text-shadow: none !important;
        opacity: 1 !important;
        white-space: nowrap;
    }

    /* Hard lock white text on all calendar button-like elements */
    .calendar-container .btn-nav,
    .calendar-container .btn-nav *,
    .calendar-container .nav-btn,
    .calendar-container .nav-btn *,
    .calendar-container .today-btn,
    .calendar-container .today-btn *,
    .calendar-container #newReservationBtn,
    .calendar-container #newReservationBtn *,
    .calendar-container .stat-tag,
    .calendar-container .stat-tag *,
    .calendar-container .status-badge,
    .calendar-container .status-badge * {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        opacity: 1 !important;
    }

    @media (max-width: 1024px) {
        .stats-dashboard-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="calendar-container">
    <!-- Header -->
    <div class="calendar-header">
        <div>
            <h1><span class="icon">📆</span> Calendar Booking</h1>
        </div>
        <div class="calendar-controls">
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/reservasi.php" class="btn-nav">
                📋 List View
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/breakfast.php" class="btn-nav">
                🍽️ Breakfast List
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/settings.php" class="btn-nav">
                ⚙️ Settings
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/dashboard.php" class="btn-nav">
                📊 Dashboard
            </a>
        </div>
    </div>

    <?php
    // DASHBOARD STATS FETCH
    try {
        // 1. RECENT RESERVATIONS (Newest ID)
        $recentBookings = $db->fetchAll("
            SELECT b.booking_code, b.status, b.check_in_date, g.guest_name 
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            ORDER BY b.id DESC LIMIT 5
        ");

        // 2. RECENT CHECK-INS
        $recentCheckins = $db->fetchAll("
            SELECT b.booking_code, b.status, b.room_id, b.check_in_date, g.guest_name, r.room_number 
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.status = 'checked_in'
            ORDER BY b.check_in_date DESC, b.id DESC LIMIT 5
        ");

        // 3. RECENT CHECK-OUTS
        $recentCheckouts = $db->fetchAll("
            SELECT b.booking_code, b.status, b.room_id, b.check_out_date, g.guest_name, r.room_number 
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.status = 'checked_out'
            ORDER BY b.check_out_date DESC, b.id DESC LIMIT 5
        ");
    } catch (Exception $e) {
        $recentBookings = [];
        $recentCheckins = [];
        $recentCheckouts = [];
    }
    ?>



    <!-- Search Bar -->
    <div class="search-reservation-bar">
        <div class="search-input-wrapper">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8" />
                <line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
            <input type="text" id="searchReservation" class="search-input" placeholder="Search reservations, guests, and more" autocomplete="off">
            <button class="search-clear-btn" id="searchClearBtn" onclick="clearSearch()" style="display:none;">×</button>
        </div>
        <div class="search-results-dropdown" id="searchResults" style="display:none;"></div>
    </div>

    <!-- Navigation -->
    <div class="calendar-nav">
        <button class="nav-btn" id="prevMonthBtn" type="button">‹</button>
        <button class="nav-btn today-btn" id="todayBtn" type="button" onclick="goToToday()">TODAY</button>
        <button class="nav-btn" id="nextMonthBtn" type="button">›</button>
        <input type="date" class="nav-date-input" id="dateInput" value="<?php echo $startDate; ?>" onchange="changeDate()">
        <span class="date-display">
            <?php echo date('M d', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($startDate . ' +29 days')); ?>
        </span>
        <button class="nav-btn" id="newReservationBtn" type="button" onclick="openNewReservationForm()" style="background: linear-gradient(135deg, #1e3a8a, #1d4ed8); color: #fff; margin-left: auto;">
            ➕ New Reservation
        </button>
    </div>

    <!-- Calendar Grid - WRAPPED IN SCROLL CONTAINER -->
    <div class="calendar-scroll-wrapper" id="drag-container" style="overflow-x: auto; cursor: grab; user-select: none;">
        <div class="calendar-wrapper">
            <div class="calendar-grid">
                <!-- Month Header Row -->
                <div class="calendar-month-header">
                    <div class="grid-month-room"></div>
                    <?php
                    // Calculate month spans
                    $monthSpans = [];
                    $currentMonth = null;
                    $spanCount = 0;
                    foreach ($dates as $i => $date) {
                        $monthKey = date('M Y', strtotime($date));
                        if ($monthKey !== $currentMonth) {
                            if ($currentMonth !== null) {
                                $monthSpans[] = ['label' => strtoupper($currentMonth), 'span' => $spanCount];
                            }
                            $currentMonth = $monthKey;
                            $spanCount = 1;
                        } else {
                            $spanCount++;
                        }
                    }
                    if ($currentMonth !== null) {
                        $monthSpans[] = ['label' => strtoupper($currentMonth), 'span' => $spanCount];
                    }
                    foreach ($monthSpans as $ms):
                    ?>
                        <div class="grid-month-label" style="grid-column: span <?php echo $ms['span']; ?>;">
                            <span><?php echo $ms['label']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Header Row -->
                <div class="calendar-grid-header">
                    <div class="grid-header-room">ROOMS</div>
                    <?php foreach ($dates as $date):
                        $avail = $availPerDate[$date] ?? 0;
                        $occPct = $totalRoomCount > 0 ? round((($totalRoomCount - $avail) / $totalRoomCount) * 100, 1) : 0;
                    ?>
                        <div class="grid-header-date<?php echo ($date === date('Y-m-d')) ? ' today' : ''; ?>">
                            <span class="grid-header-date-day"><?php echo strtoupper(substr(date('D', strtotime($date)), 0, 3)); ?> <?php echo date('d', strtotime($date)); ?></span>
                            <span class="grid-header-date-occ"><?php echo number_format($occPct, 0); ?>%</span>
                            <span class="grid-header-date-avail <?php echo $avail === 0 ? 'full' : ''; ?>"><?php echo $avail; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Room Rows -->
                <?php
                // Group rooms by type
                $roomsByType = [];
                foreach ($rooms as $room) {
                    $typeKey = $room['type_name'];
                    if (!isset($roomsByType[$typeKey])) {
                        $roomsByType[$typeKey] = [];
                    }
                    $roomsByType[$typeKey][] = $room;
                }

                // Display rooms grouped by type with type headers
                foreach ($roomsByType as $typeName => $typeRooms):
                    // Get base price from first room of this type
                    $typePrice = $typeRooms[0]['base_price'] ?? 0;
                ?>
                    <!-- Type Header Row -->
                    <div class="grid-room-type-header">
                        📂 <?php echo htmlspecialchars($typeName); ?>
                    </div>
                    <?php foreach ($dates as $date):
                        $typeAvail = $availPerTypeDate[$typeName][$date] ?? 0;
                    ?>
                        <div class="grid-type-price-cell">
                            <span class="type-avail-count"><?php echo $typeAvail; ?></span>
                            <?php if (!$isStaffView): ?>
                                <span class="type-price-text">Rp<?php echo number_format($typePrice, 0, ',', '.'); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <!-- Individual Rooms of This Type -->
                    <?php foreach ($typeRooms as $room): ?>
                        <?php $isDirty = (($room['status'] ?? '') === 'cleaning'); ?>
                        <div class="grid-room-label<?php echo $isDirty ? ' dirty' : ''; ?>" data-room-id="<?php echo (int)$room['id']; ?>">
                            <span class="grid-room-type-label"><?php echo htmlspecialchars($room['type_name']); ?></span>
                            <span class="grid-room-number"><?php echo htmlspecialchars($room['room_number']); ?></span>
                            <?php if ($isDirty): ?>
                                <span class="room-dirty-tag" title="Kamar perlu dibersihkan">🧹 Dirty</span>
                                <button type="button" class="room-clean-btn" onclick="event.stopPropagation(); markRoomClean(<?php echo (int)$room['id']; ?>, '<?php echo htmlspecialchars($room['room_number'], ENT_QUOTES); ?>');">✓ Clean</button>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($dates as $date): ?>
                            <?php
                            // Check for same-day turnover (checkout + checkin on same day)
                            $hasTurnover = false;
                            if (isset($bookingMatrix[$room['id']])) {
                                $checkouts = 0;
                                $checkins = 0;
                                foreach ($bookingMatrix[$room['id']] as $booking) {
                                    $checkinDate = date('Y-m-d', strtotime($booking['check_in_date']));
                                    $checkoutDate = date('Y-m-d', strtotime($booking['check_out_date']));
                                    if ($checkoutDate === $date) $checkouts++;
                                    if ($checkinDate === $date) $checkins++;
                                }
                                $hasTurnover = ($checkouts > 0 && $checkins > 0);
                            }
                            ?>
                            <div class="grid-date-cell<?php echo ($date === date('Y-m-d')) ? ' today' : ''; ?><?php echo $hasTurnover ? ' has-turnover' : ''; ?>"
                                data-date="<?php echo $date; ?>"
                                data-room-number="<?php echo htmlspecialchars($room['room_number']); ?>"
                                data-room-id="<?php echo $room['id']; ?>"
                                title="<?php echo htmlspecialchars($room['room_number']); ?> - <?php echo date('d M Y', strtotime($date)); ?><?php echo $hasTurnover ? ' (Turnover: CO + CI)' : ''; ?>"
                                onclick="openCellReservation(this)">
                                <?php
                                // Render room block bars (maintenance/out of order/etc)
                                if (isset($roomBlockMatrix[$room['id']])) {
                                    foreach ($roomBlockMatrix[$room['id']] as $block) {
                                        $blockStart = strtotime((string)$block['block_start_date']);
                                        $blockEnd = strtotime((string)$block['block_end_date']);
                                        $currentDate = strtotime($date);

                                        if ($currentDate === $blockStart) {
                                            $blockNights = max(1, (int)ceil(($blockEnd - $blockStart) / 86400));
                                            $barWidth = ($blockNights * 110) - 6;
                                            $reasonRaw = (string)($block['block_reason'] ?? 'maintenance');
                                            $reasonMap = [
                                                'maintenance' => 'Maintenance',
                                                'deep_cleaning' => 'Deep Cleaning',
                                                'owner_use' => 'Owner Use',
                                                'out_of_order' => 'Out of Order',
                                                'event_setup' => 'Event Setup',
                                                'other' => 'Block Room'
                                            ];
                                            $reasonText = $reasonMap[$reasonRaw] ?? ucfirst(str_replace('_', ' ', $reasonRaw));
                                            $notesText = trim((string)($block['notes'] ?? ''));
                                            $notesSuffix = $notesText !== '' ? ' • ' . $notesText : '';
                                            $blockLabel = '⛔ ' . $reasonText;
                                ?>
                                            <div class="booking-bar-container" style="left: 50%; width: <?php echo $barWidth; ?>px; z-index: 5;"
                                                data-block-id="<?php echo (int)$block['id']; ?>"
                                                data-room-id="<?php echo (int)$block['room_id']; ?>"
                                                data-block-start="<?php echo htmlspecialchars($block['block_start_date']); ?>"
                                                data-block-end="<?php echo htmlspecialchars($block['block_end_date']); ?>"
                                                data-block-reason="<?php echo htmlspecialchars($reasonRaw); ?>"
                                                data-block-notes="<?php echo htmlspecialchars($notesText); ?>">
                                                <div class="booking-bar booking-blocked"
                                                    style="background: linear-gradient(135deg, #ef4444, #f97316) !important; border-right-color: #ef4444; border-left-color: #f97316;"
                                                    onclick="event.stopPropagation();"
                                                    title="<?php echo htmlspecialchars($blockLabel . $notesSuffix); ?>">
                                                    <span><?php echo htmlspecialchars($blockLabel); ?></span>
                                                    <?php if (!$isStaffView): ?>
                                                        <button class="bar-action-btn bar-delete-btn" onclick="event.stopPropagation(); removeRoomBlock(<?php echo (int)$block['id']; ?>, '<?php echo htmlspecialchars($room['room_number'], ENT_QUOTES); ?>')" title="Batalkan Block">✕</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php
                                        }
                                    }
                                }

                                // Find bookings for this room and date - CLOUDBED STYLE (bar from noon to noon)
                                if (isset($bookingMatrix[$room['id']])) {
                                    foreach ($bookingMatrix[$room['id']] as $booking) {
                                        $checkinDate = strtotime($booking['check_in_date']);
                                        $checkoutDate = strtotime($booking['check_out_date']);
                                        $currentDate = strtotime($date);

                                        // Only render bar on check-in date
                                        if ($currentDate === $checkinDate) {
                                            // Calculate nights (days between check-in and check-out)
                                            $totalNights = ceil(($checkoutDate - $checkinDate) / 86400);

                                            // Calculate width: start from 50% of check-in cell, end at 50% of check-out cell
                                            // Width = (nights × 110px) - 6px gap = span from noon to noon with spacing
                                            $barWidth = ($totalNights * 110) - 6; // 110px per column minus 6px gap

                                            $statusClass = 'booking-' . str_replace('_', '-', $booking['status']);

                                            // Check if booking is past or checked out
                                            $today = strtotime(date('Y-m-d'));
                                            $isPastBooking = ($checkoutDate < $today);
                                            $isCheckedOut = ($booking['status'] === 'checked_out');

                                            if ($isPastBooking || $isCheckedOut) {
                                                $statusClass .= ' booking-past';
                                            }

                                            // Determine color based on status
                                            $isCheckedIn = ($booking['status'] === 'checked_in');
                                            if ($isCheckedOut || $isPastBooking) {
                                                $bookingColor = $checkedOutColor;
                                            } elseif ($isCheckedIn) {
                                                $bookingColor = $checkedInColor;
                                            } else {
                                                $bookingColor = $defaultColor;
                                            }

                                            // Add status icons
                                            $statusIcon = $isCheckedIn ? '✓ ' : ($isCheckedOut ? '📭 ' : '');

                                            $guestName = htmlspecialchars(substr($booking['guest_name'] ?? 'Guest', 0, 12));
                                            $bookingCode = htmlspecialchars($booking['booking_code']);
                                            $shortCode = substr($bookingCode, 0, 8); // Show first 8 chars
                                            $statusText = ucfirst(str_replace('_', ' ', $booking['status']));
                                        ?>
                                            <div class="booking-bar-container" style="left: 50%; width: <?php echo $barWidth; ?>px;"
                                                data-booking-id="<?php echo $booking['id']; ?>"
                                                data-room-id="<?php echo $booking['room_id']; ?>"
                                                data-check-in="<?php echo $booking['check_in_date']; ?>"
                                                data-check-out="<?php echo $booking['check_out_date']; ?>"
                                                data-status="<?php echo $booking['status']; ?>"
                                                data-nights="<?php echo $totalNights; ?>"
                                                data-guest="<?php echo $guestName; ?>"
                                                <?php if (!$isPastBooking && !$isCheckedOut): ?>draggable="true" <?php endif; ?>>
                                                <div class="booking-bar <?php echo $statusClass; ?>"
                                                    onclick="event.stopPropagation(); viewBooking(<?php echo $booking['id']; ?>, event);"
                                                    title="<?php echo $statusIcon . $guestName; ?> (<?php echo $bookingCode; ?>) - <?php echo $statusText; ?><?php echo $isPastBooking ? ' [PAST]' : ''; ?>">
                                                    <span><?php echo $statusIcon . $guestName; ?> • <?php echo $shortCode; ?></span>
                                                    <?php if ($isCheckedIn && !$isPastBooking): ?>
                                                        <button class="bar-action-btn bar-extend-btn" onclick="event.stopPropagation(); openExtendModal(<?php echo $booking['id']; ?>, '<?php echo $guestName; ?>', '<?php echo $booking['check_out_date']; ?>', <?php echo $totalNights; ?>)" title="Extend Stay">+</button>
                                                    <?php elseif (!$isCheckedIn): ?>
                                                        <button class="bar-action-btn bar-edit-btn" onclick="event.stopPropagation(); openEditReservationModal(<?php echo $booking['id']; ?>)" title="Edit Reservasi">✎</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                <?php
                                            break; // Only one bar per booking
                                        }
                                    }
                                }
                                ?>
                            </div>
                        <?php endforeach; // End dates loop for each room 
                        ?>
                    <?php endforeach; // End individual rooms loop 
                    ?>
                <?php endforeach; // End room types loop
                ?>

                <!-- FOOTER DATE ROW - Same as header for easy reference when scrolling -->
                <div class="calendar-grid-footer">
                    <div class="grid-footer-room">ROOMS</div>
                    <?php foreach ($dates as $date):
                        $avail = $availPerDate[$date] ?? 0;
                        $occPct = $totalRoomCount > 0 ? round((($totalRoomCount - $avail) / $totalRoomCount) * 100, 0) : 0;
                    ?>
                        <div class="grid-footer-date<?php echo ($date === date('Y-m-d')) ? ' today' : ''; ?>">
                            <span class="grid-footer-date-day"><?php echo strtoupper(substr(date('D', strtotime($date)), 0, 3)); ?> <?php echo date('d', strtotime($date)); ?></span>
                            <span class="grid-footer-date-num"><?php echo $occPct; ?>%</span>
                            <span class="grid-header-date-avail <?php echo $avail === 0 ? 'full' : ''; ?>"><?php echo $avail; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Legend -->
    <div class="legend">
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #1e2a5a, #1e3a8a);"></div>
            <span class="legend-label">📋 Booking (Confirmed/Pending)</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #ef4444, #f97316);"></div>
            <span class="legend-label">⛔ Block Room (Maintenance/dll)</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #10b981, #34d399);"></div>
            <span class="legend-label">✓ Checked In (Active)</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #9ca3af, #d1d5db); opacity: 0.4;"></div>
            <span class="legend-label">📭 Past Booking (History)</span>
        </div>
    </div>

    <!-- DASHBOARD STATS WIDGETS -->
    <div class="stats-dashboard-grid" style="margin-top: 1.5rem;">
        <!-- New Reservations -->
        <div class="stats-card">
            <h3>Reservasi Terbaru</h3>
            <ul class="stats-list">
                <?php if (empty($recentBookings)): ?>
                    <li style="justify-content:center; color:#94a3b8;">Belum ada data</li>
                <?php else: ?>
                    <?php
                    $displayBookings = array_slice($recentBookings, 0, 5); // Limit 5 items
                    $statsTagBlue = '#1e3a8a';
                    foreach ($displayBookings as $rb):
                        $bName = $rb['guest_name'] ?? 'Guest';
                        $bStats = str_replace('_', ' ', $rb['status']);
                        $bColor = $statsTagBlue;
                    ?>
                        <li>
                            <div class="stat-info">
                                <span class="stat-name"><?php echo htmlspecialchars(substr($bName, 0, 18)); ?></span>
                                <span class="stat-meta"><?php echo htmlspecialchars($rb['booking_code']); ?></span>
                            </div>
                            <span class="stat-tag" style="background:<?php echo $bColor; ?>; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important;"><?php echo ucfirst($bStats); ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Latest Check-ins -->
        <div class="stats-card">
            <h3>Check-in Terbaru</h3>
            <ul class="stats-list">
                <?php if (empty($recentCheckins)): ?>
                    <li style="justify-content:center; color:#94a3b8;">Belum ada data</li>
                <?php else: ?>
                    <?php
                    $displayCheckins = array_slice($recentCheckins, 0, 5); // Limit 5 items
                    foreach ($displayCheckins as $rc): ?>
                        <li>
                            <div class="stat-info">
                                <span class="stat-name"><?php echo htmlspecialchars(substr($rc['guest_name'] ?? '', 0, 18)); ?></span>
                                <span class="stat-meta">Room <?php echo $rc['room_number']; ?> • <?php echo date('d M', strtotime($rc['check_in_date'])); ?></span>
                            </div>
                            <span class="stat-tag" style="background:#1e3a8a; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important;">Active</span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Latest Check-outs -->
        <div class="stats-card">
            <h3>Checkout Terbaru</h3>
            <ul class="stats-list">
                <?php if (empty($recentCheckouts)): ?>
                    <li style="justify-content:center; color:#94a3b8;">Belum ada data</li>
                <?php else: ?>
                    <?php
                    $displayCheckouts = array_slice($recentCheckouts, 0, 5); // Limit 5 items
                    foreach ($displayCheckouts as $rco): ?>
                        <li>
                            <div class="stat-info">
                                <span class="stat-name"><?php echo htmlspecialchars(substr($rco['guest_name'] ?? '', 0, 18)); ?></span>
                                <span class="stat-meta">Room <?php echo $rco['room_number']; ?> • <?php echo date('d M', strtotime($rco['check_out_date'])); ?></span>
                            </div>
                            <span class="stat-tag" style="background:#1e3a8a; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important;">Done</span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>

</div>

<script>
    // Initialize OTA Fees from PHP - Create global variable (not just window property)
    var OTA_FEES = <?php echo json_encode($otaFees); ?>;
    var OTA_SOURCE_KEYS = <?php echo json_encode($otaSourceKeys); ?>;

    // Dynamic source name map from booking_sources table
    var SOURCE_NAMES = <?php
                        $sourceNames = [];
                        foreach ($bookingSources as $bs) {
                            $sourceNames[$bs['source_key']] = ($bs['icon'] ?? '') . ' ' . ($bs['source_name'] ?? ucfirst($bs['source_key']));
                        }
                        echo json_encode($sourceNames, JSON_UNESCAPED_UNICODE);
                        ?>;
    const IS_STAFF_VIEW = <?php echo $isStaffView ? 'true' : 'false'; ?>;

    // Global variables for reservation form (used across multiple functions)
    var currentSource = '';
    var currentFees = OTA_FEES;
    var reservationMode = 'reservation';

    window.setReservationMode = function setReservationMode(mode) {
        reservationMode = mode === 'block_room' ? 'block_room' : 'reservation';

        const guestInfoSection = document.getElementById('guestInfoSection');
        const guestCountSection = document.getElementById('guestCountSection');
        const sourcePaymentSection = document.getElementById('sourcePaymentSection');
        const priceSummarySection = document.getElementById('priceSummarySection');
        const paymentSection = document.getElementById('paymentSection');
        const blockInfoSection = document.getElementById('blockInfoSection');
        const submitBtn = document.getElementById('reservationSubmitBtn');
        const title = document.querySelector('#reservationModal .modal-header-compact h2');
        const guestNameInput = document.getElementById('guestName');
        const bookingSourceInput = document.getElementById('bookingSource');

        const isBlock = reservationMode === 'block_room';

        if (guestInfoSection) guestInfoSection.style.display = isBlock ? 'none' : 'grid';
        if (guestCountSection) guestCountSection.style.display = isBlock ? 'none' : 'flex';
        if (sourcePaymentSection) sourcePaymentSection.style.display = isBlock ? 'none' : 'grid';
        if (priceSummarySection) priceSummarySection.style.display = isBlock ? 'none' : 'block';
        if (paymentSection) paymentSection.style.display = isBlock ? 'none' : 'flex';
        if (blockInfoSection) blockInfoSection.style.display = isBlock ? 'block' : 'none';

        if (guestNameInput) guestNameInput.required = !isBlock;
        if (bookingSourceInput) bookingSourceInput.required = !isBlock;

        if (title) title.textContent = isBlock ? 'Block Room' : 'New Reservation';
        if (submitBtn) submitBtn.textContent = isBlock ? 'Save Block Room' : 'Save Reservation';

        calculateMultiRoomTotalCalendar();
    }

    window.removeRoomBlock = async function removeRoomBlock(blockId, roomNumber) {
        const ok = confirm(`Batalkan block room ${roomNumber || ''}?`);
        if (!ok) return;

        try {
            const fd = new FormData();
            fd.append('block_id', blockId);
            const res = await fetch('<?php echo BASE_URL; ?>/api/delete-room-block.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data && data.success) {
                alert(data.message || 'Block room dibatalkan');
                location.reload();
            } else {
                alert(data && data.message ? data.message : 'Gagal membatalkan block');
            }
        } catch (e) {
            alert('Gagal menghubungi server');
        }
    }

    window.viewBooking = function viewBooking(id, event) {
        event.preventDefault();
        event.stopPropagation();

        console.log('📋 Loading booking details:', id);

        // Fetch booking details via AJAX - use relative path from modules/frontdesk/
        fetch('../../api/get-booking-details.php?id=' + id)
            .then(response => {
                console.log('📡 API Response status:', response.status);
                return response.text();
            })
            .then(text => {
                console.log('📥 API Response text:', text);
                try {
                    const data = JSON.parse(text);
                    console.log('✅ Parsed JSON:', data);
                    if (data.success) {
                        console.log('🎯 Showing booking:', data.booking);
                        showBookingQuickView(data.booking);
                    } else {
                        console.error('❌ API Error:', data.message);
                        alert('Error: ' + data.message);
                    }
                } catch (e) {
                    console.error('❌ JSON Parse Error:', e);
                    console.error('Raw text:', text);
                    alert('Failed to parse response');
                }
            })
            .catch(error => {
                console.error('❌ Fetch Error:', error);
                alert('Failed to load booking details: ' + error.message);
            });
    }

    let currentPaymentBooking = null;

    // Side Panel - populate and show (Cloudbed-style)
    function showBookingQuickView(booking) {
        console.log('🎯 showBookingQuickView (side panel) called with:', booking);

        // DEBUG: Log group booking params
        console.log('📊 GROUP BOOKING PARAMS:');
        console.log('  - guest_id:', booking.guest_id, typeof booking.guest_id);
        console.log('  - check_in_date:', booking.check_in_date, typeof booking.check_in_date);
        console.log('  - check_out_date:', booking.check_out_date, typeof booking.check_out_date);
        console.log('  - group_bookings:', booking.group_bookings, 'count:', booking.group_bookings ? booking.group_bookings.length : 0);

        currentPaymentBooking = booking;
        const panel = document.getElementById('bookingQuickView');
        if (!panel) {
            alert('Side panel not found');
            return;
        }

        // Guest avatar initials
        const initials = (booking.guest_name || 'G').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
        document.getElementById('sp-avatar').textContent = initials;

        // Guest name & phone
        document.getElementById('sp-guest-name').textContent = booking.guest_name || '-';
        document.getElementById('sp-guest-phone').textContent = booking.guest_phone || '-';

        // WhatsApp link
        const waPhone = booking.guest_phone ? booking.guest_phone.replace(/^0/, '62').replace(/[^0-9]/g, '') : '';
        const waEl = document.getElementById('sp-wa-link');
        if (waPhone) {
            waEl.href = 'https://wa.me/' + waPhone;
            waEl.style.display = 'flex';
        } else {
            waEl.style.display = 'none';
        }

        // Status badge
        const statusEl = document.getElementById('sp-status');
        const statusMap = {
            checked_in: 'Checked In',
            confirmed: 'Confirmed',
            pending: 'Pending',
            checked_out: 'Checked Out',
            cancelled: 'Cancelled'
        };
        const statusColorMap = {
            checked_in: '#dcfce7;color:#16a34a',
            confirmed: '#dbeafe;color:#2563eb',
            pending: '#fef3c7;color:#d97706',
            checked_out: '#f1f5f9;color:#64748b',
            cancelled: '#fce4ec;color:#e53935'
        };
        statusEl.textContent = '● ' + (statusMap[booking.status] || booking.status);
        statusEl.style.cssText = 'font-size:0.78rem;font-weight:700;padding:4px 12px;border-radius:20px;background:' + (statusColorMap[booking.status] || '#f1f5f9;color:#475569');

        // Source badge - SIMPLIFIED & FIXED
        let bkSrc = (booking.booking_source || 'walk_in').trim().toLowerCase();

        // Hardcoded source name mapping
        const sourceNameMap = {
            'walk_in': 'Walk-In',
            'phone': 'Phone Booking',
            'online': 'Direct Online',
            'direct': 'Direct',
            'agoda': '🏨 OTA Agoda',
            'booking': '📱 OTA Booking.com',
            'tiket': '✈️ OTA Tiket.com',
            'traveloka': '🎫 OTA Traveloka',
            'airbnb': '🏠 OTA Airbnb',
            'expedia': '🗺️ OTA Expedia',
            'pegipegi': '🧳 OTA Pegipegi',
            'ota': '🌐 OTA Lainnya'
        };

        // Determine display source
        let displaySource = sourceNameMap[bkSrc] || bkSrc.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

        // Update element
        const sourceEl = document.getElementById('sp-source');
        if (sourceEl) {
            sourceEl.textContent = displaySource;
        }

        // Timeline
        const fmtD = (d) => d ? new Date(d).toLocaleDateString('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        }) : '-';
        document.getElementById('sp-booked-date').textContent = fmtD(booking.created_at);
        document.getElementById('sp-checkin-date').textContent = fmtD(booking.check_in_date);
        document.getElementById('sp-checkout-date').textContent = fmtD(booking.check_out_date);
        // Timeline progress
        let progress = 0;
        if (booking.status === 'checked_out' || booking.status === 'cancelled') progress = 100;
        else if (booking.status === 'checked_in') progress = 66;
        else progress = 33;
        document.getElementById('sp-timeline-progress').style.width = progress + '%';

        // Guest counts
        document.getElementById('sp-adults').textContent = booking.adults || 1;
        document.getElementById('sp-children').textContent = booking.children || 0;

        // Balance
        const balance = (booking.final_price || 0) - (booking.paid_amount || 0);
        const fmtR = (v) => 'Rp' + new Intl.NumberFormat('id-ID').format(v || 0);
        document.getElementById('sp-balance').textContent = fmtR(Math.max(0, balance));

        // Folio table
        let folioRows = '';
        let totalDebit = 0,
            totalCredit = 0;

        // Room charge as debit
        const roomTotal = (booking.room_price || 0) * (booking.total_nights || 1);
        totalDebit += parseFloat(booking.final_price || roomTotal);
        folioRows += '<tr><td><div class="folio-desc-title">Room Charge - ' + (booking.room_type || '') + ' (' + (booking.room_number || '') + ')</div><div class="folio-desc-sub">' + fmtD(booking.check_in_date) + ' → ' + fmtD(booking.check_out_date) + ' • ' + (booking.total_nights || 1) + ' night(s)</div></td><td class="text-right">' + fmtR(booking.final_price || roomTotal) + '</td><td class="text-right">-</td></tr>';

        // Extras as debit
        if (booking.extras && booking.extras.length > 0) {
            booking.extras.forEach(function(ex) {
                totalDebit += parseFloat(ex.total_price || 0);
                folioRows += '<tr><td><div class="folio-desc-title">' + ex.item_name + ' (' + ex.quantity + 'x)</div><div class="folio-desc-sub">' + (ex.notes || '') + '</div></td><td class="text-right">' + fmtR(ex.total_price) + '</td><td class="text-right">-</td></tr>';
            });
        }

        // Discount as credit if any
        if (parseFloat(booking.discount) > 0) {
            totalCredit += parseFloat(booking.discount);
            folioRows += '<tr><td><div class="folio-desc-title">Promo Discount</div></td><td class="text-right">-</td><td class="text-right">' + fmtR(booking.discount) + '</td></tr>';
        }

        // Payments as credit
        if (booking.payments && booking.payments.length > 0) {
            booking.payments.forEach(function(p) {
                totalCredit += parseFloat(p.amount || 0);
                const pd = new Date(p.payment_date).toLocaleDateString('id-ID', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                }) + ' ' + new Date(p.payment_date).toLocaleTimeString('id-ID', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                const pm = (p.payment_method || 'cash').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                folioRows += '<tr><td><div class="folio-desc-title">' + pm + ' - Payment Recorded</div><div class="folio-desc-sub">' + pd + '</div></td><td class="text-right">-</td><td class="text-right">' + fmtR(p.amount) + '</td></tr>';
            });
        }

        document.getElementById('sp-folio-body').innerHTML = folioRows;
        document.getElementById('sp-total-debit').textContent = fmtR(totalDebit);
        document.getElementById('sp-total-credit').textContent = fmtR(totalCredit);

        // Details tab
        document.getElementById('sp-booking-code').textContent = booking.booking_code || '-';
        document.getElementById('sp-detail-source').textContent = displaySource;
        document.getElementById('sp-detail-checkin').textContent = fmtD(booking.check_in_date);
        document.getElementById('sp-detail-checkout').textContent = fmtD(booking.check_out_date);
        document.getElementById('sp-detail-nights').textContent = (booking.total_nights || '-') + ' night(s)';
        document.getElementById('sp-detail-guests').textContent = (booking.adults || 1) + ' adult(s)' + (booking.children > 0 ? ', ' + booking.children + ' child(ren)' : '');
        document.getElementById('sp-detail-notes').textContent = booking.special_requests || '-';

        // Extras in details
        const extSec = document.getElementById('sp-extras-section');
        if (booking.extras && booking.extras.length > 0) {
            extSec.style.display = '';
            document.getElementById('sp-extras-list').innerHTML = booking.extras.map(function(ex) {
                return '<div class="sp-detail-row"><span>' + ex.item_name + ' (' + ex.quantity + 'x)</span><strong>Rp' + new Intl.NumberFormat('id-ID').format(ex.total_price) + '</strong></div>';
            }).join('');
        } else {
            extSec.style.display = 'none';
        }

        // Room tab
        document.getElementById('sp-room-type').textContent = booking.room_type || '-';
        document.getElementById('sp-room-number').textContent = 'Room ' + (booking.room_number || '-');
        document.getElementById('sp-room-price-val').textContent = fmtR(booking.room_price || booking.base_price || 0);

        // Display group bookings / related rooms if multiple
        console.log('📦 Group bookings check:', {
            hasGroupBookings: !!booking.group_bookings,
            count: booking.group_bookings ? booking.group_bookings.length : 0,
            data: booking.group_bookings,
            willDisplay: booking.group_bookings && booking.group_bookings.length > 1
        });

        const groupRoomsSection = document.getElementById('sp-group-rooms-section');
        const groupRoomsList = document.getElementById('sp-group-rooms-list');

        // Show group section ONLY if there are multiple bookings (group bookings > 1)
        if (booking.group_bookings && booking.group_bookings.length > 1) {
            console.log('✅ Showing group bookings section with ' + booking.group_bookings.length + ' rooms');
            let html = '';
            booking.group_bookings.forEach(function(gb, idx) {
                console.log('  Room ' + (idx + 1) + ':', gb);
                const isActive = gb.id === booking.id;
                html += `<div style="padding:0.6rem;background:${isActive ? 'rgba(16,185,129,0.08)' : 'rgba(99,102,241,0.05)'};border-radius:6px;border-left:3px solid ${isActive ? '#10b981' : '#6366f1'};cursor:pointer;transition:all 0.2s;" onclick="if(event.target.closest('div') && ${gb.id} !== ${booking.id}) { console.log('Switching to room', ${gb.id}); closeBookingQuickView(); setTimeout(() => viewBooking(${gb.id}, event), 100); }">`;
                html += `<div style="font-weight:600;font-size:0.9rem;color:var(--text-primary);">🚪 ${gb.room_number} <span style="font-weight:400;color:var(--text-secondary);font-size:0.8rem;">${gb.type_name}</span>`;
                if (isActive) html += ` <span style="color:#10b981;font-size:0.7rem;font-weight:700;margin-left:0.4rem;">● AKTIF</span>`;
                html += `</div>`;
                html += `<div style="font-size:0.8rem;color:var(--text-secondary);margin-top:0.3rem;">Harga: ${fmtR(gb.room_price)} | Diskon: ${fmtR(gb.discount)} | Total: ${fmtR(gb.final_price)}</div>`;
                html += `</div>`;
            });
            groupRoomsList.innerHTML = html;
            groupRoomsSection.style.display = '';
            console.log('✅ Group section rendered with HTML:', html);
        } else {
            console.log('⚠️ Not showing group section - count:', booking.group_bookings ? booking.group_bookings.length : 0);
            groupRoomsSection.style.display = 'none';
        }

        // Action buttons
        let actions = '';
        if (booking.payment_status !== 'paid') {
            actions += '<button class="sp-action-btn success" onclick="openBookingPaymentModal()">💳 Payment</button>';
        }
        if (booking.status === 'confirmed' || booking.status === 'pending') {
            actions += '<button class="sp-action-btn primary" onclick="quickViewCheckIn()">🏨 Check-in</button>';
            actions += '<button class="sp-action-btn warning" onclick="closeBookingQuickView(); openEditReservationModal(' + booking.id + ')">✏️ Edit</button>';
            actions += '<button class="sp-action-btn danger" onclick="quickViewDeleteBooking()">🗑️ Delete</button>';
        } else if (booking.status === 'checked_in') {
            actions += '<button class="sp-action-btn danger" onclick="quickViewCheckOut()">📤 Check-out</button>';
            actions += '<button class="sp-action-btn" onclick="quickViewMoveRoom()">🔄 Move</button>';
            actions += '<button class="sp-action-btn warning" onclick="closeBookingQuickView(); openEditReservationModal(' + booking.id + ')">✏️ Edit</button>';
        } else if (booking.status === 'checked_out') {
            actions += '<button class="sp-action-btn warning" onclick="closeBookingQuickView(); openEditReservationModal(' + booking.id + ')">✏️ Edit</button>';
        }
        document.getElementById('sp-actions').innerHTML = actions;

        // Reset to folio tab
        switchSPTab('folio');

        // Show panel
        panel.classList.add('active');
    }

    window.switchSPTab = function switchSPTab(tab) {
        document.querySelectorAll('.sp-tab').forEach(function(t) {
            t.classList.remove('active');
        });
        document.querySelectorAll('.sp-tab-content').forEach(function(c) {
            c.classList.remove('active');
        });
        document.querySelector('.sp-tab[onclick*="' + tab + '"]').classList.add('active');
        document.getElementById('sp-tab-' + tab).classList.add('active');
    }

    window.closeBookingQuickView = function closeBookingQuickView() {
        const modal = document.getElementById('bookingQuickView');
        modal.classList.remove('active');
    }

    window.showBookingDetailsModal = function showBookingDetailsModal(booking) {
        const modal = document.getElementById('bookingDetailsModal');
        currentPaymentBooking = booking;

        // Populate modal with booking data
        document.getElementById('detailGuestName').textContent = booking.guest_name;
        document.getElementById('detailGuestPhone').textContent = booking.guest_phone || '-';
        document.getElementById('detailGuestEmail').textContent = booking.guest_email || '-';
        document.getElementById('detailRoomNumber').textContent = booking.room_number;
        document.getElementById('detailRoomType').textContent = booking.room_type;
        document.getElementById('detailCheckIn').textContent = formatDateFull(booking.check_in_date);
        document.getElementById('detailCheckOut').textContent = formatDateFull(booking.check_out_date);
        document.getElementById('detailNights').textContent = booking.total_nights + ' night(s)';
        document.getElementById('detailBookingCode').textContent = booking.booking_code;
        document.getElementById('detailPaymentStatus').textContent = booking.payment_status.toUpperCase();
        document.getElementById('detailPaymentStatus').className = 'status-badge status-' + booking.payment_status;
        document.getElementById('detailBookingStatus').textContent = booking.status.toUpperCase().replace('_', ' ');
        document.getElementById('detailBookingStatus').className = 'status-badge status-' + booking.status;
        document.getElementById('detailTotalPrice').textContent = IS_STAFF_VIEW ? '-' : ('Rp ' + formatNumberIDR(booking.final_price));

        // Set booking ID for action buttons
        modal.dataset.bookingId = booking.id;
        modal.dataset.bookingStatus = booking.status;
        modal.dataset.paymentStatus = booking.payment_status;

        // Show/hide action buttons based on status
        updateActionButtons(booking.status, booking.payment_status);

        modal.classList.add('active');
    }

    function closeBookingDetailsModal() {
        document.getElementById('bookingDetailsModal').classList.remove('active');
    }

    function formatDateFull(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('id-ID', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }

    function formatNumberIDR(num) {
        return new Intl.NumberFormat('id-ID').format(num);
    }

    function updateActionButtons(status, paymentStatus) {
        const checkInBtn = document.getElementById('btnCheckIn');
        const checkOutBtn = document.getElementById('btnCheckOut');
        const moveBtn = document.getElementById('btnMove');
        const payBtn = document.getElementById('btnPay');

        // Show/hide buttons based on status
        if (status === 'confirmed' || status === 'pending') {
            checkInBtn.style.display = 'flex';
            checkOutBtn.style.display = 'none';
            moveBtn.style.display = 'flex';
        } else if (status === 'checked_in') {
            checkInBtn.style.display = 'none';
            checkOutBtn.style.display = 'flex';
            moveBtn.style.display = 'flex';
        } else {
            checkInBtn.style.display = 'none';
            checkOutBtn.style.display = 'none';
            moveBtn.style.display = 'none';
        }

        // Show Pay button if unpaid or partial
        if (paymentStatus === 'unpaid' || paymentStatus === 'partial') {
            payBtn.style.display = 'flex';
        } else {
            payBtn.style.display = 'none';
        }
    }

    function doCheckIn() {
        const modal = document.getElementById('bookingDetailsModal');
        const bookingId = modal.dataset.bookingId;
        const guestName = document.getElementById('detailGuestName').textContent;
        const roomNumber = document.getElementById('detailRoomNumber').textContent;
        const paymentStatus = modal.dataset.paymentStatus;

        // Detect OTA booking - skip payment, langsung check-in
        const b = currentPaymentBooking;
        if (b) {
            const otaSources = (typeof OTA_SOURCE_KEYS !== 'undefined' && OTA_SOURCE_KEYS.length > 0) ?
                OTA_SOURCE_KEYS : ['ota', 'agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'expedia', 'pegipegi'];
            const rawSource = (b.booking_source || '').trim();
            const bookingSource = rawSource.toLowerCase().replace(/\.com|\.co\.id|\.id/g, '').replace(/[^a-z0-9]/g, '');
            const isOTA = rawSource && (
                otaSources.includes(rawSource) ||
                otaSources.includes(rawSource.toLowerCase()) ||
                otaSources.some(s => bookingSource.includes(s) || s.includes(bookingSource))
            );

            if (isOTA) {
                const total = parseFloat(b.final_price) || 0;
                const sourceLabel = rawSource.charAt(0).toUpperCase() + rawSource.slice(1);
                const feePercent = (typeof OTA_FEES !== 'undefined' && OTA_FEES[rawSource]) ? OTA_FEES[rawSource] : 0;
                const feeAmount = Math.round(total * feePercent / 100);
                const netAmount = total - feeAmount;
                let feeInfo = '';
                if (feePercent > 0) {
                    feeInfo = `\n\n💰 Total: Rp ${total.toLocaleString('id-ID')}\n📉 Fee OTA ${sourceLabel} (${feePercent}%): -Rp ${feeAmount.toLocaleString('id-ID')}\n✅ Masuk Kas: Rp ${netAmount.toLocaleString('id-ID')}`;
                } else {
                    feeInfo = `\n\nRp ${total.toLocaleString('id-ID')} akan otomatis masuk ke Kas.`;
                }
                if (!confirm(`🏨 Booking via OTA ${sourceLabel}\n\nTamu: ${guestName}\nRoom: ${roomNumber}${feeInfo}\n\nLanjutkan Check-in?`)) return;

                // OTA: langsung check-in tanpa payment, cashbook otomatis di backend
                const checkInBtn = document.getElementById('btnCheckIn');
                const originalText = checkInBtn.innerHTML;
                checkInBtn.innerHTML = '<span>⏳</span><span>Processing...</span>';
                checkInBtn.disabled = true;

                fetch('<?php echo BASE_URL; ?>/api/checkin-guest.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        credentials: 'include',
                        body: 'booking_id=' + bookingId + '&pay_now=0&create_invoice=0'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('✅ ' + data.message);
                            saveScrollAndReload();
                        } else {
                            alert('❌ Error: ' + data.message);
                            checkInBtn.innerHTML = originalText;
                            checkInBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        alert('❌ Terjadi kesalahan: ' + error.message);
                        checkInBtn.innerHTML = originalText;
                        checkInBtn.disabled = false;
                    });
                return;
            }
        }

        if (paymentStatus !== 'paid') {
            const proceed = confirm('Pembayaran belum lunas. Lanjut check-in dan buat invoice sisa?');
            if (!proceed) {
                openBookingPaymentModal();
                return;
            }
        }

        if (confirm(`Check-in ${guestName} ke Room ${roomNumber} sekarang?`)) {
            // Show loading state
            const checkInBtn = document.getElementById('btnCheckIn');
            const originalText = checkInBtn.innerHTML;
            checkInBtn.innerHTML = '<span>⏳</span><span>Processing...</span>';
            checkInBtn.disabled = true;

            // Call check-in API
            const createInvoice = paymentStatus !== 'paid' ? 1 : 0;
            fetch('<?php echo BASE_URL; ?>/api/checkin-guest.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    credentials: 'include', // Important: Send session cookies
                    body: 'booking_id=' + bookingId + '&create_invoice=' + createInvoice
                })
                .then(response => {
                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text);
                            throw new Error('Server mengembalikan response non-JSON');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (data.invoice_number) {
                            alert('✅ ' + data.message + '\nInvoice: ' + data.invoice_number);
                        } else {
                            alert('✅ ' + data.message);
                        }
                        // Reload page to reflect changes
                        saveScrollAndReload();
                    } else {
                        alert('❌ Error: ' + data.message);
                        checkInBtn.innerHTML = originalText;
                        checkInBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Terjadi kesalahan sistem: ' + error.message);
                    checkInBtn.innerHTML = originalText;
                    checkInBtn.disabled = false;
                });
        }
    }

    function doCheckOut() {
        const modal = document.getElementById('bookingDetailsModal');
        const bookingId = modal.dataset.bookingId;
        const guestName = document.getElementById('detailGuestName').textContent;
        const roomNumber = document.getElementById('detailRoomNumber').textContent;

        if (confirm(`Check-out ${guestName} dari Room ${roomNumber} sekarang?`)) {
            // Show loading state
            const checkOutBtn = document.getElementById('btnCheckOut');
            const originalText = checkOutBtn.innerHTML;
            checkOutBtn.innerHTML = '<span>⏳</span><span>Processing...</span>';
            checkOutBtn.disabled = true;

            // Call check-out API
            fetch('<?php echo BASE_URL; ?>/api/checkout-guest.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    credentials: 'include', // Important: Send session cookies
                    body: 'booking_id=' + bookingId
                })
                .then(response => {
                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text);
                            throw new Error('Server mengembalikan response non-JSON');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        // Reload page to reflect changes
                        saveScrollAndReload();
                    } else {
                        alert('❌ Error: ' + data.message);
                        checkOutBtn.innerHTML = originalText;
                        checkOutBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Terjadi kesalahan sistem: ' + error.message);
                    checkOutBtn.innerHTML = originalText;
                    checkOutBtn.disabled = false;
                });
        }
    }

    function doMoveRoom() {
        const modal = document.getElementById('bookingDetailsModal');
        const bookingId = modal.dataset.bookingId;

        // TODO: Implement move room modal
        alert('Move room feature coming soon for booking #' + bookingId);
    }

    window.doPayment = function doPayment() {
        openBookingPaymentModal();
    }

    window.quickViewCheckIn = function quickViewCheckIn() {
        if (!currentPaymentBooking) {
            alert('Booking data not found');
            return;
        }

        const b = currentPaymentBooking;
        const total = parseFloat(b.final_price) || 0;
        const paid = parseFloat(b.paid_amount) || 0;
        const remaining = Math.max(0, total - paid);

        // Detect OTA booking - use dynamic list from booking_sources table
        const otaSources = (typeof OTA_SOURCE_KEYS !== 'undefined' && OTA_SOURCE_KEYS.length > 0) ?
            OTA_SOURCE_KEYS : ['ota', 'agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'expedia', 'pegipegi'];
        const rawSource = (b.booking_source || '').trim();
        const bookingSource = rawSource.toLowerCase().replace(/\.com|\.co\.id|\.id/g, '').replace(/[^a-z0-9]/g, '');
        // Check: exact match in OTA list OR fuzzy match (source contains OTA keyword or vice versa)
        const isOTA = rawSource && (
            otaSources.includes(rawSource) ||
            otaSources.includes(rawSource.toLowerCase()) ||
            otaSources.some(s => bookingSource.includes(s) || s.includes(bookingSource))
        );

        console.log('OTA Detection:', {
            rawSource,
            bookingSource,
            otaSources,
            isOTA
        });

        // OTA booking: sudah dibayar via OTA, langsung check-in (uang masuk kas bank otomatis)
        if (isOTA) {
            const sourceLabel = rawSource.charAt(0).toUpperCase() + rawSource.slice(1);
            // Get OTA fee percentage for display
            const feePercent = (typeof OTA_FEES !== 'undefined' && OTA_FEES[rawSource]) ? OTA_FEES[rawSource] : 0;
            const feeAmount = Math.round(total * feePercent / 100);
            const netAmount = total - feeAmount;
            let feeInfo = '';
            if (feePercent > 0) {
                feeInfo = `\n\n💰 Total: Rp ${total.toLocaleString('id-ID')}\n📉 Fee OTA ${sourceLabel} (${feePercent}%): -Rp ${feeAmount.toLocaleString('id-ID')}\n✅ Masuk Kas Bank: Rp ${netAmount.toLocaleString('id-ID')}`;
            } else {
                feeInfo = `\n\nRp ${total.toLocaleString('id-ID')} akan otomatis masuk ke Kas Bank.`;
            }
            if (!confirm(`🏨 Booking via OTA ${sourceLabel}\n\nTamu: ${b.guest_name}\nRoom: ${b.room_number}${feeInfo}\n\nLanjutkan Check-in?`)) return;
            performCheckin(0, null, false);
            return;
        }

        // Jika sudah lunas, langsung konfirmasi check-in
        if (remaining <= 0) {
            if (!confirm(`💳 Tagihan LUNAS\n\nCheck-in ${b.guest_name} ke Room ${b.room_number}?`)) return;
            performCheckin(0, null, false);
            return;
        }

        // Isi data ke modal
        document.getElementById('ciGuestInfo').textContent =
            (b.guest_name || '-') + ' · #' + (b.booking_code || b.id);
        document.getElementById('ciRoom').textContent = 'Room ' + (b.room_number || '-');
        document.getElementById('ciTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
        document.getElementById('ciPaid').textContent = 'Rp ' + paid.toLocaleString('id-ID');
        document.getElementById('ciRemaining').textContent = 'Rp ' + remaining.toLocaleString('id-ID');
        document.getElementById('ciPayAmount').value = remaining;

        // Reset ke state default
        hideCiPayForm();

        // Tampilkan modal
        const modal = document.getElementById('checkinPaymentModal');
        modal.classList.add('active');
        modal.style.cssText = 'display:flex;position:fixed;top:0;left:0;right:0;bottom:0;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.5)';
    }

    function closeCheckinPaymentModal() {
        const modal = document.getElementById('checkinPaymentModal');
        modal.classList.remove('active');
        modal.style.display = 'none';
    }

    function showCiPayForm() {
        document.getElementById('ciPayForm').style.display = 'block';
        document.getElementById('ciDefaultBtns').style.display = 'none';
        document.getElementById('ciPayBtns').style.display = 'flex';
    }

    function hideCiPayForm() {
        document.getElementById('ciPayForm').style.display = 'none';
        document.getElementById('ciDefaultBtns').style.display = 'block';
        document.getElementById('ciPayBtns').style.display = 'none';
        // Reset method ke cash
        document.querySelectorAll('#checkinPaymentModal [data-ci-method]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.ciMethod === 'cash');
        });
        const ciMethodInput = document.getElementById('ciPayMethod');
        if (ciMethodInput) ciMethodInput.value = 'cash';
    }

    function doCheckin(payNow) {
        let payAmount = 0,
            payMethod = 'cash';
        if (payNow) {
            payAmount = parseFloat(document.getElementById('ciPayAmount').value) || 0;
            payMethod = document.getElementById('ciPayMethod').value || 'cash';
            if (payAmount <= 0) {
                alert('Masukkan jumlah pembayaran yang valid');
                return;
            }
        }
        closeCheckinPaymentModal();
        performCheckin(payAmount, payMethod, payNow);
    }

    function performCheckin(payAmount, payMethod, payNow) {
        const booking = currentPaymentBooking;
        const btn = document.querySelector('.qv-checkin-btn');
        if (btn) {
            btn.innerHTML = '⏳ Processing...';
            btn.disabled = true;
        }

        let body = 'booking_id=' + booking.id + '&create_invoice=0';
        if (payNow && payAmount > 0) {
            body += '&pay_now=1&pay_amount=' + payAmount + '&pay_method=' + encodeURIComponent(payMethod);
        } else {
            body += '&pay_now=0';
        }

        fetch('<?php echo BASE_URL; ?>/api/checkin-guest.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: body
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    closeBookingQuickView();
                    saveScrollAndReload();
                } else {
                    alert('❌ Error: ' + data.message);
                    if (btn) {
                        btn.innerHTML = 'Check-in';
                        btn.disabled = false;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ Terjadi kesalahan: ' + error.message);
                if (btn) {
                    btn.innerHTML = 'Check-in';
                    btn.disabled = false;
                }
            });
    }

    window.quickViewDeleteBooking = function quickViewDeleteBooking() {
        if (!currentPaymentBooking) return;
        const b = currentPaymentBooking;

        if (!confirm(`⚠️ HAPUS RESERVASI\n\nTamu: ${b.guest_name}\nRoom: ${b.room_number}\nBooking: ${b.booking_code}\n\nReservasi ini akan dihapus permanen.\nLanjutkan?`)) return;

        fetch('<?php echo BASE_URL; ?>/api/delete-booking.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    booking_id: b.id
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Reservasi berhasil dihapus');
                    closeBookingQuickView();
                    saveScrollAndReload();
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(err => {
                alert('❌ Error: ' + err.message);
            });
    }

    window.quickViewCheckOut = function quickViewCheckOut() {
        if (!currentPaymentBooking) {
            alert('Booking data not found');
            return;
        }

        const booking = currentPaymentBooking;
        const guestName = booking.guest_name;
        const roomNumber = booking.room_number;

        // Check payment status before checkout
        if (booking.payment_status !== 'paid') {
            const remaining = (parseFloat(booking.total_price) - parseFloat(booking.amount_paid)).toLocaleString('id-ID');
            const proceed = confirm(`Pembayaran belum lunas (Sisa: Rp ${remaining}). Lanjut check-out?`);
            if (!proceed) {
                openBookingPaymentModal();
                return;
            }
        }

        if (confirm(`Check-out ${guestName} dari Room ${roomNumber} sekarang?`)) {
            // Show loading state
            const checkOutBtn = document.querySelector('.qv-checkout-btn');
            let originalText = 'Check-out';

            if (checkOutBtn) {
                originalText = checkOutBtn.innerHTML;
                checkOutBtn.innerHTML = 'Processing...';
                checkOutBtn.disabled = true;
            }

            // Call check-out API
            fetch('<?php echo BASE_URL; ?>/api/checkout-guest.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    credentials: 'include',
                    body: 'booking_id=' + booking.id
                })
                .then(response => {
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text);
                            throw new Error('Server mengembalikan response non-JSON');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        closeBookingQuickView();
                        saveScrollAndReload();
                    } else {
                        alert('❌ Error: ' + data.message);
                        if (checkOutBtn) {
                            checkOutBtn.innerHTML = originalText;
                            checkOutBtn.disabled = false;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Terjadi kesalahan sistem: ' + error.message);
                    if (checkOutBtn) {
                        checkOutBtn.innerHTML = originalText;
                        checkOutBtn.disabled = false;
                    }
                });
        }
    }

    window.quickViewMoveRoom = function quickViewMoveRoom() {
        if (!currentPaymentBooking) {
            alert('Booking data not found');
            return;
        }

        const booking = currentPaymentBooking;
        alert('Move room feature untuk booking ' + booking.booking_code + ' segera hadir!');
        // TODO: Implement move room modal
    }

    window.openBookingPaymentModal = function openBookingPaymentModal() {
        if (!currentPaymentBooking) {
            alert('Booking data tidak ditemukan. Silakan buka detail booking lagi.');
            return;
        }

        const total = parseFloat(currentPaymentBooking.final_price) || 0;
        const paid = parseFloat(currentPaymentBooking.paid_amount) || 0;
        const remaining = Math.max(0, total - paid);

        document.getElementById('paymentTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
        document.getElementById('paymentPaid').textContent = 'Rp ' + paid.toLocaleString('id-ID');
        document.getElementById('paymentRemaining').textContent = 'Rp ' + remaining.toLocaleString('id-ID');
        document.getElementById('paymentAmount').value = remaining;
        document.getElementById('paymentModalSubtitle').textContent = currentPaymentBooking.booking_code + ' • ' + (currentPaymentBooking.guest_name || '-');

        const methodInput = document.getElementById('paymentMethodPay');
        const methodButtons = document.querySelectorAll('#bookingPaymentModal .payment-method-btn');
        methodButtons.forEach(btn => btn.classList.remove('active'));
        const defaultBtn = document.querySelector('#bookingPaymentModal .payment-method-btn[data-value="cash"]');
        if (defaultBtn) defaultBtn.classList.add('active');
        if (methodInput) methodInput.value = 'cash';

        const modal = document.getElementById('bookingPaymentModal');
        modal.classList.add('active');
        modal.style.display = 'flex';
        modal.style.position = 'fixed';
        modal.style.zIndex = '99999';
        modal.style.top = '0';
        modal.style.left = '0';
        modal.style.right = '0';
        modal.style.bottom = '0';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
    }

    window.closeBookingPaymentModal = function closeBookingPaymentModal() {
        const modal = document.getElementById('bookingPaymentModal');
        modal.classList.remove('active');
        modal.style.display = '';
        modal.style.position = '';
        modal.style.zIndex = '';
    }

    window.submitBookingPayment = function submitBookingPayment() {
        if (!currentPaymentBooking) return;

        const amount = parseFloat(document.getElementById('paymentAmount').value) || 0;
        const method = document.getElementById('paymentMethodPay').value || 'cash';

        if (amount <= 0) {
            alert('Jumlah bayar harus lebih dari 0');
            return;
        }

        fetch('<?php echo BASE_URL; ?>/api/add-booking-payment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: 'booking_id=' + encodeURIComponent(currentPaymentBooking.id) +
                    '&amount=' + encodeURIComponent(amount) +
                    '&payment_method=' + encodeURIComponent(method)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show detailed success message with cashbook info
                    let successMsg = '✅ PEMBAYARAN BERHASIL!\n\n';
                    successMsg += data.message || 'Payment saved';

                    alert(successMsg);

                    closeBookingPaymentModal();
                    // Refresh booking details
                    return fetch('../../api/get-booking-details.php?id=' + currentPaymentBooking.id)
                        .then(res => res.json())
                        .then(updated => {
                            if (updated.success) {
                                showBookingQuickView(updated.booking);
                                const detailsModal = document.getElementById('bookingDetailsModal');
                                if (detailsModal && detailsModal.classList.contains('active')) {
                                    showBookingDetailsModal(updated.booking);
                                }
                            }
                        });
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Gagal menyimpan pembayaran');
            });
    }

    window.changeDate = function changeDate() {
        const dateInput = document.getElementById('dateInput');
        if (!dateInput) return;
        window.location.search = '?start=' + dateInput.value;
    }

    window.openNewReservationForm = function openNewReservationForm() {
        // Open reservation modal with today's date
        const modal = document.getElementById('reservationModal');

        // Reset Form First
        const form = document.getElementById('reservationForm');
        if (form) form.reset();

        const checkInInput = document.getElementById('checkInDate');
        const checkOutInput = document.getElementById('checkOutDate');

        // Set default dates (today and tomorrow)
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);

        if (checkInInput) checkInInput.value = today.toISOString().split('T')[0];
        if (checkOutInput) checkOutInput.value = tomorrow.toISOString().split('T')[0];

        const modeEl = document.getElementById('reservationMode');
        if (modeEl) modeEl.value = 'reservation';
        setReservationMode('reservation');

        // Load available rooms for default dates
        loadAvailableRoomsCalendar();

        // Reset payment method class
        document.querySelectorAll('#reservationModal .pm-item').forEach(d => d.classList.remove('active'));
        // Set cash active
        const cashBtn = document.querySelector('#reservationModal .pm-item:first-child');
        if (cashBtn) {
            cashBtn.classList.add('active');
            document.getElementById('paymentMethod').value = 'cash';
        }

        // Show Modal
        if (modal) {
            modal.classList.add('active');
        }
    }

    // Store clicked roomId for auto-selection after rooms load
    let pendingRoomSelection = null;

    // ========================================
    // CLOUDBED-STYLE TWO-CLICK BOOKING
    // Click 1: set check-in date + room (highlight cell)
    // Click 2: set check-out date → open reservation form
    // ========================================
    let firstClick = null; // {date, roomId, element}

    // Tandai kamar dirty → bersih (housekeeping)
    window.markRoomClean = async function markRoomClean(roomId, roomNumber) {
        if (!confirm(`Tandai Room ${roomNumber || roomId} sudah BERSIH?`)) return;
        try {
            const fd = new FormData();
            fd.append('room_id', roomId);
            const res = await fetch('<?php echo BASE_URL; ?>/api/mark-room-clean.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data && data.success) {
                location.reload();
            } else {
                alert(data && data.message ? data.message : 'Gagal menandai bersih');
            }
        } catch (e) {
            alert('Gagal menghubungi server');
        }
    };

    window.openCellReservation = function openCellReservation(element) {
        const date = element.getAttribute('data-date');
        const roomId = element.getAttribute('data-room-id');
        const roomNumber = element.getAttribute('data-room-number');

        // If first click exists and same room → set checkout
        if (firstClick && firstClick.roomId === roomId) {
            let checkInDate = firstClick.date;
            let checkOutDate = date;

            // If clicked same date or earlier, reset
            if (checkOutDate <= checkInDate) {
                clearFirstClick();
                return;
            }

            // Remove highlight
            clearFirstClick();

            // Open reservation form with both dates
            pendingRoomSelection = roomId;
            const modal = document.getElementById('reservationModal');
            const form = document.getElementById('reservationForm');
            if (form) form.reset();

            const checkInInput = document.getElementById('checkInDate');
            const checkOutInput = document.getElementById('checkOutDate');
            if (checkInInput) checkInInput.value = checkInDate;
            if (checkOutInput) checkOutInput.value = checkOutDate;

            const modeEl = document.getElementById('reservationMode');
            if (modeEl) modeEl.value = 'reservation';
            setReservationMode('reservation');

            loadAvailableRoomsCalendar();
            if (typeof updateSourceDetails === 'function') updateSourceDetails();

            document.querySelectorAll('#reservationModal .pm-item').forEach(d => d.classList.remove('active'));
            const cashBtn = document.querySelector('#reservationModal .pm-item:first-child');
            if (cashBtn) {
                cashBtn.classList.add('active');
                document.getElementById('paymentMethod').value = 'cash';
            }

            if (modal) modal.classList.add('active');
            return;
        }

        // If clicking different room or no first click → set as first click
        clearFirstClick();
        firstClick = {
            date,
            roomId,
            element
        };

        // Highlight the clicked cell
        element.style.background = 'rgba(99, 102, 241, 0.25)';
        element.style.outline = '2px solid #6366f1';
        element.style.outlineOffset = '-2px';

        // Show tooltip
        showClickHint(element, roomNumber, date);
    }

    function clearFirstClick() {
        if (firstClick && firstClick.element) {
            firstClick.element.style.background = '';
            firstClick.element.style.outline = '';
            firstClick.element.style.outlineOffset = '';
        }
        firstClick = null;
        // Remove hint
        const hint = document.getElementById('clickBookingHint');
        if (hint) hint.remove();
    }

    function showClickHint(element, roomNumber, date) {
        // Remove old hint
        const old = document.getElementById('clickBookingHint');
        if (old) old.remove();

        const hint = document.createElement('div');
        hint.id = 'clickBookingHint';
        hint.innerHTML = `📌 Check-in: <b>${formatDateShort(date)}</b> · Room ${roomNumber}<br><small>Klik tanggal check-out untuk buat reservasi</small>`;
        hint.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#4338ca;color:#fff;padding:8px 16px;border-radius:8px;font-size:0.75rem;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.3);text-align:center;pointer-events:none;';
        document.body.appendChild(hint);

        // Auto-dismiss after 8s
        setTimeout(() => {
            if (hint.parentNode) hint.remove();
        }, 8000);
    }

    function formatDateShort(dateStr) {
        const d = new Date(dateStr);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }

    window.closeReservationModal = function() {
        const modal = document.getElementById('reservationModal');
        if (modal) modal.classList.remove('active');
        clearFirstClick();
    }

    // Escape key clears first click selection
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && firstClick) {
            clearFirstClick();
        }
    });

    window.updateRoomPrice = function() {
        const select = document.getElementById('roomSelect');
        const priceInput = document.getElementById('roomPrice');
        if (select && priceInput) {
            const option = select.options[select.selectedIndex];
            if (option) {
                priceInput.value = option.getAttribute('data-price') || 0;
                calculateFinalPrice();
            }
        }
    }

    window.updateStayDetails = function() {
        const checkInEl = document.getElementById('checkInDate');
        const checkOutEl = document.getElementById('checkOutDate');
        if (!checkInEl || !checkOutEl) return;

        const checkIn = new Date(checkInEl.value);
        const checkOut = new Date(checkOutEl.value);

        if (checkIn && checkOut && checkOut > checkIn) {
            const diffTime = Math.abs(checkOut - checkIn);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            document.getElementById('totalNights').value = diffDays;

            const display = document.getElementById('displayNights');
            if (display) display.innerText = diffDays;

            calculateFinalPrice();
        } else {
            document.getElementById('totalNights').value = 0;
            const display = document.getElementById('displayNights');
            if (display) display.innerText = 0;
        }
    }

    window.updateSourceDetails = function() {
        const sourceSelect = document.getElementById('bookingSource');
        const feeDisplay = document.getElementById('otaFeeDisplay');
        const feeRow = document.getElementById('feeRow');
        currentSource = sourceSelect ? sourceSelect.value : '';

        // Default Fees Map (Fallback)
        // Matches the values in the HTML optgroup
        currentFees = (typeof OTA_FEES !== 'undefined') ? OTA_FEES : {
            'agoda': 15,
            'booking': 12,
            'tiket': 10,
            'traveloka': 15,
            'airbnb': 3,
            'ota': 10
        };

        let feePercent = currentFees[currentSource] || 0;

        // AUTO-SELECT PAYMENT METHOD LOGIC
        const pmOtaBtn = document.getElementById('pm-ota'); // The new hidden OTA button
        const isOtaSource = (typeof OTA_SOURCE_KEYS !== 'undefined' && OTA_SOURCE_KEYS.length > 0) ?
            OTA_SOURCE_KEYS.includes(currentSource) :
            (!['walk_in', 'phone', 'online'].includes(currentSource) && feePercent > 0);

        const paidAmountInput = document.getElementById('paidAmount');
        const payAllBtn = document.querySelector('.btn-pay-all');
        const pmSelect = document.getElementById('paymentMethod');

        if (isOtaSource) {
            // Source is an OTA: set payment method to ota_<source>
            const otaValue = 'ota_' + currentSource;
            const otaNames = {
                'agoda': 'Agoda',
                'booking': 'Booking.com',
                'tiket': 'Tiket.com',
                'traveloka': 'Traveloka',
                'airbnb': 'Airbnb',
                'ota': 'OTA Lainnya'
            };
            const otaLabel = otaNames[currentSource] || currentSource;

            if (pmSelect) {
                pmSelect.innerHTML = '<option value="' + otaValue + '" selected>OTA ' + otaLabel + '</option>';
                pmSelect.disabled = true;
                pmSelect.style.opacity = '0.7';
            }
            if (pmOtaBtn) {
                pmOtaBtn.style.display = 'flex';
                pmOtaBtn.click();
            }

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
            // Source is NOT an OTA (Direct/Walk-in): restore normal payment options
            if (pmSelect) {
                pmSelect.innerHTML =
                    '<option value="cash">Cash</option>' +
                    '<option value="transfer">Transfer</option>' +
                    '<option value="qris">QRIS</option>';
                pmSelect.disabled = false;
                pmSelect.style.opacity = '1';
            }
            if (pmOtaBtn) {
                pmOtaBtn.style.display = 'none';
            }

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

        if (feePercent > 0) {
            if (feeDisplay) {
                feeDisplay.style.display = 'inline-block';
                const pctEl = document.getElementById('otaFeePercent');
                if (pctEl) pctEl.innerText = feePercent;
            }
            if (feeRow) feeRow.style.display = 'flex';
        } else {
            if (feeDisplay) feeDisplay.style.display = 'none';
            if (feeRow) feeRow.style.display = 'none';
        }

        calculateFinalPrice();

        // Also recalculate multi-room total if that modal is open
        if (typeof calculateMultiRoomTotalCalendar === 'function') {
            calculateMultiRoomTotalCalendar();
        }
    }

    window.calculateFinalPrice = function() {
        const nightsEl = document.getElementById('totalNights');
        const priceEl = document.getElementById('roomPrice');
        const discountEl = document.getElementById('discount');

        const nights = parseInt(nightsEl ? nightsEl.value : 0) || 0;
        const price = parseFloat(priceEl ? priceEl.value : 0) || 0;
        const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;

        const total = (nights * price) - discount;
        const final = total > 0 ? total : 0;

        // Update both total_price and final_price hidden fields
        const totalPriceEl = document.getElementById('hiddenTotalPrice');
        if (totalPriceEl) totalPriceEl.value = (nights * price);

        const finalEl = document.getElementById('finalPriceDisplay');
        if (finalEl) finalEl.innerText = 'Rp ' + final.toLocaleString('id-ID');

        const hiddenEl = document.getElementById('hiddenFinalPrice');
        if (hiddenEl) hiddenEl.value = final;

        // OTA Source Logic - Updated to auto-set payment method and full payment
        const feeRow = document.getElementById('feeRow');
        const pmOta = document.getElementById('pm-ota');
        const paymentMethodInput = document.getElementById('paymentMethod');
        const paidAmountInput = document.getElementById('paidAmount');

        if (currentFees[currentSource] && currentFees[currentSource] > 0) {
            if (feeRow) feeRow.style.display = 'flex';
            // Auto select OTA payment for OTA sources
            if (pmOta) {
                pmOta.style.display = 'flex';
                // Trigger click to activate
                if (currentSource !== 'walk_in' && currentSource !== 'phone') {
                    pmOta.click();
                }
            }

            // Auto-fill paid amount with final price for OTA (Assume prepaid to OTA)
            // Check if it IS an OTA source (has fee > 0 and not a direct source)
            const directSrcs = ['walk_in', 'phone', 'online'];
            if (!directSrcs.includes(currentSource)) {
                if (paidAmountInput) paidAmountInput.value = final;

                // Update payment status dropdown logic locally if function exists
                if (typeof updatePaymentStatusFromAmount === 'function') {
                    updatePaymentStatusFromAmount();
                }

                const feeInfo = document.getElementById('otaFeeInfo');
                if (feeInfo) feeInfo.style.display = 'block';
            }

        } else {
            if (feeRow) feeRow.style.display = 'none';
            if (pmOta) pmOta.style.display = 'none';
            const feeInfo = document.getElementById('otaFeeInfo');
            if (feeInfo) feeInfo.style.display = 'none';

            // Revert to cash if OTA was selected but source changed to non-OTA
            if (paymentMethodInput && paymentMethodInput.value === 'ota') {
                const cashBtn = document.querySelector('.pm-item[onclick*="cash"]');
                if (cashBtn) cashBtn.click();
            }
        }
    }

    window.setPaymentMethod = function(method, btn) {
        document.getElementById('paymentMethod').value = method;
        // Handle both old button style and new pm-item style
        document.querySelectorAll('#reservationModal .payment-method-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('#reservationModal .pm-item').forEach(b => b.classList.remove('active'));

        if (btn) btn.classList.add('active');
    }

    window.payFullAmount = function() {
        const hiddenFinalPrice = document.getElementById('hiddenFinalPrice');
        const paidAmount = document.getElementById('paidAmount');

        if (hiddenFinalPrice && paidAmount) {
            const totalAmount = parseFloat(hiddenFinalPrice.value) || 0;
            paidAmount.value = totalAmount;

            // Optional: Show confirmation
            if (totalAmount > 0) {
                const formattedAmount = 'Rp ' + totalAmount.toLocaleString('id-ID');
                console.log('Pay All clicked - Amount set to:', formattedAmount);
            }
        }
    }

    // ============================================
    // MULTI-ROOM BOOKING FUNCTIONS FOR CALENDAR
    // ============================================

    function updateCheckOutMinDateCalendar() {
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

    async function loadAvailableRoomsCalendar() {
        const checkIn = document.getElementById('checkInDate').value;
        const checkOut = document.getElementById('checkOutDate').value;

        // Update min date for check-out
        updateCheckOutMinDateCalendar();

        if (!checkIn || !checkOut) {
            document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">Pilih tanggal check-in dan check-out terlebih dahulu</em>';
            return;
        }

        // Validate dates
        if (new Date(checkOut) <= new Date(checkIn)) {
            document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">❌ Check-out harus minimal 1 hari setelah check-in</em>';
            document.getElementById('availabilityInfoCalendar').innerHTML = '<small style="color: #ef4444;">Invalid dates</small>';
            return;
        }

        // Show loading
        document.getElementById('roomsChecklistCalendar').innerHTML = '<div style="text-align:center; padding: 20px;"><em>Loading available rooms...</em></div>';

        try {
            const mode = document.getElementById('reservationMode')?.value || 'reservation';
            const response = await fetch(`../../api/get-available-rooms.php?check_in=${checkIn}&check_out=${checkOut}&mode=${encodeURIComponent(mode)}`);

            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.rooms.length > 0) {
                let html = '';
                result.rooms.forEach(room => {
                    const roomRateBadge = IS_STAFF_VIEW ?
                        '' :
                        `<span style="color: #10b981; font-weight: bold;">(Rp ${parseInt(room.base_price).toLocaleString('id-ID')}/night)</span>`;
                    html += `
                    <label class="room-checkbox-item" style="display: block; padding: 8px; margin-bottom: 5px; background: white; border-radius: 3px; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="rooms[]" value="${room.id}" 
                               data-price="${room.base_price}"
                               data-room="${room.room_number}"
                               data-type="${room.type_name}"
                               onchange="calculateMultiRoomTotalCalendar()"
                               style="margin-right: 8px;">
                        <strong>Room ${room.room_number}</strong> - ${room.type_name}
                        ${roomRateBadge}
                    </label>
                `;
                });
                document.getElementById('roomsChecklistCalendar').innerHTML = html;
                const blockedRooms = parseInt(result.blocked_rooms || 0, 10);
                document.getElementById('availabilityInfoCalendar').innerHTML = `<small style="color: #10b981;">✅ ${result.available_rooms} room(s) available (${result.booked_rooms} booked${blockedRooms > 0 ? `, ${blockedRooms} blocked` : ''})</small>`;

                // Auto-select room if clicked from calendar cell
                if (pendingRoomSelection) {
                    const roomCheckbox = document.querySelector(`input[name="rooms[]"][value="${pendingRoomSelection}"]`);
                    if (roomCheckbox) {
                        roomCheckbox.checked = true;
                        roomCheckbox.closest('.room-checkbox-item').style.background = '#dcfce7';
                    }
                    pendingRoomSelection = null; // Clear after use
                    calculateMultiRoomTotalCalendar(); // Update totals
                }
            } else if (result.success && result.rooms.length === 0) {
                document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">❌ Tidak ada room yang tersedia untuk tanggal ini (sudah ter-booking atau sedang diblok)</em>';
                const blockedRooms = parseInt(result.blocked_rooms || 0, 10);
                document.getElementById('availabilityInfoCalendar').innerHTML = `<small style="color: #ef4444;">0 rooms available (${result.booked_rooms} booked${blockedRooms > 0 ? `, ${blockedRooms} blocked` : ''})</small>`;
            } else {
                document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">Error loading rooms: ' + (result.message || 'Unknown error') + '</em>';
            }

            // Recalculate totals
            calculateMultiRoomTotalCalendar();

        } catch (error) {
            console.error('Error loading rooms:', error);
            document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">Error loading rooms. Please try again.</em>';
        }
    }

    function setDiscountTypeCalendar(type) {
        const discountTypeInput = document.getElementById('discountType');
        const discountLabel = document.getElementById('discountTypeLabel');
        const discountInput = document.getElementById('discount');
        const buttons = document.querySelectorAll('.disc-type-btn-cal');

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

        calculateMultiRoomTotalCalendar();
    }

    function calculateMultiRoomTotalCalendar() {
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
            return;
        }

        const mode = document.getElementById('reservationMode')?.value || 'reservation';

        // Get all checked rooms
        const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');
        const totalRooms = checkedRooms.length;

        if (mode === 'block_room') {
            document.getElementById('totalRoomsDisplayCalendar').textContent = totalRooms + ' room' + (totalRooms !== 1 ? 's' : '');
            document.getElementById('displayNights').textContent = nights + ' night' + (nights !== 1 ? 's' : '');
            document.getElementById('subtotalDisplayCalendar').textContent = '-';
            document.getElementById('grandTotalDisplayCalendar').textContent = '-';
            const otaFeeRow = document.getElementById('otaFeeRow');
            if (otaFeeRow) otaFeeRow.style.display = 'none';
            if (totalRooms > 0) {
                document.getElementById('selectedRoomsSummaryCalendar').innerHTML =
                    '<strong>Selected for Block:</strong> ' + totalRooms + ' room(s) × ' + nights + ' night(s)';
            } else {
                document.getElementById('selectedRoomsSummaryCalendar').innerHTML = '<em style="color: #ef4444;">Belum ada room yang dipilih</em>';
            }
            return;
        }

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

        const grandTotal = subtotal - discountAmount - otaFeeAmount;

        // Update display
        document.getElementById('totalRoomsDisplayCalendar').textContent = totalRooms + ' room' + (totalRooms !== 1 ? 's' : '');
        document.getElementById('displayNights').textContent = nights + ' night' + (nights !== 1 ? 's' : '');
        document.getElementById('subtotalDisplayCalendar').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
        document.getElementById('grandTotalDisplayCalendar').textContent = 'Rp ' + grandTotal.toLocaleString('id-ID');

        // Update summary
        if (totalRooms > 0) {
            document.getElementById('selectedRoomsSummaryCalendar').innerHTML =
                '<strong>Selected:</strong> ' + totalRooms + ' room(s) × ' + nights + ' night(s) = Rp ' + subtotal.toLocaleString('id-ID');
        } else {
            document.getElementById('selectedRoomsSummaryCalendar').innerHTML = '<em style="color: #ef4444;">Belum ada room yang dipilih</em>';
        }
    }

    function payFullMultiRoomCalendar() {
        const grandTotalText = document.getElementById('grandTotalDisplayCalendar').textContent;
        const grandTotal = parseFloat(grandTotalText.replace(/[^\d]/g, ''));
        document.getElementById('paidAmount').value = grandTotal;
    }

    window.submitReservation = async function(event) {
        event.preventDefault();

        const mode = document.getElementById('reservationMode')?.value || 'reservation';

        // Validate room selection
        const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');
        if (checkedRooms.length === 0) {
            alert('Silakan pilih minimal 1 room!');
            return;
        }

        // DEBUG: Log total rooms selected
        console.log(`[RESERVATION] Total rooms selected: ${checkedRooms.length}`);
        checkedRooms.forEach((cb, idx) => {
            console.log(`  [${idx+1}] Room ${cb.dataset.room} (ID: ${cb.value}, Price: ${cb.dataset.price})`);
        });

        const form = event.target;
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerText;

        if (mode === 'block_room') {
            const checkIn = document.getElementById('checkInDate').value;
            const checkOut = document.getElementById('checkOutDate').value;
            const blockReason = document.getElementById('blockReason')?.value || 'maintenance';
            const blockNotes = document.getElementById('blockNotes')?.value || '';

            if (!checkIn || !checkOut) {
                alert('Tanggal block wajib diisi');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving blocks...';

            let successCount = 0;
            const failed = [];

            for (const checkbox of checkedRooms) {
                const roomId = checkbox.value;
                const roomNumber = checkbox.dataset.room;
                const fd = new FormData();
                fd.append('room_id', roomId);
                fd.append('block_start_date', checkIn);
                fd.append('block_end_date', checkOut);
                fd.append('block_reason', blockReason);
                fd.append('block_notes', blockNotes);

                try {
                    const response = await fetch('<?php echo BASE_URL; ?>/api/create-room-block.php', {
                        method: 'POST',
                        body: fd
                    });
                    const result = await response.json();
                    if (result.success) {
                        successCount++;
                    } else {
                        failed.push(`Room ${roomNumber}: ${result.message || 'Gagal block'}`);
                    }
                } catch (err) {
                    failed.push(`Room ${roomNumber}: Network error`);
                }
            }

            submitBtn.disabled = false;
            submitBtn.textContent = originalText;

            if (successCount > 0) {
                const failText = failed.length ? `\n\n${failed.join('\n')}` : '';
                alert(`✅ ${successCount} room berhasil diblok.${failText}`);
                closeReservationModal();
                const ciDate = document.getElementById('checkInDate')?.value;
                if (ciDate) {
                    sessionStorage.setItem('calendarScrollToDate', ciDate);
                    location.reload();
                } else {
                    saveScrollAndReload();
                }
            } else {
                alert('❌ Gagal membuat block room.\n' + failed.join('\n'));
            }
            return;
        }

        // Get form data
        const guestName = document.getElementById('guestName').value;
        const guestPhone = document.getElementById('guestPhone').value || '';
        const checkIn = document.getElementById('checkInDate').value;
        const checkOut = document.getElementById('checkOutDate').value;
        let bookingSource = document.getElementById('bookingSource').value;
        const paymentMethod = document.getElementById('paymentMethod').value;
        const discountValue = parseFloat(document.getElementById('discount').value) || 0;
        const discountType = document.getElementById('discountType').value;
        const paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;
        const adultCount = parseInt(document.getElementById('adultCount').value) || 1;

        // DEBUG: Log form data
        console.log('[RESERVATION] Form Data:', {
            guestName,
            checkIn,
            checkOut,
            bookingSource,
            paymentMethod,
            discountValue,
            discountType,
            paidAmount,
            adultCount
        });

        // VALIDATE: Booking Source MUST be selected
        if (!bookingSource || bookingSource.trim() === '') {
            alert('❌ Silakan pilih Booking Source (Direct/OTA)!');
            return;
        }

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

        // Calculate OTA fee
        let otaFeePercent = 0;
        let otaFeeAmount = 0;
        if (typeof OTA_FEES !== 'undefined' && OTA_FEES[bookingSource]) {
            otaFeePercent = OTA_FEES[bookingSource];
            otaFeeAmount = Math.round(subtotal * (otaFeePercent / 100));
        }

        // Calculate discount per room (distribute equally)
        const discountPerRoom = discount / checkedRooms.length;

        // Calculate payment per room (distribute proportionally)
        // OTA fee is NOT subtracted from final_price - CashbookHelper handles OTA fee deduction
        let totalPrice = 0;
        const roomPrices = [];
        checkedRooms.forEach(checkbox => {
            const price = parseFloat(checkbox.dataset.price) * nights - discountPerRoom;
            roomPrices.push(price);
            totalPrice += price;
        });

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating bookings...';

        let successCount = 0;
        let errorCount = 0;
        const bookingCodes = [];
        const errorMessages = [];

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
            const roomFinalPrice = roomTotalPrice - discountPerRoom;

            const formData = new FormData();
            if (groupId) formData.append('group_id', groupId);
            formData.append('guest_name', guestName);
            formData.append('guest_phone', guestPhone);
            formData.append('room_id', roomId);
            formData.append('check_in_date', checkIn); // API expects check_in_date
            formData.append('check_out_date', checkOut); // API expects check_out_date
            formData.append('total_nights', nights);
            formData.append('adult_count', adultCount);
            formData.append('children_count', 0);
            formData.append('room_price', roomBasePrice); // API expects room_price (per night)
            formData.append('total_price', roomTotalPrice); // API expects total_price
            formData.append('discount', discountPerRoom);
            formData.append('final_price', roomFinalPrice);
            formData.append('booking_source', bookingSource);
            formData.append('payment_method', paymentMethod);
            formData.append('paid_amount', proportionalPayment);

            // DEBUG: Log what we're sending
            console.log(`[MULTI-ROOM] Room ${i+1}/${checkedRooms.length} - Room ${roomNumber}:`, {
                groupId,
                roomId,
                bookingSource,
                checkIn,
                checkOut,
                roomPrice: roomBasePrice,
                totalPrice: roomTotalPrice,
                finalPrice: roomFinalPrice
            });

            try {
                const apiUrl = '<?php echo BASE_URL; ?>/api/create-reservation.php';
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData
                });

                // Get raw text first for debugging
                const responseText = await response.text();
                let result;

                console.log(`[MULTI-ROOM] Room ${roomNumber} - Raw response:`, responseText);

                try {
                    result = JSON.parse(responseText);
                } catch (parseErr) {
                    console.error(`[MULTI-ROOM] Room ${roomNumber} - Invalid JSON response:`, responseText);
                    errorMessages.push(`Room ${roomNumber}: Server error`);
                    errorCount++;
                    continue;
                }

                if (result.success) {
                    successCount++;
                    bookingCodes.push(result.booking_code);
                    console.log(`[MULTI-ROOM] Room ${roomNumber} - SUCCESS:`, result);
                } else {
                    errorCount++;
                    const errMsg = result.message || 'Unknown error';
                    errorMessages.push(`Room ${roomNumber}: ${errMsg}`);
                    console.error(`[MULTI-ROOM] Room ${roomNumber} - ERROR:`, result);
                }
            } catch (error) {
                errorCount++;
                errorMessages.push(`Room ${roomNumber}: Network error`);
                console.error(`[MULTI-ROOM] Room ${roomNumber} - NETWORK ERROR:`, error);
            }
        }

        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;

        // Show results
        if (successCount > 0) {
            alert(`✅ Berhasil membuat ${successCount} booking!\n\nBooking Codes: ${bookingCodes.join(', ')}\n\n${errorCount > 0 ? `⚠️ ${errorCount} booking gagal:\n${errorMessages.join('\n')}` : ''}`);
            closeReservationModal();
            // Navigate to show the new booking's check-in date
            const ciDate = document.getElementById('checkInDate')?.value;
            if (ciDate) {
                // Check if checkin date is within current grid range
                const scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');
                const dateCell = scroller ? scroller.querySelector(`.grid-date-cell[data-date="${ciDate}"]`) : null;
                if (dateCell) {
                    // Date is in current range — save target date and reload
                    sessionStorage.setItem('calendarScrollToDate', ciDate);
                    location.reload();
                } else {
                    // Date is outside range — reload with start= so it's visible
                    sessionStorage.setItem('calendarScrollToDate', ciDate);
                    window.location.search = '?start=' + ciDate;
                }
            } else {
                saveScrollAndReload();
            }
        } else {
            const errDetail = errorMessages.length > 0 ? `\n\nDetail error:\n${errorMessages.join('\n')}` : '';
            alert('❌ Gagal membuat booking. Silakan coba lagi.' + errDetail);
        }
    }

    const shiftCalendarDays = (days) => {
        const dateInput = document.getElementById('dateInput');
        if (!dateInput) return;
        const currentDate = new Date(dateInput.value);
        currentDate.setDate(currentDate.getDate() + days);
        dateInput.value = currentDate.toISOString().split('T')[0];
        changeDate();
    };

    window.prevMonth = function prevMonth() {
        shiftCalendarDays(-30);
    }

    window.nextMonth = function nextMonth() {
        shiftCalendarDays(30);
    }
    // ========================================
    // COMMENTED OUT - Reservation Form Code
    // Will rebuild from scratch
    // ========================================

    // Store form pre-fill data
    let formPreFillData = {

        date: null,
        roomId: null
    };

    // Expose functions to global scope for onclick handlers
    window.showReservationForm = function showReservationForm() {
        // Close any other open modals first
        const bookingPaymentModal = document.getElementById('bookingPaymentModal');
        if (bookingPaymentModal) {
            bookingPaymentModal.classList.remove('active');
        }
        const bookingDetailsModal = document.getElementById('bookingDetailsModal');
        if (bookingDetailsModal) {
            bookingDetailsModal.classList.remove('active');
        }
        const bookingQuickView = document.getElementById('bookingQuickView');
        if (bookingQuickView) {
            bookingQuickView.classList.remove('active');
        }

        // Use pre-fill data if available
        const savedDate = formPreFillData.date;
        const savedRoomId = formPreFillData.roomId;

        console.log('📅 Opening reservation form with date:', savedDate, 'room:', savedRoomId);

        const modal = document.getElementById('reservationModal');

        // FIRST: Reset form completely to avoid stale data
        document.getElementById('reservationForm').reset();
        const guestName = document.getElementById('guestName');
        if (guestName) guestName.value = '';

        // Pre-fill form with SAVED data (not selectedDate which is now null)
        if (savedDate) {
            console.log('✅ Setting check-in date:', savedDate);

            // Set check-in date from selected date
            const checkInInput = document.getElementById('checkInDate');
            checkInInput.value = savedDate;

            // Set check-out date to next day
            const checkOut = new Date(savedDate);
            checkOut.setDate(checkOut.getDate() + 1);
            const checkOutDate = checkOut.toISOString().split('T')[0];

            const checkOutInput = document.getElementById('checkOutDate');
            checkOutInput.value = checkOutDate;
            checkOutInput.min = checkOutDate;

            console.log('Check-in:', checkInInput.value, 'Check-out:', checkOutInput.value);
        } else {
            console.error('❌ No savedDate available!');
        }

        if (savedRoomId) {
            console.log('✅ Setting room:', savedRoomId);
            document.getElementById('roomSelect').value = savedRoomId;
            // Trigger change to update price
            document.getElementById('roomSelect').dispatchEvent(new Event('change'));
        }

        // Calculate initial nights
        calculateNights();

        // Show modal AFTER setting all values
        modal.classList.add('active');
    }

    window.closeReservationModal = function closeReservationModal() {
        const modal = document.getElementById('reservationModal');
        modal.classList.remove('active');

        // Also close other modals if open
        const bookingPaymentModal = document.getElementById('bookingPaymentModal');
        if (bookingPaymentModal) {
            bookingPaymentModal.classList.remove('active');
        }
        const bookingDetailsModal = document.getElementById('bookingDetailsModal');
        if (bookingDetailsModal) {
            bookingDetailsModal.classList.remove('active');
        }
        const bookingQuickView = document.getElementById('bookingQuickView');
        if (bookingQuickView) {
            bookingQuickView.classList.remove('active');
        }

        // Clear pre-fill data
        formPreFillData.date = null;
        formPreFillData.roomId = null;

        // Remove inline styles to allow CSS to take over
        modal.style.display = '';
        modal.style.position = '';
        modal.style.top = '';
        modal.style.left = '';
        modal.style.right = '';
        modal.style.bottom = '';
        modal.style.zIndex = '';
        modal.style.backgroundColor = '';
        modal.style.alignItems = '';
        modal.style.justifyContent = '';

        // Completely reset form
        document.getElementById('reservationForm').reset();

        // Explicitly clear guest information fields
        const guestName = document.getElementById('guestName');
        const guestPhone = document.getElementById('guestPhone');
        const guestEmail = document.getElementById('guestEmail');
        const guestId = document.getElementById('guestId');
        if (guestName) guestName.value = '';
        if (guestPhone) guestPhone.value = '';
        if (guestEmail) guestEmail.value = '';
        if (guestId) guestId.value = '';

        // Reset dates
        const checkInDate = document.getElementById('checkInDate');
        const checkOutDate = document.getElementById('checkOutDate');
        if (checkInDate) checkInDate.value = '';
        if (checkOutDate) checkOutDate.value = '';

        // Reset room and price
        const roomSelect = document.getElementById('roomSelect');
        const roomPrice = document.getElementById('roomPrice');
        if (roomSelect) roomSelect.value = '';
        if (roomPrice) roomPrice.value = '';

        // Reset discount
        const discount = document.getElementById('discount');
        if (discount) discount.value = '0';

        // Reset special request
        const specialRequest = document.getElementById('specialRequest');
        if (specialRequest) specialRequest.value = '';

        // Reset DP/Paid Amount
        const paidAmount = document.getElementById('paidAmount');
        if (paidAmount) {
            paidAmount.value = '0';
            delete paidAmount.dataset.dpPercent;
        }

        // Reset payment method to Cash
        const paymentMethod = document.getElementById('paymentMethod');
        if (paymentMethod) paymentMethod.value = 'cash';

        // Reset payment status to unpaid
        const paymentStatus = document.getElementById('paymentStatus');
        if (paymentStatus) paymentStatus.value = 'unpaid';

        // Reset button states
        const paymentButtons = document.querySelectorAll('#reservationModal .payment-method-btn');
        paymentButtons.forEach((btn, idx) => {
            if (idx === 1) { // Cash is second button (index 1)
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        const dpButtons = document.querySelectorAll('.dp-percent-btn');
        dpButtons.forEach(btn => btn.classList.remove('active'));

        // Reset Total Pax to default values
        const adultInput = document.getElementById('adultCount');
        const childrenInput = document.getElementById('childrenCount');
        if (adultInput) adultInput.value = 1;
        if (childrenInput) childrenInput.value = 0;
        calculateTotalPax();

        // Reset price display
        const totalPriceEl = document.getElementById('totalPrice');
        const discountAmountEl = document.getElementById('discountAmount');
        const finalPriceEl = document.getElementById('finalPrice');
        if (totalPriceEl) totalPriceEl.textContent = 'Rp 0';
        if (discountAmountEl) discountAmountEl.textContent = '- Rp 0';
        if (finalPriceEl) finalPriceEl.textContent = 'Rp 0';

        selectedDate = null;
        selectedRoom = null;
    }

    window.calculateNights = function calculateNights() {
        const checkInEl = document.getElementById('checkInDate');
        const checkOutEl = document.getElementById('checkOutDate');
        const checkIn = checkInEl ? checkInEl.value : null;
        const checkOut = checkOutEl ? checkOutEl.value : null;

        if (checkIn && checkOut) {
            const start = new Date(checkIn);
            const end = new Date(checkOut);
            const nights = Math.ceil((end - start) / (1000 * 60 * 60 * 24));

            const totalNightsEl = document.getElementById('totalNights');
            if (totalNightsEl) totalNightsEl.value = nights > 0 ? nights : 0;
            calculatePrice();
        }
    }

    window.calculatePrice = function calculatePrice() {
        const roomPriceEl = document.getElementById('roomPrice');
        const totalNightsEl = document.getElementById('totalNights');
        const discountEl = document.getElementById('discount');

        const roomPrice = parseFloat(roomPriceEl ? roomPriceEl.value : 0) || 0;
        const nights = parseInt(totalNightsEl ? totalNightsEl.value : 0) || 0;
        const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;

        const total = roomPrice * nights;
        const final = total - discount;

        const totalPriceEl = document.getElementById('totalPrice');
        const discountAmountEl = document.getElementById('discountAmount');
        const finalPriceDisplayEl = document.getElementById('finalPrice');

        if (totalPriceEl) totalPriceEl.textContent = 'Rp ' + total.toLocaleString('id-ID');
        if (discountAmountEl) discountAmountEl.textContent = '- Rp ' + discount.toLocaleString('id-ID');
        if (finalPriceDisplayEl) finalPriceDisplayEl.textContent = 'Rp ' + final.toLocaleString('id-ID');

        // Run Calculate Final Price for the modernized form too
        if (typeof calculateFinalPrice === 'function') calculateFinalPrice();

        // Recalculate DP amount if a percent is selected
        const paidInput = document.getElementById('paidAmount');
        if (paidInput && paidInput.dataset.dpPercent) {
            applyDpPercent(parseFloat(paidInput.dataset.dpPercent));
        }
    }

    window.getFinalPriceNumber = function getFinalPriceNumber() {
        const roomPriceEl = document.getElementById('roomPrice');
        const totalNightsEl = document.getElementById('totalNights');
        const discountEl = document.getElementById('discount');

        const roomPrice = parseFloat(roomPriceEl ? roomPriceEl.value : 0) || 0;
        const nights = parseInt(totalNightsEl ? totalNightsEl.value : 0) || 0;
        const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;
        return (roomPrice * nights) - discount;
    }

    window.applyDpPercent = function applyDpPercent(percent) {
        const paidInput = document.getElementById('paidAmount');
        if (!paidInput) return;

        const finalPrice = getFinalPriceNumber();
        const amount = Math.round(finalPrice * (percent / 100));
        paidInput.value = amount;
        paidInput.dataset.dpPercent = percent;

        updatePaymentStatusFromAmount();
    }

    window.updatePaymentStatusFromAmount = function updatePaymentStatusFromAmount() {
        const paidInput = document.getElementById('paidAmount');
        const statusSelect = document.getElementById('paymentStatus');
        if (!paidInput || !statusSelect) return;

        const paid = parseFloat(paidInput.value) || 0;
        const finalPrice = getFinalPriceNumber();

        if (paid <= 0) {
            statusSelect.value = 'unpaid';
        } else if (paid >= finalPrice) {
            statusSelect.value = 'paid';
        } else {
            statusSelect.value = 'partial';
        }
    }

    // Calculate Total Pax (Adult + Children)
    window.calculateTotalPax = function calculateTotalPax() {
        const adultEl = document.getElementById('adultCount');
        const childrenEl = document.getElementById('childrenCount');
        const totalPaxEl = document.getElementById('totalPax');

        const adults = parseInt(adultEl ? adultEl.value : 0) || 0;
        const children = parseInt(childrenEl ? childrenEl.value : 0) || 0;
        const totalPax = adults + children;

        if (totalPaxEl) totalPaxEl.value = totalPax;
    }

    // Old submitReservation removed to avoid duplication and syntax error
    // The new submitReservation is defined earlier in the file

    // Setup form event listeners (removed click-outside-to-close functionality)

    // Save scroll position before reload so we return to same spot
    function saveScrollAndReload() {
        const scroller = document.getElementById('calendarScroller');
        if (scroller) {
            sessionStorage.setItem('calendarScrollLeft', scroller.scrollLeft);
        }
        location.reload();
    }

    // Scroll calendar grid to a specific date
    function scrollCalendarToDate(dateStr, scroller) {
        if (!scroller) scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');
        if (!scroller) return;
        const cell = scroller.querySelector(`.grid-date-cell[data-date="${dateStr}"]`);
        if (cell) {
            const scrollPos = cell.offsetLeft - 100; // 100px offset = room label column
            scroller.scrollLeft = Math.max(0, scrollPos);
        }
    }

    // Go to today: if today is within the current 30-day range, just scroll; otherwise reload with today's start date
    window.goToToday = function() {
        const todayStr = new Date().toISOString().split('T')[0];
        const scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');
        const todayCell = scroller ? scroller.querySelector(`.grid-date-cell[data-date="${todayStr}"]`) : null;
        if (todayCell) {
            // Today is visible in the current date range — just scroll to it
            scrollCalendarToDate(todayStr, scroller);
        } else {
            // Today is outside the current range — reload with today as start
            window.location.search = '?start=' + todayStr;
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        console.log('🚀 DOMContentLoaded fired for calendar.php');

        // ========================================
        // SEARCH RESERVATION FUNCTIONALITY
        // ========================================
        const searchInput = document.getElementById('searchReservation');
        const searchResults = document.getElementById('searchResults');
        const searchClearBtn = document.getElementById('searchClearBtn');
        let searchTimeout = null;

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const q = this.value.trim();
                searchClearBtn.style.display = q.length > 0 ? '' : 'none';

                if (searchTimeout) clearTimeout(searchTimeout);
                if (q.length < 2) {
                    searchResults.style.display = 'none';
                    return;
                }

                searchTimeout = setTimeout(function() {
                    fetch('../../api/search-bookings.php?q=' + encodeURIComponent(q))
                        .then(r => r.json())
                        .then(function(data) {
                            if (!data.success || !data.results.length) {
                                searchResults.innerHTML = '<div class="search-no-result">No results found</div>';
                                searchResults.style.display = 'block';
                                return;
                            }
                            let html = '';
                            data.results.forEach(function(r) {
                                const initials = (r.guest_name || 'G').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
                                const checkin = new Date(r.check_in_date).toLocaleDateString('id-ID', {
                                    day: 'numeric',
                                    month: 'short'
                                });
                                const checkout = new Date(r.check_out_date).toLocaleDateString('id-ID', {
                                    day: 'numeric',
                                    month: 'short',
                                    year: 'numeric'
                                });
                                html += '<div class="search-result-item" onclick="openBookingFromSearch(' + r.id + ')">' +
                                    '<div class="sr-avatar">' + initials + '</div>' +
                                    '<div class="sr-info"><div class="sr-name">' + r.guest_name + '</div>' +
                                    '<div class="sr-meta">Room ' + (r.room_number || '-') + ' • ' + checkin + ' - ' + checkout + ' • ' + (r.booking_code || '') + '</div></div>' +
                                    '<span class="sr-status ' + r.status + '">' + r.status.replace('_', ' ') + '</span>' +
                                    '</div>';
                            });
                            searchResults.innerHTML = html;
                            searchResults.style.display = 'block';
                        })
                        .catch(function() {
                            searchResults.style.display = 'none';
                        });
                }, 300);
            });

            // Close dropdown on outside click
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.search-reservation-bar')) {
                    searchResults.style.display = 'none';
                }
            });
        }

        window.openBookingFromSearch = function(bookingId) {
            searchResults.style.display = 'none';
            searchInput.value = '';
            searchClearBtn.style.display = 'none';
            viewBooking(bookingId, new Event('click'));
        };

        window.clearSearch = function() {
            searchInput.value = '';
            searchClearBtn.style.display = 'none';
            searchResults.style.display = 'none';
        };

        try {
            // ========================================
            // 1. DRAG SCROLL IMPLEMENTATION (PRIORITY)
            // ========================================
            const scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');

            if (!scroller) {
                console.error('❌ Drag container not found');
            } else {
                console.log('✅ Drag initialized on #drag-container');

                let isDown = false;
                let startX;
                let scrollLeft;

                scroller.addEventListener('mousedown', (e) => {
                    // Ignore if clicking on interactive elements
                    if (e.target.closest('.booking-bar') ||
                        e.target.closest('.nav-btn') ||
                        e.target.closest('input') ||
                        e.target.closest('button')) return;

                    isDown = true;
                    scroller.classList.add('dragging');
                    startX = e.pageX - scroller.offsetLeft;
                    scrollLeft = scroller.scrollLeft;

                    console.log('MouseDown: StartX', startX, 'ScrollLeft', scrollLeft);
                });

                // Use window for mousemove/mouseup to handle drags that leave the container
                window.addEventListener('mouseleave', () => {
                    isDown = false;
                    if (scroller) scroller.classList.remove('dragging');
                });

                window.addEventListener('mouseup', () => {
                    isDown = false;
                    if (scroller) scroller.classList.remove('dragging');
                });

                window.addEventListener('mousemove', (e) => {
                    if (!isDown) return;
                    e.preventDefault(); // Prevent selection/dragging artifacts

                    const x = e.pageX - scroller.offsetLeft;
                    const walk = (x - startX); // Scroll 1:1
                    scroller.scrollLeft = scrollLeft - walk;

                    // console.log('MouseMove:', { x, walk, newScroll: scroller.scrollLeft }); // Uncomment for debug
                });

                // Touch support
                scroller.addEventListener('touchstart', (e) => {
                    if (e.target.closest('.booking-bar')) return;
                    const touch = e.touches[0];
                    isDown = true;
                    startX = touch.pageX - scroller.offsetLeft;
                    scrollLeft = scroller.scrollLeft;
                }, {
                    passive: true
                });

                scroller.addEventListener('touchmove', (e) => {
                    if (!isDown) return;
                    const touch = e.touches[0];
                    const x = touch.pageX - scroller.offsetLeft;
                    const walk = (x - startX);
                    scroller.scrollLeft = scrollLeft - walk;
                }, {
                    passive: false
                });

                scroller.addEventListener('touchend', () => {
                    isDown = false;
                });

                // ========================================
                // AUTO-SCROLL: restore saved position or scroll to today
                // ========================================
                setTimeout(() => {
                    const scrollToDate = sessionStorage.getItem('calendarScrollToDate');
                    const savedScroll = sessionStorage.getItem('calendarScrollLeft');

                    if (scrollToDate) {
                        // Scroll to specific date (after creating reservation)
                        sessionStorage.removeItem('calendarScrollToDate');
                        sessionStorage.removeItem('calendarScrollLeft');
                        scrollCalendarToDate(scrollToDate, scroller);
                        console.log('✅ Scrolled to new booking date:', scrollToDate);
                    } else if (savedScroll !== null) {
                        scroller.scrollLeft = parseInt(savedScroll);
                        sessionStorage.removeItem('calendarScrollLeft');
                        console.log('✅ Restored scroll position:', savedScroll);
                    } else {
                        // First load: scroll to today automatically
                        const todayStr = new Date().toISOString().split('T')[0];
                        scrollCalendarToDate(todayStr, scroller);
                        console.log('✅ Auto-scrolled to today:', todayStr);
                    }
                }, 100);
            }
        } catch (e) {
            console.error('❌ Error in Drag Scroll setup:', e);
        }

        try {
            // ========================================
            // 2. NAVIGATION BUTTONS (PRIORITY)
            // ========================================
            const prevBtn = document.getElementById('prevMonthBtn');
            const nextBtn = document.getElementById('nextMonthBtn');

            if (prevBtn) {
                prevBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log('Prev Month Clicked');
                    if (typeof window.prevMonth === 'function') {
                        window.prevMonth();
                    } else {
                        console.error('window.prevMonth is not defined');
                    }
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log('Next Month Clicked');
                    if (typeof window.nextMonth === 'function') {
                        window.nextMonth();
                    } else {
                        console.error('window.nextMonth is not defined');
                    }
                });
            }
            console.log('✅ Navigation buttons initialized');
        } catch (e) {
            console.error('❌ Error in Navigation setup:', e);
        }

        try {
            // Form event listeners
            const checkInDate = document.getElementById('checkInDate');
            const checkOutDate = document.getElementById('checkOutDate');
            const roomSelect = document.getElementById('roomSelect');
            const roomPriceInput = document.getElementById('roomPrice');
            const discountInput = document.getElementById('discount');
            const adultInput = document.getElementById('adultCount');
            const childrenInput = document.getElementById('childrenCount');
            const paidAmountInput = document.getElementById('paidAmount');
            const paymentMethodInput = document.getElementById('paymentMethod');

            if (checkInDate) checkInDate.addEventListener('change', calculateNights);
            if (checkOutDate) checkOutDate.addEventListener('change', calculateNights);
            if (roomPriceInput) roomPriceInput.addEventListener('input', calculatePrice);
            if (discountInput) discountInput.addEventListener('input', calculatePrice);
            if (adultInput) adultInput.addEventListener('change', calculateTotalPax);
            if (childrenInput) childrenInput.addEventListener('change', calculateTotalPax);

            if (paidAmountInput) {
                paidAmountInput.addEventListener('input', function() {
                    const dpButtons = document.querySelectorAll('.dp-percent-btn');
                    dpButtons.forEach(btn => btn.classList.remove('active'));
                    delete paidAmountInput.dataset.dpPercent;
                    if (typeof updatePaymentStatusFromAmount === 'function') {
                        updatePaymentStatusFromAmount();
                    }
                });
            }

            const paymentButtons = document.querySelectorAll('#reservationModal .payment-method-btn');
            paymentButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    paymentButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    if (paymentMethodInput) {
                        paymentMethodInput.value = this.dataset.value;
                    }
                });
            });

            const payModalButtons = document.querySelectorAll('#bookingPaymentModal .payment-method-btn');
            payModalButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    payModalButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    const payMethodInput = document.getElementById('paymentMethodPay');
                    if (payMethodInput) {
                        payMethodInput.value = this.dataset.value;
                    }
                });
            });

            // Check-in Payment Modal - method buttons
            document.querySelectorAll('#checkinPaymentModal [data-ci-method]').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('#checkinPaymentModal [data-ci-method]').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    const ciMethod = document.getElementById('ciPayMethod');
                    if (ciMethod) ciMethod.value = this.dataset.ciMethod;
                });
            });

            // Setup Booking Source Logic
            const sourceSelect = document.getElementById('bookingSource');
            if (sourceSelect) {
                sourceSelect.addEventListener('change', function() {
                    // Trigger the logic to show/hide OTA options
                    if (typeof calculateFinalPrice === 'function') calculateFinalPrice();
                });
            }

            const dpButtons = document.querySelectorAll('.dp-percent-btn');
            dpButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    dpButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    const percent = parseFloat(this.dataset.percent);
                    if (typeof applyDpPercent === 'function') {
                        applyDpPercent(percent);
                    }
                });
            });

            if (roomSelect) {
                roomSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const price = selectedOption.getAttribute('data-price');
                    if (price) {
                        const roomPriceEl = document.getElementById('roomPrice');
                        if (roomPriceEl) roomPriceEl.value = price;
                        if (typeof calculatePrice === 'function') calculatePrice();
                    }
                });
            }
        } catch (e) {
            console.error('❌ Error in Form Listener setup:', e);
        }
    });
</script>

<!-- RESERVATION MODAL - POPUP SYSTEM 2028 -->
<div id="reservationModal" class="modal-overlay">
    <div class="modal-content modal-compact">
        <button class="modal-close" onclick="closeReservationModal()">×</button>

        <div class="modal-header-compact">
            <h2>New Reservation</h2>
        </div>

        <form id="reservationForm" onsubmit="submitReservation(event)">
            <input type="hidden" name="action" value="create_reservation">
            <input type="hidden" id="totalNights" name="total_nights" value="1">
            <input type="hidden" id="hiddenTotalPrice" name="total_price" value="0">
            <input type="hidden" id="hiddenFinalPrice" name="final_price" value="0">

            <div class="form-compact">
                <!-- MODE -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Mode*</label>
                        <select id="reservationMode" name="reservation_mode" onchange="setReservationMode(this.value)">
                            <option value="reservation" selected>Reservasi</option>
                            <option value="block_room">Block Room</option>
                        </select>
                    </div>
                    <div class="input-compact" id="blockHintWrap" style="justify-content:flex-end;">
                        <label style="opacity:0;">Info</label>
                        <small style="color:#b45309;background:#fffbeb;border:1px solid #fde68a;padding:8px 10px;border-radius:8px;display:block;">Mode Block Room dipakai untuk maintenance, deep cleaning, owner use, dll.</small>
                    </div>
                </div>

                <!-- GUEST INFO -->
                <div id="guestInfoSection" class="form-row-2col">
                    <div class="input-compact">
                        <label>Guest Name*</label>
                        <input type="text" id="guestName" name="guest_name" required placeholder="Full name">
                    </div>
                    <div class="input-compact">
                        <label>Phone</label>
                        <input type="text" id="guestPhone" name="guest_phone" placeholder="Phone/WA">
                    </div>
                </div>

                <!-- DATES -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Check In*</label>
                        <input type="date" id="checkInDate" name="check_in_date" required onchange="loadAvailableRoomsCalendar()">
                    </div>
                    <div class="input-compact">
                        <label>Check Out*</label>
                        <input type="date" id="checkOutDate" name="check_out_date" required onchange="loadAvailableRoomsCalendar()">
                    </div>
                </div>

                <!-- BLOCK ROOM INFO -->
                <div id="blockInfoSection" style="display:none;">
                    <div class="form-row-2col">
                        <div class="input-compact">
                            <label>Alasan Block*</label>
                            <select id="blockReason" name="block_reason">
                                <option value="maintenance">Maintenance</option>
                                <option value="deep_cleaning">Deep Cleaning</option>
                                <option value="out_of_order">Out of Order</option>
                                <option value="owner_use">Owner Use</option>
                                <option value="event_setup">Event Setup</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>
                        <div class="input-compact">
                            <label>Keterangan Tambahan</label>
                            <input type="text" id="blockNotes" name="block_notes" placeholder="Contoh: AC rusak, renovasi, pipa bocor">
                        </div>
                    </div>
                </div>

                <!-- ROOMS SELECTION (MULTI SELECT) -->
                <div class="input-compact">
                    <label>Select Rooms* (dapat pilih lebih dari 1)</label>
                    <div id="availabilityInfoCalendar" style="margin-bottom: 8px; font-size: 0.85rem;"></div>
                    <div id="roomsChecklistCalendar" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px; background: #f9f9f9;">
                        <em style="color: #888;">Pilih tanggal untuk melihat room yang tersedia...</em>
                    </div>
                    <div id="selectedRoomsSummaryCalendar" style="margin-top: 8px; font-size: 0.85rem; color: #6366f1;"></div>
                </div>

                <!-- GUESTS -->
                <div class="input-compact" id="guestCountSection">
                    <label>Guests</label>
                    <div class="guest-inputs">
                        <input type="number" id="adultCount" name="adult_count" value="1" min="1" style="flex:1;">
                        <span style="font-size:0.75rem; color:#888; padding:0 4px;">adult</span>
                    </div>
                </div>

                <!-- SOURCE & PAYMENT METHOD -->
                <div class="form-row-2col" id="sourcePaymentSection">
                    <div class="input-compact">
                        <label>Booking Source <span style="color: red;">*</span></label>
                        <select id="bookingSource" name="booking_source" onchange="updateSourceDetails()" required>
                            <option value="">-- Pilih Booking Source --</option>
                            <?php
                            $srcDirect = array_filter($bookingSources, fn($s) => ($s['source_type'] ?? '') === 'direct');
                            $srcOta = array_filter($bookingSources, fn($s) => ($s['source_type'] ?? '') !== 'direct');
                            if (!empty($srcDirect) || !empty($srcOta)):
                            ?>
                                <optgroup label="Direct">
                                    <?php foreach ($srcDirect as $src): ?>
                                        <option value="<?php echo htmlspecialchars($src['source_key']); ?>"><?php echo $src['icon'] . ' ' . htmlspecialchars($src['source_name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="OTA">
                                    <?php foreach ($srcOta as $src): ?>
                                        <option value="<?php echo htmlspecialchars($src['source_key']); ?>"><?php echo $src['icon'] . ' ' . htmlspecialchars($src['source_name']) . ' (fee ' . $src['fee_percent'] . '%)'; ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php else: ?>
                                <option value="walk_in">🚶 Direct (Walk-in)</option>
                                <option value="phone">📞 Direct (Phone)</option>
                                <option value="agoda">🏨 Agoda</option>
                                <option value="booking">📱 Booking.com</option>
                                <option value="tiket">✈️ Tiket.com</option>
                                <option value="ota">🌐 OTA Lainnya</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="input-compact">
                        <label>Payment Method</label>
                        <select name="payment_method" id="paymentMethod" onchange="calculateFinalPrice()">
                            <option value="cash">Cash</option>
                            <option value="transfer">Transfer</option>
                            <option value="qris">QRIS</option>
                            <option value="ota">OTA</option>
                        </select>
                    </div>
                </div>

                <!-- PRICE SUMMARY -->
                <div class="price-summary-compact" id="priceSummarySection">
                    <div class="price-line">
                        <span>Total Rooms:</span>
                        <strong id="totalRoomsDisplayCalendar">0 rooms</strong>
                    </div>
                    <div class="price-line">
                        <span>Nights:</span>
                        <strong id="displayNights">0</strong>
                    </div>
                    <div class="price-line">
                        <span>Subtotal:</span>
                        <strong id="subtotalDisplayCalendar">Rp 0</strong>
                    </div>
                    <div class="price-line" style="flex-direction: column; align-items: flex-start;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; width: 100%; margin-bottom: 0.5rem;">
                            <span>Discount:</span>
                            <div class="discount-type-toggle" style="display: flex; gap: 0; margin-left: auto;">
                                <button type="button" class="disc-type-btn-cal active" data-type="rp" onclick="setDiscountTypeCalendar('rp')" style="padding: 4px 10px; font-size: 0.75rem; border: 1px solid #6366f1; background: #6366f1; color: white; border-radius: 4px 0 0 4px; cursor: pointer;">Rp</button>
                                <button type="button" class="disc-type-btn-cal" data-type="percent" onclick="setDiscountTypeCalendar('percent')" style="padding: 4px 10px; font-size: 0.75rem; border: 1px solid #6366f1; background: white; color: #6366f1; border-radius: 0 4px 4px 0; cursor: pointer;">%</button>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem; width: 100%;">
                            <input type="number" id="discount" name="discount" value="0" min="0" onchange="calculateMultiRoomTotalCalendar()" style="text-align:right; flex: 1; padding: 6px 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="0">
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
                    <div class="price-line-total">
                        <span>GRAND TOTAL:</span>
                        <strong id="grandTotalDisplayCalendar" style="color:#10b981; font-size: 1.3rem;">Rp 0</strong>
                    </div>
                </div>

                <!-- PAYMENT -->
                <div class="input-compact" id="paymentSection">
                    <label>Initial Payment (DP) - Rp</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="number" id="paidAmount" name="paid_amount" value="0" placeholder="0" style="flex: 1;">
                        <button type="button" onclick="payFullMultiRoomCalendar()" class="btn-pay-all" title="Pay Full Amount">Pay All</button>
                    </div>
                </div>
            </div>

            <div class="modal-footer-compact">
                <button type="button" class="btn-cancel" onclick="closeReservationModal()">Cancel</button>
                <button type="submit" class="btn-save" id="reservationSubmitBtn">Save Reservation</button>
            </div>
        </form>
    </div>
</div>

<style>
    /* COMPACT RESERVATION MODAL STYLES */
    .modal-compact {
        width: 90%;
        max-width: 600px;
        max-height: 85vh;
        display: flex;
        flex-direction: column;
        background: white;
        border-radius: 12px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        padding: 0;
        overflow: hidden;
    }

    body[data-theme="dark"] .modal-compact {
        background: #1e293b;
    }

    .modal-header-compact {
        padding: 1rem 1.5rem;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        border-bottom: none;
    }

    .modal-header-compact h2 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 700;
    }

    .form-compact {
        padding: 1.5rem;
        overflow-y: auto;
        flex: 1;
    }

    .form-row-2col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .input-compact {
        display: flex;
        flex-direction: column;
    }

    .input-compact label {
        font-size: 0.85rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
        color: #475569;
    }

    body[data-theme="dark"] .input-compact label {
        color: #cbd5e1;
    }

    .input-compact input,
    .input-compact select {
        padding: 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.9rem;
        font-family: inherit;
    }

    body[data-theme="dark"] .input-compact input,
    body[data-theme="dark"] .input-compact select {
        background: #334155;
        border-color: #475569;
        color: white;
    }

    .input-compact input:focus,
    .input-compact select:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .guest-inputs {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .guest-inputs input {
        padding: 0.4rem;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        font-size: 0.85rem;
    }

    .price-summary-compact {
        background: #f1f5f9;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        font-size: 0.9rem;
    }

    body[data-theme="dark"] .price-summary-compact {
        background: #334155;
    }

    .price-line {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        align-items: center;
    }

    .price-line input {
        width: 150px;
        padding: 0.25rem;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        font-size: 0.85rem;
        text-align: right;
    }

    body[data-theme="dark"] .price-line input {
        background: #1e293b;
        border-color: #475569;
        color: white;
    }

    .price-line-total {
        display: flex;
        justify-content: space-between;
        font-weight: 700;
        font-size: 1rem;
        padding-top: 0.5rem;
        border-top: 2px solid #cbd5e1;
    }

    body[data-theme="dark"] .price-line-total {
        border-color: #475569;
    }

    .modal-footer-compact {
        padding: 1rem 1.5rem;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
    }

    body[data-theme="dark"] .modal-footer-compact {
        background: #0f172a;
        border-color: #334155;
    }

    .btn-cancel {
        padding: 0.6rem 1.5rem;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.2s;
    }

    body[data-theme="dark"] .btn-cancel {
        background: #334155;
        border-color: #475569;
        color: white;
    }

    .btn-pay-all {
        padding: 0.5rem 1rem;
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.2s;
        white-space: nowrap;
    }

    .btn-pay-all:hover {
        background: linear-gradient(135deg, #059669, #047857);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    body[data-theme="dark"] .btn-pay-all {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .btn-cancel:hover {
        background: #f1f5f9;
    }

    .btn-save {
        padding: 0.6rem 2rem;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 700;
        font-size: 0.9rem;
        transition: all 0.3s;
    }

    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(99, 102, 241, 0.3);
    }

    .btn-save:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    /* Scrollbar styling */
    .form-compact::-webkit-scrollbar {
        width: 6px;
    }

    .form-compact::-webkit-scrollbar-track {
        background: transparent;
    }

    .form-compact::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 3px;
    }

    .form-compact::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }
</style>

<style>
    /* SYSTEM 2028 STYLES */
    .glass-panel {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        border-radius: 20px;
        padding: 0 !important;
        overflow: hidden;
    }

    body[data-theme="dark"] .glass-panel {
        background: rgba(30, 41, 59, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.05);
    }

    .modal-header {
        padding: 1.5rem 2rem;
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .modal-header h2 {
        margin: 0;
        font-size: 1.5rem;
        background: linear-gradient(to right, #6366f1, #8b5cf6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .form-grid-2028 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        padding: 2rem;
        overflow-y: auto;
        /* Enable scroll here */
        flex: 1;
        /* Take remaining space */
    }

    /* Scrollbar for form grid */
    .form-grid-2028::-webkit-scrollbar {
        width: 6px;
    }

    .form-grid-2028::-webkit-scrollbar-track {
        background: rgba(99, 102, 241, 0.05);
    }

    .form-grid-2028::-webkit-scrollbar-thumb {
        background: rgba(99, 102, 241, 0.2);
        border-radius: 3px;
    }

    .form-grid-2028::-webkit-scrollbar-thumb:hover {
        background: rgba(99, 102, 241, 0.4);
    }

    @media (max-width: 768px) {
        .form-grid-2028 {
            grid-template-columns: 1fr;
            gap: 1rem;
            padding: 1rem;
        }
    }

    .form-section-modern {
        margin-bottom: 2rem;
    }

    .form-section-modern h3 {
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #64748b;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .input-group-modern {
        margin-bottom: 1rem;
    }

    .input-group-modern label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        margin-bottom: 0.4rem;
        color: var(--text-secondary);
    }

    .input-group-modern input,
    .input-group-modern select {
        width: 100%;
        padding: 0.75rem 1rem;
        border-radius: 10px;
        border: 1px solid var(--border-color);
        background: var(--input-bg);
        color: var(--text-primary);
        transition: all 0.2s;
    }

    .input-group-modern input:focus,
    .input-group-modern select:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        outline: none;
    }

    .date-range-modern {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .price-card-2028 {
        background: var(--bg-secondary);
        border-radius: 16px;
        padding: 1.5rem;
        border: 1px solid var(--border-color);
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .price-row input {
        width: 100px;
        text-align: right;
        background: transparent;
        border: 1px solid transparent;
        color: var(--text-primary);
        font-weight: 600;
    }

    .price-row input:hover {
        border-color: var(--border-color);
        background: var(--input-bg);
        border-radius: 4px;
    }

    .total-display {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px dashed var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .total-display strong {
        font-size: 1.5rem;
        color: #6366f1;
    }

    .payment-methods-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr 1fr;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .pm-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 0.75rem 0.25rem;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s;
        background: var(--card-bg);
    }

    .pm-item.active {
        background: rgba(99, 102, 241, 0.1);
        border-color: #6366f1;
        color: #6366f1;
    }

    .pm-icon {
        font-size: 1.25rem;
        margin-bottom: 0.25rem;
    }

    .pm-name {
        font-size: 0.7rem;
        font-weight: 600;
    }

    .modal-footer-modern {
        padding: 1rem 2rem;
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        background: white;
        /* Ensure opaque background */
        border-top: 1px solid var(--border-color);
        flex-shrink: 0;
        z-index: 10;
    }

    body[data-theme="dark"] .modal-footer-modern {
        background: #1e293b;
    }

    .modal-header {
        flex-shrink: 0;
    }

    .btn-glow {
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
        color: white;
        border: none;
        padding: 0.75rem 2rem;
        border-radius: 12px;
        font-weight: 600;
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-glow:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
    }

    .btn-ghost {
        background: transparent;
        border: none;
        color: var(--text-secondary);
        font-weight: 600;
        cursor: pointer;
        padding: 0.75rem 1.5rem;
    }

    .pax-inputs {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .pax-inputs input {
        width: 60px;
        text-align: center;
        padding: 0.5rem;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        background: var(--input-bg);
        color: var(--text-primary);
    }

    .fee-badge {
        display: inline-block;
        background: rgba(244, 63, 94, 0.1);
        color: #f43f5e;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        margin-top: 0.5rem;
    }
</style>
<!-- END RESERVATION MODAL -->




<div id="bookingDetailsModal" class="modal-overlay" style="display: none !important;">
    <div class="modal-content modal-content-medium"></div>
</div>

<!-- Guest Detail Side Panel (Cloudbed-style) -->
<div id="bookingQuickView" class="guest-side-panel-overlay" onclick="if(event.target===this)closeBookingQuickView()">
    <div class="guest-side-panel">
        <div class="side-panel-header">
            <div class="side-panel-header-left">
                <div class="guest-avatar" id="sp-avatar">MS</div>
                <div class="guest-header-info">
                    <h2 id="sp-guest-name">Guest Name</h2>
                    <p id="sp-guest-phone" class="guest-phone-text">-</p>
                </div>
            </div>
            <div class="side-panel-header-right">
                <a id="sp-wa-link" href="#" target="_blank" class="sp-icon-btn" title="WhatsApp" style="display:none;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="#25D366">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" />
                        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.468l4.584-1.454A11.935 11.935 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.75c-2.115 0-4.09-.654-5.712-1.77l-.41-.262-2.717.862.724-2.632-.287-.446A9.714 9.714 0 012.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75z" />
                    </svg>
                </a>
                <button class="sp-icon-btn" onclick="closeBookingQuickView()" title="Close">×</button>
            </div>
        </div>

        <!-- Status Badge -->
        <div class="sp-status-row">
            <span class="sp-status-badge" id="sp-status">● Confirmed</span>
            <span class="sp-source-badge" id="sp-source">Walk-In</span>
        </div>

        <!-- Booking Timeline -->
        <div class="sp-timeline">
            <div class="sp-timeline-track">
                <div class="sp-timeline-progress" id="sp-timeline-progress"></div>
            </div>
            <div class="sp-timeline-labels">
                <div class="sp-timeline-point">
                    <span class="sp-timeline-label">Booked</span>
                    <span class="sp-timeline-date" id="sp-booked-date">-</span>
                </div>
                <div class="sp-timeline-point">
                    <span class="sp-timeline-label">Check-in</span>
                    <span class="sp-timeline-date" id="sp-checkin-date">-</span>
                </div>
                <div class="sp-timeline-point">
                    <span class="sp-timeline-label">Check-out</span>
                    <span class="sp-timeline-date" id="sp-checkout-date">-</span>
                </div>
            </div>
        </div>

        <!-- Guest Info Row -->
        <div class="sp-guest-info-row">
            <div class="sp-info-icon" title="Adults"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg> <span id="sp-adults">1</span></div>
            <div class="sp-info-icon" title="Children"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="7" r="4" />
                    <path d="M5.5 21v-2a4 4 0 0 1 3-3.87M18.5 21v-2a4 4 0 0 0-3-3.87" />
                </svg> <span id="sp-children">0</span></div>
        </div>

        <!-- Tabs -->
        <div class="sp-tabs">
            <button class="sp-tab active" onclick="switchSPTab('folio')">Folio</button>
            <button class="sp-tab" onclick="switchSPTab('details')">Details</button>
            <button class="sp-tab" onclick="switchSPTab('room')">Room</button>
        </div>

        <!-- Tab Content: Folio -->
        <div class="sp-tab-content active" id="sp-tab-folio">
            <div class="sp-balance-box">
                <div class="sp-balance-label">Balance due</div>
                <div class="sp-balance-amount" id="sp-balance">Rp0</div>
            </div>
            <table class="sp-folio-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Debit</th>
                        <th class="text-right">Credit</th>
                    </tr>
                </thead>
                <tbody id="sp-folio-body">
                    <!-- Populated by JS -->
                </tbody>
                <tfoot>
                    <tr class="sp-folio-total">
                        <td>Total</td>
                        <td class="text-right" id="sp-total-debit">-</td>
                        <td class="text-right" id="sp-total-credit">-</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Tab Content: Details -->
        <div class="sp-tab-content" id="sp-tab-details">
            <div class="sp-detail-section">
                <h4>Reservation Details</h4>
                <div class="sp-detail-row"><span>Booking Code</span><strong id="sp-booking-code">-</strong></div>
                <div class="sp-detail-row"><span>Booking Source</span><strong id="sp-detail-source">-</strong></div>
                <div class="sp-detail-row"><span>Check-in</span><strong id="sp-detail-checkin">-</strong></div>
                <div class="sp-detail-row"><span>Check-out</span><strong id="sp-detail-checkout">-</strong></div>
                <div class="sp-detail-row"><span>Nights</span><strong id="sp-detail-nights">-</strong></div>
                <div class="sp-detail-row"><span>Guests</span><strong id="sp-detail-guests">-</strong></div>
                <div class="sp-detail-row"><span>Special Request</span><strong id="sp-detail-notes" style="font-style:italic;font-weight:400;">-</strong></div>
            </div>
            <div class="sp-detail-section" id="sp-extras-section" style="display:none;">
                <h4>Extras</h4>
                <div id="sp-extras-list"></div>
            </div>
        </div>

        <!-- Tab Content: Room -->
        <div class="sp-tab-content" id="sp-tab-room">
            <div class="sp-room-card">
                <div class="sp-room-type" id="sp-room-type">-</div>
                <div class="sp-room-number" id="sp-room-number">Room -</div>
                <div class="sp-room-price">
                    <span>Price/night:</span>
                    <strong id="sp-room-price-val">-</strong>
                </div>
            </div>

            <!-- Group Bookings / Related Rooms -->
            <div id="sp-group-rooms-section" style="display:none;margin-top:1.2rem;padding-top:1rem;border-top:1px solid var(--border-color);">
                <h4 style="margin:0 0 0.8rem 0;font-size:0.9rem;color:var(--text-secondary);">📦 Kamar dalam Grup:</h4>
                <div id="sp-group-rooms-list" style="display:grid;gap:0.6rem;"></div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="sp-actions" id="sp-actions">
            <!-- Populated by JS -->
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="bookingPaymentModal" class="modal-overlay">
    <div class="payment-modal">
        <button class="payment-modal-close" onclick="closeBookingPaymentModal()">×</button>
        <div class="payment-modal-header">
            <h3>Payment</h3>
            <p id="paymentModalSubtitle">Pembayaran booking</p>
        </div>
        <div class="payment-modal-body">
            <div class="payment-info">
                <div><span>Total:</span> <strong id="paymentTotal">Rp 0</strong></div>
                <div><span>Sudah Bayar:</span> <strong id="paymentPaid">Rp 0</strong></div>
                <div><span>Sisa:</span> <strong id="paymentRemaining">Rp 0</strong></div>
            </div>
            <div class="form-group">
                <label>Metode Pembayaran</label>
                <input type="hidden" id="paymentMethodPay" value="cash">
                <div class="payment-method-group">
                    <button type="button" class="payment-method-btn" data-value="ota">OTA</button>
                    <button type="button" class="payment-method-btn active" data-value="cash">Cash</button>
                    <button type="button" class="payment-method-btn" data-value="transfer">Transfer</button>
                    <button type="button" class="payment-method-btn" data-value="qris">QR</button>
                </div>
            </div>
            <div class="form-group">
                <label>Jumlah Bayar (Rp)</label>
                <input type="number" id="paymentAmount" min="0" value="0">
            </div>
            <div class="payment-modal-actions">
                <button type="button" class="btn-secondary" onclick="closeBookingPaymentModal()">Cancel</button>
                <button type="button" class="btn-primary" onclick="submitBookingPayment()">Pay</button>
            </div>
        </div>
    </div>
</div>

<!-- CHECK-IN PAYMENT MODAL -->
<div id="checkinPaymentModal" class="modal-overlay" onclick="if(event.target===this)closeCheckinPaymentModal()">
    <div class="payment-modal" style="max-width:460px">
        <button class="payment-modal-close" onclick="closeCheckinPaymentModal()">×</button>
        <div style="text-align:center;margin-bottom:1rem">
            <div style="font-size:2rem;margin-bottom:0.25rem">🏨</div>
            <h3 style="margin:0;font-size:1.1rem;font-weight:700">Check-in Tamu</h3>
            <p id="ciGuestInfo" style="margin:0.25rem 0 0;font-size:0.8rem;color:var(--text-secondary)"></p>
        </div>
        <!-- Ringkasan tagihan -->
        <div class="payment-info" style="margin-bottom:0.75rem">
            <div style="display:flex;justify-content:space-between;margin-bottom:0.3rem">
                <span>Kamar</span><strong id="ciRoom">-</strong>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:0.3rem">
                <span>Total Tagihan</span><strong id="ciTotal" style="color:#ef4444">Rp 0</strong>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:0.3rem">
                <span>Sudah Dibayar</span><strong id="ciPaid" style="color:#10b981">Rp 0</strong>
            </div>
            <div style="display:flex;justify-content:space-between;border-top:1px solid rgba(99,102,241,0.2);padding-top:0.4rem;margin-top:0.2rem">
                <span style="font-weight:600">Sisa Tagihan</span>
                <strong id="ciRemaining" style="font-size:1.1em;color:#f59e0b">Rp 0</strong>
            </div>
        </div>
        <!-- Form pembayaran (tampil saat klik bayar) -->
        <div id="ciPayForm" style="display:none;margin-bottom:0.75rem">
            <div style="margin-bottom:0.6rem">
                <label style="font-size:0.78rem;font-weight:600;margin-bottom:0.3rem;display:block">Metode Pembayaran</label>
                <input type="hidden" id="ciPayMethod" value="cash">
                <div class="payment-method-group">
                    <button type="button" class="payment-method-btn active" data-ci-method="cash">Cash</button>
                    <button type="button" class="payment-method-btn" data-ci-method="transfer">Transfer</button>
                    <button type="button" class="payment-method-btn" data-ci-method="qris">QRIS</button>
                    <button type="button" class="payment-method-btn" data-ci-method="card">Card</button>
                </div>
            </div>
            <div>
                <label style="font-size:0.78rem;font-weight:600;margin-bottom:0.3rem;display:block">Jumlah Bayar (Rp)</label>
                <input type="number" id="ciPayAmount" min="0" value="0"
                    style="width:100%;padding:0.5rem;border:1px solid #e2e8f0;border-radius:6px;font-size:0.9rem;box-sizing:border-box">
            </div>
        </div>
        <!-- Tombol aksi default -->
        <div id="ciDefaultBtns">
            <button type="button" onclick="showCiPayForm()"
                style="width:100%;padding:0.75rem;background:var(--primary,#6366f1);color:white;border:none;border-radius:8px;font-weight:600;font-size:0.9rem;cursor:pointer;margin-bottom:0.5rem;transition:opacity 0.2s"
                onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                💳 Bayar Tagihan Sekarang
            </button>
            <button type="button" onclick="doCheckin(false)"
                style="width:100%;padding:0.65rem;background:transparent;color:var(--text-secondary,#6b7280);border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;cursor:pointer">
                ⏰ Check-in Dulu, Bayar Nanti
            </button>
        </div>
        <!-- Tombol saat form pembayaran tampil -->
        <div id="ciPayBtns" class="payment-modal-actions" style="display:none">
            <button type="button" class="btn-secondary" onclick="hideCiPayForm()">← Kembali</button>
            <button type="button" class="btn-primary" onclick="doCheckin(true)">✅ Bayar &amp; Check-in</button>
        </div>
    </div>
</div>

<style>
    /* RESERVATION MODAL STYLES */
    #reservationModal {
        display: none;
        /* Changed from none!important to allow flex via JS */
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }

    #reservationModal.active {
        display: flex !important;
    }

    .booking-summary-box {
        background: #f8fafc;
        padding: 1rem;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .summary-input {
        width: 120px !important;
        text-align: right;
        padding: 0.25rem 0.5rem !important;
    }

    .total-row {
        margin-top: 0.75rem;
        padding-top: 0.75rem;
        border-top: 1px dashed #cbd5e1;
        font-size: 1.1rem;
        color: #6366f1;
    }

    /* Modal Overlay Base Styles */
    .modal-overlay {
        display: none;
        /* Hidden by default */
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        z-index: 99999;
        /* High Z-index */
        align-items: center;
        justify-content: center;
        transition: opacity 0.3s ease;
        opacity: 0;
        pointer-events: none;
    }

    /* Active State for Modals */
    .modal-overlay.active {
        display: flex !important;
        opacity: 1;
        pointer-events: auto;
    }

    /* ========== SEARCH BAR ========== */
    .search-reservation-bar {
        position: relative;
        margin-bottom: 0.4rem;
    }

    .search-input-wrapper {
        display: flex;
        align-items: center;
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 10px;
        padding: 0.5rem 0.85rem;
        gap: 0.5rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .search-input-wrapper:focus-within {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
    }

    .search-icon {
        color: #94a3b8;
        flex-shrink: 0;
    }

    .search-input {
        border: none;
        outline: none;
        font-size: 0.9rem;
        color: #334155;
        flex: 1;
        background: transparent;
        font-weight: 500;
    }

    .search-input::placeholder {
        color: #94a3b8;
        font-weight: 400;
    }

    .search-clear-btn {
        background: none;
        border: none;
        font-size: 1.3rem;
        color: #94a3b8;
        cursor: pointer;
        line-height: 1;
        padding: 0 2px;
    }

    .search-clear-btn:hover {
        color: #ef4444;
    }

    .search-results-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        max-height: 380px;
        overflow-y: auto;
        z-index: 999;
        margin-top: 4px;
    }

    .search-result-item {
        display: flex;
        align-items: center;
        padding: 0.65rem 0.85rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        gap: 0.65rem;
        transition: background 0.15s;
    }

    .search-result-item:last-child {
        border-bottom: none;
    }

    .search-result-item:hover {
        background: #f8fafc;
    }

    .sr-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 800;
        flex-shrink: 0;
        letter-spacing: 0.5px;
    }

    .sr-info {
        flex: 1;
        min-width: 0;
    }

    .sr-name {
        font-weight: 700;
        font-size: 0.85rem;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sr-meta {
        font-size: 0.72rem;
        color: #64748b;
        margin-top: 1px;
    }

    .sr-status {
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 4px;
        text-transform: uppercase;
        flex-shrink: 0;
    }

    .sr-status.checked_in {
        background: #dcfce7;
        color: #16a34a;
    }

    .sr-status.confirmed {
        background: #dbeafe;
        color: #2563eb;
    }

    .sr-status.pending {
        background: #fef3c7;
        color: #d97706;
    }

    .sr-status.checked_out {
        background: #f1f5f9;
        color: #64748b;
    }

    .sr-status.cancelled {
        background: #fce4ec;
        color: #e53935;
    }

    .search-no-result {
        text-align: center;
        padding: 1.5rem;
        color: #94a3b8;
        font-size: 0.85rem;
    }

    /* ========== GUEST SIDE PANEL (Cloudbed-style) ========== */
    .guest-side-panel-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.35);
        backdrop-filter: blur(2px);
        z-index: 10000;
        justify-content: flex-end;
    }

    .guest-side-panel-overlay.active {
        display: flex !important;
    }

    .guest-side-panel {
        width: 480px;
        max-width: 95vw;
        height: 100vh;
        background: #fff;
        box-shadow: -8px 0 40px rgba(0, 0, 0, 0.15);
        overflow-y: auto;
        padding: 1.5rem;
        animation: slidePanelIn 0.25s ease-out;
        display: flex;
        flex-direction: column;
    }

    @keyframes slidePanelIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .side-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.75rem;
    }

    .side-panel-header-left {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .guest-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        font-weight: 800;
        letter-spacing: 0.5px;
        flex-shrink: 0;
    }

    .guest-header-info h2 {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
    }

    .guest-phone-text {
        margin: 2px 0 0;
        font-size: 0.8rem;
        color: #64748b;
    }

    .side-panel-header-right {
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }

    .sp-icon-btn {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border: 1px solid #e2e8f0;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1.3rem;
        color: #64748b;
        transition: all 0.2s;
        text-decoration: none;
    }

    .sp-icon-btn:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
    }

    .sp-status-row {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .sp-status-badge {
        font-size: 0.78rem;
        font-weight: 700;
        padding: 4px 12px;
        border-radius: 20px;
        background: #dbeafe;
        color: #2563eb;
    }

    .sp-source-badge {
        font-size: 0.72rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 20px;
        background: #f1f5f9;
        color: #475569;
    }

    /* Timeline */
    .sp-timeline {
        margin-bottom: 1rem;
        padding: 0.75rem;
        background: #f8fafc;
        border-radius: 10px;
    }

    .sp-timeline-track {
        height: 4px;
        background: #e2e8f0;
        border-radius: 2px;
        margin-bottom: 0.5rem;
        position: relative;
    }

    .sp-timeline-progress {
        height: 100%;
        background: linear-gradient(90deg, #6366f1, #8b5cf6);
        border-radius: 2px;
        transition: width 0.3s;
    }

    .sp-timeline-labels {
        display: flex;
        justify-content: space-between;
    }

    .sp-timeline-point {
        text-align: center;
    }

    .sp-timeline-label {
        display: block;
        font-size: 0.68rem;
        color: #94a3b8;
        font-weight: 600;
        text-transform: uppercase;
    }

    .sp-timeline-date {
        display: block;
        font-size: 0.75rem;
        color: #334155;
        font-weight: 700;
    }

    /* Guest info row */
    .sp-guest-info-row {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        padding: 0.5rem 0.75rem;
        background: #f8fafc;
        border-radius: 8px;
    }

    .sp-info-icon {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 0.82rem;
        font-weight: 700;
        color: #475569;
    }

    .sp-info-icon svg {
        color: #6366f1;
    }

    /* Tabs */
    .sp-tabs {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 0;
    }

    .sp-tab {
        padding: 0.5rem 1rem;
        border: none;
        background: none;
        font-size: 0.82rem;
        font-weight: 600;
        color: #94a3b8;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s;
    }

    .sp-tab:hover {
        color: #475569;
    }

    .sp-tab.active {
        color: #1e3a5f;
        border-bottom-color: #1e3a5f;
    }

    /* Tab content */
    .sp-tab-content {
        display: none;
        padding: 1rem 0;
        flex: 1;
        overflow-y: auto;
        min-height: 0;
    }

    .sp-tab-content.active {
        display: block;
        overflow-y: auto;
    }

    /* Balance box */
    .sp-balance-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.65rem 0.85rem;
        background: #f8fafc;
        border-radius: 8px;
        margin-bottom: 0.75rem;
        border: 1px solid #e2e8f0;
    }

    .sp-balance-label {
        font-size: 0.82rem;
        color: #64748b;
        font-weight: 600;
    }

    .sp-balance-amount {
        font-size: 1.05rem;
        font-weight: 800;
        color: #1e293b;
    }

    /* Folio table */
    .sp-folio-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }

    .sp-folio-table th {
        text-align: left;
        padding: 0.5rem 0.4rem;
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        border-bottom: 2px solid #e2e8f0;
    }

    .sp-folio-table td {
        padding: 0.55rem 0.4rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        vertical-align: top;
    }

    .sp-folio-table .text-right {
        text-align: right;
    }

    .sp-folio-table .folio-desc-title {
        font-weight: 600;
        font-size: 0.78rem;
    }

    .sp-folio-table .folio-desc-sub {
        font-size: 0.7rem;
        color: #94a3b8;
        margin-top: 1px;
    }

    .sp-folio-total td {
        font-weight: 700;
        border-top: 2px solid #cbd5e1;
        padding-top: 0.6rem;
        color: #1e293b;
    }

    /* Detail section */
    .sp-detail-section {
        margin-bottom: 1rem;
    }

    .sp-detail-section h4 {
        font-size: 0.82rem;
        font-weight: 700;
        color: #475569;
        margin: 0 0 0.5rem;
        padding-bottom: 0.35rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .sp-detail-row {
        display: flex;
        justify-content: space-between;
        padding: 0.35rem 0;
        font-size: 0.8rem;
    }

    .sp-detail-row span {
        color: #64748b;
    }

    .sp-detail-row strong {
        color: #1e293b;
    }

    /* Room card */
    .sp-room-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 1rem;
        text-align: center;
    }

    .sp-room-type {
        font-size: 0.78rem;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .sp-room-number {
        font-size: 1.3rem;
        font-weight: 900;
        color: #1e3a5f;
        margin: 0.25rem 0;
    }

    .sp-room-price {
        font-size: 0.82rem;
        color: #64748b;
    }

    .sp-room-price strong {
        color: #6366f1;
    }

    /* Action buttons */
    .sp-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        padding-top: 0.75rem;
        border-top: 1px solid #e2e8f0;
        margin-top: auto;
    }

    .sp-action-btn {
        flex: 1;
        min-width: 80px;
        padding: 0.55rem 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        background: #fff;
        color: #334155;
        text-align: center;
    }

    .sp-action-btn:hover {
        background: #f1f5f9;
    }

    .sp-action-btn.primary {
        background: #6366f1;
        color: #fff;
        border-color: #6366f1;
    }

    .sp-action-btn.primary:hover {
        background: #4f46e5;
    }

    .sp-action-btn.success {
        background: #10b981;
        color: #fff;
        border-color: #10b981;
    }

    .sp-action-btn.success:hover {
        background: #059669;
    }

    .sp-action-btn.danger {
        background: #ef4444;
        color: #fff;
        border-color: #ef4444;
    }

    .sp-action-btn.danger:hover {
        background: #dc2626;
    }

    .sp-action-btn.warning {
        background: #f59e0b;
        color: #fff;
        border-color: #f59e0b;
    }

    .sp-action-btn.warning:hover {
        background: #d97706;
    }

    .guest-side-panel::-webkit-scrollbar {
        width: 6px;
    }

    .guest-side-panel::-webkit-scrollbar-track {
        background: rgba(99, 102, 241, 0.05);
    }

    .guest-side-panel::-webkit-scrollbar-thumb {
        background: rgba(99, 102, 241, 0.2);
        border-radius: 3px;
    }

    .guest-side-panel::-webkit-scrollbar-thumb:hover {
        background: rgba(99, 102, 241, 0.4);
    }

    .payment-modal {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 420px;
        padding: 1.25rem;
        position: relative;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(99, 102, 241, 0.1);
    }

    .payment-modal-close {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        background: rgba(239, 68, 68, 0.1);
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        font-size: 1.5rem;
        cursor: pointer;
        color: #ef4444;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        line-height: 1;
        font-weight: 300;
    }

    .payment-modal-header {
        text-align: center;
        margin-bottom: 0.75rem;
    }

    .payment-modal-header h3 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 700;
    }

    .payment-modal-header p {
        margin: 0.25rem 0 0;
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    .payment-info {
        background: rgba(99, 102, 241, 0.05);
        border-radius: 8px;
        padding: 0.75rem;
        font-size: 0.8rem;
        line-height: 1.5;
        margin-bottom: 0.75rem;
    }

    .payment-info span {
        color: var(--text-secondary);
    }

    .payment-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    /* Scrollbar styling (side panel handled above) */
</style>

<!-- EXTEND STAY MODAL -->
<div id="extendModal" class="extend-modal-overlay" onclick="if(event.target===this)closeExtendModal()">
    <div class="extend-modal">
        <h3>➕ Extend Stay</h3>
        <input type="hidden" id="extendBookingId">
        <div style="background:rgba(16,185,129,0.08); border-radius:8px; padding:0.75rem; margin-bottom:0.75rem; font-size:0.8rem;">
            <div><strong>Guest:</strong> <span id="extendGuestName">-</span></div>
            <div><strong>Current Check-out:</strong> <span id="extendCurrentCO">-</span></div>
        </div>
        <div class="form-group">
            <label>Tambah Malam</label>
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <button type="button" onclick="adjustExtendNights(-1)" style="width:32px;height:32px;border:1px solid var(--border-color);border-radius:6px;background:transparent;cursor:pointer;font-size:1rem;font-weight:700;color:var(--text-primary);">−</button>
                <input type="number" id="extendNights" value="1" min="1" max="30" style="width:60px;text-align:center;">
                <button type="button" onclick="adjustExtendNights(1)" style="width:32px;height:32px;border:1px solid var(--border-color);border-radius:6px;background:transparent;cursor:pointer;font-size:1rem;font-weight:700;color:var(--text-primary);">+</button>
                <span style="font-size:0.8rem;color:var(--text-secondary);margin-left:0.5rem;">New CO: <strong id="extendNewCO">-</strong></span>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeExtendModal()">Batal</button>
            <button class="btn-confirm" onclick="submitExtendStay()">Extend Stay</button>
        </div>
    </div>
</div>

<!-- EDIT RESERVATION MODAL -->
<div id="editResModal" class="edit-res-overlay" onclick="if(event.target===this)closeEditResModal()">
    <div class="edit-res-modal" style="max-width:520px;">
        <h3>✏️ Edit Reservasi</h3>
        <input type="hidden" id="editResBookingId">
        <div class="form-row">
            <div class="form-group">
                <label>Nama Tamu</label>
                <input type="text" id="editResGuestName">
            </div>
            <div class="form-group">
                <label>Telepon</label>
                <input type="text" id="editResGuestPhone">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Check-in</label>
                <input type="date" id="editResCheckIn" onchange="updateEditResInfo()">
            </div>
            <div class="form-group">
                <label>Check-out</label>
                <input type="date" id="editResCheckOut" onchange="updateEditResInfo()">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Email</label>
                <input type="email" id="editResEmail">
            </div>
            <div class="form-group">
                <label>No. KTP/Paspor</label>
                <input type="text" id="editResIdNumber">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Booking Source</label>
                <select id="editResSource" onchange="updateEditResInfo()">
                    <?php
                    $directSrc = array_filter($bookingSources ?? [], fn($s) => ($s['source_type'] ?? '') === 'direct');
                    $otaSrcList = array_filter($bookingSources ?? [], fn($s) => ($s['source_type'] ?? '') !== 'direct');
                    if (!empty($directSrc) || !empty($otaSrcList)): ?>
                        <optgroup label="Direct">
                            <?php foreach ($directSrc as $src): ?>
                                <option value="<?php echo $src['source_key']; ?>"><?php echo $src['icon'] . ' ' . $src['source_name']; ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="OTA">
                            <?php foreach ($otaSrcList as $src): ?>
                                <option value="<?php echo $src['source_key']; ?>"><?php echo $src['icon'] . ' ' . $src['source_name'] . ' (fee ' . $src['fee_percent'] . '%)'; ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php else: ?>
                        <option value="walk_in">Walk-in</option>
                        <option value="phone">Phone</option>
                        <option value="agoda">Agoda</option>
                        <option value="booking">Booking.com</option>
                        <option value="tiket">Tiket.com</option>
                        <option value="ota">OTA Lainnya</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Harga/Malam</label>
                <input type="number" id="editResRoomPrice" onchange="updateEditResInfo()">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Jumlah Tamu</label>
                <input type="number" id="editResNumGuests" min="1" max="10" value="1">
            </div>
            <div class="form-group">
                <label>Diskon</label>
                <div style="display:flex; align-items:center; gap:0;">
                    <button type="button" class="edit-disc-type-btn active" data-type="rp" onclick="setEditDiscType('rp')" style="padding:5px 10px;font-size:0.75rem;border:1px solid #6366f1;background:#6366f1;color:white;border-radius:4px 0 0 4px;cursor:pointer;">Rp</button>
                    <button type="button" class="edit-disc-type-btn" data-type="percent" onclick="setEditDiscType('percent')" style="padding:5px 10px;font-size:0.75rem;border:1px solid #6366f1;background:white;color:#6366f1;border-radius:0 4px 4px 0;cursor:pointer;">%</button>
                    <input type="number" id="editResDiscount" min="0" value="0" onchange="updateEditResInfo()" style="flex:1;margin-left:6px;">
                </div>
                <input type="hidden" id="editResDiscountType" value="rp">
            </div>
        </div>
        <div class="form-group">
            <label>Permintaan Khusus</label>
            <textarea id="editResSpecialRequests"></textarea>
        </div>
        <div id="editResInfo" style="background:rgba(99,102,241,0.06);border-radius:8px;padding:0.6rem;font-size:0.8rem;color:var(--text-secondary);"></div>

        <!-- Group Bookings Section (if multiple rooms) -->
        <div id="editResGroupBookings" style="display:none;margin-top:1rem;padding:0.8rem;background:rgba(59,130,246,0.08);border-radius:8px;border-left:3px solid #3b82f6;">
            <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.6rem;color:var(--text-primary);">📦 Kamar dalam Reservasi Grup:</div>
            <div id="editResGroupList" style="font-size:0.8rem;line-height:1.6;"></div>
        </div>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeEditResModal()">Batal</button>
            <button class="btn-save" onclick="submitEditReservation()">💾 Simpan</button>
        </div>
    </div>
</div>

<script>
    // ===== DRAG & DROP BOOKING BARS =====
    (function() {
        let dragData = null;

        // Setup drag events on booking bars
        document.addEventListener('dragstart', function(e) {
            const container = e.target.closest('.booking-bar-container[draggable="true"]');
            if (!container) return;

            dragData = {
                bookingId: container.dataset.bookingId,
                roomId: container.dataset.roomId,
                checkIn: container.dataset.checkIn,
                checkOut: container.dataset.checkOut,
                nights: parseInt(container.dataset.nights),
                guest: container.dataset.guest
            };

            container.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragData.bookingId);

            // Show drop zones
            document.querySelectorAll('.grid-date-cell').forEach(cell => {
                cell.style.transition = 'background 0.15s ease';
            });
        });

        document.addEventListener('dragend', function(e) {
            const container = e.target.closest('.booking-bar-container');
            if (container) container.classList.remove('dragging');

            // Clean up all drag styling
            document.querySelectorAll('.grid-date-cell').forEach(cell => {
                cell.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
            });
            dragData = null;
        });

        document.addEventListener('dragover', function(e) {
            const cell = e.target.closest('.grid-date-cell');
            if (!cell || !dragData) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            // Highlight drop target
            document.querySelectorAll('.grid-date-cell.drag-over').forEach(c => {
                c.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
            });
            cell.classList.add('drag-over');
        });

        document.addEventListener('dragleave', function(e) {
            const cell = e.target.closest('.grid-date-cell');
            if (cell) {
                cell.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
            }
        });

        document.addEventListener('drop', function(e) {
            const cell = e.target.closest('.grid-date-cell');
            if (!cell || !dragData) return;
            e.preventDefault();

            const newDate = cell.dataset.date;
            const newRoomId = cell.dataset.roomId;

            if (!newDate || !newRoomId) return;

            // Calculate new check-in and check-out
            const newCheckIn = newDate;
            const ciDate = new Date(newCheckIn);
            ciDate.setDate(ciDate.getDate() + dragData.nights);
            const newCheckOut = ciDate.toISOString().split('T')[0];

            // Confirm move
            const confirmMsg = `Pindahkan booking ${dragData.guest}?\n\nDari: ${dragData.checkIn} → ${dragData.checkOut}\nKe: ${newCheckIn} → ${newCheckOut}\nRoom: ${cell.dataset.roomNumber || 'Room ' + newRoomId}`;

            if (!confirm(confirmMsg)) {
                cell.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
                return;
            }

            // API call to move booking
            const formData = new FormData();
            formData.append('booking_id', dragData.bookingId);
            formData.append('new_check_in', newCheckIn);
            formData.append('new_check_out', newCheckOut);
            formData.append('new_room_id', newRoomId);

            fetch('../../api/move-booking.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => {
                    if (!r.ok) {
                        return r.text().then(text => {
                            throw {
                                status: r.status,
                                statusText: r.statusText,
                                body: text
                            };
                        });
                    }
                    return r.json().catch(err => {
                        throw {
                            parseError: true,
                            message: 'Response bukan JSON',
                            body: text
                        };
                    });
                })
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message + '\n\nHarga baru: Rp ' + new Intl.NumberFormat('id-ID').format(data.data.final_price));
                        saveScrollAndReload();
                    } else {
                        alert('❌ ' + data.message);
                    }
                })
                .catch(err => {
                    if (err.status) {
                        console.error('API Error:', err);
                        alert('❌ Error ' + err.status + ':\n' + err.body.substring(0, 300));
                    } else if (err.parseError) {
                        console.error('Parse Error:', err);
                        alert('❌ Respons server tidak valid:\n' + err.body.substring(0, 300));
                    } else {
                        alert('❌ Error: ' + err.message);
                    }
                });

            // Clean up
            cell.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
        });
    })();

    // ===== EXTEND STAY FUNCTIONS =====
    let extendCurrentCO = '';

    window.openExtendModal = function(bookingId, guestName, checkoutDate, nights) {
        document.getElementById('extendBookingId').value = bookingId;
        document.getElementById('extendGuestName').textContent = guestName;
        document.getElementById('extendCurrentCO').textContent = new Date(checkoutDate).toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
        document.getElementById('extendNights').value = 1;
        extendCurrentCO = checkoutDate;
        updateExtendPreview();
        document.getElementById('extendModal').classList.add('active');
    };

    window.closeExtendModal = function() {
        document.getElementById('extendModal').classList.remove('active');
    };

    window.adjustExtendNights = function(delta) {
        const input = document.getElementById('extendNights');
        let val = parseInt(input.value) + delta;
        if (val < 1) val = 1;
        if (val > 30) val = 30;
        input.value = val;
        updateExtendPreview();
    };

    document.addEventListener('change', function(e) {
        if (e.target.id === 'extendNights') updateExtendPreview();
    });

    function updateExtendPreview() {
        const nights = parseInt(document.getElementById('extendNights').value) || 1;
        const co = new Date(extendCurrentCO);
        co.setDate(co.getDate() + nights);
        document.getElementById('extendNewCO').textContent = co.toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    }

    window.submitExtendStay = function() {
        const bookingId = document.getElementById('extendBookingId').value;
        const nights = document.getElementById('extendNights').value;

        if (!bookingId || nights < 1) return;

        const formData = new FormData();
        formData.append('booking_id', bookingId);
        formData.append('extra_nights', nights);

        fetch('../../api/extend-stay.php', {
                method: 'POST',
                body: formData
            })
            .then(r => {
                if (!r.ok) {
                    return r.text().then(text => {
                        throw {
                            status: r.status,
                            statusText: r.statusText,
                            body: text
                        };
                    });
                }
                return r.json().catch(err => {
                    throw {
                        parseError: true,
                        message: 'Response bukan JSON'
                    };
                });
            })
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message + '\n\nTambahan: Rp ' + new Intl.NumberFormat('id-ID').format(data.data.additional_price));
                    closeExtendModal();
                    saveScrollAndReload();
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(err => {
                if (err.status) {
                    console.error('API Error:', err);
                    alert('❌ Error ' + err.status + ':\n' + err.body.substring(0, 300));
                } else if (err.parseError) {
                    console.error('Parse Error:', err);
                    alert('❌ Respons server tidak valid');
                } else {
                    alert('❌ Error: ' + err.message);
                }
            });
    };

    // ===== EDIT RESERVATION FUNCTIONS =====
    window.openEditReservationModal = function(bookingId) {
        // Fetch booking details
        fetch('../../api/get-booking-details.php?id=' + bookingId)
            .then(r => {
                // Check status code
                if (!r.ok) {
                    return r.text().then(text => {
                        throw {
                            status: r.status,
                            statusText: r.statusText,
                            body: text
                        };
                    });
                }
                return r.text();
            })
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('❌ JSON Parse Error:', e);
                    console.error('Raw response:', text);

                    // Try to extract error message from HTML error response
                    let errorMsg = 'Server Error: Respons bukan JSON';
                    if (text.includes('Fatal error') || text.includes('Error')) {
                        const match = text.match(/Fatal error.*?:<\/b>\s*(.+?)(?:<br|<\/|$)/i);
                        if (match) errorMsg = 'Server Error: ' + match[1];
                    }

                    alert('❌ ' + errorMsg + '\n\nSilakan cek console browser untuk detail lengkap.');
                    console.error('Full error response:', text);
                    return;
                }
                if (!data.success) {
                    alert('❌ ' + (data.message || 'Gagal load data'));
                    return;
                }
                const b = data.booking;
                document.getElementById('editResBookingId').value = b.id;
                document.getElementById('editResGuestName').value = b.guest_name || '';
                document.getElementById('editResGuestPhone').value = b.guest_phone || '';
                document.getElementById('editResEmail').value = b.guest_email || '';
                document.getElementById('editResIdNumber').value = b.guest_id_number || '';
                document.getElementById('editResCheckIn').value = b.check_in_date;
                document.getElementById('editResCheckOut').value = b.check_out_date;
                document.getElementById('editResNumGuests').value = b.num_guests || b.adults || 1;
                document.getElementById('editResRoomPrice').value = b.room_price || '';
                document.getElementById('editResSpecialRequests').value = b.special_requests || '';

                // Set booking source
                const srcSelect = document.getElementById('editResSource');
                if (srcSelect && b.booking_source) {
                    srcSelect.value = b.booking_source;
                    // If value didn't match any option, try adding it dynamically
                    if (srcSelect.value !== b.booking_source) {
                        const opt = document.createElement('option');
                        opt.value = b.booking_source;
                        opt.text = (typeof SOURCE_NAMES !== 'undefined' && SOURCE_NAMES[b.booking_source]) ?
                            SOURCE_NAMES[b.booking_source] :
                            b.booking_source.charAt(0).toUpperCase() + b.booking_source.slice(1);
                        srcSelect.appendChild(opt);
                        srcSelect.value = b.booking_source;
                    }
                }

                // Set discount (stored as Rp in DB, reset toggle to Rp)
                setEditDiscType('rp');
                const discInput = document.getElementById('editResDiscount');
                if (discInput) {
                    discInput.value = parseFloat(b.discount) || 0;
                }

                // Display group bookings if multiple rooms
                const groupSection = document.getElementById('editResGroupBookings');
                const groupList = document.getElementById('editResGroupList');
                if (b.group_bookings && b.group_bookings.length > 1) {
                    let html = '';
                    b.group_bookings.forEach(function(gb, idx) {
                        const fmtR = (v) => 'Rp' + new Intl.NumberFormat('id-ID').format(v || 0);
                        html += `<div style="padding:0.5rem;background:var(--card-bg);border-radius:6px;margin-bottom:0.4rem;border-left:2px solid ${gb.id === b.id ? '#10b981' : '#cbd5e1'};">`;
                        html += `<div style="font-weight:600;color:var(--text-primary);">🚪 Kamar ${gb.room_number} (${gb.type_name})`;
                        if (gb.id === b.id) html += ` <span style="color:#10b981;font-size:0.75rem;font-weight:700;">● AKTIF</span>`;
                        html += `</div>`;
                        html += `<div style="color:var(--text-secondary);font-size:0.75rem;margin-top:0.2rem;">`;
                        html += `Harga: ${fmtR(gb.room_price)} | Diskon: ${fmtR(gb.discount)} | Total: ${fmtR(gb.final_price)}`;
                        html += `</div>`;
                        html += `</div>`;
                    });
                    groupList.innerHTML = html;
                    groupSection.style.display = 'block';
                } else {
                    groupSection.style.display = 'none';
                }

                updateEditResInfo();
                document.getElementById('editResModal').classList.add('active');
            })
            .catch(err => {
                if (err.status) {
                    // HTTP Error
                    let errorMsg = '❌ Error ' + err.status + ' (' + err.statusText + '):\n' + err.body.substring(0, 200);
                    console.error('HTTP Error Response:', err);
                    alert(errorMsg);
                } else {
                    // Network error
                    alert('❌ Network Error: ' + err.message);
                }
            });
    };

    window.closeEditResModal = function() {
        document.getElementById('editResModal').classList.remove('active');
    };

    function setEditDiscType(type) {
        const discInput = document.getElementById('editResDiscount');
        const discTypeInput = document.getElementById('editResDiscountType');
        discTypeInput.value = type;
        document.querySelectorAll('.edit-disc-type-btn').forEach(btn => {
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
        if (type === 'percent') {
            discInput.max = 100;
            discInput.placeholder = '0-100';
        } else {
            discInput.removeAttribute('max');
            discInput.placeholder = '0';
        }
        updateEditResInfo();
    }

    function updateEditResInfo() {
        const ci = document.getElementById('editResCheckIn').value;
        const co = document.getElementById('editResCheckOut').value;
        const price = parseFloat(document.getElementById('editResRoomPrice').value) || 0;
        const discVal = parseFloat(document.getElementById('editResDiscount').value) || 0;
        const discType = document.getElementById('editResDiscountType').value;
        const source = document.getElementById('editResSource').value;
        const feePercent = (typeof OTA_FEES !== 'undefined' && OTA_FEES[source]) ? OTA_FEES[source] : 0;

        if (ci && co) {
            const nights = Math.ceil((new Date(co) - new Date(ci)) / 86400000);
            const subtotal = price * nights;
            const discount = discType === 'percent' ? Math.round(subtotal * discVal / 100) : discVal;
            const afterDiscount = subtotal - discount;
            const feeAmount = feePercent > 0 ? Math.round(afterDiscount * feePercent / 100) : 0;
            const total = afterDiscount - feeAmount;

            let html = `<strong>${nights} malam</strong> × Rp ${new Intl.NumberFormat('id-ID').format(price)} = Rp ${new Intl.NumberFormat('id-ID').format(subtotal)}`;
            if (discount > 0) {
                html += `<br>Diskon${discType === 'percent' ? ' (' + discVal + '%)' : ''}: <span style="color:#ef4444;">- Rp ${new Intl.NumberFormat('id-ID').format(discount)}</span>`;
            }
            if (feePercent > 0) {
                html += `<br><span style="color:#92400e;">Fee OTA (${feePercent}%): - Rp ${new Intl.NumberFormat('id-ID').format(feeAmount)}</span>`;
            }
            html += `<br><strong style="color:#10b981;">Total: Rp ${new Intl.NumberFormat('id-ID').format(total)}</strong>`;
            document.getElementById('editResInfo').innerHTML = html;
        }
    }

    // Live update edit form info
    ['editResCheckIn', 'editResCheckOut', 'editResRoomPrice', 'editResDiscount', 'editResSource'].forEach(id => {
        document.addEventListener('input', function(e) {
            if (e.target.id === id) updateEditResInfo();
        });
        document.addEventListener('change', function(e) {
            if (e.target.id === id) updateEditResInfo();
        });
    });

    window.submitEditReservation = function() {
        const bookingId = document.getElementById('editResBookingId').value;
        if (!bookingId) return;

        const sourceVal = document.getElementById('editResSource').value;
        console.log('🔍 SUBMIT DEBUG - booking_source value:', sourceVal);
        console.log('🔍 SUBMIT DEBUG - select selectedIndex:', document.getElementById('editResSource').selectedIndex);
        console.log('🔍 SUBMIT DEBUG - select options:', Array.from(document.getElementById('editResSource').options).map(o => o.value + '=' + o.text));

        const formData = new FormData();
        formData.append('booking_id', bookingId);
        formData.append('guest_name', document.getElementById('editResGuestName').value);
        formData.append('guest_phone', document.getElementById('editResGuestPhone').value);
        formData.append('guest_email', document.getElementById('editResEmail').value);
        formData.append('guest_id_number', document.getElementById('editResIdNumber').value);
        formData.append('check_in_date', document.getElementById('editResCheckIn').value);
        formData.append('check_out_date', document.getElementById('editResCheckOut').value);
        formData.append('num_guests', document.getElementById('editResNumGuests').value);
        formData.append('room_price', document.getElementById('editResRoomPrice').value);
        formData.append('special_requests', document.getElementById('editResSpecialRequests').value);
        formData.append('booking_source', document.getElementById('editResSource').value);
        formData.append('discount_value', document.getElementById('editResDiscount').value);
        formData.append('discount_type', document.getElementById('editResDiscountType').value);

        fetch('../../api/update-reservation.php', {
                method: 'POST',
                body: formData
            })
            .then(r => {
                if (!r.ok) {
                    return r.text().then(text => {
                        throw {
                            status: r.status,
                            statusText: r.statusText,
                            body: text
                        };
                    });
                }
                return r.json().catch(err => {
                    throw {
                        parseError: true,
                        message: 'Response bukan JSON'
                    };
                });
            })
            .then(data => {
                if (data.success) {
                    console.log('✅ Update result:', JSON.stringify(data));
                    let msg = '✅ ' + data.message;
                    if (data.data) {
                        msg += '\nSource DB: ' + (data.data.booking_source || '(kosong)');
                        msg += '\nIntended: ' + (data.data.intended_source || '?');
                    }
                    if (data.debug) {
                        msg += '\n\n--- DEBUG ---';
                        msg += '\nDB: ' + (data.debug.current_db || '?');
                        msg += '\nMain rows: ' + data.debug.main_update_rows;
                        msg += '\nStandalone rows: ' + data.debug.standalone_rows;
                        msg += '\nStandalone err: ' + (data.debug.standalone_error || 'none');
                        msg += '\nPOST val: ' + data.debug.post_booking_source;
                        msg += '\nOriginal: ' + data.debug.original_source;
                        msg += '\nVerified row: ' + JSON.stringify(data.debug.verified_row);
                    }
                    alert(msg);

                    // ✅ FIX: Refresh data booking di side panel
                    const bookingId = document.getElementById('editResBookingId').value;
                    const intendedSource = document.getElementById('editResSource').value;
                    console.log(`🔄 REFRESH: Fetching booking ${bookingId} after edit (source was: ${intendedSource})`);

                    if (bookingId && currentPaymentBooking) {
                        fetch('../../api/get-booking-details.php?id=' + bookingId)
                            .then(r => r.json())
                            .then(result => {
                                if (result.success) {
                                    console.log(`✅ Booking ${bookingId} refreshed successfully:`, result.booking);
                                    console.log(`   booking_source from API: "${result.booking.booking_source}"`);
                                    currentPaymentBooking = result.booking;
                                    showBookingQuickView(result.booking);
                                    console.log(`✅ Side panel updated with refreshed data`);
                                } else {
                                    console.error(`❌ Refresh failed:`, result.message);
                                }
                            })
                            .catch(e => {
                                console.error(`❌ Refresh error:`, e);
                            });
                    } else {
                        console.warn(`⚠️ Refresh skipped: bookingId=${bookingId}, hasCurrentPaymentBooking=${!!currentPaymentBooking}`);
                    }

                    closeEditResModal();
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(err => {
                if (err.status) {
                    console.error('API Error:', err);
                    alert('❌ Error ' + err.status + ':\n' + err.body.substring(0, 300));
                } else if (err.parseError) {
                    console.error('Parse Error:', err);
                    alert('❌ Respons server tidak valid');
                } else {
                    alert('❌ Error: ' + err.message);
                }
            });
    };
</script>

<?php include '../../includes/footer.php'; ?>