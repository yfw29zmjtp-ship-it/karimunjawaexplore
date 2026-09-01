<?php
/**
 * Developer Panel - Dashboard
 * Main dashboard for website management
 */

require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();

$pageTitle = 'Dashboard';

// Get statistics
$stats = [
    'total_rooms' => 0,
    'available_rooms' => 0,
    'total_bookings' => 0,
    'online_bookings' => 0,
    'today_bookings' => 0
];

try {
    $stats['total_rooms'] = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    $stats['available_rooms'] = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'available'")->fetchColumn();
    $stats['total_bookings'] = $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
    $stats['online_bookings'] = $pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_source = 'online'")->fetchColumn();
    $stats['today_bookings'] = $pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_source = 'online' AND DATE(created_at) = CURDATE()")->fetchColumn();
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
}

// Get recent online bookings
$recentBookings = [];
try {
    $stmt = $pdo->query("SELECT b.*, r.room_number, rt.type_name 
                         FROM bookings b 
                         LEFT JOIN rooms r ON b.room_id = r.id 
                         LEFT JOIN room_types rt ON r.room_type_id = rt.id 
                         WHERE b.booking_source = 'online' 
                         ORDER BY b.created_at DESC 
                         LIMIT 5");
    $recentBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Recent bookings error: " . $e->getMessage());
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-item {
        background: white;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        display: flex;
        align-items: center;
        gap: 16px;
    }
    
    .stat-icon {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
    }
    
    .stat-info .value {
        font-size: 1.8rem;
        font-weight: 700;
        color: #1a1a2e;
        line-height: 1;
    }
    
    .stat-info .label {
        font-size: 0.8rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 4px;
    }
    
    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 30px;
    }
    
    .quick-action {
        background: white;
        border-radius: 12px;
        padding: 20px;
        text-align: center;
        text-decoration: none;
        color: #1a1a2e;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    
    .quick-action:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        color: #1a1a2e;
    }
    
    .quick-action i {
        font-size: 2rem;
        margin-bottom: 12px;
        display: block;
    }
    
    .quick-action.primary i { color: #0c2340; }
    .quick-action.accent i { color: #c8a45e; }
    .quick-action.success i { color: #10b981; }
    .quick-action.info i { color: #3b82f6; }
    
    .quick-action strong {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
    }
    
    .recent-bookings-list {
        background: white;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    
    .booking-item {
        padding: 16px 0;
        border-bottom: 1px solid #f1f3f5;
    }
    
    .booking-item:last-child {
        border-bottom: none;
    }
    
    .booking-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    
    .booking-guest {
        font-weight: 600;
        color: #1a1a2e;
    }
    
    .booking-badge {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .booking-badge.confirmed { background: #d4edda; color: #155724; }
    .booking-badge.pending { background: #fff3cd; color: #856404; }
    .booking-badge.cancelled { background: #f8d7da; color: #721c24; }
    
    .booking-details {
        font-size: 0.85rem;
        color: #6c757d;
    }
</style>

<!-- Welcome Card -->
<div class="welcome-card">
    <h2>Selamat Datang, <?= htmlspecialchars($user['full_name']) ?>! 👋</h2>
    <p>Developer Panel untuk mengelola website booking Narayana Karimunjawa</p>
</div>

<!-- Statistics -->
<div class="stat-grid">
    <div class="stat-item">
        <div class="stat-icon" style="background: rgba(12,35,64,0.1); color: #0c2340;">
            <i class="bi bi-door-open"></i>
        </div>
        <div class="stat-info">
            <div class="value"><?= $stats['total_rooms'] ?></div>
            <div class="label">Total Rooms</div>
        </div>
    </div>
    
    <div class="stat-item">
        <div class="stat-icon" style="background: rgba(16,185,129,0.1); color: #10b981;">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="stat-info">
            <div class="value"><?= $stats['available_rooms'] ?></div>
            <div class="label">Available</div>
        </div>
    </div>
    
    <div class="stat-item">
        <div class="stat-icon" style="background: rgba(59,130,246,0.1); color: #3b82f6;">
            <i class="bi bi-calendar-check"></i>
        </div>
        <div class="stat-info">
            <div class="value"><?= $stats['online_bookings'] ?></div>
            <div class="label">Online Bookings</div>
        </div>
    </div>
    
    <div class="stat-item">
        <div class="stat-icon" style="background: rgba(200,164,94,0.1); color: #c8a45e;">
            <i class="bi bi-lightning"></i>
        </div>
        <div class="stat-info">
            <div class="value"><?= $stats['today_bookings'] ?></div>
            <div class="label">Today</div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<h5 class="mb-3"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h5>
<div class="quick-actions-grid">
    <a href="web-settings.php" class="quick-action primary">
        <i class="bi bi-globe"></i>
        <strong>Web Settings</strong>
    </a>
    
    <a href="../public/" target="_blank" class="quick-action accent">
        <i class="bi bi-box-arrow-up-right"></i>
        <strong>View Website</strong>
    </a>
    
    <a href="../public/booking.php" target="_blank" class="quick-action success">
        <i class="bi bi-calendar-plus"></i>
        <strong>Test Booking</strong>
    </a>
    
    <a href="http://localhost:8081/" target="_blank" class="quick-action info">
        <i class="bi bi-speedometer2"></i>
        <strong>ADF System</strong>
    </a>
</div>

<!-- Recent Online Bookings -->
<h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>Recent Online Bookings</h5>
<div class="recent-bookings-list">
    <?php if (empty($recentBookings)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size: 3rem; opacity: 0.3;"></i>
            <p class="mb-0 mt-2">Belum ada booking online</p>
        </div>
    <?php else: ?>
        <?php foreach ($recentBookings as $booking): ?>
        <div class="booking-item">
            <div class="booking-header">
                <span class="booking-guest">
                    <i class="bi bi-person me-2"></i><?= htmlspecialchars($booking['guest_name'] ?? 'Unknown') ?>
                </span>
                <span class="booking-badge <?= strtolower($booking['booking_status'] ?? 'pending') ?>">
                    <?= htmlspecialchars($booking['booking_status'] ?? 'pending') ?>
                </span>
            </div>
            <div class="booking-details">
                <i class="bi bi-door-closed me-1"></i><?= htmlspecialchars($booking['type_name'] ?? 'N/A') ?> - Room <?= htmlspecialchars($booking['room_number'] ?? 'N/A') ?>
                <span class="mx-2">|</span>
                <i class="bi bi-calendar-event me-1"></i><?= date('d M Y', strtotime($booking['check_in_date'])) ?> - <?= date('d M Y', strtotime($booking['check_out_date'])) ?>
                <span class="mx-2">|</span>
                <i class="bi bi-clock me-1"></i><?= date('d M Y H:i', strtotime($booking['created_at'])) ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
