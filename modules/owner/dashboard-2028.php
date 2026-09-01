<?php

/**
 * OWNER DASHBOARD 2028
 * Data langsung dari PHP - Same logic as System Dashboard (index.php)
 * Multi-business aware via business_helper.php
 * @version 2.1.0 - 2026-03-01 - Pie: Income=Green, Expense=Red, Profit=Yellow
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/business_helper.php';

$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false);
$basePath = $isProduction ? '' : '/adf_system';

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
    } catch (Exception $e) {
    }
}

if (!$role || !in_array($role, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}
$userName = $_SESSION['username'] ?? 'Owner';
$isDev = ($role === 'developer');

// BUSINESS SWITCHER - handle switch request
if (isset($_GET['business']) && !empty($_GET['business'])) {
    setActiveBusinessId($_GET['business']);
    header('Location: ' . $basePath . '/modules/owner/dashboard-2028.php');
    exit;
}

// Get all available businesses & active config
require_once __DIR__ . '/../../includes/business_access.php';
$allBusinesses = getUserAvailableBusinesses();

// Sort businesses: narayana-hotel first, then alphabetically
uksort($allBusinesses, function ($a, $b) {
    if ($a === 'narayana-hotel') return -1;
    if ($b === 'narayana-hotel') return 1;
    return strcmp($a, $b);
});

// Get active business (respects session - don't force default on every load)
$activeBusinessId = getActiveBusinessId();

// If current active business is not in user's allowed list, auto-switch to first allowed
if (!empty($allBusinesses) && !isset($allBusinesses[$activeBusinessId])) {
    $firstAllowed = array_key_first($allBusinesses);
    setActiveBusinessId($firstAllowed);
    $activeBusinessId = $firstAllowed;
}

$activeConfig = getActiveBusinessConfig();

// DATABASE CONFIG - dynamic from business config
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$masterDbName = $isProduction ? 'adfb2574_adf' : 'adf_system';
$businessDbName = getDbName($activeConfig['database'] ?? 'adf_narayana_hotel');
$businessName = $activeConfig['name'] ?? 'Unknown Business';
$businessType = $activeConfig['business_type'] ?? 'other';
$businessIcon = $activeConfig['theme']['icon'] ?? '🏢';
$enabledModules = $activeConfig['enabled_modules'] ?? [];
$hasLogo = !empty($activeConfig['logo']);
$logoFile = $activeBusinessId . '_logo.png';

// Get stats - SAME LOGIC AS SYSTEM DASHBOARD (index.php)
$stats = [
    'today_income' => 0,
    'today_expense' => 0,
    'month_income' => 0,
    'month_expense' => 0,
    'total_transactions' => 0
];

$capitalStats = ['received' => 0, 'used' => 0, 'balance' => 0];
$pettyCashStats = ['received' => 0, 'used' => 0, 'balance' => 0];
$totalOperationalCash = 0;
$totalOperationalExpense = 0;

$transactions = [];
$error = null;

try {
    // Connect to business database
    $pdo = new PDO("mysql:host=$dbHost;dbname=$businessDbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Connect to master database for cash_accounts
    $masterPdo = new PDO("mysql:host=$dbHost;dbname=$masterDbName;charset=utf8mb4", $dbUser, $dbPass);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $today = date('Y-m-d');
    $thisMonth = date('Y-m');

    // Get numeric business ID from master DB
    $businessId = null;
    $stmt = $masterPdo->prepare("SELECT id FROM businesses WHERE database_name = ? LIMIT 1");
    $stmt->execute([$activeConfig['database'] ?? '']);
    $bizRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $businessId = $bizRow ? (int)$bizRow['id'] : 1;

    // Check if cash_account_id column exists in this business's cash_book
    $hasCashAccountId = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
        $hasCashAccountId = ($colCheck && $colCheck->rowCount() > 0);
    } catch (Exception $e) { /* column doesn't exist */
    }

    // Check if source_type column exists (preferred exclusion method)
    $hasSourceTypeCol = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'source_type'");
        $hasSourceTypeCol = ($colCheck && $colCheck->rowCount() > 0);
    } catch (Exception $e) {
    }

    // Get owner_capital account IDs from master DB
    $capitalAccounts = [];
    $pettyCashAccounts = [];
    if ($hasCashAccountId) {
        $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
        $stmt->execute([$businessId]);
        $capitalAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get cash (Petty Cash) account IDs from master DB
        $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
        $stmt->execute([$businessId]);
        $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Build exclude owner capital condition — SAME logic as system dashboard (index.php)
    $excludeOwnerCapital = '';
    if ($hasSourceTypeCol) {
        $excludeOwnerCapital = " AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project'))";
    } elseif ($hasCashAccountId && !empty($capitalAccounts)) {
        $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $capitalAccounts) . "))";
    }

    // Exclude owner_project from expense stats (same as index.php)
    $excludeProjectExpense = '';
    if ($hasSourceTypeCol) {
        $excludeProjectExpense = " AND (source_type IS NULL OR source_type != 'owner_project')";
    }

    // Today Income (exclude owner capital — same as system dashboard)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE transaction_date = ? AND transaction_type = 'income'" . $excludeOwnerCapital);
    $stmt->execute([$today]);
    $stats['today_income'] = (float)$stmt->fetchColumn();

    // Today Expense (exclude owner_project — same as chart API)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE transaction_date = ? AND transaction_type = 'expense'" . $excludeProjectExpense);
    $stmt->execute([$today]);
    $stats['today_expense'] = (float)$stmt->fetchColumn();

    // Month Income (exclude owner capital — same as system dashboard)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'" . $excludeOwnerCapital);
    $stmt->execute([$thisMonth]);
    $stats['month_income'] = (float)$stmt->fetchColumn();

    // Month Expense (exclude owner_project — same as chart API)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'" . $excludeProjectExpense);
    $stmt->execute([$thisMonth]);
    $stats['month_expense'] = (float)$stmt->fetchColumn();

    // Query Modal Owner stats (from cash_book with cash_account_id filter)
    if ($hasCashAccountId && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
        $query = "
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as received,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as used,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";
        $params = array_merge($capitalAccounts, [$thisMonth]);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $capitalStats['received'] = (float)($result['received'] ?? 0);
        $capitalStats['used'] = (float)($result['used'] ?? 0);
        $capitalStats['balance'] = (float)($result['balance'] ?? 0);
    } else {
        $capitalStats['received'] = 0;
        $capitalStats['used'] = 0;
        $capitalStats['balance'] = 0;
    }

    // Query Petty Cash stats (based on cash_account_id, NOT payment_method — same as system dashboard)
    if ($hasCashAccountId && !empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
        $query = "
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as received,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as used,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";
        $params = array_merge($pettyCashAccounts, [$thisMonth]);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pettyCashStats['received'] = (float)($result['received'] ?? 0);
        $pettyCashStats['used'] = (float)($result['used'] ?? 0);
        $pettyCashStats['balance'] = (float)($result['balance'] ?? 0);
    } else {
        $pettyCashStats['received'] = 0;
        $pettyCashStats['used'] = 0;
        $pettyCashStats['balance'] = 0;
    }

    // TOTAL KAS OPERASIONAL = Petty Cash balance + Modal Owner balance
    $totalOperationalCash = $pettyCashStats['balance'] + $capitalStats['balance'];

    // TOTAL PENGELUARAN OPERASIONAL = Combined expense
    $totalOperationalExpense = $pettyCashStats['used'] + $capitalStats['used'];

    // ============================================
    // CHART DATA - Expense per Division (for pie chart)
    // ============================================
    $expenseDivisionData = [];
    $stmt = $pdo->prepare("
        SELECT 
            d.division_name,
            d.division_code,
            COALESCE(SUM(cb.amount), 0) as total
        FROM divisions d
        LEFT JOIN cash_book cb ON d.id = cb.division_id 
            AND cb.transaction_type = 'expense'
            AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?
            " . ($hasSourceTypeCol ? "AND (cb.source_type IS NULL OR cb.source_type != 'owner_project')" : "") . "
        WHERE d.is_active = 1
        GROUP BY d.id, d.division_name, d.division_code
        HAVING total > 0
        ORDER BY total DESC
    ");
    $stmt->execute([$thisMonth]);
    $expenseDivisionData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total transactions
    $stmt = $pdo->query("SELECT COUNT(*) FROM cash_book");
    $stats['total_transactions'] = (int)$stmt->fetchColumn();

    // ============================================
    // CASH FLOW - All transactions for current month (like Buku Kas Besar)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT 
            cb.id, cb.transaction_date, cb.description, cb.transaction_type, cb.amount, cb.payment_method,
            d.division_name,
            c.category_name
        FROM cash_book cb
        LEFT JOIN divisions d ON cb.division_id = d.id
        LEFT JOIN categories c ON cb.category_id = c.id
        WHERE DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?
        ORDER BY cb.transaction_date DESC, cb.id DESC
    ");
    $stmt->execute([$thisMonth]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cash flow totals
    $cfTotalIncome = 0;
    $cfTotalExpense = 0;
    foreach ($transactions as $tx) {
        if ($tx['transaction_type'] === 'income') $cfTotalIncome += $tx['amount'];
        else $cfTotalExpense += $tx['amount'];
    }
    $cfBalance = $cfTotalIncome - $cfTotalExpense;

    // ============================================
    // AI HEALTH - Smart Hotel Business Analysis
    // Focuses on hotel operations ONLY (excludes project expenses)
    // ============================================

    // Previous month income/expense for growth comparison
    // Split into separate queries: income excludes capital, expense excludes project
    $lastMonth = date('Y-m', strtotime('-1 month'));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'" . $excludeOwnerCapital);
    $stmt->execute([$lastMonth]);
    $prevIncome = (float)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'" . $excludeProjectExpense);
    $stmt->execute([$lastMonth]);
    $prevExpense = (float)$stmt->fetchColumn();
    $incomeGrowth = $prevIncome > 0 ? (($stats['month_income'] - $prevIncome) / $prevIncome) * 100 : 0;

    // Hotel expense only (exclude project) for AI analysis
    $aiHotelExpense = 0;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'" . $excludeProjectExpense);
        $stmt->execute([$thisMonth]);
        $aiHotelExpense = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $aiHotelExpense = $stats['month_expense'];
    }

    // ============================================
    // FRONTDESK OCCUPANCY - Smart Analysis (using rooms + bookings tables)
    // ============================================
    $occupancyRate = 0;
    $totalRooms = 0;
    $occupiedRooms = 0;
    $monthlyOccupancyRate = 0;
    $avgStayDuration = 0;
    $bookingSourceStats = [];
    $revenuePerRoom = 0;
    $upcomingBookings = 0;
    $todayCheckins = 0;
    $todayCheckouts = 0;
    $prevMonthOccupancy = 0;
    $occupancyGrowth = 0;
    $avgRoomRate = 0;
    $revPAR = 0; // Revenue Per Available Room

    try {
        // Auto-checkout overdue bookings (same as frontdesk dashboard)
        $overdueStmt = $pdo->prepare("SELECT id, room_id FROM bookings WHERE status = 'checked_in' AND DATE(check_out_date) < CURDATE()");
        $overdueStmt->execute();
        $overdueList = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($overdueList as $ob) {
            $pdo->prepare("UPDATE bookings SET status = 'checked_out', actual_checkout_time = check_out_date, updated_at = NOW() WHERE id = ?")->execute([$ob['id']]);
            $pdo->prepare("UPDATE rooms SET status = 'available', current_guest_id = NULL, updated_at = NOW() WHERE id = ? AND status = 'occupied'")->execute([$ob['room_id']]);
        }

        // Sync room status with bookings (bookings = source of truth)
        $pdo->exec("UPDATE rooms r SET r.status = 'available', r.current_guest_id = NULL, r.updated_at = NOW() WHERE r.status = 'occupied' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.room_id = r.id AND b.status = 'checked_in')");
        $pdo->exec("UPDATE rooms r SET r.status = 'occupied', r.updated_at = NOW() WHERE r.status NOT IN ('occupied','maintenance','cleaning','blocked') AND EXISTS (SELECT 1 FROM bookings b WHERE b.room_id = r.id AND b.status = 'checked_in')");

        // Total rooms & occupied: use bookings as source of truth (not rooms.status)
        $roomStmt = $pdo->query("
            SELECT COUNT(*) as total,
                   COUNT(CASE WHEN EXISTS (
                       SELECT 1 FROM bookings b 
                       WHERE b.room_id = r.id 
                       AND b.status = 'checked_in'
                   ) THEN 1 END) as occupied
            FROM rooms r
        ");
        $roomData = $roomStmt->fetch(PDO::FETCH_ASSOC);
        $totalRooms = (int)($roomData['total'] ?? 0);
        $occupiedRooms = (int)($roomData['occupied'] ?? 0);
        $occupancyRate = $totalRooms > 0 ? ($occupiedRooms / $totalRooms) * 100 : 0;
    } catch (Exception $e) {
        // Fallback to legacy frontdesk_rooms
        try {
            $roomStmt = $pdo->query("SELECT COUNT(*) as total, COUNT(CASE WHEN status = 'occupied' THEN 1 END) as occupied FROM frontdesk_rooms");
            $roomData = $roomStmt->fetch(PDO::FETCH_ASSOC);
            $totalRooms = (int)($roomData['total'] ?? 0);
            $occupiedRooms = (int)($roomData['occupied'] ?? 0);
            $occupancyRate = $totalRooms > 0 ? ($occupiedRooms / $totalRooms) * 100 : 0;
        } catch (Exception $e2) {
        }
    }

    try {
        // Monthly occupancy: room-nights sold vs available this month
        $daysInMonth = (int)date('t');
        $daysSoFar = (int)date('j');
        $totalRoomNightsAvailable = $totalRooms * $daysSoFar;

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as room_nights
            FROM bookings 
            WHERE status IN ('checked_in', 'checked_out', 'confirmed')
            AND check_in_date <= CURDATE()
            AND check_out_date >= ?
            AND DATE_FORMAT(check_in_date, '%Y-%m') <= ?
        ");
        $stmt->execute([$thisMonth . '-01', $thisMonth]);
        $roomNightsSold = (int)($stmt->fetch(PDO::FETCH_ASSOC)['room_nights'] ?? 0);

        // Better calculation: count distinct room-days occupied
        // Include 'confirmed' bookings where check_in_date has passed (guest is there but not formally checked in)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT CONCAT(room_id, '-', d.date)) as occupied_nights
            FROM bookings b
            CROSS JOIN (
                SELECT DATE_ADD(?, INTERVAL seq.seq DAY) as date
                FROM (SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
                      UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
                      UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
                      UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
                      UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
                      UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
                      UNION SELECT 30) seq
                WHERE DATE_ADD(?, INTERVAL seq.seq DAY) <= CURDATE()
                AND DATE_ADD(?, INTERVAL seq.seq DAY) <= LAST_DAY(?)
            ) d
            WHERE b.status IN ('checked_in', 'checked_out', 'confirmed')
            AND b.check_in_date <= d.date
            AND b.check_out_date > d.date
        ");
        $firstDay = $thisMonth . '-01';
        $stmt->execute([$firstDay, $firstDay, $firstDay, $firstDay]);
        $occupiedNights = (int)($stmt->fetch(PDO::FETCH_ASSOC)['occupied_nights'] ?? 0);
        $monthlyOccupancyRate = $totalRoomNightsAvailable > 0 ? ($occupiedNights / $totalRoomNightsAvailable) * 100 : 0;

        // Previous month occupancy for comparison
        $prevFirstDay = date('Y-m-01', strtotime('-1 month'));
        $prevLastDay = date('Y-m-t', strtotime('-1 month'));
        $prevDaysInMonth = (int)date('t', strtotime('-1 month'));
        $prevTotalNights = $totalRooms * $prevDaysInMonth;

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT CONCAT(room_id, '-', d.date)) as occupied_nights
            FROM bookings b
            CROSS JOIN (
                SELECT DATE_ADD(?, INTERVAL seq.seq DAY) as date
                FROM (SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
                      UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
                      UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
                      UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
                      UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
                      UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
                      UNION SELECT 30) seq
                WHERE DATE_ADD(?, INTERVAL seq.seq DAY) <= ?
            ) d
            WHERE b.status IN ('checked_in', 'checked_out', 'confirmed')
            AND b.check_in_date <= d.date
            AND b.check_out_date > d.date
        ");
        $stmt->execute([$prevFirstDay, $prevFirstDay, $prevLastDay]);
        $prevOccupiedNights = (int)($stmt->fetch(PDO::FETCH_ASSOC)['occupied_nights'] ?? 0);
        $prevMonthOccupancy = $prevTotalNights > 0 ? ($prevOccupiedNights / $prevTotalNights) * 100 : 0;
        $occupancyGrowth = $prevMonthOccupancy > 0 ? $monthlyOccupancyRate - $prevMonthOccupancy : 0;
    } catch (Exception $e) {
        // If bookings table not available, monthly = current snapshot
        $monthlyOccupancyRate = $occupancyRate;
    }

    try {
        // Average stay duration this month (include confirmed with past check-in)
        $stmt = $pdo->prepare("
            SELECT AVG(total_nights) as avg_stay
            FROM bookings 
            WHERE status IN ('checked_in', 'checked_out', 'confirmed')
            AND DATE_FORMAT(check_in_date, '%Y-%m') = ?
        ");
        $stmt->execute([$thisMonth]);
        $avgStayDuration = round((float)($stmt->fetch(PDO::FETCH_ASSOC)['avg_stay'] ?? 0), 1);
    } catch (Exception $e) {
    }

    try {
        // Booking source analysis
        $stmt = $pdo->prepare("
            SELECT booking_source, COUNT(*) as count, COALESCE(SUM(final_price), 0) as revenue
            FROM bookings 
            WHERE status IN ('checked_in', 'checked_out', 'confirmed')
            AND DATE_FORMAT(check_in_date, '%Y-%m') = ?
            GROUP BY booking_source
            ORDER BY count DESC
        ");
        $stmt->execute([$thisMonth]);
        $bookingSourceStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }

    try {
        // Revenue per room & RevPAR (include confirmed bookings with past check-in dates)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(final_price), 0) as total_revenue, COUNT(*) as total_bookings,
                   AVG(room_price) as avg_rate
            FROM bookings 
            WHERE status IN ('checked_in', 'checked_out', 'confirmed')
            AND check_in_date <= CURDATE()
            AND DATE_FORMAT(check_in_date, '%Y-%m') = ?
        ");
        $stmt->execute([$thisMonth]);
        $revData = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalBookingRevenue = (float)($revData['total_revenue'] ?? 0);
        $totalBookingsCount = (int)($revData['total_bookings'] ?? 0);
        $avgRoomRate = round((float)($revData['avg_rate'] ?? 0));
        $revenuePerRoom = $totalRooms > 0 ? round($totalBookingRevenue / $totalRooms) : 0;
        $daysSoFar = max(1, (int)date('j'));
        $revPAR = ($totalRooms * $daysSoFar) > 0 ? round($totalBookingRevenue / ($totalRooms * $daysSoFar)) : 0;
    } catch (Exception $e) {
    }

    try {
        // Today's check-ins and check-outs
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE check_in_date = ? AND status IN ('confirmed', 'checked_in')");
        $stmt->execute([$today]);
        $todayCheckins = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE check_out_date = ? AND status = 'checked_in'");
        $stmt->execute([$today]);
        $todayCheckouts = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
    }

    try {
        // Upcoming bookings (next 7 days)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings 
            WHERE check_in_date > CURDATE() AND check_in_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND status IN ('pending', 'confirmed')
        ");
        $stmt->execute();
        $upcomingBookings = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
    }

    // Cash flow last 7 days (exclude project expenses)
    $avgDailyFlow = 0;
    try {
        $flowStmt = $pdo->query("
            SELECT SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as net_flow,
                   COUNT(DISTINCT DATE(transaction_date)) as days
            FROM cash_book 
            WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" . $excludeProjectExpense . "
        ");
        $flowData = $flowStmt->fetch(PDO::FETCH_ASSOC);
        $days = max(1, (int)($flowData['days'] ?? 1));
        $avgDailyFlow = (float)($flowData['net_flow'] ?? 0) / $days;
    } catch (Exception $e) {
    }

    // Top expense categories this month (HOTEL ONLY - exclude project expenses)
    $topExpenseCategories = [];
    try {
        $stmt = $pdo->prepare("
            SELECT c.category_name, COALESCE(SUM(cb.amount), 0) as total
            FROM cash_book cb
            LEFT JOIN categories c ON cb.category_id = c.id
            WHERE cb.transaction_type = 'expense' AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?" . $excludeProjectExpense . "
            GROUP BY cb.category_id, c.category_name
            HAVING total > 0
            ORDER BY total DESC
            LIMIT 5
        ");
        $stmt->execute([$thisMonth]);
        $topExpenseCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }

    // ============================================
    // ATTENDANCE MONITORING DATA
    // ============================================
    $attDate = date('Y-m-d');
    $attEmployees = [];
    $attRecords = [];
    $attStats = ['total' => 0, 'present' => 0, 'late' => 0, 'leave' => 0, 'absent' => 0];
    try {
        // Get all active employees
        $stmt = $pdo->query("SELECT id, employee_code, full_name, position, department FROM payroll_employees WHERE is_active = 1 ORDER BY full_name");
        $attEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $attStats['total'] = count($attEmployees);

        // Get today's attendance records (include scan_3, scan_4, late_minutes, notes)
        $stmt = $pdo->prepare("
            SELECT a.*, e.full_name, e.employee_code, e.position, e.department
            FROM payroll_attendance a
            JOIN payroll_employees e ON e.id = a.employee_id
            WHERE a.attendance_date = ?
            ORDER BY a.check_in_time ASC, e.full_name
        ");
        $stmt->execute([$attDate]);
        $attRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate stats
        $attRecordedIds = [];
        foreach ($attRecords as $ar) {
            $attRecordedIds[] = $ar['employee_id'];
            if ($ar['status'] === 'late') $attStats['late']++;
            elseif ($ar['status'] === 'leave' || $ar['status'] === 'holiday') $attStats['leave']++;
            else $attStats['present']++;
        }
        $attStats['absent'] = $attStats['total'] - count($attRecordedIds);
    } catch (Exception $e) {
        // Attendance data optional
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Check if CQC business
$isCQC = (strtolower($activeBusinessId) === 'cqc') ||
    (stripos($activeBusinessId, 'cqc') !== false) ||
    (stripos($businessName ?? '', 'cqc') !== false);

// CQC PROJECT DATA
$cqcProjects = [];
$cqcExpenses = []; // Recent expenses per project
$cqcCategoryExpenses = []; // Expenses per category per project (for pie chart)
if ($isCQC) {
    try {
        require_once __DIR__ . '/../cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();

        // ====== GET CQC FINANCIAL STATS ======
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');

        // Try to get income/expense from cash_book if exists
        $hasCashBook = false;
        try {
            $tableCheck = $cqcPdo->query("SHOW TABLES LIKE 'cash_book'");
            $hasCashBook = ($tableCheck && $tableCheck->rowCount() > 0);
        } catch (Exception $e) {
        }

        if ($hasCashBook) {
            // Get stats from cash_book
            try {
                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'income'");
                $stmt->execute([$today]);
                $stats['today_income'] = (float)$stmt->fetchColumn();

                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE(transaction_date) = ? AND transaction_type = 'expense'");
                $stmt->execute([$today]);
                $stats['today_expense'] = (float)$stmt->fetchColumn();

                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'");
                $stmt->execute([$thisMonth]);
                $stats['month_income'] = (float)$stmt->fetchColumn();

                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'expense'");
                $stmt->execute([$thisMonth]);
                $stats['month_expense'] = (float)$stmt->fetchColumn();
            } catch (Exception $e) {
                // cash_book query failed
            }
        }

        // Also add project expenses to stats - use spent_idr directly from projects
        try {
            // Get total spent from all projects for this month (using spent_idr directly)
            $stmt = $cqcPdo->query("SELECT COALESCE(SUM(spent_idr), 0) as total_spent, COALESCE(SUM(budget_idr), 0) as total_budget FROM cqc_projects WHERE status != 'completed'");
            $projTotals = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalProjectSpent = (float)($projTotals['total_spent'] ?? 0);
            $totalProjectBudget = (float)($projTotals['total_budget'] ?? 0);

            // CQC: Budget is NOT income. Income only from invoice payments.
            // Budget is just RAB (cost estimate). Don't override month_income with budget.
            // month_income stays from actual cash_book income entries (invoice payments).
            // Only add project expenses if not already counted in cash_book
            // (expenses from detail.php already sync to cash_book)

            // Also try from cqc_project_expenses table
            try {
                $stmt = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cqc_project_expenses WHERE expense_date = ?");
                $stmt->execute([$today]);
                $stats['today_expense'] += (float)$stmt->fetchColumn();
            } catch (Exception $e) {
            }
        } catch (Exception $e) {
            error_log('CQC stats error: ' . $e->getMessage());
        }

        // Simple query - just get projects
        $stmt = $cqcPdo->query("
            SELECT id, project_name, project_code, status, 
                   progress_percentage, budget_idr, spent_idr,
                   client_name, location, solar_capacity_kwp,
                   start_date, estimated_completion, end_date
            FROM cqc_projects 
            ORDER BY status ASC, progress_percentage DESC
        ");
        $cqcProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate actual spent from expenses for each project
        foreach ($cqcProjects as &$proj) {
            try {
                // Try amount_idr first, then amount
                $stmtSum = $cqcPdo->prepare("SELECT COALESCE(SUM(amount_idr), 0) as total FROM cqc_project_expenses WHERE project_id = ?");
                $stmtSum->execute([$proj['id']]);
                $sumResult = $stmtSum->fetch(PDO::FETCH_ASSOC);
                if ($sumResult && $sumResult['total'] > 0) {
                    $proj['spent_idr'] = $sumResult['total'];
                }
            } catch (Exception $e) {
                // Try with 'amount' column if 'amount_idr' doesn't exist
                try {
                    $stmtSum = $cqcPdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM cqc_project_expenses WHERE project_id = ?");
                    $stmtSum->execute([$proj['id']]);
                    $sumResult = $stmtSum->fetch(PDO::FETCH_ASSOC);
                    if ($sumResult && $sumResult['total'] > 0) {
                        $proj['spent_idr'] = $sumResult['total'];
                    }
                } catch (Exception $e2) {
                    // Keep original spent_idr
                }
            }
        }
        unset($proj);

        // Get recent expenses per project
        foreach ($cqcProjects as $proj) {
            $expenses = [];
            try {
                // Try amount_idr column first
                $stmt = $cqcPdo->prepare("
                    SELECT description, amount_idr as amount, expense_date
                    FROM cqc_project_expenses 
                    WHERE project_id = ? 
                    ORDER BY expense_date DESC, id DESC 
                    LIMIT 5
                ");
                $stmt->execute([$proj['id']]);
                $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Try 'amount' column
                try {
                    $stmt = $cqcPdo->prepare("
                        SELECT description, amount, expense_date
                        FROM cqc_project_expenses 
                        WHERE project_id = ? 
                        ORDER BY expense_date DESC, id DESC 
                        LIMIT 5
                    ");
                    $stmt->execute([$proj['id']]);
                    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e2) {
                    // No expenses
                }
            }

            $cqcExpenses[$proj['id']] = $expenses;

            // Get expenses grouped by category for pie chart
            $cqcCategoryExpenses[$proj['id']] = [];
            try {
                // First try with category join
                $stmtCat = $cqcPdo->prepare("
                    SELECT 
                        COALESCE(c.category_name, 'Lainnya') as category_name,
                        COALESCE(c.category_icon, '📦') as category_icon,
                        SUM(e.amount) as total_amount
                    FROM cqc_project_expenses e
                    LEFT JOIN cqc_expense_categories c ON e.category_id = c.id
                    WHERE e.project_id = ?
                    GROUP BY COALESCE(c.category_name, 'Lainnya'), COALESCE(c.category_icon, '📦')
                    ORDER BY total_amount DESC
                    LIMIT 6
                ");
                $stmtCat->execute([$proj['id']]);
                $result = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($result)) {
                    $cqcCategoryExpenses[$proj['id']] = $result;
                }
            } catch (Exception $catEx) {
                // If category table doesn't exist, just group as "Lainnya"
                try {
                    $stmtSimple = $cqcPdo->prepare("
                        SELECT 'Lainnya' as category_name, '📦' as category_icon, SUM(amount) as total_amount
                        FROM cqc_project_expenses WHERE project_id = ?
                    ");
                    $stmtSimple->execute([$proj['id']]);
                    $result = $stmtSimple->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($result) && floatval($result[0]['total_amount'] ?? 0) > 0) {
                        $cqcCategoryExpenses[$proj['id']] = $result;
                    }
                } catch (Exception $e2) {
                    // Ignore
                }
            }
        }
    } catch (Exception $e) {
        error_log('CQC project data error: ' . $e->getMessage());
    }
}

// Format rupiah
function rp($num)
{
    return 'Rp ' . number_format($num, 0, ',', '.');
}

$netProfit = $stats['month_income'] - $stats['month_expense'];
$netToday = $stats['today_income'] - $stats['today_expense'];
$expenseRatio = $stats['month_income'] > 0 ? ($stats['month_expense'] / $stats['month_income']) * 100 : 0;
$profitMargin = $stats['month_income'] > 0 ? ($netProfit / $stats['month_income']) * 100 : 0;

// Hotel-only expense ratio (for AI analysis, exclude project expenses)
$aiExpenseRatio = $stats['month_income'] > 0 ? ($aiHotelExpense / $stats['month_income']) * 100 : 0;
$aiNetProfit = $stats['month_income'] - $aiHotelExpense;
$aiProfitMargin = $stats['month_income'] > 0 ? ($aiNetProfit / $stats['month_income']) * 100 : 0;

// ============================================
// AI HEALTH SCORING - Smart Hotel Analysis (7-factor, 0-100)
// Focus: hotel operations only, excludes project expenses
// ============================================
$healthScore = 0;

// Factor 1: Profit Margin - Hotel Only (20 pts)
if ($aiProfitMargin >= 40) $healthScore += 20;
elseif ($aiProfitMargin >= 30) $healthScore += 17;
elseif ($aiProfitMargin >= 20) $healthScore += 14;
elseif ($aiProfitMargin >= 10) $healthScore += 10;
elseif ($aiProfitMargin >= 0) $healthScore += 6;
else $healthScore += 2;

// Factor 2: Income Growth (15 pts)
if ($incomeGrowth >= 20) $healthScore += 15;
elseif ($incomeGrowth >= 10) $healthScore += 12;
elseif ($incomeGrowth >= 5) $healthScore += 10;
elseif ($incomeGrowth >= 0) $healthScore += 7;
elseif ($incomeGrowth >= -5) $healthScore += 4;
else $healthScore += 2;

// Factor 3: Hotel Expense Control (15 pts)
if ($aiExpenseRatio <= 40) $healthScore += 15;
elseif ($aiExpenseRatio <= 50) $healthScore += 13;
elseif ($aiExpenseRatio <= 60) $healthScore += 10;
elseif ($aiExpenseRatio <= 70) $healthScore += 7;
elseif ($aiExpenseRatio <= 80) $healthScore += 4;
else $healthScore += 2;

// Factor 4: Monthly Occupancy (20 pts) - KEY HOTEL METRIC
$occForScore = $monthlyOccupancyRate > 0 ? $monthlyOccupancyRate : $occupancyRate;
if ($occForScore >= 80) $healthScore += 20;
elseif ($occForScore >= 70) $healthScore += 17;
elseif ($occForScore >= 60) $healthScore += 14;
elseif ($occForScore >= 50) $healthScore += 10;
elseif ($occForScore >= 35) $healthScore += 6;
else $healthScore += 3;

// Factor 5: RevPAR Performance (10 pts)
if ($revPAR >= 400000) $healthScore += 10;
elseif ($revPAR >= 300000) $healthScore += 8;
elseif ($revPAR >= 200000) $healthScore += 6;
elseif ($revPAR >= 100000) $healthScore += 4;
elseif ($revPAR > 0) $healthScore += 2;

// Factor 6: Cash Flow Stability (10 pts)
if ($avgDailyFlow > 500000) $healthScore += 10;
elseif ($avgDailyFlow > 0) $healthScore += 7;
elseif ($avgDailyFlow >= -100000) $healthScore += 4;
else $healthScore += 1;

// Factor 7: Booking Pipeline (10 pts)
$pipelineScore = 0;
if ($upcomingBookings >= 5) $pipelineScore += 5;
elseif ($upcomingBookings >= 3) $pipelineScore += 4;
elseif ($upcomingBookings >= 1) $pipelineScore += 2;
if ($todayCheckins >= 2) $pipelineScore += 3;
elseif ($todayCheckins >= 1) $pipelineScore += 2;
if ($occupancyGrowth > 5) $pipelineScore += 2;
elseif ($occupancyGrowth > 0) $pipelineScore += 1;
$healthScore += min(10, $pipelineScore);

// ============================================
// AI ALERTS & RECOMMENDATIONS - Hotel Focused
// ============================================
$aiAlerts = [];
$aiStrengths = [];
$aiFrontdesk = []; // Frontdesk-specific insights

// --- FINANCIAL ALERTS (hotel only, no project) ---
if (!empty($topExpenseCategories)) {
    $topCat = $topExpenseCategories[0];
    $topPct = $aiHotelExpense > 0 ? ($topCat['total'] / $aiHotelExpense) * 100 : 0;
    if ($topPct > 30) {
        $aiAlerts[] = '⚠️ <strong>' . htmlspecialchars($topCat['category_name'] ?? 'Unknown') . '</strong> absorbs ' . number_format($topPct, 0) . '% of hotel expenses (' . rp($topCat['total']) . '). Consider optimizing.';
    }
}

if ($aiExpenseRatio > 75) {
    $aiAlerts[] = '🔴 Hotel expense ratio ' . number_format($aiExpenseRatio, 1) . '% of revenue. Reduce costs or increase revenue urgently.';
} elseif ($aiExpenseRatio > 60) {
    $aiAlerts[] = '🟠 Hotel expense ratio ' . number_format($aiExpenseRatio, 1) . '% — fairly high. Monitor closely.';
}

if ($incomeGrowth < -10) {
    $aiAlerts[] = '📉 Revenue dropped ' . number_format(abs($incomeGrowth), 1) . '% vs last month. Marketing push needed.';
} elseif ($incomeGrowth < 0) {
    $aiAlerts[] = '📉 Revenue slightly down ' . number_format(abs($incomeGrowth), 1) . '% vs last month.';
}

if ($avgDailyFlow < 0) {
    $aiAlerts[] = '💸 Negative daily cash flow (avg ' . rp(abs($avgDailyFlow)) . '/day). Watch cash position.';
}

// --- FRONTDESK INTELLIGENCE ---
// Current occupancy status
if ($totalRooms > 0) {
    $occLabel = $occupancyRate >= 80 ? '🟢 High' : ($occupancyRate >= 50 ? '🟡 Medium' : '🔴 Low');
    $aiFrontdesk[] = '🏨 <strong>Now:</strong> ' . $occupiedRooms . '/' . $totalRooms . ' rooms occupied (' . number_format($occupancyRate, 0) . '%) — ' . $occLabel;
}

// Monthly occupancy trend
if ($monthlyOccupancyRate > 0) {
    $trendIcon = $occupancyGrowth > 0 ? '📈' : ($occupancyGrowth < 0 ? '📉' : '➡️');
    $trendText = abs($occupancyGrowth) > 0 ? ' (' . ($occupancyGrowth > 0 ? '+' : '') . number_format($occupancyGrowth, 1) . '% vs last month)' : '';
    $aiFrontdesk[] = $trendIcon . ' <strong>Monthly occupancy:</strong> ' . number_format($monthlyOccupancyRate, 1) . '%' . $trendText;
}

// RevPAR analysis
if ($revPAR > 0) {
    $revparLabel = $revPAR >= 300000 ? 'Excellent' : ($revPAR >= 200000 ? 'Good' : ($revPAR >= 100000 ? 'Fair' : 'Low'));
    $aiFrontdesk[] = '💰 <strong>RevPAR:</strong> ' . rp($revPAR) . '/night — ' . $revparLabel;
}

// Average room rate
if ($avgRoomRate > 0) {
    $aiFrontdesk[] = '🏷️ <strong>Avg room rate:</strong> ' . rp($avgRoomRate) . '/night';
}

// Average stay duration
if ($avgStayDuration > 0) {
    $stayLabel = $avgStayDuration >= 3 ? '(long stay, great!)' : ($avgStayDuration >= 2 ? '(normal)' : '(short, upsell opportunity)');
    $aiFrontdesk[] = '🛏️ <strong>Avg stay:</strong> ' . $avgStayDuration . ' nights ' . $stayLabel;
}

// Today activity
if ($todayCheckins > 0 || $todayCheckouts > 0) {
    $aiFrontdesk[] = '📋 <strong>Today:</strong> ' . $todayCheckins . ' check-in, ' . $todayCheckouts . ' check-out';
}

// Upcoming bookings
if ($upcomingBookings > 0) {
    $aiFrontdesk[] = '📅 <strong>Next 7 days:</strong> ' . $upcomingBookings . ' bookings';
} else {
    $aiAlerts[] = '📅 No bookings in next 7 days. Boost OTA & social media promotions.';
}

// Booking source insights
if (!empty($bookingSourceStats)) {
    $sourceLabels = ['walk_in' => 'Walk-in', 'phone' => 'Telepon', 'online' => 'Online', 'agoda' => 'Agoda', 'booking' => 'Booking.com', 'tiket' => 'Tiket.com', 'airbnb' => 'Airbnb', 'ota' => 'OTA'];
    $topSource = $bookingSourceStats[0];
    $sourceName = $sourceLabels[$topSource['booking_source']] ?? ucfirst($topSource['booking_source'] ?? 'Lainnya');
    $aiFrontdesk[] = '🔗 <strong>Top source:</strong> ' . $sourceName . ' (' . $topSource['count'] . ' bookings, ' . rp($topSource['revenue']) . ')';

    // Check OTA dependency
    $otaSources = ['agoda', 'booking', 'tiket', 'airbnb', 'ota', 'online'];
    $otaCount = 0;
    $totalCount = 0;
    foreach ($bookingSourceStats as $src) {
        $totalCount += $src['count'];
        if (in_array($src['booking_source'], $otaSources)) $otaCount += $src['count'];
    }
    $otaPct = $totalCount > 0 ? ($otaCount / $totalCount) * 100 : 0;
    if ($otaPct > 80) {
        $aiAlerts[] = '🌐 ' . number_format($otaPct, 0) . '% bookings from OTA. Diversify to direct bookings to reduce commission.';
    } elseif ($otaPct < 30 && $totalCount > 3) {
        $aiStrengths[] = '✅ Direct booking ' . number_format(100 - $otaPct, 0) . '% — saving on OTA commissions';
    }
}

// Occupancy-based alerts
if ($totalRooms > 0 && $occForScore < 40) {
    $aiAlerts[] = '🏨 Low occupancy ' . number_format($occForScore, 0) . '%. Try: OTA flash sale, weekend promos, long stay packages.';
} elseif ($totalRooms > 0 && $occForScore < 60) {
    $aiAlerts[] = '🏨 Occupancy ' . number_format($occForScore, 0) . '% — room for improvement. Optimize OTA listings & reviews.';
}

// Revenue per room insight
if ($revenuePerRoom > 0 && $totalRooms > 0) {
    $rprLabel = $revenuePerRoom >= 5000000 ? 'Excellent' : ($revenuePerRoom >= 3000000 ? 'Good' : ($revenuePerRoom >= 1500000 ? 'Fair' : 'Needs improvement'));
    if ($revenuePerRoom < 1500000) {
        $aiAlerts[] = '💵 Revenue/room only ' . rp($revenuePerRoom) . ' this month. Increase rates or occupancy.';
    }
}

// --- STRENGTHS ---
if ($aiProfitMargin >= 30) {
    $aiStrengths[] = '✅ Excellent profit margin (' . number_format($aiProfitMargin, 1) . '%)';
} elseif ($aiProfitMargin >= 20) {
    $aiStrengths[] = '✅ Healthy profit margin (' . number_format($aiProfitMargin, 1) . '%)';
}
if ($incomeGrowth > 10) {
    $aiStrengths[] = '✅ Revenue growth +' . number_format($incomeGrowth, 1) . '%';
}
if ($aiExpenseRatio < 50) {
    $aiStrengths[] = '✅ Excellent cost control (' . number_format($aiExpenseRatio, 1) . '%)';
}
if ($totalRooms > 0 && $occForScore >= 75) {
    $aiStrengths[] = '✅ High occupancy ' . number_format($occForScore, 0) . '%';
}
if ($avgStayDuration >= 3) {
    $aiStrengths[] = '✅ Avg stay ' . $avgStayDuration . ' nights — guests love it';
}
if ($revPAR >= 300000) {
    $aiStrengths[] = '✅ RevPAR ' . rp($revPAR) . ' — strong room revenue';
}
if ($upcomingBookings >= 5) {
    $aiStrengths[] = '✅ Strong pipeline (' . $upcomingBookings . ' bookings in 7 days)';
}
if ($occupancyGrowth > 10) {
    $aiStrengths[] = '✅ Occupancy up +' . number_format($occupancyGrowth, 1) . '% vs last month';
}

// Health status text
if ($healthScore >= 80) {
    $healthStatus = 'Very Healthy';
    $healthEmoji = '🟢';
} elseif ($healthScore >= 65) {
    $healthStatus = 'Healthy';
    $healthEmoji = '🟡';
} elseif ($healthScore >= 50) {
    $healthStatus = 'Fair';
    $healthEmoji = '🟠';
} else {
    $healthStatus = 'Needs Attention';
    $healthEmoji = '🔴';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Owner Dashboard - <?= htmlspecialchars($businessName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* ── Midnight Navy + Gold · Light Luxe palette ── */
            --bg: #eef0f5;
            --bg-deep: #e7eaf1;
            --surface: #ffffff;
            --surface-2: #f6f8fc;
            --text-primary: #0d2440;
            /* deep navy — high contrast */
            --text-secondary: #44597a;
            --text-muted: #7e8ea4;
            --border: #e3e7ef;
            --border-strong: #d2d9e5;
            --accent: #122f54;
            /* royal navy */
            --accent-light: #234e83;
            --gold: #c69a3f;
            --gold-light: #e3c372;
            --gold-dark: #9c7526;
            --success: #0f9d6a;
            --success-light: #34d399;
            --danger: #d83a5b;
            --danger-light: #f0708c;
            --warning: #d99a18;
            --shadow-sm: 0 1px 3px rgba(13, 36, 64, 0.06);
            --shadow: 0 8px 26px rgba(13, 36, 64, 0.09);
            --shadow-lg: 0 18px 48px rgba(13, 36, 64, 0.14);
            --shadow-navy: 0 6px 18px rgba(18, 47, 84, 0.30);
            --shadow-gold: 0 6px 18px rgba(198, 154, 63, 0.34);
            --radius: 16px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background:
                radial-gradient(1200px 600px at 100% -10%, rgba(198, 154, 63, 0.10), transparent 60%),
                radial-gradient(1000px 700px at -10% 110%, rgba(18, 47, 84, 0.06), transparent 55%),
                linear-gradient(180deg, #f1f3f8 0%, #e8ebf2 100%);
            background-attachment: fixed;
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            padding-bottom: 80px;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 100%;
            padding: 16px;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            margin-bottom: 16px;
        }

        .brand {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            padding: 4px;
            border: 1px solid rgba(198, 154, 63, 0.55);
            box-shadow: 0 2px 8px rgba(13, 36, 64, 0.12);
        }

        .brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .brand-subtext {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .user-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 50px;
            font-size: 12px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 6px;
            padding-right: 6px;
        }

        .dev-badge {
            background-color: var(--danger);
            color: white;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 20px;
        }

        .avatar {
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-refresh {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            border: none;
            border-radius: 10px;
            padding: 8px 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            color: white;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: var(--shadow-navy);
            white-space: nowrap;
        }

        .btn-refresh:hover {
            box-shadow: 0 8px 20px rgba(18, 47, 84, 0.45);
            transform: translateY(-1px);
        }

        .btn-refresh:active {
            transform: scale(0.95);
        }

        .btn-refresh svg {
            transition: transform 0.5s;
        }

        .btn-refresh:active svg {
            transform: rotate(-180deg);
        }

        /* Mobile Responsive */
        @media (max-width: 600px) {
            .container {
                padding: 10px;
            }

            .header {
                flex-wrap: wrap;
                gap: 8px;
            }

            .brand {
                min-width: 0;
                flex: 1;
            }

            .brand-text {
                font-size: 14px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                max-width: 180px;
            }

            .brand-subtext {
                font-size: 10px;
            }

            .header-right {
                gap: 6px;
            }

            .btn-refresh {
                padding: 7px 10px;
                font-size: 11px;
            }

            .btn-refresh .btn-refresh-text {
                display: none;
            }

            .user-badge {
                padding: 4px;
                font-size: 11px;
            }

            .user-info {
                display: none;
            }

            .info-card {
                padding: 10px 12px;
            }

            .biz-switcher {
                gap: 6px;
            }

            .biz-pill {
                padding: 4px 8px;
                min-width: 100px;
            }

            .biz-pill-name {
                font-size: 10px;
            }

            .biz-pill-type {
                font-size: 8px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .stat-value {
                font-size: 16px;
            }

            .operational-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 400px) {
            .brand-text {
                max-width: 140px;
                font-size: 13px;
            }

            .brand-icon {
                width: 30px;
                height: 30px;
            }

            .btn-refresh {
                padding: 6px 8px;
                border-radius: 8px;
            }
        }

        /* Info Card → Business Switcher */
        .info-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .info-card.error {
            background-color: #fff1f2;
            color: var(--danger);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .info-card-icon {
            font-size: 24px;
        }

        .info-card-content {
            flex: 1;
        }

        .info-card-title {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .info-card-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Business Switcher */
        .biz-switcher {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 2px 0;
        }

        .biz-switcher::-webkit-scrollbar {
            display: none;
        }

        .biz-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 12px;
            background: var(--bg);
            border: 1.5px solid var(--border);
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
            flex-shrink: 0;
            cursor: pointer;
        }

        .biz-pill:active {
            transform: scale(0.97);
        }

        .biz-pill.active {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            border-color: var(--gold);
            box-shadow: var(--shadow-navy);
        }

        .biz-pill-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
        }

        .biz-pill.active .biz-pill-icon {
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        .biz-pill-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .biz-pill-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .biz-pill-name {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .biz-pill.active .biz-pill-name {
            color: white;
        }

        .biz-pill-type {
            font-size: 9px;
            color: var(--text-muted);
            text-transform: capitalize;
        }

        .biz-pill.active .biz-pill-type {
            color: rgba(255, 255, 255, 0.7);
        }
        }

        /* DB Info */
        .db-info {
            background: #f0fdf4;
            color: #166534;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 11px;
            margin-bottom: 16px;
        }

        .db-info.error {
            background: #fee2e2;
            color: #dc2626;
        }

        /* Hero Section - Royal Luxe (Navy + Gold) */
        .hero {
            background: linear-gradient(160deg, #ffffff 0%, #fafbfe 35%, #f3f6fb 70%, #ffffff 100%);
            border-radius: 20px;
            padding: 22px 18px 14px;
            margin-bottom: 16px;
            color: var(--text-primary);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg), inset 0 1px 0 rgba(255, 255, 255, 0.8);
            border: 1px solid var(--border);
            border-top: 3px solid var(--gold);
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -80px;
            right: -60px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(198, 154, 63, 0.16) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -40px;
            left: -30px;
            width: 160px;
            height: 160px;
            background: radial-gradient(circle, rgba(18, 47, 84, 0.07) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-title {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: -0.2px;
            color: var(--text-primary);
        }

        .hero-subtitle {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 400;
            letter-spacing: 0.3px;
        }

        /* Donut Chart */
        .pie-wrapper {
            position: relative;
            width: 140px;
            height: 140px;
            flex-shrink: 0;
        }

        #pieChart {
            filter: drop-shadow(0 0 12px rgba(16, 185, 129, 0.12)) drop-shadow(0 4px 8px rgba(0, 0, 0, 0.08));
        }

        .pie-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1.5px solid rgba(0, 0, 0, 0.06);
            border-radius: 50%;
            width: 68px;
            height: 68px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.6);
        }

        .pie-center-label {
            font-size: 7px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 600;
            margin-bottom: 1px;
        }

        .pie-center-value {
            font-size: 20px;
            font-weight: 800;
            font-family: 'Inter', system-ui, sans-serif;
            letter-spacing: -1px;
        }

        .pie-center-value.positive {
            color: #10b981;
            text-shadow: none;
        }

        .pie-center-value.negative {
            color: #ef4444;
            text-shadow: none;
        }

        .pie-center-value.zero {
            color: #9ca3af;
        }

        /* Financial Stat Rows */
        .fp-stat-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            background: rgba(0, 0, 0, 0.02);
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-radius: 10px;
        }

        .fp-stat-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .fp-stat-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
        }

        .fp-stat-label {
            font-size: 9px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .fp-stat-val {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: -0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .fp-stat-pct {
            font-size: 10px;
            font-weight: 700;
            color: #cbd5e1;
            flex-shrink: 0;
        }

        /* Kas Harian Section - Clean Light */
        .kas-harian-section {
            margin: 16px 0;
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
            border-radius: 16px;
            padding: 18px;
            border: 1px solid #d9e6f4;
            box-shadow: 0 10px 28px rgba(15, 42, 77, 0.08);
        }

        .kas-harian-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .kas-harian-title {
            font-size: 13px;
            font-weight: 800;
            color: #17365d;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .kas-harian-date {
            font-size: 10px;
            color: #3f5f86;
            font-weight: 600;
            background: #eaf3ff;
            border: 1px solid #cfe0f4;
            padding: 3px 10px;
            border-radius: 20px;
        }

        /* Cash Hero - Start Cash + Cash Available */
        .kas-hero-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }

        .kas-hero-card {
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #d9e6f4;
        }

        .kas-hero-card.start {
            background: #f8fbff;
        }

        .kas-hero-card.available-pos {
            background: #ecfdf5;
            border-color: #86efac;
        }

        .kas-hero-card.available-neg {
            background: #fff1f2;
            border-color: #fecdd3;
        }

        .kas-hero-label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 4px;
        }

        .kas-hero-label.muted {
            color: #5f7492;
        }

        .kas-hero-label.green {
            color: #0f9d6a;
        }

        .kas-hero-label.red {
            color: #d83a5b;
        }

        .kas-hero-value {
            font-size: 15px;
            font-weight: 800;
            font-family: 'Monaco', 'Courier New', monospace;
            letter-spacing: -0.3px;
        }

        .kas-hero-value.white {
            color: #17365d;
        }

        .kas-hero-value.green {
            color: #10b981;
        }

        .kas-hero-value.red {
            color: #ef4444;
        }

        /* Summary strip - REMOVED */
        .kas-summary-strip {
            display: none;
        }

        .kas-table-wrapper {
            max-height: 260px;
            overflow-y: auto;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid #d9e6f4;
            scrollbar-width: thin;
            scrollbar-color: #c8d7ea transparent;
        }

        .kas-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .kas-table th {
            background: #f3f8ff;
            padding: 7px 10px;
            text-align: left;
            font-weight: 700;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #5f7492;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .kas-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #edf2f8;
            color: #334155;
        }

        .kas-table tr:last-child td {
            border-bottom: none;
        }

        .kas-table tr:hover td {
            background: #f8fbff;
        }

        .kas-table .text-right {
            text-align: right;
        }

        .kas-badge-masuk,
        .kas-badge-keluar {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 800;
            letter-spacing: 0.3px;
        }

        .kas-badge-masuk {
            background: #dcfce7;
            color: #0f9d6a;
        }

        .kas-badge-keluar {
            background: #ffe4e6;
            color: #d83a5b;
        }

        .kas-pay-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-left: 4px;
            vertical-align: middle;
        }

        .kas-pay-badge.cash {
            background: #dcfce7;
            color: #0f9d6a;
        }

        .kas-pay-badge.tf,
        .kas-pay-badge.transfer {
            background: #dbeafe;
            color: #2563eb;
        }

        .kas-pay-badge.qr {
            background: #fef3c7;
            color: #b45309;
        }

        .kas-pay-badge.edc,
        .kas-pay-badge.debit {
            background: #ede9fe;
            color: #7c3aed;
        }

        .kas-pay-badge.other {
            background: #e2e8f0;
            color: #475569;
        }

        .kas-amount-masuk {
            color: #10b981;
            font-weight: 700;
        }

        .kas-amount-keluar {
            color: #ef4444;
            font-weight: 700;
        }

        .kas-empty {
            text-align: center;
            padding: 24px;
            color: #64748b;
            font-size: 12px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }

        .stat-card {
            background: var(--surface);
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .stat-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
        }

        .stat-value.income {
            color: var(--success);
        }

        .stat-value.expense {
            color: var(--danger);
        }

        .stat-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Operational Section */
        .operational-section {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.8) 0%, rgba(240, 249, 255, 0.5) 100%);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 113, 227, 0.1);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }

        .operational-title {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.3px;
        }

        .operational-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .op-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.04);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .op-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--gradient-start), var(--gradient-end));
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }

        .op-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
            border-color: rgba(0, 113, 227, 0.2);
        }

        .op-card:hover::before {
            opacity: 1;
        }

        .op-card.modal-owner {
            --gradient-start: #10b981;
            --gradient-end: #34d399;
        }

        .op-card.petty-cash {
            --gradient-start: #f59e0b;
            --gradient-end: #fbbf24;
        }

        .op-card.digunakan {
            --gradient-start: #f43f5e;
            --gradient-end: #fb7185;
        }

        .op-card.total-kas {
            --gradient-start: #0071e3;
            --gradient-end: #0055b8;
        }

        .op-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            color: #6c757d;
            margin-bottom: 6px;
        }

        .op-value {
            font-size: 16px;
            font-weight: 800;
            color: #1a1a1a;
            font-family: 'Monaco', 'Courier New', monospace;
            line-height: 1.2;
        }

        .op-detail-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 16px;
            background: linear-gradient(135deg, var(--primary) 0%, #0055b8 100%);
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(0, 113, 227, 0.25);
            cursor: pointer;
            letter-spacing: -0.2px;
        }

        .op-detail-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 113, 227, 0.35);
        }

        .op-detail-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(0, 113, 227, 0.25);
        }

        /* AI Health Section */
        <?php
        // Dynamic color scheme based on health score
        if ($healthScore >= 80) {
            $aiBg = 'linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%)';
            $aiBadgeBg = '#10b981';
            $aiTitleColor = '#065f46';
            $aiContentColor = '#064e3b';
            $aiScoreLabelColor = '#065f46';
            $aiSectionTitleColor = '#065f46';
            $aiBorderTint = 'rgba(6, 95, 70, 0.1)';
            $aiTrackBg = 'rgba(6, 95, 70, 0.15)';
        } elseif ($healthScore >= 65) {
            $aiBg = 'linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%)';
            $aiBadgeBg = '#3b82f6';
            $aiTitleColor = '#1e3a5f';
            $aiContentColor = '#1e3a5f';
            $aiScoreLabelColor = '#1e3a5f';
            $aiSectionTitleColor = '#1e3a5f';
            $aiBorderTint = 'rgba(30, 58, 95, 0.1)';
            $aiTrackBg = 'rgba(30, 58, 95, 0.15)';
        } elseif ($healthScore >= 50) {
            $aiBg = 'linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)';
            $aiBadgeBg = '#f59e0b';
            $aiTitleColor = '#92400e';
            $aiContentColor = '#78350f';
            $aiScoreLabelColor = '#78350f';
            $aiSectionTitleColor = '#92400e';
            $aiBorderTint = 'rgba(146, 64, 14, 0.1)';
            $aiTrackBg = 'rgba(146, 64, 14, 0.15)';
        } else {
            $aiBg = 'linear-gradient(135deg, #fee2e2 0%, #fecaca 100%)';
            $aiBadgeBg = '#ef4444';
            $aiTitleColor = '#7f1d1d';
            $aiContentColor = '#7f1d1d';
            $aiScoreLabelColor = '#7f1d1d';
            $aiSectionTitleColor = '#7f1d1d';
            $aiBorderTint = 'rgba(127, 29, 29, 0.1)';
            $aiTrackBg = 'rgba(127, 29, 29, 0.15)';
        }
        ?>.ai-card {
            background: <?= $aiBg ?>;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .ai-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .ai-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ai-badge {
            background: <?= $aiBadgeBg ?>;
            color: white;
            font-size: 9px;
            padding: 3px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .ai-title {
            font-size: 14px;
            font-weight: 600;
            color: <?= $aiTitleColor ?>;
        }

        .ai-score {
            background: white;
            padding: 8px 14px;
            border-radius: 12px;
            text-align: center;
        }

        .ai-score-value {
            font-size: 20px;
            font-weight: 700;
            color: <?= $healthScore >= 80 ? '#10b981' : ($healthScore >= 65 ? '#3b82f6' : ($healthScore >= 50 ? '#f59e0b' : '#f43f5e')) ?>;
        }

        .ai-score-label {
            font-size: 9px;
            color: <?= $aiScoreLabelColor ?>;
            text-transform: uppercase;
        }

        .ai-content {
            font-size: 13px;
            color: <?= $aiContentColor ?>;
            line-height: 1.6;
        }

        .ai-status {
            font-size: 13px;
            margin-bottom: 10px;
        }

        .ai-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: <?= $aiSectionTitleColor ?>;
            margin: 12px 0 6px;
            letter-spacing: 0.5px;
        }

        .ai-alert-item {
            font-size: 12px;
            line-height: 1.5;
            padding: 6px 0;
            border-bottom: 1px solid <?= $aiBorderTint ?>;
        }

        .ai-alert-item:last-child {
            border-bottom: none;
        }

        .ai-expense-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            font-size: 11px;
        }

        .ai-expense-name {
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ai-expense-amount {
            font-weight: 600;
            white-space: nowrap;
        }

        .ai-expense-track {
            flex: 0 0 60px;
            height: 4px;
            background: <?= $aiTrackBg ?>;
            border-radius: 2px;
            overflow: hidden;
        }

        .ai-expense-fill {
            height: 100%;
            border-radius: 2px;
            background: #ef4444;
        }

        /* Summary Card */
        .summary-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .summary-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-title::before {
            content: '';
            width: 4px;
            height: 16px;
            background: linear-gradient(180deg, var(--accent), var(--accent-light));
            border-radius: 2px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row.total {
            font-weight: 700;
            font-size: 15px;
            padding-top: 16px;
            margin-top: 8px;
            border-top: 2px solid var(--border);
            border-bottom: none;
        }

        /* Transactions */
        .tx-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .tx-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tx-title::before {
            content: '';
            width: 4px;
            height: 16px;
            background: linear-gradient(180deg, var(--warning), #fbbf24);
            border-radius: 2px;
        }

        .tx-list {
            list-style: none;
        }

        .tx-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
            gap: 10px;
        }

        .tx-item:last-child {
            border-bottom: none;
        }

        .tx-desc {
            font-size: 12.5px;
            color: var(--text-primary);
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
            line-height: 1.4;
        }

        .tx-date {
            font-size: 10px;
            color: var(--text-muted);
        }

        .tx-amount {
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            letter-spacing: -0.2px;
        }

        .tx-amount.income {
            color: var(--success);
        }

        .tx-amount.expense {
            color: var(--danger);
        }

        .tx-method {
            display: inline-flex;
            align-items: center;
            font-size: 7.5px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            vertical-align: middle;
            flex-shrink: 0;
        }

        .tx-method.cash {
            background: #dcfce7;
            color: #16a34a;
        }

        .tx-method.transfer,
        .tx-method.tf {
            background: #dbeafe;
            color: #2563eb;
        }

        .tx-method.qr {
            background: #fef3c7;
            color: #d97706;
        }

        .tx-method.debit,
        .tx-method.edc {
            background: #f3e8ff;
            color: #9333ea;
        }

        .tx-method.other {
            background: #f1f5f9;
            color: #64748b;
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
            padding: 10px 0;
            border-top: 1px solid var(--border);
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.05);
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            font-size: 10px;
            color: var(--text-muted);
            transition: color 0.2s;
        }

        .nav-item.active {
            color: var(--accent);
        }

        .nav-icon {
            font-size: 20px;
            margin-bottom: 2px;
        }

        /* Hero Today Row - Clean */
        .hero-today-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(0, 0, 0, 0.03);
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 14px;
            gap: 8px;
        }

        .hero-today-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }

        .hero-today-label {
            font-size: 9px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            font-weight: 500;
        }

        .hero-today-value {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: #334155;
        }

        .hero-today-value.income {
            color: #10b981;
        }

        .hero-today-value.expense {
            color: #ef4444;
        }

        .hero-today-divider {
            width: 1px;
            height: 28px;
            background: rgba(0, 0, 0, 0.08);
        }

        /* Dev Badge */
        .dev-badge {
            position: fixed;
            top: 10px;
            right: 10px;
            background: #f43f5e;
            color: white;
            font-size: 10px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            z-index: 1000;
        }

        /* Expense Division Pie Chart */
        .expense-division-card {
            background: var(--surface);
            border-radius: 14px;
            margin-top: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .expense-division-header {
            padding: 12px 14px 10px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .expense-division-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .expense-division-title .icon-circle {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .expense-month-input {
            font-size: 11px;
            padding: 4px 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text-primary);
            outline: none;
            height: 28px;
        }

        .expense-month-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(18, 47, 84, 0.14);
        }

        .expense-division-body {
            position: relative;
            height: 220px;
            padding: 10px 14px 6px;
        }

        .expense-division-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-muted);
            font-size: 12px;
        }

        .expense-division-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            padding: 6px 14px 12px;
            justify-content: center;
        }

        .edl-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            color: var(--text-secondary);
        }

        .edl-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* Mobile Optimizations */
        @media (max-width: 380px) {
            .pie-wrapper {
                width: 110px;
                height: 110px;
            }

            .pie-center {
                width: 56px;
                height: 56px;
            }

            .pie-center-value {
                font-size: 16px;
            }

            .fp-stat-val {
                font-size: 12px;
            }

            .fp-stat-row {
                padding: 5px 8px;
            }

            .hero-today-value {
                font-size: 11px;
            }
        }

        @media (max-width: 340px) {
            .operational-grid {
                grid-template-columns: 1fr;
            }

            .expense-division-body {
                height: 200px;
            }
        }

        /* CQC Status Badges */
        .cqc-status-planning {
            background: #eef2ff;
            color: #4a6cf7;
        }

        .cqc-status-procurement {
            background: #fef3c7;
            color: #d97706;
        }

        .cqc-status-installation {
            background: #dbeafe;
            color: #2563eb;
        }

        .cqc-status-testing {
            background: #fce7f3;
            color: #db2777;
        }

        .cqc-status-completed {
            background: #d1fae5;
            color: #059669;
        }

        .cqc-status-on_hold {
            background: #f3f4f6;
            color: #6b7280;
        }

        /* ══════════════════════════════════════════ */
        /* ATTENDANCE MONITORING                      */
        /* ══════════════════════════════════════════ */
        .att-section {
            margin-top: 20px;
        }

        .att-hero {
            background: #ffffff;
            border-radius: 18px;
            padding: 18px 16px 14px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }

        .att-hero::before {
            content: '';
            position: absolute;
            top: -30px;
            right: -30px;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(198, 154, 63, 0.12) 0%, transparent 70%);
        }

        .att-hero-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
        }

        .att-hero-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: 0.3px;
        }

        .att-hero-badge {
            background: rgba(18, 47, 84, 0.07);
            border: 1px solid rgba(198, 154, 63, 0.45);
            color: var(--accent);
            font-size: 8px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 20px;
            letter-spacing: 1px;
            text-transform: uppercase;
            animation: attPulse 2s ease-in-out infinite;
        }

        @keyframes attPulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.6;
            }
        }

        .att-date-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .att-date-btn {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 10px;
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .att-date-btn:active {
            background: #e2e8f0;
            transform: scale(0.92);
        }

        .att-date-label {
            font-size: 13px;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: 0.3px;
            min-width: 160px;
            text-align: center;
        }

        /* Stats row inside hero */
        .att-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            position: relative;
            z-index: 1;
        }

        .att-stat-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 10px 6px;
            text-align: center;
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: background 0.2s;
        }

        .att-stat-card:active {
            background: #f1f5f9;
        }

        .att-stat-num {
            font-size: 22px;
            font-weight: 900;
            line-height: 1.1;
            color: #1e293b;
        }

        .att-stat-label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-top: 3px;
        }

        .att-stat-card.asc-present .att-stat-label {
            color: #16a34a;
        }

        .att-stat-card.asc-late .att-stat-label {
            color: #d97706;
        }

        .att-stat-card.asc-leave .att-stat-label {
            color: #2563eb;
        }

        .att-stat-card.asc-absent .att-stat-label {
            color: #dc2626;
        }

        .att-stat-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 2px;
            vertical-align: middle;
        }

        .asd-present {
            background: #22c55e;
        }

        .asd-late {
            background: #f59e0b;
        }

        .asd-leave {
            background: #3b82f6;
        }

        .asd-absent {
            background: #ef4444;
        }

        /* Staff list */
        .att-list-wrap {
            background: var(--surface);
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-top: 12px;
        }

        .att-list-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
        }

        .att-list-title {
            font-size: 12px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: 0.2px;
        }

        .att-list-count {
            font-size: 9px;
            color: var(--accent);
            font-weight: 700;
            background: rgba(18, 47, 84, 0.07);
            padding: 3px 10px;
            border-radius: 20px;
        }

        .att-emp-row {
            display: flex;
            align-items: center;
            padding: 9px 14px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.03);
            gap: 10px;
            transition: background 0.15s;
        }

        .att-emp-row:last-child {
            border-bottom: none;
        }

        .att-emp-row:active {
            background: rgba(18, 47, 84, 0.04);
        }

        .att-emp-avatar {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 900;
            color: #fff;
            flex-shrink: 0;
        }

        .att-emp-avatar.av-present {
            background: linear-gradient(135deg, #16a34a, #4ade80);
        }

        .att-emp-avatar.av-late {
            background: linear-gradient(135deg, #d97706, #fbbf24);
        }

        .att-emp-avatar.av-leave {
            background: linear-gradient(135deg, #2563eb, #60a5fa);
        }

        .att-emp-avatar.av-absent {
            background: linear-gradient(135deg, #dc2626, #f87171);
        }

        .att-emp-info {
            flex: 1;
            min-width: 0;
        }

        .att-emp-name {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .att-emp-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
            flex-wrap: wrap;
        }

        .att-emp-pos {
            font-size: 9px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .att-emp-scans {
            text-align: right;
            flex-shrink: 0;
        }

        .att-scan-pills {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .att-scan-pill {
            background: rgba(18, 47, 84, 0.05);
            border: 1px solid rgba(18, 47, 84, 0.1);
            border-radius: 6px;
            padding: 2px 6px;
            font-size: 9px;
            display: flex;
            align-items: center;
            gap: 2px;
        }

        .att-scan-pill .att-sp-lbl {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 7px;
            text-transform: uppercase;
        }

        .att-scan-pill .att-sp-val {
            color: var(--text-primary);
            font-weight: 800;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 9px;
        }

        .att-emp-note {
            font-size: 8px;
            color: #f59e0b;
            font-weight: 600;
            margin-top: 2px;
            text-align: right;
        }

        .att-emp-hours {
            font-size: 8px;
            color: var(--accent);
            font-weight: 800;
            background: rgba(18, 47, 84, 0.06);
            padding: 1px 6px;
            border-radius: 4px;
            display: inline-block;
            margin-top: 2px;
        }

        .att-status-badge {
            display: inline-block;
            font-size: 7px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .asb-present {
            background: #dcfce7;
            color: #15803d;
        }

        .asb-late {
            background: #fef3c7;
            color: #92400e;
        }

        .asb-leave {
            background: #dbeafe;
            color: #1e40af;
        }

        .asb-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .asb-holiday {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .asb-half_day {
            background: #ffedd5;
            color: #9a3412;
        }

        .att-late-tag {
            font-size: 7px;
            color: #f59e0b;
            font-weight: 800;
            background: rgba(245, 158, 11, 0.1);
            padding: 1px 5px;
            border-radius: 10px;
        }
    </style>
</head>

<body>
    <?php if ($isDev): ?>
        <div class="dev-badge">DEV</div>
    <?php endif; ?>

    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="brand">
                <div class="brand-icon">
                    <?php if (file_exists(__DIR__ . '/../../uploads/logos/' . $logoFile)): ?>
                        <img src="<?= $basePath ?>/uploads/logos/<?= $logoFile ?>" alt="Logo">
                    <?php else: ?>
                        <span style="font-size:22px;display:flex;align-items:center;justify-content:center;width:100%;height:100%"><?= $businessIcon ?></span>
                    <?php endif; ?>
                </div>
                <div class="brand-text">
                    <?= htmlspecialchars($businessName) ?>
                    <span class="brand-subtext">Owner Dashboard</span>
                </div>
            </div>
            <div class="header-right">
                <button class="btn-refresh" onclick="location.reload()" title="Refresh Data">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path d="M1 4v6h6" />
                        <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" />
                    </svg>
                    <span class="btn-refresh-text">Refresh</span>
                </button>
                <div class="user-badge">
                    <div class="avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                    <div class="user-info">
                        <?= htmlspecialchars($userName) ?>
                        <?php if ($isDev): ?><span class="dev-badge">DEV</span><?php endif; ?>
                    </div>
                </div>
            </div>
        </header>

        <?php if (count($allBusinesses) > 1): ?>
            <!-- Business Switcher -->
            <div class="info-card">
                <div style="font-size:10px;color:var(--text-muted);margin-bottom:6px;font-weight:500;text-transform:uppercase;letter-spacing:0.5px;">Switch Business</div>
                <div class="biz-switcher">
                    <?php foreach ($allBusinesses as $bizId => $biz):
                        $isActive = ($bizId === $activeBusinessId);
                        $bizLogoFile = $bizId . '_logo.png';
                        $bizLogoExists = file_exists(__DIR__ . '/../../uploads/logos/' . $bizLogoFile);
                    ?>
                        <a href="<?= $basePath ?>/modules/owner/dashboard-2028.php?business=<?= urlencode($bizId) ?>" class="biz-pill <?= $isActive ? 'active' : '' ?>">
                            <div class="biz-pill-icon">
                                <?php if ($bizLogoExists): ?>
                                    <img src="<?= $basePath ?>/uploads/logos/<?= $bizLogoFile ?>" alt="">
                                <?php else: ?>
                                    <?= $biz['theme']['icon'] ?? '🏢' ?>
                                <?php endif; ?>
                            </div>
                            <div class="biz-pill-text">
                                <span class="biz-pill-name"><?= htmlspecialchars($biz['name']) ?></span>
                                <span class="biz-pill-type"><?= $biz['business_type'] ?? 'business' ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="info-card error">
                <div class="info-card-icon">❌</div>
                <div class="info-card-content">
                    <div class="info-card-title">Connection Error</div>
                    <div class="info-card-value"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$error): ?>
            <!-- Financial Performance — Premium 2028 -->
            <div class="hero">
                <div class="hero-content">
                    <!-- Top Row: Title + Date -->
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                        <div>
                            <div class="hero-title">Financial Performance</div>
                            <div class="hero-subtitle"><?= date('F Y') ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:9px;text-transform:uppercase;letter-spacing:1.5px;font-weight:600;color:#64748b;">Net Profit</div>
                            <div style="font-size:20px;font-weight:800;letter-spacing:-0.5px;color:<?= $netProfit >= 0 ? '#10b981' : '#ef4444' ?>;"><?= $netProfit >= 0 ? '+' : '' ?><?= rp($netProfit) ?></div>
                        </div>
                    </div>

                    <!-- Main Grid: Chart + Stats -->
                    <div style="display:flex;gap:16px;align-items:center;">
                        <!-- Donut Chart -->
                        <div class="pie-wrapper">
                            <canvas id="pieChart" width="140" height="140"></canvas>
                            <?php
                            $profitMargin = $stats['month_income'] > 0
                                ? round((($stats['month_income'] - $stats['month_expense']) / $stats['month_income']) * 100)
                                : 0;
                            $profitClass = $profitMargin > 0 ? 'positive' : ($profitMargin < 0 ? 'negative' : 'zero');
                            ?>
                            <div class="pie-center">
                                <div class="pie-center-label">Margin</div>
                                <div class="pie-center-value <?= $profitClass ?>"><?= $profitMargin ?>%</div>
                            </div>
                        </div>

                        <!-- Right Stats Column -->
                        <div style="flex:1;display:flex;flex-direction:column;gap:8px;min-width:0;">
                            <!-- Income -->
                            <div class="fp-stat-row">
                                <div class="fp-stat-dot" style="background:#10b981;"></div>
                                <div class="fp-stat-info">
                                    <span class="fp-stat-label">Income</span>
                                    <span class="fp-stat-val" style="color:#34d399;"><?= rp($stats['month_income']) ?></span>
                                </div>
                                <?php if ($stats['month_income'] > 0 && ($stats['month_income'] + $stats['month_expense']) > 0): ?>
                                    <span class="fp-stat-pct"><?= round($stats['month_income'] / ($stats['month_income'] + $stats['month_expense']) * 100) ?>%</span>
                                <?php endif; ?>
                            </div>
                            <!-- Expense -->
                            <div class="fp-stat-row">
                                <div class="fp-stat-dot" style="background:#ef4444;"></div>
                                <div class="fp-stat-info">
                                    <span class="fp-stat-label">Expense</span>
                                    <span class="fp-stat-val" style="color:#fb7185;"><?= rp($stats['month_expense']) ?></span>
                                </div>
                                <?php if ($stats['month_expense'] > 0 && ($stats['month_income'] + $stats['month_expense']) > 0): ?>
                                    <span class="fp-stat-pct"><?= round($stats['month_expense'] / ($stats['month_income'] + $stats['month_expense']) * 100) ?>%</span>
                                <?php endif; ?>
                            </div>
                            <!-- Expense Ratio Bar -->
                            <div style="margin-top:4px;">
                                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                                    <span style="font-size:9px;opacity:0.5;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Expense Ratio</span>
                                    <span style="font-size:10px;font-weight:700;color:<?= $expenseRatio > 70 ? '#fb7185' : ($expenseRatio > 50 ? '#fbbf24' : '#34d399') ?>"><?= number_format($expenseRatio, 1) ?>%</span>
                                </div>
                                <div style="height:4px;background:rgba(255,255,255,0.08);border-radius:2px;overflow:hidden;">
                                    <div style="height:100%;width:<?= min($expenseRatio, 100) ?>%;background:linear-gradient(90deg,<?= $expenseRatio > 70 ? '#ef4444,#fb7185' : ($expenseRatio > 50 ? '#f59e0b,#fbbf24' : '#10b981,#34d399') ?>);border-radius:2px;transition:width 0.6s ease;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Today Strip -->
                    <div class="hero-today-row">
                        <div class="hero-today-item">
                            <span class="hero-today-label">Today In</span>
                            <span class="hero-today-value income"><?= rp($stats['today_income']) ?></span>
                        </div>
                        <div class="hero-today-divider"></div>
                        <div class="hero-today-item">
                            <span class="hero-today-label">Today Out</span>
                            <span class="hero-today-value expense"><?= rp($stats['today_expense']) ?></span>
                        </div>
                        <div class="hero-today-divider"></div>
                        <div class="hero-today-item">
                            <span class="hero-today-label">Today Net</span>
                            <span class="hero-today-value" style="color:<?= $netToday >= 0 ? '#34d399' : '#fb7185' ?>"><?= $netToday >= 0 ? '+' : '' ?><?= rp($netToday) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Daily Cash Section - SYNCED WITH index.php -->
            <?php
            // ============================================
            // DAILY CASH - SYNCED WITH index.php
            // Same logic: separate capital + petty cash stats (MONTHLY)
            // Then: startKas (carry-over) + monthly net = Cash Available
            // Plus: guestCashIncome (Cash dari Tamu - payment_method='cash')
            // ============================================
            $todayKas = [];
            $startKasHariIni = 0;
            $ownerTransferThisMonth = 0;
            $totalOperationalIncome = 0;
            $totalOperationalExpense = 0;
            $totalOperationalCash = 0;
            $guestCashIncome = 0;
            $allAccounts = [];
            $capitalStats = ['received' => 0, 'used' => 0, 'balance' => 0];
            $pettyCashStats = ['received' => 0, 'used' => 0, 'balance' => 0];

            try {
                // Connect to master DB to get cash account IDs
                $masterDb = new PDO("mysql:host=" . $dbHost . ";dbname=" . $masterDbName . ";charset=utf8mb4", $dbUser, $dbPass);
                $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $businessId = getMasterBusinessId();

                // Get owner_capital account IDs
                $stmtCap = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
                $stmtCap->execute([$businessId]);
                $capitalAccounts = $stmtCap->fetchAll(PDO::FETCH_COLUMN);

                // Get petty cash (cash) account IDs
                $stmtPetty = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
                $stmtPetty->execute([$businessId]);
                $pettyCashAccounts = $stmtPetty->fetchAll(PDO::FETCH_COLUMN);

                // Merge all operational accounts
                $allAccounts = array_merge($capitalAccounts, $pettyCashAccounts);

                $kasDb = new PDO("mysql:host=" . $dbHost . ";dbname=" . $businessDbName . ";charset=utf8mb4", $dbUser, $dbPass);
                $kasDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $today = date('Y-m-d');
                $thisMonth = date('Y-m');
                $firstDayOfMonth = date('Y-m-01');

                // Check if source_type column exists (same as index.php)
                $hasSourceTypeCol = false;
                try {
                    $colCheck = $kasDb->query("SHOW COLUMNS FROM cash_book LIKE 'source_type'");
                    $hasSourceTypeCol = $colCheck && $colCheck->rowCount() > 0;
                } catch (\Throwable $e) {
                    $hasSourceTypeCol = false;
                }

                // Query Modal Owner stats THIS MONTH (same as index.php)
                if (!empty($capitalAccounts)) {
                    $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
                    $sqlCapital = "
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) as received,
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as used,
                        (COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                         COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0)) as balance
                    FROM cash_book 
                    WHERE cash_account_id IN ($placeholders)
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtCapital = $kasDb->prepare($sqlCapital);
                    $stmtCapital->execute(array_merge($capitalAccounts, [$thisMonth]));
                    $capResult = $stmtCapital->fetch(PDO::FETCH_ASSOC);
                    $capitalStats['received'] = (float)($capResult['received'] ?? 0);
                    $capitalStats['used'] = (float)($capResult['used'] ?? 0);
                    $capitalStats['balance'] = (float)($capResult['balance'] ?? 0);
                }

                // Query Petty Cash / Kas Operasional stats THIS MONTH (same as index.php)
                if (!empty($pettyCashAccounts)) {
                    $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
                    $sqlPetty = "
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) as received,
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as used,
                        (COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                         COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0)) as balance
                    FROM cash_book 
                    WHERE cash_account_id IN ($placeholders)
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtPetty2 = $kasDb->prepare($sqlPetty);
                    $stmtPetty2->execute(array_merge($pettyCashAccounts, [$thisMonth]));
                    $pettyResult = $stmtPetty2->fetch(PDO::FETCH_ASSOC);
                    $pettyCashStats['received'] = (float)($pettyResult['received'] ?? 0);
                    $pettyCashStats['used'] = (float)($pettyResult['used'] ?? 0);
                    $pettyCashStats['balance'] = (float)($pettyResult['balance'] ?? 0);
                }

                // TOTAL KAS OPERASIONAL = Petty Cash + Modal Owner (MONTHLY net - same as index.php)
                $totalOperationalCash = $pettyCashStats['balance'] + $capitalStats['balance'];

                // TOTAL PENGELUARAN OPERASIONAL = Petty Cash expense + Modal Owner expense
                $totalOperationalExpense = $pettyCashStats['used'] + $capitalStats['used'];

                // TOTAL UANG MASUK = Petty Cash received + Modal Owner received
                $totalOperationalIncome = $pettyCashStats['received'] + $capitalStats['received'];

                if (!empty($allAccounts)) {
                    $placeholders = implode(',', array_fill(0, count($allAccounts), '?'));

                    // START KAS = Saldo akhir bulan sebelumnya (same as index.php)
                    // Modal Owner: all transactions before THIS MONTH
                    $startKasOwner = 0;
                    $startKasPetty = 0;

                    if (!empty($capitalAccounts)) {
                        $capPh = implode(',', array_fill(0, count($capitalAccounts), '?'));
                        $sqlStartOwner = "
                        SELECT COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                               COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
                        FROM cash_book WHERE cash_account_id IN ($capPh) AND transaction_date < ?
                    ";
                        $stmtStartOwner = $kasDb->prepare($sqlStartOwner);
                        $stmtStartOwner->execute(array_merge($capitalAccounts, [$firstDayOfMonth]));
                        $startKasOwner = (float)($stmtStartOwner->fetchColumn() ?: 0);
                    }

                    if (!empty($pettyCashAccounts)) {
                        $pettyPh = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
                        $sqlStartPetty = "
                        SELECT COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                               COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
                        FROM cash_book WHERE cash_account_id IN ($pettyPh) AND transaction_date < ?
                    ";
                        $stmtStartPetty = $kasDb->prepare($sqlStartPetty);
                        $stmtStartPetty->execute(array_merge($pettyCashAccounts, [$firstDayOfMonth]));
                        $startKasPetty = (float)($stmtStartPetty->fetchColumn() ?: 0);
                    }

                    $startKasHariIni = $startKasOwner + $startKasPetty;

                    // Owner Transfer THIS MONTH - menggunakan source_type='owner_fund'
                    // Fallback: income ke owner_capital accounts
                    if ($hasSourceTypeCol) {
                        $sqlOwnerTransfer = "
                        SELECT COALESCE(SUM(amount), 0) as total
                        FROM cash_book 
                        WHERE transaction_type = 'income'
                        AND source_type = 'owner_fund'
                        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                    ";
                        $stmtOwnerTransfer = $kasDb->prepare($sqlOwnerTransfer);
                        $stmtOwnerTransfer->execute([$thisMonth]);
                        $ownerTransferThisMonth = (float)($stmtOwnerTransfer->fetchColumn() ?: 0);
                    } elseif (!empty($capitalAccounts)) {
                        $ownerPh = implode(',', array_fill(0, count($capitalAccounts), '?'));
                        $sqlOwnerTransfer = "
                        SELECT COALESCE(SUM(amount), 0) as total
                        FROM cash_book 
                        WHERE cash_account_id IN ($ownerPh)
                        AND transaction_type = 'income'
                        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                    ";
                        $stmtOwnerTransfer = $kasDb->prepare($sqlOwnerTransfer);
                        $stmtOwnerTransfer->execute(array_merge($capitalAccounts, [$thisMonth]));
                        $ownerTransferThisMonth = (float)($stmtOwnerTransfer->fetchColumn() ?: 0);
                    } else {
                        $ownerTransferThisMonth = 0;
                    }

                    // Get ALL transactions for TODAY (no limit - show full daily detail)
                    $sqlKas = "
                    SELECT id, transaction_type, description, amount, payment_method, cash_account_id,
                           TIME_FORMAT(CONCAT(transaction_date, ' ', COALESCE(transaction_time, '00:00:00')), '%H:%i') as jam,
                           transaction_date
                    FROM cash_book 
                    WHERE transaction_date = ?
                    ORDER BY transaction_time DESC, id DESC
                ";
                    $stmtKas = $kasDb->prepare($sqlKas);
                    $stmtKas->execute([$today]);
                    $todayKas = $stmtKas->fetchAll(PDO::FETCH_ASSOC);
                }

                // Get Guest Cash Income this month - cash dari tamu saja (payment_method='cash', bukan owner_fund)
                if ($hasSourceTypeCol) {
                    $sqlCashIncome = "
                    SELECT COALESCE(SUM(amount), 0) as total 
                    FROM cash_book 
                    WHERE transaction_type = 'income' 
                    AND payment_method = 'cash'
                    AND (source_type IS NULL OR source_type != 'owner_fund')
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtCashIncome = $kasDb->prepare($sqlCashIncome);
                    $stmtCashIncome->execute([$thisMonth]);
                    $guestCashIncome = (float)($stmtCashIncome->fetchColumn() ?: 0);
                } elseif (!empty($capitalAccounts)) {
                    $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
                    $sqlCashIncome = "
                    SELECT COALESCE(SUM(amount), 0) as total 
                    FROM cash_book 
                    WHERE transaction_type = 'income' 
                    AND payment_method = 'cash'
                    AND (cash_account_id IS NULL OR cash_account_id NOT IN ($placeholders))
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtCashIncome = $kasDb->prepare($sqlCashIncome);
                    $stmtCashIncome->execute(array_merge($capitalAccounts, [$thisMonth]));
                    $guestCashIncome = (float)($stmtCashIncome->fetchColumn() ?: 0);
                } else {
                    $sqlCashIncome = "
                    SELECT COALESCE(SUM(amount), 0) as total 
                    FROM cash_book 
                    WHERE transaction_type = 'income' 
                    AND payment_method = 'cash'
                    AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
                ";
                    $stmtCashIncome = $kasDb->prepare($sqlCashIncome);
                    $stmtCashIncome->execute([$thisMonth]);
                    $guestCashIncome = (float)($stmtCashIncome->fetchColumn() ?: 0);
                }
            } catch (PDOException $e) {
                error_log("Daily Cash Error: " . $e->getMessage());
            }

            // CASH AVAILABLE = Start Cash + Monthly Net (same as index.php)
            $dashCashAvailable = $startKasHariIni + $totalOperationalCash;
            ?>
            <div class="kas-harian-section">
                <div class="kas-harian-header">
                    <div class="kas-harian-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2">
                            <rect x="2" y="3" width="20" height="14" rx="2" />
                            <path d="M8 21h8m-4-4v4" />
                        </svg>
                        Daily Cash
                    </div>
                    <div class="kas-harian-date"><?= date('d M Y') ?> (Today)</div>
                </div>

                <!-- Hero: Start Cash + Cash Available -->
                <div class="kas-hero-row">
                    <div class="kas-hero-card start">
                        <div class="kas-hero-label muted">Start Cash (<?= date('M') ?>)</div>
                        <div class="kas-hero-value white"><?= number_format($startKasHariIni, 0, ',', '.') ?></div>
                    </div>
                    <div class="kas-hero-card <?= $dashCashAvailable >= 0 ? 'available-pos' : 'available-neg' ?>">
                        <div class="kas-hero-label <?= $dashCashAvailable >= 0 ? 'green' : 'red' ?>">Cash Available</div>
                        <div class="kas-hero-value <?= $dashCashAvailable >= 0 ? 'green' : 'red' ?>"><?= number_format($dashCashAvailable, 0, ',', '.') ?></div>
                    </div>
                </div>

                <!-- Summary Strip: Owner Transfer | Guest Cash | Owner + Guest | Expense -->
                <div class="kas-summary-strip">
                    <div class="kas-strip-item">
                        <div class="kas-strip-label">Owner Transfer</div>
                        <div class="kas-strip-value green"><?= number_format($ownerTransferThisMonth, 0, ',', '.') ?></div>
                    </div>
                    <div class="kas-strip-item">
                        <div class="kas-strip-label">Owner + Guest</div>
                        <div class="kas-strip-value green"><?= number_format($ownerTransferThisMonth + $guestCashIncome, 0, ',', '.') ?></div>
                    </div>
                    <div class="kas-strip-item">
                        <div class="kas-strip-label">Expense</div>
                        <div class="kas-strip-value red"><?= number_format($totalOperationalExpense, 0, ',', '.') ?></div>
                    </div>
                </div>

                <?php if ($dashCashAvailable < 0): ?>
                    <div style="margin-bottom: 10px; padding: 6px 10px; background: rgba(239,68,68,0.08); border-left: 2px solid #ef4444; border-radius: 4px;">
                        <div style="font-size: 11px; color: #fb7185; font-weight: 600;">⚠️ Negative cash!</div>
                    </div>
                <?php endif; ?>

                <div class="kas-table-wrapper" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($todayKas)): ?>
                        <div class="kas-empty">No transactions today</div>
                    <?php else: ?>
                        <div style="font-size: 11px; color: #94a3b8; margin-bottom: 6px; text-align: right;"><?= count($todayKas) ?> transaksi hari ini</div>
                        <table class="kas-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Description</th>
                                    <th class="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Build lookup array of operational account IDs for color coding
                                $operationalIds = array_map('intval', $allAccounts);
                                foreach ($todayKas as $kas):
                                    $isMasuk = $kas['transaction_type'] === 'income';
                                    $amount = (float)$kas['amount'];
                                    $payMethod = strtolower(trim($kas['payment_method'] ?? 'other'));
                                    $payLabel = strtoupper($payMethod === 'transfer' ? 'TF' : $payMethod);
                                    $payClass = in_array($payMethod, ['cash', 'transfer', 'tf', 'qr', 'debit', 'edc']) ? $payMethod : 'other';
                                    // Color logic:
                                    // GREEN = transfer dana operasional masuk (income to owner/petty cash accounts)
                                    // RED = semua pengeluaran (expense)
                                    // WHITE = lainnya (income dari tamu/guest)
                                    $txAccId = isset($kas['cash_account_id']) ? (int)$kas['cash_account_id'] : 0;
                                    $isOperationalIn = $isMasuk && $txAccId > 0 && in_array($txAccId, $operationalIds);
                                    if ($isOperationalIn) {
                                        $rowColor = '#0f9d6a'; // green (income operasional)
                                    } elseif (!$isMasuk) {
                                        $rowColor = '#d83a5b'; // red (pengeluaran)
                                    } else {
                                        $rowColor = '#334155'; // slate dark (income dari tamu)
                                    }
                                ?>
                                    <tr>
                                        <td style="white-space:nowrap;font-size:11px;color:<?= $rowColor ?>;"><?= $kas['jam'] ?></td>
                                        <td>
                                            <span class="<?= $isMasuk ? 'kas-badge-masuk' : 'kas-badge-keluar' ?>"><?= $isMasuk ? 'IN' : 'OUT' ?></span>
                                            <span style="color:<?= $rowColor ?>;<?= $isOperationalIn ? 'font-weight:600;' : '' ?>"><?= htmlspecialchars(html_entity_decode(mb_substr($kas['description'], 0, 30), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?></span>
                                            <span class="kas-pay-badge <?= $payClass ?>"><?= $payLabel ?></span>
                                        </td>
                                        <td class="text-right" style="white-space:nowrap;font-weight:700;color:<?= $rowColor ?>;">
                                            <?= $isMasuk ? '+' : '-' ?><?= number_format($amount, 0, ',', '.') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isCQC): ?>
                <!-- CQC Project Monitoring - Elegant 2026 -->
                <div style="margin: 16px 0; padding: 20px; background: linear-gradient(180deg, #ffffff 0%, #fafbfc 100%); border-radius: 20px; box-shadow: 0 4px 24px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);">
                    <!-- Header with Icon -->
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 44px; height: 44px; background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); border-radius: 14px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="5" />
                                    <path d="m12 1v2m0 18v2m4.22-18.36 1.42 1.42M4.93 19.07l1.41 1.42m12.73 0 1.41-1.42M4.93 4.93l1.42 1.42M1 12h2m18 0h2" />
                                </svg>
                            </div>
                            <div>
                                <div style="font-size: 9px; color: #f59e0b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px;">CQC Enjiniring</div>
                                <div style="font-size: 16px; font-weight: 700; color: #1f2937; letter-spacing: -0.4px; margin-top: 1px;">Pencapaian & Keuangan Per Proyek</div>
                            </div>
                        </div>
                        <a href="<?php echo $basePath; ?>/modules/cqc-projects/" style="padding: 8px 14px; background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); color: white; border-radius: 10px; font-size: 11px; font-weight: 600; text-decoration: none; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3); transition: all 0.2s;">Kelola →</a>
                    </div>

                    <?php if (empty($cqcProjects)): ?>
                        <div style="text-align: center; padding: 50px 20px; color: #9ca3af; background: #f9fafb; border-radius: 16px;">
                            <div style="width: 64px; height: 64px; margin: 0 auto 16px; background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <span style="font-size: 28px;">☀️</span>
                            </div>
                            <div style="font-size: 16px; font-weight: 600; color: #6b7280;">Belum ada proyek</div>
                            <div style="font-size: 13px; margin-top: 6px; color: #9ca3af;">Tambahkan proyek di menu CQC Projects</div>
                        </div>
                    <?php else: ?>
                        <!-- Summary Stats - Glassmorphism Style -->
                        <?php
                        $totalBudget = array_sum(array_column($cqcProjects, 'budget_idr'));
                        $totalSpent = array_sum(array_column($cqcProjects, 'spent_idr'));
                        $totalRemaining = $totalBudget - $totalSpent;
                        $avgProgress = count($cqcProjects) > 0 ? round(array_sum(array_column($cqcProjects, 'progress_percentage')) / count($cqcProjects)) : 0;
                        $budgetUsedPct = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100) : 0;
                        ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 10px; margin-bottom: 24px;">
                            <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #122f54 0%, #234e83 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(18, 47, 84, 0.30); border-top: 2px solid #c69a3f;">
                                <div style="font-size: 28px; font-weight: 800; color: #fff; font-family: system-ui; line-height: 1;"><?php echo count($cqcProjects); ?></div>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Total Proyek</div>
                            </div>
                            <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(14, 165, 233, 0.25);">
                                <div style="font-size: 14px; font-weight: 700; color: #fff; font-family: system-ui;">Rp <?php echo number_format($totalBudget / 1000000000, 2); ?>M</div>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Total Budget</div>
                            </div>
                            <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(244, 63, 94, 0.25);">
                                <div style="font-size: 14px; font-weight: 700; color: #fff; font-family: system-ui;">Rp <?php echo number_format($totalSpent / 1000000, 0); ?>jt</div>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Terpakai (<?php echo $budgetUsedPct; ?>%)</div>
                            </div>
                            <div style="text-align: center; padding: 16px 10px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 16px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);">
                                <div style="font-size: 28px; font-weight: 800; color: #fff; font-family: system-ui; line-height: 1;"><?php echo $avgProgress; ?>%</div>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.8); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Avg Progress</div>
                            </div>
                        </div>

                        <!-- Project Cards Grid - Modern Design -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px;">
                            <?php foreach ($cqcProjects as $idx => $proj):
                                $budget = floatval($proj['budget_idr'] ?? 0);
                                $spent = floatval($proj['spent_idr'] ?? 0);
                                $remaining = $budget - $spent;
                                $progress = intval($proj['progress_percentage'] ?? 0);
                                $spentPct = $budget > 0 ? round(($spent / $budget) * 100, 1) : 0;
                                $statusLabels = ['planning' => 'Planning', 'procurement' => 'Procurement', 'installation' => 'Instalasi', 'testing' => 'Testing', 'completed' => 'Selesai', 'on_hold' => 'Ditunda'];
                                $statusLabel = $statusLabels[$proj['status']] ?? ucfirst($proj['status']);
                                $statusColors = ['planning' => '#6366f1', 'procurement' => '#f59e0b', 'installation' => '#3b82f6', 'testing' => '#ec4899', 'completed' => '#10b981', 'on_hold' => '#6b7280'];
                                $statusColor = $statusColors[$proj['status']] ?? '#6b7280';
                                $expenses = $cqcExpenses[$proj['id']] ?? [];
                                $kwp = floatval($proj['solar_capacity_kwp'] ?? 0);
                                $startDate = $proj['start_date'] ?? null;
                                $estCompletion = $proj['estimated_completion'] ?? null;
                                $clientName = $proj['client_name'] ?? '';
                                // Modern color palette per project
                                $projectColorPalette = [
                                    ['#10b981', '#34d399', 'rgba(16, 185, 129, 0.1)'], // Emerald
                                    ['#f59e0b', '#fbbf24', 'rgba(245, 158, 11, 0.1)'], // Amber
                                    ['#3b82f6', '#60a5fa', 'rgba(59, 130, 246, 0.1)'], // Blue
                                    ['#8b5cf6', '#a78bfa', 'rgba(139, 92, 246, 0.1)'], // Violet
                                    ['#ec4899', '#f472b6', 'rgba(236, 72, 153, 0.1)'], // Pink
                                    ['#06b6d4', '#22d3ee', 'rgba(6, 182, 212, 0.1)'], // Cyan
                                    ['#84cc16', '#a3e635', 'rgba(132, 204, 22, 0.1)'], // Lime
                                    ['#f97316', '#fb923c', 'rgba(249, 115, 22, 0.1)'], // Orange
                                ];
                                $projColorIdx = $idx % count($projectColorPalette);
                                $projColor = $projectColorPalette[$projColorIdx][0];
                                $projColorLight = $projectColorPalette[$projColorIdx][1];
                                $projColorBg = $projectColorPalette[$projColorIdx][2];
                            ?>
                                <div class="cqc-project-card" onclick="toggleExpenseDetail(<?php echo $idx; ?>)" style="background: #fff; border-radius: 20px; padding: 20px; border: 1px solid #e5e7eb; box-shadow: 0 4px 20px rgba(0,0,0,0.04); cursor: pointer; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); position: relative; overflow: hidden;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 32px rgba(0,0,0,0.1)'; this.style.borderColor='<?php echo $projColor; ?>';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 20px rgba(0,0,0,0.04)'; this.style.borderColor='#e5e7eb';">

                                    <!-- Accent Line Top -->
                                    <div style="position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, <?php echo $projColor; ?>, <?php echo $projColorLight; ?>);"></div>

                                    <!-- Header -->
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px;">
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="font-size: 10px; color: <?php echo $projColor; ?>; font-weight: 700; letter-spacing: 1.2px; font-family: system-ui; text-transform: uppercase;"><?php echo htmlspecialchars($proj['project_code']); ?></div>
                                            <div style="font-size: 15px; font-weight: 700; color: #111827; margin-top: 4px; line-height: 1.3; letter-spacing: -0.3px;"><?php echo htmlspecialchars($proj['project_name']); ?></div>
                                            <?php if ($clientName): ?>
                                                <div style="font-size: 11px; color: #6b7280; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                                        <circle cx="12" cy="7" r="4" />
                                                    </svg>
                                                    <?php echo htmlspecialchars($clientName); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <span style="padding: 6px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; background: <?php echo $statusColor; ?>15; color: <?php echo $statusColor; ?>; letter-spacing: 0.3px; text-transform: uppercase; white-space: nowrap;"><?php echo $statusLabel; ?></span>
                                    </div>

                                    <!-- Main Content: Progress Chart + Financial -->
                                    <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 16px;">

                                        <!-- Progress Donut Chart -->
                                        <div style="flex-shrink: 0; text-align: center;">
                                            <div style="position: relative; width: 100px; height: 100px;">
                                                <canvas id="cqcPie<?php echo $idx; ?>" style="filter: drop-shadow(0 4px 12px <?php echo $projColor; ?>30);"></canvas>
                                                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                                                    <div style="font-size: 22px; font-weight: 800; color: <?php echo $projColor; ?>; line-height: 1; font-family: system-ui;"><?php echo $progress; ?>%</div>
                                                    <div style="font-size: 8px; color: #9ca3af; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Progress</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Financial Details -->
                                        <div style="flex: 1; min-width: 0;">
                                            <!-- Budget -->
                                            <div style="margin-bottom: 10px;">
                                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                    <span style="font-size: 10px; color: #6b7280; font-weight: 600;">💰 Budget</span>
                                                    <span style="font-size: 12px; font-weight: 700; color: #374151; font-family: system-ui;"><?php echo number_format($budget / 1000000, 0); ?>jt</span>
                                                </div>
                                                <div style="height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                                    <div style="width: <?php echo min($spentPct, 100); ?>%; height: 100%; background: linear-gradient(90deg, <?php echo $spentPct > 90 ? '#ef4444' : $projColor; ?>, <?php echo $spentPct > 90 ? '#f87171' : $projColorLight; ?>); border-radius: 3px; transition: width 0.5s ease;"></div>
                                                </div>
                                            </div>

                                            <!-- Spent & Remaining -->
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                                <div style="background: #fef2f2; padding: 8px 10px; border-radius: 10px;">
                                                    <div style="font-size: 8px; color: #9ca3af; text-transform: uppercase; font-weight: 600;">Terpakai</div>
                                                    <div style="font-size: 13px; font-weight: 700; color: #ef4444; font-family: system-ui; margin-top: 2px;"><?php echo number_format($spent / 1000000, 1); ?>jt</div>
                                                </div>
                                                <div style="background: <?php echo $remaining >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 8px 10px; border-radius: 10px;">
                                                    <div style="font-size: 8px; color: #9ca3af; text-transform: uppercase; font-weight: 600;">Sisa</div>
                                                    <div style="font-size: 13px; font-weight: 700; color: <?php echo $remaining >= 0 ? '#10b981' : '#ef4444'; ?>; font-family: system-ui; margin-top: 2px;"><?php echo number_format($remaining / 1000000, 1); ?>jt</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Tags Row: KWP + Timeline -->
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px; padding-top: 12px; border-top: 1px solid #f3f4f6;">
                                        <?php if ($kwp > 0): ?>
                                            <span style="padding: 5px 10px; background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 8px; font-size: 10px; font-weight: 700; color: #92400e; display: flex; align-items: center; gap: 4px;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                    <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" />
                                                </svg>
                                                <?php echo number_format($kwp, 1); ?> kWp
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($startDate): ?>
                                            <span style="padding: 5px 10px; background: #f0fdf4; border-radius: 8px; font-size: 10px; font-weight: 600; color: #166534; display: flex; align-items: center; gap: 4px;">
                                                🚀 <?php echo date('d M Y', strtotime($startDate)); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($estCompletion): ?>
                                            <span style="padding: 5px 10px; background: #eff6ff; border-radius: 8px; font-size: 10px; font-weight: 600; color: #1e40af; display: flex; align-items: center; gap: 4px;">
                                                🎯 <?php echo date('d M Y', strtotime($estCompletion)); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Expense Detail (hidden by default) -->
                                    <div id="expenseDetail<?php echo $idx; ?>" style="display: none; margin-top: 16px; padding-top: 16px; border-top: 1px dashed #e5e7eb;">
                                        <div style="font-size: 11px; font-weight: 700; color: #374151; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                            </svg>
                                            Pengeluaran Terbaru
                                        </div>
                                        <?php if (empty($expenses)): ?>
                                            <div style="text-align: center; padding: 16px; color: #9ca3af; font-size: 12px; background: #f9fafb; border-radius: 12px;">
                                                <div style="font-size: 24px; margin-bottom: 6px;">📭</div>
                                                Belum ada pengeluaran
                                            </div>
                                        <?php else: ?>
                                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                                <?php foreach ($expenses as $exp): ?>
                                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f9fafb; border-radius: 10px; border: 1px solid #f3f4f6;">
                                                        <div style="flex: 1; min-width: 0;">
                                                            <div style="font-size: 12px; font-weight: 600; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($exp['description'] ?? 'Pengeluaran'); ?></div>
                                                            <div style="font-size: 10px; color: #9ca3af; margin-top: 2px;"><?php echo $exp['expense_date'] ? date('d M Y', strtotime($exp['expense_date'])) : '-'; ?></div>
                                                        </div>
                                                        <div style="font-size: 12px; font-weight: 700; color: #ef4444; font-family: system-ui; white-space: nowrap; margin-left: 12px; background: #fef2f2; padding: 4px 8px; border-radius: 6px;">-Rp <?php echo number_format(floatval($exp['amount'] ?? 0), 0, ',', '.'); ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Click indicator -->
                                    <div style="text-align: center; margin-top: 12px;">
                                        <span id="clickHint<?php echo $idx; ?>" style="font-size: 10px; color: #c0c0c0; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 4px;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="6 9 12 15 18 9" />
                                            </svg>
                                            tap untuk detail
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; // end of else (has projects) 
                    ?>
                </div>
            <?php endif; // end of isCQC 
            ?>

            <!-- AI Health - Compact with Click to Expand -->
            <div class="ai-card" onclick="toggleAiHealth()" style="cursor:pointer;">
                <!-- COMPACT VIEW (always visible) -->
                <div class="ai-header" style="margin-bottom:0;">
                    <div class="ai-title-wrap">
                        <span class="ai-badge">✨ AI</span>
                        <span class="ai-title">Health Monitor</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php if ($totalRooms > 0): ?>
                            <div style="display:flex;gap:6px;">
                                <span style="font-size:11px;font-weight:700;color:<?= $occupancyRate >= 60 ? '#10b981' : ($occupancyRate >= 40 ? '#f59e0b' : '#ef4444') ?>"><?= number_format($occupancyRate, 0) ?>% occ</span>
                                <span style="font-size:11px;opacity:0.4;">|</span>
                            </div>
                        <?php endif; ?>
                        <div class="ai-score" style="padding:6px 10px;">
                            <div class="ai-score-value" style="font-size:18px;"><?= number_format($healthScore, 0) ?></div>
                            <div class="ai-score-label"><?= $healthStatus ?></div>
                        </div>
                        <svg id="aiChevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="transition:transform 0.3s;opacity:0.4;flex-shrink:0;">
                            <polyline points="6 9 12 15 18 9" />
                        </svg>
                    </div>
                </div>
                <div style="font-size:11px;margin-top:6px;opacity:0.7;">
                    <?= $healthEmoji ?> Margin <?= number_format($aiProfitMargin, 1) ?>% · Expense <?= number_format($aiExpenseRatio, 1) ?>%<?= $prevIncome > 0 ? ' · Growth ' . ($incomeGrowth >= 0 ? '+' : '') . number_format($incomeGrowth, 1) . '%' : '' ?><?php if (!empty($aiAlerts)): ?> · <span style="color:#ef4444;font-weight:600;"><?= count($aiAlerts) ?> alert<?= count($aiAlerts) > 1 ? 's' : '' ?></span><?php endif; ?>
                </div>

                <!-- EXPANDED VIEW (hidden by default) -->
                <div id="aiHealthDetail" style="display:none;margin-top:14px;border-top:1px solid <?= $aiBorderTint ?>;padding-top:12px;" onclick="event.stopPropagation();">
                    <div class="ai-content">
                        <?php if ($totalRooms > 0): ?>
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:10px;">
                                <div style="text-align:center;padding:8px 4px;background:rgba(255,255,255,0.5);border-radius:8px;">
                                    <div style="font-size:16px;font-weight:800;color:<?= $occupancyRate >= 60 ? '#10b981' : ($occupancyRate >= 40 ? '#f59e0b' : '#ef4444') ?>"><?= number_format($occupancyRate, 0) ?>%</div>
                                    <div style="font-size:8px;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;opacity:0.7;margin-top:2px;">Occupancy Now</div>
                                </div>
                                <div style="text-align:center;padding:8px 4px;background:rgba(255,255,255,0.5);border-radius:8px;">
                                    <div style="font-size:16px;font-weight:800;color:<?= $monthlyOccupancyRate >= 60 ? '#10b981' : ($monthlyOccupancyRate >= 40 ? '#f59e0b' : '#ef4444') ?>"><?= number_format($monthlyOccupancyRate, 1) ?>%</div>
                                    <div style="font-size:8px;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;opacity:0.7;margin-top:2px;">Monthly Avg</div>
                                </div>
                                <div style="text-align:center;padding:8px 4px;background:rgba(255,255,255,0.5);border-radius:8px;">
                                    <div style="font-size:16px;font-weight:800;color:#122f54"><?= rp($revPAR) ?></div>
                                    <div style="font-size:8px;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;opacity:0.7;margin-top:2px;">RevPAR</div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($aiFrontdesk)): ?>
                            <div class="ai-section-title">🏨 Frontdesk Intelligence</div>
                            <?php foreach ($aiFrontdesk as $fd): ?>
                                <div class="ai-alert-item"><?= $fd ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($topExpenseCategories)): ?>
                            <div class="ai-section-title">💰 Top Expenses</div>
                            <?php
                            $maxExp = $topExpenseCategories[0]['total'];
                            foreach (array_slice($topExpenseCategories, 0, 5) as $cat):
                                $pct = $maxExp > 0 ? ($cat['total'] / $maxExp) * 100 : 0;
                            ?>
                                <div class="ai-expense-bar">
                                    <span class="ai-expense-name"><?= htmlspecialchars($cat['category_name'] ?? 'Other') ?></span>
                                    <span class="ai-expense-track"><span class="ai-expense-fill" style="width:<?= $pct ?>%"></span></span>
                                    <span class="ai-expense-amount"><?= rp($cat['total']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($aiAlerts)): ?>
                            <div class="ai-section-title">⚠️ Alerts</div>
                            <?php foreach ($aiAlerts as $alert): ?>
                                <div class="ai-alert-item"><?= $alert ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($aiStrengths)): ?>
                            <div class="ai-section-title">💪 Strengths</div>
                            <?php foreach ($aiStrengths as $str): ?>
                                <div class="ai-alert-item"><?= $str ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!$isCQC && $attStats['total'] > 0): ?>
                <!-- ═══════════════════════════════════════════ -->
                <!-- ATTENDANCE MONITORING                      -->
                <!-- ═══════════════════════════════════════════ -->
                <div class="att-section">
                    <!-- Hero Card with Stats -->
                    <div class="att-hero">
                        <div class="att-hero-top">
                            <div class="att-hero-title">👥 Staff Attendance</div>
                            <span class="att-hero-badge">● Live</span>
                        </div>

                        <!-- Date Navigation -->
                        <div class="att-date-nav">
                            <button class="att-date-btn" onclick="attNavDate(-1)">◀</button>
                            <span class="att-date-label" id="attDateLabel"><?php
                                                                            $hariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                                                                            $bulanIndoShort = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                                                                            $dt = new DateTime($attDate);
                                                                            echo $hariIndo[(int)$dt->format('w')] . ', ' . $dt->format('d') . ' ' . $bulanIndoShort[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
                                                                            ?></span>
                            <button class="att-date-btn" onclick="attNavDate(1)">▶</button>
                        </div>

                        <!-- Stats Cards -->
                        <div class="att-stats">
                            <div class="att-stat-card asc-present">
                                <div class="att-stat-num" id="attStatPresent"><?= $attStats['present'] ?></div>
                                <div class="att-stat-label"><span class="att-stat-dot asd-present"></span> Hadir</div>
                            </div>
                            <div class="att-stat-card asc-late">
                                <div class="att-stat-num" id="attStatLate"><?= $attStats['late'] ?></div>
                                <div class="att-stat-label"><span class="att-stat-dot asd-late"></span> Terlambat</div>
                            </div>
                            <div class="att-stat-card asc-leave">
                                <div class="att-stat-num" id="attStatLeave"><?= $attStats['leave'] ?></div>
                                <div class="att-stat-label"><span class="att-stat-dot asd-leave"></span> Izin</div>
                            </div>
                            <div class="att-stat-card asc-absent">
                                <div class="att-stat-num" id="attStatAbsent"><?= $attStats['absent'] ?></div>
                                <div class="att-stat-label"><span class="att-stat-dot asd-absent"></span> Alpha</div>
                            </div>
                        </div>
                    </div>

                    <!-- Staff List -->
                    <div class="att-list-wrap">
                        <div class="att-list-header">
                            <div class="att-list-title">Daftar Kehadiran</div>
                            <div class="att-list-count" id="attListCount"><?= count($attRecords) ?>/<?= $attStats['total'] ?> staff</div>
                        </div>
                        <div id="attStaffList">
                            <?php
                            foreach ($attRecords as $ar):
                                $statusCls = 'av-present';
                                $badgeCls = 'asb-present';
                                $badgeText = 'Hadir';
                                $initial = mb_strtoupper(mb_substr($ar['full_name'], 0, 1));
                                if ($ar['status'] === 'late') {
                                    $statusCls = 'av-late';
                                    $badgeCls = 'asb-late';
                                    $badgeText = 'Terlambat';
                                } elseif ($ar['status'] === 'leave') {
                                    $statusCls = 'av-leave';
                                    $badgeCls = 'asb-leave';
                                    $badgeText = 'Izin';
                                } elseif ($ar['status'] === 'holiday') {
                                    $statusCls = 'av-leave';
                                    $badgeCls = 'asb-holiday';
                                    $badgeText = 'Libur';
                                } elseif ($ar['status'] === 'half_day') {
                                    $statusCls = 'av-late';
                                    $badgeCls = 'asb-half_day';
                                    $badgeText = 'Half Day';
                                }
                                $s1 = $ar['check_in_time'] ? substr($ar['check_in_time'], 0, 5) : '-';
                                $s2 = $ar['check_out_time'] ? substr($ar['check_out_time'], 0, 5) : '-';
                                $s3 = !empty($ar['scan_3']) ? substr($ar['scan_3'], 0, 5) : '';
                                $s4 = !empty($ar['scan_4']) ? substr($ar['scan_4'], 0, 5) : '';
                                $wh = $ar['work_hours'] ? number_format((float)$ar['work_hours'], 1) . 'h' : '';
                                $lateMins = (int)($ar['late_minutes'] ?? 0);
                                $noteText = trim($ar['notes'] ?? '');
                            ?>
                                <div class="att-emp-row">
                                    <div class="att-emp-avatar <?= $statusCls ?>"><?= $initial ?></div>
                                    <div class="att-emp-info">
                                        <div class="att-emp-name"><?= htmlspecialchars($ar['full_name']) ?></div>
                                        <div class="att-emp-meta">
                                            <span class="att-emp-pos"><?= htmlspecialchars($ar['position'] ?? '-') ?></span>
                                            <span class="att-status-badge <?= $badgeCls ?>"><?= $badgeText ?></span>
                                            <?php if ($lateMins > 0): ?><span class="att-late-tag">+<?= $lateMins ?>m</span><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="att-emp-scans">
                                        <div class="att-scan-pills">
                                            <div class="att-scan-pill"><span class="att-sp-lbl">S1</span><span class="att-sp-val"><?= $s1 ?></span></div>
                                            <div class="att-scan-pill"><span class="att-sp-lbl">S2</span><span class="att-sp-val"><?= $s2 ?></span></div>
                                            <?php if ($s3): ?><div class="att-scan-pill"><span class="att-sp-lbl">S3</span><span class="att-sp-val"><?= $s3 ?></span></div><?php endif; ?>
                                            <?php if ($s4): ?><div class="att-scan-pill"><span class="att-sp-lbl">S4</span><span class="att-sp-val"><?= $s4 ?></span></div><?php endif; ?>
                                        </div>
                                        <?php if ($wh): ?><div class="att-emp-hours"><?= $wh ?></div><?php endif; ?>
                                        <?php if ($noteText): ?><div class="att-emp-note"><?= htmlspecialchars(mb_substr($noteText, 0, 20)) ?></div><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php
                            $recordedIds = array_column($attRecords, 'employee_id');
                            foreach ($attEmployees as $emp):
                                if (in_array($emp['id'], $recordedIds)) continue;
                                $initial = mb_strtoupper(mb_substr($emp['full_name'], 0, 1));
                            ?>
                                <div class="att-emp-row">
                                    <div class="att-emp-avatar av-absent"><?= $initial ?></div>
                                    <div class="att-emp-info">
                                        <div class="att-emp-name"><?= htmlspecialchars($emp['full_name']) ?></div>
                                        <div class="att-emp-meta">
                                            <span class="att-emp-pos"><?= htmlspecialchars($emp['position'] ?? '-') ?></span>
                                            <span class="att-status-badge asb-absent">Alpha</span>
                                        </div>
                                    </div>
                                    <div class="att-emp-scans">
                                        <div style="font-size:9px;color:#dc2626;font-weight:700;">Tidak hadir</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; // end if (!$error) 
        ?>

    </div><!-- end .container -->

    <!-- Footer Nav -->
    <?php
    require_once __DIR__ . '/../../includes/owner_footer_nav.php';
    renderOwnerFooterNav('home', $basePath, $enabledModules);
    ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ============================================
            // DOUGHNUT CHART - Expense per Division
            // ============================================
            var expDivCanvas = document.getElementById('expenseDivisionChart');
            var expenseDivisionChart = null;
            var divChartColors = [
                'rgba(239,68,68,0.85)', 'rgba(251,146,60,0.85)', 'rgba(245,158,11,0.85)',
                'rgba(234,179,8,0.85)', 'rgba(132,204,22,0.85)', 'rgba(34,197,94,0.85)',
                'rgba(20,184,166,0.85)', 'rgba(6,182,212,0.85)', 'rgba(59,130,246,0.85)',
                'rgba(99,102,241,0.85)', 'rgba(139,92,246,0.85)', 'rgba(168,85,247,0.85)'
            ];

            if (expDivCanvas) {
                var expDivCtx = expDivCanvas.getContext('2d');
                expenseDivisionChart = new Chart(expDivCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [<?php foreach ($expenseDivisionData as $div): ?> '<?= addslashes($div['division_name']) ?>', <?php endforeach; ?>],
                        datasets: [{
                            data: [<?php foreach ($expenseDivisionData as $div): ?><?= $div['total'] ?>, <?php endforeach; ?>],
                            backgroundColor: divChartColors.slice(0, <?= count($expenseDivisionData) ?>),
                            borderWidth: 0,
                            hoverOffset: 14
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '58%',
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15,23,42,0.92)',
                                padding: 10,
                                titleFont: {
                                    size: 11,
                                    weight: '600'
                                },
                                bodyFont: {
                                    size: 10
                                },
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(ctx) {
                                        var val = ctx.parsed || 0;
                                        var total = ctx.dataset.data.reduce(function(a, b) {
                                            return a + b;
                                        }, 0);
                                        var pct = ((val / total) * 100).toFixed(1);
                                        return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // AJAX update for month change
            window.updateExpenseDivisionChart = function(month) {
                var basePath = '<?= $basePath ?>';
                var activeBiz = '<?= $activeBusinessId ?>';
                fetch(basePath + '/api/expense-division-data.php?month=' + month + '&business=' + activeBiz)
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success && data.divisions.length > 0) {
                            // Show canvas, hide empty
                            var body = document.querySelector('.expense-division-body');
                            body.innerHTML = '<canvas id="expenseDivisionChart"></canvas>';
                            var newCtx = document.getElementById('expenseDivisionChart').getContext('2d');
                            expenseDivisionChart = new Chart(newCtx, {
                                type: 'doughnut',
                                data: {
                                    labels: data.divisions,
                                    datasets: [{
                                        data: data.amounts,
                                        backgroundColor: divChartColors.slice(0, data.divisions.length),
                                        borderWidth: 0,
                                        hoverOffset: 14
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    cutout: '58%',
                                    plugins: {
                                        legend: {
                                            display: false
                                        },
                                        tooltip: {
                                            backgroundColor: 'rgba(15,23,42,0.92)',
                                            padding: 10,
                                            titleFont: {
                                                size: 11,
                                                weight: '600'
                                            },
                                            bodyFont: {
                                                size: 10
                                            },
                                            cornerRadius: 8,
                                            callbacks: {
                                                label: function(ctx) {
                                                    var val = ctx.parsed || 0;
                                                    var total = ctx.dataset.data.reduce(function(a, b) {
                                                        return a + b;
                                                    }, 0);
                                                    var pct = ((val / total) * 100).toFixed(1);
                                                    return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                            // Update legend
                            var legendEl = document.getElementById('expenseDivisionLegend');
                            if (legendEl) {
                                var html = '';
                                data.divisions.forEach(function(name, i) {
                                    html += '<div class="edl-item"><span class="edl-dot" style="background:' + divChartColors[i % divChartColors.length] + '"></span>' + name + '</div>';
                                });
                                legendEl.innerHTML = html;
                                legendEl.style.display = 'flex';
                            }
                        } else {
                            // Show empty state
                            var body = document.querySelector('.expense-division-body');
                            body.innerHTML = '<div class="expense-division-empty"><span style="font-size:28px;margin-bottom:6px;">📭</span>No expense data available</div>';
                            var legendEl = document.getElementById('expenseDivisionLegend');
                            if (legendEl) legendEl.style.display = 'none';
                        }
                    })
                    .catch(function(err) {
                        console.error('Error:', err);
                    });
            };

            // ============================================
            // MAIN DONUT CHART - Income vs Expense (Premium 2028)
            // ============================================
            var canvas = document.getElementById('pieChart');
            if (!canvas) return;

            var income = <?= (float)$stats['month_income'] ?>;
            var expense = <?= (float)$stats['month_expense'] ?>;
            if (income === 0 && expense === 0) {
                income = 1;
                expense = 1;
            }

            var pieCtx = canvas.getContext('2d');
            var incomeGrad = pieCtx.createLinearGradient(0, 0, 140, 140);
            incomeGrad.addColorStop(0, '#10b981');
            incomeGrad.addColorStop(1, '#34d399');
            var expenseGrad = pieCtx.createLinearGradient(0, 0, 140, 140);
            expenseGrad.addColorStop(0, '#ef4444');
            expenseGrad.addColorStop(1, '#fb7185');

            new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Income', 'Expense'],
                    datasets: [{
                        data: [income, expense],
                        backgroundColor: [incomeGrad, expenseGrad],
                        borderWidth: 0,
                        borderRadius: 6,
                        hoverOffset: 6,
                        spacing: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '68%',
                    rotation: -90,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15,23,42,0.95)',
                            titleColor: '#e5e7eb',
                            bodyColor: '#e5e7eb',
                            cornerRadius: 10,
                            padding: 12,
                            displayColors: true,
                            titleFont: {
                                size: 12,
                                weight: '700'
                            },
                            bodyFont: {
                                size: 11
                            },
                            callbacks: {
                                label: function(ctx) {
                                    var val = ctx.parsed || 0;
                                    var total = ctx.dataset.data.reduce(function(a, b) {
                                        return a + b;
                                    }, 0);
                                    var pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                    return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                                }
                            }
                        }
                    },
                    animation: {
                        animateRotate: true,
                        duration: 800,
                        easing: 'easeOutQuart'
                    }
                }
            });
        });

        <?php if ($isCQC && !empty($cqcProjects)): ?>
            // CQC PROJECT PIE CHARTS - Modern Elegant with Shadows
            <?php
            $projectColors = [
                ['#10b981', '#34d399'], // Emerald
                ['#f59e0b', '#fbbf24'], // Amber
                ['#3b82f6', '#60a5fa'], // Blue
                ['#8b5cf6', '#a78bfa'], // Violet
                ['#ec4899', '#f472b6'], // Pink
                ['#06b6d4', '#22d3ee'], // Cyan
                ['#84cc16', '#a3e635'], // Lime
                ['#f97316', '#fb923c'], // Orange
            ];
            foreach ($cqcProjects as $idx => $proj):
                $progress = intval($proj['progress_percentage'] ?? 0);
                $colorIdx = $idx % count($projectColors);
                $color1 = $projectColors[$colorIdx][0];
                $color2 = $projectColors[$colorIdx][1];
            ?>
                    (function() {
                        const ctx = document.getElementById('cqcPie<?php echo $idx; ?>');
                        if (!ctx) return;

                        // Create elegant gradient
                        const chartCtx = ctx.getContext('2d');
                        const gradient = chartCtx.createLinearGradient(0, 0, 100, 100);
                        gradient.addColorStop(0, '<?php echo $color1; ?>');
                        gradient.addColorStop(1, '<?php echo $color2; ?>');

                        new Chart(chartCtx, {
                            type: 'doughnut',
                            data: {
                                labels: ['Progress', 'Remaining'],
                                datasets: [{
                                    data: [<?php echo $progress; ?>, <?php echo 100 - $progress; ?>],
                                    backgroundColor: [gradient, '#f3f4f6'],
                                    borderWidth: 0,
                                    borderRadius: 8,
                                    hoverBackgroundColor: ['<?php echo $color1; ?>', '#e5e7eb'],
                                    hoverOffset: 4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                cutout: '70%',
                                rotation: -90,
                                circumference: 360,
                                plugins: {
                                    legend: {
                                        display: false
                                    },
                                    tooltip: {
                                        enabled: true,
                                        backgroundColor: 'rgba(17, 24, 39, 0.95)',
                                        titleColor: '<?php echo $color2; ?>',
                                        bodyColor: '#e5e7eb',
                                        cornerRadius: 12,
                                        padding: 14,
                                        displayColors: false,
                                        titleFont: {
                                            size: 13,
                                            weight: '700'
                                        },
                                        bodyFont: {
                                            size: 12
                                        },
                                        callbacks: {
                                            label: function(ctx) {
                                                return ctx.label + ': ' + ctx.parsed + '%';
                                            }
                                        }
                                    }
                                },
                                animation: {
                                    animateRotate: true,
                                    duration: 1000,
                                    easing: 'easeOutQuart'
                                }
                            }
                        });

                        // Category Expense Pie Chart
                        <?php
                        $catExpenses = $cqcCategoryExpenses[$proj['id']] ?? [];
                        if (!empty($catExpenses)):
                            $catColors = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4'];
                            $catLabels = array_map(function ($c) {
                                return $c['category_name'];
                            }, $catExpenses);
                            $catValues = array_map(function ($c) {
                                return floatval($c['total_amount']);
                            }, $catExpenses);
                            $catColorsJson = array_slice($catColors, 0, count($catExpenses));
                        ?>
                            const catCtx<?php echo $idx; ?> = document.getElementById('cqcCatPie<?php echo $idx; ?>');
                            if (catCtx<?php echo $idx; ?>) {
                                new Chart(catCtx<?php echo $idx; ?>.getContext('2d'), {
                                    type: 'doughnut',
                                    data: {
                                        labels: <?php echo json_encode($catLabels); ?>,
                                        datasets: [{
                                            data: <?php echo json_encode($catValues); ?>,
                                            backgroundColor: <?php echo json_encode($catColorsJson); ?>,
                                            borderWidth: 0
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: true,
                                        cutout: '55%',
                                        plugins: {
                                            legend: {
                                                display: false
                                            },
                                            tooltip: {
                                                backgroundColor: '#374151',
                                                bodyColor: '#e5e7eb',
                                                cornerRadius: 8,
                                                padding: 8,
                                                displayColors: true,
                                                callbacks: {
                                                    label: function(ctx) {
                                                        return ctx.label + ': Rp ' + ctx.parsed.toLocaleString();
                                                    }
                                                }
                                            }
                                        },
                                        animation: {
                                            animateRotate: true,
                                            duration: 600
                                        }
                                    }
                                });
                            }
                        <?php endif; ?>
                    })();
            <?php endforeach; ?>
        <?php endif; ?>

        // Toggle expense detail with smooth animation
        function toggleExpenseDetail(idx) {
            const detail = document.getElementById('expenseDetail' + idx);
            const hint = document.getElementById('clickHint' + idx);
            if (detail.style.display === 'none') {
                detail.style.display = 'block';
                detail.style.animation = 'fadeIn 0.3s ease';
                hint.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg> tutup detail';
            } else {
                detail.style.display = 'none';
                hint.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg> tap untuk detail';
            }
        }

        // ═══════════════════════════════════════════
        // AI HEALTH - Toggle Expand/Collapse
        // ═══════════════════════════════════════════
        function toggleAiHealth() {
            const detail = document.getElementById('aiHealthDetail');
            const chevron = document.getElementById('aiChevron');
            if (detail.style.display === 'none') {
                detail.style.display = 'block';
                detail.style.animation = 'fadeIn 0.3s ease';
                chevron.style.transform = 'rotate(180deg)';
            } else {
                detail.style.display = 'none';
                chevron.style.transform = 'rotate(0deg)';
            }
        }

        // ═══════════════════════════════════════════
        // ATTENDANCE MONITORING - Date Navigation
        // ═══════════════════════════════════════════
        <?php if (!$isCQC && $attStats['total'] > 0): ?>
                (function() {
                    let attCurrentDate = '<?= $attDate ?>';
                    const businessId = <?= (int)$_SESSION['business_id'] ?>;
                    const hariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                    const bulanShort = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

                    function formatDateIndo(dateStr) {
                        const d = new Date(dateStr + 'T00:00:00');
                        return hariIndo[d.getDay()] + ', ' + String(d.getDate()).padStart(2, '0') + ' ' + bulanShort[d.getMonth()] + ' ' + d.getFullYear();
                    }

                    window.attNavDate = function(offset) {
                        const d = new Date(attCurrentDate + 'T00:00:00');
                        d.setDate(d.getDate() + offset);
                        // Don't go beyond today
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        if (d > today) return;

                        attCurrentDate = d.toISOString().split('T')[0];
                        document.getElementById('attDateLabel').textContent = formatDateIndo(attCurrentDate);
                        loadAttendance(attCurrentDate);
                    };

                    function loadAttendance(dateStr) {
                        const list = document.getElementById('attStaffList');
                        list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:12px;">Memuat data...</div>';

                        fetch(basePath + '/api/owner-attendance.php?date=' + encodeURIComponent(dateStr) + '&business_id=' + businessId)
                            .then(r => r.json())
                            .then(data => {
                                if (!data.success) {
                                    list.innerHTML = '<div style="text-align:center;padding:20px;color:#dc2626;font-size:12px;">Gagal memuat data</div>';
                                    return;
                                }
                                // Update stats
                                document.getElementById('attStatPresent').textContent = data.stats.present;
                                document.getElementById('attStatLate').textContent = data.stats.late;
                                document.getElementById('attStatLeave').textContent = data.stats.leave;
                                document.getElementById('attStatAbsent').textContent = data.stats.absent;
                                document.getElementById('attListCount').textContent = data.stats.recorded + '/' + data.stats.total + ' staff';

                                let html = '';
                                // Present/late employees
                                data.records.forEach(ar => {
                                    let statusCls = 'av-present',
                                        badgeCls = 'asb-present',
                                        badgeText = 'Hadir';
                                    if (ar.status === 'late') {
                                        statusCls = 'av-late';
                                        badgeCls = 'asb-late';
                                        badgeText = 'Terlambat';
                                    } else if (ar.status === 'leave') {
                                        statusCls = 'av-leave';
                                        badgeCls = 'asb-leave';
                                        badgeText = 'Izin';
                                    } else if (ar.status === 'holiday') {
                                        statusCls = 'av-leave';
                                        badgeCls = 'asb-holiday';
                                        badgeText = 'Libur';
                                    } else if (ar.status === 'half_day') {
                                        statusCls = 'av-late';
                                        badgeCls = 'asb-half_day';
                                        badgeText = 'Half Day';
                                    }
                                    const s1 = ar.check_in_time ? ar.check_in_time.substring(0, 5) : '-';
                                    const s2 = ar.check_out_time ? ar.check_out_time.substring(0, 5) : '-';
                                    const s3 = ar.scan_3 ? ar.scan_3.substring(0, 5) : '';
                                    const s4 = ar.scan_4 ? ar.scan_4.substring(0, 5) : '';
                                    const wh = ar.work_hours ? parseFloat(ar.work_hours).toFixed(1) + 'h' : '';
                                    const lateMins = parseInt(ar.late_minutes || 0);
                                    const noteText = (ar.notes || '').trim();
                                    const initial = (ar.full_name || '?')[0].toUpperCase();

                                    html += '<div class="att-emp-row">';
                                    html += '<div class="att-emp-avatar ' + statusCls + '">' + initial + '</div>';
                                    html += '<div class="att-emp-info"><div class="att-emp-name">' + (ar.full_name || '-') + '</div>';
                                    html += '<div class="att-emp-meta"><span class="att-emp-pos">' + (ar.position || '-') + '</span>';
                                    html += '<span class="att-status-badge ' + badgeCls + '">' + badgeText + '</span>';
                                    if (lateMins > 0) html += '<span class="att-late-tag">+' + lateMins + 'm</span>';
                                    html += '</div></div>';
                                    html += '<div class="att-emp-scans"><div class="att-scan-pills">';
                                    html += '<div class="att-scan-pill"><span class="att-sp-lbl">S1</span><span class="att-sp-val">' + s1 + '</span></div>';
                                    html += '<div class="att-scan-pill"><span class="att-sp-lbl">S2</span><span class="att-sp-val">' + s2 + '</span></div>';
                                    if (s3) html += '<div class="att-scan-pill"><span class="att-sp-lbl">S3</span><span class="att-sp-val">' + s3 + '</span></div>';
                                    if (s4) html += '<div class="att-scan-pill"><span class="att-sp-lbl">S4</span><span class="att-sp-val">' + s4 + '</span></div>';
                                    html += '</div>';
                                    if (wh) html += '<div class="att-emp-hours">' + wh + '</div>';
                                    if (noteText) html += '<div class="att-emp-note">' + noteText.substring(0, 20) + '</div>';
                                    html += '</div></div>';
                                });

                                // Absent employees
                                data.absent.forEach(emp => {
                                    const initial = (emp.full_name || '?')[0].toUpperCase();
                                    html += '<div class="att-emp-row">';
                                    html += '<div class="att-emp-avatar av-absent">' + initial + '</div>';
                                    html += '<div class="att-emp-info"><div class="att-emp-name">' + (emp.full_name || '-') + '</div>';
                                    html += '<div class="att-emp-meta"><span class="att-emp-pos">' + (emp.position || '-') + '</span>';
                                    html += '<span class="att-status-badge asb-absent">Alpha</span></div></div>';
                                    html += '<div class="att-emp-scans"><div style="font-size:9px;color:#dc2626;font-weight:700;">Tidak hadir</div></div>';
                                    html += '</div>';
                                });

                                if (!html) html = '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:12px;">Tidak ada data kehadiran</div>';
                                list.innerHTML = html;
                            })
                            .catch(() => {
                                list.innerHTML = '<div style="text-align:center;padding:20px;color:#dc2626;font-size:12px;">Error koneksi</div>';
                            });
                    }
                })();
        <?php endif; ?>
    </script>
    <style>
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</body>

</html>