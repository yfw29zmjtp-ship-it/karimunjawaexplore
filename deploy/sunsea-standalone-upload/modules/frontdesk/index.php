<?php
/**
 * FRONT DESK MANAGEMENT SYSTEM v2028
 * Premium hotel management dashboard
 * Secure, Elegant, Modern
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

// Verify user has frontdesk permission
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Front Desk Management';

// ============================================
// GET TODAY'S STATISTICS (Secure Query)
// ============================================
try {
    $today = date('Y-m-d');

    // ==========================================
    // AUTO-CHECKOUT OVERDUE BOOKINGS
    // ==========================================
    $overdueBookings = $db->fetchAll("
        SELECT id, room_id FROM bookings 
        WHERE status = 'checked_in' AND DATE(check_out_date) < ?
    ", [$today]);
    foreach ($overdueBookings as $ob) {
        $db->query("UPDATE bookings SET status = 'checked_out', actual_checkout_time = check_out_date, updated_at = NOW() WHERE id = ?", [$ob['id']]);
        $db->query("UPDATE rooms SET status = 'available', current_guest_id = NULL, updated_at = NOW() WHERE id = ? AND status = 'occupied'", [$ob['room_id']]);
    }

    // Today's check-ins (using Database class query method)
    $checkinsResult = $db->fetchOne("
        SELECT COUNT(*) as count FROM bookings 
        WHERE DATE(check_in_date) = ? 
        AND status IN ('confirmed', 'checked_in')
    ", [$today]);
    $stats['checkins'] = $checkinsResult['count'] ?? 0;

    // Today's check-outs
    $checkoutsResult = $db->fetchOne("
        SELECT COUNT(*) as count FROM bookings 
        WHERE DATE(check_out_date) = ? 
        AND status = 'checked_in'
    ", [$today]);
    $stats['checkouts'] = $checkoutsResult['count'] ?? 0;

    // Available rooms
    $availResult = $db->fetchOne("
        SELECT COUNT(*) as count FROM rooms 
        WHERE status = 'available'
    ");
    $stats['available'] = $availResult['count'] ?? 0;

    // Total rooms
    $totalResult = $db->fetchOne("
        SELECT COUNT(*) as count FROM rooms
    ");
    $stats['total_rooms'] = $totalResult['count'] ?? 0;

    // Today's revenue (payments made today)
    $revenueResult = $db->fetchOne("
        SELECT COALESCE(SUM(bp.amount), 0) as total
        FROM booking_payments bp
        JOIN bookings b ON bp.booking_id = b.id
        WHERE DATE(bp.payment_date) = ?
    ", [$today]);
    $stats['revenue_today'] = $revenueResult['total'] ?? 0;

    // Room Revenue - ambil nilai TERBESAR antara paid_amount dan cash_book per booking
    $roomBookingsIdx = $db->fetchAll("
        SELECT booking_code, paid_amount
        FROM bookings
        WHERE status = 'checked_in'
           OR (status = 'checked_out' AND DATE_FORMAT(check_out_date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m'))
    ");
    $totalRoomRevenueIdx = 0;
    foreach ($roomBookingsIdx as $bk) {
        $paidAmount = (float)($bk['paid_amount'] ?? 0);
        $cbAmount   = 0;
        if (!empty($bk['booking_code'])) {
            $cbResult = $db->fetchOne("
                SELECT COALESCE(SUM(amount), 0) as total
                FROM cash_book
                WHERE transaction_type = 'income'
                AND description LIKE ?
                AND DATE_FORMAT(transaction_date, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
            ", ['%' . $bk['booking_code'] . '%']);
            $cbAmount = (float)($cbResult['total'] ?? 0);
        }
        $totalRoomRevenueIdx += max($paidAmount, $cbAmount);
    }
    $stats['inhouse_revenue'] = $totalRoomRevenueIdx;

    // Current occupancy - count all checked_in (overdue already auto-checked-out)
    $occupiedResult = $db->fetchOne("
        SELECT COUNT(DISTINCT room_id) as count FROM bookings 
        WHERE status = 'checked_in'
    ");
    $stats['occupied'] = $occupiedResult['count'] ?? 0;

    $stats['occupancy_rate'] = $stats['total_rooms'] > 0 ? 
        round(($stats['occupied'] / $stats['total_rooms']) * 100, 1) : 0;

    // ============================================
    // CASH BOOK DATA - For Daily Cash Report
    // ============================================
    $thisMonth = date('Y-m');
    $thisYear = date('Y');
    
    // Connect to master DB to get owner_capital account IDs
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessId = getMasterBusinessId();
    
    // Get owner_capital account IDs
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Build exclusion clause for owner capital
    $excludeOwnerCapital = '';
    if (!empty($ownerCapitalIds)) {
        $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalIds) . "))";
    }
    
    // Get today's income (excluding owner capital / modal owner)
    $todayIncomeResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'income' AND transaction_date = ?" . $excludeOwnerCapital,
        [$today]
    );
    $stats['cash_income_today'] = $todayIncomeResult['total'] ?? 0;
    
    // Get today's expenses
    $todayExpenseResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'expense' AND transaction_date = ?",
        [$today]
    );
    $stats['cash_expense_today'] = $todayExpenseResult['total'] ?? 0;
    
    // Get petty cash from owner TODAY ONLY (income to owner_capital account today)
    $stats['owner_transfer_today'] = 0;
    if (!empty($ownerCapitalIds)) {
        $placeholders = implode(',', array_fill(0, count($ownerCapitalIds), '?'));
        $ownerTodayResult = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
             WHERE transaction_type = 'income' AND transaction_date = ?
             AND cash_account_id IN ($placeholders)",
            array_merge([$today], $ownerCapitalIds)
        );
        $stats['owner_transfer_today'] = $ownerTodayResult['total'] ?? 0;
    }
    
    // Get TOTAL cash balance (all time: income - expense)
    $totalCashIncomeResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'income'" . $excludeOwnerCapital
    );
    $totalCashExpenseResult = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'expense'"
    );
    $stats['cash_balance'] = ($totalCashIncomeResult['total'] ?? 0) - ($totalCashExpenseResult['total'] ?? 0);
    
    // ============================================
    // REPORT DATA - Daily, Monthly, Yearly
    // ============================================
    
    // DAILY REPORT - Today's bookings and revenue
    $dailyBookings = $db->fetchOne("
        SELECT COUNT(*) as total FROM bookings 
        WHERE DATE(created_at) = ? AND status IN ('confirmed', 'checked_in', 'checked_out')
    ", [$today]);
    $stats['daily_bookings'] = $dailyBookings['total'] ?? 0;
    
    $dailyRevenue = $db->fetchOne("
        SELECT COALESCE(SUM(paid_amount), 0) as revenue 
        FROM bookings 
        WHERE DATE(created_at) = ? AND status IN ('confirmed', 'checked_in', 'checked_out')
    ", [$today]);
    $stats['daily_revenue'] = $dailyRevenue['revenue'] ?? 0;
    
    $dailyCheckins = $db->fetchOne("
        SELECT COUNT(*) as total FROM bookings 
        WHERE DATE(check_in_date) = ? AND status IN ('confirmed', 'checked_in')
    ", [$today]);
    $stats['daily_checkins'] = $dailyCheckins['total'] ?? 0;
    
    $dailyCheckouts = $db->fetchOne("
        SELECT COUNT(*) as total FROM bookings 
        WHERE DATE(check_out_date) = ? AND status = 'checked_in'
    ", [$today]);
    $stats['daily_checkouts'] = $dailyCheckouts['total'] ?? 0;
    
    // MONTHLY REPORT - This month's bookings and revenue
    $monthlyBookings = $db->fetchOne("
        SELECT COUNT(*) as total FROM bookings 
        WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status IN ('confirmed', 'checked_in', 'checked_out')
    ", [$thisMonth]);
    $stats['monthly_bookings'] = $monthlyBookings['total'] ?? 0;
    
    $monthlyRevenue = $db->fetchOne("
        SELECT COALESCE(SUM(paid_amount), 0) as revenue 
        FROM bookings 
        WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status IN ('confirmed', 'checked_in', 'checked_out')
    ", [$thisMonth]);
    $stats['monthly_revenue'] = $monthlyRevenue['revenue'] ?? 0;
    
    $monthlyNights = $db->fetchOne("
        SELECT COALESCE(SUM(DATEDIFF(check_out_date, check_in_date)), 0) as nights 
        FROM bookings 
        WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status IN ('confirmed', 'checked_in', 'checked_out')
    ", [$thisMonth]);
    $stats['monthly_nights'] = $monthlyNights['nights'] ?? 0;
    
    // YEARLY REPORT - This year's bookings and revenue
    $yearlyBookings = $db->fetchOne("
        SELECT COUNT(*) as total FROM bookings 
        WHERE YEAR(created_at) = ? AND status IN ('confirmed', 'checked_in', 'checked_out')
    ", [$thisYear]);
    $stats['yearly_bookings'] = $yearlyBookings['total'] ?? 0;
    
    $yearlyRevenue = $db->fetchOne("
        SELECT COALESCE(SUM(paid_amount), 0) as revenue 
        FROM bookings 
        WHERE YEAR(created_at) = ? AND status IN ('confirmed', 'checked_in', 'checked_out')
    ", [$thisYear]);
    $stats['yearly_revenue'] = $yearlyRevenue['revenue'] ?? 0;
    
    $yearlyNights = $db->fetchOne("
        SELECT COALESCE(SUM(DATEDIFF(check_out_date, check_in_date)), 0) as nights 
        FROM bookings 
        WHERE YEAR(created_at) = ? AND status IN ('confirmed', 'checked_in', 'checked_out')
    ", [$thisYear]);
    $stats['yearly_nights'] = $yearlyNights['nights'] ?? 0;
    
    // Recent bookings for daily list
    $recentBookings = $db->fetchAll("
        SELECT b.booking_code, g.guest_name, r.room_number, b.check_in_date, b.check_out_date, 
               b.final_price, b.paid_amount, b.status, b.created_at
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE DATE(b.created_at) = ?
        ORDER BY b.created_at DESC
        LIMIT 10
    ", [$today]);
    $stats['recent_bookings'] = $recentBookings;

} catch (Exception $e) {
    error_log("Front Desk Stats Error: " . $e->getMessage());
    $stats = [
        'checkins' => 0, 'checkouts' => 0, 'available' => 0, 
        'total_rooms' => 0, 'revenue_today' => 0, 'inhouse_revenue' => 0, 'occupied' => 0,
        'occupancy_rate' => 0, 'daily_bookings' => 0, 'daily_revenue' => 0,
        'monthly_bookings' => 0, 'monthly_revenue' => 0, 'yearly_bookings' => 0,
        'yearly_revenue' => 0, 'daily_checkins' => 0, 'daily_checkouts' => 0,
        'monthly_nights' => 0, 'yearly_nights' => 0, 'recent_bookings' => [],
        'cash_income_today' => 0, 'cash_expense_today' => 0, 
        'owner_transfer_today' => 0, 'cash_balance' => 0
    ];
}

include '../../includes/header.php';
?>

<style>
/* ============================================
   FRONT DESK DASHBOARD - LUXURIOUS STYLING
   ============================================ */

