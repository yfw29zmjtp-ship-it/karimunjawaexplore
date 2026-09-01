<?php
/**
 * FRONTDESK MOBILE DASHBOARD
 * Mobile-optimized view for owner monitoring
 * Clean, Compact, Modern - Light Theme
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/business_helper.php';

// Auth check
$role = $_SESSION['role'] ?? null;
if (!$role && isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    try {
        $authDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
        $authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $roleStmt = $authDb->prepare("SELECT r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $roleStmt->execute([$_SESSION['user_id'] ?? 0]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($roleRow) {
            $role = $roleRow['role_code'];
            $_SESSION['role'] = $role;
        }
    } catch (Exception $e) {}
}

if (!$role || !in_array($role, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ../../login.php');
    exit;
}

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
$basePath = $isProduction ? '' : '/adf_system';

// Multi-business: get active business config
require_once __DIR__ . '/../../includes/business_access.php';
$allBusinesses = getUserAvailableBusinesses();
$activeBusinessId = getActiveBusinessId();

// Auto-switch if current business not in user's allowed list
if (!empty($allBusinesses) && !isset($allBusinesses[$activeBusinessId])) {
    $firstAllowed = array_key_first($allBusinesses);
    setActiveBusinessId($firstAllowed);
    $activeBusinessId = $firstAllowed;
}

$activeConfig = getActiveBusinessConfig();

// Database config - connect to BUSINESS database
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$businessDbName = getDbName($activeConfig['database'] ?? 'adf_narayana_hotel');
$businessName = $activeConfig['name'] ?? 'Unknown Business';
$businessIcon = $activeConfig['theme']['icon'] ?? '🏢';
$enabledModules = $activeConfig['enabled_modules'] ?? [];
$hasLogo = !empty($activeConfig['logo']);
$logoFile = $activeBusinessId . '_logo.png';

// Get today's date
$today = date('Y-m-d');
$thisMonth = date('Y-m');

// Initialize default values
$stats = [
    'checkins' => 0,
    'checkouts' => 0,
    'available' => 0,
    'occupied' => 0,
    'total_rooms' => 0,
    'occupancy' => 0,
    'today_revenue' => 0,
    'inhouse_revenue' => 0,
    'month_revenue' => 0
];
$inHouseGuests = [];
$todayArrivals = [];
$todayDepartures = [];
$roomStatusMap = [
    'available' => 0,
    'occupied' => 0,
    'maintenance' => 0,
    'cleaning' => 0
];
$error = null;

try {
    // Connect to business database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$businessDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if rooms table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'rooms'");
    $hasTables = $tableCheck->rowCount() > 0;
    
    if ($hasTables) {
        // ==========================================
        // AUTO-CHECKOUT OVERDUE BOOKINGS  
        // Sync: bookings with check_out_date < today still 'checked_in' → auto checkout
        // Same logic as frontdesk/dashboard.php to keep status in sync
        // ==========================================
        try {
            $overdueStmt = $pdo->prepare("
                SELECT b.id, b.room_id FROM bookings b
                WHERE b.status = 'checked_in' AND DATE(b.check_out_date) < ?
            ");
            $overdueStmt->execute([$today]);
            $overdueBookings = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($overdueBookings)) {
                foreach ($overdueBookings as $overdue) {
                    $pdo->prepare("UPDATE bookings SET status = 'checked_out', actual_checkout_time = check_out_date, updated_at = NOW() WHERE id = ?")->execute([$overdue['id']]);
                    $pdo->prepare("UPDATE rooms SET status = 'available', current_guest_id = NULL, updated_at = NOW() WHERE id = ? AND status = 'occupied'")->execute([$overdue['room_id']]);
                }
                error_log("Owner monitor auto-checkout: " . count($overdueBookings) . " overdue bookings");
            }
        } catch (Exception $e) {
            error_log("Auto-checkout error: " . $e->getMessage());
        }

        // ==========================================
        // SYNC ROOM STATUS WITH BOOKINGS (source of truth)
        // Fix stale rooms.status: if room has no checked_in booking, mark available
        // If room has checked_in booking, mark occupied
        // ==========================================
        try {
            // Rooms marked 'occupied' but have NO active checked_in booking → set available
            $pdo->exec("
                UPDATE rooms r 
                SET r.status = 'available', r.current_guest_id = NULL, r.updated_at = NOW()
                WHERE r.status = 'occupied'
                AND NOT EXISTS (
                    SELECT 1 FROM bookings b 
                    WHERE b.room_id = r.id AND b.status = 'checked_in'
                )
            ");
            // Rooms NOT marked 'occupied' but HAVE active checked_in booking → set occupied
            $pdo->exec("
                UPDATE rooms r 
                SET r.status = 'occupied', r.updated_at = NOW()
                WHERE r.status != 'occupied'
                AND r.status NOT IN ('maintenance', 'cleaning', 'blocked')
                AND EXISTS (
                    SELECT 1 FROM bookings b 
                    WHERE b.room_id = r.id AND b.status = 'checked_in'
                )
            ");
        } catch (Exception $e) {
            error_log("Room sync error: " . $e->getMessage());
        }

        // Today's check-ins
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM bookings 
            WHERE DATE(check_in_date) = ? 
            AND status IN ('confirmed', 'checked_in')
        ");
        $stmt->execute([$today]);
        $stats['checkins'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Today's check-outs
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM bookings 
            WHERE DATE(check_out_date) = ? 
            AND status = 'checked_in'
        ");
        $stmt->execute([$today]);
        $stats['checkouts'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Room counts - use same logic as frontdesk dashboard
        $stmt = $pdo->query("SELECT COUNT(*) FROM rooms");
        $stats['total_rooms'] = (int)$stmt->fetchColumn();
        
        // Current occupancy - count from bookings where status = 'checked_in' (same as frontdesk)
        $stmt = $pdo->query("SELECT COUNT(DISTINCT room_id) FROM bookings WHERE status = 'checked_in'");
        $stats['occupied'] = (int)$stmt->fetchColumn();
        
        // Available & occupancy - will be recalculated below after room status map is loaded
        $stats['available'] = $stats['total_rooms'] - $stats['occupied'];
        $stats['occupancy'] = $stats['total_rooms'] > 0 ? round(($stats['occupied'] / $stats['total_rooms']) * 100) : 0;

        // Revenue from cash_book (source of truth - matches Buku Kas Besar in system)
        // Exclude owner capital/modal entries for accurate business revenue
        $hasCashBook = false;
        try {
            $cbCheck = $pdo->query("SHOW TABLES LIKE 'cash_book'");
            $hasCashBook = $cbCheck->rowCount() > 0;
        } catch (Exception $e) {}

        if ($hasCashBook) {
            // Build exclusion clause for modal/capital entries
            $revenueExclude = "";
            try {
                // Check if cash_account_id column exists
                $colCheck = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
                if ($colCheck && $colCheck->rowCount() > 0) {
                    // Get master DB connection for cash_accounts
                    $masterPdoRev = new PDO(
                        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    // Get business ID from master
                    $bizStmt = $masterPdoRev->prepare("SELECT id FROM businesses WHERE database_name = ? LIMIT 1");
                    $bizStmt->execute([$activeConfig['database'] ?? '']);
                    $bizRow = $bizStmt->fetch(PDO::FETCH_ASSOC);
                    $numBizId = $bizRow ? (int)$bizRow['id'] : null;
                    
                    if ($numBizId) {
                        $capStmt = $masterPdoRev->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
                        $capStmt->execute([$numBizId]);
                        $capitalAccIds = $capStmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($capitalAccIds)) {
                            $revenueExclude .= " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', array_map('intval', $capitalAccIds)) . "))";
                        }
                    }
                }
                
                // Exclude modal/transfer categories
                $catStmt = $pdo->query("SELECT id FROM categories WHERE LOWER(category_name) LIKE '%modal%' OR LOWER(category_name) LIKE '%transfer%dana%' OR LOWER(category_name) LIKE '%capital%'");
                $excludeCats = $catStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($excludeCats)) {
                    $revenueExclude .= " AND (category_id IS NULL OR category_id NOT IN (" . implode(',', array_map('intval', $excludeCats)) . "))";
                }
                
                // Exclude by description patterns
                $revenueExclude .= " AND (LOWER(description) NOT LIKE '%modal operasional%' AND LOWER(description) NOT LIKE '%transfer dana%' AND LOWER(description) NOT LIKE '%setoran modal%')";
            } catch (Exception $e) {
                // If exclusion logic fails, continue without exclusions
            }

            // Today's revenue from cash_book
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'income'" . $revenueExclude);
                $stmt->execute([$today]);
                $stats['today_revenue'] = (float)$stmt->fetchColumn();
            } catch (Exception $e) {
                $stats['today_revenue'] = 0;
            }

            // This month's revenue from cash_book
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'" . $revenueExclude);
                $stmt->execute([$thisMonth]);
                $stats['month_revenue'] = (float)$stmt->fetchColumn();
            } catch (Exception $e) {
                $stats['month_revenue'] = 0;
            }
        }

        // In-House Revenue (total from currently checked-in guests)
        try {
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(bp.amount), 0) as total
                FROM booking_payments bp
                JOIN bookings b ON bp.booking_id = b.id
                WHERE b.status = 'checked_in'
            ");
            $stats['inhouse_revenue'] = (float)$stmt->fetchColumn();
            
            // Fallback: If booking_payments empty, use bookings.paid_amount
            if ($stats['inhouse_revenue'] == 0) {
                $stmt = $pdo->query("
                    SELECT COALESCE(SUM(paid_amount), 0) as total
                    FROM bookings
                    WHERE status = 'checked_in'
                ");
                $stats['inhouse_revenue'] = (float)$stmt->fetchColumn();
            }
        } catch (Exception $e) {
            $stats['inhouse_revenue'] = 0;
        }

        // In-house guests list
        $stmt = $pdo->query("
            SELECT b.id, g.guest_name, g.phone as guest_phone, b.check_in_date, b.check_out_date, 
                   r.room_number, rt.type_name as room_type, b.final_price as total_amount
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            WHERE b.status = 'checked_in'
            ORDER BY b.check_out_date ASC
        ");
        $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Today's arrivals
        $stmt = $pdo->prepare("
            SELECT b.id, g.guest_name, b.check_in_date, b.check_out_date, 
                   r.room_number, rt.type_name as room_type, b.status
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            WHERE DATE(b.check_in_date) = ? AND b.status IN ('confirmed', 'checked_in')
            ORDER BY b.check_in_date ASC
            LIMIT 5
        ");
        $stmt->execute([$today]);
        $todayArrivals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Today's departures
        $stmt = $pdo->prepare("
            SELECT b.id, g.guest_name, b.check_out_date, 
                   r.room_number, rt.type_name as room_type, b.status
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
            WHERE DATE(b.check_out_date) = ? AND b.status = 'checked_in'
            ORDER BY b.check_out_date ASC
            LIMIT 5
        ");
        $stmt->execute([$today]);
        $todayDepartures = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Room status breakdown from rooms table
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM rooms GROUP BY status");
        $roomStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roomStatus as $rs) {
            $roomStatusMap[$rs['status']] = (int)$rs['count'];
        }

        // Fix: Sync room counts - occupied from bookings overrides rooms.status
        // rooms.status may be stale, bookings is source of truth
        $maint = ($roomStatusMap['maintenance'] ?? 0) + ($roomStatusMap['cleaning'] ?? 0) + ($roomStatusMap['blocked'] ?? 0);
        $stats['available'] = max(0, $stats['total_rooms'] - $stats['occupied'] - $maint);
        $stats['occupancy'] = $stats['total_rooms'] > 0 ? round(($stats['occupied'] / $stats['total_rooms']) * 100) : 0;

        // ── Calendar Timeline Data (CloudBeds style) ──
        $calRooms = [];
        $calBookings = [];
        $calRoomsByType = [];
        $calDates = [];
        $hasCalendar = false;
        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'rooms'");
            if ($tableCheck && $tableCheck->rowCount() > 0) {
                $hasCalendar = true;

                // Date range: 7 days before today, 60 days after
                $calStartDate = date('Y-m-d', strtotime('-7 days'));
                $calEndDate = date('Y-m-d', strtotime('+60 days'));
                $dt = new DateTime($calStartDate);
                $end = new DateTime($calEndDate);
                while ($dt <= $end) {
                    $calDates[] = $dt->format('Y-m-d');
                    $dt->modify('+1 day');
                }

                // Fetch rooms with corrected type ordering
                  $stmtR = $pdo->query("
                      SELECT r.id, r.room_number, r.floor_number, r.status,
                          rt.type_name, rt.base_price,
                          CASE WHEN b.id IS NOT NULL THEN 1 ELSE 0 END as has_checkin,
                          g.guest_name
                      FROM rooms r
                      LEFT JOIN room_types rt ON r.room_type_id = rt.id
                      LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'checked_in'
                      LEFT JOIN guests g ON b.guest_id = g.id
                      ORDER BY FIELD(rt.type_name, 'Queen Chambers','Queen','Twin Chambers','Twin',
                            'King Quarters','King','Deluxe Queen','Deluxe King'),
                         rt.type_name ASC, r.floor_number ASC, r.room_number ASC
                  ");
                $calRooms = $stmtR->fetchAll(PDO::FETCH_ASSOC);

                // Group by type
                foreach ($calRooms as $room) {
                    $calRoomsByType[$room['type_name']][] = $room;
                }

                // Fetch bookings in range
                $stmtB = $pdo->prepare("
                    SELECT b.id, b.booking_code, b.room_id, b.check_in_date, b.check_out_date,
                           b.status, b.booking_source,
                           g.guest_name
                    FROM bookings b
                    LEFT JOIN guests g ON b.guest_id = g.id
                    WHERE b.check_in_date < ? AND b.check_out_date > ?
                      AND b.status IN ('pending','confirmed','checked_in','checked_out')
                    ORDER BY b.check_in_date ASC
                ");
                $stmtB->execute([$calEndDate, $calStartDate]);
                $calBookings = $stmtB->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            $hasCalendar = false;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Format currency
function rp($num) {
    if ($num >= 1000000) {
        return 'Rp ' . number_format($num / 1000000, 1, ',', '.') . 'M';
    } else {
        return 'Rp ' . number_format($num, 0, ',', '.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="60">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Frontdesk Monitor - Owner</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 80px;
        }
        
        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 16px;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 16px;
            border-radius: 20px;
            margin-bottom: 16px;
            text-align: center;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3);
        }
        
        .header-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .header-logo {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            margin: 0 auto 10px;
            background: white;
            padding: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .header-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .header-subtitle {
            font-size: 12px;
            opacity: 0.9;
        }
        
        .header-date {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 8px;
        }
        
        /* Occupancy Section with Pie Chart - 2028 Digital Luxury */
        .occupancy-section {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 20px;
            padding: 18px;
            margin-bottom: 16px;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.08), 0 1px 3px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            gap: 18px;
            border: 1px solid rgba(99, 102, 241, 0.08);
        }
        
        .pie-chart-container {
            position: relative;
            width: 110px;
            height: 110px;
            flex-shrink: 0;
            filter: drop-shadow(0 4px 12px rgba(99, 102, 241, 0.2));
        }
        
        .pie-chart-container canvas {
            width: 110px;
            height: 110px;
        }
        
        .pie-center-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            line-height: 1.1;
        }
        
        .pie-percent {
            font-size: 18px;
            font-weight: 800;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }
        
        .pie-label {
            font-size: 7px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .occupancy-details {
            flex: 1;
        }
        
        .occupancy-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 10px;
        }
        
        .occ-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            padding: 4px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .occ-row:last-child { border-bottom: none; }
        
        .occ-label { color: var(--text-muted); }
        .occ-value { font-weight: 600; color: var(--text); }
        .occ-value.success { color: var(--success); }
        .occ-value.danger { color: var(--danger); }
        .occ-value.warning { color: var(--warning); }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .stat-card {
            background: var(--card);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent-color);
        }
        
        .stat-card.checkin { --accent-color: var(--success); }
        .stat-card.checkout { --accent-color: var(--warning); }
        .stat-card.available { --accent-color: var(--info); }
        .stat-card.occupied { --accent-color: var(--danger); }
        
        .stat-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: var(--text);
        }
        
        .stat-hint {
            font-size: 9px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        /* Revenue Section */
        .revenue-section {
            background: var(--card);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .revenue-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .revenue-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
        
        .revenue-item {
            text-align: center;
            padding: 10px 8px;
            background: var(--bg);
            border-radius: 12px;
        }
        
        .revenue-label {
            font-size: 9px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        
        .revenue-value {
            font-size: 14px;
            font-weight: 700;
            color: var(--success);
        }
        
        /* Section Title */
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title .badge {
            background: var(--primary);
            color: white;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
        }
        
        /* Guest List */
        .guest-list {
            background: var(--card);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        .guest-item {
            display: flex;
            align-items: center;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
        }
        
        .guest-item:last-child { border-bottom: none; }
        
        .guest-room {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 13px;
            margin-right: 12px;
        }
        
        .guest-info { flex: 1; }
        
        .guest-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 2px;
        }
        
        .guest-detail {
            font-size: 10px;
            color: var(--text-muted);
        }
        
        .guest-checkout {
            text-align: right;
        }
        
        .checkout-date {
            font-size: 11px;
            color: var(--text-muted);
        }
        
        .checkout-label {
            font-size: 9px;
            color: var(--warning);
            font-weight: 600;
        }
        
        /* ── Room Status Grid ──────────────────────────── */
        .room-status-wrap { background: var(--card); border-radius: 14px; padding: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 14px; }
        .room-status-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 6px; }
        .room-status-label { font-size: 12px; font-weight: 700; color: var(--text); }
        .room-status-summary { display: flex; gap: 6px; flex-wrap: wrap; }
        .room-status-pill { display: flex; align-items: center; gap: 4px; font-size: 9px; font-weight: 700; padding: 3px 8px; border-radius: 10px; }
        .rsp-available { background: #dcfce7; color: #15803d; }
        .rsp-occupied { background: #fee2e2; color: #dc2626; }
        .rsp-maintenance { background: #f3f4f6; color: #6b7280; }
        .room-grid-owner { display: grid; grid-template-columns: repeat(auto-fill, minmax(66px, 1fr)); gap: 6px; }
        .room-box-owner { border-radius: 10px; padding: 8px 6px; text-align: center; border: 1.5px solid var(--border); position: relative; transition: all 0.2s; }
        .room-box-owner.rb-avail { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-color: #86efac; }
        .room-box-owner.rb-occ { background: linear-gradient(135deg, #fef2f2, #fee2e2); border-color: #fca5a5; }
        .room-box-owner.rb-maint { background: #f9fafb; border-color: #d1d5db; opacity: 0.6; }
        .rb-number { font-size: 15px; font-weight: 900; color: #1e293b; line-height: 1; }
        .rb-type { font-size: 7px; font-weight: 700; text-transform: uppercase; color: #6366f1; letter-spacing: 0.5px; margin-top: 2px; }
        .rb-guest { font-size: 7px; font-weight: 600; color: #dc2626; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 58px; margin-inline: auto; }
        .rb-status-dot { width: 6px; height: 6px; border-radius: 50%; position: absolute; top: 4px; right: 4px; }
        .rb-dot-avail { background: #22c55e; }
        .rb-dot-occ { background: #ef4444; }

        /* ── Calendar Timeline (CloudBeds - PHP rendered) ── */
        .ocal-section {
            margin: 0 0 14px;
            background: #ffffff;
            border-radius: 18px;
            padding: 16px 14px;
            border: 1px solid rgba(0,0,0,0.06);
            box-shadow: 0 4px 24px rgba(0,0,0,0.04);
        }
        .ocal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .ocal-title {
            font-size: 14px;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ocal-nav {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ocal-nav-btn {
            width: 28px; height: 28px;
            border-radius: 8px;
            background: #f1f5f9;
            border: 1px solid rgba(0,0,0,0.06);
            color: #475569;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700;
            cursor: pointer; transition: all 0.2s;
        }
        .ocal-nav-btn:active { background: #e2e8f0; transform: scale(0.92); }
        .ocal-nav-btn.today-btn {
            padding: 0 10px; width: auto;
            font-size: 10px; font-weight: 800;
            color: #6366f1; background: rgba(99,102,241,0.06);
            border-color: rgba(99,102,241,0.15);
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .ocal-scroll-wrapper {
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            cursor: grab;
            user-select: none;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        .ocal-scroll-wrapper::-webkit-scrollbar { height: 4px; }
        .ocal-scroll-wrapper::-webkit-scrollbar-track { background: transparent; }
        .ocal-scroll-wrapper::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .ocal-grid-wrapper {
            display: inline-block;
            min-width: 100%;
            width: fit-content;
        }
        .ocal-grid {
            display: grid;
            gap: 0;
            width: fit-content;
            min-width: fit-content;
        }
        .ocal-month-row { display: contents; }
        .ocal-month-room {
            background: #f8fafc;
            border-right: 2px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            position: sticky; left: 0; z-index: 41;
            min-width: 90px; max-width: 90px;
        }
        .ocal-month-label {
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            font-size: 0.72rem;
            letter-spacing: 1px;
            padding: 0.2rem 0;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            min-height: 22px;
        }
        .ocal-month-label span {
            position: sticky;
            left: 97px;
            z-index: 2;
            background: #f8fafc;
            padding: 0 0.4rem;
        }
        .ocal-header-row { display: contents; }
        .ocal-header-room {
            background: linear-gradient(135deg, #f1f5f9 0%, #ffffff 100%);
            border-right: 2px solid #e2e8f0;
            border-bottom: 2px solid #cbd5e1;
            padding: 0.2rem 0.3rem;
            font-weight: 800;
            text-align: center;
            position: sticky; left: 0; z-index: 40;
            font-size: 0.7rem;
            color: #475569;
            letter-spacing: 1px;
            text-transform: uppercase;
            display: flex; align-items: center; justify-content: center;
            min-height: 40px;
            min-width: 90px; max-width: 90px;
        }
        .ocal-header-date {
            background: linear-gradient(180deg, #f8fafc, #f1f5f9);
            border-right: 1px solid #e2e8f0;
            border-bottom: 2px solid #cbd5e1;
            padding: 0.15rem 0.1rem;
            text-align: center;
            font-weight: 700;
            font-size: 0.65rem;
            color: #334155;
            min-height: 40px;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 1px;
        }
        .ocal-header-date.today {
            background: rgba(99,102,241,0.08) !important;
        }
        .ocal-header-date-day {
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #334155;
        }
        .ocal-header-date-num {
            font-size: 0.82rem;
            font-weight: 900;
            color: #1e293b;
        }
        .ocal-header-date.today .ocal-header-date-num {
            color: #6366f1;
        }
        .ocal-type-header {
            background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
            border-right: 2px solid #a5b4fc;
            border-bottom: 1px solid #c7d2fe;
            padding: 0.1rem 0.3rem;
            font-weight: 800;
            color: #4338ca;
            position: sticky; left: 0; z-index: 30;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.68rem;
            min-width: 90px; max-width: 90px;
            min-height: 22px;
        }
        .ocal-type-cell {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            border-right: 1px solid #c7d2fe;
            border-bottom: 1px solid #a5b4fc;
            min-height: 22px;
        }
        .ocal-room-label {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border-right: 2px solid #e2e8f0;
            border-bottom: 1px solid #f1f5f9;
            padding: 0.15rem 0.3rem;
            font-weight: 700;
            color: #334155;
            position: sticky; left: 0; z-index: 30;
            display: flex; flex-direction: column;
            justify-content: center; align-items: center;
            text-align: center; gap: 0;
            min-width: 90px; max-width: 90px;
            font-size: 0.75rem;
            min-height: 26px;
            box-shadow: 2px 0 6px rgba(0,0,0,0.03);
        }
        .ocal-room-type-label {
            font-size: 0.55rem;
            font-weight: 600;
            color: #6366f1;
            text-transform: uppercase;
        }
        .ocal-room-number {
            font-size: 0.78rem;
            font-weight: 900;
            color: #1e293b;
        }
        .ocal-date-cell {
            border-right: 0.5px solid #e2e8f0;
            border-bottom: 0.5px solid #e2e8f0;
            min-height: 26px;
            position: relative;
            background: transparent;
        }
        .ocal-date-cell.today {
            background: rgba(99,102,241,0.04) !important;
        }
        .ocal-bar-container {
            position: absolute;
            top: 2px;
            left: 1px;
            height: 22px;
            display: flex;
            align-items: center;
            overflow: visible;
            pointer-events: auto;
            z-index: 10;
        }
        .ocal-bar {
            width: 100%;
            height: 20px;
            padding: 0 0.3rem;
            overflow: visible;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
            font-weight: 700;
            font-size: 0.62rem;
            position: relative;
            border-radius: 3px;
            white-space: nowrap;
            transform: skewX(-20deg);
            color: #fff !important;
        }
        .ocal-bar > span {
            transform: skewX(20deg);
            color: #fff !important;
            text-shadow: 0 1px 2px rgba(0,0,0,0.4);
            font-weight: 800;
            font-size: 0.6rem;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ocal-bar::before {
            content: '';
            position: absolute; left: -5px; top: 50%;
            transform: translateY(-50%);
            width: 0; height: 0;
            border-top: 7px solid transparent;
            border-bottom: 7px solid transparent;
            border-right: 4px solid;
            border-right-color: inherit;
        }
        .ocal-bar::after {
            content: '';
            position: absolute; right: -5px; top: 50%;
            transform: translateY(-50%);
            width: 0; height: 0;
            border-top: 7px solid transparent;
            border-bottom: 7px solid transparent;
            border-left: 4px solid;
            border-left-color: inherit;
        }
        .ocal-bar.status-confirmed {
            background: linear-gradient(135deg, #06b6d4, #22d3ee) !important;
        }
        .ocal-bar.status-pending {
            background: linear-gradient(135deg, #0ea5e9, #38bdf8) !important;
        }
        .ocal-bar.status-checked_in {
            background: linear-gradient(135deg, #10b981, #34d399) !important;
        }
        .ocal-bar.status-checked_out {
            background: linear-gradient(135deg, #9ca3af, #d1d5db) !important;
            opacity: 0.5;
        }
        .ocal-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #f1f5f9;
        }
        .ocal-legend-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 9px;
            font-weight: 600;
            color: #64748b;
        }
        .ocal-legend-dot {
            width: 8px; height: 8px;
            border-radius: 2px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: var(--text-muted);
        }
        
        .empty-icon {
            font-size: 40px;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        .empty-text {
            font-size: 13px;
        }
        
        /* Error State */
        .error-card {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            text-align: center;
        }
        
        .error-title {
            color: var(--danger);
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .error-text {
            font-size: 12px;
            color: #991b1b;
        }
        
        /* Footer Nav */
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 10px 0 14px;
            border-top: 1px solid var(--border);
            box-shadow: 0 -4px 16px rgba(0,0,0,0.06);
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            font-size: 10px;
            color: var(--text-muted);
            transition: color 0.2s;
            padding: 4px 12px;
        }
        
        .nav-item.active { color: var(--primary); }
        
        .nav-icon {
            font-size: 20px;
            margin-bottom: 2px;
        }

        /* Arrival/Departure cards */
        .movement-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .movement-card {
            background: var(--card);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }

        .movement-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }

        .movement-icon {
            font-size: 16px;
        }

        .movement-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .movement-list {
            font-size: 11px;
        }

        .movement-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid var(--border);
        }

        .movement-item:last-child { border-bottom: none; }

        .movement-name {
            color: var(--text);
            font-weight: 500;
        }

        .movement-room {
            color: var(--primary);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-logo">
                <img src="<?= $basePath ?>/uploads/logos/<?= htmlspecialchars($logoFile) ?>" alt="Logo" onerror="this.parentElement.style.display='none'">
            </div>
            <div class="header-title">Frontdesk Monitor</div>
            <div class="header-subtitle"><?= htmlspecialchars($businessName) ?></div>
            <div class="header-date"><?= date('l, d F Y') ?></div>
        </div>
        
        <?php if ($error): ?>
        <div class="error-card">
            <div class="error-title">⚠️ Connection Error</div>
            <div class="error-text"><?= htmlspecialchars($error) ?></div>
        </div>
        <?php else: ?>
        
        <!-- Occupancy with Pie Chart - 2028 Digital Luxury -->
        <div class="occupancy-section">
            <div class="pie-chart-container">
                <canvas id="occupancyPie" width="110" height="110"></canvas>
                <div class="pie-center-text">
                    <div class="pie-percent"><?= $stats['occupancy'] ?>%</div>
                    <div class="pie-label">Occ</div>
                </div>
            </div>
            <div class="occupancy-details">
                <div class="occupancy-title">Room Status</div>
                <div class="occ-row">
                    <span class="occ-label">Available</span>
                    <span class="occ-value success"><?= $stats['available'] ?> rooms</span>
                </div>
                <div class="occ-row">
                    <span class="occ-label">Occupied</span>
                    <span class="occ-value danger"><?= $stats['occupied'] ?> rooms</span>
                </div>
                <?php $maintTotal = ($roomStatusMap['maintenance'] ?? 0) + ($roomStatusMap['cleaning'] ?? 0); if ($maintTotal > 0): ?>
                <div class="occ-row">
                    <span class="occ-label">Maintenance</span>
                    <span class="occ-value warning"><?= $maintTotal ?> rooms</span>
                </div>
                <?php endif; ?>
                <div class="occ-row">
                    <span class="occ-label">Total</span>
                    <span class="occ-value"><?= $stats['total_rooms'] ?> rooms</span>
                </div>
            </div>
        </div>

        <!-- Room Status Grid (below pie chart) -->
        <?php if (!empty($calRooms)): ?>
        <div class="room-status-wrap">
            <div class="room-status-header">
                <div class="room-status-label">🏨 Status Kamar</div>
                <div class="room-status-summary">
                    <span class="room-status-pill rsp-available">✓ <?= $stats['available'] ?> Available</span>
                    <span class="room-status-pill rsp-occupied">● <?= $stats['occupied'] ?> Occupied</span>
                    <?php $maintCount = ($roomStatusMap['maintenance'] ?? 0) + ($roomStatusMap['cleaning'] ?? 0); if ($maintCount > 0): ?>
                    <span class="room-status-pill rsp-maintenance">🔧 <?= $maintCount ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="room-grid-owner">
                <?php foreach ($calRooms as $cr):
                    // Use bookings as source of truth for occupancy (not rooms.status which can be stale)
                    $isOcc = ((int)($cr['has_checkin'] ?? 0) > 0) || !empty($cr['guest_name']);
                    $isMaint = in_array($cr['status'], ['maintenance', 'cleaning', 'blocked']);
                    $cls = $isOcc ? 'rb-occ' : ($isMaint ? 'rb-maint' : 'rb-avail');
                    $dotCls = $isOcc ? 'rb-dot-occ' : 'rb-dot-avail';
                ?>
                <div class="room-box-owner <?= $cls ?>">
                    <div class="rb-status-dot <?= $dotCls ?>"></div>
                    <div class="rb-number"><?= htmlspecialchars($cr['room_number']) ?></div>
                    <div class="rb-type"><?= htmlspecialchars(substr($cr['room_type'], 0, 8)) ?></div>
                    <?php if ($isOcc && !empty($cr['guest_name'])): ?>
                    <div class="rb-guest"><?= htmlspecialchars(substr($cr['guest_name'], 0, 12)) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card checkin">
                <div class="stat-label">Check-In Today</div>
                <div class="stat-value"><?= $stats['checkins'] ?></div>
                <div class="stat-hint">Expected arrivals</div>
            </div>
            <div class="stat-card checkout">
                <div class="stat-label">Check-Out Today</div>
                <div class="stat-value"><?= $stats['checkouts'] ?></div>
                <div class="stat-hint">Expected departures</div>
            </div>
        </div>
        
        <!-- Revenue Section -->
        <div class="revenue-section">
            <div class="revenue-title">💰 Revenue</div>
            <div class="revenue-grid">
                <div class="revenue-item">
                    <div class="revenue-label">Today</div>
                    <div class="revenue-value"><?= rp($stats['today_revenue']) ?></div>
                </div>
                <div class="revenue-item">
                    <div class="revenue-label">In-House</div>
                    <div class="revenue-value" style="color: #10b981;"><?= rp($stats['inhouse_revenue']) ?></div>
                </div>
                <div class="revenue-item">
                    <div class="revenue-label">This Month</div>
                    <div class="revenue-value"><?= rp($stats['month_revenue']) ?></div>
                </div>
            </div>
        </div>

        <!-- Arrivals & Departures -->
        <div class="movement-grid">
            <div class="movement-card">
                <div class="movement-header">
                    <span class="movement-icon">🛬</span>
                    <span class="movement-title">Arrivals</span>
                </div>
                <div class="movement-list">
                    <?php if (empty($todayArrivals)): ?>
                        <div style="color: var(--text-muted); font-size: 11px;">No arrivals today</div>
                    <?php else: ?>
                        <?php foreach ($todayArrivals as $arr): ?>
                        <div class="movement-item">
                            <span class="movement-name"><?= htmlspecialchars(substr($arr['guest_name'] ?? '-', 0, 12)) ?></span>
                            <span class="movement-room"><?= htmlspecialchars($arr['room_number'] ?? '-') ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="movement-card">
                <div class="movement-header">
                    <span class="movement-icon">🛫</span>
                    <span class="movement-title">Departures</span>
                </div>
                <div class="movement-list">
                    <?php if (empty($todayDepartures)): ?>
                        <div style="color: var(--text-muted); font-size: 11px;">No departures today</div>
                    <?php else: ?>
                        <?php foreach ($todayDepartures as $dep): ?>
                        <div class="movement-item">
                            <span class="movement-name"><?= htmlspecialchars(substr($dep['guest_name'] ?? '-', 0, 12)) ?></span>
                            <span class="movement-room"><?= htmlspecialchars($dep['room_number'] ?? '-') ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Booking Calendar Timeline (PHP Server Rendered) -->
        <?php if ($hasCalendar && !empty($calRooms)): ?>
        <div class="section-title">
            📅 Booking Calendar
            <span class="badge"><?= count($calBookings) ?></span>
        </div>
        <div class="ocal-section">
            <div class="ocal-header">
                <div class="ocal-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Booking Calendar
                </div>
                <div class="ocal-nav">
                    <button class="ocal-nav-btn today-btn" onclick="ocalGoToday()">Today</button>
                </div>
            </div>
            <?php
            $colW = 90;
            $todayStr = date('Y-m-d');
            $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            $totalCols = count($calDates);

            // Build month spans
            $monthSpans = [];
            foreach ($calDates as $d) {
                $mk = date('M Y', strtotime($d));
                if (!isset($monthSpans[$mk])) $monthSpans[$mk] = 0;
                $monthSpans[$mk]++;
            }

            // Index bookings by room_id
            $bookingsByRoom = [];
            foreach ($calBookings as $bk) {
                $bookingsByRoom[$bk['room_id']][] = $bk;
            }
            ?>
            <div class="ocal-scroll-wrapper" id="ocalScroller">
                <div class="ocal-grid-wrapper">
                    <div class="ocal-grid" style="grid-template-columns: 90px repeat(<?= $totalCols ?>, <?= $colW ?>px);">
                        <!-- Month Row -->
                        <div class="ocal-month-row">
                            <div class="ocal-month-room"></div>
                            <?php foreach ($monthSpans as $mLabel => $mSpan): ?>
                            <div class="ocal-month-label" style="grid-column: span <?= $mSpan ?>;">
                                <span><?= strtoupper($mLabel) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- Date Header -->
                        <div class="ocal-header-row">
                            <div class="ocal-header-room">Rooms</div>
                            <?php foreach ($calDates as $d):
                                $dow = date('w', strtotime($d));
                                $isToday = ($d === $todayStr);
                            ?>
                            <div class="ocal-header-date<?= $isToday ? ' today' : '' ?>" data-date="<?= $d ?>">
                                <span class="ocal-header-date-day"><?= $dayNames[$dow] ?></span>
                                <span class="ocal-header-date-num"><?= date('j', strtotime($d)) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- Room Rows -->
                        <?php foreach ($calRoomsByType as $typeName => $typeRooms): ?>
                        <!-- Type Header -->
                        <div class="ocal-type-header"><?= htmlspecialchars($typeName) ?></div>
                        <?php for ($i = 0; $i < $totalCols; $i++): ?>
                        <div class="ocal-type-cell"></div>
                        <?php endfor; ?>

                        <?php foreach ($typeRooms as $room):
                            $roomBookings = $bookingsByRoom[$room['id']] ?? [];
                        ?>
                        <div class="ocal-room-label">
                            <span class="ocal-room-type-label"><?= htmlspecialchars($typeName) ?></span>
                            <span class="ocal-room-number"><?= htmlspecialchars($room['room_number']) ?></span>
                        </div>
                        <?php foreach ($calDates as $dateIdx => $d):
                            $isToday = ($d === $todayStr);
                        ?>
                        <div class="ocal-date-cell<?= $isToday ? ' today' : '' ?>" data-date="<?= $d ?>">
                            <?php
                            foreach ($roomBookings as $bk) {
                                $ciDate = $bk['check_in_date'];
                                $coDate = $bk['check_out_date'];
                                if ($d === $ciDate) {
                                    $ci = strtotime($ciDate);
                                    $co = strtotime($coDate);
                                    $nights = max(1, (int)ceil(($co - $ci) / 86400));
                                    $barWidth = ($nights * $colW) - 6;
                                    $statusCls = 'status-confirmed';
                                    $icon = '';
                                    if ($bk['status'] === 'checked_in') {
                                        $statusCls = 'status-checked_in';
                                        $icon = '🟢 ';
                                    } elseif ($bk['status'] === 'checked_out') {
                                        $statusCls = 'status-checked_out';
                                    } elseif ($bk['status'] === 'pending') {
                                        $statusCls = 'status-pending';
                                    }
                                    $guestShort = mb_substr($bk['guest_name'] ?? 'Guest', 0, 12);
                                    echo '<div class="ocal-bar-container" style="left:50%;width:' . $barWidth . 'px;">';
                                    echo '<div class="ocal-bar ' . $statusCls . '">';
                                    echo '<span>' . $icon . htmlspecialchars($guestShort) . '</span>';
                                    echo '</div></div>';
                                }
                            }
                            ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="ocal-legend">
                <div class="ocal-legend-item"><span class="ocal-legend-dot" style="background:#06b6d4;"></span> Confirmed</div>
                <div class="ocal-legend-item"><span class="ocal-legend-dot" style="background:#0ea5e9;"></span> Pending</div>
                <div class="ocal-legend-item"><span class="ocal-legend-dot" style="background:#10b981;"></span> Checked In</div>
                <div class="ocal-legend-item"><span class="ocal-legend-dot" style="background:#9ca3af;"></span> Checked Out</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- In-House Guests -->
        <div class="section-title">
            🛏️ In-House Guests
            <span class="badge"><?= count($inHouseGuests) ?></span>
        </div>
        
        <div class="guest-list">
            <?php if (empty($inHouseGuests)): ?>
            <div class="empty-state">
                <div class="empty-icon">🏨</div>
                <div class="empty-text">No guests currently checked in</div>
            </div>
            <?php else: ?>
                <?php foreach ($inHouseGuests as $guest): ?>
                <div class="guest-item">
                    <div class="guest-room"><?= htmlspecialchars($guest['room_number'] ?? '-') ?></div>
                    <div class="guest-info">
                        <div class="guest-name"><?= htmlspecialchars($guest['guest_name'] ?? 'Guest') ?></div>
                        <div class="guest-detail"><?= htmlspecialchars($guest['room_type'] ?? '-') ?></div>
                    </div>
                    <div class="guest-checkout">
                        <div class="checkout-date"><?= date('d M', strtotime($guest['check_out_date'])) ?></div>
                        <div class="checkout-label">Check-out</div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>

        
    <?php
    require_once __DIR__ . '/../../includes/owner_footer_nav.php';
    $activeConfig = getActiveBusinessConfig();
    renderOwnerFooterNav('frontdesk', $basePath, $activeConfig['enabled_modules'] ?? []);
    ?>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // ── Pie Chart ──
        var canvas = document.getElementById('occupancyPie');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        
        var occupied = <?= (int)$stats['occupied'] ?>;
        var available = <?= (int)$stats['available'] ?>;
        var maintenance = <?= (int)(($roomStatusMap['maintenance'] ?? 0) + ($roomStatusMap['cleaning'] ?? 0)) ?>;
        var total = occupied + available + maintenance;
        if (total === 0) { available = 1; total = 1; }
        
        var cx = 55, cy = 55, r = 50, innerR = 32;
        var startAngle = -Math.PI / 2;
        
        var gradOccupied = ctx.createLinearGradient(0, 0, 110, 110);
        gradOccupied.addColorStop(0, '#ef4444'); gradOccupied.addColorStop(1, '#f87171');
        var gradAvailable = ctx.createLinearGradient(0, 0, 110, 110);
        gradAvailable.addColorStop(0, '#10b981'); gradAvailable.addColorStop(1, '#34d399');
        var gradMaint = ctx.createLinearGradient(0, 0, 110, 110);
        gradMaint.addColorStop(0, '#f59e0b'); gradMaint.addColorStop(1, '#fbbf24');
        
        var segments = [
            { value: occupied, color: gradOccupied },
            { value: available, color: gradAvailable },
            { value: maintenance, color: gradMaint }
        ];
        var currentAngle = startAngle;
        segments.forEach(function(seg) {
            if (seg.value > 0) {
                var angle = (seg.value / total) * 2 * Math.PI;
                ctx.beginPath(); ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, r, currentAngle, currentAngle + angle);
                ctx.closePath(); ctx.fillStyle = seg.color; ctx.fill();
                currentAngle += angle;
            }
        });
        var innerGrad = ctx.createRadialGradient(cx, cy, 0, cx, cy, innerR);
        innerGrad.addColorStop(0, '#ffffff'); innerGrad.addColorStop(1, '#f8fafc');
        ctx.beginPath(); ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        ctx.fillStyle = innerGrad; ctx.fill();
        ctx.beginPath(); ctx.arc(cx, cy, innerR, 0, 2 * Math.PI);
        ctx.strokeStyle = 'rgba(99, 102, 241, 0.1)'; ctx.lineWidth = 1; ctx.stroke();
    });

    // ── Owner Calendar - Drag to scroll + Today ──
    (function() {
        var scroller = document.getElementById('ocalScroller');
        if (!scroller) return;

        // Drag-to-scroll
        var isDown = false, startX, scrollLeft;
        scroller.addEventListener('mousedown', function(e) {
            if (e.target.closest('button')) return;
            isDown = true;
            scroller.style.cursor = 'grabbing';
            startX = e.pageX - scroller.offsetLeft;
            scrollLeft = scroller.scrollLeft;
        });
        scroller.addEventListener('mouseleave', function() { isDown = false; scroller.style.cursor = 'grab'; });
        scroller.addEventListener('mouseup', function() { isDown = false; scroller.style.cursor = 'grab'; });
        scroller.addEventListener('mousemove', function(e) {
            if (!isDown) return;
            e.preventDefault();
            var x = e.pageX - scroller.offsetLeft;
            scroller.scrollLeft = scrollLeft - (x - startX);
        });

        // Touch drag
        var touchStartX, touchScrollLeft;
        scroller.addEventListener('touchstart', function(e) {
            touchStartX = e.touches[0].pageX;
            touchScrollLeft = scroller.scrollLeft;
        }, {passive: true});
        scroller.addEventListener('touchmove', function(e) {
            var x = e.touches[0].pageX;
            scroller.scrollLeft = touchScrollLeft - (x - touchStartX);
        }, {passive: true});

        // Scroll to today on load
        setTimeout(function() {
            var todayCell = scroller.querySelector('.ocal-header-date.today');
            if (todayCell) {
                scroller.scrollLeft = todayCell.offsetLeft - 100;
            }
        }, 100);
    })();

    function ocalGoToday() {
        var scroller = document.getElementById('ocalScroller');
        if (!scroller) return;
        var todayCell = scroller.querySelector('.ocal-header-date.today');
        if (todayCell) {
            scroller.scrollTo({ left: todayCell.offsetLeft - 100, behavior: 'smooth' });
        }
    }
    </script>
</body>
</html>