.fd-container {
    max-width: 1440px;
    margin: 0 auto;
    padding: 2rem 1rem;
}

/* Header Section */
.fd-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2.5rem;
    gap: 2rem;
}

.fd-header-content h1 {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.fd-header-content p {
    color: var(--text-secondary);
    font-size: 0.938rem;
    margin: 0.5rem 0 0 0;
}

/* Stats Grid - Responsive */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2.5rem;
}

.stat-card {
    background: var(--bg-secondary);
    border: 2px solid var(--bg-tertiary);
    border-radius: 16px;
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    backdrop-filter: blur(10px);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, var(--primary-color), transparent);
    opacity: 0.05;
    border-radius: 50%;
    pointer-events: none;
}

.stat-card:hover {
    transform: translateY(-8px);
    border-color: var(--primary-color);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
}

.stat-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    margin-bottom: 0.75rem;
}

.stat-value {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
}

.stat-label {
    color: var(--text-secondary);
    font-size: 0.813rem;
    font-weight: 600;
    margin-top: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Tab Navigation - Modern */
.fd-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 2rem;
    border-bottom: 2px solid var(--bg-tertiary);
    flex-wrap: wrap;
    padding-bottom: 0;
}

.tab-btn {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    padding: 1rem 1.5rem;
    font-size: 0.938rem;
    font-weight: 600;
    cursor: pointer;
    position: relative;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
}

.tab-btn:hover {
    color: var(--text-primary);
}

.tab-btn.active {
    color: var(--primary-color);
}

.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    border-radius: 3px 3px 0 0;
}

/* Tab Content */
.tab-content {
    display: none;
    animation: fadeIn 0.3s ease;
}

.tab-content.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Card Styling */
.fd-card {
    background: var(--bg-secondary);
    border: 2px solid var(--bg-tertiary);
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
}

.fd-card:hover {
    border-color: var(--primary-color);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
}

.fd-card-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

/* Quick Action Grid */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
}

.quick-btn {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    border: none;
    color: white;
    padding: 1rem;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    font-size: 0.875rem;
}

.quick-btn:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(99, 102, 241, 0.3);
}

/* Menu Grid */
.menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 1.5rem;
    padding: 1.5rem 0;
}

.menu-item {
    background: linear-gradient(135deg, var(--bg-secondary), var(--bg-tertiary));
    border: 2px solid var(--bg-tertiary);
    border-radius: 16px;
    padding: 1.5rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.menu-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
}

.menu-item:hover {
    transform: translateY(-12px);
    border-color: var(--primary-color);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
}

.menu-icon {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
}

.menu-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.menu-desc {
    font-size: 0.75rem;
    color: var(--text-secondary);
    line-height: 1.4;
}

/* Report Cards Responsive Container */
.report-cards-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

/* Responsive */
@media (max-width: 768px) {
    .fd-container {
        padding: 1rem;
    }

    .fd-header {
        flex-direction: column;
        align-items: flex-start;
        margin-bottom: 1.5rem;
    }

    .fd-header-content h1 {
        font-size: 1.5rem;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .report-cards-grid,
    #tab-laporan > div:first-child {
        grid-template-columns: 1fr !important;
    }

    .stat-card {
        padding: 1rem;
    }

    .stat-value {
        font-size: 1.5rem;
    }

    .fd-tabs {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .tab-btn {
        padding: 0.75rem 1rem;
        font-size: 0.813rem;
    }

    .menu-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .menu-grid {
        grid-template-columns: 1fr;
    }

    .quick-actions {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="fd-container">
    <!-- Header -->
    <div class="fd-header">
        <div class="fd-header-content">
            <h1>🏨 Front Desk Management</h1>
            <p><?php echo date('l, d F Y'); ?> • <?php echo date('H:i'); ?> WIB</p>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(99, 102, 241, 0.15); color: #6366f1;">👥</div>
            <div class="stat-value"><?php echo $stats['checkins']; ?></div>
            <div class="stat-label">Check-in Today</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">👋</div>
            <div class="stat-value"><?php echo $stats['checkouts']; ?></div>
            <div class="stat-label">Check-out Today</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">🛎️</div>
            <div class="stat-value"><?php echo $stats['available']; ?>/<?php echo $stats['total_rooms']; ?></div>
            <div class="stat-label">Kamar Tersedia</div>
        </div>


        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(139, 92, 246, 0.15); color: #8b5cf6;">👨‍💼</div>
            <div class="stat-value"><?php echo $stats['occupied']; ?></div>
            <div class="stat-label">Rooms Occupied</div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="fd-tabs">
        <button class="tab-btn active" onclick="switchTab('checkinout', this)">
            🏁 Check-in/out
        </button>
        <button class="tab-btn" onclick="switchTab('reservasi', this)">
            📅 Reservations
        </button>
        <button class="tab-btn" onclick="switchTab('ruangan', this)">
            🛏️ Manajemen Ruangan
        </button>
        <button class="tab-btn" onclick="switchTab('laporan', this)">
            📊 Laporan
        </button>
        <button class="tab-btn" onclick="switchTab('pengaturan', this)">
            ⚙️ Pengaturan
        </button>
    </div>

    <!-- Tab 1: CHECK-IN/OUT -->
    <div id="tab-checkinout" class="tab-content active">
        <!-- Load Dashboard Content via iframe atau include -->
        <div style="background: var(--bg-secondary); border-radius: 16px; border: 2px solid var(--bg-tertiary); overflow: hidden;">
            <!-- Dashboard Header with Quick Actions -->
            <div style="padding: 2rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1)); border-bottom: 2px solid var(--bg-tertiary);">
                <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin: 0 0 1rem 0;">
                    🏁 Daily Operations Dashboard
                </h3>
                <div class="quick-actions" style="margin-bottom: 0;">
                    <a href="dashboard.php" class="quick-btn" target="_blank" style="text-decoration: none;">
                        📊 Full Dashboard
                    </a>
                    <button class="quick-btn" onclick="alert('Coming Soon: Quick Check-in')">
                        ➡️ Quick Check-in
                    </button>
                    <button class="quick-btn" onclick="alert('Coming Soon: Quick Check-out')">
                        ⬅️ Quick Check-out
                    </button>
                    <button class="quick-btn" onclick="alert('Coming Soon: Today Schedule')">
                        📅 Today's Schedule
                    </button>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div style="padding: 2rem;">
                <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                    <p style="font-size: 1rem; margin-bottom: 1rem;">
                        📊 Lihat dashboard lengkap dengan Chart.js Occupancy dan Analytics real-time
                    </p>
                    <a href="dashboard.php" style="display: inline-block; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; padding: 1rem 2rem; border-radius: 12px; text-decoration: none; font-weight: 600; transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 24px rgba(99, 102, 241, 0.3)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                        ➜ Buka Dashboard Lengkap
                    </a>
                </div>
            </div>
        </div>

        <div class="fd-card">
            <div class="fd-card-title">📋 Today's Check-in/out List</div>
            <p style="color: var(--text-secondary); text-align: center; padding: 2rem;">
                Feature akan diimplementasikan pada tahap berikutnya
            </p>
        </div>
    </div>

    <!-- Tab 2: RESERVASI -->
    <div id="tab-reservasi" class="tab-content">
        <div class="fd-card">
            <div class="fd-card-title">📅 Reservation Management</div>
            <div class="quick-actions">
                <button class="quick-btn" onclick="alert('Coming Soon: New Reservation')">
                    ➕ New Reservation
                </button>
                <button class="quick-btn" onclick="alert('Coming Soon: Upcoming Bookings')">
                    📆 Upcoming Bookings
                </button>
                <button class="quick-btn" onclick="alert('Coming Soon: Reservation List')">
                    📋 All Reservations
                </button>
                <button class="quick-btn" onclick="alert('Coming Soon: Waiting List')">
                    ⏳ Waiting List
                </button>
            </div>
        </div>

        <div class="fd-card">
            <div class="fd-card-title">🗓️ Booking Calendar</div>
            <div style="border: 1px solid var(--glass-border); border-radius: 12px; overflow: hidden; background: var(--bg-secondary);">
                <iframe
                    src="calendar.php?staff_view=1"
                    title="Booking Calendar"
                    loading="lazy"
                    style="width: 100%; height: 78vh; min-height: 720px; border: 0; background: transparent;"
                ></iframe>
            </div>
        </div>
    </div>

    <!-- Tab 3: MANAJEMEN RUANGAN -->
    <div id="tab-ruangan" class="tab-content">
        <div class="fd-card">
            <div class="fd-card-title">🛏️ Room Management</div>
            <div class="quick-actions">
                <button class="quick-btn" onclick="alert('Coming Soon: Room Status Board')">
                    🔄 Status Board
                </button>
                <button class="quick-btn" onclick="alert('Coming Soon: Room Map')">
                    🗺️ Room Map
                </button>
                <button class="quick-btn" onclick="alert('Coming Soon: Room Details')">
                    📍 Room Details
                </button>
                <button class="quick-btn" onclick="alert('Coming Soon: Maintenance')">
                    🔧 Maintenance
                </button>
            </div>
        </div>

        <div class="fd-card">
            <div class="fd-card-title">📍 Real-time Room Status</div>
            <p style="color: var(--text-secondary); text-align: center; padding: 2rem;">
                Room grid dengan status real-time akan ditampilkan di sini
            </p>
        </div>
    </div>

    <!-- Tab 4: LAPORAN -->
    <div id="tab-laporan" class="tab-content">
        <!-- Report Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
            
            <!-- Daily Cash Report Card - Premium Redesign -->
            <div style="background: linear-gradient(135deg, #1a1f35 0%, #252d48 100%); border-radius: 24px; padding: 0; color: white; box-shadow: 0 20px 60px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.1); position: relative; overflow: hidden; border: 1px solid rgba(255,255,255,0.08);">
                <!-- Subtle gradient overlay -->
                <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(180deg, rgba(255,255,255,0.03) 0%, transparent 50%); pointer-events: none;"></div>
                
                <!-- Content wrapper -->
                <div style="position: relative; z-index: 1; padding: 1.5rem;">
                    <!-- Header -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                        <div>
                            <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.6; margin-bottom: 0.4rem; font-weight: 600;">💰 Laporan Kas</div>
                            <div style="font-size: 0.85rem; opacity: 0.5;"><?php echo strftime('%d %B %Y', strtotime(date('Y-m-d'))); ?></div>
                        </div>
                    </div>
                    
                    <!-- Main Saldo Card -->
                    <div style="background: linear-gradient(135deg, rgba(16,185,129,0.2) 0%, rgba(16,185,129,0.05) 100%); border-radius: 16px; padding: 1.25rem; margin-bottom: 1.25rem; border: 1px solid rgba(16,185,129,0.3);">
                        <div style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.7; margin-bottom: 0.5rem; color: #a1f3e1;">SALDO KAS</div>
                        <div style="font-size: 2rem; font-weight: 900; color: #10b981; letter-spacing: -1px;">
                            Rp <?php echo number_format($stats['cash_balance'], 0, ',', '.'); ?>
                        </div>
                    </div>
                    
                    <!-- Metrics Grid -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; margin-bottom: 0.5rem;">
                        <!-- Today's Income -->
                        <div style="background: rgba(16,185,129,0.12); border-radius: 12px; padding: 1rem; border-left: 3px solid #10b981;">
                            <div style="font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.8px; opacity: 0.65; margin-bottom: 0.4rem; color: #a1f3e1;">📈 Pemasukan</div>
                            <div style="font-size: 1.3rem; font-weight: 800; color: #10b981;">
                                Rp <?php echo number_format($stats['cash_income_today'], 0, ',', '.'); ?>
                            </div>
                        </div>
                        
                        <!-- Today's Expense -->
                        <div style="background: rgba(239,68,68,0.12); border-radius: 12px; padding: 1rem; border-left: 3px solid #f87171;">
                            <div style="font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.8px; opacity: 0.65; margin-bottom: 0.4rem; color: #fca5a5;">📉 Pengeluaran</div>
                            <div style="font-size: 1.3rem; font-weight: 800; color: #f87171;">
                                Rp <?php echo number_format($stats['cash_expense_today'], 0, ',', '.'); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Owner Transfer - Conditional Row -->
                    <?php if ($stats['owner_transfer_today'] > 0): ?>
                    <div style="background: rgba(99,102,241,0.12); border-radius: 12px; padding: 1rem; border-left: 3px solid #818cf8;">
                        <div style="font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.8px; opacity: 0.65; margin-bottom: 0.4rem; color: #c7d2fe;">👤 Transfer dari Owner</div>
                        <div style="font-size: 1.3rem; font-weight: 800; color: #818cf8;">
                            Rp <?php echo number_format($stats['owner_transfer_today'], 0, ',', '.'); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Monthly Report Card -->
            <div style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border-radius: 16px; padding: 1.25rem; color: white;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                    <div>
                        <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 0.25rem;">📆 Monthly Report</div>
                        <div style="font-size: 0.75rem; opacity: 0.7;"><?php echo date('F Y'); ?></div>
                    </div>
                    <div style="background: rgba(255,255,255,0.2); padding: 0.5rem; border-radius: 10px;">
                        <span style="font-size: 1.5rem;">📈</span>
                    </div>
                </div>
                <div style="font-size: 1.75rem; font-weight: 800; margin-bottom: 0.5rem;">
                    Rp <?php echo number_format($stats['monthly_revenue'], 0, ',', '.'); ?>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.2);">
                    <div>
                        <div style="font-size: 1.25rem; font-weight: 700;"><?php echo $stats['monthly_bookings']; ?></div>
                        <div style="font-size: 0.7rem; opacity: 0.8;">Total Reservations</div>
                    </div>
                    <div>
                        <div style="font-size: 1.25rem; font-weight: 700;"><?php echo $stats['monthly_nights']; ?></div>
                        <div style="font-size: 0.7rem; opacity: 0.8;">Room Nights</div>
                    </div>
                </div>
            </div>
            
            <!-- Yearly Report Card -->
            <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-radius: 16px; padding: 1.25rem; color: white;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                    <div>
                        <div style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 0.25rem;">📅 Annual Report</div>
                        <div style="font-size: 0.75rem; opacity: 0.7;">Year <?php echo date('Y'); ?></div>
                    </div>
                    <div style="background: rgba(255,255,255,0.2); padding: 0.5rem; border-radius: 10px;">
                        <span style="font-size: 1.5rem;">🏆</span>
                    </div>
                </div>
                <div style="font-size: 1.75rem; font-weight: 800; margin-bottom: 0.5rem;">
                    Rp <?php echo number_format($stats['yearly_revenue'], 0, ',', '.'); ?>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.2);">
                    <div>
                        <div style="font-size: 1.25rem; font-weight: 700;"><?php echo $stats['yearly_bookings']; ?></div>
                        <div style="font-size: 0.7rem; opacity: 0.8;">Total Reservations</div>
                    </div>
                    <div>
                        <div style="font-size: 1.25rem; font-weight: 700;"><?php echo $stats['yearly_nights']; ?></div>
                        <div style="font-size: 0.7rem; opacity: 0.8;">Room Nights</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Bookings Table -->
        <div class="fd-card">
            <div class="fd-card-title" style="display: flex; justify-content: space-between; align-items: center;">
                <span>📋 Today's Reservations</span>
                <span style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo date('d M Y'); ?></span>
            </div>
            <?php if (!empty($stats['recent_bookings'])): ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr style="background: var(--bg-tertiary); text-align: left;">
                            <th style="padding: 0.75rem; font-weight: 600; color: var(--text-secondary);">Kode</th>
                            <th style="padding: 0.75rem; font-weight: 600; color: var(--text-secondary);">Guest</th>
                            <th style="padding: 0.75rem; font-weight: 600; color: var(--text-secondary);">Kamar</th>
                            <th style="padding: 0.75rem; font-weight: 600; color: var(--text-secondary);">Check-in</th>
                            <th style="padding: 0.75rem; font-weight: 600; color: var(--text-secondary);">Check-out</th>
                            <th style="padding: 0.75rem; font-weight: 600; color: var(--text-secondary); text-align: right;">Total</th>
                            <th style="padding: 0.75rem; font-weight: 600; color: var(--text-secondary); text-align: right;">Paid</th>
                            <th style="padding: 0.75rem; font-weight: 600; color: var(--text-secondary);">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['recent_bookings'] as $booking): ?>
                        <tr style="border-bottom: 1px solid var(--glass-border);">
                            <td style="padding: 0.75rem;">
                                <code style="background: var(--bg-tertiary); padding: 0.2rem 0.4rem; border-radius: 4px; font-size: 0.75rem;">
                                    <?php echo htmlspecialchars($booking['booking_code']); ?>
                                </code>
                            </td>
                            <td style="padding: 0.75rem; font-weight: 500;"><?php echo htmlspecialchars($booking['guest_name'] ?? '-'); ?></td>
                            <td style="padding: 0.75rem;">
                                <span style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600;">
                                    <?php echo htmlspecialchars($booking['room_number'] ?? '-'); ?>
                                </span>
                            </td>
                            <td style="padding: 0.75rem; font-size: 0.8rem;"><?php echo date('d/m/Y', strtotime($booking['check_in_date'])); ?></td>
                            <td style="padding: 0.75rem; font-size: 0.8rem;"><?php echo date('d/m/Y', strtotime($booking['check_out_date'])); ?></td>
                            <td style="padding: 0.75rem; text-align: right; font-weight: 600;">Rp <?php echo number_format($booking['final_price'], 0, ',', '.'); ?></td>
                            <td style="padding: 0.75rem; text-align: right; color: #10b981; font-weight: 600;">Rp <?php echo number_format($booking['paid_amount'], 0, ',', '.'); ?></td>
                            <td style="padding: 0.75rem;">
                                <?php
                                $statusColors = [
                                    'confirmed' => '#10b981',
                                    'checked_in' => '#6366f1',
                                    'checked_out' => '#64748b',
                                    'cancelled' => '#ef4444'
                                ];
                                $statusColor = $statusColors[$booking['status']] ?? '#64748b';
                                ?>
                                <span style="background: <?php echo $statusColor; ?>20; color: <?php echo $statusColor; ?>; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase;">
                                    <?php echo htmlspecialchars($booking['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📭</div>
                <p>No reservations today</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="fd-card">
            <div class="fd-card-title">🔗 Other Reports</div>
            <div class="quick-actions" style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="laporan.php" class="quick-btn" style="text-decoration: none;">
                    📊 Detailed Daily Report
                </a>
                <a href="in-house.php" class="quick-btn" style="text-decoration: none;">
                    🏨 In-House Guests
                </a>
                <a href="calendar.php" class="quick-btn" style="text-decoration: none;">
                    📅 Booking Calendar
                </a>
            </div>
        </div>
    </div>

    <!-- Tab 5: PENGATURAN -->
    <div id="tab-pengaturan" class="tab-content">
        <div class="fd-card">
            <div class="fd-card-title">⚙️ Settings & Configuration</div>
            <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                Manage rooms, room types, and OTA commission fees
            </p>
            <div class="quick-actions">
                <a href="settings.php?tab=rooms" class="quick-btn" style="text-decoration: none; display: inline-block;">
                    🚪 Manage Rooms
                </a>
                <a href="settings.php?tab=room_types" class="quick-btn" style="text-decoration: none; display: inline-block;">
                    🏷️ Room Types
                </a>
                <a href="settings.php?tab=ota_fees" class="quick-btn" style="text-decoration: none; display: inline-block;">
                    💰 OTA Fees
                </a>
            </div>
        </div>

        <div class="fd-card">
            <div class="fd-card-title">📊 Configuration Summary</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1.5rem;">
                <div style="background: rgba(99, 102, 241, 0.1); padding: 1rem; border-radius: 10px; border: 1px solid rgba(99, 102, 241, 0.2);">
                    <p style="margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.85rem;">Total Rooms</p>
                    <p style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #6366f1;">12</p>
                </div>
                <div style="background: rgba(16, 185, 129, 0.1); padding: 1rem; border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2);">
                    <p style="margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.85rem;">Room Types</p>
                    <p style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #10b981;">3</p>
                </div>
                <div style="background: rgba(139, 92, 246, 0.1); padding: 1rem; border-radius: 10px; border: 1px solid rgba(139, 92, 246, 0.2);">
                    <p style="margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.85rem;">OTA Providers</p>
                    <p style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #8b5cf6;">8</p>
                </div>
            </div>
            <p style="color: var(--text-secondary); margin-top: 1.5rem; font-size: 0.9rem;">
                ℹ️ Klik "Settings & Configuration" untuk manage database kamar, tipe kamar, dan fee OTA.
            </p>
        </div>
    </div>

</div>

<script>
// Tab switching functionality
function switchTab(tabName, btnElement) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });

    // Remove active from all buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    // Show selected tab
    const tabId = 'tab-' + tabName;
    const tabElement = document.getElementById(tabId);
    if (tabElement) {
        tabElement.classList.add('active');
        btnElement.classList.add('active');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
